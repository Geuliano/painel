<?php

if (!function_exists('ensurePersonalizacaoTable')) {
    function ensurePersonalizacaoTable(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS personalizacoes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                titulo_site VARCHAR(180) NOT NULL,
                favicon VARCHAR(255) DEFAULT NULL,
                logo_light VARCHAR(255) DEFAULT NULL,
                logo_dark VARCHAR(255) DEFAULT NULL,
                logo_small VARCHAR(255) DEFAULT NULL,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        try {
            $pdo->exec($sql);
            $ensured = true;
        } catch (Throwable $e) {
            $ensured = true;
        }
    }
}

if (!function_exists('getBrandingDefaults')) {
    function getBrandingDefaults(): array
    {
        return [
            'titulo_site' => 'Clipboard | Approx - Admin & Dashboard Template',
            'favicon'     => 'public/assets/images/favicon.ico',
            'logo_light'  => 'public/assets/images/logo-light.png',
            'logo_dark'   => 'public/assets/images/logo-dark.png',
            'logo_small'  => 'public/assets/images/logo-sm.png',
        ];
    }
}

if (!function_exists('getBrandingRecord')) {
    function getBrandingRecord(PDO $pdo): array
    {
        ensurePersonalizacaoTable($pdo);
        $stmt = $pdo->query("
            SELECT id, titulo_site, favicon, logo_light, logo_dark, logo_small
            FROM personalizacoes
            ORDER BY id DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }
}

if (!function_exists('getBrandingConfig')) {
    function getBrandingConfig(PDO $pdo): array
    {
        $defaults = getBrandingDefaults();
        $record = getBrandingRecord($pdo);
        if (!$record) {
            return $defaults;
        }

        return [
            'titulo_site' => $record['titulo_site'] ?: $defaults['titulo_site'],
            'favicon'     => $record['favicon'] ?: $defaults['favicon'],
            'logo_light'  => $record['logo_light'] ?: $defaults['logo_light'],
            'logo_dark'   => $record['logo_dark'] ?: $defaults['logo_dark'],
            'logo_small'  => $record['logo_small'] ?: $defaults['logo_small'],
            'id'          => $record['id'] ?? null,
        ];
    }
}
