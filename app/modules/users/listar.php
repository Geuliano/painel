<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdoColumnsEnsured = false;
function ensureUsuariosExtraColumns(PDO $pdo): void
{
    global $pdoColumnsEnsured;
    if ($pdoColumnsEnsured) {
        return;
    }
    $pdoColumnsEnsured = true;
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN apelido VARCHAR(160) NULL AFTER nome");
    } catch (Throwable $e) {
        // já existe ou sem permissão
    }
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(20) NULL AFTER email");
    } catch (Throwable $e) {
        // já existe ou sem permissão
    }
}
ensureUsuariosExtraColumns($pdo);

$formatarTelefone = function (?string $telefone): ?string {
    if (!$telefone) {
        return null;
    }
    $numeros = preg_replace('/\D+/', '', $telefone);
    if (strpos($numeros, '55') !== 0) {
        return $telefone;
    }
    $semDdi = substr($numeros, 2);
    if (strlen($semDdi) === 10) {
        return sprintf('+55 (%s) %s-%s', substr($semDdi, 0, 2), substr($semDdi, 2, 4), substr($semDdi, 6));
    }
    if (strlen($semDdi) === 11) {
        return sprintf('+55 (%s) %s %s-%s', substr($semDdi, 0, 2), substr($semDdi, 2, 1), substr($semDdi, 3, 4), substr($semDdi, 7));
    }
    return '+55 ' . $semDdi;
};

$avatarDir = rtrim(PUBLIC_PATH, '/\\') . '/uploads/avatars/';
$avatarUrlBase = BASE_URL . 'public/uploads/avatars/';
$defaultAvatar = BASE_URL . 'public/assets/images/users/default.png';

try {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.nome,
            u.apelido,
            u.email,
            u.telefone,
            u.avatar,
            u.criado_em,
            u.nivel,
            COALESCE(n2.nome, n1.nome) AS nivel_nome
        FROM usuarios u
        LEFT JOIN niveis_acesso n1 ON n1.id = u.nivel
        LEFT JOIN usuario_nivel un ON un.id_usuario = u.id
        LEFT JOIN niveis_acesso n2 ON n2.id = un.id_nivel
        ORDER BY u.id DESC
    ");
    $usuarios = $stmt->fetchAll();
} catch (Throwable $e) {
    $usuarios = [];
}

try {
    $stmtNivel = $pdo->query("SELECT id, nome FROM niveis_acesso ORDER BY nome ASC");
    $niveisAcesso = $stmtNivel->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $niveisAcesso = [];
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$possuiNiveis = !empty($niveisAcesso);

foreach ($usuarios as &$userTmp) {
    $userTmp['telefone_formatado'] = $formatarTelefone($userTmp['telefone'] ?? null);
}
unset($userTmp);
?>

<!-- CSS DataTable do template -->
<link href="<?= BASE_URL ?>public/assets/libs/simple-datatables/style.css" rel="stylesheet">

<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col">
                <h4 class="card-title">Gerenciar Usuários</h4>
            </div>
            <div class="col-auto">
                <button type="button" class="btn bg-primary text-white"
                    onclick="openUserModal('create')" <?= $possuiNiveis ? '' : 'disabled' ?>>
                    <i class="fas fa-plus me-1"></i> Novo Usuário
                </button>
            </div>
        </div>
    </div>

    <div class="card-body pt-0">
        <div class="table-responsive">
            <table class="table mb-0" id="datatable_usuarios">
                <thead class="table-dark">
                    <tr>
                        <th>Usuário</th>
                        <th>Apelido</th>
                        <th>Email</th>
                        <th>Telefone</th>
                        <th>Nível</th>
                        <th>Registrado</th>
                        <th class="text-end">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                        $avatarFile = !empty($u['avatar']) ? $avatarDir . $u['avatar'] : null;
                        $avatarUrl = (!empty($u['avatar']) && is_file($avatarFile))
                            ? $avatarUrlBase . $u['avatar']
                            : $defaultAvatar;
                        ?>
                        <tr>
                            <td class="d-flex align-items-center">
                                <div class="d-flex align-items-center">
                                    <img src="<?= htmlspecialchars($avatarUrl) ?>" class="me-2 thumb-md align-self-center rounded"
                                        style="width:40px;height:40px;object-fit:cover" alt="Avatar">
                                    <div class="flex-grow-1 text-truncate">
                                        <h6 class="m-0"><?= htmlspecialchars($u['nome']) ?></h6>
                                        <p class="fs-12 text-muted mb-0">ID <?= (int)$u['id'] ?></p>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($u['apelido'] ?: '--') ?></td>
                            <td>
                                <a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="text-body text-decoration-underline">
                                    <?= htmlspecialchars($u['email']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($u['telefone_formatado'] ?? ($u['telefone'] ?: '--')) ?></td>
                            <td><?= htmlspecialchars($u['nivel_nome'] ?? '--') ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($u['criado_em'])) ?></td>
                            <td class="text-end">
                                <button type="button"
                                    class="btn btn-link text-secondary p-0 me-2"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Editar usuário"
                                    onclick="openUserModal('edit', <?= (int)$u['id'] ?>, '<?= htmlspecialchars($avatarUrl, ENT_QUOTES) ?>')">
                                    <i class="las la-pen fs-18"></i>
                                </button>
                                <button type="button"
                                    class="btn btn-link text-danger p-0"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Excluir usuário"
                                    onclick="confirmDelete(<?= (int)$u['id'] ?>)">
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

<!-- Modal de criação/edição -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="usuarioModalTitle">Novo usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="formUsuario" enctype="multipart/form-data" data-mode="create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="usuario_id">
                <div class="modal-body">
                    <div class="row g">
                        <div class="col-lg-4 text-center">
                            <img src="<?= htmlspecialchars($defaultAvatar) ?>" id="previewAvatar"
                                class="rounded-circle shadow mb-3"
                                style="width:140px;height:140px;object-fit:cover" alt="Avatar">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Avatar</label>
                                <input type="file" name="avatar" id="campo_avatar" class="form-control" accept="image/*">
                                <small class="text-muted d-block">JPG/PNG até 2MB</small>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="mb-3">
                                <label class="form-label">Nome completo</label>
                                <input type="text" class="form-control" name="nome" id="campo_nome" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Apelido</label>
                                <input type="text" class="form-control" name="apelido" id="campo_apelido" placeholder="Opcional">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="campo_email" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Telefone</label>
                                <div class="input-group">
                                    <span class="input-group-text">+55</span>
                                    <input type="text" class="form-control" name="telefone" id="campo_telefone" placeholder="(11) 98888-7777" inputmode="tel">
                                </div>
                                <small class="text-muted">Guardamos já com DDI para uso no WhatsApp.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" id="labelSenha">Senha</label>
                                <input type="password" class="form-control" name="senha" id="campo_senha"
                                    placeholder="Mínimo 6 caracteres">
                                <small class="text-muted" id="hintSenha">Obrigatória apenas para novos usuários.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nível de acesso</label>
                                <select class="form-select" name="nivel" id="campo_nivel" <?= $possuiNiveis ? 'required' : 'disabled' ?>>
                                    <?php if (!$possuiNiveis): ?>
                                        <option value="">Nenhum nível cadastrado</option>
                                    <?php else: ?>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($niveisAcesso as $nivel): ?>
                                            <option value="<?= (int)$nivel['id'] ?>"><?= htmlspecialchars($nivel['nome']) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <?php if (!$possuiNiveis): ?>
                                    <small class="text-danger">Cadastre níveis antes de criar novos usuários.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btnSalvarUsuario"
                        <?= $possuiNiveis ? '' : 'disabled' ?>>Salvar usuário</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="<?= BASE_URL ?>public/assets/libs/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= BASE_URL ?>public/assets/libs/simple-datatables/umd/simple-datatables.js"></script>
<script>
    const controllerUrl = '<?= BASE_URL ?>app/modules/users/users_controller.php';
    const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
    const niveisDisponiveis = <?= json_encode($niveisAcesso, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
    const possuiNiveis = niveisDisponiveis.length > 0;
    const defaultAvatar = '<?= htmlspecialchars($defaultAvatar) ?>';

    function getSwalThemeUsers() {
        const theme = (document.documentElement.getAttribute('data-bs-theme') || 'light').toLowerCase();
        const isDark = theme === 'dark';
        return {
            background: isDark ? '#1f1f1f' : '#fff',
            color: isDark ? '#f8f9fa' : '#111',
            iconColor: isDark ? '#f8f9fa' : undefined
        };
    }

    const toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
        didOpen: (toastEl) => {
            toastEl.addEventListener('mouseenter', Swal.stopTimer);
            toastEl.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    function showUsersAlert(message, type = 'danger') {
        const icon = (type === 'success')
            ? 'success'
            : (type === 'warning')
            ? 'warning'
            : (type === 'info' || type === 'primary')
                ? 'info'
                : 'error';

        const swalTheme = getSwalThemeUsers();
        Swal.fire({
            icon,
            text: message,
            confirmButtonColor: '#3085d6',
            background: swalTheme.background,
            color: swalTheme.color,
            iconColor: swalTheme.iconColor,
        });
    }

    function showToast(message, type = 'success') {
        const icon = (type === 'success')
            ? 'success'
            : (type === 'warning')
                ? 'warning'
                : (type === 'info' || type === 'primary')
                    ? 'info'
                    : 'error';
        const swalTheme = getSwalThemeUsers();
        toast.fire({
            icon,
            title: message,
            background: swalTheme.background,
            color: swalTheme.color,
            iconColor: swalTheme.iconColor
        });
    }

    function hideUsersAlert() {
        Swal.close();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const tabelaUsuarios = new simpleDatatables.DataTable('#datatable_usuarios', {
            searchable: true,
            fixedHeight: false,
            perPage: 10,
            perPageSelect: [5, 10, 20, 50],
            labels: {
                placeholder: 'Pesquisar...',
                perPage: 'registros por página',
                noRows: 'Nenhum registro encontrado',
                info: 'Mostrando {start} a {end} de {rows} registros',
                previous: 'Anterior',
                next: 'Próximo',
                first: 'Primeiro',
                last: 'Último',
            }
        });

        if (window.initTooltips) {
            window.initTooltips();
        }
        ['datatable.page', 'datatable.sort', 'datatable.search'].forEach(evt => {
            tabelaUsuarios.on(evt, () => {
                if (window.initTooltips) {
                    window.initTooltips();
                }
            });
        });

        document.getElementById('formUsuario')?.addEventListener('submit', submitUsuarioForm);
        document.getElementById('campo_avatar')?.addEventListener('change', handleAvatarPreview);
        attachTelefoneMask();
    });

    function setPreviewSrc(src) {
        const preview = document.getElementById('previewAvatar');
        if (!preview) return;
        preview.onerror = () => {
            preview.onerror = null;
            preview.src = defaultAvatar;
        };
        preview.src = src || defaultAvatar;
    }

    function handleAvatarPreview(event) {
        const file = event.target.files[0];
        setPreviewSrc(file ? URL.createObjectURL(file) : defaultAvatar);
    }

    function formatTelefoneBr(value) {
        let digits = (value || '').replace(/\D+/g, '');
        if (digits.startsWith('55') && digits.length > 11) {
            digits = digits.slice(2);
        }
        digits = digits.slice(0, 11);
        const ddd = digits.slice(0, 2);
        const rest = digits.slice(2);
        if (!ddd) return '';
        if (rest.length <= 4) {
            return `(${ddd}) ${rest}`;
        }
        if (rest.length <= 8) {
            return `(${ddd}) ${rest.slice(0, 4)}-${rest.slice(4)}`;
        }
        return `(${ddd}) ${rest.slice(0, 5)}-${rest.slice(5, 9)}`;
    }

    function attachTelefoneMask() {
        const tel = document.getElementById('campo_telefone');
        if (!tel) return;
        tel.value = formatTelefoneBr(tel.value);
        tel.addEventListener('input', () => {
            tel.value = formatTelefoneBr(tel.value);
        });
    }

    function resetUsuarioForm() {
        const form = document.getElementById('formUsuario');
        form.reset();
        form.dataset.mode = 'create';
        document.getElementById('usuario_id').value = '';
        document.getElementById('campo_apelido').value = '';
        document.getElementById('campo_telefone').value = '';
        setPreviewSrc(defaultAvatar);
        document.getElementById('hintSenha').textContent = 'Obrigatória apenas para novos usuários.';
        document.getElementById('campo_senha').required = true;

        if (possuiNiveis) {
            const select = document.getElementById('campo_nivel');
            select.innerHTML = '<option value=\"\">Selecione...</option>';
            niveisDisponiveis.forEach(nivel => {
                const option = document.createElement('option');
                option.value = nivel.id;
                option.textContent = nivel.nome;
                select.appendChild(option);
            });
            select.disabled = false;
        }
    }

    async function openUserModal(mode, userId = null, avatarHint = '') {
        const modal = bootstrap.Modal.getOrCreateInstance('#modalUsuario');
        hideUsersAlert();
        resetUsuarioForm();
        const form = document.getElementById('formUsuario');
        form.dataset.mode = mode;

        const senhaInput = document.getElementById('campo_senha');
        document.getElementById('usuarioModalTitle').textContent = mode === 'create'
            ? 'Novo usuário'
            : 'Editar usuário';
        document.getElementById('hintSenha').textContent = mode === 'create'
            ? 'Obrigatória apenas para novos usuários.'
            : 'Preencha apenas se desejar alterar a senha.';
        senhaInput.value = '';
        senhaInput.required = (mode === 'create');

        if (mode === 'edit') {
            if (!userId) {
                showUsersAlert('ID inválido.', 'danger');
                return;
            }
            if (avatarHint) {
                setPreviewSrc(avatarHint);
            }
            try {
                const resp = await fetch(`${controllerUrl}?action=get&id=${userId}`, { credentials: 'same-origin' });
                const data = await resp.json();
                if (!data.success) {
                    showUsersAlert(data.message || 'Não foi possível carregar o usuário.', 'danger');
                    return;
                }
                fillUsuarioForm(data.usuario || {});
                modal.show();
            } catch (err) {
                showUsersAlert(err.message || 'Erro ao buscar usuário.', 'danger');
            }
        } else {
            if (!possuiNiveis) {
                showUsersAlert('Cadastre um nível de acesso antes de criar usuários.', 'warning');
                return;
            }
            modal.show();
        }
    }

    function fillUsuarioForm(usuario) {
        document.getElementById('usuario_id').value = usuario.id || '';
        document.getElementById('campo_nome').value = usuario.nome || '';
        document.getElementById('campo_apelido').value = usuario.apelido || '';
        document.getElementById('campo_email').value = usuario.email || '';
        document.getElementById('campo_telefone').value = formatTelefoneBr(usuario.telefone || '');
        setPreviewSrc(usuario.avatar_url || defaultAvatar);

        const select = document.getElementById('campo_nivel');
        if (possuiNiveis) {
            let optionExists = false;
            Array.from(select.options).forEach(opt => {
                if (parseInt(opt.value, 10) === parseInt(usuario.nivel, 10)) {
                    optionExists = true;
                    opt.selected = true;
                }
            });
            if (!optionExists && usuario.nivel) {
                const opt = document.createElement('option');
                opt.value = usuario.nivel;
                opt.textContent = 'Nível atual (inativo)';
                opt.selected = true;
                select.appendChild(opt);
            }
        }
    }

    async function submitUsuarioForm(event) {
        event.preventDefault();
        const form = event.target;
        const mode = form.dataset.mode || 'create';
        if (!possuiNiveis) {
            showUsersAlert('Cadastre um nível de acesso antes de salvar.', 'warning');
            return;
        }
        hideUsersAlert();

        const fd = new FormData(form);
        fd.append('action', mode === 'create' ? 'create' : 'update');
        if (mode === 'edit') {
            fd.append('id', document.getElementById('usuario_id').value);
        }

        const btn = document.getElementById('btnSalvarUsuario');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await resp.json();

            if (data.success) {
                showToast(data.message || 'Usuário salvo.', 'success');
                setTimeout(() => window.location.reload(), 800);
            } else {
                showUsersAlert(data.message || 'Falha ao salvar.', 'danger');
            }
        } catch (err) {
            showUsersAlert(err.message || 'Erro inesperado.', 'danger');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar usuário';
        }
    }

    function confirmDelete(id) {
        if (!id) {
            showUsersAlert('Usu?rio inv?lido.', 'danger');
            return;
        }

        const swalTheme = getSwalThemeUsers();
        Swal.fire({
            title: 'Excluir usu?rio?',
            text: 'Esta a??o n?o poder? ser desfeita.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sim, excluir',
            cancelButtonText: 'Cancelar',
            background: swalTheme.background,
            color: swalTheme.color,
            iconColor: swalTheme.iconColor,
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }
            deleteUser(id);
        });
    }

    function deleteUser(id) {
        if (!id) {
            showUsersAlert('Usuário inválido.', 'danger');
            return;
        }

        fetch(`${controllerUrl}?action=delete`, {
            method: 'POST',
            body: new URLSearchParams({ id, csrf_token: csrfToken }),
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    showUsersAlert(res.message || 'Erro ao excluir usuário.', 'danger');
                }
            })
            .catch(() => showUsersAlert('Erro ao conectar com o servidor.', 'danger'));
    }
</script>
