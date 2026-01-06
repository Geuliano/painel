<?php
/**
 * ==========================================================
 * INDEX DO MÓDULO (SEGURO)
 * ==========================================================
 * - Só aceita ações permitidas
 * - Só inclui arquivos permitidos
 * - Impede exploração via URL
 * - Impede que usuário invente uma ação
 * ==========================================================
 */


requireLogin();

$moduloSlug = basename(__DIR__);

// Ação solicitada
$acao = $_GET['acao'] ?? 'listar';

// Lista branca de ações PERMITIDAS
$acoesPermitidas = [
    'listar',
    'criar',
    'editar',
    'excluir',
    'detalhes',
];

if (!in_array($acao, $acoesPermitidas, true)) {
    $acao = 'listar'; // fallback seguro
}

// Caminhos internos do módulo
$basePath = __DIR__;
$arquivo = "{$basePath}/{$acao}.php";

// O arquivo precisa existir **e** a ação estar na lista branca
if (!file_exists($arquivo)) {
    $arquivo = "{$basePath}/listar.php"; // fallback
}

// Só agora carrega o header (seguro)
require_once dirname(__DIR__, 2) . '/core/module_header.php';

// Inclui a ação
require $arquivo;
