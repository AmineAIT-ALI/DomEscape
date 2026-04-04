-- =============================================================
-- DomEscape — Schéma SQL complet (BDD v3 — VERSION GELÉE)
-- 20 tables : 13 métier + 3 auth/RBAC + 4 extension multi-sites
-- Base de données : domescape
-- SGBD : MariaDB 10.5+ / MySQL 8.0+
--
-- Ordre de création :
--   1. Catalogues sans dépendance (evenement_type, action_type)
--   2. Extension géographique (site, salle)
--   3. Tables de référence physiques (capteur, actionneur)
--   4. Tables de scénario (scenario, etape, scenario_version)
--   5. Association salle ↔ version (salle_scenario)
--   6. Associations ternaires (etape_attend, etape_declenche)
--   7. Auth/RBAC (utilisateur, role, utilisateur_role)
--   8. Runtime (joueur, session, evenement_session, action_executee)
--   9. Télémétrie (mesure_capteur)
-- =============================================================

CREATE DATABASE IF NOT EXISTS domescape CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE domescape;

-- =============================================================
-- 1. CATALOGUES
-- =============================================================

-- -------------------------------------------------------------
-- EVENEMENT_TYPE — Catalogue des événements métier normalisés
-- -------------------------------------------------------------
CREATE TABLE evenement_type (
    id_type_evenement INT AUTO_INCREMENT PRIMARY KEY,
    code_evenement    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_evenement VARCHAR(100) NOT NULL,
    description       TEXT,
    type_capteur      VARCHAR(50)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ACTION_TYPE — Catalogue des actions possibles sur actionneurs
-- -------------------------------------------------------------
CREATE TABLE action_type (
    id_type_action INT AUTO_INCREMENT PRIMARY KEY,
    code_action    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_action VARCHAR(100) NOT NULL,
    description    TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 2. EXTENSION GÉOGRAPHIQUE
-- =============================================================

-- -------------------------------------------------------------
-- SITE — Un site physique (bâtiment, centre)
-- -------------------------------------------------------------
CREATE TABLE site (
    id_site     INT AUTO_INCREMENT PRIMARY KEY,
    nom_site    VARCHAR(100) NOT NULL,
    description TEXT,
    adresse     VARCHAR(255),
    actif       BOOLEAN      DEFAULT TRUE,
    cree_le     DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- SALLE — Une salle physique au sein d'un site
-- -------------------------------------------------------------
CREATE TABLE salle (
    id_salle    INT AUTO_INCREMENT PRIMARY KEY,
    id_site     INT          NOT NULL,
    nom_salle   VARCHAR(100) NOT NULL,
    description TEXT,
    capacite    INT,
    actif       BOOLEAN      DEFAULT TRUE,
    cree_le     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_salle_site
        FOREIGN KEY (id_site) REFERENCES site(id_site)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 3. TABLES DE RÉFÉRENCE PHYSIQUES
-- =============================================================

-- -------------------------------------------------------------
-- CAPTEUR — Équipements physiques en entrée (door, button, motion)
-- id_salle : NULL en Phase 1 (mono-salle), NOT NULL en Phase 4
-- -------------------------------------------------------------
CREATE TABLE capteur (
    id_capteur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_capteur  VARCHAR(100) NOT NULL,
    type_capteur VARCHAR(50)  NOT NULL,   -- door_sensor | motion_sensor | button
    domoticz_idx INT          NOT NULL UNIQUE,
    emplacement  VARCHAR(100),
    actif        BOOLEAN      DEFAULT TRUE,
    id_salle     INT          NOT NULL,
    CONSTRAINT fk_capteur_salle
        FOREIGN KEY (id_salle) REFERENCES salle(id_salle)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ACTIONNEUR — Équipements physiques en sortie (plug, lcd)
-- id_salle : NULL en Phase 1, NOT NULL en Phase 4
-- -------------------------------------------------------------
CREATE TABLE actionneur (
    id_actionneur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_actionneur  VARCHAR(100) NOT NULL,
    type_actionneur VARCHAR(50)  NOT NULL,   -- plug | lcd
    domoticz_idx    INT          UNIQUE,      -- NULL pour LCD (hors Domoticz)
    emplacement     VARCHAR(100),
    actif           BOOLEAN      DEFAULT TRUE,
    id_salle        INT          NOT NULL,
    CONSTRAINT fk_actionneur_salle
        FOREIGN KEY (id_salle) REFERENCES salle(id_salle)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 4. TABLES DE SCÉNARIO (CONFIGURATION)
-- =============================================================

-- -------------------------------------------------------------
-- SCENARIO — Un scénario interactif configurable
-- -------------------------------------------------------------
CREATE TABLE scenario (
    id_scenario  INT AUTO_INCREMENT PRIMARY KEY,
    nom_scenario VARCHAR(150) NOT NULL,
    description  TEXT,
    theme        VARCHAR(100),
    actif        BOOLEAN      DEFAULT TRUE,
    cree_le      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ETAPE — Une étape / puzzle d'un scénario, dans l'ordre
-- -------------------------------------------------------------
CREATE TABLE etape (
    id_etape          INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario       INT          NOT NULL,
    numero_etape      INT          NOT NULL,
    titre_etape       VARCHAR(150) NOT NULL,
    description_etape TEXT,
    message_succes    TEXT,
    message_echec     TEXT,
    indice            TEXT,
    points            INT          DEFAULT 100,
    finale            BOOLEAN      DEFAULT FALSE,
    CONSTRAINT fk_etape_scenario
        FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- SCENARIO_VERSION — Versionnage d'un scénario
-- Permet de modifier un scénario sans impacter les sessions actives
-- statut_version : draft | active | archived
-- -------------------------------------------------------------
CREATE TABLE scenario_version (
    id_scenario_version INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario         INT          NOT NULL,
    numero_version      VARCHAR(20)  NOT NULL,   -- ex: v1.0, v2.1-beta
    statut_version      VARCHAR(20)  DEFAULT 'draft',
    commentaire         TEXT,
    cree_le             DATETIME     DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scenver_scenario
        FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 5. ASSOCIATION SALLE ↔ VERSION DE SCÉNARIO
-- =============================================================

-- -------------------------------------------------------------
-- SALLE_SCENARIO — Déploiement d'une version de scénario dans une salle
-- configuration_locale : JSON avec idx locaux, messages adaptés, etc.
-- -------------------------------------------------------------
CREATE TABLE salle_scenario (
    id_salle_scenario    INT AUTO_INCREMENT PRIMARY KEY,
    id_salle             INT          NOT NULL,
    id_scenario_version  INT          NOT NULL,
    actif                BOOLEAN      DEFAULT TRUE,
    date_activation      DATETIME,
    configuration_locale TEXT,
    CONSTRAINT fk_ss_salle
        FOREIGN KEY (id_salle) REFERENCES salle(id_salle)
        ON DELETE RESTRICT,
    CONSTRAINT fk_ss_version
        FOREIGN KEY (id_scenario_version) REFERENCES scenario_version(id_scenario_version)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 6. ASSOCIATIONS TERNAIRES (configuration scénario)
-- =============================================================

-- -------------------------------------------------------------
-- ETAPE_ATTEND — Événement attendu pour valider une étape
-- (ternaire : étape × capteur × type_événement)
-- -------------------------------------------------------------
CREATE TABLE etape_attend (
    id_etape          INT     NOT NULL,
    id_capteur        INT     NOT NULL,
    id_type_evenement INT     NOT NULL,
    obligatoire       BOOLEAN DEFAULT TRUE,
    PRIMARY KEY (id_etape, id_capteur, id_type_evenement),
    CONSTRAINT fk_attend_etape
        FOREIGN KEY (id_etape) REFERENCES etape(id_etape)
        ON DELETE CASCADE,
    CONSTRAINT fk_attend_capteur
        FOREIGN KEY (id_capteur) REFERENCES capteur(id_capteur),
    CONSTRAINT fk_attend_evenement
        FOREIGN KEY (id_type_evenement) REFERENCES evenement_type(id_type_evenement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ETAPE_DECLENCHE — Actions à exécuter selon le moment du cycle
-- moment_declenchement : on_enter | on_success | on_failure | on_hint
-- -------------------------------------------------------------
CREATE TABLE etape_declenche (
    id_etape_declenche   INT         AUTO_INCREMENT PRIMARY KEY,
    id_etape             INT         NOT NULL,
    id_actionneur        INT         NOT NULL,
    id_type_action       INT         NOT NULL,
    ordre_action         INT         DEFAULT 1,
    valeur_action        TEXT,
    moment_declenchement VARCHAR(20) NOT NULL DEFAULT 'on_success',
    CONSTRAINT fk_declenche_etape
        FOREIGN KEY (id_etape) REFERENCES etape(id_etape)
        ON DELETE CASCADE,
    CONSTRAINT fk_declenche_actionneur
        FOREIGN KEY (id_actionneur) REFERENCES actionneur(id_actionneur),
    CONSTRAINT fk_declenche_type_action
        FOREIGN KEY (id_type_action) REFERENCES action_type(id_type_action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 7. AUTH / RBAC
-- =============================================================

-- -------------------------------------------------------------
-- UTILISATEUR — Compte de connexion à l'application
-- -------------------------------------------------------------
CREATE TABLE utilisateur (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    email              VARCHAR(255) NOT NULL UNIQUE,
    mot_de_passe       VARCHAR(255) NOT NULL,   -- hash bcrypt coût 12
    actif              TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ROLE — Rôles applicatifs : joueur | superviseur | administrateur
-- -------------------------------------------------------------
CREATE TABLE role (
    id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- UTILISATEUR_ROLE — Association N:N utilisateur ↔ rôle
-- -------------------------------------------------------------
CREATE TABLE utilisateur_role (
    id_utilisateur INT UNSIGNED NOT NULL,
    id_role        INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_utilisateur, id_role),
    CONSTRAINT fk_ur_utilisateur
        FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ur_role
        FOREIGN KEY (id_role) REFERENCES role(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 8. RUNTIME (SESSION & HISTORIQUE)
-- =============================================================

-- -------------------------------------------------------------
-- JOUEUR — Un joueur ou une équipe
-- id_utilisateur : lien optionnel vers un compte (joueur non inscrit possible)
-- -------------------------------------------------------------
CREATE TABLE joueur (
    id_joueur      INT AUTO_INCREMENT PRIMARY KEY,
    nom_joueur     VARCHAR(100) NOT NULL,
    type_joueur    VARCHAR(50)  DEFAULT 'equipe',   -- equipe | individuel | jury
    cree_le        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    id_utilisateur INT UNSIGNED NULL,
    CONSTRAINT fk_joueur_utilisateur
        FOREIGN KEY (id_utilisateur) REFERENCES utilisateur(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- SESSION — Une exécution réelle d'un scénario dans une salle
-- statut_session : en_attente | en_cours | gagnee | perdue | abandonnee
-- id_salle : NULL en Phase 1 (mono-salle), NOT NULL en Phase 4
-- -------------------------------------------------------------
CREATE TABLE session (
    id_session        INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario       INT          NOT NULL,
    id_joueur         INT          NOT NULL,
    id_etape_courante INT          NULL,
    statut_session    VARCHAR(20)  NOT NULL DEFAULT 'en_attente',
    date_debut        DATETIME,
    date_fin          DATETIME,
    score             INT          DEFAULT 0,
    nb_erreurs        INT          DEFAULT 0,
    nb_indices        INT          DEFAULT 0,
    duree_secondes    INT,
    id_salle          INT          NOT NULL,
    CONSTRAINT fk_session_scenario
        FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario),
    CONSTRAINT fk_session_joueur
        FOREIGN KEY (id_joueur) REFERENCES joueur(id_joueur),
    CONSTRAINT fk_session_etape
        FOREIGN KEY (id_etape_courante) REFERENCES etape(id_etape)
        ON DELETE SET NULL,
    CONSTRAINT fk_session_salle
        FOREIGN KEY (id_salle) REFERENCES salle(id_salle)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- EVENEMENT_SESSION — Historique de tous les événements reçus
-- evenement_attendu : TRUE si l'événement correspondait à l'étape
-- valide : TRUE si retenu comme exploitable par le moteur
-- -------------------------------------------------------------
CREATE TABLE evenement_session (
    id_evenement_session INT AUTO_INCREMENT PRIMARY KEY,
    id_session           INT      NOT NULL,
    id_capteur           INT      NULL,
    id_type_evenement    INT      NULL,
    id_etape             INT      NULL,
    date_evenement       DATETIME NOT NULL,
    valeur_brute         TEXT,
    evenement_attendu    BOOLEAN,
    valide               BOOLEAN,
    CONSTRAINT fk_evtsession_session
        FOREIGN KEY (id_session) REFERENCES session(id_session)
        ON DELETE CASCADE,
    CONSTRAINT fk_evtsession_capteur
        FOREIGN KEY (id_capteur) REFERENCES capteur(id_capteur)
        ON DELETE SET NULL,
    CONSTRAINT fk_evtsession_type
        FOREIGN KEY (id_type_evenement) REFERENCES evenement_type(id_type_evenement)
        ON DELETE SET NULL,
    CONSTRAINT fk_evtsession_etape
        FOREIGN KEY (id_etape) REFERENCES etape(id_etape)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- ACTION_EXECUTEE — Historique des actions déclenchées
-- statut_execution : ok | erreur | simulation
-- -------------------------------------------------------------
CREATE TABLE action_executee (
    id_action_executee INT AUTO_INCREMENT PRIMARY KEY,
    id_session         INT         NOT NULL,
    id_actionneur      INT         NULL,
    id_type_action     INT         NULL,
    id_etape           INT         NULL,
    date_execution     DATETIME    NOT NULL,
    valeur_action      TEXT,
    statut_execution   VARCHAR(20) DEFAULT 'ok',
    CONSTRAINT fk_action_session
        FOREIGN KEY (id_session) REFERENCES session(id_session)
        ON DELETE CASCADE,
    CONSTRAINT fk_action_actionneur
        FOREIGN KEY (id_actionneur) REFERENCES actionneur(id_actionneur)
        ON DELETE SET NULL,
    CONSTRAINT fk_action_type
        FOREIGN KEY (id_type_action) REFERENCES action_type(id_type_action)
        ON DELETE SET NULL,
    CONSTRAINT fk_action_etape
        FOREIGN KEY (id_etape) REFERENCES etape(id_etape)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 9. TÉLÉMÉTRIE
-- =============================================================

-- -------------------------------------------------------------
-- MESURE_CAPTEUR — Relevés environnementaux (température, humidité)
-- Séparé d'evenement_session qui gère la logique de jeu
-- -------------------------------------------------------------
CREATE TABLE mesure_capteur (
    id_mesure   INT AUTO_INCREMENT PRIMARY KEY,
    id_capteur  INT             NOT NULL,
    date_mesure DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    temperature DECIMAL(5,2),
    humidite    DECIMAL(5,2),
    CONSTRAINT fk_mesure_capteur
        FOREIGN KEY (id_capteur) REFERENCES capteur(id_capteur)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- SEED DATA
-- =============================================================

-- Catalogue des événements métier normalisés
INSERT INTO evenement_type (code_evenement, libelle_evenement, type_capteur, description) VALUES
('BUTTON_PRESS',        'Bouton — appui simple',   'button',        'Fibaro Button : 1 appui (nvalue=1)'),
('BUTTON_DOUBLE_PRESS', 'Bouton — double appui',   'button',        'Fibaro Button : 2 appuis consécutifs (nvalue=2)'),
('BUTTON_TRIPLE_PRESS', 'Bouton — triple appui',   'button',        'Fibaro Button : 3 appuis consécutifs (nvalue=3)'),
('BUTTON_HOLD',         'Bouton — maintenu',       'button',        'Fibaro Button : appui long (nvalue à vérifier sur hardware)'),
('DOOR_OPEN',           'Porte ouverte',           'door_sensor',   'Capteur porte : état ouvert'),
('DOOR_CLOSE',          'Porte fermée',            'door_sensor',   'Capteur porte : état fermé'),
('MOTION_DETECTED',     'Mouvement détecté',       'motion_sensor', 'Multisensor : mouvement présent'),
('NO_MOTION',           'Aucun mouvement',         'motion_sensor', 'Multisensor : pas de mouvement');

-- Catalogue des actions
INSERT INTO action_type (code_action, libelle_action, description) VALUES
('PLUG_ON',     'Activer prise',    'Active un Wall Plug via Domoticz'),
('PLUG_OFF',    'Désactiver prise', 'Désactive un Wall Plug via Domoticz'),
('LCD_MESSAGE', 'Message LCD',      'Affiche un message sur l\'écran LCD PiFace'),
('LOG_ONLY',    'Log uniquement',   'Enregistre l\'action sans effet physique');

-- Site et salle physiques (Raspberry Pi de démo)
INSERT INTO site (id_site, nom_site, adresse, actif)
VALUES (1, 'DomEscape Lab', 'Raspberry Pi — local', TRUE);

INSERT INTO salle (id_salle, id_site, nom_salle, description, capacite, actif)
VALUES (1, 1, 'Salle 1', 'Salle principale démo Raspberry Pi', 4, TRUE);

-- Capteurs — idx validés sur hardware réel (Raspberry Pi Z-Wave)
INSERT INTO capteur (nom_capteur, type_capteur, domoticz_idx, emplacement, id_salle) VALUES
('Button',        'button',        9,  'Bureau',           1), -- Node 3 — idx 9  : Level (appui simple)
('Porte',         'door_sensor',   25, 'Porte principale', 1), -- Node 5 — idx 25 : Alarm Access Control 6
('Multisensor',   'motion_sensor',  7, 'Centre pièce',     1), -- Node 2 — idx 7  : Alarm Home Security 7
('Button Double', 'button_double', 30, 'Bureau',           1); -- Node 3 — idx 30 : Light/Switch Unknown (double appui)

-- Actionneurs — idx validés sur hardware réel
INSERT INTO actionneur (nom_actionneur, type_actionneur, domoticz_idx, emplacement, id_salle) VALUES
('Wall Plug',  'plug', 13,   'Bureau', 1),  -- Node 4 — idx 13 : Switch
('LCD PiFace', 'lcd',  NULL, 'Bureau', 1);  -- Service Flask Python (hors Domoticz)

-- Auth — Rôles de base
INSERT INTO role (nom) VALUES ('joueur'), ('superviseur'), ('administrateur');

-- Auth — Compte administrateur par défaut (mot de passe : Admin1234!)
-- IMPORTANT : régénérer ce hash via PHP password_hash('Admin1234!', PASSWORD_BCRYPT, ['cost'=>12])
--             avant tout déploiement en production
INSERT INTO utilisateur (nom, email, mot_de_passe, actif)
VALUES (
    'Administrateur',
    'admin@domescape.local',
    '$2y$12$PLACEHOLDER_REGENERATE_BEFORE_DEPLOY_domescape_admin_hash',
    1
);

-- Assigner le rôle administrateur
INSERT INTO utilisateur_role (id_utilisateur, id_role)
SELECT u.id, r.id
FROM utilisateur u, role r
WHERE u.email = 'admin@domescape.local' AND r.nom = 'administrateur';

-- Scénario de démo
INSERT INTO scenario (nom_scenario, description, theme) VALUES
('DomEscape Lab 01', 'Réactivez le système de sécurité du laboratoire en 4 étapes.', 'Laboratoire sécurisé');

-- Version initiale du scénario de démo
INSERT INTO scenario_version (id_scenario, numero_version, statut_version, commentaire) VALUES
(1, 'v1.0', 'active', 'Version initiale — démo Raspberry Pi');

-- Étapes (id_scenario = 1)
INSERT INTO etape (id_scenario, numero_etape, titre_etape, description_etape, message_succes, message_echec, indice, points, finale) VALUES
(1, 1, 'Boot Sequence',
    'Appuyez sur le bouton pour démarrer le système.',
    'Système en ligne. Continuez.',
    'Action incorrecte. Réessayez.',
    'Il y a un bouton sur le bureau.',
    100, FALSE),
(1, 2, 'Secret Door',
    'Ouvrez la porte pour accéder à la zone sécurisée.',
    'Accès autorisé.',
    'Action incorrecte.',
    'La porte est la seule issue.',
    150, FALSE),
(1, 3, 'Motion Scan',
    'Traversez la zone de détection.',
    'Scan validé. Accès final débloqué.',
    'Hors zone. Réessayez.',
    'Passez devant le capteur central.',
    200, FALSE),
(1, 4, 'Final Code',
    'Appuyez deux fois sur le bouton pour sceller le laboratoire.',
    'Félicitations ! Escape réussi.',
    'Action incorrecte. Double appui requis.',
    'Un double appui est nécessaire pour valider la séquence finale.',
    300, TRUE);

-- Événements attendus par étape
-- etape_attend(id_etape, id_capteur, id_type_evenement)
-- id_type_evenement : 1=BUTTON_PRESS 2=BUTTON_DOUBLE_PRESS 5=DOOR_OPEN 7=MOTION_DETECTED
INSERT INTO etape_attend (id_etape, id_capteur, id_type_evenement) VALUES
(1, 1, 1),   -- étape 1 → BUTTON_PRESS       sur Button     (capteur 1, event 1)
(2, 2, 5),   -- étape 2 → DOOR_OPEN          sur Porte      (capteur 2, event 5)
(3, 3, 7),   -- étape 3 → MOTION_DETECTED    sur Multisensor(capteur 3, event 7)
(4, 4, 2);   -- étape 4 → BUTTON_DOUBLE_PRESS sur Button Double (capteur 4, event 2)

-- Actions déclenchées par étape
-- etape_declenche(id_etape, id_actionneur, id_type_action, ordre, valeur, moment)
-- id_actionneur : 1=Wall Plug  2=LCD PiFace
-- id_type_action : 1=PLUG_ON  2=PLUG_OFF  3=LCD_MESSAGE  4=LOG_ONLY
INSERT INTO etape_declenche (id_etape, id_actionneur, id_type_action, ordre_action, valeur_action, moment_declenchement) VALUES
-- Étape 1 — Boot Sequence
(1, 2, 3, 1, 'Systeme en veille...',        'on_enter'),
(1, 2, 3, 1, 'Systeme active. Niveau 1.',   'on_success'),
(1, 1, 1, 2, NULL,                          'on_success'),
(1, 2, 3, 1, 'Sequence invalide.',          'on_failure'),
-- Étape 2 — Secret Door
(2, 2, 3, 1, 'Acces restreint. Entrez.',    'on_enter'),
(2, 2, 3, 1, 'Acces autorise. Niveau 2.',   'on_success'),
(2, 2, 3, 1, 'Intrusion refusee.',          'on_failure'),
-- Étape 3 — Motion Scan
(3, 2, 3, 1, 'Scanner en attente...',       'on_enter'),
(3, 2, 3, 1, 'Scan valide. Niveau 3.',      'on_success'),
(3, 1, 1, 2, NULL,                          'on_success'),
(3, 2, 3, 1, 'Hors perimetre. Stop.',       'on_failure'),
-- Étape 4 — Final Code
(4, 2, 3, 1, 'Code final: double appui.',   'on_enter'),
(4, 2, 3, 1, 'ESCAPE SUCCESSFUL !',         'on_success'),
(4, 1, 1, 2, NULL,                          'on_success'),
(4, 2, 3, 1, 'Code invalide. Reessayez.',   'on_failure');
