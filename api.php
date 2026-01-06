<?php
/**
 * API interna do modulo Clientes.
 * - Apenas requisicoes POST
 * - Protecao via chave estatica enviada em POST
 * - Respostas sempre em JSON
 *
 * Acoes:
 *   - check_client: busca cliente pelo telefone (normalizado) ou usuario e retorna dados.
 *   - create_client: cria um novo cliente localmente.
 */

ob_start();

// Caminhos base
$rootPath = __DIR__; // raiz do projeto (onde esta este api.php)
require_once $rootPath . '/app/core/config.php';
require_once $rootPath . '/app/modules/clientes/clientes_helper.php';

ensureClientesTable($pdo);

// Configure a chave de acesso da API (ideal via variavel de ambiente)
$API_ACCESS_KEY = getenv('CLIENTES_API_KEY') ?: 'e45a68b3d055e43d08fc014c36d1be1d338fd00b347d6de029ea1dbab7f461e8bde33ab142741945922cf870463cc80c40d7aa38ca92716600e2f0221cd7dc28';

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

    if ($extra) {
        $payload = array_merge($payload, $extra);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metodo nao permitido. Use POST.');
}

// Validacao da chave de acesso
$providedKey = $_POST['api_key'] ?? $_POST['key'] ?? '';
if ($API_ACCESS_KEY === '' || $API_ACCESS_KEY === 'defina-uma-chave-secreta') {
    jsonResponse(false, 'API_KEY nao configurada no servidor.');
}
if (!hash_equals($API_ACCESS_KEY, (string)$providedKey)) {
    jsonResponse(false, 'Chave de acesso invalida.');
}

$action = $_POST['action'] ?? '';
$action = trim($action);

$allowed = ['check_client', 'create_client'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Acao invalida.');
}

// ========== Acoes ==========

if ($action === 'check_client') {
    $numero = $_POST['numero'] ?? '';
    $usuarioLogin = trim($_POST['usuario'] ?? '');

    $numeroNormalizado = null;
    if ($numero !== '') {
        $numeroNormalizado = normalizarTelefoneBrasil($numero);
        if ($numeroNormalizado === false) {
            jsonResponse(false, 'Informe um telefone valido (Brasil).');
        }
    }

    if ($numeroNormalizado === null && $usuarioLogin === '') {
        jsonResponse(false, 'Informe telefone ou usuario para buscar.');
    }

    $where = [];
    $params = [];
    if ($numeroNormalizado !== null) {
        $where[] = 'telefone = ?';
        $params[] = $numeroNormalizado;
    }
    if ($usuarioLogin !== '') {
        $where[] = 'usuario = ?';
        $params[] = $usuarioLogin;
    }

    $sql = "
        SELECT id, nome, usuario, senha_app, provedor, provedor_usuario, provedor_senha, telefone, plano, valor_plano, quantidade_telas, aplicativo_usado, pagamento_tipo, cobranca_em, validade, observacoes, ativo, criado_em, atualizado_em
        FROM clientes
        WHERE " . implode(' OR ', $where) . "
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        jsonResponse(false, 'Cliente nao encontrado.');
    }

    $cliente['valor_plano'] = (float)($cliente['valor_plano'] ?? 0);
    $cliente['telefone_formatado'] = formatarTelefoneParaExibicao($cliente['telefone'] ?? null);

    jsonResponse(true, ['cliente' => $cliente]);
}

if ($action === 'create_client') {
    $nome      = trim($_POST['nome'] ?? '');
    $usuario   = trim($_POST['usuario'] ?? '');
    $senhaApp  = trim($_POST['senha_app'] ?? '');
    $telefone  = $_POST['telefone'] ?? '';
    $plano     = trim($_POST['plano'] ?? '');
    $valor     = (float)($_POST['valor_plano'] ?? 0);
    $provedor  = trim($_POST['provedor'] ?? '');
    $provedorUsuario = trim($_POST['provedor_usuario'] ?? '');
    $provedorSenha = trim($_POST['provedor_senha'] ?? '');
    $validade  = parseDateOrNull($_POST['validade'] ?? '');
    $telas     = (int)($_POST['quantidade_telas'] ?? 1);
    $appUsado  = trim($_POST['aplicativo_usado'] ?? '');
    $observ    = trim($_POST['observacoes'] ?? '');
    $pagamento = sanitizePagamentoTipo($_POST['pagamento_tipo'] ?? 'avista');
    $cobrancaEm = parseDateOrNull($_POST['cobranca_em'] ?? '');

    if ($nome === '' || $usuario === '' || $senhaApp === '') {
        jsonResponse(false, 'Informe nome, usuario e senha do app.');
    }

    $telNormalizado = normalizarTelefoneBrasil($telefone);
    if ($telNormalizado === false) {
        jsonResponse(false, 'Telefone invalido para Brasil.');
    }

    if ($telas < 1) {
        $telas = 1;
    }

    if ($telNormalizado !== null) {
        $stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefone = ? AND (excluido_em IS NULL) LIMIT 1");
        $stmt->execute([$telNormalizado]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'Ja existe um cliente com este telefone.');
        }
    }

    if ($pagamento === 'fiado' && !$cobrancaEm) {
        jsonResponse(false, 'Informe a data de cobranca para pagamento fiado.');
    }
    if ($pagamento !== 'fiado') {
        $cobrancaEm = null;
    }

    // Usuario unico
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE usuario = ? LIMIT 1");
    $stmt->execute([$usuario]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Ja existe um cliente com este usuario.');
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clientes (nome, usuario, senha_app, provedor, provedor_usuario, provedor_senha, telefone, plano, valor_plano, quantidade_telas, aplicativo_usado, validade, observacoes, pagamento_tipo, cobranca_em, ativo, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $nome,
            $usuario,
            $senhaApp,
            $provedor ?: null,
            $provedorUsuario ?: null,
            $provedorSenha ?: null,
            $telNormalizado,
            $plano ?: null,
            $valor,
            $telas,
            $appUsado ?: null,
            $validade,
            $observ ?: null,
            $pagamento,
            $cobrancaEm,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        jsonResponse(false, 'Nao foi possivel criar o cliente.');
    }

    registrarLogPlano($pdo, $newId, null, 'api_criacao', [
        'plano' => $plano ?: null,
        'valor_plano' => $valor,
        'validade' => $validade,
        'pagamento_tipo' => $pagamento,
        'cobranca_em' => $cobrancaEm,
        'observacao' => $observ ?: null,
    ]);

    jsonResponse(true, ['id' => $newId]);
}
