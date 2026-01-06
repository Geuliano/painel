<?php

require_once dirname(__DIR__) . '/core/config.php';

/**
 * Normaliza o slug/caminho do m��dulo permitindo subdiret��rios.
 * Ex: "Modules/Produtos/Sub-Modulo" => "modules/produtos/sub-modulo".
 */
function normalizeModuleSlug($slug): string
{
    if (!is_string($slug)) {
        return '';
    }

    $slug = trim($slug);
    if ($slug === '') {
        return '';
    }

    $slug = str_replace('\\', '/', $slug);
    $slug = preg_replace('#/+#', '/', $slug);
    $parts = array_filter(array_map('trim', explode('/', $slug)), 'strlen');

    $cleanParts = [];
    foreach ($parts as $part) {
        $part = strtolower($part);
        if (function_exists('iconv')) {
            $part = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $part);
        }
        $part = preg_replace('/[^a-z0-9_\-]+/', '-', $part);
        $part = trim($part, '-');
        if ($part !== '') {
            $cleanParts[] = $part;
        }
    }

    $cleanSlug = implode('/', $cleanParts);
    if (strlen($cleanSlug) > 180) {
        $cleanSlug = substr($cleanSlug, 0, 180);
    }

    return $cleanSlug;
}

/**
 * Retorna os dados do m��dulo pelo ID (com cache simples).
 */
function getModuleById(int $id): ?array
{
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT id, nome, slug, descricao, icone, ordem, id_pai, ativo, ver_menu
        FROM modulos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cache[$id] = null;
        return null;
    }

    $row['id'] = (int)$row['id'];
    $row['id_pai'] = $row['id_pai'] !== null ? (int)$row['id_pai'] : null;
    $row['ordem'] = (int)($row['ordem'] ?? 0);
    $row['ativo'] = (int)($row['ativo'] ?? 0);
    $row['ver_menu'] = (int)($row['ver_menu'] ?? 0);
    $row['slug'] = normalizeModuleSlug((string)$row['slug']);

    $cache[$id] = $row;
    return $row;
}

/**
 * Recupera o ID do m��dulo a partir do slug/caminho.
 */
function getModuleIdBySlug(string $slug): ?int
{
    static $cache = [];

    $normalized = normalizeModuleSlug($slug);
    if ($normalized === '') {
        return null;
    }

    if (array_key_exists($normalized, $cache)) {
        return $cache[$normalized];
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM modulos WHERE slug = ? LIMIT 1");
    $stmt->execute([$normalized]);
    $id = $stmt->fetchColumn();

    $cache[$normalized] = $id ? (int)$id : null;
    return $cache[$normalized];
}

/**
 * Resolve o identificador do m��dulo aceitando ID num��rico ou slug/caminho.
 */
function resolveModuleId($moduleIdentifier): ?int
{
    if (is_int($moduleIdentifier)) {
        return getModuleById($moduleIdentifier)['id'] ?? null;
    }

    if (is_string($moduleIdentifier) && ctype_digit($moduleIdentifier)) {
        $id = (int)$moduleIdentifier;
        return getModuleById($id)['id'] ?? null;
    }

    if (!is_string($moduleIdentifier)) {
        return null;
    }

    return getModuleIdBySlug($moduleIdentifier);
}

/**
 * Retorna todos os n��veis de acesso do usu��rio logado
 */
function getUserLevels($userId)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT id_nivel FROM usuario_nivel WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Verifica se o usu��rio tem acesso a um m��dulo espec��fico
 * Pode verificar permiss��es espec��ficas (visualizar, criar, editar, excluir)
 */
function userHasPermission($userId, $moduleIdentifier, $tipo = 'pode_visualizar')
{
    global $pdo;
    $userLevels = getUserLevels($userId);

    if (empty($userLevels)) return false;

    $moduloId = resolveModuleId($moduleIdentifier);
    if (!$moduloId) {
        return false;
    }

    // Monta placeholders para os n��veis do usu��rio
    $placeholders = implode(',', array_fill(0, count($userLevels), '?'));
    $params = array_merge($userLevels, [$moduloId]);

    // Verifica se algum dos n��veis tem permiss��o
    $query = "
        SELECT COUNT(*) FROM permissoes 
        WHERE id_nivel IN ($placeholders)
        AND id_modulo = ?
        AND $tipo = 1
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    return $stmt->fetchColumn() > 0;
}

/**
 * Protege uma p��gina espec��fica do painel
 */
function requirePermission($moduleIdentifier, $tipo = 'pode_visualizar')
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . 'app/pages/login.php');
        exit;
    }

    if (!userHasPermission($_SESSION['usuario_id'], $moduleIdentifier, $tipo)) {
        http_response_code(403);
        echo "<h2 style='color:red;text-align:center;margin-top:40px;'>Acesso negado ao m��dulo.</h2>";
        exit;
    }
}


function checkActionPermission($userId, $moduleIdentifier, $acao)
{
    switch ($acao) {
        case 'editar':
        case 'atualizar':
            return userHasPermission($userId, $moduleIdentifier, 'pode_editar');
        case 'criar':
        case 'salvar':
            return userHasPermission($userId, $moduleIdentifier, 'pode_criar');
        case 'excluir':
            return userHasPermission($userId, $moduleIdentifier, 'pode_excluir');
        default:
            return userHasPermission($userId, $moduleIdentifier, 'pode_visualizar');
    }
}
