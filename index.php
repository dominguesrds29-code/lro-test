<?php
declare(strict_types=1);
require_once __DIR__ . '/src/auth.php';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LRO-ST | Livro de Registro de Ocorrências — DTCEA-SJ</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <!-- TELA DE LOGIN -->
    <div id="loginView" class="login-wrapper <?= $user ? 'hidden' : '' ?>">
        <div class="login-card">
            <div class="login-brand">
                <div class="brand-crest" aria-hidden="true">
                    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M24 4L40 10V22C40 32.5 33.1 41.8 24 44C14.9 41.8 8 32.5 8 22V10L24 4Z" stroke="currentColor" stroke-width="2.5" fill="rgba(255,255,255,0.08)"/>
                        <path d="M16 24L22 30L33 18" stroke="#60a5fa" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div>
                    <div class="brand-org">COMANDO DA AERONÁUTICA • DECEA</div>
                    <h1>DTCEA-SJ — Seção Técnica (ST)</h1>
                    <p>Livro de Registro de Ocorrências Técnicas (LRO-ST)</p>
                </div>
            </div>

            <form id="loginForm" class="form-stack">
                <div class="form-group">
                    <label for="loginUsername">Usuário (Nome de Guerra)</label>
                    <input type="text" id="loginUsername" name="username" placeholder="Ex: ramos, glaudio, sobreaviso, silva" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="loginPassword">Senha</label>
                    <input type="password" id="loginPassword" name="password" placeholder="Digite sua senha" autocomplete="current-password" required>
                </div>
                <div id="loginError" class="alert alert-error hidden"></div>
                <button type="submit" class="btn btn-primary btn-block">Entrar no Livro de Ocorrências</button>
            </form>

            <div class="login-hint">
                <strong>Primeiro acesso da equipe ST:</strong> utilize seu nome de guerra em minúsculas (ex: <code>ramos</code>, <code>glaudio</code>, <code>sobreaviso</code>, <code>silva</code>) e senha inicial <code>dtceasj</code>. Recomenda-se alterar a senha após o primeiro acesso.
            </div>
        </div>
    </div>

    <!-- APLICAÇÃO PRINCIPAL -->
    <div id="appView" class="app-shell <?= $user ? '' : 'hidden' ?>">
        <header class="topbar">
            <div class="topbar-inner">
                <div class="topbar-brand">
                    <div class="topbar-logo">ST</div>
                    <div>
                        <div class="topbar-title">LRO-ST • DTCEA-SJ</div>
                        <div class="topbar-sub">Livro de Ocorrências da Seção Técnica</div>
                    </div>
                </div>

                <nav class="topbar-nav" aria-label="Navegação principal">
                    <button type="button" class="nav-tab active" data-tab="occurrences">Livro de Ocorrências</button>
                    <button type="button" class="nav-tab" data-tab="report">Relatório SIGADAER</button>
                    <button type="button" class="nav-tab" data-tab="team">Equipe ST</button>
                </nav>

                <div class="topbar-actions">
                    <button type="button" id="btnNewOccurrence" class="btn btn-accent">+ Nova Ocorrência</button>
                    <div class="user-pill">
                        <span id="currentUserBadge" class="user-name"></span>
                        <button type="button" id="btnChangePass" class="btn-icon" title="Alterar minha senha">🔑</button>
                        <button type="button" id="btnLogout" class="btn btn-ghost btn-sm">Sair</button>
                    </div>
                </div>
            </div>
        </header>

        <main class="main-container">
            <!-- ABA 1: LIVRO DE OCORRÊNCIAS -->
            <section id="tab-occurrences" class="tab-panel active">
                <div class="kpi-grid">
                    <div class="kpi-card kpi-open">
                        <div class="kpi-label">Abertas</div>
                        <div class="kpi-value" id="kpiAberta">0</div>
                    </div>
                    <div class="kpi-card kpi-progress">
                        <div class="kpi-label">Em Acompanhamento</div>
                        <div class="kpi-value" id="kpiAcompanhamento">0</div>
                    </div>
                    <div class="kpi-card kpi-waiting">
                        <div class="kpi-label">Aguardando Terceiros</div>
                        <div class="kpi-value" id="kpiTerceiros">0</div>
                    </div>
                    <div class="kpi-card kpi-resolved">
                        <div class="kpi-label">Resolvidas</div>
                        <div class="kpi-value" id="kpiResolvida">0</div>
                    </div>
                </div>

                <div class="card filter-card">
                    <div class="filter-row">
                        <div class="filter-field filter-search">
                            <label for="filterSearch">Buscar</label>
                            <input type="search" id="filterSearch" placeholder="Código LRO, Ticket Embratel, localidade, frequência, descrição...">
                        </div>
                        <div class="filter-field">
                            <label for="filterStatus">Status</label>
                            <select id="filterStatus">
                                <option value="todas">Todos os Status</option>
                                <option value="pendentes">Pendentes (Não Resolvidas)</option>
                                <option value="aberta">Aberta</option>
                                <option value="em_acompanhamento">Em Acompanhamento</option>
                                <option value="aguardando_terceiros">Aguardando Terceiros</option>
                                <option value="resolvida">Resolvida / Encerrada</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="filterSeverity">Severidade</label>
                            <select id="filterSeverity">
                                <option value="todas">Todas</option>
                                <option value="critica">Crítica</option>
                                <option value="alta">Alta</option>
                                <option value="media">Média</option>
                                <option value="baixa">Baixa</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="filterLocalidade">Localidade</label>
                            <select id="filterLocalidade">
                                <option value="todas">Todas</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="filterDateFrom">De</label>
                            <input type="date" id="filterDateFrom">
                        </div>
                        <div class="filter-field">
                            <label for="filterDateTo">Até</label>
                            <input type="date" id="filterDateTo">
                        </div>
                        <div class="filter-actions">
                            <button type="button" id="btnClearFilters" class="btn btn-ghost btn-sm">Limpar</button>
                        </div>
                    </div>
                </div>

                <div id="occurrencesList" class="occurrences-list"></div>
            </section>

            <!-- ABA 2: RELATÓRIO SIGADAER -->
            <section id="tab-report" class="tab-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>Gerador de Relatório Técnico para SIGADAER</h2>
                            <p class="muted">Selecione o período (semanal, mensal, anual ou personalizado) e os filtros desejados para gerar o documento oficial de evidência em auditorias.</p>
                        </div>
                    </div>

                    <div class="preset-bar">
                        <span class="preset-label">Períodos rápidos:</span>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="today">Hoje</button>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="week">Esta Semana</button>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="last7">Últimos 7 dias</button>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="month">Este Mês</button>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="prev_month">Mês Anterior</button>
                        <button type="button" class="btn btn-outline btn-sm" data-preset="year">Este Ano</button>
                    </div>

                    <div class="filter-row" style="margin-top: 14px;">
                        <div class="filter-field">
                            <label for="repDateFrom">Data Inicial</label>
                            <input type="date" id="repDateFrom">
                        </div>
                        <div class="filter-field">
                            <label for="repDateTo">Data Final</label>
                            <input type="date" id="repDateTo">
                        </div>
                        <div class="filter-field">
                            <label for="repStatus">Status</label>
                            <select id="repStatus">
                                <option value="todas">Todos</option>
                                <option value="pendentes">Pendentes</option>
                                <option value="aberta">Aberta</option>
                                <option value="em_acompanhamento">Em Acompanhamento</option>
                                <option value="aguardando_terceiros">Aguardando Terceiros</option>
                                <option value="resolvida">Resolvida / Encerrada</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="repSeverity">Severidade</label>
                            <select id="repSeverity">
                                <option value="todas">Todas</option>
                                <option value="critica">Crítica</option>
                                <option value="alta">Alta</option>
                                <option value="media">Média</option>
                                <option value="baixa">Baixa</option>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="repLocalidade">Localidade</label>
                            <select id="repLocalidade">
                                <option value="todas">Todas</option>
                            </select>
                        </div>
                        <div class="filter-actions" style="gap: 8px; display: flex; align-items: flex-end;">
                            <button type="button" id="btnPreviewReport" class="btn btn-secondary">Atualizar Prévia</button>
                            <button type="button" id="btnOpenPrintReport" class="btn btn-primary">📄 Gerar Relatório Oficial (PDF / SIGADAER)</button>
                        </div>
                    </div>
                </div>

                <div id="reportPreviewBox" class="card" style="margin-top: 16px;">
                    <div class="card-header">
                        <h3>Prévia Estatística do Período</h3>
                        <span id="repPeriodBadge" class="badge badge-info"></span>
                    </div>
                    <div id="reportPreviewStats" class="kpi-grid" style="margin-top: 12px;"></div>
                    <div id="reportPreviewTableWrap" style="margin-top: 16px; overflow-x: auto;"></div>
                </div>
            </section>

            <!-- ABA 3: EQUIPE ST -->
            <section id="tab-team" class="tab-panel">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>Efetivo da Seção Técnica (ST) — Acesso ao LRO</h2>
                            <p class="muted">Técnicos habilitados para lançamento e acompanhamento de ocorrências.</p>
                        </div>
                        <button type="button" id="btnAddUser" class="btn btn-primary hidden">+ Cadastrar Técnico</button>
                    </div>
                    <div id="teamTableWrap" style="overflow-x: auto; margin-top: 12px;"></div>
                </div>
            </section>
        </main>
    </div>

    <!-- MODAL: NOVA OCORRÊNCIA -->
    <div id="modalNewOcc" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="modalNewOccTitle">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog modal-lg">
            <div class="modal-header">
                <h2 id="modalNewOccTitle">Novo Lançamento de Ocorrência Técnica</h2>
                <button type="button" class="modal-close" data-close="modalNewOcc">&times;</button>
            </div>
            <form id="formNewOcc" class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group span-2">
                        <label for="newTitle">Título / Resumo da Ocorrência *</label>
                        <input type="text" id="newTitle" required placeholder="Ex: Indisponibilidade Link A MPLS e frequências VHF em Salesópolis">
                    </div>

                    <div class="form-group">
                        <label for="newOpenedAt">Data e Hora do Evento *</label>
                        <input type="datetime-local" id="newOpenedAt" required>
                    </div>

                    <div class="form-group">
                        <label for="newSeveridade">Severidade / Impacto *</label>
                        <select id="newSeveridade" required>
                            <option value="critica">Crítica (Indisponibilidade Total)</option>
                            <option value="alta">Alta (Degradação Severa)</option>
                            <option value="media" selected>Média (Contingência / Parcial)</option>
                            <option value="baixa">Baixa / Informativo</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="newLocalidade">Localidade / Sítio *</label>
                        <input type="text" id="newLocalidade" list="dlLocalidades" required placeholder="Ex: Salesópolis - SP">
                    </div>

                    <div class="form-group">
                        <label for="newCentro">Centro / Órgão / Estação</label>
                        <input type="text" id="newCentro" placeholder="Ex: EACEA-HSA_A">
                    </div>

                    <div class="form-group">
                        <label for="newSistema">Sistema / Equipamento *</label>
                        <input type="text" id="newSistema" list="dlSistemas" required placeholder="Ex: MPLS / VHF (MARC / ICCB)">
                    </div>

                    <div class="form-group">
                        <label for="newStatus">Status Inicial</label>
                        <select id="newStatus">
                            <option value="aberta">Aberta</option>
                            <option value="em_acompanhamento">Em Acompanhamento</option>
                            <option value="aguardando_terceiros">Aguardando Terceiros (Operadora)</option>
                            <option value="resolvida">Resolvida / Encerrada</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="newTicket">Ticket Externo / Nº OS</label>
                        <input type="text" id="newTicket" placeholder="Ex: 10939457">
                    </div>

                    <div class="form-group">
                        <label for="newOperadora">Operadora / Responsável Externo</label>
                        <input type="text" id="newOperadora" list="dlOperadoras" placeholder="Ex: Embratel, Telebras, Interno ST">
                    </div>

                    <div class="form-group span-2">
                        <label for="newDescricao">Descrição Técnica da Ocorrência *</label>
                        <textarea id="newDescricao" rows="4" required placeholder="Descreva os sintomas, frequências/links afetados, alarmes observados..."></textarea>
                    </div>

                    <div class="form-group span-2">
                        <label for="newAcoes">Ações Preliminares / Testes Já Realizados</label>
                        <textarea id="newAcoes" rows="3" placeholder="Ex: Resetados rádios no MARC, realizado START/STOP nas frequências, comutada ICCB..."></textarea>
                    </div>

                    <div class="form-group span-2">
                        <label for="newImpacto">Impacto Operacional / Contingência Adotada</label>
                        <input type="text" id="newImpacto" placeholder="Ex: Frequências operando em modo analógico">
                    </div>
                </div>

                <div id="newOccError" class="alert alert-error hidden"></div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-close="modalNewOcc">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar no Livro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: DETALHE E LINHA DO TEMPO DA OCORRÊNCIA -->
    <div id="modalDetail" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog modal-xl">
            <div class="modal-header">
                <div>
                    <span id="detCode" class="badge badge-code"></span>
                    <span id="detStatusBadge" class="badge"></span>
                    <span id="detSevBadge" class="badge"></span>
                    <h2 id="detTitle" style="margin-top: 6px;"></h2>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" id="btnCopyWhatsapp" class="btn btn-outline btn-sm" title="Copiar resumo formatado para WhatsApp">📋 Copiar p/ WhatsApp</button>
                    <button type="button" class="modal-close" data-close="modalDetail">&times;</button>
                </div>
            </div>
            <div class="modal-body">
                <div id="detMetaGrid" class="detail-meta-grid"></div>

                <div class="detail-section">
                    <h4>Descrição Técnica Inicial</h4>
                    <div id="detDescricao" class="detail-text-box"></div>
                </div>

                <div class="detail-grid-2">
                    <div class="detail-section">
                        <h4>Ações Preliminares / Testes Realizados</h4>
                        <div id="detAcoes" class="detail-text-box"></div>
                    </div>
                    <div class="detail-section">
                        <h4>Impacto Operacional / Contingência</h4>
                        <div id="detImpacto" class="detail-text-box"></div>
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Histórico de Andamento e Despachos (Trilha de Auditoria)</h4>
                    <div id="detTimeline" class="timeline"></div>
                </div>

                <div class="detail-section update-box">
                    <h4>Registrar Nova Atualização / Despacho / Encerramento</h4>
                    <form id="formAddUpdate" class="form-grid-2">
                        <input type="hidden" id="updOccId">
                        <div class="form-group">
                            <label for="updType">Tipo de Registro</label>
                            <select id="updType">
                                <option value="atualizacao">Atualização Técnica</option>
                                <option value="contato_operadora">Contato Operadora / Terceiro</option>
                                <option value="restabelecimento_parcial">Restabelecimento Parcial</option>
                                <option value="despacho">Despacho / Acionamento</option>
                                <option value="encerramento">Encerramento / Restabelecimento Total</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="updStatusAfter">Alterar Status para</label>
                            <select id="updStatusAfter">
                                <option value="">(Manter status atual)</option>
                                <option value="aberta">Aberta</option>
                                <option value="em_acompanhamento">Em Acompanhamento</option>
                                <option value="aguardando_terceiros">Aguardando Terceiros</option>
                                <option value="resolvida">Resolvida / Encerrada</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="updEventAt">Data / Hora do Evento</label>
                            <input type="datetime-local" id="updEventAt">
                        </div>
                        <div class="form-group span-2">
                            <label for="updMessage">Descrição da Atualização / Providência *</label>
                            <textarea id="updMessage" rows="3" required placeholder="Descreva o andamento, retorno da operadora, testes realizados ou solução aplicada..."></textarea>
                        </div>
                        <div id="updError" class="alert alert-error hidden span-2"></div>
                        <div class="span-2" style="display:flex; justify-content:flex-end;">
                            <button type="submit" class="btn btn-primary">Adicionar ao Histórico</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: CADASTRO/EDIÇÃO DE TÉCNICO -->
    <div id="modalUser" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 id="modalUserTitle">Cadastrar Técnico ST</h2>
                <button type="button" class="modal-close" data-close="modalUser">&times;</button>
            </div>
            <form id="formUser" class="modal-body form-stack">
                <input type="hidden" id="usrId">
                <div class="form-group">
                    <label for="usrUsername">Usuário (login) *</label>
                    <input type="text" id="usrUsername" required placeholder="Ex: ramos">
                </div>
                <div class="form-group">
                    <label for="usrRankName">Posto / Grad. e Nome de Guerra *</label>
                    <input type="text" id="usrRankName" required placeholder="Ex: 2S Ramos">
                </div>
                <div class="form-group">
                    <label for="usrFullName">Nome Completo *</label>
                    <input type="text" id="usrFullName" required placeholder="Ex: 2º Sgt Ramos">
                </div>
                <div class="form-group">
                    <label for="usrSpecialty">Especialidade / Função</label>
                    <input type="text" id="usrSpecialty" placeholder="Ex: Telecomunicações / Rádio">
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="usrRole">Perfil</label>
                        <select id="usrRole">
                            <option value="tecnico">Técnico ST</option>
                            <option value="supervisor">Supervisor / Chefe ST</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="usrActive">Situação</label>
                        <select id="usrActive">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="usrPassword">Senha (deixe em branco para manter / padrão: dtceasj)</label>
                    <input type="password" id="usrPassword" placeholder="Nova senha (opcional na edição)">
                </div>
                <div id="usrError" class="alert alert-error hidden"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-close="modalUser">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: ALTERAR PRÓPRIA SENHA -->
    <div id="modalPass" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Alterar Minha Senha</h2>
                <button type="button" class="modal-close" data-close="modalPass">&times;</button>
            </div>
            <form id="formPass" class="modal-body form-stack">
                <div class="form-group">
                    <label for="passCurrent">Senha Atual</label>
                    <input type="password" id="passCurrent" required>
                </div>
                <div class="form-group">
                    <label for="passNew">Nova Senha (mín. 5 caracteres)</label>
                    <input type="password" id="passNew" minlength="5" required>
                </div>
                <div id="passError" class="alert alert-error hidden"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-close="modalPass">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Atualizar Senha</button>
                </div>
            </form>
        </div>
    </div>

    <datalist id="dlLocalidades"></datalist>
    <datalist id="dlSistemas"></datalist>
    <datalist id="dlOperadoras"></datalist>

    <div id="toast" class="toast hidden"></div>

    <script src="assets/js/app.js"></script>
</body>
</html>
