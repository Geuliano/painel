<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';
require_once __DIR__ . '/clientes_helper.php';

ensureClientesTable($pdo);

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>

<link href="<?= BASE_URL ?>public/assets/libs/simple-datatables/style.css" rel="stylesheet">

<div class="card" id="clientesListContainer">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="button" onclick="openCreateCliente()">
                <i class="las la-user-plus me-1"></i> Novo cliente
            </button>
            <button class="btn btn-outline-secondary" type="button" onclick="loadClientes(true)">
                <i class="las la-sync-alt me-1"></i> Atualizar
            </button>
            <div class="btn-group">
                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btnFiltroPlanoLabel">
                    Plano: Assinatura
                </button>
                <ul class="dropdown-menu">
                    <li><button class="dropdown-item" type="button" onclick="setPlanoFiltro('assinatura')">Assinatura</button></li>
                    <li><button class="dropdown-item" type="button" onclick="setPlanoFiltro('teste')">Teste</button></li>
                    <li><button class="dropdown-item" type="button" onclick="setPlanoFiltro('todos')">Todos</button></li>
                </ul>
            </div>
            <div class="btn-group">
                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btnFiltroStatusLabel">
                    Status: Todos
                </button>
                <ul class="dropdown-menu p-3" style="min-width: 220px;">
                    <li class="form-check">
                        <input class="form-check-input" type="checkbox" value="ativo" id="status-ativo" onchange="onStatusCheckboxChange(this)">
                        <label class="form-check-label" for="status-ativo">Ativo</label>
                    </li>
                    <li class="form-check">
                        <input class="form-check-input" type="checkbox" value="vencido" id="status-vencido" onchange="onStatusCheckboxChange(this)">
                        <label class="form-check-label" for="status-vencido">Vencido</label>
                    </li>
                    <li class="form-check">
                        <input class="form-check-input" type="checkbox" value="inativo" id="status-inativo" onchange="onStatusCheckboxChange(this)">
                        <label class="form-check-label" for="status-inativo">Inativo</label>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <div class="card-body pt-0">
        <div id="clientesAlert" class="alert d-none" role="alert"></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0" id="datatable_clientes">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>Provedor</th>
                        <th>Telefone</th>
                        <th>Plano</th>
                        <th>Valor</th>
                        <th>Pagamento</th>
                        <th>Telas</th>
                        <th>Validade</th>
                        <th>Status</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Formulario em abas -->
<div id="clienteFormContainer" class="card d-none">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0" id="clienteFormTitulo">Novo cliente</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" type="button" onclick="hideClienteForm()">Cancelar</button>
            <button class="btn btn-success" type="submit" form="formCliente">Salvar</button>
        </div>
    </div>
    <div class="card-body">
        <form id="formCliente">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="id" id="cliente_id">
            <input type="hidden" name="aplicativo_usado" id="cliente_app" value="">
                        <ul class="nav nav-tabs mb-3" id="clienteTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-dados-pessoais" data-bs-toggle="tab" data-bs-target="#pane-dados-pessoais" type="button" role="tab">Dados pessoais</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-acesso" data-bs-toggle="tab" data-bs-target="#pane-acesso" type="button" role="tab">Acessos</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-cobranca" data-bs-toggle="tab" data-bs-target="#pane-cobranca" type="button" role="tab">Vencimento e Cobrancas</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-log" data-bs-toggle="tab" data-bs-target="#pane-log" type="button" role="tab">Log de renovacao</button>
                </li>
            </ul>
            <div id="alertCliente" class="alert d-none"></div>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-dados-pessoais" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome do cliente</label>
                            <input type="text" name="nome" id="cliente_nome" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefone (Brasil)</label>
                            <input type="tel" name="telefone" id="cliente_telefone" class="form-control" placeholder="(11) 99999-9999">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="ativo" id="cliente_ativo" class="form-select">
                                <option value="1">Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="pane-acesso" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Provedor</label>
                            <input type="text" name="provedor" id="cliente_provedor" class="form-control" placeholder="Ex.: Painel XPTO">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Usuario (provedor)</label>
                            <input type="text" name="provedor_usuario" id="cliente_provedor_usuario" class="form-control" placeholder="Login do provedor">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Senha (provedor)</label>
                            <input type="text" name="provedor_senha" id="cliente_provedor_senha" class="form-control" placeholder="Senha do provedor">
                            <small class="text-muted">Visivel apenas aqui para suporte.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plano</label>
                            <select name="plano" id="cliente_plano" class="form-select">
                                <option value="assinatura" selected>Assinatura</option>
                                <option value="teste">Teste</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Qtd. de telas</label>
                            <select name="quantidade_telas" id="cliente_telas" class="form-select">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="pane-cobranca" role="tabpanel">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Valor</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" name="valor_plano" id="cliente_valor" class="form-control" placeholder="0,00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Validade</label>
                            <input type="date" name="validade" id="cliente_validade" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pagamento</label>
                            <select name="pagamento_tipo" id="cliente_pagamento" class="form-select">
                                <option value="avista">Pago a vista</option>
                                <option value="fiado">Fiado</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cobranca (fiado)</label>
                            <input type="date" name="cobranca_em" id="cliente_cobranca" class="form-control">
                            <small class="text-muted">Obrigatorio se for fiado.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observacoes</label>
                            <textarea name="observacoes" id="cliente_obs" class="form-control" rows="4"></textarea>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="pane-log" role="tabpanel">
                    <div id="cliente_logs" class="border rounded bg-body-secondary-subtle p-2 small">
                        <div class="text-muted">Nenhum log para exibir.</div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<!-- Modal Renovar Plano -->
<div class="modal fade" id="modalRenovar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Renovar plano</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formRenovar">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="renovar_id">
                <div class="modal-body">
                    <div id="alertRenovar" class="alert d-none"></div>
                    <p class="mb-3" id="renovarTituloCliente"></p>
                    <div class="mb-3">
                        <label class="form-label">Adicionar dias de validade</label>
                        <select name="dias" id="renovar_dias" class="form-select">
                            <option value="15">15 dias</option>
                            <option value="30" selected>30 dias</option>
                            <option value="60">60 dias</option>
                            <option value="90">90 dias</option>
                            <option value="180">180 dias</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pagamento</label>
                        <select name="pagamento_tipo" id="renovar_pagamento" class="form-select">
                            <option value="avista">Pago a vista</option>
                            <option value="fiado">Fiado</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Valor pago</label>
                        <div class="input-group">
                            <span class="input-group-text">R$</span>
                            <input type="text" name="valor_plano" id="renovar_valor" class="form-control" placeholder="0,00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cobranca (fiado)</label>
                        <input type="date" name="cobranca_em" id="renovar_cobranca" class="form-control">
                        <small class="text-muted">Obrigatorio se for fiado.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Renovar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>public/assets/libs/simple-datatables/umd/simple-datatables.js"></script>
<script>
const controllerUrl = '<?= BASE_URL ?>app/modules/clientes/clientes_controller.php';
const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';

let tabelaClientes = null;
let clientesCache = [];
let filtroPlano = localStorage.getItem('clientes_filtro_plano') || 'assinatura';
let filtroStatus = (() => {
    try {
        const saved = JSON.parse(localStorage.getItem('clientes_filtro_status') || '[]');
        return Array.isArray(saved) && saved.length ? saved : ['ativo', 'vencido', 'inativo'];
    } catch (e) {
        return ['ativo', 'vencido', 'inativo'];
    }
})();

function setPlanoFiltro(valor) {
    filtroPlano = valor;
    localStorage.setItem('clientes_filtro_plano', filtroPlano);
    const label = document.getElementById('btnFiltroPlanoLabel');
    if (label) {
        const texto = valor === 'teste' ? 'Teste' : valor === 'todos' ? 'Todos' : 'Assinatura';
        label.textContent = `Plano: ${texto}`;
    }
    renderClientesTable(clientesCache);
}

function updateStatusLabel() {
    const label = document.getElementById('btnFiltroStatusLabel');
    if (!label) return;
    if (!filtroStatus.length || filtroStatus.length === 3) {
        label.textContent = 'Status: Todos';
        return;
    }
    const nomes = [];
    if (filtroStatus.includes('ativo')) nomes.push('Ativo');
    if (filtroStatus.includes('vencido')) nomes.push('Vencido');
    if (filtroStatus.includes('inativo')) nomes.push('Inativo');
    label.textContent = `Status: ${nomes.join(' / ')}`;
}

function syncStatusCheckboxes() {
    ['ativo', 'vencido', 'inativo'].forEach(key => {
        const el = document.getElementById(`status-${key}`);
        if (el) {
            el.checked = filtroStatus.includes(key);
        }
    });
    updateStatusLabel();
}

function onStatusCheckboxChange(el) {
    if (!el) return;
    const val = el.value;
    if (el.checked) {
        if (!filtroStatus.includes(val)) filtroStatus.push(val);
    } else {
        filtroStatus = filtroStatus.filter(item => item !== val);
    }
    if (!filtroStatus.length) {
        filtroStatus = ['ativo', 'vencido', 'inativo']; // evite filtro vazio
    }
    localStorage.setItem('clientes_filtro_status', JSON.stringify(filtroStatus));
    updateStatusLabel();
    renderClientesTable(clientesCache);
}

function getSwalTheme() {
    const theme = (document.documentElement.getAttribute('data-bs-theme') || 'light').toLowerCase();
    const isDark = theme === 'dark';
    return {
        background: isDark ? '#1f1f1f' : '#fff',
        color: isDark ? '#f8f9fa' : '#111',
        iconColor: isDark ? '#f8f9fa' : undefined
    };
}

const swalToast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    }
});

function showToast(msg, type = 'info') {
    const theme = getSwalTheme();
    swalToast.fire({
        icon: type,
        title: msg,
        background: theme.background,
        color: theme.color,
        iconColor: theme.iconColor
    });
}

function showClientesAlert(message, type = 'danger') {
    const alertBox = document.getElementById('clientesAlert');
    if (!alertBox) return;
    alertBox.textContent = message;
    alertBox.className = `alert alert-${type}`;
    alertBox.classList.remove('d-none');
}

function hideClientesAlert() {
    const alertBox = document.getElementById('clientesAlert');
    if (!alertBox) return;
    alertBox.classList.add('d-none');
}

async function parseJsonResponse(response) {
    const text = await response.text();
    if (!text) throw new Error('Resposta vazia do servidor.');
    try {
        return JSON.parse(text);
    } catch (err) {
        throw new Error(text);
    }
}

function formatMoney(value) {
    const v = Number(value || 0);
    return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatPlano(value) {
    const v = (value || '').toLowerCase();
    if (v === 'teste') return 'Teste';
    if (v === 'assinatura') return 'Assinatura';
    return value || '--';
}

function formatDate(value) {
    if (!value) return '--';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('pt-BR');
}

function formatDateTime(value) {
    if (!value) return '--';
    const normalized = String(value).replace(' ', 'T');
    const d = new Date(normalized);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('pt-BR');
}

function statusBadge(ativo, validade) {
    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);

    let badge = '<span class="badge bg-secondary-subtle text-muted">Desativado</span>';

    if (Number(ativo) === 1) {
        const dataVal = validade ? new Date(validade) : null;
        const vencido = dataVal && !Number.isNaN(dataVal.getTime()) && dataVal < hoje;

        if (vencido) {
            badge = '<span class="badge bg-danger-subtle text-danger">Vencido</span>';
        } else {
            badge = '<span class="badge bg-success-subtle text-success">Ativo</span>';
        }
    }

    return badge;
}

function getClienteStatusKey(cliente) {
    const ativo = Number(cliente.ativo) === 1;
    if (!ativo) return 'inativo';
    const hoje = new Date();
    hoje.setHours(0, 0, 0, 0);
    const dataVal = cliente.validade ? new Date(cliente.validade) : null;
    const vencido = dataVal && !Number.isNaN(dataVal.getTime()) && dataVal < hoje;
    return vencido ? 'vencido' : 'ativo';
}

function formatPagamento(cliente) {
    const normalized = (cliente?.pagamento_tipo || '').toLowerCase();
    if (normalized === 'fiado') {
        const data = cliente?.cobranca_em ? formatDate(cliente.cobranca_em) : '--';
        return `
            <div class="d-flex flex-column align-items-start gap-1">
                <div><span class="badge bg-warning-subtle text-warning">Fiado</span></div>
                <div class="small text-muted">Cobran\u00e7a: ${data}</div>
                <button class="btn btn-sm btn-outline-success" type="button" onclick="baixarFiado(${cliente.id})">
                    <i class="las la-check-circle me-1"></i>Dar baixa
                </button>
            </div>
        `;
    }
    return '<span class="badge bg-success-subtle text-success">Pago \u00e0 vista</span>';
}

function toggleCobrancaField(selectEl, dateEl) {
    if (!selectEl || !dateEl) return;
    const isFiado = (selectEl.value || '').toLowerCase() === 'fiado';
    dateEl.disabled = !isFiado;
    dateEl.required = isFiado;
    if (!isFiado) {
        dateEl.value = '';
    }
}


function renderClientesTable(data) {
    if (tabelaClientes) {
        tabelaClientes.destroy();
        tabelaClientes = null;
    }

    const dataFiltrada = data.filter((c) => {
        const plano = (c.plano || '').toString().trim().toLowerCase();
        if (filtroPlano === 'assinatura' && !(plano === 'assinatura' || plano === '')) return false;
        if (filtroPlano === 'teste' && plano !== 'teste') return false;
        const statusKey = getClienteStatusKey(c);
        if (filtroStatus.length && !filtroStatus.includes(statusKey)) return false;
        return true;
    });

    const tbody = document.querySelector('#datatable_clientes tbody');
    tbody.innerHTML = '';
    dataFiltrada.forEach(c => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div class="fw-semibold">${escapeHtml(c.nome)}</div>
                <div class="text-muted small">${escapeHtml(c.observacoes || '')}</div>
            </td>
            <td>
                <div><strong>Provedor:</strong> ${escapeHtml(c.provedor || '--')}</div>
                <div class="small text-muted">Usuario: ${escapeHtml(c.provedor_usuario || '--')} <br> Senha: <code>${escapeHtml(c.provedor_senha || '--')}</code></div>
            </td>
            <td>${escapeHtml(c.telefone_formatado || '')}</td>
            <td>${formatPlano(c.plano)}</td>
            <td>${formatMoney(c.valor_plano)}</td>
            <td>${formatPagamento(c)}</td>
            <td>${c.quantidade_telas ?? 1}</td>
            <td>${formatDate(c.validade)}</td>
            <td>${statusBadge(c.ativo, c.validade)}</td>
            <td class="text-end">
                <div class="btn-group">
                    <button class="btn btn-link text-secondary p-0 me-2" data-bs-toggle="tooltip" data-bs-placement="top" title="Editar cliente" onclick="openEditCliente(${c.id})">
                        <i class="las la-pen fs-18"></i>
                    </button>
                    <button class="btn btn-link text-warning p-0 me-2" data-bs-toggle="tooltip" data-bs-placement="top" title="${Number(c.ativo) === 1 ? 'Desativar' : 'Ativar'} cliente" onclick="toggleCliente(${c.id}, ${Number(c.ativo) === 1 ? 0 : 1})">
                        <i class="las ${Number(c.ativo) === 1 ? 'la-user-slash' : 'la-user-check'} fs-18"></i>
                    </button>
                    <button class="btn btn-link text-success p-0 me-2" data-bs-toggle="tooltip" data-bs-placement="top" title="Renovar plano" onclick="openRenovar(${c.id})">
                        <i class="las la-history fs-18"></i>
                    </button>
                    <button class="btn btn-link text-danger p-0" data-bs-toggle="tooltip" data-bs-placement="top" title="Excluir cliente" onclick="confirmDeleteCliente(${c.id})">
                        <i class="las la-trash-alt fs-18"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    tabelaClientes = new simpleDatatables.DataTable('#datatable_clientes', {
        searchable: true,
        perPage: 50,
        labels: {
            placeholder: 'Buscar...',
            perPage: 'registros por pagina',
            noRows: 'Nenhum cliente encontrado',
            info: 'Mostrando de {start} a {end}. Total {rows}.'
        }
    });

    if (window.initTooltips) {
        window.initTooltips();
    }
}
function renderLogs(logs = []) {
    const container = document.getElementById('cliente_logs');
    if (!container) return;

    if (!Array.isArray(logs) || logs.length === 0) {
        container.innerHTML = '<div class="text-muted">Nenhum log para exibir.</div>';
        return;
    }

    container.innerHTML = '';
    logs.forEach((log) => {
        const item = document.createElement('div');
        item.className = 'pb-2 mb-2 border-bottom';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-start gap-2">
                <strong>${escapeHtml(log.acao || 'Atualizacao')}</strong>
                <span class="text-muted">${formatDateTime(log.criado_em)}</span>
            </div>
            <div class="small text-muted">Plano: ${formatPlano(log.plano)} - Valor: ${formatMoney(log.valor_plano || 0)} - Validade: ${formatDate(log.validade)}</div>
            <div class="small">Pagamento: ${escapeHtml((log.pagamento_tipo || 'avista').toUpperCase())}${log.cobranca_em ? ` - Cobranca: ${formatDate(log.cobranca_em)}` : ''}</div>
            ${log.observacao ? `<div class="small">${escapeHtml(log.observacao)}</div>` : ''}
        `;
        container.appendChild(item);
    });
    if (container.lastElementChild) {
        container.lastElementChild.classList.remove('border-bottom', 'mb-2', 'pb-2');
    }
}

async function loadClientes(showToastOnRefresh = false) {
    hideClientesAlert();
    try {
        const resp = await fetch(`${controllerUrl}?action=list`, { credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        if (!data.success) {
            showClientesAlert(data.message || 'Nao foi possivel carregar os clientes.');
            return;
        }
        clientesCache = data.clientes || [];
        renderClientesTable(clientesCache);
        if (showToastOnRefresh) showToast('Lista atualizada.', 'success');
    } catch (err) {
        showClientesAlert(err.message || 'Erro ao carregar clientes.');
    }
}

function resetClienteForm() {
    const form = document.getElementById('formCliente');
    form.reset();
    document.getElementById('cliente_id').value = '';
    document.getElementById('alertCliente').className = 'alert d-none';
    document.getElementById('cliente_ativo').value = '1';
    document.getElementById('cliente_plano').value = 'assinatura';
    document.getElementById('cliente_telas').value = '1';
    document.getElementById('cliente_pagamento').value = 'avista';
    document.getElementById('cliente_cobranca').value = '';
    document.getElementById('cliente_valor').value = '';
    toggleCobrancaField(document.getElementById('cliente_pagamento'), document.getElementById('cliente_cobranca'));
    renderLogs([]);
}

function showClienteForm() {
    const container = document.getElementById('clienteFormContainer');
    const listContainer = document.getElementById('clientesListContainer');
    if (listContainer) listContainer.classList.add('d-none');
    if (container) {
        container.classList.remove('d-none');
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function hideClienteForm() {
    const container = document.getElementById('clienteFormContainer');
    if (container) container.classList.add('d-none');
    const listContainer = document.getElementById('clientesListContainer');
    if (listContainer) listContainer.classList.remove('d-none');
}

function openCreateCliente() {
    resetClienteForm();
    const title = document.getElementById('clienteFormTitulo');
    if (title) title.textContent = 'Novo cliente';
    showClienteForm();
}

function fillClienteForm(cliente) {
    document.getElementById('cliente_id').value = cliente.id;
    document.getElementById('cliente_nome').value = cliente.nome || '';
    document.getElementById('cliente_provedor').value = cliente.provedor || '';
    document.getElementById('cliente_provedor_usuario').value = cliente.provedor_usuario || '';
    document.getElementById('cliente_provedor_senha').value = cliente.provedor_senha || '';
    document.getElementById('cliente_telefone').value = cliente.telefone || '';
    const planoValor = (cliente.plano || '').toLowerCase() === 'teste' ? 'teste' : 'assinatura';
    document.getElementById('cliente_plano').value = planoValor;
    document.getElementById('cliente_valor').value = cliente.valor_plano || '';
    document.getElementById('cliente_validade').value = cliente.validade || '';
    document.getElementById('cliente_telas').value = cliente.quantidade_telas || 1;
    document.getElementById('cliente_app').value = cliente.aplicativo_usado || '';
    document.getElementById('cliente_obs').value = cliente.observacoes || '';
    document.getElementById('cliente_pagamento').value = (cliente.pagamento_tipo || 'avista');
    document.getElementById('cliente_cobranca').value = cliente.cobranca_em || '';
    toggleCobrancaField(document.getElementById('cliente_pagamento'), document.getElementById('cliente_cobranca'));
    document.getElementById('cliente_ativo').value = cliente.ativo ? '1' : '0';
}

async function openEditCliente(id) {
    resetClienteForm();
    const title = document.getElementById('clienteFormTitulo');
    if (title) title.textContent = 'Editar cliente';
    try {
        const resp = await fetch(`${controllerUrl}?action=get&id=${id}`, { credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        if (!data.success) {
            showToast(data.message || 'Nao foi possivel carregar o cliente.', 'error');
            return;
        }
        fillClienteForm(data.cliente);
        renderLogs(data.logs || []);
        showClienteForm();
    } catch (err) {
        showToast(err.message || 'Erro ao carregar cliente.', 'error');
    }
}

document.getElementById('formCliente')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const form = e.target;
    const alertBox = document.getElementById('alertCliente');
    alertBox.className = 'alert d-none';

    const pagamentoSelect = document.getElementById('cliente_pagamento');
    const cobrancaInput = document.getElementById('cliente_cobranca');
    if ((pagamentoSelect.value || '').toLowerCase() === 'fiado' && !cobrancaInput.value) {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = 'Informe a data de cobran\u00e7a para pagamento fiado.';
        return;
    }

    const provUser = document.getElementById('cliente_provedor_usuario');
    const provPass = document.getElementById('cliente_provedor_senha');
    const hiddenApp = document.getElementById('cliente_app');
    if (provUser && !provUser.value) provUser.value = (document.getElementById('cliente_nome')?.value || '').replace(/\s+/g, '').toLowerCase();
    if (provPass && !provPass.value) provPass.value = '123456';
    if (hiddenApp && !hiddenApp.value) hiddenApp.value = 'N/A';

    const fd = new FormData(form);
    fd.append('action', 'save');

    const btn = form.querySelector('button[type="submit"]') || document.querySelector('button[type="submit"][form="formCliente"]');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Salvando...';
    }

    try {
        const resp = await fetch(controllerUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        alertBox.classList.remove('d-none');
        alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
        alertBox.textContent = data.message || (data.success ? 'Salvo com sucesso.' : 'Falha ao salvar.');
        if (data.success) {
            showToast(data.message || 'Cliente salvo.', 'success');
            setTimeout(() => window.location.reload(), 600);
        }
    } catch (err) {
        alertBox.classList.remove('d-none');
        alertBox.classList.add('alert-danger');
        alertBox.textContent = err.message || 'Erro inesperado.';
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Salvar';
        }
    }
});

function openRenovar(id) {
    const cliente = clientesCache.find(c => Number(c.id) === Number(id));
    document.getElementById('renovar_id').value = id;
    document.getElementById('renovar_dias').value = '30';
    document.getElementById('alertRenovar').className = 'alert d-none';
    document.getElementById('renovarTituloCliente').textContent = cliente ? `Cliente: ${cliente.nome}` : '';
    const pagamentoSelect = document.getElementById('renovar_pagamento');
    const cobrancaInput = document.getElementById('renovar_cobranca');
    const valorInput = document.getElementById('renovar_valor');
    if (pagamentoSelect) {
        pagamentoSelect.value = cliente?.pagamento_tipo || 'avista';
    }
    if (cobrancaInput) {
        cobrancaInput.value = cliente?.cobranca_em || '';
    }
    if (valorInput) {
        valorInput.value = formatMoneyPlain(cliente?.valor_plano);
    }
    toggleCobrancaField(pagamentoSelect, cobrancaInput);
    const modal = bootstrap.Modal.getOrCreateInstance('#modalRenovar');
    modal.show();
}

async function toggleCliente(id, novoStatus) {
    const theme = getSwalTheme();
    const confirm = await Swal.fire({
        title: novoStatus ? 'Ativar cliente?' : 'Desativar cliente?',
        text: 'Esta acao altera o acesso no painel.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: novoStatus ? 'Ativar' : 'Desativar',
        cancelButtonText: 'Cancelar',
        background: theme.background,
        color: theme.color
    });
    if (!confirm.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'toggle_active');
    fd.append('csrf_token', csrfToken);
    fd.append('id', id);
    fd.append('ativo', novoStatus ? 1 : 0);

    try {
        const resp = await fetch(controllerUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        if (!data.success) {
            showToast(data.message || 'Nao foi possivel atualizar o status.', 'error');
            return;
        }
        showToast(data.message || 'Status atualizado.', 'success');
        setTimeout(() => window.location.reload(), 400);
    } catch (err) {
        showToast(err.message || 'Erro ao atualizar status.', 'error');
    }
}

async function baixarFiado(clienteId) {
    if (!clienteId) return;
    const theme = getSwalTheme();
    const confirm = await Swal.fire({
        title: 'Remover pendencia de fiado?',
        text: 'O pagamento sera marcado como pago a vista.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        cancelButtonText: 'Cancelar',
        background: theme.background,
        color: theme.color
    });
    if (!confirm.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'clear_fiado');
    fd.append('csrf_token', csrfToken);
    fd.append('id', clienteId);

    try {
        const resp = await fetch(controllerUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        if (!data.success) {
            showToast(data.message || 'Nao foi possivel remover a pendencia.', 'error');
            return;
        }
        showToast(data.message || 'Pendencia removida.', 'success');
        loadClientes();
    } catch (err) {
        showToast(err.message || 'Erro ao remover pendencia.', 'error');
    }
}

async function confirmDeleteCliente(id) {
    const theme = getSwalTheme();
    const confirm = await Swal.fire({
        title: 'Excluir cliente?',
        text: 'Esta acao e permanente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Excluir',
        cancelButtonText: 'Cancelar',
        background: theme.background,
        color: theme.color
    });
    if (!confirm.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', csrfToken);
    fd.append('id', id);

    try {
        const resp = await fetch(controllerUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        if (!data.success) {
            showToast(data.message || 'Nao foi possivel excluir.', 'error');
            return;
        }
        showToast(data.message || 'Cliente excluido.', 'success');
        setTimeout(() => window.location.reload(), 400);
    } catch (err) {
        showToast(err.message || 'Erro ao excluir.', 'error');
    }
}

document.getElementById('formRenovar')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const form = e.target;
    const alertBox = document.getElementById('alertRenovar');
    alertBox.className = 'alert d-none';

    const pagamentoSelect = document.getElementById('renovar_pagamento');
    const cobrancaInput = document.getElementById('renovar_cobranca');
    if ((pagamentoSelect.value || '').toLowerCase() === 'fiado' && !cobrancaInput.value) {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = 'Informe a data de cobran\u00e7a para pagamento fiado.';
        return;
    }

    const fd = new FormData(form);
    fd.append('action', 'renew');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Renovando...';

    try {
        const resp = await fetch(controllerUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await parseJsonResponse(resp);
        alertBox.classList.remove('d-none');
        alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
        alertBox.textContent = data.message || (data.success ? 'Renovado com sucesso.' : 'Falha ao renovar.');
        if (data.success) {
            showToast(data.message || 'Plano renovado.', 'success');
            setTimeout(() => window.location.reload(), 600);
        }
    } catch (err) {
        alertBox.classList.remove('d-none');
        alertBox.classList.add('alert-danger');
        alertBox.textContent = err.message || 'Erro inesperado.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Renovar';
    }
});

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.getElementById('cliente_pagamento')?.addEventListener('change', () => {
    toggleCobrancaField(document.getElementById('cliente_pagamento'), document.getElementById('cliente_cobranca'));
});
document.getElementById('renovar_pagamento')?.addEventListener('change', () => {
    toggleCobrancaField(document.getElementById('renovar_pagamento'), document.getElementById('renovar_cobranca'));
});

function formatMoneyPlain(value) {
    const num = Number(value ?? 0);
    if (Number.isNaN(num)) return '';
    return num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function maskTelefoneBR(valor) {
    const digitos = (valor || '').replace(/\D+/g, '').slice(0, 11);
    if (digitos.length <= 2) return digitos;
    if (digitos.length <= 6) return `(${digitos.slice(0, 2)}) ${digitos.slice(2)}`;
    if (digitos.length <= 10) return `(${digitos.slice(0, 2)}) ${digitos.slice(2, 6)}-${digitos.slice(6)}`;
    return `(${digitos.slice(0, 2)}) ${digitos.slice(2, 7)}-${digitos.slice(7)}`;
}

function maskMoneyBR(valor) {
    const digits = (valor || '').replace(/\D+/g, '');
    const number = parseInt(digits || '0', 10);
    return (number / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.getElementById('cliente_telefone')?.addEventListener('input', (e) => {
    e.target.value = maskTelefoneBR(e.target.value);
});
document.getElementById('renovar_valor')?.addEventListener('input', (e) => {
    e.target.value = maskMoneyBR(e.target.value);
});
document.getElementById('cliente_valor')?.addEventListener('input', (e) => {
    e.target.value = maskMoneyBR(e.target.value);
});

document.addEventListener('DOMContentLoaded', () => {
    toggleCobrancaField(document.getElementById('cliente_pagamento'), document.getElementById('cliente_cobranca'));
    toggleCobrancaField(document.getElementById('renovar_pagamento'), document.getElementById('renovar_cobranca'));
    syncStatusCheckboxes();
    setPlanoFiltro(filtroPlano);
    loadClientes();
    const params = new URLSearchParams(window.location.search);
    const formParam = params.get('form');
    const idParam = params.get('id');
    if (formParam) {
        if (formParam.toLowerCase() === 'novo' || formParam.toLowerCase() === 'new') {
            openCreateCliente();
        } else if (idParam || formParam) {
            const targetId = Number(idParam || formParam);
            if (Number.isFinite(targetId) && targetId > 0) {
                openEditCliente(targetId);
            }
        }
    }
});
</script>












