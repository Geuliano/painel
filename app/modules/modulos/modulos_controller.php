<?php
ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';
require_once __DIR__ . '/ordem_helper.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Sessão expirada.');
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;

function jsonResponse(bool $success, $messageOrData = null, array $extra = []): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    $payload = ['success' => $success];

    if (is_array($messageOrData)) {
        $payload = array_merge($payload, $messageOrData);
    } elseif ($messageOrData !== null) {
        $payload['message'] = $messageOrData;
    }

    if ($extra) {
        $payload = array_merge($payload, $extra);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!isset($pdo)) {
    jsonResponse(false, 'Conexão com o banco indisponível.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$allowed = ['get_module', 'save_module', 'get_permissions', 'save_permissions', 'delete_module', 'reorder_module'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Ação inválida.');
}

$requiresCSRF = ['save_module', 'save_permissions', 'delete_module', 'reorder_module'];
if (!function_exists('safeHashEquals')) {
    function safeHashEquals($known, $user)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $user);
        }
        if (!is_string($known) || !is_string($user)) {
            return false;
        }
        if (strlen($known) !== strlen($user)) {
            return false;
        }
        $res = 0;
        $len = strlen($known);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $res === 0;
    }
}

if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !safeHashEquals($_SESSION['csrf_token'], $token)) {
        jsonResponse(false, 'Falha de segurança. Recarregue a página.');
    }
}

function ensurePermission($tipo)
{
    global $usuarioId, $pdo;

    if (userHasPermission($usuarioId, 'modulos', $tipo)) {
        return;
    }

    // Se o módulo ainda não estiver registrado, libera acesso para configuração inicial
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM modulos WHERE slug = ?");
    $stmt->execute(['modulos']);
    if (!$stmt->fetchColumn()) {
        return;
    }

    jsonResponse(false, 'Você não tem permissão para executar esta ação.');
}

function sanitizeSlug(string $slug): string
{
    return normalizeModuleSlug($slug);
}

function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    static $cache = [];
    $key = strtolower($tabela . '|' . $coluna);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$tabela, $coluna]);
    $cache[$key] = $stmt->fetchColumn() > 0;
    return $cache[$key];
}

if ($action === 'get_module') {
    ensurePermission('pode_visualizar');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID inválido.');
    }

    $stmt = $pdo->prepare("
        SELECT id, nome, slug, descricao, icone, ordem, id_pai, ativo, ver_menu
        FROM modulos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $modulo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$modulo) {
        jsonResponse(false, 'Módulo não encontrado.');
    }

    $modulo['ativo'] = (int)$modulo['ativo'] === 1;
    $modulo['ver_menu'] = (int)$modulo['ver_menu'] === 1;
    $modulo['id_pai'] = $modulo['id_pai'] ? (int)$modulo['id_pai'] : null;

    jsonResponse(true, ['modulo' => $modulo]);
}

if ($action === 'save_module') {
    $id = filter_input(INPUT_POST, 'modulo_id', FILTER_VALIDATE_INT);
    $isUpdate = (bool)$id;

    ensurePermission($isUpdate ? 'pode_editar' : 'pode_criar');

    $nome = trim($_POST['nome'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $icone = trim($_POST['icone'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);
    $ativo = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;
    $verMenu = isset($_POST['ver_menu']) ? (int)$_POST['ver_menu'] : 1;
    $idPai = $_POST['id_pai'] ?? '';

    if ($nome === '') {
        jsonResponse(false, 'Informe o nome do módulo.');
    }

    $slug = $slug !== '' ? $slug : $nome;
    $slug = sanitizeSlug($slug);
    if ($slug === '') {
        jsonResponse(false, 'Slug inválido.');
    }

    $idPai = $idPai !== '' ? (int)$idPai : null;
    if ($id && $idPai === $id) {
        jsonResponse(false, 'O módulo não pode ser pai de si mesmo.');
    }

    if ($idPai) {
        $stmtPai = $pdo->prepare("SELECT COUNT(*) FROM modulos WHERE id = ?");
        $stmtPai->execute([$idPai]);
        if (!$stmtPai->fetchColumn()) {
            jsonResponse(false, 'Módulo pai não encontrado.');
        }
    } else {
        $idPai = null;
    }

    $stmtSlug = $pdo->prepare("SELECT id FROM modulos WHERE slug = ? AND id <> ? LIMIT 1");
    $stmtSlug->execute([$slug, $id ?: 0]);
    if ($stmtSlug->fetch()) {
        jsonResponse(false, 'Já existe um módulo com este slug.');
    }

    if ($isUpdate) {
        try {
        $stmt = $pdo->prepare("
            UPDATE modulos
            SET nome = ?, slug = ?, descricao = ?, icone = ?, ordem = ?, id_pai = ?, ativo = ?, ver_menu = ?
            WHERE id = ?
        ");
        $stmt->execute([$nome, $slug, $descricao, $icone, $ordem, $idPai, $ativo ? 1 : 0, $verMenu ? 1 : 0, $id]);
        } catch (Throwable $e) {
            jsonResponse(false, 'Não foi possível atualizar o módulo.');
        }
    } else {
        $colunas = "nome, slug, descricao, icone, ordem, id_pai, ativo, ver_menu";
        $placeholders = "?, ?, ?, ?, ?, ?, ?, ?";
        $params = [$nome, $slug, $descricao, $icone, $ordem, $idPai, $ativo ? 1 : 0, $verMenu ? 1 : 0];

        if (colunaExiste($pdo, 'modulos', 'criado_em')) {
            $colunas .= ", criado_em";
            $placeholders .= ", NOW()";
        }

        $sql = "INSERT INTO modulos ($colunas) VALUES ($placeholders)";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $id = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            jsonResponse(false, 'Não foi possível criar o módulo.');
        }
    }

    organizarOrdemModulos($pdo);

    jsonResponse(true, $isUpdate ? 'Módulo atualizado com sucesso.' : 'Módulo criado com sucesso.', ['id' => $id]);
}

if ($action === 'delete_module') {
    ensurePermission('pode_excluir');

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'Modulo invalido.');
    }

    $stmtChild = $pdo->prepare("SELECT COUNT(*) FROM modulos WHERE id_pai = ?");
    $stmtChild->execute([$id]);
    if ($stmtChild->fetchColumn() > 0) {
        jsonResponse(false, 'Remova ou reatribua os modulos filhos antes de excluir este modulo.');
    }

    try {
        $pdo->beginTransaction();

        $stmtPerm = $pdo->prepare("DELETE FROM permissoes WHERE id_modulo = ?");
        $stmtPerm->execute([$id]);

        $stmtModulo = $pdo->prepare("DELETE FROM modulos WHERE id = ? LIMIT 1");
        $stmtModulo->execute([$id]);

        if ($stmtModulo->rowCount() === 0) {
            $pdo->rollBack();
            jsonResponse(false, 'Modulo nao encontrado ou ja removido.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Nao foi possivel excluir o modulo.');
    }

    organizarOrdemModulos($pdo);

    jsonResponse(true, 'Modulo excluido com sucesso.');
}
if ($action === 'reorder_module') {
    ensurePermission('pode_editar');

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $direction = $_POST['direction'] ?? '';
    if (!$id || !in_array($direction, ['up', 'down'], true)) {
        jsonResponse(false, 'Parametros invalidos.');
    }

    $stmtModulo = $pdo->prepare("SELECT id, id_pai, ordem FROM modulos WHERE id = ? LIMIT 1");
    $stmtModulo->execute([$id]);
    $modulo = $stmtModulo->fetch(PDO::FETCH_ASSOC);
    if (!$modulo) {
        jsonResponse(false, 'Modulo nao encontrado.');
    }

    $parentId = $modulo['id_pai'];
    $parentId = ($parentId === null || $parentId === '' || (int)$parentId === 0) ? null : (int)$parentId;
    $ordemAtual = (int)($modulo['ordem'] ?? 0);

    $parentClause = 'id_pai IS NULL';
    $params = [
        ':id' => $id,
        ':ordem' => $ordemAtual,
    ];
    if ($parentId !== null) {
        $parentClause = 'id_pai = :parentId';
        $params[':parentId'] = $parentId;
    }

    $comparison = $direction === 'up' ? '<' : '>';
    $ordenacao = $direction === 'up' ? 'DESC' : 'ASC';

    $sqlVizinho = "
        SELECT id, ordem
        FROM modulos
        WHERE {$parentClause}
          AND id <> :id
          AND ordem {$comparison} :ordem
        ORDER BY ordem {$ordenacao}, id {$ordenacao}
        LIMIT 1
    ";

    $stmtVizinho = $pdo->prepare($sqlVizinho);
    $stmtVizinho->execute($params);
    $vizinho = $stmtVizinho->fetch(PDO::FETCH_ASSOC);

    if (!$vizinho) {
        $limite = $direction === 'up' ? 'superior' : 'inferior';
        jsonResponse(false, 'O modulo ja esta no limite ' . $limite . '.');
    }

    try {
        $pdo->beginTransaction();
        $stmtUpdate = $pdo->prepare("UPDATE modulos SET ordem = ? WHERE id = ?");
        $stmtUpdate->execute([(int)$vizinho['ordem'], $id]);
        $stmtUpdate->execute([$ordemAtual, (int)$vizinho['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Nao foi possivel atualizar a ordem do modulo.');
    }

    organizarOrdemModulos($pdo);

    jsonResponse(true, 'Ordem do modulo atualizada.');
}
if ($action === 'get_permissions') {
    ensurePermission('pode_visualizar');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID inválido.');
    }

    $stmtMod = $pdo->prepare("SELECT COUNT(*) FROM modulos WHERE id = ?");
    $stmtMod->execute([$id]);
    if (!$stmtMod->fetchColumn()) {
        jsonResponse(false, 'Módulo não encontrado.');
    }

    $stmt = $pdo->prepare("
        SELECT
            n.id,
            n.nome,
            COALESCE(p.pode_visualizar, 0) AS pode_visualizar,
            COALESCE(p.pode_criar, 0) AS pode_criar,
            COALESCE(p.pode_editar, 0) AS pode_editar,
            COALESCE(p.pode_excluir, 0) AS pode_excluir
        FROM niveis_acesso n
        LEFT JOIN permissoes p 
            ON p.id_nivel = n.id
           AND p.id_modulo = ?
        ORDER BY n.nome ASC
    ");
    $stmt->execute([$id]);
    $niveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, ['niveis' => $niveis]);
}

if ($action === 'save_permissions') {
    ensurePermission('pode_editar');

    $id = filter_input(INPUT_POST, 'modulo_id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'Módulo inválido.');
    }

    $stmtMod = $pdo->prepare("SELECT COUNT(*) FROM modulos WHERE id = ?");
    $stmtMod->execute([$id]);
    if (!$stmtMod->fetchColumn()) {
        jsonResponse(false, 'Módulo não encontrado.');
    }

    $payload = json_decode($_POST['permissions'] ?? '[]', true);
    if (!is_array($payload)) {
        jsonResponse(false, 'Formato de permissões inválido.');
    }

    try {
        $pdo->beginTransaction();

        $stmtDelete = $pdo->prepare("DELETE FROM permissoes WHERE id_modulo = ?");
        $stmtDelete->execute([$id]);

        $stmtInsert = $pdo->prepare("
            INSERT INTO permissoes (id_modulo, id_nivel, pode_visualizar, pode_criar, pode_editar, pode_excluir)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($payload as $row) {
            $nivelId = (int)($row['nivel_id'] ?? 0);
            if ($nivelId <= 0) {
                continue;
            }

            $flags = [
                !empty($row['pode_visualizar']) ? 1 : 0,
                !empty($row['pode_criar']) ? 1 : 0,
                !empty($row['pode_editar']) ? 1 : 0,
                !empty($row['pode_excluir']) ? 1 : 0,
            ];

            if (array_sum($flags) === 0) {
                continue; // ignora níveis sem nenhuma permissão
            }

            $stmtInsert->execute(array_merge([$id, $nivelId], $flags));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Não foi possível salvar as permissões.');
    }

    jsonResponse(true, 'Permissões atualizadas com sucesso.');
}

