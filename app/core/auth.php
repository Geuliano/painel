<?php
require_once dirname(__DIR__) . '/core/config.php';


// ============== Helpers de sessão ==============
function isLoggedIn(): bool {
    return !empty($_SESSION['usuario_id']);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/**
 * Exige login (redireciona se não estiver logado) e
 * renova/expira sessão por inatividade (30 min).
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header("Location: " . BASE_URL);
        exit;
    }
    // Timeout 30 min
    if (isset($_SESSION['ultimo_aceso']) && (time() - $_SESSION['ultimo_aceso'] > 36000)) {
        logout();
        exit;
    }
    $_SESSION['ultimo_aceso'] = time();
}

// ============== Autenticação ==============
function autenticar(string $email, string $senha): bool {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, nome, senha_hash FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($senha, $user['senha_hash'])) {
        $_SESSION['usuario_id']  = $user['id'];
        $_SESSION['usuario_nome'] = $user['nome'];
        $_SESSION['ultimo_aceso'] = time();
        return true; // ✅ apenas retorna true
    }

    return false;
}


// ============== Logout ==============
function logout(): void {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL);
    exit;
}
