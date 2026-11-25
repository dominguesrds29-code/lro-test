<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }

    $dbPath = $dbDir . DIRECTORY_SEPARATOR . 'lro.sqlite';
    $isNew = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');

    init_schema($pdo);

    if ($isNew || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        seed_initial_data($pdo);
    }

    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
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
    SQL);
}

function seed_initial_data(PDO $pdo): void
{
    $now = date('Y-m-d H:i:s');
    $defaultHash = password_hash('dtceasj', PASSWORD_DEFAULT);

    // 14 membros da Seção Técnica (ST) do DTCEA-SJ
    $users = [
        ['glaudio',    'Ten Gláudio',     '1º Ten Gláudio (Chefe ST)',      'supervisor', 'Chefia ST'],
        ['ramos',      '2S Ramos',        '2º Sgt Ramos',                   'tecnico',    'Telecomunicações / Rádio'],
        ['sobreaviso', 'Sobreaviso SELT', 'Equipe de Sobreaviso SELT',      'tecnico',    'Sobreaviso Operacional'],
        ['silva',      '1S Silva',        '1º Sgt Silva',                   'admin',      'Administrador LRO / ST'],
        ['oliveira',   '2S Oliveira',     '2º Sgt Oliveira',                'tecnico',    'Rádio / VHF-UHF'],
        ['santos',     '2S Santos',       '2º Sgt Santos',                  'tecnico',    'Redes / MPLS'],
        ['costa',      '3S Costa',        '3º Sgt Costa',                   'tecnico',    'Auxílios à Navegação'],
        ['pereira',    '3S Pereira',      '3º Sgt Pereira',                 'tecnico',    'Energia / Climatização'],
        ['almeida',    '3S Almeida',      '3º Sgt Almeida',                 'tecnico',    'Radar / Vigilância'],
        ['ferreira',   '3S Ferreira',     '3º Sgt Ferreira',                'tecnico',    'Telecomunicações'],
        ['souza',      '3S Souza',        '3º Sgt Souza',                   'tecnico',    'Sistemas Digitais'],
        ['lima',       'CB Lima',         'Cb Lima',                        'tecnico',    'Infraestrutura / Energia'],
        ['rocha',      'CB Rocha',        'Cb Rocha',                       'tecnico',    'Suporte Técnico ST'],
        ['mendes',     '2S Mendes',       '2º Sgt Mendes',                  'tecnico',    'Comunicações / MARC'],
    ];

    $stmtUser = $pdo->prepare(
        "INSERT INTO users (username, password_hash, rank_name, full_name, role, specialty, active, created_at)
         VALUES (:u, :p, :r, :f, :role, :s, 1, :c)"
    );

    $userIds = [];
    foreach ($users as [$u, $r, $f, $role, $spec]) {
        $stmtUser->execute([
            ':u' => $u,
            ':p' => $defaultHash,
            ':r' => $r,
            ':f' => $f,
            ':role' => $role,
            ':s' => $spec,
            ':c' => $now,
        ]);
        $userIds[$u] = (int)$pdo->lastInsertId();
    }

    // Ocorrência 1: Baseada exatamente no registro do WhatsApp anexado (18/10/2026)
    $stmtOcc = $pdo->prepare(
        "INSERT INTO occurrences (
            code, title, localidade, centro, sistema, ticket_externo, operadora,
            severidade, status, descricao, acoes_preliminares, impacto_operacional,
            opened_at, closed_at, created_by, created_at, updated_at
        ) VALUES (
            :code, :title, :loc, :centro, :sys, :ticket, :op,
            :sev, :status, :desc, :acoes, :imp,
            :opened, :closed, :by, :cat, :uat
        )"
    );

    $stmtOcc->execute([
        ':code' => 'LRO-2026-0001',
        ':title' => 'Indisponibilidade Link A MPLS e frequências VHF em Salesópolis (119,375 / 120,850 MHz)',
        ':loc' => 'Salesópolis - SP',
        ':centro' => 'EACEA-HSA_A',
        ':sys' => 'MPLS / VHF (MARC / ICCB)',
        ':ticket' => '10939457',
        ':op' => 'Embratel',
        ':sev' => 'critica',
        ':status' => 'aguardando_terceiros',
        ':desc' => "Link A MPLS inoperante (Indisponibilidade Total).\nA frequência 119,375 MHz (119@375 SL) já se encontrava inoperante.\nNa data de hoje ficou inoperante a frequência 120,850 MHz (120@85 SL), mesmo com o Link MPLS B ativo.",
        ':acoes' => "1. Resetados os rádios no MARC;\n2. Realizado START/STOP nas frequências afetadas;\n3. Comutada a ICCB, sem obtenção de sucesso;\n4. Aberto Ticket nº 10939457 junto à Embratel.",
        ':imp' => 'Frequências operando em modo analógico (contingência).',
        ':opened' => '2026-10-18 20:08:00',
        ':closed' => null,
        ':by' => $userIds['ramos'],
        ':cat' => '2026-10-18 20:08:00',
        ':uat' => '2026-10-18 21:30:00',
    ]);
    $occ1Id = (int)$pdo->lastInsertId();

    $stmtUpd = $pdo->prepare(
        "INSERT INTO occurrence_updates (
            occurrence_id, user_id, author_label, update_type, message, status_after, event_at, created_at
        ) VALUES (:oid, :uid, :author, :type, :msg, :st, :eat, :cat)"
    );

    $updates1 = [
        [
            $userIds['ramos'], '2S Ramos', 'contato_operadora',
            'A Embratel precisa agendar uma data para que um técnico da empresa vá até Salesópolis para verificação e correção da falha.',
            'aguardando_terceiros', '2026-10-18 20:09:00'
        ],
        [
            $userIds['sobreaviso'], 'Sobreaviso SELT', 'restabelecimento_parcial',
            "O link foi restabelecido agora:\n- MPLS A: OK (operacional)\n- MPLS B: OK (operacional)\n- Frequência 120,850 MHz (120@85): OK\n- Frequência 119,375 MHz (119@375): OFF (permanece inoperante)",
            'em_acompanhamento', '2026-10-18 21:26:00'
        ],
        [
            $userIds['glaudio'], 'Ten Gláudio', 'despacho',
            "Formalizado Ticket nº 10939457 junto à Embratel referente à indisponibilidade total do Link A MPLS. Pelo Link B MPLS permanecem indisponíveis as frequências 119,375 MHz e 120,850 MHz (operando por meio analógico). A Embratel aguarda agendamento de visita técnica a Salesópolis.\nSolicitado acionamento para a EACEA-HSA em 19 OUT 2026 para verificação das condições operacionais e adoção das providências cabíveis.",
            'aguardando_terceiros', '2026-10-18 21:30:00'
        ],
    ];

    foreach ($updates1 as [$uid, $author, $type, $msg, $st, $eat]) {
        $stmtUpd->execute([
            ':oid' => $occ1Id, ':uid' => $uid, ':author' => $author,
            ':type' => $type, ':msg' => $msg, ':st' => $st,
            ':eat' => $eat, ':cat' => $eat,
        ]);
    }

    // Ocorrência 2: Exemplo resolvido em Novembro/2026
    $stmtOcc->execute([
        ':code' => 'LRO-2026-0002',
        ':title' => 'Alarme de falha no Retificador 02 — Sala de Equipamentos Sede',
        ':loc' => 'DTCEA-SJ (Sede)',
        ':centro' => 'Casa de Força / Sala Técnica',
        ':sys' => 'Energia / No-Break CC',
        ':ticket' => 'OS-INT-2026-041',
        ':op' => 'Interno ST',
        ':sev' => 'alta',
        ':status' => 'resolvida',
        ':desc' => 'Identificado alarme de subtensão no módulo retificador 02 (-48VCC). Banco de baterias assumiu carga normalmente sem interrupção dos rádios.',
        ':acoes' => 'Verificação de disjuntores de entrada CA, medição de tensão por fase e inspeção térmica dos conectores.',
        ':imp' => 'Sem perda operacional. Sistema mantido pelo Retificador 01 e banco de baterias.',
        ':opened' => '2026-11-12 09:15:00',
        ':closed' => '2026-11-12 11:40:00',
        ':by' => $userIds['pereira'],
        ':cat' => '2026-11-12 09:15:00',
        ':uat' => '2026-11-12 11:40:00',
    ]);
    $occ2Id = (int)$pdo->lastInsertId();

    $stmtUpd->execute([
        ':oid' => $occ2Id, ':uid' => $userIds['lima'], ':author' => 'CB Lima',
        ':type' => 'encerramento',
        ':msg' => 'Substituído módulo retificador reserva e normalizado barramento -48VCC. Sistema testado em carga por 30 minutos sem novos alarmes.',
        ':st' => 'resolvida', ':eat' => '2026-11-12 11:40:00', ':cat' => '2026-11-12 11:40:00',
    ]);

    // Ocorrência 3: Exemplo recente na semana atual (Novembro/2026)
    $stmtOcc->execute([
        ':code' => 'LRO-2026-0003',
        ':title' => 'Oscilação de enlace de dados secundário — Estação Remota',
        ':loc' => 'DTCEA-SJ (Sede)',
        ':centro' => 'TORRE / APP-SJ',
        ':sys' => 'Redes / Enlace de Dados',
        ':ticket' => '11024890',
        ':op' => 'Telebras',
        ':sev' => 'media',
        ':status' => 'em_acompanhamento',
        ':desc' => 'Registradas perdas de pacotes intermitentes (3% a 5%) no enlace secundário durante janela da manhã. Enlace principal operando nominalmente.',
        ':acoes' => 'Realizado ping estendido, conferência de atenuação óptica na SFP e abertura de chamado preventivo.',
        ':imp' => 'Operação normal pelo enlace principal; redundância degradada.',
        ':opened' => '2026-11-23 14:20:00',
        ':closed' => null,
        ':by' => $userIds['santos'],
        ':cat' => '2026-11-23 14:20:00',
        ':uat' => '2026-11-24 08:10:00',
    ]);
    $occ3Id = (int)$pdo->lastInsertId();

    $stmtUpd->execute([
        ':oid' => $occ3Id, ':uid' => $userIds['santos'], ':author' => '2S Santos',
        ':type' => 'atualizacao',
        ':msg' => 'Operadora informou limpeza de conector óptico no POP regional. Monitorando estabilidade por 48h antes do encerramento.',
        ':st' => 'em_acompanhamento', ':eat' => '2026-11-24 08:10:00', ':cat' => '2026-11-24 08:10:00',
    ]);
}
