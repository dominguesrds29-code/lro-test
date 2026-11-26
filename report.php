<?php
declare(strict_types=1);

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/occurrence_service.php';

$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}

$pdo = get_db();

$filters = [
    'date_from' => clean_str($_GET['date_from'] ?? date('Y-m-01')),
    'date_to' => clean_str($_GET['date_to'] ?? date('Y-m-t')),
    'status' => clean_str($_GET['status'] ?? 'todas'),
    'severidade' => clean_str($_GET['severidade'] ?? 'todas'),
    'localidade' => clean_str($_GET['localidade'] ?? 'todas'),
];

$payload = build_report_payload($pdo, $filters);
$stats = $payload['stats'];
$occurrences = $payload['occurrences'];

// Exportação CSV opcional (compatível com Excel PT-BR)
if (($_GET['format'] ?? '') === 'csv') {
    $filename = sprintf(
        'LRO-ST_DTCEA-SJ_%s_a_%s.csv',
        $filters['date_from'] ?: 'inicio',
        $filters['date_to'] ?: 'fim'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // BOM UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Codigo LRO', 'Data/Hora Inicio', 'Data/Hora Termino', 'Duracao',
        'Localidade', 'Centro/Estacao', 'Sistema', 'Ticket Externo', 'Operadora',
        'Severidade', 'Status', 'Responsavel Abertura', 'Titulo', 'Descricao',
        'Acoes Preliminares', 'Impacto Operacional', 'Qtd Atualizacoes'
    ], ';');

    foreach ($occurrences as $o) {
        fputcsv($out, [
            $o['code'],
            $o['opened_at_br'],
            $o['closed_at_br'],
            $o['duration_label'],
            $o['localidade'],
            $o['centro'],
            $o['sistema'],
            $o['ticket_externo'],
            $o['operadora'],
            $o['severidade_label'],
            $o['status_label'],
            $o['creator_full_name'],
            $o['title'],
            str_replace(["\r\n", "\n"], ' | ', $o['descricao']),
            str_replace(["\r\n", "\n"], ' | ', $o['acoes_preliminares']),
            $o['impacto_operacional'],
            count($o['updates']),
        ], ';');
    }
    fclose($out);
    exit;
}

$fmtDateBr = static function (?string $iso): string {
    if (!$iso) {
        return '—';
    }
    $parts = explode('-', $iso);
    return count($parts) === 3 ? "{$parts[2]}/{$parts[1]}/{$parts[0]}" : $iso;
};

$generatedAt = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i:s');
$integrityHash = strtoupper(substr(hash('sha256', json_encode([$filters, $stats, count($occurrences), $generatedAt])), 0, 16));

$esc = static fn(?string $s): string => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
$csvUrl = 'report.php?' . http_build_query($filters + ['format' => 'csv']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório LRO-ST DTCEA-SJ (<?= $esc($fmtDateBr($filters['date_from'])) ?> a <?= $esc($fmtDateBr($filters['date_to'])) ?>)</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 14mm 12mm 16mm 12mm;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            color: #111827;
            background: #e5e7eb;
            font-size: 10.5pt;
            line-height: 1.4;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #0b1b34;
            color: #ffffff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.25);
        }
        .toolbar-title { font-weight: 700; font-size: 0.95rem; }
        .toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            border: none;
            border-radius: 6px;
            padding: 8px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print { background: #2563eb; color: #fff; }
        .btn-csv { background: #0f766e; color: #fff; }
        .btn-back { background: rgba(255,255,255,0.14); color: #fff; }

        .sheet {
            max-width: 210mm;
            margin: 18px auto;
            background: #ffffff;
            padding: 16mm 14mm;
            box-shadow: 0 6px 25px rgba(0,0,0,0.14);
        }

        /* Cabeçalho Oficial */
        .doc-header {
            border: 1.5px solid #0b1b34;
            padding: 12px 14px;
            margin-bottom: 14px;
        }
        .doc-header-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            text-align: center;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .doc-logo {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
        }
        .org-line { font-size: 8.5pt; font-weight: 700; letter-spacing: 0.05em; color: #1e3a8a; }
        .unit-line { font-size: 11pt; font-weight: 800; color: #0b1b34; margin: 2px 0; }
        .doc-title { font-size: 12pt; font-weight: 800; color: #0b1b34; margin: 4px 0 2px; text-transform: uppercase; }
        .doc-subtitle { font-size: 8.5pt; color: #475569; }

        .doc-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px 14px;
            font-size: 8.8pt;
        }
        .doc-meta-grid div strong { color: #0b1b34; }

        h2.sec-title {
            font-size: 10.5pt;
            text-transform: uppercase;
            color: #0b1b34;
            border-bottom: 2px solid #0b1b34;
            padding-bottom: 3px;
            margin: 16px 0 10px;
            letter-spacing: 0.03em;
        }

        /* KPIs */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        .kpi-box {
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            padding: 7px 9px;
            background: #f8fafc;
            text-align: center;
        }
        .kpi-box .k-lbl { font-size: 7.5pt; text-transform: uppercase; color: #475569; font-weight: 700; }
        .kpi-box .k-val { font-size: 13pt; font-weight: 800; color: #0b1b34; margin-top: 2px; }

        .dist-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .dist-box {
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            padding: 8px 10px;
            font-size: 8.8pt;
        }
        .dist-box h4 { margin: 0 0 6px; font-size: 8.8pt; color: #0b1b34; text-transform: uppercase; }
        .dist-list { margin: 0; padding-left: 16px; }
        .dist-list li { margin-bottom: 2px; }

        /* Tabela Sinótica */
        table.synoptic {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.3pt;
            margin-bottom: 14px;
        }
        table.synoptic th, table.synoptic td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
        }
        table.synoptic th {
            background: #0b1b34;
            color: #ffffff;
            font-weight: 700;
            font-size: 7.8pt;
            text-transform: uppercase;
        }
        table.synoptic tr:nth-child(even) td { background: #f8fafc; }

        /* Blocos detalhados */
        .occ-block {
            border: 1px solid #94a3b8;
            border-radius: 5px;
            margin-bottom: 14px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .occ-block-head {
            background: #f1f5f9;
            border-bottom: 1px solid #94a3b8;
            padding: 7px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .occ-code { font-family: Consolas, monospace; font-weight: 800; color: #0b1b34; font-size: 9.5pt; }
        .occ-pill {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: 700;
            border: 1px solid #cbd5e1;
            background: #ffffff;
        }
        .occ-block-body { padding: 9px 11px; font-size: 9pt; }
        .occ-block-title { font-weight: 700; font-size: 9.8pt; color: #0b1b34; margin: 0 0 6px; }
        .occ-meta-line {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 16px;
            font-size: 8.3pt;
            background: #f8fafc;
            padding: 5px 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
        }
        .occ-field { margin-bottom: 6px; }
        .occ-field-lbl { font-weight: 700; font-size: 8.2pt; color: #334155; text-transform: uppercase; }
        .occ-field-val { white-space: pre-wrap; margin-top: 1px; }

        .upd-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.2pt;
            margin-top: 6px;
        }
        .upd-table th, .upd-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        .upd-table th { background: #e2e8f0; color: #0b1b34; font-weight: 700; }

        /* Assinaturas */
        .signature-section {
            margin-top: 24px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .sig-note {
            font-size: 8.5pt;
            color: #334155;
            margin-bottom: 26px;
            text-align: justify;
        }
        .sig-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 36px;
            text-align: center;
            font-size: 8.8pt;
        }
        .sig-line {
            border-top: 1px solid #0b1b34;
            padding-top: 5px;
            margin-top: 28px;
        }

        @media print {
            body { background: #ffffff; }
            .toolbar { display: none !important; }
            .sheet {
                margin: 0;
                padding: 0;
                box-shadow: none;
                max-width: none;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="toolbar-title">Pré-visualização Oficial — Relatório LRO-ST (SIGADAER)</div>
        <div class="toolbar-actions">
            <a href="index.php" class="btn btn-back">← Voltar ao Livro</a>
            <a href="<?= $esc($csvUrl) ?>" class="btn btn-csv">⬇ Exportar CSV (Excel)</a>
            <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF (SIGADAER)</button>
        </div>
    </div>

    <div class="sheet">
        <header class="doc-header">
            <div class="doc-header-top">
                <img src="assets/img/logo-lro-st.svg" alt="Logo LRO-ST" class="doc-logo">
                <div>
                    <div class="org-line">MINISTÉRIO DA DEFESA • COMANDO DA AERONÁUTICA • DECEA</div>
                    <div class="unit-line">DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS (DTCEA-SJ)</div>
                    <div class="doc-title">Relatório do Livro de Registro de Ocorrências — Seção Técnica (LRO-ST)</div>
                </div>
            </div>
            <div class="doc-meta-grid">
                <div><strong>Período Apurado:</strong> <?= $esc($fmtDateBr($filters['date_from'])) ?> a <?= $esc($fmtDateBr($filters['date_to'])) ?></div>
                <div><strong>Data/Hora Emissão:</strong> <?= $esc($generatedAt) ?></div>
                <div><strong>Código de Controle:</strong> <code><?= $esc($integrityHash) ?></code></div>
                <div><strong>Emitido por:</strong> <?= $esc($user['full_name']) ?></div>
                <div><strong>Filtro Status:</strong> <?= $esc($filters['status'] === 'todas' ? 'Todos' : (STATUS_LABELS[$filters['status']] ?? $filters['status'])) ?></div>
                <div><strong>Filtro Localidade:</strong> <?= $esc($filters['localidade'] === 'todas' ? 'Todas' : $filters['localidade']) ?></div>
            </div>
        </header>

        <h2 class="sec-title">1. Resumo Executivo e Indicadores do Período</h2>
        <div class="kpi-row">
            <div class="kpi-box"><div class="k-lbl">Total Ocorrências</div><div class="k-val"><?= (int)$stats['total'] ?></div></div>
            <div class="kpi-box"><div class="k-lbl">Abertas / Andamento</div><div class="k-val"><?= (int)($stats['aberta'] + $stats['em_acompanhamento']) ?></div></div>
            <div class="kpi-box"><div class="k-lbl">Aguard. Terceiros</div><div class="k-val"><?= (int)$stats['aguardando_terceiros'] ?></div></div>
            <div class="kpi-box"><div class="k-lbl">Resolvidas</div><div class="k-val"><?= (int)$stats['resolvida'] ?></div></div>
            <div class="kpi-box"><div class="k-lbl">MTTR (Resolvidas)</div><div class="k-val" style="font-size:10.5pt; padding-top:3px;"><?= $esc($stats['mttr_human']) ?></div></div>
        </div>

        <?php if (!empty($occurrences)): ?>
        <div class="dist-grid">
            <div class="dist-box">
                <h4>Distribuição por Localidade / Sítio</h4>
                <ul class="dist-list">
                    <?php foreach ($stats['by_localidade'] as $loc => $qty): ?>
                        <li><strong><?= $esc($loc) ?>:</strong> <?= (int)$qty ?> ocorrência(s)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="dist-box">
                <h4>Distribuição por Sistema / Equipamento</h4>
                <ul class="dist-list">
                    <?php foreach ($stats['by_sistema'] as $sys => $qty): ?>
                        <li><strong><?= $esc($sys) ?>:</strong> <?= (int)$qty ?> ocorrência(s)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <h2 class="sec-title">2. Quadro Sinótico das Ocorrências</h2>
        <?php if (empty($occurrences)): ?>
            <p style="font-size:9.5pt; color:#475569;">Nenhuma ocorrência técnica registrada no período e filtros especificados.</p>
        <?php else: ?>
            <table class="synoptic">
                <thead>
                    <tr>
                        <th>Registro</th>
                        <th>Início</th>
                        <th>Término</th>
                        <th>Duração</th>
                        <th>Localidade / Centro</th>
                        <th>Sistema</th>
                        <th>Ticket</th>
                        <th>Severidade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($occurrences as $o): ?>
                    <tr>
                        <td><strong><?= $esc($o['code']) ?></strong></td>
                        <td><?= $esc($o['opened_at_br']) ?></td>
                        <td><?= $esc($o['closed_at_br']) ?></td>
                        <td><?= $esc($o['duration_label']) ?></td>
                        <td><?= $esc($o['localidade']) ?><?= $o['centro'] ? '<br><small>' . $esc($o['centro']) . '</small>' : '' ?></td>
                        <td><?= $esc($o['sistema']) ?></td>
                        <td><?= $esc($o['ticket_externo'] ?: '—') ?></td>
                        <td><?= $esc($o['severidade_label']) ?></td>
                        <td><strong><?= $esc($o['status_label']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="sec-title">3. Detalhamento Técnico e Trilha de Auditoria</h2>
            <?php foreach ($occurrences as $o): ?>
            <article class="occ-block">
                <div class="occ-block-head">
                    <div>
                        <span class="occ-code"><?= $esc($o['code']) ?></span>
                        <span class="occ-pill"><?= $esc($o['status_label']) ?></span>
                        <span class="occ-pill"><?= $esc($o['severidade_label']) ?></span>
                    </div>
                    <div style="font-size:8.2pt; color:#334155;">
                        <strong>Início:</strong> <?= $esc($o['opened_at_br']) ?> |
                        <strong>Término:</strong> <?= $esc($o['closed_at_br']) ?> |
                        <strong>Duração:</strong> <?= $esc($o['duration_label']) ?>
                    </div>
                </div>
                <div class="occ-block-body">
                    <div class="occ-block-title"><?= $esc($o['title']) ?></div>
                    <div class="occ-meta-line">
                        <span><strong>Local:</strong> <?= $esc($o['localidade']) ?><?= $o['centro'] ? ' (' . $esc($o['centro']) . ')' : '' ?></span>
                        <span><strong>Sistema:</strong> <?= $esc($o['sistema']) ?></span>
                        <?php if ($o['ticket_externo'] !== ''): ?>
                            <span><strong>Ticket:</strong> <?= $esc($o['ticket_externo']) ?><?= $o['operadora'] ? ' (' . $esc($o['operadora']) . ')' : '' ?></span>
                        <?php endif; ?>
                        <span><strong>Autor:</strong> <?= $esc($o['creator_full_name']) ?></span>
                    </div>

                    <div class="occ-field">
                        <div class="occ-field-lbl">Relato Técnico</div>
                        <div class="occ-field-val"><?= $esc($o['descricao']) ?></div>
                    </div>

                    <?php if ($o['acoes_preliminares'] !== '' || $o['impacto_operacional'] !== ''): ?>
                    <div class="occ-field">
                        <div class="occ-field-lbl">Ações / Impacto</div>
                        <div class="occ-field-val"><?= $esc(trim($o['acoes_preliminares'] . ($o['impacto_operacional'] ? "\nImpacto: " . $o['impacto_operacional'] : ''))) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($o['updates'])): ?>
                    <div class="occ-field" style="margin-top:8px;">
                        <div class="occ-field-lbl">Histórico de Atualizações e Despachos</div>
                        <table class="upd-table">
                            <thead>
                                <tr>
                                    <th style="width:17%;">Data / Hora</th>
                                    <th style="width:18%;">Responsável</th>
                                    <th style="width:20%;">Tipo / Status</th>
                                    <th>Descrição / Providência</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($o['updates'] as $u): ?>
                                <tr>
                                    <td><?= $esc($u['event_at_br']) ?></td>
                                    <td><strong><?= $esc($u['author_label']) ?></strong></td>
                                    <td>
                                        <?= $esc($u['update_type_label']) ?>
                                        <?= $u['status_after_label'] ? '<br><small>Status: ' . $esc($u['status_after_label']) . '</small>' : '' ?>
                                    </td>
                                    <td style="white-space:pre-wrap;"><?= $esc($u['message']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <section class="signature-section">
            <div class="sig-note">
                Declaro para fins de registro administrativo, controle de manutenção e evidência técnica junto ao Sistema de Documento Eletrônico da Aeronáutica (SIGADAER) que as ocorrências acima listadas refletem fielmente os lançamentos efetuados pelos técnicos da Seção Técnica (ST) do DTCEA-SJ no período especificado.
            </div>
            <div class="sig-grid">
                <div>
                    <div class="sig-line">
                        <strong><?= $esc($user['full_name']) ?></strong><br>
                        Responsável pela Emissão — Seção Técnica (ST)
                    </div>
                </div>
                <div>
                    <div class="sig-line">
                        <strong>Chefe da Seção Técnica — DTCEA-SJ</strong><br>
                        Visto / Conferência para Auditoria
                    </div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
