<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('LRO_DTCEASJ_SID');
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user(): ?array
{
    start_app_session();
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) {
        return null;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT id, username, rank_name, full_name, role, specialty, active
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => (int)$uid]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'error' => 'Sessão expirada ou usuário não autenticado.'], 401);
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['ok' => false, 'error' => 'Permissão insuficiente para esta operação.'], 403);
    }
    return $user;
}
