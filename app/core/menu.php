<?php
requireLogin();
require_once CORE_PATH . '/permissions.php';

$userId = $_SESSION['usuario_id'] ?? 0;
$moduloAtualId = resolveModuleId($_GET['mod'] ?? null);
if (!$moduloAtualId) {
    $moduloAtualId = getModuleIdBySlug('dashboard');
}

$stmt = $pdo->prepare("
    SELECT m.id, m.nome, m.slug, m.descricao, m.icone
    FROM modulos m
    INNER JOIN permissoes p ON p.id_modulo = m.id
    INNER JOIN niveis_acesso n ON n.id = p.id_nivel
    INNER JOIN usuario_nivel un ON un.id_nivel = n.id
    WHERE un.id_usuario = ? 
      AND p.pode_visualizar = 1
      AND m.id_pai IS NULL
      AND m.ativo = 1
      AND COALESCE(m.ver_menu, 1) = 1
    ORDER BY m.ordem ASC
");

$stmt->execute([$userId]);
$modulosPrincipais = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<ul class="nav flex-column">';

foreach ($modulosPrincipais as $modulo) {
    $stmtSub = $pdo->prepare("
        SELECT m.id, m.nome, m.slug, m.icone
        FROM modulos m
        INNER JOIN permissoes p ON p.id_modulo = m.id
        INNER JOIN niveis_acesso n ON n.id = p.id_nivel
        INNER JOIN usuario_nivel un ON un.id_nivel = n.id
        WHERE un.id_usuario = ?
          AND p.pode_visualizar = 1
          AND m.id_pai = ?
          AND m.ativo = 1
          AND COALESCE(m.ver_menu, 1) = 1
        ORDER BY m.ordem ASC
    ");

    $stmtSub->execute([$userId, $modulo['id']]);
    $subModulos = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    $temFilhos = count($subModulos) > 0;
    $slugModulo = $modulo['slug'];
    $moduloAtivo = ((int)$modulo['id'] === (int)$moduloAtualId);
    $filhoAtivo = false;

    if ($temFilhos) {
        foreach ($subModulos as $sub) {
            if ((int)$sub['id'] === (int)$moduloAtualId) {
                $filhoAtivo = true;
                break;
            }
        }
    }

    $collapseId = 'sidebar' . preg_replace('/[^a-z0-9_\-]/i', '-', $slugModulo);
    $collapseId = trim(preg_replace('/-+/', '-', $collapseId), '-');

    if ($temFilhos) {
        $isOpen = $moduloAtivo || $filhoAtivo;
        $liClasses = 'nav-item';
        $linkClasses = 'nav-link d-flex align-items-center';

        $collapseClasses = 'collapse';
        if ($isOpen) {
            $collapseClasses .= ' show';
        }

        $ariaExpanded = $isOpen ? 'true' : 'false';

        echo '
        <li class="' . $liClasses . '">
            <a class="' . $linkClasses . '" href="#' . htmlspecialchars($collapseId) . '" 
               data-bs-toggle="collapse" role="button" aria-expanded="' . $ariaExpanded . '" 
               aria-controls="' . htmlspecialchars($collapseId) . '">
                <i class="' . htmlspecialchars($modulo['icone'] ?: 'heart-solid') . ' menu-icon"></i>
                <span>' . htmlspecialchars($modulo['nome']) . '</span>
            </a>
            <div class="' . $collapseClasses . '" id="' . htmlspecialchars($collapseId) . '">
                <ul class="nav flex-column">';

        foreach ($subModulos as $sub) {
            $subAtivo = ((int)$sub['id'] === (int)$moduloAtualId);
            $subLiClasses = 'nav-item' . ($subAtivo ? ' active' : '');
            $subLinkClasses = 'nav-link' . ($subAtivo ? ' active' : '');
            echo '
                    <li class="' . $subLiClasses . '">
                        <a class="' . $subLinkClasses . '" href="' . BASE_URL . '?mod=' . (int)$sub['id'] . '">
                            ' . htmlspecialchars($sub['nome']) . '
                        </a>
                    </li>';
        }

        echo '
                </ul>
            </div>
        </li>';
    } else {
        $liClasses = 'nav-item' . ($moduloAtivo ? ' active' : '');
        $linkClasses = 'nav-link d-flex align-items-center' . ($moduloAtivo ? ' active' : '');

        echo '
        <li class="' . $liClasses . '">
            <a class="' . $linkClasses . '" href="' . BASE_URL . '?mod=' . (int)$modulo['id'] . '">
                <i class="' . htmlspecialchars($modulo['icone'] ?: 'heart-solid') . ' menu-icon"></i>
                <span>' . htmlspecialchars($modulo['nome']) . '</span>
            </a>
        </li>';
    }
}

echo '</ul>';
