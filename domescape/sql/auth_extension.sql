-- ============================================================
-- DomEscape — Extension authentification & autorisation
-- À exécuter UNE SEULE FOIS sur la BDD domescape
-- ============================================================

-- Table des utilisateurs
CREATE TABLE IF NOT EXISTS utilisateur (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(100)  NOT NULL,
    email          VARCHAR(255)  NOT NULL UNIQUE,
    mot_de_passe   VARCHAR(255)  NOT NULL,
    actif          TINYINT(1)    NOT NULL DEFAULT 1,
    cree_le        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des rôles
CREATE TABLE IF NOT EXISTS role (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom   VARCHAR(50) NOT NULL UNIQUE   -- 'joueur' | 'superviseur' | 'administrateur'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table de liaison utilisateur ↔ rôle (N:N)
CREATE TABLE IF NOT EXISTS utilisateur_role (
    id_utilisateur INT UNSIGNED NOT NULL,
    id_role        INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_utilisateur, id_role),
    CONSTRAINT fk_ur_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role        FOREIGN KEY (id_role)        REFERENCES role(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lien optionnel entre un joueur et un compte utilisateur
-- (exécuter seulement si la colonne n'existe pas encore)
ALTER TABLE joueur
    ADD COLUMN id_utilisateur INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_joueur_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id) ON DELETE SET NULL;

-- ============================================================
-- Données initiales
-- ============================================================

-- Rôles de base
INSERT IGNORE INTO role (nom) VALUES ('joueur'), ('superviseur'), ('administrateur');

-- Compte administrateur par défaut  (mot de passe : Admin1234!)
-- IMPORTANT : changer ce mot de passe en production
INSERT IGNORE INTO utilisateur (nom, email, mot_de_passe, actif)
VALUES (
    'Administrateur',
    'admin@domescape.local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- Admin1234!
    1
);

-- Assigner le rôle administrateur au compte par défaut
INSERT IGNORE INTO utilisateur_role (id_utilisateur, id_role)
SELECT u.id, r.id
FROM utilisateur u, role r
WHERE u.email = 'admin@domescape.local' AND r.nom = 'administrateur';
