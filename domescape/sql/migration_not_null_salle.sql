-- =============================================================
-- Migration : id_salle NOT NULL sur capteur, actionneur, session
-- =============================================================
-- Prérequis : exécuter sur le Pi DANS L'ORDRE
-- Durée estimée : < 1 minute
--
-- Ce script :
--   1. Insère le site et la salle physiques du Pi
--   2. Lie tous les capteurs / actionneurs existants à cette salle
--   3. Lie toutes les sessions historiques à cette salle
--   4. Passe les colonnes en NOT NULL
-- =============================================================

USE domescape;

-- -------------------------------------------------------------
-- 1. Site physique
-- -------------------------------------------------------------
INSERT IGNORE INTO site (id_site, nom_site, adresse, actif)
VALUES (1, 'DomEscape Lab', 'Raspberry Pi — local', TRUE);

-- -------------------------------------------------------------
-- 2. Salle physique (liée au site)
-- salle.id_site est déjà NOT NULL dans le schéma — aucune ALTER nécessaire
-- -------------------------------------------------------------
INSERT IGNORE INTO salle (id_salle, id_site, nom_salle, description, capacite, actif)
VALUES (1, 1, 'Salle 1', 'Salle principale démo Raspberry Pi', 4, TRUE);

-- -------------------------------------------------------------
-- 3. Lier les capteurs existants à la salle 1
-- -------------------------------------------------------------
UPDATE capteur SET id_salle = 1 WHERE id_salle IS NULL;

-- -------------------------------------------------------------
-- 4. Lier les actionneurs existants à la salle 1
-- -------------------------------------------------------------
UPDATE actionneur SET id_salle = 1 WHERE id_salle IS NULL;

-- -------------------------------------------------------------
-- 5. Lier les sessions historiques à la salle 1
-- (les sessions passées n'ont pas de salle — on les rattache
--  rétrospectivement à la salle unique du Pi)
-- -------------------------------------------------------------
UPDATE session SET id_salle = 1 WHERE id_salle IS NULL;

-- -------------------------------------------------------------
-- 6. Passer les colonnes en NOT NULL
-- -------------------------------------------------------------
ALTER TABLE capteur
    MODIFY id_salle INT NOT NULL;

ALTER TABLE actionneur
    MODIFY id_salle INT NOT NULL;

ALTER TABLE session
    MODIFY id_salle INT NOT NULL;

-- -------------------------------------------------------------
-- Vérification rapide (optionnel — à lancer après)
-- -------------------------------------------------------------
-- SELECT 'capteur'    AS table_name, COUNT(*) AS total, SUM(id_salle IS NULL) AS nulls FROM capteur
-- UNION ALL
-- SELECT 'actionneur' AS table_name, COUNT(*) AS total, SUM(id_salle IS NULL) AS nulls FROM actionneur
-- UNION ALL
-- SELECT 'session'    AS table_name, COUNT(*) AS total, SUM(id_salle IS NULL) AS nulls FROM session;
