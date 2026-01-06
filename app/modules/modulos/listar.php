<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';
require_once APP_PATH . '/core/permissions.php';
require_once __DIR__ . '/ordem_helper.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

organizarOrdemModulos($pdo);

try {
    $stmt = $pdo->query("
        SELECT 
            m.id,
            m.nome,
            m.slug,
            m.descricao,
            m.icone,
            m.ordem,
            m.id_pai,
            m.ativo,
            m.ver_menu,
            pai.nome AS nome_pai,
            COUNT(DISTINCT perm.id_nivel) AS niveis_configurados
        FROM modulos m
        LEFT JOIN modulos pai ON pai.id = m.id_pai
        LEFT JOIN permissoes perm ON perm.id_modulo = m.id
        GROUP BY m.id, m.nome, m.slug, m.descricao, m.icone, m.ordem, m.id_pai, m.ativo, pai.nome
        ORDER BY m.ordem ASC, m.nome ASC
    ");
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $modulos = [];
}

$modulosOrganizados = $modulos;
if (!empty($modulos)) {
    $modulosMap = [];
    foreach ($modulos as $item) {
        $item['filhos'] = [];
        $modulosMap[$item['id']] = $item;
    }

    foreach ($modulosMap as $id => &$item) {
        $parentId = (int)($item['id_pai'] ?? 0);
        if ($parentId && isset($modulosMap[$parentId])) {
            $modulosMap[$parentId]['filhos'][] =& $item;
        }
    }
    unset($item);

    $tree = [];
    foreach ($modulosMap as $id => &$item) {
        $parentId = (int)($item['id_pai'] ?? 0);
        if (!$parentId || !isset($modulosMap[$parentId])) {
            $tree[] =& $item;
        }
    }
    unset($item);

    $sortTree = function (array &$nodes) use (&$sortTree) {
        usort($nodes, function ($a, $b) {
            $ordA = (int)($a['ordem'] ?? 0);
            $ordB = (int)($b['ordem'] ?? 0);
            if ($ordA === $ordB) {
                return strcasecmp((string)$a['nome'], (string)$b['nome']);
            }
            return $ordA <=> $ordB;
        });
        foreach ($nodes as &$node) {
            if (!empty($node['filhos'])) {
                $sortTree($node['filhos']);
            }
        }
        unset($node);
    };
    $sortTree($tree);

    $flatten = function (array $nodes, int $nivel = 0) use (&$flatten) {
        $result = [];
        foreach ($nodes as $node) {
            $children = $node['filhos'] ?? [];
            $node['nivel_visual'] = $nivel;
            unset($node['filhos']);
            $result[] = $node;
            if ($children) {
                $result = array_merge($result, $flatten($children, $nivel + 1));
            }
        }
        return $result;
    };

    $modulosOrganizados = $flatten($tree);
}

$parentOptions = array_map(function ($item) {
    return [
        'id'      => (int)$item['id'],
        'nome'    => $item['nome'],
        'ver_menu'=> (int)($item['ver_menu'] ?? 1)
    ];
}, $modulos);

$csrfToken = $_SESSION['csrf_token'] ?? '';
$usuarioPodeExcluirModulo = isset($_SESSION['usuario_id']) && function_exists('userHasPermission')
    ? userHasPermission($_SESSION['usuario_id'], 'modulos', 'pode_excluir')
    : false;
?>

<link href="<?= BASE_URL ?>public/assets/libs/simple-datatables/style.css" rel="stylesheet">

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h4 class="card-title mb-0">Gerenciar Módulos e Permissões</h4>
            </div>
            <div class="col-auto">
                <button type="button" class="btn bg-primary text-white" data-bs-toggle="modal"
                    data-bs-target="#modalModulo" onclick="openCreateModule()">
                    <i class="fas fa-plus me-1"></i> Novo Módulo
                </button>
            </div>
        </div>
    </div>

    <div class="card-body pt-0">
        <div id="modulosAlert" class="alert d-none" role="alert"></div>
        <div class="table-responsive">
            <table class="table mb-0" id="datatable_modulos">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>Diretorio</th>
                        <th>Ícone</th>
                        <th>Pai</th>
                        <th>Ordem</th>
                        <th>Menu</th>
                        <th>Status</th>
                        <th>Níveis</th>
                        <th class="text-end">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modulosOrganizados as $mod): ?>
                        <?php
                            $nivelAtual = (int)($mod['nivel_visual'] ?? 0);
                            $indentPadding = max(0, $nivelAtual * 20);
                        ?>
                        <tr>
                            <td style="padding-left: <?= (int)$indentPadding ?>px;">
                                <div class="fw-semibold"><?= htmlspecialchars($mod['nome']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($mod['descricao'] ?? '') ?></small>
                            </td>
                            <td><code><?= htmlspecialchars($mod['slug']) ?></code></td>
                            <td><?= htmlspecialchars($mod['icone'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($mod['nome_pai'] ?? '--') ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-semibold"><?= (int)$mod['ordem'] ?></span>
                                    <button type="button" class="btn btn-link text-secondary p-0" data-bs-toggle="tooltip"
                                        title="Mover para cima" onclick="reorderModule(<?= (int)$mod['id'] ?>, 'up')">
                                        <i class="las la-arrow-up"></i>
                                    </button>
                                    <button type="button" class="btn btn-link text-secondary p-0" data-bs-toggle="tooltip"
                                        title="Mover para baixo" onclick="reorderModule(<?= (int)$mod['id'] ?>, 'down')">
                                        <i class="las la-arrow-down"></i>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <?php if ((int)($mod['ver_menu'] ?? 1) === 1): ?>
                                    <span class="badge bg-success-subtle text-success">Visível</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-muted">Oculto</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$mod['ativo'] === 1): ?>
                                    <span class="badge bg-success-subtle text-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-dark-subtle text-dark">
                                    <?= (int)$mod['niveis_configurados'] ?> níveis
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button"
                                    class="btn btn-link text-secondary p-0 me-2"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Editar módulo"
                                    onclick="openEditModule(<?= (int)$mod['id'] ?>)">
                                    <i class="las la-pen fs-18"></i>
                                </button>
                                <button type="button"
                                    class="btn btn-link text-secondary p-0 me-2"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Gerenciar permissões"
                                    onclick="openPermissionsModal(<?= (int)$mod['id'] ?>, '<?= htmlspecialchars($mod['nome'], ENT_QUOTES) ?>')">
                                    <i class="las la-user-shield fs-18"></i>
                                </button>
                                <?php if ($usuarioPodeExcluirModulo): ?>
                                    <button type="button"
                                        class="btn btn-link text-danger p-0"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        title="Excluir módulo"
                                        onclick="confirmDeleteModule(<?= (int)$mod['id'] ?>)">
                                        <i class="las la-trash-alt fs-18"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal cadastro/edição -->
<div class="modal fade" id="modalModulo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalModuloTitulo">Novo módulo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formModulo">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="modulo_id" id="campo_modulo_id">
                <div class="modal-body">
                    <div id="alertModulo" class="alert d-none"></div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="nome" id="campo_nome" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Diretório (slug)</label>
                            <input type="text" name="slug" id="campo_slug" class="form-control" required>
                            <small class="text-muted">Informe o caminho do módulo (ex.: modules/produtos/sub-modulo), minúsculo e sem espaços.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ícone (classe)</label>
                            <input type="text" name="icone" id="campo_icone" class="form-control" placeholder="las la-chart-line">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ordem</label>
                            <input type="number" step="1" min="0" name="ordem" id="campo_ordem" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Módulo pai</label>
                            <select name="id_pai" id="campo_modulo_pai" class="form-select">
                                <option value="">(Nenhum)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="ativo" id="campo_ativo" class="form-select">
                                <option value="1">Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mostrar no menu</label>
                            <select name="ver_menu" id="campo_ver_menu" class="form-select">
                                <option value="1">Visível</option>
                                <option value="0">Oculto</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="campo_descricao" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Salvar módulo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal permissões -->
<div class="modal fade" id="modalPermissoes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    Permissões de acesso
                    <span class="text-muted fs-14 d-block" id="tituloPermissoes"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formPermissoes">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="modulo_id" id="permissoes_modulo_id">
                <div class="modal-body">
                    <div id="alertPermissoes" class="alert d-none"></div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="tabelaPermissoes">
                            <thead>
                                <tr>
                                    <th>Nível</th>
                                    <th class="text-center">Visualizar</th>
                                    <th class="text-center">Criar</th>
                                    <th class="text-center">Editar</th>
                                    <th class="text-center">Excluir</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
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

<div class="modal fade" id="modalConfirmDeleteModulo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar exclusão</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Tem certeza que deseja excluir este módulo? Essa ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDeleteModulo">Excluir módulo</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>public/assets/libs/simple-datatables/umd/simple-datatables.js"></script>
<script>
const controllerUrl = '<?= BASE_URL ?>app/modules/modulos/modulos_controller.php';
const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
const modulosDisponiveis = <?= json_encode($parentOptions, JSON_UNESCAPED_UNICODE) ?>;
let modulosAlertTimeout = null;
let pendingDeleteModuloId = null;
let confirmDeleteModuloModal = null;
let reorderInProgress = false;

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) {
            throw new Error('Resposta vazia do servidor.');
        }
        try {
            return JSON.parse(text);
        } catch (err) {
            throw new Error(text);
        }
    }

    function showModulosAlert(message, type = 'danger', autoHide = true) {
        const alertBox = document.getElementById('modulosAlert');
        if (!alertBox) {
            window.alert(message);
            return;
        }

        if (modulosAlertTimeout) {
            clearTimeout(modulosAlertTimeout);
            modulosAlertTimeout = null;
        }

        alertBox.textContent = message;
        alertBox.className = `alert alert-${type}`;
        alertBox.classList.remove('d-none');

        if (autoHide) {
            modulosAlertTimeout = setTimeout(() => {
                alertBox.classList.add('d-none');
                modulosAlertTimeout = null;
            }, 5000);
        }
    }

    function hideModulosAlert() {
        const alertBox = document.getElementById('modulosAlert');
        if (!alertBox) {
            return;
        }
        if (modulosAlertTimeout) {
            clearTimeout(modulosAlertTimeout);
            modulosAlertTimeout = null;
        }
        alertBox.classList.add('d-none');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const tabelaModulos = new simpleDatatables.DataTable('#datatable_modulos', {
            searchable: true,
            perPage: 50,
            labels: {
                placeholder: 'Buscar...',
                perPage: 'registros por página',
                noRows: 'Nenhum módulo encontrado',
                info: 'Mostrando de {start} a {end}. Total {rows}.'
            }
        });

        if (window.initTooltips) {
            window.initTooltips();
        }
        ['datatable.page', 'datatable.sort', 'datatable.search'].forEach(function (evt) {
            tabelaModulos.on(evt, function () {
                if (window.initTooltips) {
                    window.initTooltips();
                }
            });
        });

        document.getElementById('formModulo')?.addEventListener('submit', submitModuloForm);
        document.getElementById('formPermissoes')?.addEventListener('submit', submitPermissoesForm);

        document.getElementById('campo_nome')?.addEventListener('input', function () {
            if (!document.getElementById('campo_modulo_id').value) {
                document.getElementById('campo_slug').value = slugify(this.value);
            }
        });

        document.getElementById('btnConfirmDeleteModulo')?.addEventListener('click', function () {
            if (!pendingDeleteModuloId) {
                showModulosAlert('Registro inválido para exclusão.', 'danger');
                return;
            }
            confirmDeleteModuloModal = confirmDeleteModuloModal || bootstrap.Modal.getOrCreateInstance('#modalConfirmDeleteModulo');
            confirmDeleteModuloModal.hide();
            const moduleId = pendingDeleteModuloId;
            pendingDeleteModuloId = null;
            deleteModule(moduleId);
        });
    });

    function slugify(value) {
        return value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\/]+/g, '-')
            .replace(/\/+/g, '/')
            .replace(/(^[-\/]+|[-\/]+$)/g, '')
            .substring(0, 120);
    }

    function fillParentOptions(excludeId = 0, selectedId = '') {
        const select = document.getElementById('campo_modulo_pai');
        if (!select) return;

        const currentValue = selectedId || '';
        select.innerHTML = '<option value="">(Nenhum)</option>';
        modulosDisponiveis.forEach(mod => {
            if (excludeId && mod.id === excludeId) return;
            const option = document.createElement('option');
            option.value = mod.id;
            option.textContent = mod.nome;
            if (parseInt(currentValue, 10) === mod.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function resetModuloForm() {
        const form = document.getElementById('formModulo');
        form.reset();
        document.getElementById('campo_modulo_id').value = '';
        document.getElementById('alertModulo').className = 'alert d-none';
        const campoVerMenu = document.getElementById('campo_ver_menu');
        if (campoVerMenu) {
            campoVerMenu.value = '1';
        }
    }

    function openCreateModule() {
        hideModulosAlert();
        resetModuloForm();
        fillParentOptions();
        document.getElementById('modalModuloTitulo').textContent = 'Novo módulo';
    }

    async function openEditModule(id) {
        hideModulosAlert();
        resetModuloForm();
        document.getElementById('modalModuloTitulo').textContent = 'Editar módulo';
        fillParentOptions(id);

        try {
            const resp = await fetch(`${controllerUrl}?action=get_module&id=${id}`, { credentials: 'same-origin' });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                showModulosAlert(data.message || 'Não foi possível carregar o módulo.', 'danger', false);
                return;
            }
            const modulo = data.modulo;
            document.getElementById('campo_modulo_id').value = modulo.id;
            document.getElementById('campo_nome').value = modulo.nome;
            document.getElementById('campo_slug').value = modulo.slug;
            document.getElementById('campo_descricao').value = modulo.descricao || '';
            document.getElementById('campo_icone').value = modulo.icone || '';
            document.getElementById('campo_ordem').value = modulo.ordem ?? 0;
            document.getElementById('campo_ativo').value = modulo.ativo ? '1' : '0';
            const campoVerMenu = document.getElementById('campo_ver_menu');
            if (campoVerMenu) {
                campoVerMenu.value = modulo.ver_menu ? '1' : '0';
            }
            fillParentOptions(modulo.id, modulo.id_pai || '');

            const modal = bootstrap.Modal.getOrCreateInstance('#modalModulo');
            modal.show();
        } catch (err) {
            showModulosAlert(err.message || 'Erro inesperado ao carregar o módulo.', 'danger', false);
        }
    }

    async function submitModuloForm(e) {
        e.preventDefault();
        const form = e.target;
        const alertBox = document.getElementById('alertModulo');
        alertBox.className = 'alert d-none';

        const fd = new FormData(form);
        fd.append('action', 'save_module');

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await parseJsonResponse(resp);
            alertBox.classList.remove('d-none');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            alertBox.textContent = data.message || (data.success ? 'Módulo salvo.' : 'Falha ao salvar módulo.');

            if (data.success) {
                setTimeout(() => window.location.reload(), 700);
            }
        } catch (err) {
            alertBox.classList.remove('d-none');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = err.message || 'Erro inesperado.';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar módulo';
        }
    }

    async function openPermissionsModal(id, nome) {
        document.getElementById('alertPermissoes').className = 'alert d-none';
        document.querySelector('#tabelaPermissoes tbody').innerHTML = '<tr><td colspan="5">Carregando...</td></tr>';
        document.getElementById('permissoes_modulo_id').value = id;
        document.getElementById('tituloPermissoes').textContent = nome;

        const modal = bootstrap.Modal.getOrCreateInstance('#modalPermissoes');
        modal.show();

        try {
            const resp = await fetch(`${controllerUrl}?action=get_permissions&id=${id}`, { credentials: 'same-origin' });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                document.querySelector('#tabelaPermissoes tbody').innerHTML = '<tr><td colspan="5">Não foi possível carregar os níveis.</td></tr>';
                return;
            }

            renderPermissionsRows(data.niveis || []);
        } catch (err) {
            document.querySelector('#tabelaPermissoes tbody').innerHTML = `<tr><td colspan="5">${err.message}</td></tr>`;
        }
    }

    function renderPermissionsRows(levels) {
        const tbody = document.querySelector('#tabelaPermissoes tbody');
        tbody.innerHTML = '';
        if (!levels.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">Nenhum nível cadastrado.</td></tr>';
            return;
        }

        levels.forEach(level => {
            const tr = document.createElement('tr');
            tr.dataset.id = level.id;
            tr.innerHTML = `
                <td>${level.nome}</td>
                ${['visualizar', 'criar', 'editar', 'excluir'].map(tipo => `
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input"
                            ${level['pode_' + tipo] ? 'checked' : ''} data-perm="${tipo}">
                    </td>
                `).join('')}
            `;
            tbody.appendChild(tr);
        });
    }

    async function submitPermissoesForm(e) {
        e.preventDefault();
        const form = e.target;
        const alertBox = document.getElementById('alertPermissoes');
        alertBox.className = 'alert d-none';

        const linhas = Array.from(document.querySelectorAll('#tabelaPermissoes tbody tr'));
        const payload = linhas
            .filter(linha => linha.dataset.id)
            .map(linha => {
                const check = tipo => linha.querySelector(`input[data-perm="${tipo}"]`)?.checked ? 1 : 0;
                return {
                    nivel_id: parseInt(linha.dataset.id, 10),
                    pode_visualizar: check('visualizar'),
                    pode_criar: check('criar'),
                    pode_editar: check('editar'),
                    pode_excluir: check('excluir'),
                };
            });

        const fd = new FormData(form);
        fd.append('action', 'save_permissions');
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
            const data = await parseJsonResponse(resp);
            alertBox.classList.remove('d-none');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            alertBox.textContent = data.message || (data.success ? 'Permissões atualizadas.' : 'Falha ao salvar permissões.');

            if (data.success) {
                setTimeout(() => window.location.reload(), 800);
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

    async function reorderModule(id, direction) {
        if (!id || !['up', 'down'].includes(direction)) {
            return;
        }
        if (reorderInProgress) {
            return;
        }
        reorderInProgress = true;

        const fd = new FormData();
        fd.append('action', 'reorder_module');
        fd.append('id', id);
        fd.append('direction', direction);
        fd.append('csrf_token', csrfToken);

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                showModulosAlert(data.message || 'Nǜo foi poss��vel reordenar o m��dulo.', 'danger', false);
                return;
            }
            showModulosAlert(data.message || 'Ordem atualizada.', 'success');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            showModulosAlert(err.message || 'Erro inesperado ao reordenar o m��dulo.', 'danger', false);
        } finally {
            reorderInProgress = false;
        }
    }

    function confirmDeleteModule(id) {
        pendingDeleteModuloId = id;
        hideModulosAlert();
        confirmDeleteModuloModal = confirmDeleteModuloModal || bootstrap.Modal.getOrCreateInstance('#modalConfirmDeleteModulo');
        confirmDeleteModuloModal.show();
    }

    async function deleteModule(id) {
        if (!id) {
            showModulosAlert('Módulo inválido.', 'danger');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'delete_module');
        fd.append('id', id);
        fd.append('csrf_token', csrfToken);

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                showModulosAlert(data.message || 'Não foi possível excluir o módulo.', 'danger', false);
                return;
            }
            showModulosAlert(data.message || 'Módulo excluído com sucesso.', 'success');
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            showModulosAlert(err.message || 'Erro inesperado ao excluir módulo.', 'danger', false);
        }
    }
</script>
