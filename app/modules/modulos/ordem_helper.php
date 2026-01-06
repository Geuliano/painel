<?php

if (!function_exists('organizarOrdemModulos')) {
    /**
     * Normaliza o campo "ordem" para cada agrupamento de módulos (pai -> filhos).
     *
     * @param PDO      $pdo            Conexão ativa.
     * @param int|null $specificParent ID do pai que deve ser reordenado. Null reordena todos.
     *
     * @return bool True em caso de sucesso, false se ocorrer alguma falha inesperada.
     */
    function organizarOrdemModulos(PDO $pdo, ?int $specificParent = null): bool
    {
        if (!$pdo instanceof PDO) {
            return false;
        }

        $parents = [];

        if ($specificParent !== null) {
            $parents[] = $specificParent === 0 ? null : $specificParent;
        } else {
            $stmtParents = $pdo->query("SELECT DISTINCT id_pai FROM modulos");
            if ($stmtParents) {
                $parents = $stmtParents->fetchAll(PDO::FETCH_COLUMN);
            }

            $parents = array_map(function ($value) {
                return $value === null ? null : (int)$value;
            }, $parents ?: []);

            if (!$parents) {
                $parents = [null];
            } elseif (!in_array(null, $parents, true)) {
                array_unshift($parents, null);
            }
        }

        $updates = [];
        $sqlBase = "SELECT id, ordem FROM modulos WHERE %s ORDER BY ordem ASC, nome ASC, id ASC";

        foreach ($parents as $parentId) {
            if ($parentId === null) {
                $stmt = $pdo->query(sprintf($sqlBase, 'id_pai IS NULL'));
            } else {
                $stmt = $pdo->prepare(sprintf($sqlBase, 'id_pai = :parentId'));
                $stmt->execute([':parentId' => $parentId]);
            }

            if (!$stmt) {
                continue;
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $expectedOrder = 1;

            foreach ($rows as $row) {
                $rowId = (int)$row['id'];
                $ordemAtual = (int)$row['ordem'];
                if ($ordemAtual !== $expectedOrder) {
                    $updates[] = ['id' => $rowId, 'ordem' => $expectedOrder];
                }
                $expectedOrder++;
            }
        }

        if (!$updates) {
            return true;
        }

        $stmtUpdate = $pdo->prepare("UPDATE modulos SET ordem = ? WHERE id = ?");
        $manageTransaction = !$pdo->inTransaction();

        try {
            if ($manageTransaction) {
                $pdo->beginTransaction();
            }

            foreach ($updates as $update) {
                $stmtUpdate->execute([$update['ordem'], $update['id']]);
            }

            if ($manageTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($manageTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        return true;
    }
}
