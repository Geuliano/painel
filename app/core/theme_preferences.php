<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, $message = null, array $extra = []): void
{
    $payload = ['success' => $success];
    if (is_array($message)) {
        $payload = array_merge($payload, $message);
    } elseif ($message !== null) {
        $payload['message'] = $message;
    }
    if ($extra) {
        $payload = array_merge($payload, $extra);
    }
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Método não permitido.');
}

if (empty($_SESSION['csrf_token'])) {
    respond(false, 'Sessão inválida.');
}

$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (empty($input) && stripos($contentType, 'application/json') !== false) {
    $raw = json_decode(file_get_contents('php://input'), true);
    if (is_array($raw)) {
        $input = $raw;
    }
}

$token = $input['csrf_token'] ?? '';

if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string)
    {
        if (!is_string($known_string) || !is_string($user_string)) {
            return false;
        }
        if (strlen($known_string) !== strlen($user_string)) {
            return false;
        }
        $res = 0;
        $len = strlen($known_string);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }
        return $res === 0;
    }
}

if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    respond(false, 'Falha de segurança.');
}

$tema = $input['theme'] ?? '';
$tema = is_string($tema) ? strtolower(trim($tema)) : '';

if (!in_array($tema, ['light', 'dark'], true)) {
    respond(false, 'Tema inválido.');
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
if (!$usuarioId) {
    respond(false, 'Sessão expirada.');
}

try {
    $stmt = $pdo->prepare("UPDATE usuarios SET tema = ? WHERE id = ? LIMIT 1");
    $stmt->execute([$tema, $usuarioId]);
} catch (Throwable $e) {
    respond(false, 'Não foi possível atualizar o tema.');
}

$_SESSION['tema'] = $tema;

respond(true, 'Tema atualizado.', ['theme' => $tema]);
