<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $stmt = $pdo->query("SELECT id, nome, descricao FROM niveis_acesso ORDER BY nome ASC");
    $niveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $niveis = [];
}

$csrfToken = $_SESSION['csrf_token'];
?>

<link href="<?= BASE_URL ?>public/assets/libs/simple-datatables/style.css" rel="stylesheet">

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h4 class="card-title mb-0">Gerenciar níveis de acesso</h4>
            </div>
            <div class="col-auto">
                <button type="button" class="btn bg-primary text-white" onclick="openLevelModal('create')">
                    <i class="fas fa-plus me-1"></i> Novo nível
                </button>
            </div>
        </div>
    </div>
    <div class="card-body pt-0">
        <div id="permissionsAlert" class="alert d-none" role="alert"></div>
        <div class="table-responsive">
            <table class="table mb-0 table-hover" id="datatable_niveis">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th class="text-end">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($niveis as $nivel): ?>
                        <tr>
                            <td><?= htmlspecialchars($nivel['nome']) ?></td>
                            <td><?= htmlspecialchars($nivel['descricao'] ?? '') ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-link text-secondary p-0 me-2"
                                    data-bs-toggle="tooltip" title="Editar nível"
                                    onclick="openLevelModal('edit', <?= (int)$nivel['id'] ?>)">
                                    <i class="las la-pen fs-18"></i>
                                </button>
                                <button type="button" class="btn btn-link text-secondary p-0 me-2"
                                    data-bs-toggle="tooltip" title="Permissões"
                                    onclick="openPermissionsModal(<?= (int)$nivel['id'] ?>, '<?= htmlspecialchars($nivel['nome'], ENT_QUOTES) ?>')">
                                    <i class="las la-user-shield fs-18"></i>
                                </button>
                                <button type="button" class="btn btn-link text-danger p-0"
                                    data-bs-toggle="tooltip" title="Excluir nível"
                                    onclick="confirmDeleteLevel(<?= (int)$nivel['id'] ?>)">
                                    <i class="las la-trash-alt fs-18"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Nível -->
<div class="modal fade" id="modalNivel" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalNivelTitulo">Novo nível</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formNivel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="nivel_id" id="campo_nivel_id">
                <div class="modal-body">
                    <div id="alertNivel" class="alert d-none"></div>
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" id="campo_nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" id="campo_descricao" rows="3" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Salvar nível</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Permissões -->
<div class="modal fade" id="modalPermissoes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Permissões do nível
                    <span class="text-muted fs-14 d-block" id="tituloPermissoesNivel"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formPermissoesNivel">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="nivel_id" id="permissoes_nivel_id">
                <div class="modal-body">
                    <div id="alertPermissoesNivel" class="alert d-none"></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="tabelaModulosPermissoes">
                            <thead>
                                <tr>
                                    <th>Módulo</th>
                                    <th class="text-center">Todas</th>
                                    <th class="text-center">Visualizar</th>
                                    <th class="text-center">Criar</th>
                                    <th class="text-center">Editar</th>
                                    <th class="text-center">Excluir</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-success">Salvar permissões</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConfirmNivel" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar exclusão</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Tem certeza que deseja excluir este nível?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDeleteNivel">Excluir nível</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>public/assets/libs/simple-datatables/umd/simple-datatables.js"></script>
<script>
    const controllerUrl = '<?= BASE_URL ?>app/modules/permissions/permissions_controller.php';
    const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
    const permissionTypes = ['visualizar', 'criar', 'editar', 'excluir'];
    let permissionsAlertTimeout = null;
    let pendingDeleteNivelId = null;
    let confirmNivelModal = null;

    function showPermissionsAlert(message, type = 'danger', autoHide = true) {
        const alertBox = document.getElementById('permissionsAlert');
        if (!alertBox) {
            window.alert(message);
            return;
        }

        if (permissionsAlertTimeout) {
            clearTimeout(permissionsAlertTimeout);
            permissionsAlertTimeout = null;
        }

        alertBox.textContent = message;
        alertBox.className = `alert alert-${type}`;
        alertBox.classList.remove('d-none');

        if (autoHide) {
            permissionsAlertTimeout = setTimeout(() => {
                alertBox.classList.add('d-none');
                permissionsAlertTimeout = null;
            }, 5000);
        }
    }

    function hidePermissionsAlert() {
        const alertBox = document.getElementById('permissionsAlert');
        if (!alertBox) {
            return;
        }
        if (permissionsAlertTimeout) {
            clearTimeout(permissionsAlertTimeout);
            permissionsAlertTimeout = null;
        }
        alertBox.classList.add('d-none');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const tabelaNiveis = new simpleDatatables.DataTable('#datatable_niveis', {
            searchable: true,
            fixedHeight: false,
            perPage: 10,
            perPageSelect: [5, 10, 20, 50],
            labels: {
                placeholder: 'Pesquisar...',
                perPage: 'registros por página',
                noRows: 'Nenhum nível encontrado',
                info: 'Mostrando {start} a {end} de {rows} níveis',
                previous: 'Anterior',
                next: 'Próximo',
                first: 'Primeiro',
                last: 'Último',
            }
        });

        if (window.initTooltips) {
            window.initTooltips();
            ['datatable.page', 'datatable.sort', 'datatable.search'].forEach(evt => {
                tabelaNiveis.on(evt, () => window.initTooltips && window.initTooltips());
            });
        }

        document.getElementById('formNivel')?.addEventListener('submit', submitNivelForm);
        document.getElementById('formPermissoesNivel')?.addEventListener('submit', submitPermissoesForm);
        const corpoPermissoes = document.querySelector('#tabelaModulosPermissoes tbody');
        if (corpoPermissoes) {
            corpoPermissoes.addEventListener('change', handlePermissionCheckboxChange);
        }
        document.getElementById('btnConfirmDeleteNivel')?.addEventListener('click', () => {
            if (!pendingDeleteNivelId) {
                showPermissionsAlert('Registro inválido para exclusão.', 'danger');
                return;
            }
            confirmNivelModal = confirmNivelModal || bootstrap.Modal.getOrCreateInstance('#modalConfirmNivel');
            confirmNivelModal.hide();
            const nivelId = pendingDeleteNivelId;
            pendingDeleteNivelId = null;
            deleteLevel(nivelId);
        });
    });

    function resetNivelForm() {
        const form = document.getElementById('formNivel');
        form.reset();
        document.getElementById('campo_nivel_id').value = '';
        document.getElementById('alertNivel').className = 'alert d-none';
    }

    function handlePermissionCheckboxChange(event) {
        const target = event.target;
        if (!target || target.type !== 'checkbox') {
            return;
        }

        const row = target.closest('tr');
        if (!row) {
            return;
        }

        if (target.dataset.perm === 'all') {
            const others = row.querySelectorAll('input[data-perm]:not([data-perm="all"])');
            others.forEach(cb => {
                cb.checked = target.checked;
            });
            return;
        }

        if (permissionTypes.includes(target.dataset.perm)) {
            const others = permissionTypes
                .map(tipo => row.querySelector(`input[data-perm="${tipo}"]`))
                .filter(Boolean);
            const allCheckbox = row.querySelector('input[data-perm="all"]');
            if (allCheckbox) {
                allCheckbox.checked = others.length && others.every(cb => cb.checked);
            }
        }
    }

    function openLevelModal(mode, id = null) {
        hidePermissionsAlert();
        resetNivelForm();
        document.getElementById('modalNivelTitulo').textContent = mode === 'edit' ? 'Editar nível' : 'Novo nível';

        if (mode === 'edit' && id) {
            fetch(`${controllerUrl}?action=get_level&id=${id}`, { credentials: 'same-origin' })
                .then(resp => resp.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Não foi possível carregar o nível.');
                    }
                    const nivel = data.nivel;
                    document.getElementById('campo_nivel_id').value = nivel.id;
                    document.getElementById('campo_nome').value = nivel.nome;
                    document.getElementById('campo_descricao').value = nivel.descricao || '';
                    bootstrap.Modal.getOrCreateInstance('#modalNivel').show();
                })
                .catch(err => showPermissionsAlert(err.message || 'Erro ao carregar nível.', 'danger', false));
        } else {
            bootstrap.Modal.getOrCreateInstance('#modalNivel').show();
        }
    }

    async function submitNivelForm(event) {
        event.preventDefault();
        const form = event.target;
        const alertBox = document.getElementById('alertNivel');
        alertBox.className = 'alert d-none';

        const fd = new FormData(form);
        fd.append('action', 'save_level');

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await resp.json();

            alertBox.classList.remove('d-none');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            alertBox.textContent = data.message || (data.success ? 'Nível salvo com sucesso.' : 'Falha ao salvar.');

            if (data.success) {
                setTimeout(() => window.location.reload(), 900);
            }
        } catch (err) {
            alertBox.classList.remove('d-none');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = err.message || 'Erro inesperado.';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar nível';
        }
    }

    function confirmDeleteLevel(id) {
        pendingDeleteNivelId = id;
        hidePermissionsAlert();
        confirmNivelModal = confirmNivelModal || bootstrap.Modal.getOrCreateInstance('#modalConfirmNivel');
        confirmNivelModal.show();
    }

    async function deleteLevel(id) {
        if (!id) {
            showPermissionsAlert('Nível inválido.', 'danger');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'delete_level');
        fd.append('id', id);
        fd.append('csrf_token', csrfToken);

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await resp.json();
            if (!data.success) {
                showPermissionsAlert(data.message || 'Não foi possível excluir.', 'danger', false);
                return;
            }
            window.location.reload();
        } catch (err) {
            showPermissionsAlert(err.message || 'Erro inesperado.', 'danger', false);
        }
    }

    async function openPermissionsModal(nivelId, nomeNivel) {
        hidePermissionsAlert();
        document.getElementById('permissoes_nivel_id').value = nivelId;
        document.getElementById('tituloPermissoesNivel').textContent = nomeNivel;
        document.querySelector('#tabelaModulosPermissoes tbody').innerHTML = '<tr><td colspan="6">Carregando...</td></tr>';

        const modal = bootstrap.Modal.getOrCreateInstance('#modalPermissoes');
        modal.show();

        try {
            const resp = await fetch(`${controllerUrl}?action=get_level_permissions&id=${nivelId}`, { credentials: 'same-origin' });
            const data = await resp.json();
            if (!data.success) {
                document.querySelector('#tabelaModulosPermissoes tbody').innerHTML = '<tr><td colspan="6">Erro ao carregar permissões.</td></tr>';
                return;
            }
            renderPermissionsRows(data.modulos || []);
        } catch (err) {
            document.querySelector('#tabelaModulosPermissoes tbody').innerHTML = `<tr><td colspan="6">${err.message}</td></tr>`;
        }
    }

    function renderPermissionsRows(modulos) {
        const tbody = document.querySelector('#tabelaModulosPermissoes tbody');
        tbody.innerHTML = '';

        if (!Array.isArray(modulos) || !modulos.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Nenhum módulo encontrado.</td></tr>';
            return;
        }

        modulos.forEach(modulo => {
            const tr = document.createElement('tr');
            tr.dataset.id = modulo.id;
            const allChecked = permissionTypes.every(tipo => modulo['pode_' + tipo]);
            tr.innerHTML = `
                <td>${modulo.nome}</td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" data-perm="all" ${allChecked ? 'checked' : ''}>
                </td>
                ${permissionTypes.map(tipo => `
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input" data-perm="${tipo}" ${modulo['pode_' + tipo] ? 'checked' : ''}>
                    </td>
                `).join('')}
            `;
            tbody.appendChild(tr);
        });
    }

    async function submitPermissoesForm(event) {
        event.preventDefault();
        const form = event.target;
        const alertBox = document.getElementById('alertPermissoesNivel');
        alertBox.className = 'alert d-none';

        const linhas = Array.from(document.querySelectorAll('#tabelaModulosPermissoes tbody tr'));
        const payload = linhas
            .filter(linha => linha.dataset.id)
            .map(linha => {
                const flag = tipo => linha.querySelector(`input[data-perm="${tipo}"]`)?.checked ? 1 : 0;
                return {
                    modulo_id: parseInt(linha.dataset.id, 10),
                    pode_visualizar: flag('visualizar'),
                    pode_criar: flag('criar'),
                    pode_editar: flag('editar'),
                    pode_excluir: flag('excluir'),
                };
            });

        const fd = new FormData(form);
        fd.append('action', 'save_level_permissions');
        fd.append('permissions', JSON.stringify(payload));

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await resp.json();

            alertBox.classList.remove('d-none');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            alertBox.textContent = data.message || (data.success ? 'Permissões atualizadas.' : 'Falha ao salvar permissões.');

            if (data.success) {
                setTimeout(() => window.location.reload(), 900);
            }
        } catch (err) {
            alertBox.classList.remove('d-none');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = err.message || 'Erro inesperado.';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar permissões';
        }
    }
</script>
