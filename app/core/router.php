<?php

require_once CORE_PATH . '/config.php';
require_once CORE_PATH . '/auth.php';
require_once CORE_PATH . '/permissions.php';

function runRouter()
{
    global $pdo;

    requireLogin();

    $modParam = $_GET['mod'] ?? null;
    $acao     = $_GET['acao'] ?? 'listar';
    $id       = $_GET['id'] ?? null;

    $acao = preg_replace('/[^a-z0-9_\-]/i', '', (string)$acao);
    if ($acao === '') {
        $acao = 'listar';
    }

    $moduloId = resolveModuleId($modParam);
    if (!$moduloId) {
        $moduloId = getModuleIdBySlug('dashboard');
    }

    $moduloInfo = $moduloId ? getModuleById($moduloId) : null;
    if (!$moduloInfo || empty($moduloInfo['slug'])) {
        return "<div class='alert alert-danger text-center mt-5'>Módulo não encontrado.</div>";
    }

    if (empty($moduloInfo['ativo'])) {
        http_response_code(403);
        return "<div class='alert alert-warning text-center mt-5'>Módulo inativo.</div>";
    }

    if (!checkActionPermission($_SESSION['usuario_id'], $moduloId, $acao)) {
        return "<div class='alert alert-warning text-center mt-5'>Você não tem permissão para acessar aqui.</div>";
    }

    $slugCaminho = ltrim($moduloInfo['slug'], '/');
    if (strpos($slugCaminho, '..') !== false) {
        return "<div class='alert alert-danger text-center mt-5'>Caminho do módulo inválido.</div>";
    }

    // Aceita slugs legados (ex.: "dashboard") e novos caminhos completos (ex.: "modules/produtos/sub-modulo").
    if (stripos($slugCaminho, 'modules/') === 0) {
        $moduloPath = rtrim(APP_PATH . '/' . $slugCaminho, '/') . '/index.php';
    } else {
        $moduloPath = rtrim(MODULES_PATH . '/' . $slugCaminho, '/') . '/index.php';
    }

    if (!file_exists($moduloPath)) {
        return "<div class='alert alert-danger text-center mt-5'>Módulo não encontrado: <b>" . htmlspecialchars($moduloInfo['slug']) . "</b></div>";
    }

    // Mantém variáveis globais de compatibilidade
    $GLOBALS['modulo']          = $moduloInfo['slug'];
    $GLOBALS['moduloSlugAtual'] = $moduloInfo['slug'];
    $GLOBALS['moduloIdAtual']   = $moduloId;
    $GLOBALS['acaoAtual']       = $acao;
    $GLOBALS['registroId']      = $id;

    ob_start();
    require $moduloPath;

    $conteudo = ob_get_clean();
    if ($conteudo === false) {
        return '';
    }

    // remove BOM acidental e espaços antes do conteúdo
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    return ltrim($conteudo);
}

/**
 * Retorna o slug/caminho do módulo solicitado apenas se o usuário tiver permissão.
 */
function getAuthorizedModuleSlugForUser(int $userId, $moduloSolicitado, string $acaoSolicitada = 'listar'): ?string
{
    $acao = preg_replace('/[^a-z0-9_\-]/i', '', $acaoSolicitada);
    if ($acao === '') {
        $acao = 'listar';
    }

    $moduloId = resolveModuleId($moduloSolicitado);
    if (!$moduloId) {
        return null;
    }

    $moduloInfo = getModuleById($moduloId);
    if (!$moduloInfo || empty($moduloInfo['slug']) || empty($moduloInfo['ativo'])) {
        return null;
    }

    return checkActionPermission($userId, $moduloId, $acao) ? $moduloInfo['slug'] : null;
}
