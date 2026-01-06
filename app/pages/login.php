<?php
require_once dirname(__DIR__) . '/core/auth.php';
require_once APP_PATH . '/modules/personalizar/personalizar_helper.php';

if (isLoggedIn()) {
    header("Location: " . BASE_URL);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$brandingConfig = getBrandingConfig($pdo);
$pageTitleBrand = $brandingConfig['titulo_site'] ?? 'Painel Administrativo';
$faviconLogin = $brandingConfig['favicon'] ?? 'public/assets/images/favicon.ico';
?>

<!DOCTYPE html>
<html lang="pt_BR" dir="ltr" data-startbar="dark" data-bs-theme="dark">
<head>
  <meta charset="utf-8" />
  <title><?= htmlspecialchars($pageTitleBrand) ?> | Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Assets via BASE_URL -->
  <link rel="shortcut icon" href="<?= BASE_URL . $faviconLogin ?>">
  <link href="<?= BASE_URL ?>public/assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>public/assets/css/icons.min.css" rel="stylesheet">
  <link href="<?= BASE_URL ?>public/assets/css/app.min.css" rel="stylesheet">
</head>

<body>
<div class="container-xl">
    <div class="row vh-100 d-flex justify-content-center align-items-center">
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-body p-0 bg-black auth-header-box rounded-top text-center text-white p-4">
                    <!-- <img src="../../assets/images/logo-sm.png" height="50" alt="logo" class="mb-2"> -->
                    <h4 class="fw-semibold fs-18"><?= htmlspecialchars($pageTitleBrand) ?></h4>
                    <p class="text-muted">Insira suas credenciais</p>
                </div>

                <div class="card-body pt-4">
                    <div id="alert-box" class="alert d-none"></div>

                    <form id="loginForm">
                        <div class="form-group mb-2">
                          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <label for="username" class="form-label">E-mail</label>
                            <input type="email" name="username" id="username" class="form-control" placeholder="Seu e-mail" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="password" class="form-label">Senha</label>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Sua senha" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="btnLogin">Entrar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById("loginForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();

  const form = e.target;
  const btn = document.getElementById("btnLogin");
  const alertBox = document.getElementById("alert-box");
  const formData = new FormData(form);

  // Limpa mensagens anteriores
  alertBox.classList.add("d-none");
  alertBox.classList.remove("alert-danger", "alert-success");

  btn.disabled = true;
  btn.innerHTML = "Verificando...";

  try {
    const endpoint = "<?= BASE_URL ?>app/auth/login_action.php";
    const resp = await fetch(endpoint, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const ct = resp.headers.get("content-type") || "";

    if (!ct.includes("application/json")) {
      throw new Error("Resposta não é JSON (verifique o endpoint).");
    }

    const result = await resp.json();

    // Exibe o alerta estilizado
    alertBox.classList.remove("d-none");
    if (result.success) {
      alertBox.classList.add("alert-success");
      alertBox.textContent = "Tudo certo, entrando no sistema...";
      setTimeout(() => (window.location.href = result.redirect), 1000);
    } else {
      alertBox.classList.add("alert-danger");
      alertBox.textContent = "" + (result.message || "Falha no login.");
    }
  } catch (err) {
    alertBox.classList.remove("d-none");
    alertBox.classList.add("alert-danger");
    alertBox.textContent = "Erro: " + err.message;
  } finally {
    btn.disabled = false;
    btn.innerHTML = "Entrar";
  }
});
</script>

</body>
</html>
