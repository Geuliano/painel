<?php
/**
 * Controller do calendario de vencimentos.
 * Retorna eventos para o FullCalendar com base na validade dos clientes.
 */
ob_start();

$appPath = dirname(__DIR__, 2);
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php';
require_once __DIR__ . '/../clientes/clientes_helper.php';

/**
 * Resposta JSON padrao.
 */
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

if (!isLoggedIn()) {
    jsonResponse(false, 'Sessao expirada.');
}

if (!isset($pdo)) {
    jsonResponse(false, 'Conexao com o banco indisponivel.');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = trim((string)$action);

if ($action !== 'events') {
    jsonResponse(false, 'Acao invalida.');
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$moduloSlug = 'calendario';

if (!checkActionPermission($usuarioId, $moduloSlug, 'listar')) {
    jsonResponse(false, 'Voce nao tem permissao para acessar este calendario.');
}

ensureClientesTable($pdo);

$hoje = new DateTimeImmutable('today');
$startRaw = substr((string)($_GET['start'] ?? ''), 0, 10);
$endRaw = substr((string)($_GET['end'] ?? ''), 0, 10);

$inicio = DateTimeImmutable::createFromFormat('Y-m-d', $startRaw) ?: $hoje->modify('-1 month');
$fim = DateTimeImmutable::createFromFormat('Y-m-d', $endRaw) ?: $hoje->modify('+2 months');

if ($fim <= $inicio) {
    $fim = $inicio->modify('+2 months');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            nome,
            validade,
            telefone,
            plano,
            valor_plano,
            ativo,
            pagamento_tipo,
            quantidade_telas,
            provedor
        FROM clientes
        WHERE validade IS NOT NULL
          AND excluido_em IS NULL
          AND validade BETWEEN :inicio AND :fim
        ORDER BY validade ASC, nome ASC
        LIMIT 1000
    ");
    $stmt->execute([
        ':inicio' => $inicio->format('Y-m-d'),
        ':fim' => $fim->format('Y-m-d'),
    ]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    jsonResponse(false, 'Falha ao carregar vencimentos.');
}

$eventos = [];
$limiteAviso = $hoje->modify('+7 days');

foreach ($clientes as $c) {
    $validade = DateTimeImmutable::createFromFormat('Y-m-d', (string)($c['validade'] ?? ''));
    if (!$validade) {
        continue;
    }

    $status = 'Em dia';
    $cor = '#0d6efd'; // primary
    $textColor = '#fff';

    $ativo = (int)($c['ativo'] ?? 0) === 1;

    if (!$ativo) {
        $status = 'Inativo';
        $cor = '#6c757d';
    } elseif ($validade < $hoje) {
        $status = 'Vencido';
        $cor = '#dc3545';
    } elseif ($validade <= $limiteAviso) {
        $status = 'Vence em breve';
        $cor = '#ffc107';
        $textColor = '#111';
    }

    $valorPlano = $c['valor_plano'] ?? null;
    $valorPlano = is_numeric($valorPlano) ? (float)$valorPlano : null;

    $eventos[] = [
        'id' => 'cliente-' . (int)$c['id'],
        'title' => $c['nome'] ?: 'Cliente sem nome',
        'start' => $validade->format('Y-m-d'),
        'allDay' => true,
        'backgroundColor' => $cor,
        'borderColor' => $cor,
        'textColor' => $textColor,
        'extendedProps' => [
            'cliente_id' => (int)$c['id'],
            'status' => $status,
            'telefone' => formatarTelefoneParaExibicao($c['telefone'] ?? null),
            'plano' => $c['plano'] ?? null,
            'valor_plano' => $valorPlano,
            'pagamento_tipo' => $c['pagamento_tipo'] ?? null,
            'quantidade_telas' => $c['quantidade_telas'] !== null ? (int)$c['quantidade_telas'] : null,
            'provedor' => $c['provedor'] ?? null,
            'validade' => $validade->format('Y-m-d'),
        ],
    ];
}

jsonResponse(true, ['events' => $eventos]);
