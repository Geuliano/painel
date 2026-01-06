<?php

requireLogin();

$acao = $_GET['acao'] ?? 'listar';

$acoesPermitidas = [
    'listar',
];

if (!in_array($acao, $acoesPermitidas, true)) {
    $acao = 'listar';
}

$arquivo = __DIR__ . '/' . $acao . '.php';
if (!is_file($arquivo)) {
    $arquivo = __DIR__ . '/listar.php';
}

require_once dirname(__DIR__, 2) . '/core/module_header.php';
require $arquivo;
