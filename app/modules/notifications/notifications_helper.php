<?php

if (!function_exists('normalizeModuleSlug')) {
    require_once dirname(__DIR__, 2) . '/core/permissions.php';
}

if (!function_exists('ensureNotificationModulesTable')) {
    /**
     * Garantir que a tabela pivô exista antes de usarmos filtros por módulo.
     */
    function ensureNotificationModulesTable(PDO $pdo): bool
    {
        static $ensured = false;

        if ($ensured) {
            return true;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS notificacoes_modulos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                id_notificacao INT UNSIGNED NOT NULL,
                slug_modulo VARCHAR(120) NOT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_notif_mod_notif (id_notificacao),
                KEY idx_notif_mod_slug (slug_modulo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        try {
            $pdo->exec($sql);
            return $ensured = true;
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_name = 'notificacoes_modulos'
                    LIMIT 1
                ");
                $stmt->execute();
                $exists = (int)$stmt->fetchColumn() > 0;
                $ensured = $exists;
                return $exists;
            } catch (Throwable $inner) {
                return false;
            }
        }
    }
}

if (!function_exists('sanitizeNotificationModuleSlugs')) {
    /**
     * Normaliza e valida a lista de módulos enviados via formulário.
     *
     * @return string[] Lista de slugs válidos.
     */
    function sanitizeNotificationModuleSlugs(PDO $pdo, array $slugs): array
    {
        $clean = [];

        foreach ($slugs as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $slug = normalizeModuleSlug($slug);
            if ($slug !== '') {
                $clean[] = $slug;
            }
        }

        $clean = array_values(array_unique($clean));
        if (!$clean) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $pdo->prepare("SELECT slug FROM modulos WHERE slug IN ($placeholders) AND ativo = 1");
        $stmt->execute($clean);
        $valid = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return array_values(array_intersect($clean, $valid));
    }
}

if (!function_exists('fetchNotificationModules')) {
    /**
     * Retorna os módulos vinculados às notificações informadas.
     *
     * @param int[] $notificationIds
     * @return array<int, array<int, array{slug:string,nome:string}>>
     */
    function fetchNotificationModules(PDO $pdo, array $notificationIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $notificationIds)));
        if (!$ids) {
            return [];
        }

        ensureNotificationModulesTable($pdo);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    nm.id_notificacao,
                    nm.slug_modulo,
                    COALESCE(m.nome, nm.slug_modulo) AS nome_modulo
                FROM notificacoes_modulos nm
                LEFT JOIN modulos m
                    ON m.slug COLLATE utf8mb4_unicode_ci = nm.slug_modulo
                WHERE nm.id_notificacao IN ($placeholders)
                ORDER BY nome_modulo ASC, nm.slug_modulo ASC
            ");
            $stmt->execute($ids);
        } catch (Throwable $e) {
            return [];
        }

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(int)$row['id_notificacao']][] = [
                'slug' => $row['slug_modulo'],
                'nome' => $row['nome_modulo'] ?? $row['slug_modulo'],
            ];
        }

        return $result;
    }
}

if (!function_exists('fetchNotificationModulesForId')) {
    /**
     * Retorna os módulos de uma notificação específica.
     *
     * @return array<int, array{slug:string,nome:string}>
     */
    function fetchNotificationModulesForId(PDO $pdo, int $notificationId): array
    {
        $map = fetchNotificationModules($pdo, [$notificationId]);
        return $map[$notificationId] ?? [];
    }
}

if (!function_exists('syncNotificationModules')) {
    /**
     * Atualiza a relação dos módulos com a notificação.
     *
     * @param string[] $moduleSlugs
     */
    function syncNotificationModules(PDO $pdo, int $notificationId, array $moduleSlugs): void
    {
        ensureNotificationModulesTable($pdo);

        $notificationId = (int)$notificationId;
        if ($notificationId <= 0) {
            return;
        }

        try {
            $pdo->prepare("DELETE FROM notificacoes_modulos WHERE id_notificacao = ?")->execute([$notificationId]);
        } catch (Throwable $e) {
            return;
        }

        if (!$moduleSlugs) {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notificacoes_modulos (id_notificacao, slug_modulo)
                VALUES (?, ?)
            ");
            foreach ($moduleSlugs as $slug) {
                $stmt->execute([$notificationId, $slug]);
            }
        } catch (Throwable $e) {
            // ignora falhas de inserção
        }
    }
}
