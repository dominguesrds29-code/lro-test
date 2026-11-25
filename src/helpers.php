<?php
declare(strict_types=1);

/**
 * Envia resposta JSON padronizada e encerra a execução.
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Lê o corpo JSON da requisição.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Sanitiza string simples.
 */
function clean_str(?string $value): string
{
    return trim((string)($value ?? ''));
}

/**
 * Formata data/hora ISO ou SQLite (Y-m-d H:i:s) para padrão brasileiro (dd/mm/yyyy HH:ii).
 */
function format_dt_br(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i');
    } catch (Throwable) {
        return $dt;
    }
}

/**
 * Calcula duração legível entre duas datas/horas.
 */
function format_duration(?string $start, ?string $end): string
{
    if (!$start) {
        return '—';
    }
    try {
        $d1 = new DateTime($start);
        $d2 = $end ? new DateTime($end) : new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        if ($d2 < $d1) {
            return '0min';
        }
        $diff = $d1->diff($d2);
        $parts = [];
        $totalDays = (int)$diff->format('%a');
        if ($totalDays > 0) {
            $parts[] = $totalDays . 'd';
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h . 'h';
        }
        if ($diff->i > 0 || empty($parts)) {
            $parts[] = $diff->i . 'min';
        }
        return implode(' ', $parts);
    } catch (Throwable) {
        return '—';
    }
}

/**
 * Retorna minutos entre duas datas/horas.
 */
function diff_minutes(?string $start, ?string $end): ?int
{
    if (!$start || !$end) {
        return null;
    }
    try {
        $d1 = new DateTime($start);
        $d2 = new DateTime($end);
        if ($d2 < $d1) {
            return 0;
        }
        return (int)round(($d2->getTimestamp() - $d1->getTimestamp()) / 60);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Formata minutos em texto legível (ex: 1h 22min).
 */
function format_minutes_human(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . 'min';
    }
    $days = intdiv($minutes, 1440);
    $rem = $minutes % 1440;
    $hours = intdiv($rem, 60);
    $mins = $rem % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($mins > 0) {
        $parts[] = $mins . 'min';
    }
    return implode(' ', $parts);
}

/**
 * Gera próximo código sequencial LRO-YYYY-NNNN.
 */
function generate_occurrence_code(PDO $pdo, string $openedAt): string
{
    $year = date('Y');
    try {
        $year = (new DateTime($openedAt))->format('Y');
    } catch (Throwable) {
    }

    $prefix = "LRO-{$year}-";
    $stmt = $pdo->prepare("SELECT code FROM occurrences WHERE code LIKE :prefix ORDER BY id DESC LIMIT 1");
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNum = 1;
    if ($last && preg_match('/^LRO-\d{4}-(\d+)$/', (string)$last, $m)) {
        $nextNum = (int)$m[1] + 1;
    }
    return sprintf('%s%04d', $prefix, $nextNum);
}

/**
 * Registra evento na trilha de auditoria interna.
 */
function log_audit(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId, string $details = ''): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address, created_at)
         VALUES (:uid, :action, :entity, :eid, :details, :ip, :now)"
    );
    $stmt->execute([
        ':uid' => $userId,
        ':action' => $action,
        ':entity' => $entity,
        ':eid' => $entityId,
        ':details' => $details,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ':now' => (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
    ]);
}
