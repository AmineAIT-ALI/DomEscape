-- =============================================================
-- DÉPRÉCIÉ — Ce fichier n'est plus utilisé.
--
-- Les tables utilisateur, role et utilisateur_role,
-- ainsi que le compte admin par défaut, sont désormais
-- inclus dans schema.sql (BDD v3 — VERSION GELÉE).
--
-- Conserver uniquement pour référence / migration de l'ancienne
-- BDD v2 vers v3 (ALTER TABLE uniquement, pas de CREATE).
-- =============================================================

-- Migration v2 → v3 : ajouter les tables auth si elles n'existent pas encore
-- (utile si la BDD a été créée avec l'ancien schema.sql à 13 tables)

CREATE TABLE IF NOT EXISTS utilisateur (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    email              VARCHAR(255) NOT NULL UNIQUE,
    mot_de_passe       VARCHAR(255) NOT NULL,
    actif              TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role (
    id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utilisateur_role (
    id_utilisateur INT UNSIGNED NOT NULL,
    id_role        INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_utilisateur, id_role),
    CONSTRAINT fk_ur_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role        FOREIGN KEY (id_role)        REFERENCES role(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lien joueur → utilisateur (si colonne absente)
ALTER TABLE joueur
    ADD COLUMN IF NOT EXISTS id_utilisateur INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE joueur
    ADD FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id) ON DELETE SET NULL;

-- Extension multi-sites (Phase 1)
CREATE TABLE IF NOT EXISTS site (
    id_site     INT AUTO_INCREMENT PRIMARY KEY,
    nom_site    VARCHAR(100) NOT NULL,
    description TEXT,
    adresse     VARCHAR(255),
    actif       BOOLEAN  DEFAULT TRUE,
    cree_le     DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salle (
    id_salle    INT AUTO_INCREMENT PRIMARY KEY,
    id_site     INT NOT NULL,
    nom_salle   VARCHAR(100) NOT NULL,
    description TEXT,
    capacite    INT,
    actif       BOOLEAN  DEFAULT TRUE,
    cree_le     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_salle_site FOREIGN KEY (id_site) REFERENCES site(id_site) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scenario_version (
    id_scenario_version INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario         INT         NOT NULL,
    numero_version      VARCHAR(20) NOT NULL,
    statut_version      VARCHAR(20) DEFAULT 'draft',
    commentaire         TEXT,
    cree_le             DATETIME    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scenver_scenario FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salle_scenario (
    id_salle_scenario   INT AUTO_INCREMENT PRIMARY KEY,
    id_salle            INT NOT NULL,
    id_scenario_version INT NOT NULL,
    actif               BOOLEAN  DEFAULT TRUE,
    date_activation     DATETIME,
    configuration_locale TEXT,
    CONSTRAINT fk_ss_salle    FOREIGN KEY (id_salle)            REFERENCES salle(id_salle)                       ON DELETE RESTRICT,
    CONSTRAINT fk_ss_version  FOREIGN KEY (id_scenario_version) REFERENCES scenario_version(id_scenario_version) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nullable FKs id_salle sur les tables existantes (Phase 1)
ALTER TABLE capteur
    ADD COLUMN IF NOT EXISTS id_salle INT NULL;
ALTER TABLE capteur
    ADD FOREIGN KEY (id_salle) REFERENCES salle(id_salle) ON DELETE SET NULL;

ALTER TABLE actionneur
    ADD COLUMN IF NOT EXISTS id_salle INT NULL;
ALTER TABLE actionneur
    ADD FOREIGN KEY (id_salle) REFERENCES salle(id_salle) ON DELETE SET NULL;

ALTER TABLE session
    ADD COLUMN IF NOT EXISTS id_salle INT NULL;
ALTER TABLE session
    ADD FOREIGN KEY (id_salle) REFERENCES salle(id_salle) ON DELETE SET NULL;

-- Données initiales
INSERT IGNORE INTO role (nom) VALUES ('joueur'), ('superviseur'), ('administrateur');

-- Version initiale du scénario de démo (si scenario id=1 existe)
INSERT IGNORE INTO scenario_version (id_scenario, numero_version, statut_version, commentaire)
SELECT 1, 'v1.0', 'active', 'Version initiale — démo Raspberry Pi'
FROM scenario WHERE id_scenario = 1 LIMIT 1;
