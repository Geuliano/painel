<?php

require_once dirname(__DIR__) . '/core/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

ini_set('display_errors', 0);
error_reporting(0);

function resposta(bool $success, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function registroBloqueado(?array $registro): bool
{
    return !empty($registro['bloqueado_ate']) && strtotime($registro['bloqueado_ate']) > time();
}

function minutosRestantes(?array $registro): int
{
    if (!$registro || empty($registro['bloqueado_ate'])) {
        return 0;
    }
    $restante = strtotime($registro['bloqueado_ate']) - time();
    return max(1, (int)ceil($restante / 60));
}

function definirFingerprintCookie(string $valor): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('login_fingerprint', $valor, [
        'expires'  => time() + (30 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function ensureLoginTentativasTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS login_tentativas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL,
            fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            tentativas INT UNSIGNED NOT NULL DEFAULT 0,
            ultimo_erro DATETIME NOT NULL,
            bloqueado_ate DATETIME DEFAULT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_fingerprint_ip (email, fingerprint, ip),
            INDEX idx_ip_only (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    try {
        $pdo->exec($sql);
        $checked = true;
    } catch (Throwable $e) {
        error_log('Falha ao garantir login_tentativas: ' . $e->getMessage());
    }
}

$email = filter_var(trim($_POST['username'] ?? ''), FILTER_SANITIZE_EMAIL);
$senha = is_string($_POST['password'] ?? null) ? trim($_POST['password']) : '';
$csrf  = is_string($_POST['csrf_token'] ?? null) ? trim($_POST['csrf_token']) : '';

$fingerprint = $_COOKIE['login_fingerprint'] ?? '';
if (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{32}$/i', $fingerprint)) {
    $fingerprint = bin2hex(random_bytes(16));
    definirFingerprintCookie($fingerprint);
}

if ($email === '' || $senha === '') {
    resposta(false, 'Preencha todos os campos.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    resposta(false, 'E-mail inválido.');
}
if (strlen($email) > 150 || strlen($senha) > 200) {
    resposta(false, 'Entrada excede o tamanho permitido.');
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    resposta(false, 'Token CSRF inválido. Atualize a página e tente novamente.');
}

$ip = 'unknown';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}
if ($ip === '::1') {
    $ip = '127.0.0.1';
}
$ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco de dados indisponível.');
}

try {
    ensureLoginTentativasTable($pdo);

    $MAX_TENTATIVAS      = 5;
    $BLOQUEIO_MINUTOS    = 15;
    $MAX_TENTATIVAS_IP   = 20;
    $BLOQUEIO_IP_MINUTOS = 30;

    $stmt = $pdo->prepare("
        SELECT tentativas, ultimo_erro, bloqueado_ate
        FROM login_tentativas
        WHERE (email = ? OR fingerprint = ?) AND ip = ?
        LIMIT 1
    ");
    $stmt->execute([$email, $fingerprint, $ip]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtIp = $pdo->prepare("
        SELECT tentativas, ultimo_erro, bloqueado_ate
        FROM login_tentativas
        WHERE email = '' AND fingerprint = '' AND ip = ?
        LIMIT 1
    ");
    $stmtIp->execute([$ip]);
    $regIp = $stmtIp->fetch(PDO::FETCH_ASSOC) ?: null;

    if (registroBloqueado($reg)) {
        $restante = minutosRestantes($reg);
        resposta(false, "Muitas tentativas falhas. Aguarde {$restante} minutos antes de tentar novamente.");
    }
    if ($regIp && registroBloqueado($regIp)) {
        $restante = minutosRestantes($regIp);
        resposta(false, "Detectamos muitas tentativas deste IP. Aguarde {$restante} minutos antes de tentar novamente.");
    }

    if (autenticar($email, $senha)) {
        $stmt = $pdo->prepare("
            UPDATE login_tentativas
            SET tentativas = 0, bloqueado_ate = NULL, ultimo_erro = NOW()
            WHERE (email = ? OR fingerprint = ?) AND ip = ?
        ");
        $stmt->execute([$email, $fingerprint, $ip]);

        $stmt = $pdo->prepare("
            UPDATE login_tentativas
            SET tentativas = 0, bloqueado_ate = NULL, ultimo_erro = NOW()
            WHERE email = '' AND fingerprint = '' AND ip = ?
        ");
        $stmt->execute([$ip]);

        session_regenerate_id(true);
        definirFingerprintCookie(bin2hex(random_bytes(16)));

        if (!empty($_SESSION['usuario_id'])) {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW(), ultimo_ip = ? WHERE id = ?");
                $stmt->execute([$ip, $_SESSION['usuario_id']]);
            } catch (Throwable $e) {
                error_log('Aviso: falha ao atualizar último login: ' . $e->getMessage());
            }
        }

        resposta(true, 'Login realizado com sucesso.', ['redirect' => ""]);
    }

    if (!$reg) {
        $stmt = $pdo->prepare("
            INSERT INTO login_tentativas (email, ip, fingerprint, tentativas, ultimo_erro, bloqueado_ate)
            VALUES (?, ?, ?, 1, NOW(), NULL)
        ");
        $stmt->execute([$email, $ip, $fingerprint]);
    } else {
        $tentativas    = (int)$reg['tentativas'] + 1;
        $novaExpiracao = date('Y-m-d H:i:s', strtotime("+{$BLOQUEIO_MINUTOS} minutes"));

        if ($tentativas >= $MAX_TENTATIVAS) {
            $stmt = $pdo->prepare("
                UPDATE login_tentativas
                SET tentativas = ?, ultimo_erro = NOW(), bloqueado_ate = ?
                WHERE (email = ? OR fingerprint = ?) AND ip = ?
            ");
            $stmt->execute([$tentativas, $novaExpiracao, $email, $fingerprint, $ip]);
            resposta(false, "Muitas tentativas incorretas. Tente novamente em {$BLOQUEIO_MINUTOS} minutos.");
        }

        $stmt = $pdo->prepare("
            UPDATE login_tentativas
            SET tentativas = ?, ultimo_erro = NOW()
            WHERE (email = ? OR fingerprint = ?) AND ip = ?
        ");
        $stmt->execute([$tentativas, $email, $fingerprint, $ip]);
    }

    if (!$regIp) {
        $stmt = $pdo->prepare("
            INSERT INTO login_tentativas (email, ip, fingerprint, tentativas, ultimo_erro, bloqueado_ate)
            VALUES ('', ?, '', 1, NOW(), NULL)
        ");
        $stmt->execute([$ip]);
        $regIp = [
            'email'        => '',
            'ip'           => $ip,
            'fingerprint'  => '',
            'tentativas'   => 1,
            'ultimo_erro'  => date('Y-m-d H:i:s'),
            'bloqueado_ate'=> null,
        ];
    } else {
        $tentativasIp    = (int)$regIp['tentativas'] + 1;
        $novaExpiracaoIp = date('Y-m-d H:i:s', strtotime("+{$BLOQUEIO_IP_MINUTOS} minutes"));

        if ($tentativasIp >= $MAX_TENTATIVAS_IP) {
            $stmt = $pdo->prepare("
                UPDATE login_tentativas
                SET tentativas = ?, ultimo_erro = NOW(), bloqueado_ate = ?
                WHERE email = '' AND fingerprint = '' AND ip = ?
            ");
            $stmt->execute([$tentativasIp, $novaExpiracaoIp, $ip]);
            resposta(false, "Ocorreram muitas tentativas neste IP. Aguarde {$BLOQUEIO_IP_MINUTOS} minutos antes de tentar novamente.");
        }

        $stmt = $pdo->prepare("
            UPDATE login_tentativas
            SET tentativas = ?, ultimo_erro = NOW()
            WHERE email = '' AND fingerprint = '' AND ip = ?
        ");
        $stmt->execute([$tentativasIp, $ip]);
    }

    resposta(false, 'Usuário ou senha incorretos.');
} catch (Throwable $e) {
    error_log('Erro no login_action: ' . $e->getMessage());
    resposta(false, 'Erro interno ao processar o login.');
}
