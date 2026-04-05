-- =============================================================
-- Migration : intégration scenario_version dans etape + session
-- BDD v3 → v4
--
-- Appliquer sur le Pi :
--   mysql -u root -p domescape < migration_scenario_version.sql
-- =============================================================

USE domescape;

-- -------------------------------------------------------------
-- Étape 0 — S'assurer que la version v1.0 existe
-- (idempotent : INSERT IGNORE ne fait rien si elle existe déjà)
-- -------------------------------------------------------------
INSERT IGNORE INTO scenario_version (id_scenario_version, id_scenario, numero_version, statut_version, commentaire)
VALUES (1, 1, 'v1.0', 'active', 'Version initiale — démo Raspberry Pi');

-- -------------------------------------------------------------
-- Étape 1 — Ajouter les colonnes (ADD IF NOT EXISTS = idempotent MariaDB 10.0+)
-- -------------------------------------------------------------
ALTER TABLE etape
    ADD COLUMN IF NOT EXISTS id_scenario_version INT NULL;

ALTER TABLE session
    ADD COLUMN IF NOT EXISTS id_scenario_version INT NULL;

-- -------------------------------------------------------------
-- Étape 2 — Backfill : rattacher les lignes existantes à v1.0
-- -------------------------------------------------------------
UPDATE etape   SET id_scenario_version = 1 WHERE id_scenario = 1 AND id_scenario_version IS NULL;
UPDATE session SET id_scenario_version = 1 WHERE id_scenario = 1 AND id_scenario_version IS NULL;

-- -------------------------------------------------------------
-- Étape 3 — FK (uniquement si elles n'existent pas déjà)
-- -------------------------------------------------------------
-- Suppression préventive si partiellement appliqué
ALTER TABLE etape   DROP FOREIGN KEY IF EXISTS fk_etape_scenver;
ALTER TABLE session DROP FOREIGN KEY IF EXISTS fk_session_scenver;

ALTER TABLE etape
    ADD CONSTRAINT fk_etape_scenver
    FOREIGN KEY (id_scenario_version) REFERENCES scenario_version(id_scenario_version)
    ON DELETE SET NULL;

ALTER TABLE session
    ADD CONSTRAINT fk_session_scenver
    FOREIGN KEY (id_scenario_version) REFERENCES scenario_version(id_scenario_version)
    ON DELETE SET NULL;

-- -------------------------------------------------------------
-- Vérification
-- -------------------------------------------------------------
SELECT 'etape'   AS tbl, COUNT(*) AS total, SUM(id_scenario_version IS NOT NULL) AS avec_version FROM etape
UNION ALL
SELECT 'session', COUNT(*), SUM(id_scenario_version IS NOT NULL) FROM session;

SELECT id_scenario_version, id_scenario, numero_version, statut_version FROM scenario_version ORDER BY id_scenario_version;
