CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    rank_name TEXT NOT NULL,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'tecnico',
    specialty TEXT NOT NULL DEFAULT 'ST - Manutenção',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    localidade TEXT NOT NULL,
    centro TEXT NOT NULL DEFAULT '',
    sistema TEXT NOT NULL,
    ticket_externo TEXT NOT NULL DEFAULT '',
    operadora TEXT NOT NULL DEFAULT '',
    severidade TEXT NOT NULL DEFAULT 'media',
    status TEXT NOT NULL DEFAULT 'aberta',
    descricao TEXT NOT NULL,
    acoes_preliminares TEXT NOT NULL DEFAULT '',
    impacto_operacional TEXT NOT NULL DEFAULT '',
    opened_at TEXT NOT NULL,
    closed_at TEXT DEFAULT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS occurrence_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    occurrence_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    author_label TEXT NOT NULL,
    update_type TEXT NOT NULL DEFAULT 'atualizacao',
    message TEXT NOT NULL,
    status_after TEXT DEFAULT NULL,
    event_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (occurrence_id) REFERENCES occurrences(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    action TEXT NOT NULL,
    entity TEXT NOT NULL,
    entity_id INTEGER DEFAULT NULL,
    details TEXT NOT NULL DEFAULT '',
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_occ_opened_at ON occurrences(opened_at);
CREATE INDEX IF NOT EXISTS idx_occ_status ON occurrences(status);
CREATE INDEX IF NOT EXISTS idx_occ_localidade ON occurrences(localidade);
CREATE INDEX IF NOT EXISTS idx_upd_occ_id ON occurrence_updates(occurrence_id);

-- Efetivo inicial da ST (senha padrão: dtceasj)
INSERT OR IGNORE INTO users (id, username, password_hash, rank_name, full_name, role, specialty, active, created_at) VALUES
(1,  'glaudio',    'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'Ten. Gláudio',     '1º Ten Gláudio (Chefe ST)', 'supervisor', 'Chefia ST',             1, '2026-10-18 20:00:00'),
(2,  'domingues',  'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'SO Domingues',     'Suboficial Domingues',      'admin',      'Administrador LRO / ST', 1, '2026-10-18 20:00:00'),
(3,  'alex',       'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'SO Alex',          'Suboficial Alex',           'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(4,  'michely',    'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'SO Michely',       'Suboficial Michely',        'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(5,  'pereira',    'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'SO Pereira',       'Suboficial Pereira',        'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(6,  'claudionor', 'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', 'SO R1 Claudionor', 'SO R1 Claudionor',          'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(7,  'ramos1',     'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '1S Ramos',         '1º Sgt Ramos',              'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(8,  'bruno',      'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '1S Bruno Silva',   '1º Sgt Bruno Silva',        'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(9,  'bianca',     'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '1S Bianca',        '1º Sgt Bianca',             'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(10, 'nogueira',   'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '1S Nogueira',      '1º Sgt Nogueira',           'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(11, 'ramos',      'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '2S Ramos',         '2º Sgt Ramos',              'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(12, 'marrani',    'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '2S Marrani',       '2º Sgt Marrani',            'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00'),
(13, 'anaflavia',  'pbkdf2_sha256$100000$55a147d3b88f0b2fa574e00c0939fbed$d2b082d50f5f3142486394ec0da13e5990403b0ecbb8517d7ff4192898e1fd0f', '2S Ana Flávia',    '2º Sgt Ana Flávia',         'tecnico',    'Seção Técnica',          1, '2026-10-18 20:00:00');

INSERT OR IGNORE INTO occurrences (id, code, title, localidade, centro, sistema, ticket_externo, operadora, severidade, status, descricao, acoes_preliminares, impacto_operacional, opened_at, closed_at, created_by, created_at, updated_at) VALUES
(1, 'LRO-2026-0001', 'Indisponibilidade Link A MPLS e frequências VHF em Salesópolis (119,375 / 120,850 MHz)', 'Salesópolis - SP', 'EACEA-HSA_A', 'MPLS / VHF (MARC / ICCB)', '10939457', 'Embratel', 'critica', 'aguardando_terceiros',
 'Link A MPLS inoperante (Indisponibilidade Total).
A frequência 119,375 MHz (119@375 SL) já se encontrava inoperante.
Na data de hoje ficou inoperante a frequência 120,850 MHz (120@85 SL), mesmo com o Link MPLS B ativo.',
 '1. Resetados os rádios no MARC;
2. Realizado START/STOP nas frequências afetadas;
3. Comutada a ICCB, sem obtenção de sucesso;
4. Aberto Ticket nº 10939457 junto à Embratel.',
 'Frequências operando em modo analógico (contingência).', '2026-10-18 20:08:00', NULL, 11, '2026-10-18 20:08:00', '2026-10-18 21:30:00'),
(2, 'LRO-2026-0002', 'Alarme de falha no Retificador 02 — Sala de Equipamentos Sede', 'DTCEA-SJ (Sede)', 'Casa de Força / Sala Técnica', 'Energia / No-Break CC', 'OS-INT-2026-041', 'Interno ST', 'alta', 'resolvida',
 'Identificado alarme de subtensão no módulo retificador 02 (-48VCC). Banco de baterias assumiu carga normalmente sem interrupção dos rádios.',
 'Verificação de disjuntores de entrada CA, medição de tensão por fase e inspeção térmica dos conectores.',
 'Sem perda operacional. Sistema mantido pelo Retificador 01 e banco de baterias.', '2026-11-12 09:15:00', '2026-11-12 11:40:00', 5, '2026-11-12 09:15:00', '2026-11-12 11:40:00'),
(3, 'LRO-2026-0003', 'Oscilação de enlace de dados secundário — Estação Remota', 'DTCEA-SJ (Sede)', 'TORRE / APP-SJ', 'Redes / Enlace de Dados', '11024890', 'Telebras', 'media', 'em_acompanhamento',
 'Registradas perdas de pacotes intermitentes (3% a 5%) no enlace secundário durante janela da manhã. Enlace principal operando nominalmente.',
 'Realizado ping estendido, conferência de atenuação óptica na SFP e abertura de chamado preventivo.',
 'Operação normal pelo enlace principal; redundância degradada.', '2026-11-23 14:20:00', NULL, 10, '2026-11-23 14:20:00', '2026-11-24 08:10:00');

INSERT OR IGNORE INTO occurrence_updates (id, occurrence_id, user_id, author_label, update_type, message, status_after, event_at, created_at) VALUES
(1, 1, 11, '2S Ramos', 'contato_operadora', 'A Embratel precisa agendar uma data para que um técnico da empresa vá até Salesópolis para verificação e correção da falha.', 'aguardando_terceiros', '2026-10-18 20:09:00', '2026-10-18 20:09:00'),
(2, 1, 3, 'SO Alex', 'restabelecimento_parcial', 'O link foi restabelecido agora:
- MPLS A: OK (operacional)
- MPLS B: OK (operacional)
- Frequência 120,850 MHz (120@85): OK
- Frequência 119,375 MHz (119@375): OFF (permanece inoperante)', 'em_acompanhamento', '2026-10-18 21:26:00', '2026-10-18 21:26:00'),
(3, 1, 1, 'Ten. Gláudio', 'despacho', 'Formalizado Ticket nº 10939457 junto à Embratel referente à indisponibilidade total do Link A MPLS. Pelo Link B MPLS permanecem indisponíveis as frequências 119,375 MHz e 120,850 MHz (operando por meio analógico). A Embratel aguarda agendamento de visita técnica a Salesópolis.
Solicitado acionamento para a EACEA-HSA em 19 OUT 2026 para verificação das condições operacionais e adoção das providências cabíveis.', 'aguardando_terceiros', '2026-10-18 21:30:00', '2026-10-18 21:30:00'),
(4, 2, 2, 'SO Domingues', 'encerramento', 'Substituído módulo retificador reserva e normalizado barramento -48VCC. Sistema testado em carga por 30 minutos sem novos alarmes.', 'resolvida', '2026-11-12 11:40:00', '2026-11-12 11:40:00'),
(5, 3, 10, '1S Nogueira', 'atualizacao', 'Operadora informou limpeza de conector óptico no POP regional. Monitorando estabilidade por 48h antes do encerramento.', 'em_acompanhamento', '2026-11-24 08:10:00', '2026-11-24 08:10:00');
