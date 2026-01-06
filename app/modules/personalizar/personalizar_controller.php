<?php
ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';
require_once __DIR__ . '/personalizar_helper.php';

requireLogin();

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

if (!isset($pdo)) {
    jsonResponse(false, 'Conexão com o banco indisponível.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$allowed = ['save_settings'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Ação inválida.');
}

$requiresCSRF = ['save_settings'];
if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(false, 'Falha de segurança. Recarregue a página.');
    }
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
if (!$usuarioId) {
    jsonResponse(false, 'Sessão expirada.');
}

function ensureBrandPermission(string $tipo): void
{
    global $usuarioId;
    if (!userHasPermission($usuarioId, 'personalizar', $tipo)) {
        jsonResponse(false, 'Você não tem permissão para executar esta ação.');
    }
}

function deleteBrandingFile(?string $path): void
{
    if (!$path) {
        return;
    }
    $normalized = str_replace('\\', '/', $path);
    if (strpos($normalized, 'public/uploads/branding/') !== 0) {
        return;
    }
    $fullPath = ROOT_PATH . '/' . $normalized;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function handleBrandUpload(string $field, array $allowedExtensions, int $maxBytes, string $prefix, string $uploadDir): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [false, null];
    }

    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [true, null];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return [true, false];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return [true, false];
    }

    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $name = sprintf(
        '%s_%s.%s',
        $prefix,
        bin2hex(random_bytes(6)),
        $ext
    );
    $dest = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [true, false];
    }

    $relative = 'public/uploads/branding/' . $name;
    return [true, $relative];
}

if ($action === 'save_settings') {
    ensureBrandPermission('pode_editar');

    $titulo = trim($_POST['titulo_site'] ?? '');
    if ($titulo === '') {
        jsonResponse(false, 'Informe o título do site.');
    }

    ensurePersonalizacaoTable($pdo);
    $registroAtual = getBrandingRecord($pdo);
    $uploadDir = ROOT_PATH . '/public/uploads/branding/';

    [$hasFileFav, $novoFavicon] = handleBrandUpload('favicon', ['png', 'jpg', 'jpeg', 'ico', 'svg'], 300 * 1024, 'favicon', $uploadDir);
    if ($hasFileFav && $novoFavicon === false) {
        jsonResponse(false, 'Favicon inválido. Utilize PNG, JPG, ICO ou SVG até 300KB.');
    }

    [$hasLight, $logoLight] = handleBrandUpload('logo_light', ['png', 'jpg', 'jpeg', 'svg'], 1024 * 1024, 'logo_light', $uploadDir);
    if ($hasLight && $logoLight === false) {
        jsonResponse(false, 'Logo do tema claro inválida. Envie PNG/JPG/SVG até 1MB.');
    }

    [$hasDark, $logoDark] = handleBrandUpload('logo_dark', ['png', 'jpg', 'jpeg', 'svg'], 1024 * 1024, 'logo_dark', $uploadDir);
    if ($hasDark && $logoDark === false) {
        jsonResponse(false, 'Logo do tema escuro inválida. Envie PNG/JPG/SVG até 1MB.');
    }

    [$hasSmall, $logoSmall] = handleBrandUpload('logo_small', ['png', 'jpg', 'jpeg', 'svg'], 600 * 1024, 'logo_small', $uploadDir);
    if ($hasSmall && $logoSmall === false) {
        jsonResponse(false, 'Logo reduzida inválida. Envie PNG/JPG/SVG até 600KB.');
    }

    $dados = [
        'titulo_site' => $titulo,
        'favicon'     => $novoFavicon ?? ($registroAtual['favicon'] ?? null),
        'logo_light'  => $logoLight ?? ($registroAtual['logo_light'] ?? null),
        'logo_dark'   => $logoDark ?? ($registroAtual['logo_dark'] ?? null),
        'logo_small'  => $logoSmall ?? ($registroAtual['logo_small'] ?? null),
    ];

    try {
        if (!empty($registroAtual['id'])) {
            $stmt = $pdo->prepare("
                UPDATE personalizacoes
                SET titulo_site = ?, favicon = ?, logo_light = ?, logo_dark = ?, logo_small = ?,
                    atualizado_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $dados['titulo_site'],
                $dados['favicon'],
                $dados['logo_light'],
                $dados['logo_dark'],
                $dados['logo_small'],
                $registroAtual['id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO personalizacoes (titulo_site, favicon, logo_light, logo_dark, logo_small, atualizado_em)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $dados['titulo_site'],
                $dados['favicon'],
                $dados['logo_light'],
                $dados['logo_dark'],
                $dados['logo_small']
            ]);
        }
    } catch (Throwable $e) {
        if ($novoFavicon) {
            deleteBrandingFile($novoFavicon);
        }
        if ($logoLight) {
            deleteBrandingFile($logoLight);
        }
        if ($logoDark) {
            deleteBrandingFile($logoDark);
        }
        if ($logoSmall) {
            deleteBrandingFile($logoSmall);
        }
        jsonResponse(false, 'Não foi possível salvar a personalização.');
    }

    if ($novoFavicon && !empty($registroAtual['favicon'])) {
        deleteBrandingFile($registroAtual['favicon']);
    }
    if ($logoLight && !empty($registroAtual['logo_light'])) {
        deleteBrandingFile($registroAtual['logo_light']);
    }
    if ($logoDark && !empty($registroAtual['logo_dark'])) {
        deleteBrandingFile($registroAtual['logo_dark']);
    }
    if ($logoSmall && !empty($registroAtual['logo_small'])) {
        deleteBrandingFile($registroAtual['logo_small']);
    }

    jsonResponse(true, 'Personalização salva com sucesso.');
}
