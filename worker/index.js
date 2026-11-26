/**
 * Cloudflare Worker para o Livro de Registro de Ocorrências da Seção Técnica (LRO-ST) — DTCEA-SJ
 * Compatível com o frontend em public/ e banco Cloudflare D1 (binding: DB).
 */
import { STATIC_ASSETS } from './static_assets.js';

const STATUS_LABELS = {
  aberta: 'Aberta',
  em_acompanhamento: 'Em Acompanhamento',
  aguardando_terceiros: 'Aguardando Terceiros',
  resolvida: 'Resolvida / Encerrada',
};

const SEVERITY_LABELS = {
  critica: 'Crítica (Indisponibilidade)',
  alta: 'Alta (Degradação Severa)',
  media: 'Média (Contingência / Parcial)',
  baixa: 'Baixa / Informativo',
};

const UPDATE_TYPE_LABELS = {
  atualizacao: 'Atualização Técnica',
  contato_operadora: 'Contato Operadora / Terceiro',
  restabelecimento_parcial: 'Restabelecimento Parcial',
  despacho: 'Despacho / Acionamento',
  encerramento: 'Encerramento / Restabelecimento',
};

const SESSION_COOKIE = 'LRO_SESSION';
const DEFAULT_FALLBACK_SECRET = 'lro-dtceasj-st-edge-secret-2026-change-in-vars';

// ============================================================================
// UTILITÁRIOS DE DATA, HORA, FORMATAÇÃO E CRIPTOGRAFIA
// ============================================================================

function nowSaoPaulo() {
  const parts = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'America/Sao_Paulo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).formatToParts(new Date());
  const map = Object.fromEntries(parts.map((p) => [p.type, p.value]));
  return `${map.year}-${map.month}-${map.day} ${map.hour}:${map.minute}:${map.second}`;
}

function parseLocalIsoToSql(raw) {
  if (!raw) return null;
  const cleaned = String(raw).trim().replace('T', ' ');
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(cleaned)) return `${cleaned}:00`;
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(cleaned)) return cleaned;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function formatDtBr(dt) {
  if (!dt) return '—';
  const m = String(dt).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (!m) return dt;
  return `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}`;
}

function formatDateBr(iso) {
  if (!iso) return '—';
  const p = String(iso).split('-');
  return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
}

function toTimestampMs(dt) {
  if (!dt) return null;
  const iso = String(dt).replace(' ', 'T') + '-03:00';
  const t = Date.parse(iso);
  return Number.isNaN(t) ? null : t;
}

function formatMinutesHuman(totalMinutes) {
  if (totalMinutes < 60) return `${totalMinutes}min`;
  const days = Math.floor(totalMinutes / 1440);
  const rem = totalMinutes % 1440;
  const hours = Math.floor(rem / 60);
  const mins = rem % 60;
  const parts = [];
  if (days > 0) parts.push(`${days}d`);
  if (hours > 0) parts.push(`${hours}h`);
  if (mins > 0) parts.push(`${mins}min`);
  return parts.join(' ');
}

function formatDuration(start, end) {
  const t1 = toTimestampMs(start);
  if (t1 === null) return '—';
  const t2 = end ? toTimestampMs(end) : toTimestampMs(nowSaoPaulo());
  if (t2 === null || t2 < t1) return '0min';
  const mins = Math.round((t2 - t1) / 60000);
  return formatMinutesHuman(mins);
}

function diffMinutes(start, end) {
  if (!start || !end) return null;
  const t1 = toTimestampMs(start);
  const t2 = toTimestampMs(end);
  if (t1 === null || t2 === null) return null;
  return t2 < t1 ? 0 : Math.round((t2 - t1) / 60000);
}

function esc(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function cleanStr(v) {
  return String(v ?? '').trim();
}

function jsonResponse(data, status = 200, extraHeaders = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
      ...extraHeaders,
    },
  });
}

function hexToBytes(hex) {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) {
    out[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return out;
}

function bytesToHex(bytes) {
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

async function hashPassword(password) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' }, key, 256);
  return `pbkdf2_sha256$100000$${bytesToHex(salt)}$${bytesToHex(new Uint8Array(bits))}`;
}

async function verifyPassword(password, storedHash) {
  const parts = String(storedHash || '').split('$');
  if (parts.length !== 4 || parts[0] !== 'pbkdf2_sha256') return false;
  const iterations = Number(parts[1]) || 100000;
  const salt = hexToBytes(parts[2]);
  const expected = parts[3];
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations, hash: 'SHA-256' }, key, 256);
  const actual = bytesToHex(new Uint8Array(bits));
  if (actual.length !== expected.length) return false;
  let diff = 0;
  for (let i = 0; i < actual.length; i++) diff |= actual.charCodeAt(i) ^ expected.charCodeAt(i);
  return diff === 0;
}

async function signSession(payload, secret) {
  const data = btoa(JSON.stringify(payload)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data));
  const sigB64 = btoa(String.fromCharCode(...new Uint8Array(sig))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  return `${data}.${sigB64}`;
}

async function verifySession(token, secret) {
  if (!token || !token.includes('.')) return null;
  const [data, sig] = token.split('.');
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['verify']
  );
  const sigNorm = sig.replace(/-/g, '+').replace(/_/g, '/');
  const sigBytes = Uint8Array.from(atob(sigNorm), (c) => c.charCodeAt(0));
  const ok = await crypto.subtle.verify('HMAC', key, sigBytes, new TextEncoder().encode(data));
  if (!ok) return null;
  const dataNorm = data.replace(/-/g, '+').replace(/_/g, '/');
  const parsed = JSON.parse(atob(dataNorm));
  if (!parsed || !parsed.uid || !parsed.exp || Date.now() > parsed.exp) return null;
  return parsed;
}

function getCookie(request, name) {
  const raw = request.headers.get('Cookie') || '';
  for (const part of raw.split(';')) {
    const [k, ...rest] = part.trim().split('=');
    if (k === name) return decodeURIComponent(rest.join('='));
  }
  return null;
}

function sessionCookieHeader(token, maxAgeSec, isHttps) {
  const attrs = [`${SESSION_COOKIE}=${encodeURIComponent(token)}`, 'Path=/', 'HttpOnly', 'SameSite=Lax', `Max-Age=${maxAgeSec}`];
  if (isHttps) attrs.push('Secure');
  return attrs.join('; ');
}

async function getCurrentUser(request, env) {
  const token = getCookie(request, SESSION_COOKIE);
  if (!token) return null;
  const secret = env.AUTH_SECRET || DEFAULT_FALLBACK_SECRET;
  const session = await verifySession(token, secret);
  if (!session) return null;
  const user = await env.DB.prepare(
    'SELECT id, username, rank_name, full_name, role, specialty, active FROM users WHERE id = ? LIMIT 1'
  ).bind(session.uid).first();
  if (!user || Number(user.active) !== 1) return null;
  return user;
}

async function logAudit(env, userId, action, entity, entityId, details, request) {
  const ip = request.headers.get('CF-Connecting-IP') || '0.0.0.0';
  await env.DB.prepare(
    'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
  ).bind(userId, action, entity, entityId, details || '', ip, nowSaoPaulo()).run();
}

// ============================================================================
// SERVIÇO DE OCORRÊNCIAS (D1)
// ============================================================================

function buildWhere(filters) {
  const clauses = ['1=1'];
  const binds = [];

  if (filters.date_from) {
    clauses.push('o.opened_at >= ?');
    binds.push(`${filters.date_from} 00:00:00`);
  }
  if (filters.date_to) {
    clauses.push('o.opened_at <= ?');
    binds.push(`${filters.date_to} 23:59:59`);
  }
  if (filters.status && filters.status !== 'todas') {
    if (filters.status === 'pendentes') {
      clauses.push("o.status != 'resolvida'");
    } else {
      clauses.push('o.status = ?');
      binds.push(filters.status);
    }
  }
  if (filters.severidade && filters.severidade !== 'todas') {
    clauses.push('o.severidade = ?');
    binds.push(filters.severidade);
  }
  if (filters.localidade && filters.localidade !== 'todas') {
    clauses.push('o.localidade = ?');
    binds.push(filters.localidade);
  }
  if (filters.sistema && filters.sistema !== 'todos') {
    clauses.push('o.sistema = ?');
    binds.push(filters.sistema);
  }
  if (filters.search) {
    clauses.push('(o.code LIKE ? OR o.title LIKE ? OR o.localidade LIKE ? OR o.centro LIKE ? OR o.sistema LIKE ? OR o.ticket_externo LIKE ? OR o.descricao LIKE ?)');
    const q = `%${filters.search}%`;
    binds.push(q, q, q, q, q, q, q);
  }

  return { where: clauses.join(' AND '), binds };
}

function enrichOccurrence(row) {
  return {
    ...row,
    duration_label: formatDuration(row.opened_at, row.closed_at),
    opened_at_br: formatDtBr(row.opened_at),
    closed_at_br: formatDtBr(row.closed_at),
    status_label: STATUS_LABELS[row.status] || row.status,
    severidade_label: SEVERITY_LABELS[row.severidade] || row.severidade,
  };
}

async function queryOccurrences(env, filters, dir = 'DESC') {
  const { where, binds } = buildWhere(filters);
  const orderDir = dir === 'ASC' ? 'ASC' : 'DESC';
  const sql = `
    SELECT
      o.*,
      u.rank_name AS creator_rank_name,
      u.full_name AS creator_full_name,
      (SELECT COUNT(*) FROM occurrence_updates ou WHERE ou.occurrence_id = o.id) AS updates_count
    FROM occurrences o
    JOIN users u ON u.id = o.created_by
    WHERE ${where}
    ORDER BY o.opened_at ${orderDir}, o.id ${orderDir}
  `;
  const { results } = await env.DB.prepare(sql).bind(...binds).all();
  return (results || []).map(enrichOccurrence);
}

async function getOccurrenceDetail(env, id) {
  const row = await env.DB.prepare(`
    SELECT o.*, u.rank_name AS creator_rank_name, u.full_name AS creator_full_name
    FROM occurrences o
    JOIN users u ON u.id = o.created_by
    WHERE o.id = ?
    LIMIT 1
  `).bind(id).first();
  if (!row) return null;

  const { results: updates } = await env.DB.prepare(`
    SELECT ou.*, u.rank_name, u.full_name
    FROM occurrence_updates ou
    JOIN users u ON u.id = ou.user_id
    WHERE ou.occurrence_id = ?
    ORDER BY ou.event_at ASC, ou.id ASC
  `).bind(id).all();

  const occ = enrichOccurrence(row);
  occ.updates = (updates || []).map((u) => ({
    ...u,
    event_at_br: formatDtBr(u.event_at),
    update_type_label: UPDATE_TYPE_LABELS[u.update_type] || u.update_type,
    status_after_label: u.status_after ? (STATUS_LABELS[u.status_after] || u.status_after) : null,
  }));
  return occ;
}

async function generateOccurrenceCode(env, openedAt) {
  const year = String(openedAt || nowSaoPaulo()).slice(0, 4);
  const prefix = `LRO-${year}-`;
  const last = await env.DB.prepare('SELECT code FROM occurrences WHERE code LIKE ? ORDER BY id DESC LIMIT 1')
    .bind(`${prefix}%`).first();
  let nextNum = 1;
  if (last && last.code) {
    const m = String(last.code).match(/^LRO-\d{4}-(\d+)$/);
    if (m) nextNum = Number(m[1]) + 1;
  }
  return `${prefix}${String(nextNum).padStart(4, '0')}`;
}

async function buildReportPayload(env, filters) {
  const occurrences = await queryOccurrences(env, filters, 'ASC');
  const updatesByOcc = {};

  if (occurrences.length > 0) {
    const placeholders = occurrences.map(() => '?').join(',');
    const ids = occurrences.map((o) => o.id);
    const { results: allUpdates } = await env.DB.prepare(`
      SELECT ou.*, u.rank_name, u.full_name
      FROM occurrence_updates ou
      JOIN users u ON u.id = ou.user_id
      WHERE ou.occurrence_id IN (${placeholders})
      ORDER BY ou.event_at ASC, ou.id ASC
    `).bind(...ids).all();

    for (const u of allUpdates || []) {
      const enriched = {
        ...u,
        event_at_br: formatDtBr(u.event_at),
        update_type_label: UPDATE_TYPE_LABELS[u.update_type] || u.update_type,
        status_after_label: u.status_after ? (STATUS_LABELS[u.status_after] || u.status_after) : null,
      };
      if (!updatesByOcc[u.occurrence_id]) updatesByOcc[u.occurrence_id] = [];
      updatesByOcc[u.occurrence_id].push(enriched);
    }
  }

  const stats = {
    total: occurrences.length,
    aberta: 0,
    em_acompanhamento: 0,
    aguardando_terceiros: 0,
    resolvida: 0,
    critica: 0,
    alta: 0,
    media: 0,
    baixa: 0,
    by_localidade: {},
    by_sistema: {},
  };
  const resolvedDurations = [];

  for (const occ of occurrences) {
    occ.updates = updatesByOcc[occ.id] || [];
    if (occ.status in stats) stats[occ.status]++;
    if (occ.severidade in stats) stats[occ.severidade]++;
    if (occ.status === 'resolvida' && occ.closed_at) {
      const mins = diffMinutes(occ.opened_at, occ.closed_at);
      if (mins !== null) resolvedDurations.push(mins);
    }
    const loc = occ.localidade || 'Não informada';
    stats.by_localidade[loc] = (stats.by_localidade[loc] || 0) + 1;
    const sys = occ.sistema || 'Não informado';
    stats.by_sistema[sys] = (stats.by_sistema[sys] || 0) + 1;
  }

  const sortObjDesc = (obj) => Object.fromEntries(Object.entries(obj).sort((a, b) => b[1] - a[1]));
  stats.by_localidade = sortObjDesc(stats.by_localidade);
  stats.by_sistema = sortObjDesc(stats.by_sistema);

  if (resolvedDurations.length > 0) {
    const avg = Math.round(resolvedDurations.reduce((a, b) => a + b, 0) / resolvedDurations.length);
    stats.mttr_minutes = avg;
    stats.mttr_human = formatMinutesHuman(avg);
  } else {
    stats.mttr_minutes = null;
    stats.mttr_human = '—';
  }

  return { stats, occurrences };
}

// ============================================================================
// HANDLER DA API (/api.php)
// ============================================================================

async function handleApi(request, env, url) {
  const action = url.searchParams.get('action') || '';
  const isHttps = url.protocol === 'https:';
  const secret = env.AUTH_SECRET || DEFAULT_FALLBACK_SECRET;

  try {
    if (action === 'login') {
      const body = await request.json().catch(() => ({}));
      const username = cleanStr(body.username).toLowerCase();
      const password = String(body.password ?? '');
      if (!username || !password) {
        return jsonResponse({ ok: false, error: 'Informe usuário e senha.' }, 400);
      }

      const user = await env.DB.prepare('SELECT * FROM users WHERE username = ? LIMIT 1').bind(username).first();
      if (!user || Number(user.active) !== 1 || !(await verifyPassword(password, user.password_hash))) {
        await logAudit(env, null, 'login_failed', 'users', null, `Tentativa para usuário: ${username}`, request);
        return jsonResponse({ ok: false, error: 'Usuário ou senha inválidos (ou conta inativa).' }, 401);
      }

      await logAudit(env, user.id, 'login_success', 'users', user.id, `Login de ${user.rank_name}`, request);
      const token = await signSession({ uid: user.id, exp: Date.now() + 86400 * 7 * 1000 }, secret);
      const { password_hash, ...safeUser } = user;

      return jsonResponse({ ok: true, user: safeUser }, 200, {
        'Set-Cookie': sessionCookieHeader(token, 86400 * 7, isHttps),
      });
    }

    if (action === 'logout') {
      const user = await getCurrentUser(request, env);
      if (user) await logAudit(env, user.id, 'logout', 'users', user.id, '', request);
      return jsonResponse({ ok: true }, 200, {
        'Set-Cookie': sessionCookieHeader('', 0, isHttps),
      });
    }

    if (action === 'me') {
      const user = await getCurrentUser(request, env);
      return jsonResponse({ ok: true, user });
    }

    const user = await getCurrentUser(request, env);
    if (!user) {
      return jsonResponse({ ok: false, error: 'Sessão expirada ou usuário não autenticado.' }, 401);
    }

    if (action === 'change_password') {
      const body = await request.json().catch(() => ({}));
      const current = String(body.current_password ?? '');
      const next = String(body.new_password ?? '');
      if (next.length < 5) {
        return jsonResponse({ ok: false, error: 'A nova senha deve ter pelo menos 5 caracteres.' }, 400);
      }
      const row = await env.DB.prepare('SELECT password_hash FROM users WHERE id = ?').bind(user.id).first();
      if (!row || !(await verifyPassword(current, row.password_hash))) {
        return jsonResponse({ ok: false, error: 'Senha atual incorreta.' }, 400);
      }
      const newHash = await hashPassword(next);
      await env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(newHash, user.id).run();
      await logAudit(env, user.id, 'change_password', 'users', user.id, 'Senha alterada pelo próprio usuário', request);
      return jsonResponse({ ok: true });
    }

    if (action === 'get_catalogs') {
      const locsRes = await env.DB.prepare("SELECT DISTINCT localidade FROM occurrences WHERE localidade != '' ORDER BY localidade").all();
      const sysRes = await env.DB.prepare("SELECT DISTINCT sistema FROM occurrences WHERE sistema != '' ORDER BY sistema").all();
      const opsRes = await env.DB.prepare("SELECT DISTINCT operadora FROM occurrences WHERE operadora != '' ORDER BY operadora").all();

      const defaultLocs = ['DTCEA-SJ (Sede)', 'Salesópolis - SP', 'EACEA-HSA', 'Pico do Couto', 'Campos do Jordão - SP'];
      const defaultSys = ['MPLS / VHF (MARC / ICCB)', 'Redes / Enlace de Dados', 'VHF / UHF Rádio', 'Energia / No-Break CC', 'Radar / Vigilância', 'Auxílios à Navegação (VOR/DME/ILS)', 'Climatização / Infraestrutura'];
      const defaultOps = ['Interno ST', 'Embratel', 'Telebras', 'Claro', 'Vivo', 'EDP / Concessionária'];

      const uniq = (a, b) => Array.from(new Set([...a, ...(b || []).map((r) => Object.values(r)[0])]));
      return jsonResponse({
        ok: true,
        localidades: uniq(defaultLocs, locsRes.results),
        sistemas: uniq(defaultSys, sysRes.results),
        operadoras: uniq(defaultOps, opsRes.results),
        statuses: STATUS_LABELS,
        severidades: SEVERITY_LABELS,
        update_types: UPDATE_TYPE_LABELS,
      });
    }

    if (action === 'list_occurrences') {
      const filters = {
        date_from: cleanStr(url.searchParams.get('date_from')),
        date_to: cleanStr(url.searchParams.get('date_to')),
        status: cleanStr(url.searchParams.get('status')),
        severidade: cleanStr(url.searchParams.get('severidade')),
        localidade: cleanStr(url.searchParams.get('localidade')),
        sistema: cleanStr(url.searchParams.get('sistema')),
        search: cleanStr(url.searchParams.get('search')),
      };
      const occurrences = await queryOccurrences(env, filters, 'DESC');
      const counts = await env.DB.prepare(`
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN status = 'aberta' THEN 1 ELSE 0 END) AS aberta,
          SUM(CASE WHEN status = 'em_acompanhamento' THEN 1 ELSE 0 END) AS em_acompanhamento,
          SUM(CASE WHEN status = 'aguardando_terceiros' THEN 1 ELSE 0 END) AS aguardando_terceiros,
          SUM(CASE WHEN status = 'resolvida' THEN 1 ELSE 0 END) AS resolvida,
          SUM(CASE WHEN status != 'resolvida' AND severidade = 'critica' THEN 1 ELSE 0 END) AS criticas_abertas
        FROM occurrences
      `).first();

      return jsonResponse({ ok: true, occurrences, counts });
    }

    if (action === 'get_occurrence') {
      const id = Number(url.searchParams.get('id') || 0);
      if (id <= 0) return jsonResponse({ ok: false, error: 'ID inválido.' }, 400);
      const occ = await getOccurrenceDetail(env, id);
      if (!occ) return jsonResponse({ ok: false, error: 'Ocorrência não encontrada.' }, 404);
      return jsonResponse({ ok: true, occurrence: occ });
    }

    if (action === 'create_occurrence') {
      const body = await request.json().catch(() => ({}));
      const title = cleanStr(body.title);
      const localidade = cleanStr(body.localidade);
      const centro = cleanStr(body.centro);
      const sistema = cleanStr(body.sistema);
      const ticket = cleanStr(body.ticket_externo);
      const operadora = cleanStr(body.operadora);
      const severidade = body.severidade in SEVERITY_LABELS ? body.severidade : 'media';
      const status = body.status in STATUS_LABELS ? body.status : 'aberta';
      const descricao = cleanStr(body.descricao);
      const acoes = cleanStr(body.acoes_preliminares);
      const impacto = cleanStr(body.impacto_operacional);

      if (!title || !localidade || !sistema || !descricao) {
        return jsonResponse({ ok: false, error: 'Preencha Título, Localidade, Sistema e Descrição.' }, 400);
      }

      const now = nowSaoPaulo();
      const openedAt = parseLocalIsoToSql(body.opened_at) || now;
      const closedAt = status === 'resolvida' ? now : null;
      const code = await generateOccurrenceCode(env, openedAt);

      const res = await env.DB.prepare(`
        INSERT INTO occurrences (
          code, title, localidade, centro, sistema, ticket_externo, operadora,
          severidade, status, descricao, acoes_preliminares, impacto_operacional,
          opened_at, closed_at, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).bind(
        code, title, localidade, centro, sistema, ticket, operadora,
        severidade, status, descricao, acoes, impacto,
        openedAt, closedAt, user.id, now, now
      ).run();

      const newId = res.meta?.last_row_id;
      await logAudit(env, user.id, 'create_occurrence', 'occurrences', newId, `Criada ocorrência ${code}: ${title}`, request);
      const created = await getOccurrenceDetail(env, newId);
      return jsonResponse({ ok: true, occurrence: created }, 201);
    }

    if (action === 'add_update') {
      const body = await request.json().catch(() => ({}));
      const occId = Number(body.occurrence_id || 0);
      const updateType = body.update_type in UPDATE_TYPE_LABELS ? body.update_type : 'atualizacao';
      const message = cleanStr(body.message);
      const statusAfter = body.status_after in STATUS_LABELS ? body.status_after : null;

      if (occId <= 0 || !message) {
        return jsonResponse({ ok: false, error: 'Ocorrência e descrição da atualização são obrigatórios.' }, 400);
      }

      const occ = await getOccurrenceDetail(env, occId);
      if (!occ) return jsonResponse({ ok: false, error: 'Ocorrência não encontrada.' }, 404);

      const now = nowSaoPaulo();
      const eventAt = parseLocalIsoToSql(body.event_at) || now;

      await env.DB.prepare(`
        INSERT INTO occurrence_updates (
          occurrence_id, user_id, author_label, update_type, message, status_after, event_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      `).bind(occId, user.id, user.rank_name, updateType, message, statusAfter, eventAt, now).run();

      if (statusAfter) {
        let closedAt = occ.closed_at;
        if (statusAfter === 'resolvida') closedAt = eventAt;
        else if (occ.status === 'resolvida' && statusAfter !== 'resolvida') closedAt = null;
        await env.DB.prepare('UPDATE occurrences SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?')
          .bind(statusAfter, closedAt, now, occId).run();
      } else {
        await env.DB.prepare('UPDATE occurrences SET updated_at = ? WHERE id = ?').bind(now, occId).run();
      }

      await logAudit(env, user.id, 'add_update', 'occurrences', occId, `Atualização (${updateType}) em ${occ.code}`, request);
      const updated = await getOccurrenceDetail(env, occId);
      return jsonResponse({ ok: true, occurrence: updated });
    }

    if (action === 'get_report_data') {
      const filters = {
        date_from: cleanStr(url.searchParams.get('date_from')),
        date_to: cleanStr(url.searchParams.get('date_to')),
        status: cleanStr(url.searchParams.get('status')),
        severidade: cleanStr(url.searchParams.get('severidade')),
        localidade: cleanStr(url.searchParams.get('localidade')),
        sistema: cleanStr(url.searchParams.get('sistema')),
      };
      const payload = await buildReportPayload(env, filters);
      return jsonResponse({ ok: true, ...payload });
    }

    if (action === 'list_users') {
      const { results } = await env.DB.prepare(
        'SELECT id, username, rank_name, full_name, role, specialty, active, created_at FROM users ORDER BY active DESC, rank_name ASC'
      ).all();
      return jsonResponse({ ok: true, users: results || [] });
    }

    if (action === 'save_user') {
      if (!['admin', 'supervisor'].includes(user.role)) {
        return jsonResponse({ ok: false, error: 'Permissão insuficiente para esta operação.' }, 403);
      }
      const body = await request.json().catch(() => ({}));
      const id = Number(body.id || 0);
      const username = cleanStr(body.username).toLowerCase();
      const rankName = cleanStr(body.rank_name);
      const fullName = cleanStr(body.full_name);
      const role = ['tecnico', 'supervisor', 'admin'].includes(body.role) ? body.role : 'tecnico';
      const specialty = cleanStr(body.specialty) || 'ST - Manutenção';
      const active = body.active === false ? 0 : 1;
      const password = String(body.password ?? '');

      if (!username || !rankName || !fullName) {
        return jsonResponse({ ok: false, error: 'Usuário, Posto/Nome de Guerra e Nome Completo são obrigatórios.' }, 400);
      }

      if (id > 0) {
        await env.DB.prepare(
          'UPDATE users SET username = ?, rank_name = ?, full_name = ?, role = ?, specialty = ?, active = ? WHERE id = ?'
        ).bind(username, rankName, fullName, role, specialty, active, id).run();
        if (password) {
          const h = await hashPassword(password);
          await env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(h, id).run();
        }
        await logAudit(env, user.id, 'update_user', 'users', id, `Atualizado técnico ${rankName}`, request);
      } else {
        const h = await hashPassword(password || 'dtceasj');
        const res = await env.DB.prepare(
          'INSERT INTO users (username, password_hash, rank_name, full_name, role, specialty, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        ).bind(username, h, rankName, fullName, role, specialty, active, nowSaoPaulo()).run();
        await logAudit(env, user.id, 'create_user', 'users', res.meta?.last_row_id, `Cadastrado técnico ${rankName}`, request);
      }

      return jsonResponse({ ok: true });
    }

    return jsonResponse({ ok: false, error: 'Ação desconhecida.' }, 404);
  } catch (err) {
    const msg = String(err?.message || err);
    if (msg.includes('UNIQUE')) {
      return jsonResponse({ ok: false, error: 'Registro duplicado (usuário ou código já existente).' }, 409);
    }
    return jsonResponse({ ok: false, error: `Erro interno: ${msg}` }, 500);
  }
}

// ============================================================================
// HANDLER DO RELATÓRIO SIGADAER (/report.php)
// ============================================================================

async function handleReport(request, env, url) {
  const user = await getCurrentUser(request, env);
  if (!user) {
    return Response.redirect(new URL('/', url).toString(), 302);
  }

  const nowDate = nowSaoPaulo().slice(0, 10);
  const firstDay = `${nowDate.slice(0, 7)}-01`;
  const filters = {
    date_from: cleanStr(url.searchParams.get('date_from')) || firstDay,
    date_to: cleanStr(url.searchParams.get('date_to')) || nowDate,
    status: cleanStr(url.searchParams.get('status')) || 'todas',
    severidade: cleanStr(url.searchParams.get('severidade')) || 'todas',
    localidade: cleanStr(url.searchParams.get('localidade')) || 'todas',
  };

  const { stats, occurrences } = await buildReportPayload(env, filters);

  if (url.searchParams.get('format') === 'csv') {
    const csvEsc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const header = [
      'Codigo LRO', 'Data/Hora Inicio', 'Data/Hora Termino', 'Duracao',
      'Localidade', 'Centro/Estacao', 'Sistema', 'Ticket Externo', 'Operadora',
      'Severidade', 'Status', 'Responsavel Abertura', 'Titulo', 'Descricao',
      'Acoes Preliminares', 'Impacto Operacional', 'Qtd Atualizacoes'
    ].map(csvEsc).join(';');

    const rows = occurrences.map((o) => [
      o.code, o.opened_at_br, o.closed_at_br, o.duration_label,
      o.localidade, o.centro, o.sistema, o.ticket_externo, o.operadora,
      o.severidade_label, o.status_label, o.creator_full_name, o.title,
      String(o.descricao || '').replace(/\r?\n/g, ' | '),
      String(o.acoes_preliminares || '').replace(/\r?\n/g, ' | '),
      o.impacto_operacional, (o.updates || []).length
    ].map(csvEsc).join(';'));

    const csvContent = '\uFEFF' + [header, ...rows].join('\r\n');
    const filename = `LRO-ST_DTCEA-SJ_${filters.date_from}_a_${filters.date_to}.csv`;
    return new Response(csvContent, {
      headers: {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': `attachment; filename="${filename}"`,
      },
    });
  }

  const generatedAt = formatDtBr(nowSaoPaulo()) + ':' + nowSaoPaulo().slice(17, 19);
  const hashInput = new TextEncoder().encode(JSON.stringify([filters, stats, occurrences.length, generatedAt]));
  const hashBuf = await crypto.subtle.digest('SHA-256', hashInput);
  const integrityHash = bytesToHex(new Uint8Array(hashBuf)).toUpperCase().slice(0, 16);

  const csvParams = new URLSearchParams({ ...filters, format: 'csv' }).toString();
  const statusDisplay = filters.status === 'todas' ? 'Todos' : (STATUS_LABELS[filters.status] || filters.status);
  const locDisplay = filters.localidade === 'todas' ? 'Todas' : filters.localidade;

  const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório LRO-ST DTCEA-SJ (${esc(formatDateBr(filters.date_from))} a ${esc(formatDateBr(filters.date_to))})</title>
<style>
@page { size: A4 portrait; margin: 14mm 12mm 16mm 12mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", Arial, Helvetica, sans-serif; color: #111827; background: #e5e7eb; font-size: 10.5pt; line-height: 1.4; }
.toolbar { position: sticky; top: 0; z-index: 50; background: #0b1b34; color: #fff; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.25); flex-wrap: wrap; }
.toolbar-title { font-weight: 700; font-size: 0.95rem; }
.toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn { border: none; border-radius: 6px; padding: 8px 14px; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-print { background: #2563eb; color: #fff; }
.btn-csv { background: #0f766e; color: #fff; }
.btn-back { background: rgba(255,255,255,0.14); color: #fff; }
.sheet { max-width: 210mm; margin: 18px auto; background: #fff; padding: 16mm 14mm; box-shadow: 0 6px 25px rgba(0,0,0,0.14); }
.doc-header { border: 1.5px solid #0b1b34; padding: 12px 14px; margin-bottom: 14px; }
.doc-header-top { display: flex; align-items: center; justify-content: center; gap: 14px; text-align: center; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; margin-bottom: 10px; }
.doc-logo { width: 52px; height: 52px; flex-shrink: 0; }
.org-line { font-size: 8.5pt; font-weight: 700; letter-spacing: 0.05em; color: #1e3a8a; }
.unit-line { font-size: 11pt; font-weight: 800; color: #0b1b34; margin: 2px 0; }
.doc-title { font-size: 12pt; font-weight: 800; color: #0b1b34; margin: 4px 0 2px; text-transform: uppercase; }
.doc-meta-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 14px; font-size: 8.8pt; }
.doc-meta-grid div strong { color: #0b1b34; }
h2.sec-title { font-size: 10.5pt; text-transform: uppercase; color: #0b1b34; border-bottom: 2px solid #0b1b34; padding-bottom: 3px; margin: 16px 0 10px; letter-spacing: 0.03em; }
.kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 12px; }
.kpi-box { border: 1px solid #cbd5e1; border-radius: 5px; padding: 7px 9px; background: #f8fafc; text-align: center; }
.kpi-box .k-lbl { font-size: 7.5pt; text-transform: uppercase; color: #475569; font-weight: 700; }
.kpi-box .k-val { font-size: 13pt; font-weight: 800; color: #0b1b34; margin-top: 2px; }
.dist-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.dist-box { border: 1px solid #cbd5e1; border-radius: 5px; padding: 8px 10px; font-size: 8.8pt; }
.dist-box h4 { margin: 0 0 6px; font-size: 8.8pt; color: #0b1b34; text-transform: uppercase; }
.dist-list { margin: 0; padding-left: 16px; }
table.synoptic { width: 100%; border-collapse: collapse; font-size: 8.3pt; margin-bottom: 14px; }
table.synoptic th, table.synoptic td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
table.synoptic th { background: #0b1b34; color: #fff; font-weight: 700; font-size: 7.8pt; text-transform: uppercase; }
table.synoptic tr:nth-child(even) td { background: #f8fafc; }
.occ-block { border: 1px solid #94a3b8; border-radius: 5px; margin-bottom: 14px; page-break-inside: avoid; break-inside: avoid; }
.occ-block-head { background: #f1f5f9; border-bottom: 1px solid #94a3b8; padding: 7px 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; }
.occ-code { font-family: Consolas, monospace; font-weight: 800; color: #0b1b34; font-size: 9.5pt; }
.occ-pill { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 7.5pt; font-weight: 700; border: 1px solid #cbd5e1; background: #fff; }
.occ-block-body { padding: 9px 11px; font-size: 9pt; }
.occ-block-title { font-weight: 700; font-size: 9.8pt; color: #0b1b34; margin: 0 0 6px; }
.occ-meta-line { display: flex; flex-wrap: wrap; gap: 4px 16px; font-size: 8.3pt; background: #f8fafc; padding: 5px 8px; border: 1px solid #e2e8f0; margin-bottom: 8px; }
.occ-field { margin-bottom: 6px; }
.occ-field-lbl { font-weight: 700; font-size: 8.2pt; color: #334155; text-transform: uppercase; }
.occ-field-val { white-space: pre-wrap; margin-top: 1px; }
.upd-table { width: 100%; border-collapse: collapse; font-size: 8.2pt; margin-top: 6px; }
.upd-table th, .upd-table td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; }
.upd-table th { background: #e2e8f0; color: #0b1b34; font-weight: 700; }
.signature-section { margin-top: 24px; page-break-inside: avoid; break-inside: avoid; }
.sig-note { font-size: 8.5pt; color: #334155; margin-bottom: 26px; text-align: justify; }
.sig-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; text-align: center; font-size: 8.8pt; }
.sig-line { border-top: 1px solid #0b1b34; padding-top: 5px; margin-top: 28px; }
@media print { body { background: #fff; } .toolbar { display: none !important; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; width: 100%; } }
</style>
</head>
<body>
<div class="toolbar">
  <div class="toolbar-title">Pré-visualização Oficial — Relatório LRO-ST (SIGADAER)</div>
  <div class="toolbar-actions">
    <a href="/" class="btn btn-back">← Voltar ao Livro</a>
    <a href="/report.php?${esc(csvParams)}" class="btn btn-csv">⬇ Exportar CSV (Excel)</a>
    <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF (SIGADAER)</button>
  </div>
</div>
<div class="sheet">
  <header class="doc-header">
    <div class="doc-header-top">
      <img src="/assets/img/logo-lro-st.svg" alt="Logo LRO-ST" class="doc-logo">
      <div>
        <div class="org-line">MINISTÉRIO DA DEFESA • COMANDO DA AERONÁUTICA • DECEA</div>
        <div class="unit-line">DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS (DTCEA-SJ)</div>
        <div class="doc-title">Relatório do Livro de Registro de Ocorrências — Seção Técnica (LRO-ST)</div>
      </div>
    </div>
    <div class="doc-meta-grid">
      <div><strong>Período Apurado:</strong> ${esc(formatDateBr(filters.date_from))} a ${esc(formatDateBr(filters.date_to))}</div>
      <div><strong>Data/Hora Emissão:</strong> ${esc(generatedAt)}</div>
      <div><strong>Código de Controle:</strong> <code>${esc(integrityHash)}</code></div>
      <div><strong>Emitido por:</strong> ${esc(user.full_name)}</div>
      <div><strong>Filtro Status:</strong> ${esc(statusDisplay)}</div>
      <div><strong>Filtro Localidade:</strong> ${esc(locDisplay)}</div>
    </div>
  </header>

  <h2 class="sec-title">1. Resumo Executivo e Indicadores do Período</h2>
  <div class="kpi-row">
    <div class="kpi-box"><div class="k-lbl">Total Ocorrências</div><div class="k-val">${stats.total}</div></div>
    <div class="kpi-box"><div class="k-lbl">Abertas / Andamento</div><div class="k-val">${stats.aberta + stats.em_acompanhamento}</div></div>
    <div class="kpi-box"><div class="k-lbl">Aguard. Terceiros</div><div class="k-val">${stats.aguardando_terceiros}</div></div>
    <div class="kpi-box"><div class="k-lbl">Resolvidas</div><div class="k-val">${stats.resolvida}</div></div>
    <div class="kpi-box"><div class="k-lbl">MTTR (Resolvidas)</div><div class="k-val" style="font-size:10.5pt;padding-top:3px;">${esc(stats.mttr_human)}</div></div>
  </div>

  ${occurrences.length ? `
  <div class="dist-grid">
    <div class="dist-box">
      <h4>Distribuição por Localidade / Sítio</h4>
      <ul class="dist-list">${Object.entries(stats.by_localidade).map(([k, v]) => `<li><strong>${esc(k)}:</strong> ${v} ocorrência(s)</li>`).join('')}</ul>
    </div>
    <div class="dist-box">
      <h4>Distribuição por Sistema / Equipamento</h4>
      <ul class="dist-list">${Object.entries(stats.by_sistema).map(([k, v]) => `<li><strong>${esc(k)}:</strong> ${v} ocorrência(s)</li>`).join('')}</ul>
    </div>
  </div>` : ''}

  <h2 class="sec-title">2. Quadro Sinótico das Ocorrências</h2>
  ${!occurrences.length ? '<p style="font-size:9.5pt;color:#475569;">Nenhuma ocorrência técnica registrada no período e filtros especificados.</p>' : `
  <table class="synoptic">
    <thead><tr><th>Registro</th><th>Início</th><th>Término</th><th>Duração</th><th>Localidade / Centro</th><th>Sistema</th><th>Ticket</th><th>Severidade</th><th>Status</th></tr></thead>
    <tbody>
      ${occurrences.map((o) => `<tr>
        <td><strong>${esc(o.code)}</strong></td>
        <td>${esc(o.opened_at_br)}</td>
        <td>${esc(o.closed_at_br)}</td>
        <td>${esc(o.duration_label)}</td>
        <td>${esc(o.localidade)}${o.centro ? `<br><small>${esc(o.centro)}</small>` : ''}</td>
        <td>${esc(o.sistema)}</td>
        <td>${esc(o.ticket_externo || '—')}</td>
        <td>${esc(o.severidade_label)}</td>
        <td><strong>${esc(o.status_label)}</strong></td>
      </tr>`).join('')}
    </tbody>
  </table>

  <h2 class="sec-title">3. Detalhamento Técnico e Trilha de Auditoria</h2>
  ${occurrences.map((o) => `
  <article class="occ-block">
    <div class="occ-block-head">
      <div><span class="occ-code">${esc(o.code)}</span> <span class="occ-pill">${esc(o.status_label)}</span> <span class="occ-pill">${esc(o.severidade_label)}</span></div>
      <div style="font-size:8.2pt;color:#334155;"><strong>Início:</strong> ${esc(o.opened_at_br)} | <strong>Término:</strong> ${esc(o.closed_at_br)} | <strong>Duração:</strong> ${esc(o.duration_label)}</div>
    </div>
    <div class="occ-block-body">
      <div class="occ-block-title">${esc(o.title)}</div>
      <div class="occ-meta-line">
        <span><strong>Local:</strong> ${esc(o.localidade)}${o.centro ? ` (${esc(o.centro)})` : ''}</span>
        <span><strong>Sistema:</strong> ${esc(o.sistema)}</span>
        ${o.ticket_externo ? `<span><strong>Ticket:</strong> ${esc(o.ticket_externo)}${o.operadora ? ` (${esc(o.operadora)})` : ''}</span>` : ''}
        <span><strong>Autor:</strong> ${esc(o.creator_full_name)}</span>
      </div>
      <div class="occ-field"><div class="occ-field-lbl">Relato Técnico</div><div class="occ-field-val">${esc(o.descricao)}</div></div>
      ${(o.acoes_preliminares || o.impacto_operacional) ? `<div class="occ-field"><div class="occ-field-lbl">Ações / Impacto</div><div class="occ-field-val">${esc((o.acoes_preliminares || '') + (o.impacto_operacional ? `\nImpacto: ${o.impacto_operacional}` : ''))}</div></div>` : ''}
      ${(o.updates && o.updates.length) ? `
      <div class="occ-field" style="margin-top:8px;">
        <div class="occ-field-lbl">Histórico de Atualizações e Despachos</div>
        <table class="upd-table">
          <thead><tr><th style="width:17%;">Data / Hora</th><th style="width:18%;">Responsável</th><th style="width:20%;">Tipo / Status</th><th>Descrição / Providência</th></tr></thead>
          <tbody>${o.updates.map((u) => `<tr>
            <td>${esc(u.event_at_br)}</td>
            <td><strong>${esc(u.author_label)}</strong></td>
            <td>${esc(u.update_type_label)}${u.status_after_label ? `<br><small>Status: ${esc(u.status_after_label)}</small>` : ''}</td>
            <td style="white-space:pre-wrap;">${esc(u.message)}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>` : ''}
    </div>
  </article>`).join('')}`}

  <section class="signature-section">
    <div class="sig-note">Declaro para fins de registro administrativo, controle de manutenção e evidência técnica junto ao Sistema de Documento Eletrônico da Aeronáutica (SIGADAER) que as ocorrências acima listadas refletem fielmente os lançamentos efetuados pelos técnicos da Seção Técnica (ST) do DTCEA-SJ no período especificado.</div>
    <div class="sig-grid">
      <div><div class="sig-line"><strong>${esc(user.full_name)}</strong><br>Responsável pela Emissão — Seção Técnica (ST)</div></div>
      <div><div class="sig-line"><strong>Chefe da Seção Técnica — DTCEA-SJ</strong><br>Visto / Conferência para Auditoria</div></div>
    </div>
  </section>
</div>
</body>
</html>`;

  return new Response(html, {
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
  });
}

// ============================================================================
// ENTRYPOINT DO WORKER
// ============================================================================

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (url.pathname === '/api.php' || url.pathname === '/api') {
      return handleApi(request, env, url);
    }
    if (url.pathname === '/report.php' || url.pathname === '/report') {
      return handleReport(request, env, url);
    }

    if (env.ASSETS) {
      return env.ASSETS.fetch(request);
    }

    const content = STATIC_ASSETS[url.pathname];
    if (content !== undefined) {
      const ct = url.pathname.endsWith('.css')
        ? 'text/css; charset=utf-8'
        : url.pathname.endsWith('.js')
          ? 'application/javascript; charset=utf-8'
          : url.pathname.endsWith('.svg')
            ? 'image/svg+xml'
            : 'text/html; charset=utf-8';
      return new Response(content, { headers: { 'Content-Type': ct } });
    }

    return new Response('Não encontrado.', { status: 404 });
  },
};
