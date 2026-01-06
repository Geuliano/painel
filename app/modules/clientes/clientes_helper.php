<?php

/**
 * Helper do modulo clientes.
 * - Garante a existencia da tabela necessaria.
 * - Normaliza/valida telefone no padrao brasileiro (DDI 55).
 */

function ensureClientesTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $sql = "
        CREATE TABLE IF NOT EXISTS clientes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(160) NOT NULL,
            provedor VARCHAR(160) DEFAULT NULL,
            provedor_usuario VARCHAR(120) DEFAULT NULL,
            provedor_senha VARCHAR(120) DEFAULT NULL,
            telefone VARCHAR(20) DEFAULT NULL,
            plano VARCHAR(160) DEFAULT NULL,
            valor_plano DECIMAL(10,2) DEFAULT 0,
            validade DATE DEFAULT NULL,
            observacoes TEXT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX (validade)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // falha silenciosa: evita quebrar a listagem se sem permissao DDL
    }

    // Campos novos adicionados gradualmente
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN quantidade_telas INT DEFAULT 1");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN aplicativo_usado VARCHAR(160) DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN pagamento_tipo VARCHAR(20) DEFAULT 'avista'");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN cobranca_em DATE DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN provedor VARCHAR(160) DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN provedor_usuario VARCHAR(120) DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN provedor_senha VARCHAR(120) DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN excluido_em DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }

    ensureClientesPlanoLogs($pdo);
}


/**
 * Normaliza telefone para DDI 55. Retorna string, null (se vazio) ou false se invalido.
 */
function normalizarTelefoneBrasil($telefone)
{
    $apenasDigitos = preg_replace('/\\D+/', '', (string)$telefone);
    if ($apenasDigitos === '') {
        return null;
    }

    $apenasDigitos = ltrim($apenasDigitos, '0');

    if (strpos($apenasDigitos, '55') !== 0) {
        $apenasDigitos = '55' . $apenasDigitos;
    }

    $len = strlen($apenasDigitos);
    if ($len < 12 || $len > 13) {
        return false;
    }

    return $apenasDigitos;
}

/**
 * Formata telefone com DDI 55 para exibicao amigavel.
 */
function formatarTelefoneParaExibicao(?string $telefone): ?string
{
    if (!$telefone) {
        return null;
    }
    $numeros = preg_replace('/\\D+/', '', $telefone);
    if (strpos($numeros, '55') !== 0) {
        return $telefone;
    }
    $semDdi = substr($numeros, 2);
    if (strlen($semDdi) === 10) {
        return sprintf('+55 (%s) %s-%s', substr($semDdi, 0, 2), substr($semDdi, 2, 4), substr($semDdi, 6));
    }
    if (strlen($semDdi) === 11) {
        return sprintf('+55 (%s) %s %s-%s', substr($semDdi, 0, 2), substr($semDdi, 2, 1), substr($semDdi, 3, 4), substr($semDdi, 7));
    }
    return '+55 ' . $semDdi;
}

function ensureClientesPlanoLogs(PDO $pdo): void
{
    static $logsChecked = false;
    if ($logsChecked) {
        return;
    }
    $logsChecked = true;

    $sql = "
        CREATE TABLE IF NOT EXISTS clientes_planos_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED DEFAULT NULL,
            acao_tipo VARCHAR(30) DEFAULT NULL,
            acao VARCHAR(40) DEFAULT 'lancamento',
            plano VARCHAR(160) DEFAULT NULL,
            valor_plano DECIMAL(10,2) DEFAULT 0,
            validade DATE DEFAULT NULL,
            pagamento_tipo VARCHAR(20) DEFAULT 'avista',
            cobranca_em DATE DEFAULT NULL,
            observacao TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (cliente_id),
            INDEX (acao_tipo),
            INDEX (validade),
            INDEX (cobranca_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // silencioso para evitar quebrar fluxo
    }

    // Campos adicionados depois
    try {
        $pdo->exec("ALTER TABLE clientes_planos_logs ADD COLUMN acao_tipo VARCHAR(30) DEFAULT NULL");
    } catch (Throwable $e) {
        // coluna ja existe ou sem permissao: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE clientes_planos_logs ADD INDEX idx_acao_tipo (acao_tipo)");
    } catch (Throwable $e) {
        // indice ja existe ou sem permissao: ignorar
    }
}

function planoLogSlug(string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') {
        return '';
    }
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    $ascii = $ascii !== false ? $ascii : $valor;
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii);
    return trim($ascii, '_');
}

function planoLogNormalizarAcao(string $acao): array
{
    // slug => label
    $catalogo = [
        'criar' => 'Criacao de plano',
        'atualizar' => 'Atualizacao de plano',
        'renovar' => 'Renovacao',
        'pagamento_fiado' => 'Pagamento de pendencia',
        'ajuste' => 'Ajuste de plano',
    ];

    $slugEntrada = planoLogSlug($acao);
    foreach ($catalogo as $slug => $label) {
        if ($slugEntrada === $slug || $slugEntrada === planoLogSlug($label)) {
            return [$slug, $label];
        }
    }

    if (strpos($slugEntrada, 'renov') === 0) {
        return ['renovar', $catalogo['renovar']];
    }
    if (strpos($slugEntrada, 'cria') === 0 || strpos($slugEntrada, 'novo') === 0) {
        return ['criar', $catalogo['criar']];
    }
    if (strpos($slugEntrada, 'atual') === 0 || strpos($slugEntrada, 'edit') === 0) {
        return ['atualizar', $catalogo['atualizar']];
    }
    if (strpos($slugEntrada, 'pend') === 0 || strpos($slugEntrada, 'fiado') === 0) {
        return ['pagamento_fiado', $catalogo['pagamento_fiado']];
    }

    $slugFinal = $slugEntrada !== '' ? $slugEntrada : 'movimento';
    $labelFinal = $acao !== '' ? $acao : 'Movimento';
    return [$slugFinal, $labelFinal];
}

function registrarLogPlano(PDO $pdo, int $clienteId, ?int $usuarioId, string $acao, array $dados = []): void
{
    ensureClientesPlanoLogs($pdo);

    [$acaoTipo, $acaoLabel] = planoLogNormalizarAcao($acao);

    $plano = $dados['plano'] ?? null;
    $valor = isset($dados['valor_plano']) ? (float)$dados['valor_plano'] : 0;
    $validade = $dados['validade'] ?? null;
    $pagamentoTipo = $dados['pagamento_tipo'] ?? 'avista';
    $cobrancaEm = $dados['cobranca_em'] ?? null;
    $observacao = $dados['observacao'] ?? null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO clientes_planos_logs (cliente_id, usuario_id, acao_tipo, acao, plano, valor_plano, validade, pagamento_tipo, cobranca_em, observacao, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $clienteId,
            $usuarioId ?: null,
            $acaoTipo ?: null,
            $acaoLabel,
            $plano ?: null,
            $valor,
            $validade,
            $pagamentoTipo ?: 'avista',
            $cobrancaEm ?: null,
            $observacao ?: null,
        ]);
    } catch (Throwable $e) {
        // falha no log nao deve impedir fluxo principal
    }
}
