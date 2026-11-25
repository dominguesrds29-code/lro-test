<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/occurrence_service.php';

start_app_session();
$pdo = get_db();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'login': {
            $body = read_json_body();
            $username = strtolower(clean_str($body['username'] ?? ''));
            $password = (string)($body['password'] ?? '');

            if ($username === '' || $password === '') {
                json_response(['ok' => false, 'error' => 'Informe usuário e senha.'], 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();

            if (!$user || (int)$user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
                log_audit($pdo, null, 'login_failed', 'users', null, "Tentativa para usuário: {$username}");
                json_response(['ok' => false, 'error' => 'Usuário ou senha inválidos (ou conta inativa).'], 401);
            }

            $_SESSION['user_id'] = (int)$user['id'];
            log_audit($pdo, (int)$user['id'], 'login_success', 'users', (int)$user['id'], "Login de {$user['rank_name']}");

            unset($user['password_hash']);
            json_response(['ok' => true, 'user' => $user]);
        }

        case 'logout': {
            $user = current_user();
            if ($user) {
                log_audit($pdo, (int)$user['id'], 'logout', 'users', (int)$user['id'], '');
            }
            session_destroy();
            json_response(['ok' => true]);
        }

        case 'me': {
            $user = current_user();
            json_response(['ok' => true, 'user' => $user]);
        }

        case 'change_password': {
            $user = require_auth();
            $body = read_json_body();
            $current = (string)($body['current_password'] ?? '');
            $next = (string)($body['new_password'] ?? '');

            if (strlen($next) < 5) {
                json_response(['ok' => false, 'error' => 'A nova senha deve ter pelo menos 5 caracteres.'], 400);
            }

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute([':id' => (int)$user['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                json_response(['ok' => false, 'error' => 'Senha atual incorreta.'], 400);
            }

            $newHash = password_hash($next, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute([':h' => $newHash, ':id' => (int)$user['id']]);
            log_audit($pdo, (int)$user['id'], 'change_password', 'users', (int)$user['id'], 'Senha alterada pelo próprio usuário');

            json_response(['ok' => true]);
        }

        case 'get_catalogs': {
            require_auth();
            $locs = $pdo->query("SELECT DISTINCT localidade FROM occurrences WHERE localidade != '' ORDER BY localidade")->fetchAll(PDO::FETCH_COLUMN);
            $sys = $pdo->query("SELECT DISTINCT sistema FROM occurrences WHERE sistema != '' ORDER BY sistema")->fetchAll(PDO::FETCH_COLUMN);
            $ops = $pdo->query("SELECT DISTINCT operadora FROM occurrences WHERE operadora != '' ORDER BY operadora")->fetchAll(PDO::FETCH_COLUMN);

            $defaultLocs = ['DTCEA-SJ (Sede)', 'Salesópolis - SP', 'EACEA-HSA', 'Pico do Couto', 'Campos do Jordão - SP'];
            $defaultSys = ['MPLS / VHF (MARC / ICCB)', 'Redes / Enlace de Dados', 'VHF / UHF Rádio', 'Energia / No-Break CC', 'Radar / Vigilância', 'Auxílios à Navegação (VOR/DME/ILS)', 'Climatização / Infraestrutura'];
            $defaultOps = ['Interno ST', 'Embratel', 'Telebras', 'Claro', 'Vivo', 'EDP / Concessionária'];

            $localidades = array_values(array_unique(array_merge($defaultLocs, $locs)));
            $sistemas = array_values(array_unique(array_merge($defaultSys, $sys)));
            $operadoras = array_values(array_unique(array_merge($defaultOps, $ops)));

            json_response([
                'ok' => true,
                'localidades' => $localidades,
                'sistemas' => $sistemas,
                'operadoras' => $operadoras,
                'statuses' => STATUS_LABELS,
                'severidades' => SEVERITY_LABELS,
                'update_types' => UPDATE_TYPE_LABELS,
            ]);
        }

        case 'list_occurrences': {
            require_auth();
            $filters = [
                'date_from' => clean_str($_GET['date_from'] ?? ''),
                'date_to' => clean_str($_GET['date_to'] ?? ''),
                'status' => clean_str($_GET['status'] ?? ''),
                'severidade' => clean_str($_GET['severidade'] ?? ''),
                'localidade' => clean_str($_GET['localidade'] ?? ''),
                'sistema' => clean_str($_GET['sistema'] ?? ''),
                'search' => clean_str($_GET['search'] ?? ''),
            ];
            $rows = query_occurrences($pdo, $filters, 'DESC');

            // Contadores globais rápidos para o painel
            $counts = $pdo->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'aberta' THEN 1 ELSE 0 END) AS aberta,
                    SUM(CASE WHEN status = 'em_acompanhamento' THEN 1 ELSE 0 END) AS em_acompanhamento,
                    SUM(CASE WHEN status = 'aguardando_terceiros' THEN 1 ELSE 0 END) AS aguardando_terceiros,
                    SUM(CASE WHEN status = 'resolvida' THEN 1 ELSE 0 END) AS resolvida,
                    SUM(CASE WHEN status != 'resolvida' AND severidade = 'critica' THEN 1 ELSE 0 END) AS criticas_abertas
                FROM occurrences
            ")->fetch();

            json_response(['ok' => true, 'occurrences' => $rows, 'counts' => $counts]);
        }

        case 'get_occurrence': {
            require_auth();
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'error' => 'ID inválido.'], 400);
            }
            $occ = get_occurrence_detail($pdo, $id);
            if (!$occ) {
                json_response(['ok' => false, 'error' => 'Ocorrência não encontrada.'], 404);
            }
            json_response(['ok' => true, 'occurrence' => $occ]);
        }

        case 'create_occurrence': {
            $user = require_auth();
            $body = read_json_body();

            $title = clean_str($body['title'] ?? '');
            $localidade = clean_str($body['localidade'] ?? '');
            $centro = clean_str($body['centro'] ?? '');
            $sistema = clean_str($body['sistema'] ?? '');
            $ticket = clean_str($body['ticket_externo'] ?? '');
            $operadora = clean_str($body['operadora'] ?? '');
            $severidade = clean_str($body['severidade'] ?? 'media');
            $status = clean_str($body['status'] ?? 'aberta');
            $descricao = clean_str($body['descricao'] ?? '');
            $acoes = clean_str($body['acoes_preliminares'] ?? '');
            $impacto = clean_str($body['impacto_operacional'] ?? '');
            $openedAtRaw = clean_str($body['opened_at'] ?? '');

            if ($title === '' || $localidade === '' || $sistema === '' || $descricao === '') {
                json_response(['ok' => false, 'error' => 'Preencha Título, Localidade, Sistema e Descrição.'], 400);
            }

            if (!array_key_exists($severidade, SEVERITY_LABELS)) {
                $severidade = 'media';
            }
            if (!array_key_exists($status, STATUS_LABELS)) {
                $status = 'aberta';
            }

            $now = date('Y-m-d H:i:s');
            $openedAt = $now;
            if ($openedAtRaw !== '') {
                $ts = strtotime(str_replace('T', ' ', $openedAtRaw));
                if ($ts !== false) {
                    $openedAt = date('Y-m-d H:i:s', $ts);
                }
            }
            $closedAt = ($status === 'resolvida') ? $now : null;

            $code = generate_occurrence_code($pdo, $openedAt);

            $stmt = $pdo->prepare("
                INSERT INTO occurrences (
                    code, title, localidade, centro, sistema, ticket_externo, operadora,
                    severidade, status, descricao, acoes_preliminares, impacto_operacional,
                    opened_at, closed_at, created_by, created_at, updated_at
                ) VALUES (
                    :code, :title, :loc, :centro, :sys, :ticket, :op,
                    :sev, :status, :desc, :acoes, :imp,
                    :opened, :closed, :by, :cat, :uat
                )
            ");
            $stmt->execute([
                ':code' => $code,
                ':title' => $title,
                ':loc' => $localidade,
                ':centro' => $centro,
                ':sys' => $sistema,
                ':ticket' => $ticket,
                ':op' => $operadora,
                ':sev' => $severidade,
                ':status' => $status,
                ':desc' => $descricao,
                ':acoes' => $acoes,
                ':imp' => $impacto,
                ':opened' => $openedAt,
                ':closed' => $closedAt,
                ':by' => (int)$user['id'],
                ':cat' => $now,
                ':uat' => $now,
            ]);

            $newId = (int)$pdo->lastInsertId();
            log_audit($pdo, (int)$user['id'], 'create_occurrence', 'occurrences', $newId, "Criada ocorrência {$code}: {$title}");

            $created = get_occurrence_detail($pdo, $newId);
            json_response(['ok' => true, 'occurrence' => $created], 201);
        }

        case 'add_update': {
            $user = require_auth();
            $body = read_json_body();

            $occId = (int)($body['occurrence_id'] ?? 0);
            $updateType = clean_str($body['update_type'] ?? 'atualizacao');
            $message = clean_str($body['message'] ?? '');
            $statusAfter = clean_str($body['status_after'] ?? '');
            $eventAtRaw = clean_str($body['event_at'] ?? '');

            if ($occId <= 0 || $message === '') {
                json_response(['ok' => false, 'error' => 'Ocorrência e descrição da atualização são obrigatórios.'], 400);
            }
            if (!array_key_exists($updateType, UPDATE_TYPE_LABELS)) {
                $updateType = 'atualizacao';
            }

            $occ = get_occurrence_detail($pdo, $occId);
            if (!$occ) {
                json_response(['ok' => false, 'error' => 'Ocorrência não encontrada.'], 404);
            }

            $now = date('Y-m-d H:i:s');
            $eventAt = $now;
            if ($eventAtRaw !== '') {
                $ts = strtotime(str_replace('T', ' ', $eventAtRaw));
                if ($ts !== false) {
                    $eventAt = date('Y-m-d H:i:s', $ts);
                }
            }

            $newStatus = null;
            if ($statusAfter !== '' && array_key_exists($statusAfter, STATUS_LABELS)) {
                $newStatus = $statusAfter;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO occurrence_updates (
                    occurrence_id, user_id, author_label, update_type, message, status_after, event_at, created_at
                ) VALUES (:oid, :uid, :author, :type, :msg, :st, :eat, :cat)
            ");
            $stmt->execute([
                ':oid' => $occId,
                ':uid' => (int)$user['id'],
                ':author' => $user['rank_name'],
                ':type' => $updateType,
                ':msg' => $message,
                ':st' => $newStatus,
                ':eat' => $eventAt,
                ':cat' => $now,
            ]);

            if ($newStatus !== null) {
                $closedAt = $occ['closed_at'];
                if ($newStatus === 'resolvida') {
                    $closedAt = $eventAt;
                } elseif ($occ['status'] === 'resolvida' && $newStatus !== 'resolvida') {
                    $closedAt = null;
                }
                $pdo->prepare("UPDATE occurrences SET status = :st, closed_at = :cl, updated_at = :uat WHERE id = :id")
                    ->execute([':st' => $newStatus, ':cl' => $closedAt, ':uat' => $now, ':id' => $occId]);
            } else {
                $pdo->prepare("UPDATE occurrences SET updated_at = :uat WHERE id = :id")
                    ->execute([':uat' => $now, ':id' => $occId]);
            }

            $pdo->commit();
            log_audit($pdo, (int)$user['id'], 'add_update', 'occurrences', $occId, "Atualização ({$updateType}) em {$occ['code']}");

            $updated = get_occurrence_detail($pdo, $occId);
            json_response(['ok' => true, 'occurrence' => $updated]);
        }

        case 'update_occurrence': {
            $user = require_auth();
            $body = read_json_body();
            $occId = (int)($body['id'] ?? 0);
            $occ = get_occurrence_detail($pdo, $occId);
            if (!$occ) {
                json_response(['ok' => false, 'error' => 'Ocorrência não encontrada.'], 404);
            }

            $title = clean_str($body['title'] ?? $occ['title']);
            $localidade = clean_str($body['localidade'] ?? $occ['localidade']);
            $centro = clean_str($body['centro'] ?? $occ['centro']);
            $sistema = clean_str($body['sistema'] ?? $occ['sistema']);
            $ticket = clean_str($body['ticket_externo'] ?? $occ['ticket_externo']);
            $operadora = clean_str($body['operadora'] ?? $occ['operadora']);
            $severidade = clean_str($body['severidade'] ?? $occ['severidade']);
            $impacto = clean_str($body['impacto_operacional'] ?? $occ['impacto_operacional']);

            if ($title === '' || $localidade === '' || $sistema === '') {
                json_response(['ok' => false, 'error' => 'Título, Localidade e Sistema são obrigatórios.'], 400);
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare("
                UPDATE occurrences SET
                    title = :t, localidade = :l, centro = :c, sistema = :s,
                    ticket_externo = :tk, operadora = :op, severidade = :sv,
                    impacto_operacional = :imp, updated_at = :uat
                WHERE id = :id
            ")->execute([
                ':t' => $title, ':l' => $localidade, ':c' => $centro, ':s' => $sistema,
                ':tk' => $ticket, ':op' => $operadora, ':sv' => $severidade,
                ':imp' => $impacto, ':uat' => $now, ':id' => $occId,
            ]);

            log_audit($pdo, (int)$user['id'], 'update_occurrence', 'occurrences', $occId, "Metadados atualizados em {$occ['code']}");
            json_response(['ok' => true, 'occurrence' => get_occurrence_detail($pdo, $occId)]);
        }

        case 'get_report_data': {
            require_auth();
            $filters = [
                'date_from' => clean_str($_GET['date_from'] ?? ''),
                'date_to' => clean_str($_GET['date_to'] ?? ''),
                'status' => clean_str($_GET['status'] ?? ''),
                'severidade' => clean_str($_GET['severidade'] ?? ''),
                'localidade' => clean_str($_GET['localidade'] ?? ''),
                'sistema' => clean_str($_GET['sistema'] ?? ''),
            ];
            $payload = build_report_payload($pdo, $filters);
            json_response(['ok' => true] + $payload);
        }

        case 'list_users': {
            require_auth();
            $rows = $pdo->query("
                SELECT id, username, rank_name, full_name, role, specialty, active, created_at
                FROM users
                ORDER BY active DESC, rank_name ASC
            ")->fetchAll();
            json_response(['ok' => true, 'users' => $rows]);
        }

        case 'save_user': {
            $actor = require_role(['admin', 'supervisor']);
            $body = read_json_body();
            $id = (int)($body['id'] ?? 0);
            $username = strtolower(clean_str($body['username'] ?? ''));
            $rankName = clean_str($body['rank_name'] ?? '');
            $fullName = clean_str($body['full_name'] ?? '');
            $role = clean_str($body['role'] ?? 'tecnico');
            $specialty = clean_str($body['specialty'] ?? 'ST - Manutenção');
            $active = isset($body['active']) ? (int)(bool)$body['active'] : 1;
            $password = (string)($body['password'] ?? '');

            if ($username === '' || $rankName === '' || $fullName === '') {
                json_response(['ok' => false, 'error' => 'Usuário, Posto/Nome de Guerra e Nome Completo são obrigatórios.'], 400);
            }
            if (!in_array($role, ['tecnico', 'supervisor', 'admin'], true)) {
                $role = 'tecnico';
            }

            if ($id > 0) {
                $pdo->prepare("
                    UPDATE users SET username = :u, rank_name = :r, full_name = :f, role = :role, specialty = :sp, active = :act
                    WHERE id = :id
                ")->execute([
                    ':u' => $username, ':r' => $rankName, ':f' => $fullName,
                    ':role' => $role, ':sp' => $specialty, ':act' => $active, ':id' => $id,
                ]);
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                        ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
                }
                log_audit($pdo, (int)$actor['id'], 'update_user', 'users', $id, "Atualizado técnico {$rankName}");
            } else {
                $passToUse = $password !== '' ? $password : 'dtceasj';
                $pdo->prepare("
                    INSERT INTO users (username, password_hash, rank_name, full_name, role, specialty, active, created_at)
                    VALUES (:u, :p, :r, :f, :role, :sp, :act, :cat)
                ")->execute([
                    ':u' => $username,
                    ':p' => password_hash($passToUse, PASSWORD_DEFAULT),
                    ':r' => $rankName,
                    ':f' => $fullName,
                    ':role' => $role,
                    ':sp' => $specialty,
                    ':act' => $active,
                    ':cat' => date('Y-m-d H:i:s'),
                ]);
                $newUid = (int)$pdo->lastInsertId();
                log_audit($pdo, (int)$actor['id'], 'create_user', 'users', $newUid, "Cadastrado técnico {$rankName}");
            }

            json_response(['ok' => true]);
        }

        default:
            json_response(['ok' => false, 'error' => 'Ação desconhecida.'], 404);
    }
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Registro duplicado (usuário ou código já existente).'], 409);
    }
    json_response(['ok' => false, 'error' => 'Erro de banco de dados: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()], 500);
}
