<?php

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH', ROOT_PATH . '/app');
define('CORE_PATH', APP_PATH . '/core');
define('MODULES_PATH', APP_PATH . '/modules');
define('PAGES_PATH', APP_PATH . '/pages');
define('PUBLIC_PATH', ROOT_PATH . '/public');

date_default_timezone_set('America/Porto_Velho');

// ======= Conexão com BD =======
$host = '127.0.0.1';
$db = 'iptv';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    die('Erro na conexão ao banco.');
}

// ===== Sessão segura =====
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'httponly' => true,
    'secure' => false,
    'samesite' => 'Strict'
]);

session_start();

// ===== BASE_URL =====
// Prioriza o caminho da raiz do projeto em relacao ao document root, evitando que chamadas diretas a scripts em /app mudem a BASE_URL.
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/') : null;
$rootRealpath = rtrim(str_replace('\\', '/', realpath(ROOT_PATH)), '/');
$basePath = '';
if ($documentRoot && $rootRealpath && str_starts_with($rootRealpath, $documentRoot)) {
    $relative = trim(substr($rootRealpath, strlen($documentRoot)), '/');
    $basePath = $relative === '' ? '' : '/' . $relative;
}
// Fallback para o comportamento anterior caso n�o consiga resolver via DOCUMENT_ROOT
if ($basePath === '') {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/public$#', '', rtrim($scriptDir, '/'));
}
define('BASE_URL', ($basePath === '' ? '/' : $basePath . '/'));
