<?php
require_once dirname(__DIR__) . '/core/config.php';

require_once APP_PATH . '/core/auth.php';
require_once APP_PATH . '/core/permissions.php';
require_once APP_PATH . '/core/router.php';
require_once APP_PATH . '/modules/notifications/notifications_helper.php';
require_once APP_PATH . '/modules/personalizar/personalizar_helper.php';

requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$moduloParam = $_GET['mod'] ?? null;
$moduloSolicitado = resolveModuleId($moduloParam);
if (!$moduloSolicitado) {
    $moduloSolicitado = getModuleIdBySlug('dashboard');
}
$acaoSolicitada = $_GET['acao'] ?? 'listar';
$acaoSolicitada = preg_replace('/[^a-z0-9_\-]/i', '', (string)$acaoSolicitada);
if ($acaoSolicitada === '') {
    $acaoSolicitada = 'listar';
}

function buildModuleLink(string $slug, array $params = []): string
{
    $moduleId = getModuleIdBySlug($slug);
    $query = array_merge(['mod' => $moduleId ?? $slug], $params);
    return BASE_URL . '?' . http_build_query($query);
}

$brandingConfig = getBrandingConfig($pdo);
$pageTitleBrand = $brandingConfig['titulo_site'] ?? 'Painel';
$faviconPath = $brandingConfig['favicon'] ?? 'public/assets/images/favicon.ico';
$logoLightPath = $brandingConfig['logo_light'] ?? 'public/assets/images/logo-light.png';
$logoDarkPath = $brandingConfig['logo_dark'] ?? 'public/assets/images/logo-dark.png';
$logoSmallPath = $brandingConfig['logo_small'] ?? 'public/assets/images/logo-sm.png';

$temaPreferido = $_SESSION['tema'] ?? 'light';
if (!in_array($temaPreferido, ['light', 'dark'], true)) {
    $temaPreferido = 'light';
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
if ($usuarioId) {
    try {
        $stmt = $pdo->prepare("SELECT tema FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        $temaBanco = $stmt->fetchColumn();
        if (is_string($temaBanco) && in_array($temaBanco, ['light', 'dark'], true)) {
            $temaPreferido = $temaBanco;
            $_SESSION['tema'] = $temaBanco;
        }
    } catch (Throwable $e) {
        // mant�m tema padr�o mesmo se o banco falhar
    }
}

$usuarioAtual = [
    'nome' => $_SESSION['usuario_nome'] ?? 'Usu�rio',
    'email' => '',
    'avatar' => null,
    'nivel' => '',
    'nivel_id' => 0,
];

try {
    $stmtInfo = $pdo->prepare("
        SELECT 
            u.nome,
            u.email,
            u.avatar,
            COALESCE(n.nome, '') AS nivel_nome,
            COALESCE(un.id_nivel, u.nivel, 0) AS nivel_id
        FROM usuarios u
        LEFT JOIN usuario_nivel un ON un.id_usuario = u.id
        LEFT JOIN niveis_acesso n ON n.id = un.id_nivel
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmtInfo->execute([$usuarioId]);
    $rowInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    if ($rowInfo) {
        $usuarioAtual['nome'] = $rowInfo['nome'] ?: $usuarioAtual['nome'];
        $usuarioAtual['email'] = $rowInfo['email'] ?? '';
        $usuarioAtual['avatar'] = $rowInfo['avatar'] ?? null;
        $usuarioAtual['nivel'] = $rowInfo['nivel_nome'] ?? '';
        $usuarioAtual['nivel_id'] = (int) ($rowInfo['nivel_id'] ?? 0);
    }
} catch (Throwable $e) {
    // ignora, usa dados da sess�o
}

$avatarHeader = $usuarioAtual['avatar']
    ? BASE_URL . 'public/uploads/avatars/' . $usuarioAtual['avatar']
    : BASE_URL . 'public/assets/images/users/default.png';

$notificacoesAtivas = [];
ensureNotificationModulesTable($pdo);
$moduloPermitidoParaAvisos = $usuarioId
    ? getAuthorizedModuleSlugForUser($usuarioId, $moduloSolicitado, $acaoSolicitada)
    : null;
try {
    $stmtNotif = $pdo->prepare("
        SELECT 
            n.id,
            n.titulo,
            n.mensagem,
            n.tipo_alerta,
            n.pode_fechar
        FROM notificacoes n
        LEFT JOIN notificacoes_leituras l
            ON l.id_notificacao = n.id
           AND l.id_usuario = ?
        WHERE n.ativo = 1
          AND (n.vigencia_inicio IS NULL OR n.vigencia_inicio <= NOW())
          AND (n.fixa = 1 OR n.vigencia_fim IS NULL OR n.vigencia_fim >= NOW())
          AND (
                n.destino_tipo = 'todos'
             OR (n.destino_tipo = 'nivel' AND n.destino_valor = ?)
             OR (n.destino_tipo = 'usuario' AND FIND_IN_SET(?, n.destino_valor))
          )
          AND (
                NOT EXISTS (
                    SELECT 1
                    FROM notificacoes_modulos nm
                    WHERE nm.id_notificacao = n.id
                )
             OR (
                    ? IS NOT NULL
                AND EXISTS (
                        SELECT 1
                        FROM notificacoes_modulos nm2
                        WHERE nm2.id_notificacao = n.id
                          AND nm2.slug_modulo = ?
                    )
                )
          )
          AND (n.pode_fechar = 0 OR l.id_notificacao IS NULL)
        ORDER BY n.vigencia_inicio IS NULL DESC, n.vigencia_inicio DESC, n.id DESC
    ");
    $stmtNotif->execute([
        $usuarioId,
        $usuarioAtual['nivel_id'],
        $usuarioId,
        $moduloPermitidoParaAvisos,
        $moduloPermitidoParaAvisos
    ]);
    $notificacoesAtivas = $stmtNotif->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $notificacoesAtivas = [];
}
$notificationAlertMeta = [
    'danger' => ['icon' => 'fas fa-skull-crossbones'],
    'warning' => ['icon' => 'fas fa-triangle-exclamation'],
    'success' => ['icon' => 'fas fa-check'],
    'info' => ['icon' => 'fas fa-circle-info'],
    'primary' => ['icon' => 'fas fa-circle-info'],
    'secondary' => ['icon' => 'fas fa-circle-info']
];
?>
<!DOCTYPE html>
<html lang="pt_BR" dir="ltr" data-startbar="<?= htmlspecialchars($temaPreferido) ?>"
    data-bs-theme="<?= htmlspecialchars($temaPreferido) ?>">

<head>


    <meta charset="utf-8" />
    <title><?= htmlspecialchars($pageTitleBrand) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />

    <!-- App favicon -->
    <link rel="shortcut icon" href="<?= BASE_URL . $faviconPath ?>">

    <!-- App css -->
    <link href="<?= BASE_URL ?>public/assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= BASE_URL ?>public/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= BASE_URL ?>public/assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= BASE_URL ?>public/assets/css/theme-contrast.css" rel="stylesheet" type="text/css" />
    <style>
        .startbar .brand .logo-lg {
            height: 35px !important;
            
        }
                .startbar .brand .logo-sm {
            height: 35px !important;
            
        }
    </style>
    <script>
        window.APP_THEME = {
            current: '<?= htmlspecialchars($temaPreferido, ENT_QUOTES) ?>',
            endpoint: '<?= BASE_URL ?>app/core/theme_preferences.php',
            csrf: '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>'
        };
    </script>





</head>


<!-- Top Bar Start -->

<body>
    <!-- Top Bar Start -->
    <div class="topbar d-print-none">
        <div class="container-fluid">
            <nav class="topbar-custom d-flex justify-content-between" id="topbar-custom">


                <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                    <li>
                        <button class="nav-link mobile-menu-btn nav-icon" id="togglemenu">
                            <i class="iconoir-menu"></i>
                        </button>
                    </li>
                </ul>
                <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">

                    <li class="topbar-item">
                        <a class="nav-link nav-icon" href="javascript:void(0);" id="light-dark-mode">
                            <i class="iconoir-half-moon dark-mode"></i>
                            <i class="iconoir-sun-light light-mode"></i>
                        </a>
                    </li>

                    <li class="dropdown topbar-item">
                        <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#"
                            role="button" aria-haspopup="false" aria-expanded="false" data-bs-offset="0,19">
                            <img src="<?= htmlspecialchars($avatarHeader) ?>" alt="Avatar"
                                class="thumb-md rounded-circle">
                        </a>
                        <div class="dropdown-menu dropdown-menu-end py-0">
                            <div class="d-flex align-items-center dropdown-item py-2 bg-secondary-subtle">
                                <div class="flex-shrink-0">
                                    <img src="<?= htmlspecialchars($avatarHeader) ?>" alt="Avatar"
                                        class="thumb-md rounded-circle">
                                </div>
                                <div class="flex-grow-1 ms-2 text-truncate align-self-center">
                                    <h6 class="my-0 fw-medium text-dark fs-13">
                                        <?= htmlspecialchars($usuarioAtual['nome']) ?></h6>
                                    <small class="text-muted mb-0">
                                        <?= htmlspecialchars($usuarioAtual['nivel'] ?: 'Usu�rio') ?>
                                    </small>
                                </div>
                            </div>
                            <div class="dropdown-divider mt-0"></div>
                            <small class="text-muted px-2 pb-1 d-block">Conta</small>
                            <a class="dropdown-item" href="<?= htmlspecialchars(buildModuleLink('meus-dados')) ?>">
                                <i class="las la-user fs-18 me-1 align-text-bottom"></i> Meus dados
                            </a>
                            <a class="dropdown-item" href="<?= htmlspecialchars(buildModuleLink('meus-dados', ['acao' => 'listar'])) ?>#formSenha">
                                <i class="las la-lock fs-18 me-1 align-text-bottom"></i> Alterar senha
                            </a>
                            <div class="dropdown-divider mb-0"></div>
                            <a class="dropdown-item text-danger" href="<?= htmlspecialchars(buildModuleLink('logout')) ?>">
                                <i class="las la-power-off fs-18 me-1 align-text-bottom"></i> Sair
                            </a>
                        </div>
                    </li>
                </ul><!--end topbar-nav-->
            </nav>
            <!-- end navbar-->
        </div>
    </div>
    <!-- Top Bar End -->
    <!-- leftbar-tab-menu -->
    <div class="startbar d-print-none">
        <!--start brand-->
        <div class="brand">
            <a href="<?= BASE_URL ?>" class="logo">
                <span>
                    <img src="<?= BASE_URL . $logoSmallPath ?>" alt="<?= htmlspecialchars($pageTitleBrand) ?>"
                        class="logo-sm">
                </span>
                <span>
                    <img src="<?= BASE_URL . $logoLightPath ?>" alt="<?= htmlspecialchars($pageTitleBrand) ?>"
                        class="logo-lg logo-light">
                    <img src="<?= BASE_URL . $logoDarkPath ?>" alt="<?= htmlspecialchars($pageTitleBrand) ?>"
                        class="logo-lg logo-dark">
                </span>
            </a>
        </div>
        <!--end brand-->
        <!--start startbar-menu-->
        <div class="startbar-menu">
            <div class="startbar-collapse" id="startbarCollapse" data-simplebar>
                <div class="d-flex align-items-start flex-column w-100">
                    <!-- Navigation -->
                    <ul class="navbar-nav mb-auto w-100">
                        <li class="menu-label mt-2">
                            <span>NAVEGAÇÃO</span>
                        </li>

                        <?php require_once __DIR__ . '/../core/menu.php'; ?>
                    </ul><!--end navbar-nav--->
                </div>
            </div><!--end startbar-collapse-->
        </div><!--end startbar-menu-->
    </div><!--end startbar-->
    <div class="startbar-overlay d-print-none"></div>
    <!-- end leftbar-tab-menu-->


    <div class="page-wrapper">

        <!-- Page Content-->
        <div class="page-content">
            <div class="container-fluid">
                <?php if (!empty($notificacoesAtivas)): ?>
                    <div class="mb-3 mt-3">
                        <?php foreach ($notificacoesAtivas as $notificacao): ?>
                            <?php
                            $tipoAlerta = $notificacao['tipo_alerta'] ?? 'info';
                            $meta = $notificationAlertMeta[$tipoAlerta] ?? $notificationAlertMeta['info'];
                            ?>
                            <div class="alert alert-<?= htmlspecialchars($tipoAlerta) ?> <?= (int) $notificacao['pode_fechar'] === 1 ? 'alert-dismissible' : '' ?> fade show shadow-sm border-start border-2 border-<?= htmlspecialchars($tipoAlerta) ?> mb-2"
                                role="alert" data-notification-container="<?= (int) $notificacao['id'] ?>">
                                <div class="d-flex align-items-center gap-2 w-100">
                                    <i
                                        class="<?= htmlspecialchars($meta['icon']) ?> align-self-center fs-30 text-<?= htmlspecialchars($tipoAlerta) ?>"></i>
                                    <div class="flex-grow-1 ms-2 text-truncate">
                                        <h5 class="mb-1 fw-bold mt-0 text-<?= htmlspecialchars($tipoAlerta) ?>">
                                            <?= htmlspecialchars($notificacao['titulo']) ?></h5>
                                        <div class="mb-0"><?= $notificacao['mensagem'] ?></div>
                                        <?php if ((int) $notificacao['pode_fechar'] === 1): ?>
                                            <button type="button" class="btn-close mt-2" aria-label="Fechar" data-close-notification
                                                data-notification-id="<?= (int) $notificacao['id'] ?>"></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php
                echo runRouter();
                ?>
            </div>
            <!--end footer-->
        </div>
        <!-- end page content -->
    </div>
    <!-- end page-wrapper -->

    <!-- Javascript  -->
    <!-- vendor js -->

    <script src="<?= BASE_URL ?>public/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>public/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= BASE_URL ?>public/assets/libs/clipboard/clipboard.min.js"></script>
    <script src="<?= BASE_URL ?>public/assets/js/pages/clipboard.init.js"></script>
    <script src="<?= BASE_URL ?>public/assets/js/app.js"></script>
    <script>
        (function () {
            const controllerUrl = '<?= BASE_URL ?>app/modules/notifications/notifications_controller.php';
            const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';

            function parseJson(text) {
                if (!text) {
                    throw new Error('Resposta vazia do servidor.');
                }
                try {
                    return JSON.parse(text);
                } catch (err) {
                    throw new Error(text);
                }
            }

            document.addEventListener('click', function (event) {
                const btn = event.target.closest('[data-close-notification]');
                if (!btn) {
                    return;
                }
                const id = btn.getAttribute('data-notification-id');
                if (!id) {
                    return;
                }
                event.preventDefault();
                const container = document.querySelector(`[data-notification-container="${id}"]`);

                const fd = new FormData();
                fd.append('action', 'close_notification');
                fd.append('id', id);
                fd.append('csrf_token', csrfToken);

                fetch(controllerUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                    .then(resp => resp.text())
                    .then(parseJson)
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'N�o foi poss�vel atualizar a notifica��o.');
                        }
                        if (container) {
                            container.classList.remove('show');
                            setTimeout(() => container.remove(), 200);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        if (container) {
                            container.classList.add('shake');
                            setTimeout(() => container.classList.remove('shake'), 600);
                        }
                    });
            });
        })();
    </script>

</body>




</html>
