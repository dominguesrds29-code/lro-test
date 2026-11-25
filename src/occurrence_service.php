<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const STATUS_LABELS = [
    'aberta' => 'Aberta',
    'em_acompanhamento' => 'Em Acompanhamento',
    'aguardando_terceiros' => 'Aguardando Terceiros',
    'resolvida' => 'Resolvida / Encerrada',
];

const SEVERITY_LABELS = [
    'critica' => 'Crítica (Indisponibilidade)',
    'alta' => 'Alta (Degradação Severa)',
    'media' => 'Média (Contingência / Parcial)',
    'baixa' => 'Baixa / Informativo',
];

const UPDATE_TYPE_LABELS = [
    'atualizacao' => 'Atualização Técnica',
    'contato_operadora' => 'Contato Operadora / Terceiro',
    'restabelecimento_parcial' => 'Restabelecimento Parcial',
    'despacho' => 'Despacho / Acionamento',
    'encerramento' => 'Encerramento / Restabelecimento',
];

function build_occurrence_where(array $filters, array &$params): string
{
    $clauses = ['1=1'];

    if (!empty($filters['date_from'])) {
        $clauses[] = 'o.opened_at >= :date_from';
        $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $clauses[] = 'o.opened_at <= :date_to';
        $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['status']) && $filters['status'] !== 'todas') {
        if ($filters['status'] === 'pendentes') {
            $clauses[] = "o.status != 'resolvida'";
        } else {
            $clauses[] = 'o.status = :status';
            $params[':status'] = $filters['status'];
        }
    }
    if (!empty($filters['severidade']) && $filters['severidade'] !== 'todas') {
        $clauses[] = 'o.severidade = :severidade';
        $params[':severidade'] = $filters['severidade'];
    }
    if (!empty($filters['localidade']) && $filters['localidade'] !== 'todas') {
        $clauses[] = 'o.localidade = :localidade';
        $params[':localidade'] = $filters['localidade'];
    }
    if (!empty($filters['sistema']) && $filters['sistema'] !== 'todos') {
        $clauses[] = 'o.sistema = :sistema';
        $params[':sistema'] = $filters['sistema'];
    }
    if (!empty($filters['search'])) {
        $clauses[] = '(o.code LIKE :q OR o.title LIKE :q OR o.localidade LIKE :q OR o.centro LIKE :q OR o.sistema LIKE :q OR o.ticket_externo LIKE :q OR o.descricao LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }

    return implode(' AND ', $clauses);
}

function query_occurrences(PDO $pdo, array $filters, string $order = 'DESC'): array
{
    $params = [];
    $where = build_occurrence_where($filters, $params);
    $dir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $sql = "
        SELECT
            o.*,
            u.rank_name AS creator_rank_name,
            u.full_name AS creator_full_name,
            (SELECT COUNT(*) FROM occurrence_updates ou WHERE ou.occurrence_id = o.id) AS updates_count
        FROM occurrences o
        JOIN users u ON u.id = o.created_by
        WHERE {$where}
        ORDER BY o.opened_at {$dir}, o.id {$dir}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['duration_label'] = format_duration($row['opened_at'], $row['closed_at']);
        $row['opened_at_br'] = format_dt_br($row['opened_at']);
        $row['closed_at_br'] = format_dt_br($row['closed_at']);
        $row['status_label'] = STATUS_LABELS[$row['status']] ?? $row['status'];
        $row['severidade_label'] = SEVERITY_LABELS[$row['severidade']] ?? $row['severidade'];
    }

    return $rows;
}

function get_occurrence_detail(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT o.*, u.rank_name AS creator_rank_name, u.full_name AS creator_full_name
        FROM occurrences o
        JOIN users u ON u.id = o.created_by
        WHERE o.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $occ = $stmt->fetch();
    if (!$occ) {
        return null;
    }

    $occ['duration_label'] = format_duration($occ['opened_at'], $occ['closed_at']);
    $occ['opened_at_br'] = format_dt_br($occ['opened_at']);
    $occ['closed_at_br'] = format_dt_br($occ['closed_at']);
    $occ['status_label'] = STATUS_LABELS[$occ['status']] ?? $occ['status'];
    $occ['severidade_label'] = SEVERITY_LABELS[$occ['severidade']] ?? $occ['severidade'];

    $stmtUpd = $pdo->prepare("
        SELECT ou.*, u.rank_name, u.full_name
        FROM occurrence_updates ou
        JOIN users u ON u.id = ou.user_id
        WHERE ou.occurrence_id = :id
        ORDER BY ou.event_at ASC, ou.id ASC
    ");
    $stmtUpd->execute([':id' => $id]);
    $updates = $stmtUpd->fetchAll();
    foreach ($updates as &$upd) {
        $upd['event_at_br'] = format_dt_br($upd['event_at']);
        $upd['update_type_label'] = UPDATE_TYPE_LABELS[$upd['update_type']] ?? $upd['update_type'];
        $upd['status_after_label'] = $upd['status_after'] ? (STATUS_LABELS[$upd['status_after']] ?? $upd['status_after']) : null;
    }

    $occ['updates'] = $updates;
    return $occ;
}

function build_report_payload(PDO $pdo, array $filters): array
{
    $occurrences = query_occurrences($pdo, $filters, 'ASC');

    // Carregar atualizações de todas as ocorrências do relatório em uma consulta
    $ids = array_map(fn($r) => (int)$r['id'], $occurrences);
    $updatesByOcc = [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT ou.*, u.rank_name, u.full_name
            FROM occurrence_updates ou
            JOIN users u ON u.id = ou.user_id
            WHERE ou.occurrence_id IN ({$placeholders})
            ORDER BY ou.event_at ASC, ou.id ASC
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $upd) {
            $upd['event_at_br'] = format_dt_br($upd['event_at']);
            $upd['update_type_label'] = UPDATE_TYPE_LABELS[$upd['update_type']] ?? $upd['update_type'];
            $upd['status_after_label'] = $upd['status_after'] ? (STATUS_LABELS[$upd['status_after']] ?? $upd['status_after']) : null;
            $updatesByOcc[(int)$upd['occurrence_id']][] = $upd;
        }
    }

    $stats = [
        'total' => count($occurrences),
        'aberta' => 0,
        'em_acompanhamento' => 0,
        'aguardando_terceiros' => 0,
        'resolvida' => 0,
        'critica' => 0,
        'alta' => 0,
        'media' => 0,
        'baixa' => 0,
        'resolved_durations' => [],
        'by_localidade' => [],
        'by_sistema' => [],
    ];

    foreach ($occurrences as &$occ) {
        $occ['updates'] = $updatesByOcc[(int)$occ['id']] ?? [];
        $st = $occ['status'];
        if (isset($stats[$st])) {
            $stats[$st]++;
        }
        $sv = $occ['severidade'];
        if (isset($stats[$sv])) {
            $stats[$sv]++;
        }
        if ($occ['status'] === 'resolvida' && $occ['closed_at']) {
            $mins = diff_minutes($occ['opened_at'], $occ['closed_at']);
            if ($mins !== null) {
                $stats['resolved_durations'][] = $mins;
            }
        }
        $loc = $occ['localidade'] ?: 'Não informada';
        $stats['by_localidade'][$loc] = ($stats['by_localidade'][$loc] ?? 0) + 1;

        $sys = $occ['sistema'] ?: 'Não informado';
        $stats['by_sistema'][$sys] = ($stats['by_sistema'][$sys] ?? 0) + 1;
    }

    arsort($stats['by_localidade']);
    arsort($stats['by_sistema']);

    $mttrMinutes = null;
    if (!empty($stats['resolved_durations'])) {
        $mttrMinutes = (int)round(array_sum($stats['resolved_durations']) / count($stats['resolved_durations']));
    }
    $stats['mttr_minutes'] = $mttrMinutes;
    $stats['mttr_human'] = $mttrMinutes !== null ? format_minutes_human($mttrMinutes) : '—';
    unset($stats['resolved_durations']);

    return [
        'stats' => $stats,
        'occurrences' => $occurrences,
    ];
}
