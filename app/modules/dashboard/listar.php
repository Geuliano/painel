<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';

$agendaHelperPath = __DIR__ . '/../agenda/agenda_helper.php';
$hasAgenda = is_file($agendaHelperPath);
if ($hasAgenda) {
    require_once $agendaHelperPath;
}
require_once __DIR__ . '/../clientes/clientes_helper.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($hasAgenda) {
    ensureAgendaTables($pdo);
}
ensureClientesTable($pdo);
ensureClientesPlanoLogs($pdo);

$hoje = new DateTimeImmutable('today');

$statusClientes = [
    'total' => 0,
    'ativos' => 0,
    'ativos_ok' => 0,
    'vencidos' => 0,
    'inativos' => 0,
    'assinatura' => 0,
    'teste' => 0,
    'fiado_qtd' => 0,
    'fiado_valor' => 0,
];

try {
    $stmtStatus = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
            SUM(CASE WHEN ativo = 1 AND (validade IS NULL OR validade >= :hoje) THEN 1 ELSE 0 END) AS ativos_ok,
            SUM(CASE WHEN ativo = 1 AND validade IS NOT NULL AND validade < :hoje THEN 1 ELSE 0 END) AS vencidos,
            SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) AS inativos,
            SUM(CASE WHEN plano = 'teste' THEN 1 ELSE 0 END) AS teste,
            SUM(CASE WHEN plano IS NULL OR plano = '' OR plano = 'assinatura' THEN 1 ELSE 0 END) AS assinatura,
            SUM(CASE WHEN pagamento_tipo = 'fiado' THEN 1 ELSE 0 END) AS fiado_qtd,
            SUM(CASE WHEN pagamento_tipo = 'fiado' THEN valor_plano ELSE 0 END) AS fiado_valor
        FROM clientes
        WHERE excluido_em IS NULL
    ");
    $stmtStatus->execute([':hoje' => $hoje->format('Y-m-d')]);
    $statusClientes = array_merge($statusClientes, $stmtStatus->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
    // silencioso para nao quebrar o dashboard
}

$prevRefRaw = trim((string)($_GET['prev_ref'] ?? ''));
$receitaPrevistaMeses = [];
$receitaPrevistaTotal = 0;
$receitaPrevistaSelecionada = null;
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
        $receitaPrevistaMeses[] = ['key' => $key, 'label' => $label, 'total' => $total];
        $receitaPrevistaTotal += $total;
    }
    if (!empty($receitaPrevistaMeses)) {
        foreach ($receitaPrevistaMeses as $item) {
            if ($prevRefRaw && $item['key'] === $prevRefRaw) {
                $receitaPrevistaSelecionada = $item;
                break;
            }
        }
        if ($receitaPrevistaSelecionada === null) {
            $receitaPrevistaSelecionada = $receitaPrevistaMeses[0];
        }
    }
} catch (Throwable $e) {
    // silencioso
}

$novosClientes = [
    'novos7' => 0,
    'novos30' => 0,
    'atualizados30' => 0,
];

try {
    $stmtNovos = $pdo->query("
        SELECT
            SUM(CASE WHEN criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS novos7,
            SUM(CASE WHEN criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS novos30,
            SUM(CASE WHEN atualizado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS atualizados30
        FROM clientes
        WHERE excluido_em IS NULL
    ");
    $novosClientes = array_merge($novosClientes, $stmtNovos->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
    // silencioso
}

$receitas = [
    'mensal' => 0,
    'ticket_medio' => 0,
    'fiado_aberto' => 0,
    'total_telas' => 0,
    'renovacoes_mes' => 0,
];

$mesRefRaw = trim((string)($_GET['mes_ref'] ?? ''));
$mesSelecionado = $hoje->modify('first day of this month');
if (preg_match('/^\\d{4}-\\d{2}$/', $mesRefRaw)) {
    $tmpMes = DateTimeImmutable::createFromFormat('Y-m-d', $mesRefRaw . '-01');
    if ($tmpMes) {
        $mesSelecionado = $tmpMes;
    }
}
$mesSelecionadoLabel = $mesSelecionado->format('m/Y');
$mesOpcoes = [];
for ($i = 0; $i < 6; $i++) {
    $mesOpcoes[] = $hoje->modify("-{$i} months")->modify('first day of this month');
}

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
    $receitas = array_merge($receitas, $stmtRec->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
    // silencioso
}

try {
    $inicioMes = $mesSelecionado->setTime(0, 0, 0);
    $fimMes = $mesSelecionado->modify('last day of this month')->setTime(23, 59, 59);

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
    $receitas['renovacoes_mes'] = (float)($stmtRenov->fetchColumn() ?: 0);
} catch (Throwable $e) {
    // silencioso
}

$proxVencerLista = [];
try {
    $stmtLista = $pdo->prepare("
        SELECT id, nome, validade, valor_plano, pagamento_tipo, plano, ativo
        FROM clientes
        WHERE excluido_em IS NULL AND ativo = 1 AND validade IS NOT NULL AND validade >= :hoje
        ORDER BY validade ASC
        LIMIT 8
    ");
    $stmtLista->execute([':hoje' => $hoje->format('Y-m-d')]);
    $proxVencerLista = $stmtLista->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $proxVencerLista = [];
}

$vencidosLista = [];
try {
    $stmtVencidos = $pdo->prepare("
        SELECT id, nome, validade, valor_plano, pagamento_tipo, plano, ativo
        FROM clientes
        WHERE excluido_em IS NULL AND ativo = 1 AND validade IS NOT NULL AND validade < :hoje
        ORDER BY validade DESC
        LIMIT 10
    ");
    $stmtVencidos->execute([':hoje' => $hoje->format('Y-m-d')]);
    $vencidosLista = $stmtVencidos->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $vencidosLista = [];
}

$fiadoLista = [];
try {
    $stmtFiado = $pdo->prepare("
        SELECT id, nome, cobranca_em, valor_plano, validade, plano
        FROM clientes
        WHERE excluido_em IS NULL AND pagamento_tipo = 'fiado'
        ORDER BY (cobranca_em IS NULL) ASC, cobranca_em ASC
        LIMIT 8
    ");
    $stmtFiado->execute();
    $fiadoLista = $stmtFiado->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $fiadoLista = [];
}

$logsPlano = [];
try {
    $stmtLogs = $pdo->query("
        SELECT l.acao, l.valor_plano, l.validade, l.pagamento_tipo, l.cobranca_em, l.criado_em, c.nome AS cliente_nome
        FROM clientes_planos_logs l
        JOIN clientes c ON c.id = l.cliente_id
        WHERE c.excluido_em IS NULL
        ORDER BY l.criado_em DESC
        LIMIT 10
    ");
    $logsPlano = $stmtLogs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $logsPlano = [];
}

$fmtInt = fn($v) => number_format((float)$v, 0, ',', '.');
$fmtMoney = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$canViewAll = false;
$usuariosAcessoIds = [];
$usuariosFiltro = [];

if ($hasAgenda) {
    $canViewAll = agendaCanViewAll($usuarioId);
    $usuariosAcessoIds = agendaOwnersAccessibleByUser($pdo, $usuarioId);

    if ($canViewAll) {
        try {
            $stmt = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome ASC");
            $usuariosFiltro = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $usuariosFiltro = [];
        }
    } else {
        $allowedOwners = array_values(array_unique(array_merge([$usuarioId], $usuariosAcessoIds)));
        if ($allowedOwners) {
            $placeholders = implode(',', array_fill(0, count($allowedOwners), '?'));
            try {
                $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id IN ($placeholders) ORDER BY nome ASC");
                $stmt->execute($allowedOwners);
                $usuariosFiltro = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $usuariosFiltro = [];
            }
        }
    }
}

$canFilterShared = count($usuariosFiltro) > 1;

$configUsuario = [
    'hora_inicio' => '08:00',
    'hora_fim' => '18:00',
];

try {
    if ($hasAgenda) {
        $stmtCfg = $pdo->prepare("
            SELECT hora_inicio, hora_fim
            FROM agenda_config
            WHERE id_usuario = ?
            LIMIT 1
        ");
        $stmtCfg->execute([$usuarioId]);
        if ($row = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
            $configUsuario['hora_inicio'] = substr($row['hora_inicio'] ?? $configUsuario['hora_inicio'], 0, 5);
            $configUsuario['hora_fim'] = substr($row['hora_fim'] ?? $configUsuario['hora_fim'], 0, 5);
        }
    }
} catch (Throwable $e) {
    // mantém valores padrão silenciosamente
}

$agendaEndpoint = BASE_URL . 'app/modules/agenda/agenda_controller.php';
?>

<style>
    .dash-card {
        --dash-border: var(--bs-border-color, rgba(0,0,0,0.08));
        --dash-surface: var(--bs-card-bg, var(--bs-body-bg));
        border-radius: 12px;
        border: 1px solid var(--dash-border);
        box-shadow: 0 12px 30px rgba(0,0,0,0.06);
        background: var(--dash-surface);
        position: relative;
        overflow: hidden;
    }

    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
    }

    .metric-card {
        --metric-surface: var(--bs-card-bg, var(--bs-body-bg));
        border-radius: 12px;
        border: 1px solid var(--bs-border-color, rgba(0,0,0,0.08));
        padding: 12px 14px;
        background: linear-gradient(135deg, rgba(49,103,243,0.10), rgba(49,103,243,0.02)), var(--metric-surface);
    }

    .metric-title {
        font-size: 0.9rem;
        color: var(--bs-secondary-color, #6c757d);
    }

    .metric-value {
        font-size: 1.6rem;
        font-weight: 700;
    }

    .metric-sub {
        color: var(--bs-secondary-color, #6c757d);
        font-size: 0.9rem;
    }

    .list-tile {
        border: 1px solid color-mix(in srgb, var(--bs-primary, #0d6efd) 14%, var(--bs-border-color, rgba(0,0,0,0.08)));
        border-radius: 10px;
        padding: 12px;
        background: linear-gradient(135deg, rgba(13,110,253,0.08), rgba(13,110,253,0.02)), var(--bs-card-bg, var(--bs-body-bg));
        color: var(--bs-body-color, #1f2d3d);
        box-shadow: 0 8px 18px rgba(0,0,0,0.05);
        min-height: 90px;
    }

    .list-tile:last-child {
        margin-bottom: 0;
    }

    .section-grid {
        margin-left: 0;
        margin-right: 0;
    }
    .section-grid > [class*="col-"] {
        padding-left: 10px;
        padding-right: 10px;
    }
    .section-grid .list-tile {
        margin-bottom: 12px;
    }

    .tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .tag-green { background: color-mix(in srgb, var(--bs-success) 18%, transparent); color: var(--bs-success-text, #13653f); }
    .tag-red { background: color-mix(in srgb, var(--bs-danger) 18%, transparent); color: var(--bs-danger-text, #b02a37); }
    .tag-amber { background: color-mix(in srgb, var(--bs-warning) 18%, transparent); color: #b26a00; }
    .tag-blue { background: color-mix(in srgb, var(--bs-primary) 18%, transparent); color: var(--bs-primary); }

    .dash-toolbar {
        gap: 12px;
    }

    #dashAgendaAlert {
        position: relative;
        z-index: 10;
    }

    .kanban-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .kanban-column {
        background: var(--bs-card-bg, var(--bs-body-bg));
        border: 1px solid var(--bs-border-color, rgba(0,0,0,0.06));
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        min-height: 260px;
    }

    .kanban-header {
        padding: 10px 12px;
        background: linear-gradient(135deg, rgba(49,103,243,0.12), rgba(49,103,243,0.02));
        border-bottom: 1px solid var(--bs-border-color, rgba(0,0,0,0.05));
    }

    .kanban-day-label {
        font-weight: 600;
        font-size: 0.95rem;
    }

    .kanban-date {
        color: #6c757d;
        font-size: 0.85rem;
    }

    .kanban-body {
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
    }

    .kanban-empty {
        color: var(--bs-secondary-color, #adb5bd);
        font-size: 0.9rem;
        text-align: center;
        padding: 12px 6px;
        border: 1px dashed var(--bs-border-color, rgba(0,0,0,0.08));
        border-radius: 8px;
    }

    .event-card {
        border: 1px solid var(--bs-border-color, rgba(0,0,0,0.07));
        border-left: 4px solid var(--event-color, #0d6efd);
        border-radius: 8px;
        padding: 10px;
        background: var(--bs-card-bg, var(--bs-body-bg));
        box-shadow: 0 6px 16px rgba(0,0,0,0.05);
    }

    .event-card .title {
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 4px;
    }

    .event-card .meta {
        color: #6c757d;
        font-size: 0.83rem;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .event-chip {
        border-radius: 6px;
        padding: 3px 8px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .chip-urgente {
        background: color-mix(in srgb, var(--bs-warning) 18%, transparent);
        color: var(--bs-warning-text, #664d03);
    }
    .chip-cancelado {
        background: color-mix(in srgb, var(--bs-danger) 18%, transparent);
        color: var(--bs-danger-text, #842029);
        text-decoration: line-through;
    }
    .chip-normal {
        background: color-mix(in srgb, var(--bs-secondary) 18%, transparent);
        color: var(--bs-secondary-color, #343a40);
    }
</style>

<div class="card dash-card mb-4">
    <div class="card-body">
        <div class="metrics-grid mb-3">
            <div class="metric-card">
                <div class="metric-title">Clientes</div>
                <div class="metric-value"><?= $fmtInt($statusClientes['total'] ?? 0) ?></div>
                <div class="metric-sub">
                    Ativos: <?= $fmtInt($statusClientes['ativos_ok'] ?? 0) ?> |
                    Vencidos: <?= $fmtInt($statusClientes['vencidos'] ?? 0) ?> |
                    Inativos: <?= $fmtInt($statusClientes['inativos'] ?? 0) ?>
                </div>
                <div class="metric-sub">
                    Assinatura: <?= $fmtInt($statusClientes['assinatura'] ?? 0) ?> |
                    Teste: <?= $fmtInt($statusClientes['teste'] ?? 0) ?>
                </div>
            </div>
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-title mb-0">Previsao receita (vencimentos)</div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="prevMesBtn">
                            --
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" id="prevMesMenu">
                            <li class="dropdown-header">Escolha o mes</li>
                        </ul>
                    </div>
                </div>
                <div class="metric-value" id="prevValor">--</div>
                <div class="metric-sub" id="prevLabel">Referencia: --</div>
                <div class="metric-sub" id="prevTotalFuturo">Total previsto proximos meses: --</div>
            </div>
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-title mb-0">Receita estimada (renovacoes)</div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="receitaMesBtn">
                            --
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" id="receitaMesMenu">
                            <li class="dropdown-header">Escolha o mes</li>
                        </ul>
                    </div>
                </div>
                <div class="metric-value" id="receitaValor">--</div>
                <div class="metric-sub" id="receitaLabel">Referencia: --</div>
                <div class="metric-sub" id="receitaBase">Base atual (assinaturas): --</div>
                <div class="metric-sub" id="receitaDetalhes">Ticket medio: -- | Telas: --</div>
            </div>
        </div>

        <div class="metric-card">
                <div class="metric-title">Fiado e base recente</div>
                <div class="metric-value"><?= $fmtMoney($receitas['fiado_aberto'] ?? 0) ?></div>
                <div class="metric-sub">Registros fiado: <?= $fmtInt($statusClientes['fiado_qtd'] ?? 0) ?></div>
                <div class="metric-sub">Novos 30 dias: <?= $fmtInt($novosClientes['novos30'] ?? 0) ?> | Atualizados 30 dias: <?= $fmtInt($novosClientes['atualizados30'] ?? 0) ?></div>
            </div>
        </div>

        <div style="padding: var(--bs-card-spacer-y) var(--bs-card-spacer-x);" class="row g-3 section-grid">
            <div class="col-lg-4" >
                <div class="h6 mb-2 d-flex align-items-center gap-2">
                    <i class="las la-hourglass-half text-warning" ></i> Proximos a vencer</div>
                <?php if (empty($proxVencerLista)): ?>
                    <div class="list-tile text-muted">Nenhum cliente com validade preenchida.</div>
                <?php else: ?>
                    <?php foreach ($proxVencerLista as $c): ?>
                        <div class="list-tile">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($c['nome']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars(ucfirst($c['plano'] ?: 'assinatura')) ?> | <?= htmlspecialchars(ucfirst($c['pagamento_tipo'] ?? 'avista')) ?></div>
                                </div>
                                <div class="tag <?= ($c['validade'] ?? '') < $hoje->format('Y-m-d') ? 'tag-red' : 'tag-amber' ?>">
                                    <i class="las la-calendar-alt"></i>
                                    <?= $c['validade'] ? (new DateTime($c['validade']))->format('d/m') : '--' ?>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">Valor: <?= $fmtMoney($c['valor_plano'] ?? 0) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="h6 mb-2 d-flex align-items-center gap-2">
                    <i class="las la-ban text-danger"></i> Ultimos vencidos
                </div>
                <?php if (empty($vencidosLista)): ?>
                    <div class="list-tile text-muted">Nenhum vencido recente.</div>
                <?php else: ?>
                    <?php foreach ($vencidosLista as $v): ?>
                        <div class="list-tile">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($v['nome']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars(ucfirst($v['plano'] ?: 'assinatura')) ?> | <?= htmlspecialchars(ucfirst($v['pagamento_tipo'] ?? 'avista')) ?></div>
                                </div>
                                <div class="tag tag-red">
                                    <i class="las la-calendar-times"></i>
                                    <?= $v['validade'] ? (new DateTime($v['validade']))->format('d/m') : '--' ?>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">Valor: <?= $fmtMoney($v['valor_plano'] ?? 0) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="h6 mb-2 d-flex align-items-center gap-2">
                    <i class="las la-wallet text-success"></i> Cobrancas fiado
                </div>
                <?php if (empty($fiadoLista)): ?>
                    <div class="list-tile text-muted">Nenhum fiado cadastrado.</div>
                <?php else: ?>
                    <?php foreach ($fiadoLista as $f): ?>
                        <div class="list-tile">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($f['nome']) ?></div>
                                    <div class="small text-muted">Plano: <?= htmlspecialchars(ucfirst($f['plano'] ?: 'assinatura')) ?></div>
                                </div>
                                <div class="tag <?= ($f['cobranca_em'] ?? '') && ($f['cobranca_em'] < $hoje->format('Y-m-d')) ? 'tag-red' : 'tag-green' ?>">
                                    <i class="las la-bell"></i>
                                    <?= $f['cobranca_em'] ? (new DateTime($f['cobranca_em']))->format('d/m') : 'Sem data' ?>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">Valor: <?= $fmtMoney($f['valor_plano'] ?? 0) ?> | Validade: <?= $f['validade'] ? (new DateTime($f['validade']))->format('d/m') : '--' ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3" style="padding: var(--bs-card-spacer-y) var(--bs-card-spacer-x);">
            <div class="h6 mb-2 d-flex align-items-center gap-2" >
                <i class="las la-history text-primary"></i> Ultimos movimentos de planos
            </div>
            <?php if (empty($logsPlano)): ?>
                <div class="list-tile text-muted">Nenhum log registrado.</div>
            <?php else: ?>
                <div class="row g-2 section-grid">
                    <?php foreach ($logsPlano as $log): ?>
                        <div class="col-md-6">
                            <div class="list-tile">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($log['cliente_nome'] ?? 'Cliente') ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($log['acao'] ?? 'Movimento') ?></div>
                                    </div>
                                    <div class="tag tag-blue">
                                        <i class="las la-clock"></i>
                                        <?= $log['criado_em'] ? (new DateTime($log['criado_em']))->format('d/m H:i') : '--' ?>
                                    </div>
                                </div>
                                <div class="small text-muted mt-1">Valor: <?= $fmtMoney($log['valor_plano'] ?? 0) ?> | Validade: <?= $log['validade'] ? (new DateTime($log['validade']))->format('d/m') : '--' ?></div>
                                <div class="small text-muted">Pagamento: <?= htmlspecialchars(strtoupper($log['pagamento_tipo'] ?? 'AVISTA')) ?><?= $log['cobranca_em'] ? ' - Cobranca: ' . (new DateTime($log['cobranca_em']))->format('d/m') : '' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(() => {
    const dashControllerUrl = '<?= BASE_URL ?>app/modules/dashboard/dashboard_controller.php';
    let currentMesRef = '';
    let currentPrevRef = '';

    const fmtMoney = (val) => {
        const n = Number(val || 0);
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    };

    function clearDynamic(menu) {
        menu.querySelectorAll('li[data-item="dynamic"]').forEach((el) => el.remove());
    }

    function buildDropdown(menuId, btnId, options, currentKey, target) {
        const menu = document.getElementById(menuId);
        const btn = document.getElementById(btnId);
        if (!menu || !btn) return;
        clearDynamic(menu);
        if (!options || !options.length) {
            btn.disabled = true;
            btn.textContent = '--';
            return;
        }
        btn.disabled = false;
        options.forEach((opt) => {
            const li = document.createElement('li');
            li.dataset.item = 'dynamic';
            const a = document.createElement('a');
            a.className = 'dropdown-item';
            if ((opt.key || '') === currentKey) a.classList.add('active');
            a.href = '#';
            a.dataset.key = opt.key || '';
            a.dataset.target = target;
            a.textContent = opt.label || '--';
            a.addEventListener('click', (ev) => {
                ev.preventDefault();
                const key = a.dataset.key || '';
                if (target === 'receita') {
                    currentMesRef = key;
                    loadDashboardCards({ mesRef: key, prevRef: currentPrevRef });
                } else {
                    currentPrevRef = key;
                    loadDashboardCards({ mesRef: currentMesRef, prevRef: key });
                }
            });
            li.appendChild(a);
            menu.appendChild(li);
        });
    }

    function renderReceita(data = {}) {
        const receita = data.receita || {};
        const selecionado = receita.selecionado || {};
        currentMesRef = selecionado.key || '';

        const receitValorEl = document.getElementById('receitaValor');
        const receitLabelEl = document.getElementById('receitaLabel');
        const receitBaseEl = document.getElementById('receitaBase');
        const receitDetEl = document.getElementById('receitaDetalhes');
        const receitBtn = document.getElementById('receitaMesBtn');

        if (receitValorEl) receitValorEl.textContent = fmtMoney(selecionado.valor);
        if (receitLabelEl) receitLabelEl.textContent = `Referencia: ${selecionado.label || '--'}`;
        if (receitBaseEl) receitBaseEl.textContent = `Base atual (assinaturas): ${fmtMoney(receita.base_atual || 0)}`;
        if (receitDetEl) receitDetEl.textContent = `Ticket medio: ${fmtMoney(receita.ticket_medio || 0)} | Telas: ${receita.telas ?? '--'}`;
        if (receitBtn) receitBtn.textContent = selecionado.label || '--';

        buildDropdown('receitaMesMenu', 'receitaMesBtn', receita.options || [], currentMesRef, 'receita');
    }

    function renderPrevisao(data = {}) {
        const previsao = data.previsao || {};
        const selecionado = previsao.selecionado || {};
        currentPrevRef = selecionado.key || '';

        const prevValEl = document.getElementById('prevValor');
        const prevLabelEl = document.getElementById('prevLabel');
        const prevTotalEl = document.getElementById('prevTotalFuturo');
        const prevBtn = document.getElementById('prevMesBtn');

        if (prevValEl) prevValEl.textContent = fmtMoney(selecionado.total || 0);
        if (prevLabelEl) prevLabelEl.textContent = `Referencia: ${selecionado.label || '--'}`;
        if (prevTotalEl) prevTotalEl.textContent = `Total previsto proximos meses: ${fmtMoney(previsao.total_futuro || 0)}`;
        if (prevBtn) prevBtn.textContent = selecionado.label || '--';

        buildDropdown('prevMesMenu', 'prevMesBtn', previsao.options || [], currentPrevRef, 'previsao');
    }

    function loadDashboardCards(params = {}) {
        const url = new URL(dashControllerUrl, window.location.origin);
        url.searchParams.set('action', 'overview');
        if (params.mesRef) url.searchParams.set('mes_ref', params.mesRef);
        if (params.prevRef) url.searchParams.set('prev_ref', params.prevRef);

        fetch(url.toString(), { credentials: 'same-origin' })
            .then((resp) => resp.json())
            .then((resp) => {
                if (!resp || resp.success === false) {
                    console.warn((resp && resp.message) || 'Falha ao carregar dados do dashboard.');
                    return;
                }
                renderReceita(resp);
                renderPrevisao(resp);
            })
            .catch(() => {
                console.warn('Erro de comunicacao com o dashboard_controller.');
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadDashboardCards();

    });
})();
</script>
<?php if ($hasAgenda): ?>
<div class="card dash-card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between dash-toolbar">
        <div class="d-flex align-items-center gap-3">
            <div>
                <div class="fw-semibold">Agenda dos próximos 7 dias</div>
                <div class="text-muted small">Visualização em colunas (kanban por dia)</div>
            </div>
            <?php if ($canFilterShared): ?>
                <div>
                    <label for="dashAgendaUsuario" class="form-label small mb-1 text-muted">Agenda</label>
                    <select id="dashAgendaUsuario" class="form-select form-select-sm">
                        <?php foreach ($usuariosFiltro as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $usuarioId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" id="dashAgendaToday">
                <i class="las la-calendar-day me-1"></i> Hoje
            </button>
            <button class="btn btn-outline-primary btn-sm" id="dashAgendaRefresh">
                <i class="las la-sync-alt me-1"></i> Atualizar
            </button>
        </div>
    </div>
    <div class="card-body">
        <div id="dashAgendaAlert" class="d-none"></div>
        <div class="kanban-grid" id="kanbanGrid"></div>
    </div>
</div>

<script>
(() => {
    const agendaEndpoint = '<?= $agendaEndpoint ?>';
    const canViewAll = <?= $canViewAll ? 'true' : 'false' ?>;
    const canFilterShared = <?= $canFilterShared ? 'true' : 'false' ?>;
    const horaInicioPadrao = '<?= $configUsuario['hora_inicio'] ?>';
    const horaFimPadrao = '<?= $configUsuario['hora_fim'] ?>';

    const grid = document.getElementById('kanbanGrid');
    const filtroUsuario = document.getElementById('dashAgendaUsuario');
    const btnRefresh = document.getElementById('dashAgendaRefresh');
    const btnToday = document.getElementById('dashAgendaToday');
    const alertBox = document.getElementById('dashAgendaAlert');

    function showAlert(message, type = 'danger') {
        if (!alertBox) return;
        alertBox.className = `alert alert-${type}`;
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
    }

    function clearAlert() {
        if (!alertBox) return;
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
    }

    function toDateParts(date) {
        return {
            iso: date.toISOString().slice(0, 10),
            label: date.toLocaleDateString('pt-BR', { weekday: 'short' }),
            full: date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })
        };
    }

    function buildDays(startDate) {
        const days = [];
        for (let i = 0; i < 7; i++) {
            const d = new Date(startDate);
            d.setHours(0, 0, 0, 0);
            d.setDate(d.getDate() + i);
            days.push(toDateParts(d));
        }
        return days;
    }

    function parseDateTime(value) {
        if (!value) return null;
        const normalized = value.includes('T') ? value : value.replace(' ', 'T');
        const dt = new Date(normalized);
        return Number.isNaN(dt.getTime()) ? null : dt;
    }

    function overlapsDay(evStart, evEnd, dayIso) {
        const dayStart = new Date(`${dayIso}T00:00:00`);
        const dayEnd = new Date(`${dayIso}T23:59:59`);
        return evStart < dayEnd && evEnd > dayStart;
    }

    function formatTime(dt) {
        if (!dt) return '';
        return dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function renderColumns(days, events) {
        if (!grid) return;
        const dayMap = days.map(day => {
            const evs = events.filter(ev => {
                const evStart = parseDateTime(ev.start);
                const evEnd = parseDateTime(ev.end) || evStart;
                if (!evStart || !evEnd) return false;
                return overlapsDay(evStart, evEnd, day.iso);
            });
            return { day, events: evs };
        });

        let html = '';
        dayMap.forEach(({ day, events: evs }) => {
            html += `
                <div class="kanban-column">
                    <div class="kanban-header">
                        <div class="kanban-day-label text-capitalize">${day.label}</div>
                        <div class="kanban-date">${day.full}</div>
                    </div>
                    <div class="kanban-body">
                        ${evs.length === 0 ? `<div class="kanban-empty">Sem agendamentos</div>` : evs.map(renderEventCard).join('')}
                    </div>
                </div>
            `;
        });

        grid.innerHTML = html;
    }

    function renderEventCard(ev) {
        const props = ev.extendedProps || {};
        const start = parseDateTime(ev.start);
        const end = parseDateTime(ev.end) || start;
        const hora = ev.allDay ? 'Dia inteiro' : `${formatTime(start)}${end ? ' - ' + formatTime(end) : ''}`;
        const dono = (canViewAll || canFilterShared) && props.usuario_nome ? ` | ${props.usuario_nome}` : '';

        let chipClass = 'chip-normal';
        let color = '#0d6efd';
        if (props.urgencia === 'urgente') {
            chipClass = 'chip-urgente';
            color = '#ffc107';
        }
        if (props.status === 'cancelado') {
            chipClass = 'chip-cancelado';
            color = '#dc3545';
        }

        return `
            <div class="event-card" style="--event-color:${color}">
                <div class="title">${ev.title || '(sem título)'}</div>
                <div class="meta">
                    <span>${hora}${dono}</span>
                    <span class="event-chip ${chipClass}">${props.status === 'cancelado' ? 'Cancelado' : (props.urgencia === 'urgente' ? 'Urgente' : 'Normal')}</span>
                </div>
            </div>
        `;
    }

    function fetchEvents(days) {
        clearAlert();
        const start = days[0].iso + 'T' + horaInicioPadrao;
        const endDate = new Date(days[days.length - 1].iso);
        endDate.setHours(23, 59, 59, 0);
        const end = endDate.toISOString().slice(0, 19);

        const params = new URLSearchParams({
            action: 'list',
            start,
            end,
            status: 'ativos'
        });
        if ((canViewAll || canFilterShared) && filtroUsuario && filtroUsuario.value) {
            params.append('user_id', filtroUsuario.value);
        }

        return fetch(`${agendaEndpoint}?${params.toString()}`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showAlert(data.message || 'Não foi possível carregar os agendamentos.');
                    return [];
                }
                return data.events || [];
            })
            .catch(() => {
                showAlert('Erro de comunicação ao carregar agendamentos.');
                return [];
            });
    }

    function load(days) {
        fetchEvents(days).then(events => {
            renderColumns(days, events);
        });
    }

    function start(fromDate = new Date()) {
        fromDate.setHours(0, 0, 0, 0);
        const days = buildDays(fromDate);
        load(days);
    }

    if (btnRefresh) {
        btnRefresh.addEventListener('click', () => start());
    }

    if (btnToday) {
        btnToday.addEventListener('click', () => start());
    }

    if (filtroUsuario) {
        filtroUsuario.addEventListener('change', () => start());
    }

    start();
})();
</script>
<?php endif; ?>
