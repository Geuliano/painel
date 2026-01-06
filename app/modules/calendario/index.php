<?php
requireLogin();

$acao = $_GET['acao'] ?? 'listar';
$permitidas = ['listar'];
if (!in_array($acao, $permitidas, true)) {
    $acao = 'listar';
}

$arquivo = __DIR__ . '/' . $acao . '.php';
if (!is_file($arquivo)) {
    $arquivo = __DIR__ . '/listar.php';
}

require_once dirname(__DIR__, 2) . '/core/module_header.php';
require $arquivo;
