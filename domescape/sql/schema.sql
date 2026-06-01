-- =============================================================
-- DomEscape — Core Edition — Schéma SQL complet
-- 13 tables : 11 entités + 2 associations (etape_attend, etape_declenche)
--
-- Tables supprimées vs version précédente :
--   scenario_version, equipe, equipe_utilisateur, session_utilisateur,
--   demande_rejoindre_session, log_rebond, role, utilisateur_role
--
-- Auth simplifiée : is_admin BOOLEAN sur utilisateur (plus de RBAC)
-- Session simplifiée : nom_equipe direct (plus de FK equipe)
-- evenement_session : id_session NOT NULL — on n'insère que si session active
-- Fix : Button Double → type_capteur='button_double' (était 'button')
-- =============================================================

CREATE DATABASE IF NOT EXISTS domescape CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE domescape;

-- =============================================================
-- 1. CATALOGUES
-- =============================================================

-- Catalogue des événements métier normalisés
-- type_capteur lie chaque code à un type de capteur physique
CREATE TABLE evenement_type (
    id_type_evenement INT AUTO_INCREMENT PRIMARY KEY,
    code_evenement    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_evenement VARCHAR(100) NOT NULL,
    description       TEXT,
    type_capteur      VARCHAR(50)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogue des actions possibles sur les actionneurs
CREATE TABLE action_type (
    id_type_action INT AUTO_INCREMENT PRIMARY KEY,
    code_action    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_action VARCHAR(100) NOT NULL,
    description    TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 2. PHYSIQUE — Équipements Z-Wave
-- =============================================================

-- Capteurs Z-Wave : ce qui déclenche des événements
CREATE TABLE capteur (
    id_capteur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_capteur  VARCHAR(100) NOT NULL,
    type_capteur VARCHAR(50)  NOT NULL,   -- door_sensor | motion_sensor | button | button_double
    domoticz_idx INT          NOT NULL UNIQUE,
    emplacement  VARCHAR(100),
    actif        BOOLEAN      DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actionneurs : ce que le système pilote en retour
CREATE TABLE actionneur (
    id_actionneur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_actionneur  VARCHAR(100) NOT NULL,
    type_actionneur VARCHAR(50)  NOT NULL,   -- plug | lcd
    domoticz_idx    INT          UNIQUE,      -- NULL pour LCD (hors Domoticz)
    emplacement     VARCHAR(100),
    actif           BOOLEAN      DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 3. SCÉNARIO — Configuration du jeu
-- =============================================================

CREATE TABLE scenario (
    id_scenario        INT AUTO_INCREMENT PRIMARY KEY,
    nom_scenario       VARCHAR(150) NOT NULL,
    description        TEXT,
    theme              VARCHAR(100),
    actif              BOOLEAN      DEFAULT TRUE,
    duree_max_secondes INT          NULL,   -- NULL = illimitée
    cree_le            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
        ON DELETE CASCADE,
    UNIQUE KEY uq_etape_ordre (id_scenario, numero_etape)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 4. ASSOCIATIONS TERNAIRES — Règles du scénario
-- =============================================================

-- etape_attend : association ternaire pure (clé composite)
-- Une étape attend un type d'événement précis sur un capteur précis.
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

-- etape_declenche : entité-association (clé surrogate)
-- Une étape peut déclencher plusieurs actions ordonnées sur le même actionneur.
-- moment_declenchement : on_enter | on_success | on_failure | on_hint
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
-- 5. AUTH — Simplifié (is_admin remplace role/utilisateur_role)
-- =============================================================

CREATE TABLE utilisateur (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                VARCHAR(100) NOT NULL,
    email              VARCHAR(255) NOT NULL UNIQUE,
    mot_de_passe       VARCHAR(255) NOT NULL,   -- bcrypt coût 12
    is_admin           BOOLEAN      NOT NULL DEFAULT FALSE,
    actif              TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- 6. RUNTIME — Exécution du jeu
-- =============================================================

-- Session : nom_equipe stocké directement (pas de FK equipe)
-- statut_session : en_cours | gagnee | perdue | abandonnee
CREATE TABLE session (
    id_session        INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario       INT          NOT NULL,
    nom_equipe        VARCHAR(100) NOT NULL,
    id_etape_courante INT          NULL,
    statut_session    VARCHAR(20)  NOT NULL DEFAULT 'en_cours',
    date_debut        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin          DATETIME     NULL,
    score             INT          DEFAULT 0,
    nb_erreurs        INT          DEFAULT 0,
    nb_indices        INT          DEFAULT 0,
    duree_secondes    INT          NULL,
    CONSTRAINT fk_session_scenario
        FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario),
    CONSTRAINT fk_session_etape
        FOREIGN KEY (id_etape_courante) REFERENCES etape(id_etape)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique de tous les événements reçus pendant une session active.
-- id_session NOT NULL : on n'insère que si une session est en cours.
-- evenement_attendu : l'événement correspondait-il à l'étape courante ?
-- valide           : toujours 1 (Core Edition — événements hors session non insérés)
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

-- Historique des actions physiques déclenchées pendant une session.
-- statut_execution : ok | erreur
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
-- 7. TÉLÉMÉTRIE
-- =============================================================

-- Relevés température/humidité alimentés par poll_telemetry.php (cron */5 *)
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

-- Catalogue événements (7 codes)
-- BUTTON_DOUBLE_PRESS : type_capteur='button_double' (fix vs version précédente)
-- BUTTON_TRIPLE_PRESS : type_capteur='button_triple' — device Domoticz dédié idx 27
INSERT INTO evenement_type (code_evenement, libelle_evenement, type_capteur, description) VALUES
('BUTTON_PRESS',        'Bouton — appui simple',   'button',        'Fibaro Button : 1 appui (nvalue=1)'),
('BUTTON_DOUBLE_PRESS', 'Bouton — double appui',   'button_double', 'Fibaro Button : 2 appuis consécutifs (idx 30)'),
('DOOR_OPEN',           'Porte ouverte',           'door_sensor',   'Capteur porte : état ouvert'),
('DOOR_CLOSE',          'Porte fermée',            'door_sensor',   'Capteur porte : état fermé'),
('MOTION_DETECTED',     'Mouvement détecté',       'motion_sensor', 'Multisensor : mouvement présent'),
('NO_MOTION',           'Aucun mouvement',         'motion_sensor', 'Multisensor : pas de mouvement'),
('BUTTON_TRIPLE_PRESS', 'Bouton — triple appui',   'button_triple', 'Fibaro Button : 3 appuis consécutifs (idx 27)');

-- Catalogue actions
INSERT INTO action_type (code_action, libelle_action, description) VALUES
('PLUG_ON',     'Activer prise',    'Active un Wall Plug via Domoticz'),
('PLUG_OFF',    'Désactiver prise', 'Désactive un Wall Plug via Domoticz'),
('LCD_MESSAGE', 'Message LCD',      'Affiche un message sur l\'écran LCD PiFace'),
('PLUG_FESTIF', 'Effet festif',     'Séquence de clignotement festif sur le Wall Plug — feux d\'artifice'),
('LOG_ONLY',    'Log uniquement',   'Enregistre sans effet physique');

-- Capteurs — idx validés sur hardware réel
-- Fix : Button Double type_capteur='button_double' (corrige le bug étape 4)
INSERT INTO capteur (nom_capteur, type_capteur, domoticz_idx, emplacement) VALUES
('Button',            'button',        9,  'Bureau'),           -- Node 3 — idx 9  : appui simple
('Porte',             'door_sensor',   25, 'Porte principale'), -- Node 5 — idx 25 : Access Control 6
('Multisensor',       'motion_sensor',  7, 'Centre pièce'),     -- Node 2 — idx 7  : Home Security 7
('Button Double',     'button_double', 30, 'Bureau'),           -- Node 3 — idx 30 : double appui
('Air Temp/Humidity', 'temp_humidity',  8, 'Bureau'),           -- Node 2 — idx 8  : température/humidité (poll_telemetry)
('Button Triple',     'button_triple', 27, 'Bureau');           -- Node 3 — idx 27 : triple appui

-- Actionneurs — idx validés sur hardware réel
INSERT INTO actionneur (nom_actionneur, type_actionneur, domoticz_idx, emplacement) VALUES
('Wall Plug',  'plug', 13,   'Bureau'),  -- Node 4 — idx 13 : Switch
('LCD PiFace', 'lcd',  NULL, 'Bureau');  -- Service Python lcd_service.py (hors Domoticz)

-- Compte administrateur par défaut (mot de passe : Admin1234!)
-- IMPORTANT : régénérer le hash via password_hash('...', PASSWORD_BCRYPT, ['cost'=>12])
INSERT INTO utilisateur (nom, email, mot_de_passe, is_admin, actif)
VALUES (
    'Administrateur',
    'admin@domescape.local',
    '$2y$12$9ohz3j7QQSJKhR8HqsuscOmmZP9ajwLZt7WeIGhKdaNz1apRAHYDW',
    TRUE,
    1
);

-- Scénario de démo
INSERT INTO scenario (nom_scenario, description, theme, duree_max_secondes) VALUES
('DomEscape Lab 01', 'Réactivez le système de sécurité du laboratoire en 4 étapes.', 'Laboratoire sécurisé', 3600);

-- Étapes (id_scenario=1)
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
    'Double appui requis.',
    'Un double appui est nécessaire.',
    300, TRUE);

-- Événements attendus par étape
-- id_type_evenement : 1=BUTTON_PRESS 2=BUTTON_DOUBLE_PRESS 3=DOOR_OPEN 5=MOTION_DETECTED
-- id_capteur        : 1=Button 2=Porte 3=Multisensor 4=Button Double
INSERT INTO etape_attend (id_etape, id_capteur, id_type_evenement) VALUES
(1, 1, 1),   -- étape 1 → BUTTON_PRESS        sur Button
(2, 2, 3),   -- étape 2 → DOOR_OPEN           sur Porte
(3, 3, 5),   -- étape 3 → MOTION_DETECTED     sur Multisensor
(4, 4, 2);   -- étape 4 → BUTTON_DOUBLE_PRESS sur Button Double

-- Actions déclenchées par étape
-- id_actionneur  : 1=Wall Plug  2=LCD PiFace
-- id_type_action : 1=PLUG_ON  2=PLUG_OFF  3=LCD_MESSAGE  4=LOG_ONLY
INSERT INTO etape_declenche (id_etape, id_actionneur, id_type_action, ordre_action, valeur_action, moment_declenchement) VALUES
-- Étape 1 — Boot Sequence (LCD 16 chars max)
(1, 2, 3, 1, 'En veille...',       'on_enter'),
(1, 2, 3, 1, 'Niveau 1 OK !',      'on_success'),
(1, 1, 1, 2, NULL,                 'on_success'),
(1, 2, 3, 1, 'Invalide !',         'on_failure'),
-- Étape 2 — Secret Door
(2, 2, 3, 1, 'Zone restreinte',    'on_enter'),
(2, 2, 3, 1, 'Acces autorise!',    'on_success'),
(2, 2, 3, 1, 'Intrus detecte!',    'on_failure'),
-- Étape 3 — Motion Scan
(3, 2, 3, 1, 'Scan en attente',    'on_enter'),
(3, 2, 3, 1, 'Scan valide !',      'on_success'),
(3, 1, 1, 2, NULL,                 'on_success'),
(3, 2, 3, 1, 'Hors perimetre!',    'on_failure'),
-- Étape 4 — Final Code
(4, 2, 3, 1, 'Double appui !',     'on_enter'),
(4, 2, 3, 1, 'ESCAPE SUCCESS!',    'on_success'),
(4, 1, 1, 2, NULL,                 'on_success'),
(4, 2, 3, 1, 'Code invalide !',    'on_failure');

-- =============================================================
-- Scénario 2 : Protocole Omega : Confinement IA
-- =============================================================

INSERT INTO scenario (nom_scenario, description, theme, duree_max_secondes) VALUES
('Protocole Omega : Confinement IA',
 'Une IA instable a pris le contrôle du laboratoire. Activez les 5 protocoles de confinement pour reprendre le système.',
 'Confinement IA',
 3600);

-- Étapes (id_scenario=2, id_etape 5 à 9)
INSERT INTO etape (id_scenario, numero_etape, titre_etape, description_etape, message_succes, message_echec, indice, points, finale) VALUES
(2, 1, 'Initialisation du noyau',
    'Activez le noyau IA. Triple appui sur le bouton de démarrage.',
    'Noyau IA en ligne.',
    'Action incorrecte. Réessayez.',
    'Trois appuis rapides sur le bouton.',
    100, FALSE),
(2, 2, 'Restauration du secteur',
    'Le réseau électrique est hors ligne. Rétablissez l''alimentation.',
    'Réseau électrique rétabli.',
    'Action incorrecte.',
    'Appuyez sur le bouton principal.',
    150, FALSE),
(2, 3, 'Porte de sécurité',
    'La porte de sécurité bloque l''accès à la zone de contrôle. Franchissez-la.',
    'Accès autorisé. Zone déverrouillée.',
    'Accès refusé.',
    'Franchissez la porte de sécurité.',
    200, FALSE),
(2, 4, 'Scan biométrique',
    'Le scanner biométrique est actif. Traversez la zone de détection.',
    'Identification validée.',
    'Hors zone. Réessayez.',
    'Passez devant le capteur central.',
    200, FALSE),
(2, 5, 'Confinement Omega',
    'Protocole final. Confirmez le confinement par double appui.',
    'Confinement Omega activé. Mission accomplie.',
    'Double confirmation requise.',
    'Un double appui est nécessaire.',
    300, TRUE);

-- Événements attendus (scénario 2, étapes 5 à 9)
-- id_type_evenement : 1=BUTTON_PRESS  2=BUTTON_DOUBLE_PRESS  3=DOOR_OPEN  5=MOTION_DETECTED  7=BUTTON_TRIPLE_PRESS
-- id_capteur        : 1=Button  2=Porte  3=Multisensor  4=Button Double  6=Button Triple
INSERT INTO etape_attend (id_etape, id_capteur, id_type_evenement) VALUES
(5, 6, 7),   -- Initialisation du noyau   : BUTTON_TRIPLE_PRESS sur Button Triple
(6, 1, 1),   -- Restauration du secteur   : BUTTON_PRESS        sur Button
(7, 2, 3),   -- Porte de sécurité         : DOOR_OPEN           sur Porte
(8, 3, 5),   -- Scan biométrique          : MOTION_DETECTED     sur Multisensor
(9, 4, 2);   -- Confinement Omega         : BUTTON_DOUBLE_PRESS sur Button Double

-- Actions déclenchées (scénario 2)
INSERT INTO etape_declenche (id_etape, id_actionneur, id_type_action, ordre_action, valeur_action, moment_declenchement) VALUES
-- Étape 5 : Initialisation du noyau
(5, 2, 3, 1, 'NOYAU INACTIF',    'on_enter'),
(5, 2, 3, 1, 'NOYAU ACTIF',      'on_success'),
(5, 1, 1, 2, NULL,               'on_success'),
(5, 2, 3, 1, 'ERREUR NOYAU',     'on_failure'),
-- Étape 6 : Restauration du secteur
(6, 2, 3, 1, 'SECTEUR COUPE',    'on_enter'),
(6, 2, 3, 1, 'SECTEUR RETABLI',  'on_success'),
(6, 1, 1, 2, NULL,               'on_success'),
(6, 2, 3, 1, 'PANNE SECTEUR',    'on_failure'),
-- Étape 7 : Porte de sécurité
(7, 2, 3, 1, 'PORTE BLOQUEE',    'on_enter'),
(7, 2, 3, 1, 'ACCES AUTORISE',   'on_success'),
(7, 2, 3, 1, 'ACCES REFUSE',     'on_failure'),
-- Étape 8 : Scan biométrique
(8, 2, 3, 1, 'SCAN BIOMETRIQUE', 'on_enter'),
(8, 2, 3, 1, 'SCAN VALIDE',      'on_success'),
(8, 1, 2, 2, NULL,               'on_success'),
(8, 1, 1, 3, NULL,               'on_success'),
(8, 2, 3, 1, 'SCAN ECHOUE',      'on_failure'),
-- Étape 9 : Confinement Omega (finale)
(9, 2, 3, 1, 'DOUBLE APPUI',     'on_enter'),
(9, 2, 3, 1, 'CONFINEMENT OK',   'on_success'),
(9, 1, 4, 2, NULL,               'on_success'),
(9, 2, 3, 1, 'ECHEC OMEGA',      'on_failure');
