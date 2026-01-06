<?php
ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';
require_once __DIR__ . '/notifications_helper.php';

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

ensureNotificationModulesTable($pdo);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$allowed = [
    'get_notification',
    'save_notification',
    'delete_notification',
    'close_notification'
];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Ação inválida.');
}

$requiresCSRF = ['save_notification', 'delete_notification', 'close_notification'];
if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(false, 'Falha de segurança. Recarregue a página.');
    }
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;

function ensureNotificationPermission($tipo): void
{
    global $usuarioId;
    if (!userHasPermission($usuarioId, 'notifications', $tipo)) {
        jsonResponse(false, 'Você não tem permissão para executar esta ação.');
    }
}

function normalizeDate(?string $input): ?string
{
    if (empty($input)) {
        return null;
    }
    $timestamp = strtotime($input);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function sanitizeNotificationMessage($input): string
{
    if (!is_string($input)) {
        return '';
    }

    $decoded = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = strip_tags($decoded);

    $stripped = preg_replace('/[ \t]+/', ' ', $stripped);
    $stripped = preg_replace("/\r\n|\r/", "\n", $stripped);
    $stripped = preg_replace("/\n{3,}/", "\n\n", $stripped);

    return trim($stripped);
}

if ($action === 'get_notification') {
    ensureNotificationPermission('pode_visualizar');
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'Notificação inválida.');
    }

    $stmt = $pdo->prepare("
        SELECT id, titulo, mensagem, tipo_alerta, destino_tipo, destino_valor,
               vigencia_inicio, vigencia_fim, fixa, pode_fechar, ativo
        FROM notificacoes
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $notificacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$notificacao) {
        jsonResponse(false, 'Notificação não encontrada.');
    }

    $notificacao['modulos'] = fetchNotificationModulesForId($pdo, (int)$notificacao['id']);

    jsonResponse(true, ['notificacao' => $notificacao]);
}

if ($action === 'save_notification') {
    $id = filter_input(INPUT_POST, 'notificacao_id', FILTER_VALIDATE_INT) ?: null;
    ensureNotificationPermission($id ? 'pode_editar' : 'pode_criar');

    $titulo = trim($_POST['titulo'] ?? '');
    $mensagem = sanitizeNotificationMessage($_POST['mensagem'] ?? '');
    $tipoAlerta = $_POST['tipo_alerta'] ?? 'primary';
    $destinoTipo = $_POST['destino_tipo'] ?? 'todos';
    $destinoNiveis = $_POST['destino_nivel'] ?? [];
    if (!is_array($destinoNiveis)) {
        $destinoNiveis = [$destinoNiveis];
    }
    $destinoNiveis = array_values(array_unique(array_filter(array_map('intval', $destinoNiveis), function ($v) {
        return $v > 0;
    })));
    $destinoUsuarios = $_POST['destino_usuario'] ?? [];
    if (!is_array($destinoUsuarios)) {
        $destinoUsuarios = [$destinoUsuarios];
    }
    $destinoUsuarios = array_values(array_unique(array_filter(array_map('intval', $destinoUsuarios), function ($v) {
        return $v > 0;
    })));
    $vigenciaInicio = normalizeDate($_POST['vigencia_inicio'] ?? null);
    $vigenciaFim = normalizeDate($_POST['vigencia_fim'] ?? null);
    $fixa = !empty($_POST['fixa']) ? 1 : 0;
    $podeFechar = !empty($_POST['pode_fechar']) ? 1 : 0;
    $ativo = !empty($_POST['ativo']) ? 1 : 0;
    $restricaoModulos = !empty($_POST['restricao_modulos']);
    $modulosSelecionados = $restricaoModulos ? ($_POST['modulos_exibicao'] ?? []) : [];
    if (!is_array($modulosSelecionados)) {
        $modulosSelecionados = [$modulosSelecionados];
    }
    $modulosSelecionados = $restricaoModulos
        ? sanitizeNotificationModuleSlugs($pdo, $modulosSelecionados)
        : [];

    $tiposPermitidos = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
    if (!in_array($tipoAlerta, $tiposPermitidos, true)) {
        $tipoAlerta = 'primary';
    }

    $destinosPermitidos = ['todos', 'nivel', 'usuario'];
    if (!in_array($destinoTipo, $destinosPermitidos, true)) {
        $destinoTipo = 'todos';
    }

    if ($restricaoModulos && empty($modulosSelecionados)) {
        jsonResponse(false, 'Selecione ao menos um módulo para exibir a notificação.');
    }

    if ($titulo === '' || $mensagem === '') {
        jsonResponse(false, 'Informe título e mensagem.');
    }

    $destinoValor = null;
    if ($destinoTipo === 'nivel') {
        if (empty($destinoNiveis)) {
            jsonResponse(false, 'Selecione pelo menos um nível.');
        }
        $placeholdersNivel = implode(',', array_fill(0, count($destinoNiveis), '?'));
        $stmtNivel = $pdo->prepare("SELECT COUNT(*) FROM niveis_acesso WHERE id IN ($placeholdersNivel)");
        $stmtNivel->execute($destinoNiveis);
        if ((int)$stmtNivel->fetchColumn() !== count($destinoNiveis)) {
            jsonResponse(false, 'Alguns níveis selecionados não existem.');
        }
        $destinoValor = implode(',', $destinoNiveis);
    } elseif ($destinoTipo === 'usuario') {
        if (empty($destinoUsuarios)) {
            jsonResponse(false, 'Selecione ao menos um usuário.');
        }
        $placeholders = implode(',', array_fill(0, count($destinoUsuarios), '?'));
        $stmtUsuario = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id IN ($placeholders)");
        $stmtUsuario->execute($destinoUsuarios);
        if ((int)$stmtUsuario->fetchColumn() !== count($destinoUsuarios)) {
            jsonResponse(false, 'Alguns usuários selecionados não existem.');
        }
        $destinoValor = implode(',', $destinoUsuarios);
    }

    if (!$fixa && empty($vigenciaFim)) {
        jsonResponse(false, 'Defina uma data de término ou marque a notificação como fixa.');
    }

    $params = [
        $titulo,
        $mensagem,
        $tipoAlerta,
        $destinoTipo,
        $destinoValor,
        $vigenciaInicio,
        $vigenciaFim,
        $fixa,
        $podeFechar,
        $ativo
    ];

    try {
        if ($id) {
            $params[] = $id;
            $stmt = $pdo->prepare("
                UPDATE notificacoes
                SET titulo = ?, mensagem = ?, tipo_alerta = ?, destino_tipo = ?, destino_valor = ?,
                    vigencia_inicio = ?, vigencia_fim = ?, fixa = ?, pode_fechar = ?, ativo = ?
                WHERE id = ?
            ");
            $stmt->execute($params);
            syncNotificationModules($pdo, $id, $modulosSelecionados);
            jsonResponse(true, 'Notificação atualizada com sucesso.');
        } else {
            $params[] = $usuarioId;
            $stmt = $pdo->prepare("
                INSERT INTO notificacoes
                    (titulo, mensagem, tipo_alerta, destino_tipo, destino_valor,
                     vigencia_inicio, vigencia_fim, fixa, pode_fechar, ativo, criado_por, criado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute($params);
            $novoId = (int)$pdo->lastInsertId();
            syncNotificationModules($pdo, $novoId, $modulosSelecionados);
            jsonResponse(true, 'Notificação criada com sucesso.');
        }
    } catch (Throwable $e) {
        jsonResponse(false, 'Não foi possível salvar a notificação.');
    }
}

if ($action === 'delete_notification') {
    ensureNotificationPermission('pode_excluir');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'Notificação inválida.');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM notificacoes_leituras WHERE id_notificacao = ?")->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM notificacoes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            jsonResponse(false, 'Notificação não encontrada.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Não foi possível excluir a notificação.');
    }

    jsonResponse(true, 'Notificação excluída com sucesso.');
}

if ($action === 'close_notification') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || !$usuarioId) {
        jsonResponse(false, 'Notificação inválida.');
    }

    $nivelUsuario = 0;
    try {
        $stmtNivel = $pdo->prepare("
            SELECT COALESCE(un.id_nivel, u.nivel) AS nivel_id
            FROM usuarios u
            LEFT JOIN usuario_nivel un ON un.id_usuario = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmtNivel->execute([$usuarioId]);
        $nivelUsuario = (int)$stmtNivel->fetchColumn();
    } catch (Throwable $e) {
        $nivelUsuario = 0;
    }

    $stmt = $pdo->prepare("
        SELECT id, destino_tipo, destino_valor, pode_fechar
        FROM notificacoes
        WHERE id = ? AND ativo = 1
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $notif = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$notif) {
        jsonResponse(false, 'Notificação não encontrada.');
    }

    if ((int)$notif['pode_fechar'] !== 1) {
        jsonResponse(false, 'Esta notificação não pode ser fechada manualmente.');
    }

    $permitido = false;
    if ($notif['destino_tipo'] === 'todos') {
        $permitido = true;
    } elseif ($notif['destino_tipo'] === 'nivel' && $notif['destino_valor']) {
        $listaNiveis = array_filter(array_map('intval', explode(',', (string)$notif['destino_valor'])));
        $permitido = in_array((int)$nivelUsuario, $listaNiveis, true);
    } elseif ($notif['destino_tipo'] === 'usuario' && $notif['destino_valor']) {
        $lista = array_filter(array_map('intval', explode(',', (string)$notif['destino_valor'])));
        $permitido = in_array((int)$usuarioId, $lista, true);
    }

    if (!$permitido) {
        jsonResponse(false, 'Você não pode fechar esta notificação.');
    }

    try {
        $pdo->prepare("DELETE FROM notificacoes_leituras WHERE id_notificacao = ? AND id_usuario = ?")
            ->execute([$id, $usuarioId]);
        $pdo->prepare("
            INSERT INTO notificacoes_leituras (id_notificacao, id_usuario, fechado_em)
            VALUES (?, ?, NOW())
        ")->execute([$id, $usuarioId]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Não foi possível registrar o fechamento.');
    }

    jsonResponse(true, 'Notificação ocultada.');
}
