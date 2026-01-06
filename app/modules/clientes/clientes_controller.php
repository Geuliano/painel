<?php
/**
 * Controller do modulo de clientes IPTV.
 * - Login obrigatorio
 * - Permissoes por acao
 * - CSRF para acoes de escrita
 * - Respostas sempre em JSON limpo
 */

ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';
require_once __DIR__ . '/clientes_helper.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Sessao expirada.');
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
ensureClientesTable($pdo);

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
    jsonResponse(false, 'Conexao com o banco indisponivel.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$allowedActions = ['list', 'get', 'save', 'renew', 'toggle_active', 'delete', 'clear_fiado'];
if (!in_array($action, $allowedActions, true)) {
    jsonResponse(false, 'Acao invalida.');
}

$mapaPermessao = [
    'list'  => 'listar',
    'get'   => 'listar',
    'save'  => null,    // definido conforme create/update
    'renew' => 'editar',
    'toggle_active' => 'editar',
    'clear_fiado' => 'editar',
    'delete' => 'excluir',
];

$requiresCSRF = ['save', 'renew', 'toggle_active', 'delete', 'clear_fiado'];
if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        jsonResponse(false, 'Falha de seguranca: CSRF token invalido.');
    }
}

$modulo = 'clientes';

/**
 * Determina se o usuario tem a permessao necessaria para a acao atual.
 */
function ensurePermissionForAction(string $action, int $usuarioId, string $modulo): void
{
    $mapa = [
        'list'  => 'listar',
        'get'   => 'listar',
        'save'  => null, // definido dinamicamente (criar|editar)
        'renew' => 'editar',
        'toggle_active' => 'editar',
        'clear_fiado' => 'editar',
        'delete' => 'excluir',
    ];

    $tipo = $mapa[$action] ?? 'listar';
    if ($action === 'save') {
        $isUpdate = !empty($_POST['id']);
        $tipo = $isUpdate ? 'editar' : 'criar';
    }

    if (!checkActionPermission($usuarioId, $modulo, $tipo)) {
        jsonResponse(false, 'Voce nao tem permissao para executar esta acao.');
    }
}

ensurePermissionForAction($action, $usuarioId, $modulo);

/**
 * Converte string monetaria em decimal.
 */
function parseValorPlano($valor): float
{
    if (is_numeric($valor)) {
        return (float)$valor;
    }
    $clean = str_replace(['.', ' '], '', (string)$valor);
    $clean = str_replace(',', '.', $clean);
    return (float)$clean;
}

/**
 * Validacao basica de data (YYYY-MM-DD).
 */
function parseDateOrNull($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value ? $value : null;
}

function sanitizePagamentoTipo($valor): string
{
    $valor = strtolower(trim((string)$valor));
    return in_array($valor, ['avista', 'fiado'], true) ? $valor : 'avista';
}

/**
 * Soma meses preservando o dia quando possivel, caindo para o ultimo dia do mes se necessario.
 */
function addMonthsKeepingDay(DateTime $dataBase, int $meses): DateTime
{
    $diaOriginal = (int)$dataBase->format('d');
    $dataBase->modify('first day of this month');
    $dataBase->modify("+{$meses} months");
    $ultimoDia = (int)$dataBase->format('t');
    $dataBase->setDate((int)$dataBase->format('Y'), (int)$dataBase->format('m'), min($diaOriginal, $ultimoDia));
    return $dataBase;
}

if ($action === 'list') {
    try {
        $stmt = $pdo->query("
            SELECT
                id,
                nome,
                provedor,
                provedor_usuario,
                provedor_senha,
                telefone,
                plano,
                valor_plano,
                quantidade_telas,
                aplicativo_usado,
                pagamento_tipo,
                cobranca_em,
                validade,
                observacoes,
                ativo,
                criado_em,
                atualizado_em
            FROM clientes
            WHERE excluido_em IS NULL
            ORDER BY nome ASC
        ");
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $clientes = [];
    }

    foreach ($clientes as &$c) {
        $c['telefone_formatado'] = formatarTelefoneParaExibicao($c['telefone'] ?? null);
        $c['valor_plano'] = (float)($c['valor_plano'] ?? 0);
    }
    unset($c);

    jsonResponse(true, ['clientes' => $clientes]);
}

if ($action === 'get') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID invalido.');
    }

    $stmt = $pdo->prepare("
            SELECT
                id,
                nome,
                provedor,
                provedor_usuario,
                provedor_senha,
                telefone,
                plano,
            valor_plano,
            quantidade_telas,
            aplicativo_usado,
            pagamento_tipo,
            cobranca_em,
            validade,
            observacoes,
            ativo,
            criado_em,
            atualizado_em
        FROM clientes
        WHERE id = ? AND excluido_em IS NULL
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        jsonResponse(false, 'Cliente nao encontrado.');
    }

    $cliente['telefone_formatado'] = formatarTelefoneParaExibicao($cliente['telefone'] ?? null);
    $cliente['valor_plano'] = (float)($cliente['valor_plano'] ?? 0);

    $logs = [];
    try {
        $stmtLogs = $pdo->prepare("
            SELECT acao, plano, valor_plano, validade, pagamento_tipo, cobranca_em, observacao, criado_em
            FROM clientes_planos_logs
            WHERE cliente_id = ?
            ORDER BY criado_em DESC
            LIMIT 30
        ");
        $stmtLogs->execute([$id]);
        $logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $logs = [];
    }

    jsonResponse(true, ['cliente' => $cliente, 'logs' => $logs]);
}

if ($action === 'save') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $isUpdate = (bool)$id;
    ensurePermissionForAction('save', $usuarioId, $modulo);

    $nome = trim($_POST['nome'] ?? '');
    $provedor = trim($_POST['provedor'] ?? '');
    $provedorUsuario = trim($_POST['provedor_usuario'] ?? '');
    $provedorSenha = trim($_POST['provedor_senha'] ?? '');
    $telefoneRaw = $_POST['telefone'] ?? '';
    $plano = trim($_POST['plano'] ?? '');
    $valorPlano = parseValorPlano($_POST['valor_plano'] ?? 0);
    $quantidadeTelas = (int)($_POST['quantidade_telas'] ?? 1);
    $aplicativoUsado = trim($_POST['aplicativo_usado'] ?? '');
    $validade = parseDateOrNull($_POST['validade'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $pagamentoTipo = sanitizePagamentoTipo($_POST['pagamento_tipo'] ?? 'avista');
    $cobrancaEm = parseDateOrNull($_POST['cobranca_em'] ?? '');
    $ativo = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

    if ($nome === '' || $provedorUsuario === '' || $provedorSenha === '') {
        jsonResponse(false, 'Informe nome, usuario e senha do provedor.');
    }

    if ($quantidadeTelas < 1) {
        $quantidadeTelas = 1;
    }

    $telefone = normalizarTelefoneBrasil($telefoneRaw);
    if ($telefone === false) {
        jsonResponse(false, 'Informe um telefone com DDD valido.');
    }

    if ($telefone !== null) {
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefone = ? AND id <> ? AND excluido_em IS NULL LIMIT 1");
        $stmt->execute([$telefone, $id ?: 0]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'Ja existe um cliente com este telefone.');
        }
    }

    if ($pagamentoTipo === 'fiado' && !$cobrancaEm) {
        jsonResponse(false, 'Informe a data de cobranca quando o pagamento for fiado.');
    }
    if ($pagamentoTipo !== 'fiado') {
        $cobrancaEm = null;
    }

    $registroAnterior = null;
    if ($isUpdate) {
        $stmt = $pdo->prepare("SELECT plano, valor_plano, validade, pagamento_tipo, cobranca_em FROM clientes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $registroAnterior = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$registroAnterior) {
            jsonResponse(false, 'Cliente nao encontrado para atualizacao.');
        }
    }

    // Evita duplicar usuario de login no provedor
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE provedor_usuario = ? AND id <> ? LIMIT 1");
    $stmt->execute([$provedorUsuario, $id ?: 0]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Ja existe um cliente com este usuario de provedor.');
    }

    if ($isUpdate) {
        $sql = "
            UPDATE clientes
            SET nome = ?, provedor = ?, provedor_usuario = ?, provedor_senha = ?, telefone = ?, plano = ?, valor_plano = ?, quantidade_telas = ?, aplicativo_usado = ?, validade = ?, observacoes = ?, pagamento_tipo = ?, cobranca_em = ?, ativo = ?
            WHERE id = ?
        ";
        $params = [$nome, $provedor ?: null, $provedorUsuario ?: null, $provedorSenha ?: null, $telefone, $plano ?: null, $valorPlano, $quantidadeTelas, $aplicativoUsado ?: null, $validade, $observacoes ?: null, $pagamentoTipo, $cobrancaEm, $ativo ? 1 : 0, $id];
    } else {
        $sql = "
            INSERT INTO clientes (nome, provedor, provedor_usuario, provedor_senha, telefone, plano, valor_plano, quantidade_telas, aplicativo_usado, validade, observacoes, pagamento_tipo, cobranca_em, ativo, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $params = [$nome, $provedor ?: null, $provedorUsuario ?: null, $provedorSenha ?: null, $telefone, $plano ?: null, $valorPlano, $quantidadeTelas, $aplicativoUsado ?: null, $validade, $observacoes ?: null, $pagamentoTipo, $cobrancaEm, $ativo ? 1 : 0];
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $newId = $isUpdate ? $id : (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel salvar o cliente.');
    }

    $mudouPlano = !$isUpdate
        || ($registroAnterior['plano'] ?? null) !== ($plano ?: null)
        || (float)($registroAnterior['valor_plano'] ?? 0) !== $valorPlano
        || ($registroAnterior['validade'] ?? null) !== $validade
        || ($registroAnterior['pagamento_tipo'] ?? '') !== $pagamentoTipo
        || ($registroAnterior['cobranca_em'] ?? null) !== $cobrancaEm;

    if ($mudouPlano) {
        registrarLogPlano($pdo, $newId, $usuarioId, $isUpdate ? 'atualizar' : 'criar', [
            'plano' => $plano ?: null,
            'valor_plano' => $valorPlano,
            'validade' => $validade,
            'pagamento_tipo' => $pagamentoTipo,
            'cobranca_em' => $cobrancaEm,
            'observacao' => $observacoes ?: null,
        ]);
    }

    jsonResponse(true, $isUpdate ? 'Cliente atualizado comesucesso.' : 'Cliente criado comesucesso.', ['id' => $newId]);
}

if ($action === 'renew') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $dias = (int)($_POST['dias'] ?? 0);
    $pagamentoTipo = sanitizePagamentoTipo($_POST['pagamento_tipo'] ?? 'avista');
    $cobrancaEm = parseDateOrNull($_POST['cobranca_em'] ?? '');
    $valorPago = parseValorPlano($_POST['valor_plano'] ?? 0);

    if (!$id || $dias <= 0 || $dias > 365) {
        jsonResponse(false, 'Informe um periodo de renovacao valido (1 a 365 dias).');
    }
    if ($pagamentoTipo === 'fiado' && !$cobrancaEm) {
        jsonResponse(false, 'Informe a data de cobranca quando o pagamento for fiado.');
    }
    if ($pagamentoTipo !== 'fiado') {
        $cobrancaEm = null;
    }

    $stmt = $pdo->prepare("SELECT validade, plano, valor_plano FROM clientes WHERE id = ? AND excluido_em IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonResponse(false, 'Cliente nao encontrado.');
    }

    $base = $row['validade'] ? DateTime::createFromFormat('Y-m-d', $row['validade']) : null;
    $hoje = new DateTime('today');
    if (!$base || $base < $hoje) {
        $base = $hoje;
    }

    if ($dias >= 30 && $dias % 30 === 0) {
        $meses = (int)($dias / 30);
        $base = addMonthsKeepingDay($base, $meses);
    } else {
        $base->modify("+{$dias} days");
    }

    $novaValidade = $base->format('Y-m-d');

    try {
        $stmt = $pdo->prepare("UPDATE clientes SET validade = ?, pagamento_tipo = ?, cobranca_em = ?, valor_plano = ? WHERE id = ?");
        $stmt->execute([$novaValidade, $pagamentoTipo, $cobrancaEm, $valorPago, $id]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel renovar o plano.');
    }

    registrarLogPlano($pdo, $id, $usuarioId, 'renovar', [
        'plano' => $row['plano'] ?? null,
        'valor_plano' => $valorPago,
        'validade' => $novaValidade,
        'pagamento_tipo' => $pagamentoTipo,
        'cobranca_em' => $cobrancaEm,
    ]);

    jsonResponse(true, 'Plano renovado comesucesso.', ['validade' => $novaValidade]);
}

if ($action === 'clear_fiado') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID invalido.');
    }

    $clienteAtual = null;
    try {
        $stmt = $pdo->prepare("SELECT plano, valor_plano, validade, pagamento_tipo, cobranca_em FROM clientes WHERE id = ? AND excluido_em IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $clienteAtual = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // segue fluxo para mensagem generica de falha
    }

    if (!$clienteAtual) {
        jsonResponse(false, 'Cliente nao encontrado.');
    }

    $eraFiado = strtolower((string)($clienteAtual['pagamento_tipo'] ?? '')) === 'fiado';

    try {
        $stmt = $pdo->prepare("UPDATE clientes SET pagamento_tipo = 'avista', cobranca_em = NULL WHERE id = ? AND excluido_em IS NULL");
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel atualizar o pagamento.');
    }

    registrarLogPlano($pdo, $id, $usuarioId, 'pagamento_fiado', [
        'plano' => $clienteAtual['plano'] ?? null,
        'valor_plano' => $clienteAtual['valor_plano'] ?? 0,
        'validade' => $clienteAtual['validade'] ?? null,
        'pagamento_tipo' => 'avista',
        'cobranca_em' => null,
        'observacao' => 'Pendencia de fiado removida manualmente.',
    ]);

    $mensagem = $eraFiado ? 'Pendencia de fiado removida.' : 'Pagamento marcado como pago a vista.';
    jsonResponse(true, $mensagem);
}

if ($action === 'toggle_active') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $ativo = isset($_POST['ativo']) ? (int)$_POST['ativo'] : null;
    if (!$id || $ativo === null) {
        jsonResponse(false, 'Parametros invalidos.');
    }

    try {
        $stmt = $pdo->prepare("UPDATE clientes SET ativo = ? WHERE id = ? AND excluido_em IS NULL");
        $stmt->execute([$ativo ? 1 : 0, $id]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel atualizar o status.');
    }

    jsonResponse(true, $ativo ? 'Cliente ativado.' : 'Cliente desativado.');
}

if ($action === 'delete') {
    ensurePermissionForAction('delete', $usuarioId, $modulo);

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        jsonResponse(false, 'ID invalido.');
    }

    try {
        $stmt = $pdo->prepare("UPDATE clientes SET ativo = 0, excluido_em = NOW() WHERE id = ? AND excluido_em IS NULL");
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel excluir o cliente.');
    }

    jsonResponse(true, 'Cliente excluido comesucesso.');
}

