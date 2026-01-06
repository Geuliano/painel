<?php
ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';

requireLogin();

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

    if (!empty($extra)) {
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

$allowed = ['get_level','save_level','delete_level','get_level_permissions','save_level_permissions'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Ação inválida.');
}

$requiresCSRF = ['save_level','delete_level','save_level_permissions'];
if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(false, 'Falha de segurança. Recarregue a página.');
    }
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;

function ensurePerm($tipo)
{
    global $usuarioId;
    if (!userHasPermission($usuarioId, 'permissions', $tipo)) {
        jsonResponse(false, 'Você não tem permissão para executar esta ação.');
    }
}

if ($action === 'get_level') {
    ensurePerm('pode_visualizar');
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID inválido.');
    }

    $stmt = $pdo->prepare('SELECT id, nome, descricao FROM niveis_acesso WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $nivel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nivel) {
        jsonResponse(false, 'Nível não encontrado.');
    }

    jsonResponse(true, ['nivel' => $nivel]);
}

if ($action === 'save_level') {
    $id = filter_input(INPUT_POST, 'nivel_id', FILTER_VALIDATE_INT) ?: null;
    ensurePerm($id ? 'pode_editar' : 'pode_criar');

    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    if ($nome === '') {
        jsonResponse(false, 'Informe o nome do nível.');
    }

    $stmt = $pdo->prepare('SELECT id FROM niveis_acesso WHERE nome = ? AND id <> ? LIMIT 1');
    $stmt->execute([$nome, $id ?? 0]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Já existe um nível com este nome.');
    }

    if ($id) {
        $stmt = $pdo->prepare('UPDATE niveis_acesso SET nome = ?, descricao = ? WHERE id = ?');
        $stmt->execute([$nome, $descricao, $id]);
        jsonResponse(true, 'Nível atualizado com sucesso.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO niveis_acesso (nome, descricao) VALUES (?, ?)');
        $stmt->execute([$nome, $descricao]);
        jsonResponse(true, 'Nível criado com sucesso.');
    }
}

if ($action === 'delete_level') {
    ensurePerm('pode_excluir');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID inválido.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuario_nivel WHERE id_nivel = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        jsonResponse(false, 'Não é possível excluir níveis com usuários vinculados.');
    }

    $pdo->prepare('DELETE FROM niveis_acesso WHERE id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM permissoes WHERE id_nivel = ?')->execute([$id]);

    jsonResponse(true, 'Nível excluído com sucesso.');
}

if ($action === 'get_level_permissions') {
    ensurePerm('pode_visualizar');
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'Nível inválido.');
    }

    $stmt = $pdo->prepare('
        SELECT m.id, m.nome,
               COALESCE(p.pode_visualizar, 0) AS pode_visualizar,
               COALESCE(p.pode_criar, 0) AS pode_criar,
               COALESCE(p.pode_editar, 0) AS pode_editar,
               COALESCE(p.pode_excluir, 0) AS pode_excluir
        FROM modulos m
        LEFT JOIN permissoes p ON p.id_modulo = m.id AND p.id_nivel = ?
        WHERE m.ativo = 1
        ORDER BY m.nome ASC
    ');
    $stmt->execute([$id]);
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, ['modulos' => $modulos]);
}

if ($action === 'save_level_permissions') {
    ensurePerm('pode_editar');
    $nivelId = filter_input(INPUT_POST, 'nivel_id', FILTER_VALIDATE_INT);
    if (!$nivelId) {
        jsonResponse(false, 'Nível inválido.');
    }

    $payload = json_decode($_POST['permissions'] ?? '[]', true);
    if (!is_array($payload)) {
        jsonResponse(false, 'Formato de permissões inválido.');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM permissoes WHERE id_nivel = ?')->execute([$nivelId]);

        $stmtModulo = $pdo->prepare('SELECT COUNT(*) FROM modulos WHERE id = ?');
        $stmtInsert = $pdo->prepare('
            INSERT INTO permissoes (id_modulo, id_nivel, pode_visualizar, pode_criar, pode_editar, pode_excluir)
            VALUES (?, ?, ?, ?, ?, ?)
        ');

        foreach ($payload as $linha) {
            $moduloId = (int)($linha['modulo_id'] ?? 0);
            if ($moduloId <= 0) {
                continue;
            }

            $stmtModulo->execute([$moduloId]);
            if (!$stmtModulo->fetchColumn()) {
                continue;
            }

            $flags = [
                !empty($linha['pode_visualizar']) ? 1 : 0,
                !empty($linha['pode_criar']) ? 1 : 0,
                !empty($linha['pode_editar']) ? 1 : 0,
                !empty($linha['pode_excluir']) ? 1 : 0,
            ];

            if (array_sum($flags) === 0) {
                continue;
            }

            $stmtInsert->execute(array_merge([$moduloId, $nivelId], $flags));
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
