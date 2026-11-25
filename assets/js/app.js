(() => {
    'use strict';

    const state = {
        user: null,
        catalogs: {
            localidades: [],
            sistemas: [],
            operadoras: [],
            statuses: {},
            severidades: {},
            update_types: {},
        },
        currentOccurrence: null,
        users: [],
    };

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.from(document.querySelectorAll(sel));

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function localDatetimeInputNow() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function toIsoDate(d) {
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    function showToast(msg) {
        const el = $('#toast');
        el.textContent = msg;
        el.classList.remove('hidden');
        clearTimeout(el._timer);
        el._timer = setTimeout(() => el.classList.add('hidden'), 3200);
    }

    async function api(action, options = {}) {
        const method = options.method || 'GET';
        let url = `api.php?action=${encodeURIComponent(action)}`;
        if (options.params) {
            const qs = new URLSearchParams(options.params).toString();
            if (qs) url += '&' + qs;
        }
        const fetchOpts = { method, headers: {} };
        if (options.body !== undefined) {
            fetchOpts.headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(options.body);
        }
        const res = await fetch(url, fetchOpts);
        const data = await res.json().catch(() => ({ ok: false, error: 'Resposta inválida do servidor.' }));
        if (!res.ok || !data.ok) {
            if (res.status === 401 && action !== 'login') {
                setUnauthenticated();
            }
            throw new Error(data.error || `Erro HTTP ${res.status}`);
        }
        return data;
    }

    function openModal(id) {
        $(`#${id}`).classList.remove('hidden');
    }

    function closeModal(id) {
        $(`#${id}`).classList.add('hidden');
    }

    function setAuthenticated(user) {
        state.user = user;
        $('#loginView').classList.add('hidden');
        $('#appView').classList.remove('hidden');
        $('#btnFabNew').classList.remove('hidden');
        $('#currentUserBadge').textContent = `${user.rank_name}`;

        const canManageUsers = ['admin', 'supervisor'].includes(user.role);
        $('#btnAddUser').classList.toggle('hidden', !canManageUsers);

        loadCatalogs().then(() => {
            loadOccurrences();
            applyReportPreset('month');
        });
    }

    function setUnauthenticated() {
        state.user = null;
        $('#appView').classList.add('hidden');
        $('#btnFabNew').classList.add('hidden');
        $('#loginView').classList.remove('hidden');
    }

    async function loadCatalogs() {
        const data = await api('get_catalogs');
        state.catalogs = data;

        const fillDatalist = (id, items) => {
            $(`#${id}`).innerHTML = items.map((v) => `<option value="${escapeHtml(v)}"></option>`).join('');
        };
        fillDatalist('dlLocalidades', data.localidades);
        fillDatalist('dlSistemas', data.sistemas);
        fillDatalist('dlOperadoras', data.operadoras);

        const fillLocSelect = (id) => {
            const sel = $(`#${id}`);
            const cur = sel.value || 'todas';
            sel.innerHTML = `<option value="todas">Todas</option>` +
                data.localidades.map((v) => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
            sel.value = cur;
        };
        fillLocSelect('filterLocalidade');
        fillLocSelect('repLocalidade');
    }

    async function loadOccurrences() {
        const params = {
            search: $('#filterSearch').value.trim(),
            status: $('#filterStatus').value,
            severidade: $('#filterSeverity').value,
            localidade: $('#filterLocalidade').value,
            date_from: $('#filterDateFrom').value,
            date_to: $('#filterDateTo').value,
        };
        const data = await api('list_occurrences', { params });

        const c = data.counts || {};
        $('#kpiAberta').textContent = c.aberta ?? 0;
        $('#kpiAcompanhamento').textContent = c.em_acompanhamento ?? 0;
        $('#kpiTerceiros').textContent = c.aguardando_terceiros ?? 0;
        $('#kpiResolvida').textContent = c.resolvida ?? 0;

        renderOccurrencesList(data.occurrences || []);
    }

    function renderOccurrencesList(items) {
        const wrap = $('#occurrencesList');
        if (!items.length) {
            wrap.innerHTML = `<div class="empty-state">Nenhuma ocorrência encontrada com os filtros atuais.</div>`;
            return;
        }

        wrap.innerHTML = `
            <div class="card book-table-card">
                <div class="book-table-wrap">
                    <table class="book-table">
                        <thead>
                            <tr>
                                <th>Registro</th>
                                <th>Início</th>
                                <th>Localidade / Sistema</th>
                                <th>Assunto / Ticket</th>
                                <th>Status</th>
                                <th>Duração</th>
                                <th>Autor</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map((o) => {
                                const ticketTxt = o.ticket_externo
                                    ? `<span class="badge badge-ticket" style="margin-left:6px;">Ticket ${escapeHtml(o.ticket_externo)}</span>`
                                    : '';
                                const updBadge = Number(o.updates_count) > 0
                                    ? `<div class="book-sub">💬 ${o.updates_count} atualiz.</div>`
                                    : '';
                                return `
                                    <tr data-occ-id="${o.id}">
                                        <td>
                                            <span class="badge badge-code">${escapeHtml(o.code)}</span>
                                            <div class="book-sub"><span class="badge sev-${escapeHtml(o.severidade)}">${escapeHtml(o.severidade_label.split(' ')[0])}</span></div>
                                        </td>
                                        <td style="white-space:nowrap;">
                                            <strong>${escapeHtml(o.opened_at_br)}</strong>
                                            ${o.closed_at ? `<div class="book-sub">Fim: ${escapeHtml(o.closed_at_br)}</div>` : ''}
                                        </td>
                                        <td>
                                            <strong>${escapeHtml(o.localidade)}</strong>${o.centro ? ` • ${escapeHtml(o.centro)}` : ''}
                                            <div class="book-sub">${escapeHtml(o.sistema)}</div>
                                        </td>
                                        <td>
                                            <strong>${escapeHtml(o.title)}</strong>${ticketTxt}
                                        </td>
                                        <td><span class="badge status-${escapeHtml(o.status)}">${escapeHtml(o.status_label)}</span></td>
                                        <td style="white-space:nowrap;">${escapeHtml(o.duration_label)}</td>
                                        <td>
                                            <strong>${escapeHtml(o.creator_rank_name)}</strong>
                                            ${updBadge}
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        $$('.book-table tbody tr').forEach((row) => {
            row.addEventListener('click', () => openOccurrenceDetail(Number(row.dataset.occId)));
        });
    }

    async function openOccurrenceDetail(id) {
        const data = await api('get_occurrence', { params: { id } });
        const o = data.occurrence;
        state.currentOccurrence = o;

        $('#detCode').textContent = o.code;
        $('#detStatusBadge').className = `badge status-${o.status}`;
        $('#detStatusBadge').textContent = o.status_label;
        $('#detSevBadge').className = `badge sev-${o.severidade}`;
        $('#detSevBadge').textContent = o.severidade_label;
        $('#detTitle').textContent = o.title;

        const metaPairs = [
            ['Início', o.opened_at_br],
            ['Término', o.closed_at_br],
            ['Duração', o.duration_label],
            ['Localidade', o.localidade + (o.centro ? ` (${o.centro})` : '')],
            ['Sistema', o.sistema],
            o.ticket_externo ? ['Ticket/OS', o.ticket_externo + (o.operadora ? ` (${o.operadora})` : '')] : null,
            ['Autor', o.creator_full_name],
        ].filter(Boolean);

        $('#detMetaGrid').innerHTML = metaPairs.map(([k, v]) =>
            `<span><strong>${escapeHtml(k)}:</strong> ${escapeHtml(v)}</span>`
        ).join('');

        $('#detDescricao').textContent = o.descricao || '—';

        const extraParts = [];
        if (o.acoes_preliminares) extraParts.push(`Ações: ${o.acoes_preliminares}`);
        if (o.impacto_operacional) extraParts.push(`Impacto: ${o.impacto_operacional}`);
        if (extraParts.length) {
            $('#detAcoes').textContent = extraParts.join('\n\n');
            $('#detExtraWrap').classList.remove('hidden');
        } else {
            $('#detExtraWrap').classList.add('hidden');
        }

        const timelineEl = $('#detTimeline');
        if (!o.updates || !o.updates.length) {
            timelineEl.innerHTML = `<div class="muted">Nenhuma atualização registrada após a abertura.</div>`;
        } else {
            timelineEl.innerHTML = o.updates.map((u) => `
                <div class="timeline-item">
                    <div class="timeline-head">
                        <div>
                            <span class="timeline-author">${escapeHtml(u.author_label)}</span>
                            <span class="badge badge-info" style="margin-left:6px;">${escapeHtml(u.update_type_label)}</span>
                            ${u.status_after ? `<span class="badge status-${escapeHtml(u.status_after)}" style="margin-left:4px;">→ ${escapeHtml(u.status_after_label)}</span>` : ''}
                        </div>
                        <span class="timeline-time">${escapeHtml(u.event_at_br)}</span>
                    </div>
                    <div class="timeline-msg">${escapeHtml(u.message)}</div>
                </div>
            `).join('');
        }

        $('#updOccId').value = o.id;
        $('#updType').value = 'atualizacao';
        $('#updStatusAfter').value = '';
        $('#updEventAt').value = localDatetimeInputNow();
        $('#updMessage').value = '';
        $('#updError').classList.add('hidden');

        openModal('modalDetail');
    }

    function copyWhatsappSummary() {
        const o = state.currentOccurrence;
        if (!o) return;
        const lines = [
            `*DTCEA-SJ — ST | ${o.code}*`,
            `*Ocorrência:* ${o.title}`,
            o.ticket_externo ? `*Ticket:* ${o.ticket_externo}${o.operadora ? ' (' + o.operadora + ')' : ''}` : null,
            `*Localidade:* ${o.localidade}${o.centro ? ' | Centro: ' + o.centro : ''}`,
            `*Sistema:* ${o.sistema}`,
            `*Status:* ${o.status_label} | *Início:* ${o.opened_at_br}`,
            '',
            o.descricao,
            o.acoes_preliminares ? `\n*Ações realizadas:*\n${o.acoes_preliminares}` : null,
            o.impacto_operacional ? `\n*Impacto:* ${o.impacto_operacional}` : null,
        ].filter((x) => x !== null).join('\n');

        navigator.clipboard.writeText(lines).then(() => {
            showToast('Resumo copiado para a área de transferência!');
        }).catch(() => {
            showToast('Não foi possível copiar automaticamente.');
        });
    }

    // RELATÓRIO SIGADAER
    function applyReportPreset(preset) {
        const now = new Date();
        let from = new Date(now);
        let to = new Date(now);

        if (preset === 'today') {
            // hoje -> hoje
        } else if (preset === 'week') {
            const day = now.getDay();
            const diffToMon = day === 0 ? 6 : day - 1;
            from.setDate(now.getDate() - diffToMon);
        } else if (preset === 'last7') {
            from.setDate(now.getDate() - 6);
        } else if (preset === 'month') {
            from = new Date(now.getFullYear(), now.getMonth(), 1);
            to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        } else if (preset === 'prev_month') {
            from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            to = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === 'year') {
            from = new Date(now.getFullYear(), 0, 1);
            to = new Date(now.getFullYear(), 11, 31);
        }

        $('#repDateFrom').value = toIsoDate(from);
        $('#repDateTo').value = toIsoDate(to);
        loadReportPreview();
    }

    function getReportQueryParams() {
        return {
            date_from: $('#repDateFrom').value,
            date_to: $('#repDateTo').value,
            status: $('#repStatus').value,
            severidade: $('#repSeverity').value,
            localidade: $('#repLocalidade').value,
        };
    }

    async function loadReportPreview() {
        const params = getReportQueryParams();
        const data = await api('get_report_data', { params });
        const s = data.stats;
        const occs = data.occurrences || [];

        const fmtDate = (iso) => iso ? iso.split('-').reverse().join('/') : '—';
        $('#repPeriodBadge').textContent = `Período: ${fmtDate(params.date_from)} a ${fmtDate(params.date_to)}`;

        $('#reportPreviewStats').innerHTML = `
            <div class="kpi-card"><div class="kpi-label">Total no Período</div><div class="kpi-value">${s.total}</div></div>
            <div class="kpi-card kpi-open"><div class="kpi-label">Pendentes / Abertas</div><div class="kpi-value">${s.aberta + s.em_acompanhamento + s.aguardando_terceiros}</div></div>
            <div class="kpi-card kpi-resolved"><div class="kpi-label">Resolvidas</div><div class="kpi-value">${s.resolvida}</div></div>
            <div class="kpi-card kpi-progress"><div class="kpi-label">MTTR Médio (Resolvidas)</div><div class="kpi-value" style="font-size:1.35rem;">${escapeHtml(s.mttr_human)}</div></div>
        `;

        const wrap = $('#reportPreviewTableWrap');
        if (!occs.length) {
            wrap.innerHTML = `<div class="empty-state">Nenhuma ocorrência registrada neste período com os filtros selecionados.</div>`;
            return;
        }

        wrap.innerHTML = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Início</th>
                        <th>Localidade / Centro</th>
                        <th>Sistema</th>
                        <th>Ticket</th>
                        <th>Severidade</th>
                        <th>Status</th>
                        <th>Duração</th>
                    </tr>
                </thead>
                <tbody>
                    ${occs.map((o) => `
                        <tr>
                            <td><strong>${escapeHtml(o.code)}</strong></td>
                            <td>${escapeHtml(o.opened_at_br)}</td>
                            <td>${escapeHtml(o.localidade)}${o.centro ? '<br><small class="muted">' + escapeHtml(o.centro) + '</small>' : ''}</td>
                            <td>${escapeHtml(o.sistema)}</td>
                            <td>${escapeHtml(o.ticket_externo || '—')}</td>
                            <td><span class="badge sev-${escapeHtml(o.severidade)}">${escapeHtml(o.severidade_label)}</span></td>
                            <td><span class="badge status-${escapeHtml(o.status)}">${escapeHtml(o.status_label)}</span></td>
                            <td>${escapeHtml(o.duration_label)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    // EQUIPE ST
    async function loadTeam() {
        const data = await api('list_users');
        state.users = data.users || [];
        const canManage = state.user && ['admin', 'supervisor'].includes(state.user.role);

        $('#teamTableWrap').innerHTML = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Posto / Nome de Guerra</th>
                        <th>Nome Completo</th>
                        <th>Usuário (Login)</th>
                        <th>Especialidade</th>
                        <th>Perfil</th>
                        <th>Situação</th>
                        ${canManage ? '<th>Ações</th>' : ''}
                    </tr>
                </thead>
                <tbody>
                    ${state.users.map((u) => `
                        <tr>
                            <td><strong>${escapeHtml(u.rank_name)}</strong></td>
                            <td>${escapeHtml(u.full_name)}</td>
                            <td><code>${escapeHtml(u.username)}</code></td>
                            <td>${escapeHtml(u.specialty)}</td>
                            <td>${escapeHtml(u.role)}</td>
                            <td>${Number(u.active) === 1 ? '<span class="badge status-resolvida">Ativo</span>' : '<span class="badge sev-baixa">Inativo</span>'}</td>
                            ${canManage ? `<td><button type="button" class="btn btn-outline btn-sm btn-edit-user" data-uid="${u.id}">Editar</button></td>` : ''}
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;

        if (canManage) {
            $$('.btn-edit-user').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const u = state.users.find((x) => Number(x.id) === Number(btn.dataset.uid));
                    if (!u) return;
                    $('#modalUserTitle').textContent = `Editar Técnico: ${u.rank_name}`;
                    $('#usrId').value = u.id;
                    $('#usrUsername').value = u.username;
                    $('#usrRankName').value = u.rank_name;
                    $('#usrFullName').value = u.full_name;
                    $('#usrSpecialty').value = u.specialty;
                    $('#usrRole').value = u.role;
                    $('#usrActive').value = String(u.active);
                    $('#usrPassword').value = '';
                    $('#usrError').classList.add('hidden');
                    openModal('modalUser');
                });
            });
        }
    }

    // EVENTOS INICIAIS
    document.addEventListener('DOMContentLoaded', async () => {
        // Navegação entre abas
        $$('.nav-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                $$('.nav-tab').forEach((t) => t.classList.remove('active'));
                $$('.tab-panel').forEach((p) => p.classList.remove('active'));
                tab.classList.add('active');
                $(`#tab-${tab.dataset.tab}`).classList.add('active');
                if (tab.dataset.tab === 'team') loadTeam();
                if (tab.dataset.tab === 'report') loadReportPreview();
            });
        });

        // Fechar modais
        $$('[data-close]').forEach((btn) => {
            btn.addEventListener('click', () => closeModal(btn.dataset.close));
        });
        $$('.modal-backdrop').forEach((bd) => {
            bd.addEventListener('click', () => bd.closest('.modal').classList.add('hidden'));
        });

        // Login
        $('#loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            $('#loginError').classList.add('hidden');
            try {
                const data = await api('login', {
                    method: 'POST',
                    body: {
                        username: $('#loginUsername').value.trim(),
                        password: $('#loginPassword').value,
                    },
                });
                setAuthenticated(data.user);
            } catch (err) {
                $('#loginError').textContent = err.message;
                $('#loginError').classList.remove('hidden');
            }
        });

        // Logout
        $('#btnLogout').addEventListener('click', async () => {
            await api('logout', { method: 'POST' }).catch(() => {});
            setUnauthenticated();
        });

        // Filtros do Livro
        let searchTimer;
        const syncSearch = (val) => {
            $('#filterSearch').value = val;
            $('#filterSearchMobile').value = val;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadOccurrences, 250);
        };
        $('#filterSearch').addEventListener('input', (e) => syncSearch(e.target.value));
        $('#filterSearchMobile').addEventListener('input', (e) => syncSearch(e.target.value));
        $('#btnToggleFilters').addEventListener('click', () => {
            $('#filterRow').classList.toggle('mobile-open');
        });
        ['filterStatus', 'filterSeverity', 'filterLocalidade', 'filterDateFrom', 'filterDateTo'].forEach((id) => {
            $(`#${id}`).addEventListener('change', loadOccurrences);
        });
        $('#btnClearFilters').addEventListener('click', () => {
            $('#filterSearch').value = '';
            $('#filterSearchMobile').value = '';
            $('#filterStatus').value = 'todas';
            $('#filterSeverity').value = 'todas';
            $('#filterLocalidade').value = 'todas';
            $('#filterDateFrom').value = '';
            $('#filterDateTo').value = '';
            loadOccurrences();
        });

        // Nova Ocorrência (Desktop + FAB Mobile)
        const openNewOccModal = () => {
            $('#formNewOcc').reset();
            $('#newOpenedAt').value = localDatetimeInputNow();
            $('#newSeveridade').value = 'media';
            $('#newStatus').value = 'aberta';
            $('#newOccError').classList.add('hidden');
            openModal('modalNewOcc');
        };
        $('#btnNewOccurrence').addEventListener('click', openNewOccModal);
        $('#btnFabNew').addEventListener('click', openNewOccModal);

        $('#formNewOcc').addEventListener('submit', async (e) => {
            e.preventDefault();
            $('#newOccError').classList.add('hidden');
            try {
                await api('create_occurrence', {
                    method: 'POST',
                    body: {
                        title: $('#newTitle').value,
                        opened_at: $('#newOpenedAt').value,
                        severidade: $('#newSeveridade').value,
                        localidade: $('#newLocalidade').value,
                        sistema: $('#newSistema').value,
                        status: $('#newStatus').value,
                        ticket_externo: $('#newTicket').value,
                        descricao: $('#newDescricao').value,
                    },
                });
                closeModal('modalNewOcc');
                showToast('Ocorrência registrada com sucesso!');
                await loadCatalogs();
                await loadOccurrences();
            } catch (err) {
                $('#newOccError').textContent = err.message;
                $('#newOccError').classList.remove('hidden');
            }
        });

        // Auto-seleciona status "resolvida" quando usuário escolhe tipo "encerramento"
        $('#updType').addEventListener('change', () => {
            if ($('#updType').value === 'encerramento') {
                $('#updStatusAfter').value = 'resolvida';
            }
        });

        // Adicionar atualização na timeline
        $('#formAddUpdate').addEventListener('submit', async (e) => {
            e.preventDefault();
            $('#updError').classList.add('hidden');
            try {
                const occId = Number($('#updOccId').value);
                await api('add_update', {
                    method: 'POST',
                    body: {
                        occurrence_id: occId,
                        update_type: $('#updType').value,
                        status_after: $('#updStatusAfter').value,
                        event_at: $('#updEventAt').value,
                        message: $('#updMessage').value,
                    },
                });
                showToast('Atualização registrada na trilha de auditoria!');
                await openOccurrenceDetail(occId);
                await loadOccurrences();
            } catch (err) {
                $('#updError').textContent = err.message;
                $('#updError').classList.remove('hidden');
            }
        });

        $('#btnCopyWhatsapp').addEventListener('click', copyWhatsappSummary);

        // Relatório SIGADAER
        $$('[data-preset]').forEach((btn) => {
            btn.addEventListener('click', () => applyReportPreset(btn.dataset.preset));
        });
        $('#btnPreviewReport').addEventListener('click', loadReportPreview);
        $('#btnOpenPrintReport').addEventListener('click', () => {
            const qs = new URLSearchParams(getReportQueryParams()).toString();
            window.open(`report.php?${qs}`, '_blank');
        });

        // Equipe ST
        $('#btnAddUser').addEventListener('click', () => {
            $('#modalUserTitle').textContent = 'Cadastrar Técnico ST';
            $('#formUser').reset();
            $('#usrId').value = '';
            $('#usrActive').value = '1';
            $('#usrError').classList.add('hidden');
            openModal('modalUser');
        });

        $('#formUser').addEventListener('submit', async (e) => {
            e.preventDefault();
            $('#usrError').classList.add('hidden');
            try {
                await api('save_user', {
                    method: 'POST',
                    body: {
                        id: $('#usrId').value ? Number($('#usrId').value) : 0,
                        username: $('#usrUsername').value.trim(),
                        rank_name: $('#usrRankName').value.trim(),
                        full_name: $('#usrFullName').value.trim(),
                        specialty: $('#usrSpecialty').value.trim(),
                        role: $('#usrRole').value,
                        active: $('#usrActive').value === '1',
                        password: $('#usrPassword').value,
                    },
                });
                closeModal('modalUser');
                showToast('Cadastro salvo com sucesso!');
                loadTeam();
            } catch (err) {
                $('#usrError').textContent = err.message;
                $('#usrError').classList.remove('hidden');
            }
        });

        // Alterar própria senha
        $('#btnChangePass').addEventListener('click', () => {
            $('#formPass').reset();
            $('#passError').classList.add('hidden');
            openModal('modalPass');
        });

        $('#formPass').addEventListener('submit', async (e) => {
            e.preventDefault();
            $('#passError').classList.add('hidden');
            try {
                await api('change_password', {
                    method: 'POST',
                    body: {
                        current_password: $('#passCurrent').value,
                        new_password: $('#passNew').value,
                    },
                });
                closeModal('modalPass');
                showToast('Senha alterada com sucesso!');
            } catch (err) {
                $('#passError').textContent = err.message;
                $('#passError').classList.remove('hidden');
            }
        });

        // Verifica sessão existente
        try {
            const me = await api('me');
            if (me.user) {
                setAuthenticated(me.user);
            }
        } catch {
            setUnauthenticated();
        }
    });
})();
