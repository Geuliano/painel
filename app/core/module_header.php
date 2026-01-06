<?php
requireLogin();

require_once CORE_PATH . '/config.php';
require_once CORE_PATH . '/permissions.php';

$moduloParam = $_GET['mod'] ?? ($GLOBALS['moduloIdAtual'] ?? null);
$moduloId = resolveModuleId($moduloParam);
if (!$moduloId) {
    $moduloId = getModuleIdBySlug('dashboard');
}

$acaoInput  = $_GET['acao'] ?? 'listar';
$acaoInput = preg_replace('/[^a-z0-9_\-]/i', '', (string)$acaoInput);

$acoesPermitidas = [
    'listar', 'adicionar', 'editar', 'excluir', 'detalhes', 'salvar'
];

$acao = in_array($acaoInput, $acoesPermitidas, true) ? $acaoInput : 'listar';

$moduloInfo = null;
$parentInfo = null;

try {
    $moduloInfo = $moduloId ? getModuleById((int)$moduloId) : null;
} catch (Throwable $e) {
    $moduloInfo = null;
}

if (!empty($moduloInfo['id_pai'])) {
    $pai = getModuleById((int)$moduloInfo['id_pai']);
    if ($pai) {
        $parentInfo = [
            'id' => $pai['id'],
            'nome' => $pai['nome'],
            'slug' => $pai['slug'] ?? '',
        ];
    }
}

$tituloModulo = $moduloInfo['nome'] ?? 'M��dulo';
$descModulo   = $moduloInfo['descricao'] ?? ($moduloInfo['slug'] ?? '');

$acoesLegiveis = [
    'listar'    => 'Listar',
    'adicionar' => 'Adicionar',
    'editar'    => 'Editar',
    'excluir'   => 'Excluir',
    'detalhes'  => 'Detalhes',
    'salvar'    => 'Salvar',
];

$tituloAcao = $acoesLegiveis[$acao] ?? ucfirst($acao);
?>

<!-- Cabe�alho do m�dulo -->
<div class="row">
    <div class="col-sm-12">
        <div class="page-title-box d-md-flex justify-content-md-between align-items-center">
            <h4 class="page-title">
                <?= htmlspecialchars($tituloModulo) ?>
                <span class="text-muted fs-14">/ <?= htmlspecialchars($descModulo) ?></span>
            </h4>

            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="<?= BASE_URL ?>">Painel</a>
                    </li>

                    <?php if (!empty($parentInfo)): ?>
                        <li class="breadcrumb-item">
                            <a href="<?= BASE_URL ?>?mod=<?= (int)($parentInfo['id'] ?? 0) ?>">
                                <?= htmlspecialchars($parentInfo['nome'] ?? 'M�dulo') ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="breadcrumb-item">
                        <a href="#">
                            <?= htmlspecialchars($tituloModulo) ?>
                        </a>
                    </li>

                </ol>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert watcher to convert Bootstrap alerts into themed toasts -->
<script src="<?= BASE_URL ?>public/assets/libs/sweetalert2/sweetalert2.all.min.js"></script>
<script>
(function () {
    if (window.__swalAlertWatcher || !window.Swal) {
        window.__swalAlertWatcher = true;
        return;
    }
    window.__swalAlertWatcher = true;

    function getSwalTheme() {
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

    function alertToToast(alertEl) {
        if (!alertEl || !alertEl.classList.contains('alert')) {
            return;
        }
        const classes = Array.from(alertEl.classList);
        const typeClass = classes.find(c => c.startsWith('alert-') && c !== 'alert' && c !== 'alert-dismissible');
        const type = typeClass ? typeClass.replace('alert-', '') : 'info';
        const icon = type === 'success'
            ? 'success'
            : (type === 'warning'
                ? 'warning'
                : (type === 'info' ? 'info' : 'error'));
        const text = (alertEl.textContent || '').trim() || 'Notificação';
        const theme = getSwalTheme();

        toast.fire({
            icon,
            title: text,
            background: theme.background,
            color: theme.color,
            iconColor: theme.iconColor
        });
        alertEl.classList.add('d-none');
    }

    function watchAlert(el) {
        if (!el || !el.classList.contains('alert')) {
            return;
        }
        el.dataset.swalWatch = '1';
        if (!el.classList.contains('d-none')) {
            alertToToast(el);
        }
    }

    document.querySelectorAll('.alert.d-none').forEach(watchAlert);

    const observer = new MutationObserver((records) => {
        records.forEach((record) => {
            if (record.type === 'attributes' && record.attributeName === 'class') {
                const el = record.target;
                if (el.dataset.swalWatch === '1' && !el.classList.contains('d-none')) {
                    alertToToast(el);
                }
            }
            record.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                if (node.classList.contains('alert') && node.classList.contains('d-none')) {
                    watchAlert(node);
                } else if (node.dataset && node.dataset.swalWatch === '1' && !node.classList.contains('d-none')) {
                    alertToToast(node);
                }
                node.querySelectorAll?.('.alert.d-none').forEach(watchAlert);
            });
        });
    });

    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class'],
        attributeOldValue: true,
        subtree: true,
        childList: true
    });
})();
</script>
