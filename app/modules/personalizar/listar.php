<?php
requireLogin();
require_once APP_PATH . '/core/module_header.php';
require_once __DIR__ . '/personalizar_helper.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$branding = getBrandingConfig($pdo);
$csrfToken = $_SESSION['csrf_token'];
?>

<style>
    .branding-preview {
        width: auto;
        height: 60px;
        max-width: 160px;
        object-fit: contain;
        border: 1px dashed rgba(255, 255, 255, 0.2);
        padding: 6px;
        background-color: rgba(255, 255, 255, 0.05);
    }
    .branding-preview--favicon {
        height: 32px;
        width: 32px;
        padding: 0;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Personalização da identidade visual</h4>
                <p class="text-muted mb-0">Atualize título, favicon e logomarcas exibidas no topo do painel.</p>
            </div>
            <div class="card-body">
                <div id="brandingAlert" class="alert d-none"></div>
                <form id="formPersonalizacao" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="mb-3">
                        <label for="campo_titulo_site" class="form-label">Título do site</label>
                        <input type="text" class="form-control" id="campo_titulo_site" name="titulo_site"
                            value="<?= htmlspecialchars($branding['titulo_site']) ?>" required>
                        <small class="text-muted">Esse texto aparece na aba do navegador e em áreas que utilizem o título global.</small>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label">Favicon (16x16 ou SVG)</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <img src="<?= BASE_URL . $branding['favicon'] ?>" alt="Favicon atual"
                                     class="branding-preview branding-preview--favicon" id="preview_favicon">
                                <input type="file" class="form-control" name="favicon"
                                       accept=".png,.jpg,.jpeg,.ico,.svg"
                                       data-preview-target="preview_favicon">
                            </div>
                            <small class="text-muted d-block mt-1">Formatos aceitos: PNG, JPG, ICO ou SVG (até 300KB).</small>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label">Logo tema escuro</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <img src="<?= BASE_URL . $branding['logo_light'] ?>" alt="Logo tema claro"
                                     class="branding-preview" id="preview_logo_light">
                                <input type="file" class="form-control" name="logo_light"
                                       accept=".png,.jpg,.jpeg,.svg"
                                       data-preview-target="preview_logo_light">
                            </div>
                            <small class="text-muted d-block mt-1">Sugestão: PNG com fundo transparente (até 1MB).</small>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label">Logo tema claro</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <img src="<?= BASE_URL . $branding['logo_dark'] ?>" alt="Logo tema escuro"
                                     class="branding-preview" id="preview_logo_dark">
                                <input type="file" class="form-control" name="logo_dark"
                                       accept=".png,.jpg,.jpeg,.svg"
                                       data-preview-target="preview_logo_dark">
                            </div>
                            <small class="text-muted d-block mt-1">Será exibida quando o usuário escolher tema escuro.</small>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label">Logo reduzida</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <img src="<?= BASE_URL . $branding['logo_small'] ?>" alt="Logo compacta"
                                     class="branding-preview branding-preview--favicon" id="preview_logo_small">
                                <input type="file" class="form-control" name="logo_small"
                                       accept=".png,.jpg,.jpeg,.svg"
                                       data-preview-target="preview_logo_small">
                            </div>
                            <small class="text-muted d-block mt-1">Utilizada no menu recolhido. Recomenda-se imagens quadradas.</small>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary" id="btnSalvarPersonalizacao">
                            Salvar personalização
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    const controllerUrl = '<?= BASE_URL ?>app/modules/personalizar/personalizar_controller.php';

    document.querySelectorAll('[data-preview-target]').forEach(input => {
        input.addEventListener('change', event => {
            const targetId = event.currentTarget.dataset.previewTarget;
            const target = document.getElementById(targetId);
            if (!target || !event.currentTarget.files?.length) {
                return;
            }
            const file = event.currentTarget.files[0];
            target.src = URL.createObjectURL(file);
        });
    });

    document.getElementById('formPersonalizacao')?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const form = event.currentTarget;
        const btn = document.getElementById('btnSalvarPersonalizacao');
        const alertBox = document.getElementById('brandingAlert');
        alertBox.className = 'alert d-none';
        alertBox.textContent = '';

        const formData = new FormData(form);
        formData.append('action', 'save_settings');

        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch(controllerUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const data = await resp.json();

            alertBox.classList.remove('d-none');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            alertBox.textContent = data.message || (data.success ? 'Configurações atualizadas.' : 'Falha ao salvar.');

            if (data.success) {
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (error) {
            alertBox.classList.remove('d-none');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = error.message || 'Erro inesperado ao salvar.';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar personalização';
        }
    });
</script>
