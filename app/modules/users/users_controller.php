<?php


// ============================================================================
// USERS CONTROLLER – Totalmente protegido com:
// ✔ Permissões por ação
// ✔ Proteção CSRF
// ✔ Proteção hierárquica (não editar/deletar superiores)
// ✔ Impede excluir a si mesmo
// ✔ JSON limpo sempre
// ============================================================================

// Inicia buffer para evitar qualquer saída antes do JSON
ob_start();

// Caminhos do projeto
$appPath = dirname(__DIR__, 2); // .../painel/app
require_once $appPath . '/core/config.php';
require_once $appPath . '/core/auth.php';
require_once $appPath . '/core/permissions.php'; // << necessário

// Garante colunas extras que este módulo usa
function ensureUsuariosExtraColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN apelido VARCHAR(160) NULL AFTER nome");
    } catch (Throwable $e) {
        // já existe ou sem permissão: ignorar
    }
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(20) NULL AFTER email");
    } catch (Throwable $e) {
        // já existe ou sem permissão: ignorar
    }
}

// ---------------------------------------------------------------------------
// Verifica login
// ---------------------------------------------------------------------------
if (function_exists('verificarLogin')) {
    verificarLogin();
} elseif (empty($_SESSION['usuario_id'])) {
    jsonResponse(false, 'Acesso não autorizado.');
}

$usuarioId = $_SESSION['usuario_id'] ?? 0;
ensureUsuariosExtraColumns($pdo);

// ---------------------------------------------------------------------------
// Função auxiliar - responder JSON sem ruídos
// ---------------------------------------------------------------------------
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

/**
 * Normaliza o telefone para o formato aceito pelo WhatsApp (somente dígitos e DDI 55).
 * Retorna string ou null se nenhum telefone foi informado; false para formato inválido.
 */
function normalizarTelefoneBrasil($telefone)
{
    $apenasDigitos = preg_replace('/\D+/', '', (string)$telefone);
    if ($apenasDigitos === '') {
        return null;
    }

    $apenasDigitos = ltrim($apenasDigitos, '0'); // remove zeros iniciais comuns em DDI

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
 * Formata telefone com DDI 55 para exibição amigável.
 */
function formatarTelefoneParaExibicao(?string $telefone): ?string
{
    if (!$telefone) {
        return null;
    }
    $numeros = preg_replace('/\D+/', '', $telefone);
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

// ---------------------------------------------------------------------------
// Garante que o PDO exista
// ---------------------------------------------------------------------------
if (!isset($pdo)) {
    jsonResponse(false, 'Conexão com o banco não encontrada.');
}

// ---------------------------------------------------------------------------
// Detecta ação
// ---------------------------------------------------------------------------
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$allowedActions = ['list', 'get', 'create', 'update', 'delete'];

if (!in_array($action, $allowedActions, true)) {
    jsonResponse(false, 'Ação inválida.');
}


// ============================================================================
// 1) VERIFICAÇÃO DE PERMISSÕES
// ============================================================================

$mapaPermissao = [
    'list'   => 'listar',
    'get'    => 'listar',
    'create' => 'criar',
    'update' => 'editar',
    'delete' => 'excluir',
];

$modulo = 'users';
$permissaoNecessaria = $mapaPermissao[$action];

if (!checkActionPermission($usuarioId, $modulo, $permissaoNecessaria)) {
    jsonResponse(false, 'Você não tem permissão para executar esta ação.');
}


// ============================================================================
// 2) CSRF VALIDATION – Apenas ações que alteram estado
// ============================================================================
$requiresCSRF = ['create','update','delete'];

if (in_array($action, $requiresCSRF, true)) {
    $token = $_POST['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        jsonResponse(false, 'Falha de segurança: CSRF Token inválido.');
    }
}


// ============================================================================
// 3) Função auxiliar - Capturar nível do usuário
// ============================================================================
function nivelUsuario($id, $pdo)
{
    $sql = "SELECT nivel FROM usuarios WHERE id = ? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/**
 * Valida se o nível informado existe na tabela oficial.
 */
function nivelEhValido(PDO $pdo, int $nivelId): bool
{
    static $cache = [];

    if ($nivelId <= 0) {
        return false;
    }

    if (array_key_exists($nivelId, $cache)) {
        return $cache[$nivelId];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM niveis_acesso WHERE id = ?");
    $stmt->execute([$nivelId]);
    $cache[$nivelId] = $stmt->fetchColumn() > 0;

    return $cache[$nivelId];
}

/**
 * Mantém a tabela usuario_nivel sincronizada com o campo principal.
 */
function sincronizarNivelUsuario(PDO $pdo, int $usuarioId, int $nivelId): void
{
    $stmt = $pdo->prepare("DELETE FROM usuario_nivel WHERE id_usuario = ?");
    $stmt->execute([$usuarioId]);

    $stmt = $pdo->prepare("INSERT INTO usuario_nivel (id_usuario, id_nivel) VALUES (?, ?)");
    $stmt->execute([$usuarioId, $nivelId]);
}


// ============================================================================
// 4) Configurações de avatar
// ============================================================================
$projectRoot  = dirname($appPath);
$publicPath   = $projectRoot . '/public';
$avatarDir    = $publicPath . '/uploads/avatars/';
$avatarUrl    = BASE_URL . 'public/uploads/avatars/';

if (!is_dir($avatarDir)) {
    @mkdir($avatarDir, 0775, true);
}


// ============================================================================
// 5) AÇÃO LISTAR
// ============================================================================
if ($action === 'list') {

    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.nome,
            u.apelido,
            u.email,
            u.telefone,
            u.nivel,
            u.criado_em,
            u.ultimo_login,
            u.avatar,
            COALESCE(n2.nome, n1.nome) AS nivel_nome
        FROM usuarios u
        LEFT JOIN niveis_acesso n1 ON n1.id = u.nivel
        LEFT JOIN usuario_nivel un ON un.id_usuario = u.id
        LEFT JOIN niveis_acesso n2 ON n2.id = un.id_nivel
        ORDER BY u.id DESC
    ");

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($usuarios as &$u) {
        $u['avatar_url'] = $u['avatar']
            ? $avatarUrl . $u['avatar']
            : BASE_URL . 'public/assets/images/users/default.png';
        $u['telefone_formatado'] = formatarTelefoneParaExibicao($u['telefone'] ?? null);
    }

    jsonResponse(true, ['usuarios' => $usuarios]);
}


// ============================================================================
// 6) AÇÃO GET (obter 1 usuário)
// ============================================================================
if ($action === 'get') {

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) 
        ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (!$id) jsonResponse(false, 'ID inválido.');

    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nome,
            u.apelido,
            u.email,
            u.telefone,
            u.nivel,
            u.criado_em,
            u.ultimo_login,
            u.avatar,
            COALESCE(n2.nome, n1.nome) AS nivel_nome
        FROM usuarios u
        LEFT JOIN niveis_acesso n1 ON n1.id = u.nivel
        LEFT JOIN usuario_nivel un ON un.id_usuario = u.id
        LEFT JOIN niveis_acesso n2 ON n2.id = un.id_nivel
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) jsonResponse(false, 'Usuário não encontrado.');

    $u['avatar_url'] = $u['avatar']
        ? $avatarUrl . $u['avatar']
        : BASE_URL . 'public/assets/images/users/default.png';
    $u['telefone_formatado'] = formatarTelefoneParaExibicao($u['telefone'] ?? null);

    jsonResponse(true, ['usuario' => $u]);
}


// ============================================================================
// 7) AÇÃO CREATE
// ============================================================================
if ($action === 'create') {

    $nome  = trim($_POST['nome']  ?? '');
    $apelido = trim($_POST['apelido'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefoneRaw = $_POST['telefone'] ?? '';
    $senha = trim($_POST['senha'] ?? '');
    $nivel = (int)($_POST['nivel'] ?? 0);

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 6) {
        jsonResponse(false, 'Dados inválidos.');
    }
    if (!nivelEhValido($pdo, $nivel)) {
        jsonResponse(false, 'Selecione um nível de acesso válido.');
    }

    $telefone = normalizarTelefoneBrasil($telefoneRaw);
    if ($telefone === false) {
        jsonResponse(false, 'Informe um telefone com DDD válido.');
    }

    $nivelAtualLogado = nivelUsuario($usuarioId, $pdo);
    if ($nivelAtualLogado > 0 && $nivel > $nivelAtualLogado) {
        jsonResponse(false, 'Você não pode atribuir um nível superior ao seu.');
    }

    // Verifica duplicação de email
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Já existe um usuário com este email.');
    }

    $senhaHash = password_hash($senha, PASSWORD_BCRYPT);

    $newId = 0;
    $novoAvatarNome = null;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, apelido, email, telefone, senha_hash, nivel, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $nome,
            $apelido !== '' ? $apelido : null,
            $email,
            $telefone,
            $senhaHash,
            $nivel
        ]);
        $newId = (int)$pdo->lastInsertId();

        if (!empty($_FILES['avatar']['name'] ?? '')) {
            $avatarNome = processAvatarUpload($_FILES['avatar'], $newId, $avatarDir);
            if ($avatarNome === false) {
                throw new RuntimeException('Falha no upload do avatar.');
            }
            $novoAvatarNome = $avatarNome;

            $stmt = $pdo->prepare("UPDATE usuarios SET avatar = ? WHERE id = ?");
            $stmt->execute([$avatarNome, $newId]);
        }

        sincronizarNivelUsuario($pdo, $newId, $nivel);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($novoAvatarNome) {
            $uploaded = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $novoAvatarNome;
            if (is_file($uploaded)) {
                @unlink($uploaded);
            }
        }
        jsonResponse(false, 'Erro ao criar usuário.');
    }

    jsonResponse(true, 'Usuário criado com sucesso.', ['id' => $newId]);
}


// ============================================================================
// 8) AÇÃO UPDATE
// ============================================================================
if ($action === 'update') {

    $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $nome  = trim($_POST['nome'] ?? '');
    $apelido = trim($_POST['apelido'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefoneRaw = $_POST['telefone'] ?? '';
    $senha = trim($_POST['senha'] ?? '');
    $nivel = (int)($_POST['nivel'] ?? 0);

    if (!$id) jsonResponse(false, 'ID inválido.');
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Dados inválidos.');
    }
    if (!nivelEhValido($pdo, $nivel)) {
        jsonResponse(false, 'Selecione um nível válido.');
    }

    $telefone = normalizarTelefoneBrasil($telefoneRaw);
    if ($telefone === false) {
        jsonResponse(false, 'Informe um telefone com DDD válido.');
    }

    // Impede editar superiores
    $nivelAtual = nivelUsuario($usuarioId, $pdo);
    $nivelAlvo  = nivelUsuario($id, $pdo);

    if ($nivelAlvo > $nivelAtual) {
        jsonResponse(false, 'Você não pode editar um usuário com nível superior ao seu.');
    }
    if ($nivelAtual > 0 && $nivel > $nivelAtual) {
        jsonResponse(false, 'Você não pode atribuir um nível superior ao seu.');
    }

    // Busca usuário
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) jsonResponse(false, 'Usuário não encontrado.');

    // Email duplicado
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Já existe outro usuário com este email.');
    }

    // Atualiza
    $query  = "UPDATE usuarios SET nome = ?, apelido = ?, email = ?, telefone = ?, nivel = ?";
    $params = [
        $nome,
        $apelido !== '' ? $apelido : null,
        $email,
        $telefone,
        $nivel
    ];

    if ($senha !== '') {
        if (strlen($senha) < 6) {
            jsonResponse(false, 'A nova senha deve ter no mínimo 6 caracteres.');
        }
        $query .= ", senha_hash = ?";
        $params[] = password_hash($senha, PASSWORD_BCRYPT);
    }

    $novoAvatarNome = null;
    if (!empty($_FILES['avatar']['name'] ?? '')) {
        $upload = processAvatarUpload($_FILES['avatar'], $id, $avatarDir);
        if ($upload === false) {
            jsonResponse(false, 'Falha no upload do avatar.');
        }
        $novoAvatarNome = $upload;
        $query .= ", avatar = ?";
        $params[] = $novoAvatarNome;
    }

    $query .= " WHERE id = ?";
    $params[] = $id;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        sincronizarNivelUsuario($pdo, $id, $nivel);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($novoAvatarNome) {
            $uploaded = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $novoAvatarNome;
            if (is_file($uploaded)) {
                @unlink($uploaded);
            }
        }
        jsonResponse(false, 'Erro ao atualizar usuário.');
    }

    if ($novoAvatarNome && !empty($usuario['avatar'])) {
        $old = $avatarDir . $usuario['avatar'];
        if (is_file($old)) {
            @unlink($old);
        }
    }

    jsonResponse(true, 'Usuário atualizado com sucesso.');
}


// ============================================================================
// 9) AÇÃO DELETE
// ============================================================================
if ($action === 'delete') {

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) jsonResponse(false, 'ID inválido.');

    // Impede excluir a si mesmo
    if ($usuarioId == $id) {
        jsonResponse(false, 'Você não pode excluir seu próprio usuário.');
    }

    // Hierarquia
    $nivelAtual = nivelUsuario($usuarioId, $pdo);
    $nivelAlvo  = nivelUsuario($id, $pdo);

    if ($nivelAlvo > $nivelAtual) {
        jsonResponse(false, 'Você não pode excluir um usuário de nível superior.');
    }

    // Busca avatar
    $stmt = $pdo->prepare("SELECT avatar FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) jsonResponse(false, 'Usuário não encontrado.');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM usuario_nivel WHERE id_usuario = ?");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Erro ao excluir usuário.');
    }

    // Remove avatar do disco
    if (!empty($u['avatar'])) {
        $old = $avatarDir . $u['avatar'];
        if (is_file($old)) @unlink($old);
    }

    jsonResponse(true, 'Usuário excluído com sucesso.');
}


// ============================================================================
// 10) Função UPLOAD AVATAR
// ============================================================================
function processAvatarUpload(array $file, int $idUser, string $avatarDir)
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png'];

    if (!in_array($ext, $permitidas, true)) return false;
    if ($file['size'] > 2 * 1024 * 1024) return false; // 2MB

    $novoNome = "avatar_{$idUser}_" . time() . "." . $ext;
    $destino  = rtrim($avatarDir, '/\\') . DIRECTORY_SEPARATOR . $novoNome;

    if (!move_uploaded_file($file['tmp_name'], $destino)) return false;

    return $novoNome;
}

?>
