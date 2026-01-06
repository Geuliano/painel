<?php

ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';

requireLogin();

// Garante colunas extras que o módulo utiliza (apelido/telefone)
function ensureUsuariosExtraColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
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

function jsonResponse(bool $success, $message = null, array $extra = []): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    $payload = ['success' => $success];
    if (is_array($message)) {
        $payload = array_merge($payload, $message);
    } elseif ($message !== null) {
        $payload['message'] = $message;
    }
    if ($extra) {
        $payload = array_merge($payload, $extra);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Normaliza o telefone brasileiro para envio via WhatsApp (DDI 55 + dígitos).
 * Retorna string, null quando vazio ou false quando o formato for inválido.
 */
function normalizarTelefoneBrasil($telefone)
{
    $apenasDigitos = preg_replace('/\D+/', '', (string)$telefone);
    if ($apenasDigitos === '') {
        return null;
    }

    $apenasDigitos = ltrim($apenasDigitos, '0');

    if (strpos($apenasDigitos, '55') !== 0) {
        $apenasDigitos = '55' . $apenasDigitos;
    }

    $len = strlen($apenasDigitos);
    if ($len < 12 || $len > 13) {
        return false;
    }

    return $apenasDigitos;
}

if (!isset($pdo)) {
    jsonResponse(false, 'Conexão com o banco não disponível.');
}

ensureUsuariosExtraColumns($pdo);

$action = $_POST['action'] ?? '';
$action = trim($action);

$allowed = ['update_profile', 'update_password'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Ação inválida.');
}

if (empty($_SESSION['csrf_token']) || ($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
    jsonResponse(false, 'Falha de segurança.');
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
if (!$usuarioId) {
    jsonResponse(false, 'Sessão expirada.');
}

$projectRoot = dirname($appPath);
$publicPath = $projectRoot . '/public';
$avatarDir = $publicPath . '/uploads/avatars/';
if (!is_dir($avatarDir)) {
    @mkdir($avatarDir, 0775, true);
}

function processAvatarUpload(array $file, int $userId, string $avatarDir)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed, true)) {
        return false;
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return false;
    }
    $name = "avatar_{$userId}_" . time() . '.' . $ext;
    $dest = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return false;
    }
    return $name;
}

if ($action === 'update_profile') {
    $nome  = trim($_POST['nome'] ?? '');
    $apelido = trim($_POST['apelido'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefoneRaw = $_POST['telefone'] ?? '';
    $tema  = $_POST['tema'] ?? 'light';

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Dados inválidos.');
    }

    if (!in_array($tema, ['light', 'dark'], true)) {
        $tema = 'light';
    }

    $telefone = normalizarTelefoneBrasil($telefoneRaw);
    if ($telefone === false) {
        jsonResponse(false, 'Informe um telefone com DDD válido.');
    }

    try {
        $stmt = $pdo->prepare("SELECT avatar FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $user = null;
    }

    if (!$user) {
        jsonResponse(false, 'Usuário não encontrado.');
    }

    $newAvatar = null;
    if (!empty($_FILES['avatar']['name'] ?? '')) {
        $upload = processAvatarUpload($_FILES['avatar'], $usuarioId, $avatarDir);
        if ($upload === false) {
            jsonResponse(false, 'Falha no upload do avatar.');
        }
        $newAvatar = $upload;
    }

    try {
        $pdo->beginTransaction();

        $pedido = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1");
        $pedido->execute([$email, $usuarioId]);
        if ($pedido->fetch()) {
            $pdo->rollBack();
            jsonResponse(false, 'Já existe outro usuário com este email.');
        }

        $query = "UPDATE usuarios SET nome = ?, apelido = ?, email = ?, telefone = ?, tema = ?";
        $params = [
            $nome,
            $apelido !== '' ? $apelido : null,
            $email,
            $telefone,
            $tema
        ];

        if ($newAvatar) {
            $query .= ", avatar = ?";
            $params[] = $newAvatar;
        }

        $query .= " WHERE id = ?";
        $params[] = $usuarioId;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newAvatar) {
            $file = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $newAvatar;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        jsonResponse(false, 'Erro ao atualizar o perfil.');
    }

    $_SESSION['tema'] = $tema;

    if ($newAvatar && !empty($user['avatar'])) {
        $old = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $user['avatar'];
        if (is_file($old)) {
            @unlink($old);
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    jsonResponse(true, 'Dados atualizados com sucesso.');
}

if ($action === 'update_password') {
    $senhaAtual    = $_POST['senha_atual'] ?? '';
    $senhaNova     = $_POST['senha_nova'] ?? '';
    $senhaConfirma = $_POST['senha_confirma'] ?? '';

    if ($senhaNova === '' || strlen($senhaNova) < 6) {
        jsonResponse(false, 'A nova senha deve possuir ao menos 6 caracteres.');
    }
    if ($senhaNova !== $senhaConfirma) {
        jsonResponse(false, 'As senhas informadas não conferem.');
    }

    $stmt = $pdo->prepare("SELECT senha_hash FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$usuarioId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['senha_hash']) || !password_verify($senhaAtual, $user['senha_hash'])) {
        jsonResponse(false, 'Senha atual incorreta.');
    }

    $novaHash = password_hash($senhaNova, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
    $stmt->execute([$novaHash, $usuarioId]);

    jsonResponse(true, 'Senha alterada com sucesso.');
}
