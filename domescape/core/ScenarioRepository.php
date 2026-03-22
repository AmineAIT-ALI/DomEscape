<?php

require_once __DIR__ . '/../config/database.php';

// =============================================================
// ScenarioRepository
// Accès aux données des scénarios
// =============================================================

class ScenarioRepository
{
    /**
     * Retourne tous les scénarios actifs avec leur nombre d'étapes.
     */
    public static function getActive(): array
    {
        $pdo = getDB();
        return $pdo->query("
            SELECT s.*, COUNT(e.id_etape) AS nb_etapes
            FROM scenario s
            LEFT JOIN etape e ON s.id_scenario = e.id_scenario
            WHERE s.actif = 1
            GROUP BY s.id_scenario
            ORDER BY s.nom_scenario
        ")->fetchAll();
    }
}
