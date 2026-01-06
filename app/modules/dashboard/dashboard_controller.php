<?php
/**
 * Controller AJAX do dashboard.
 * Retorna dados consolidados para os cards.
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

$allowed = ['overview', 'calendar'];
if (!in_array($action, $allowed, true)) {
    jsonResponse(false, 'Acao invalida.');
}

ensureClientesTable($pdo);
ensureClientesPlanoLogs($pdo);

if ($action === 'overview') {
    $hoje = new DateTimeImmutable('today');

    // Mes para receita (renovacoes)
    $mesRefRaw = trim((string)($_GET['mes_ref'] ?? ''));
    $mesOptions = [];
    for ($i = 0; $i < 6; $i++) {
        $m = $hoje->modify("-{$i} months")->modify('first day of this month');
        $mesOptions[] = ['key' => $m->format('Y-m'), 'label' => $m->format('m/Y')];
    }
    $mesSelecionadoKey = $mesOptions[0]['key'] ?? $hoje->format('Y-m');
    foreach ($mesOptions as $opt) {
        if ($mesRefRaw === $opt['key']) {
            $mesSelecionadoKey = $opt['key'];
            break;
        }
    }
    $mesSelecionadoDate = DateTimeImmutable::createFromFormat('Y-m-d', $mesSelecionadoKey . '-01') ?: $hoje;
    $inicioMes = $mesSelecionadoDate->setTime(0, 0, 0);
    $fimMes = $mesSelecionadoDate->modify('last day of this month')->setTime(23, 59, 59);

    // Base atual
    $receitasBase = [
        'mensal' => 0,
        'ticket_medio' => 0,
        'fiado_aberto' => 0,
        'total_telas' => 0,
    ];
    try {
        $stmtRec = $pdo->query("
            SELECT
                SUM(CASE WHEN ativo = 1 AND (plano IS NULL OR plano = '' OR plano = 'assinatura') THEN valor_plano ELSE 0 END) AS mensal,
                AVG(CASE WHEN ativo = 1 AND valor_plano > 0 THEN valor_plano END) AS ticket_medio,
                SUM(CASE WHEN pagamento_tipo = 'fiado' THEN valor_plano ELSE 0 END) AS fiado_aberto,
                SUM(quantidade_telas) AS total_telas
            FROM clientes
            WHERE excluido_em IS NULL
        ");
        $receitasBase = array_merge($receitasBase, $stmtRec->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        // silencioso
    }

    // Renovacoes do mes selecionado
    $renovacoesMes = 0;
    try {
        $stmtRenov = $pdo->prepare("
            SELECT SUM(valor_plano) AS renovacoes_mes
            FROM clientes_planos_logs
            WHERE criado_em BETWEEN :inicio AND :fim
              AND (
                    acao_tipo = 'renovar'
                    OR (acao_tipo IS NULL AND acao LIKE 'renov%')
                  )
        ");
        $stmtRenov->execute([
            ':inicio' => $inicioMes->format('Y-m-d H:i:s'),
            ':fim' => $fimMes->format('Y-m-d H:i:s'),
        ]);
        $renovacoesMes = (float)($stmtRenov->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // silencioso
    }

    // Previsao de receita por validade futura
    $prevRefRaw = trim((string)($_GET['prev_ref'] ?? ''));
    $prevOptions = [];
    $previsaoSelecionada = null;
    $previsaoTotalFuturo = 0;
    try {
        $stmtPrev = $pdo->prepare("
            SELECT DATE_FORMAT(validade, '%Y-%m') AS mes, SUM(valor_plano) AS total
            FROM clientes
            WHERE excluido_em IS NULL
              AND ativo = 1
              AND validade IS NOT NULL
              AND validade >= :hoje
            GROUP BY mes
            ORDER BY mes ASC
            LIMIT 6
        ");
        $stmtPrev->execute([':hoje' => $hoje->format('Y-m-d')]);
        $rowsPrev = $stmtPrev->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rowsPrev as $r) {
            $key = $r['mes'] ?? '';
            $dt = DateTime::createFromFormat('Y-m', $key) ?: null;
            $label = $dt ? $dt->format('m/Y') : ($key ?: '--');
            $total = (float)($r['total'] ?? 0);
            $prevOptions[] = ['key' => $key, 'label' => $label, 'total' => $total];
            $previsaoTotalFuturo += $total;
        }
        if (!empty($prevOptions)) {
            foreach ($prevOptions as $opt) {
                if ($prevRefRaw && $opt['key'] === $prevRefRaw) {
                    $previsaoSelecionada = $opt;
                    break;
                }
            }
            if ($previsaoSelecionada === null) {
                $previsaoSelecionada = $prevOptions[0];
            }
        }
    } catch (Throwable $e) {
        // silencioso
    }

    jsonResponse(true, [
        'receita' => [
            'options' => $mesOptions,
            'selecionado' => [
                'key' => $mesSelecionadoKey,
                'label' => $mesSelecionadoDate->format('m/Y'),
                'valor' => $renovacoesMes,
            ],
            'base_atual' => (float)($receitasBase['mensal'] ?? 0),
            'ticket_medio' => (float)($receitasBase['ticket_medio'] ?? 0),
            'telas' => (int)($receitasBase['total_telas'] ?? 0),
        ],
        'previsao' => [
            'options' => $prevOptions,
            'selecionado' => $previsaoSelecionada,
            'total_futuro' => $previsaoTotalFuturo,
        ],
    ]);
}

if ($action === 'calendar') {
    $startRaw = trim((string)($_GET['start'] ?? ''));
    $endRaw = trim((string)($_GET['end'] ?? ''));

    $hoje = new DateTimeImmutable('today');
    $start = DateTimeImmutable::createFromFormat('Y-m-d', substr($startRaw, 0, 10)) ?: $hoje->modify('-15 days');
    $end = DateTimeImmutable::createFromFormat('Y-m-d', substr($endRaw, 0, 10)) ?: $hoje->modify('+60 days');

    $events = [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, nome, validade, valor_plano, pagamento_tipo, plano
            FROM clientes
            WHERE excluido_em IS NULL
              AND ativo = 1
              AND validade IS NOT NULL
              AND validade BETWEEN :inicio AND :fim
            ORDER BY validade ASC
            LIMIT 500
        ");
        $stmt->execute([
            ':inicio' => $start->format('Y-m-d'),
            ':fim' => $end->format('Y-m-d'),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $pagamento = strtolower((string)($row['pagamento_tipo'] ?? 'avista'));
            $color = $pagamento === 'fiado' ? '#f59f00' : '#198754';
            $events[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => trim(($row['nome'] ?? 'Cliente') . ' - R$ ' . number_format((float)($row['valor_plano'] ?? 0), 2, ',', '.')),
                'start' => $row['validade'],
                'allDay' => true,
                'color' => $color,
                'extendedProps' => [
                    'pagamento' => $pagamento,
                    'valor' => (float)($row['valor_plano'] ?? 0),
                    'plano' => $row['plano'] ?? null,
                ],
            ];
        }
    } catch (Throwable $e) {
        $events = [];
    }

    jsonResponse(true, ['events' => $events]);
}
