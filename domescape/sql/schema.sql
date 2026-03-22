-- =============================================================
-- DomEscape — Schema SQL (MLD v2)
-- Base de données : domescape
-- SGBD : MariaDB / MySQL
-- =============================================================

CREATE DATABASE IF NOT EXISTS domescape CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE domescape;

-- -------------------------------------------------------------
-- CAPTEUR
-- Équipements physiques en entrée (door, button, motion)
-- -------------------------------------------------------------
CREATE TABLE capteur (
    id_capteur    INT AUTO_INCREMENT PRIMARY KEY,
    nom_capteur   VARCHAR(100) NOT NULL,
    type_capteur  VARCHAR(50)  NOT NULL,   -- door_sensor | motion_sensor | button
    domoticz_idx  INT          NOT NULL UNIQUE,
    emplacement   VARCHAR(100),
    actif         BOOLEAN      DEFAULT TRUE
);

-- -------------------------------------------------------------
-- ACTIONNEUR
-- Équipements physiques en sortie (plug, lcd)
-- -------------------------------------------------------------
CREATE TABLE actionneur (
    id_actionneur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_actionneur  VARCHAR(100) NOT NULL,
    type_actionneur VARCHAR(50)  NOT NULL,   -- plug | lcd
    domoticz_idx    INT          UNIQUE,      -- NULL pour LCD (hors Domoticz)
    emplacement     VARCHAR(100),
    actif           BOOLEAN      DEFAULT TRUE
);

-- -------------------------------------------------------------
-- EVENEMENT_TYPE
-- Catalogue des événements métier normalisés
-- -------------------------------------------------------------
CREATE TABLE evenement_type (
    id_type_evenement INT AUTO_INCREMENT PRIMARY KEY,
    code_evenement    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_evenement VARCHAR(100) NOT NULL,
    description       TEXT,
    type_capteur      VARCHAR(50)  NOT NULL   -- device_type associé
);

-- -------------------------------------------------------------
-- ACTION_TYPE
-- Catalogue des actions possibles sur les actionneurs
-- -------------------------------------------------------------
CREATE TABLE action_type (
    id_type_action INT AUTO_INCREMENT PRIMARY KEY,
    code_action    VARCHAR(50)  NOT NULL UNIQUE,
    libelle_action VARCHAR(100) NOT NULL,
    description    TEXT
);

-- -------------------------------------------------------------
-- SCENARIO
-- Un scénario interactif configurable
-- -------------------------------------------------------------
CREATE TABLE scenario (
    id_scenario  INT AUTO_INCREMENT PRIMARY KEY,
    nom_scenario VARCHAR(150) NOT NULL,
    description  TEXT,
    theme        VARCHAR(100),
    actif        BOOLEAN      DEFAULT TRUE,
    cree_le      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------------
-- ETAPE
-- Une étape / puzzle d'un scénario, dans l'ordre
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
);

-- -------------------------------------------------------------
-- JOUEUR
-- Un joueur ou une équipe
-- -------------------------------------------------------------
CREATE TABLE joueur (
    id_joueur   INT AUTO_INCREMENT PRIMARY KEY,
    nom_joueur  VARCHAR(100) NOT NULL,
    type_joueur VARCHAR(50)  DEFAULT 'equipe',   -- equipe | individuel | jury
    cree_le     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------------
-- SESSION
-- Une exécution réelle d'un scénario
-- statut_session : en_attente | en_cours | gagnee | perdue | abandonnee
-- -------------------------------------------------------------
CREATE TABLE session (
    id_session       INT AUTO_INCREMENT PRIMARY KEY,
    id_scenario      INT          NOT NULL,
    id_joueur        INT          NOT NULL,
    id_etape_courante INT,
    statut_session   VARCHAR(20)  NOT NULL DEFAULT 'en_attente',
    date_debut       DATETIME,
    date_fin         DATETIME,
    score            INT          DEFAULT 0,
    nb_erreurs       INT          DEFAULT 0,
    nb_indices       INT          DEFAULT 0,
    duree_secondes   INT,
    CONSTRAINT fk_session_scenario
        FOREIGN KEY (id_scenario) REFERENCES scenario(id_scenario),
    CONSTRAINT fk_session_joueur
        FOREIGN KEY (id_joueur) REFERENCES joueur(id_joueur),
    CONSTRAINT fk_session_etape
        FOREIGN KEY (id_etape_courante) REFERENCES etape(id_etape)
        ON DELETE SET NULL
);

-- -------------------------------------------------------------
-- ETAPE_ATTEND
-- Association ternaire : événement attendu pour valider une étape
-- (une étape attend un événement précis sur un capteur précis)
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
);

-- -------------------------------------------------------------
-- ETAPE_DECLENCHE
-- Association ternaire : actions à exécuter selon le moment
-- moment_declenchement : on_enter | on_success | on_failure | on_hint
-- -------------------------------------------------------------
CREATE TABLE etape_declenche (
    id_etape              INT         NOT NULL,
    id_actionneur         INT         NOT NULL,
    id_type_action        INT         NOT NULL,
    ordre_action          INT         DEFAULT 1,
    valeur_action         TEXT,
    moment_declenchement  VARCHAR(20) NOT NULL DEFAULT 'on_success',
    PRIMARY KEY (id_etape, id_actionneur, id_type_action, ordre_action, moment_declenchement),
    CONSTRAINT fk_declenche_etape
        FOREIGN KEY (id_etape) REFERENCES etape(id_etape)
        ON DELETE CASCADE,
    CONSTRAINT fk_declenche_actionneur
        FOREIGN KEY (id_actionneur) REFERENCES actionneur(id_actionneur),
    CONSTRAINT fk_declenche_type_action
        FOREIGN KEY (id_type_action) REFERENCES action_type(id_type_action)
);

-- -------------------------------------------------------------
-- EVENEMENT_SESSION
-- Historique de tous les événements reçus pendant une session
-- valide : TRUE si l'événement correspondait à l'étape courante
-- -------------------------------------------------------------
CREATE TABLE evenement_session (
    id_evenement_session INT AUTO_INCREMENT PRIMARY KEY,
    id_session           INT      NOT NULL,
    id_capteur           INT,
    id_type_evenement    INT,
    id_etape             INT,
    date_evenement       DATETIME NOT NULL,
    valeur_brute         TEXT,               -- payload JSON brut Domoticz
    evenement_attendu    BOOLEAN,            -- correspondait à l'attendu ?
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
);

-- -------------------------------------------------------------
-- ACTION_EXECUTEE
-- Historique des actions déclenchées par le moteur
-- statut_execution : ok | erreur | simulation
-- -------------------------------------------------------------
CREATE TABLE action_executee (
    id_action_executee INT AUTO_INCREMENT PRIMARY KEY,
    id_session         INT         NOT NULL,
    id_actionneur      INT,
    id_type_action     INT,
    id_etape           INT,
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
);

-- =============================================================
-- SEED DATA
-- =============================================================

-- Catalogue des événements
INSERT INTO evenement_type (code_evenement, libelle_evenement, type_capteur, description) VALUES
('BUTTON_PRESS',         'Bouton — appui simple',  'button', 'Fibaro Button : 1 appui (nvalue=1)'),
('BUTTON_DOUBLE_PRESS',  'Bouton — double appui',  'button', 'Fibaro Button : 2 appuis consécutifs (nvalue=2)'),
('BUTTON_TRIPLE_PRESS',  'Bouton — triple appui',  'button', 'Fibaro Button : 3 appuis consécutifs (nvalue=3)'),
('DOOR_OPEN',       'Porte ouverte',        'door_sensor',  'Capteur porte : état ouvert'),
('DOOR_CLOSE',      'Porte fermée',         'door_sensor',  'Capteur porte : état fermé'),
('MOTION_DETECTED', 'Mouvement détecté',    'motion_sensor','Multisensor : mouvement présent'),
('NO_MOTION',       'Aucun mouvement',      'motion_sensor','Multisensor : pas de mouvement');

-- Catalogue des actions
INSERT INTO action_type (code_action, libelle_action, description) VALUES
('PLUG_ON',     'Activer prise',    'Active un Wall Plug via Domoticz'),
('PLUG_OFF',    'Désactiver prise', 'Désactive un Wall Plug via Domoticz'),
('LCD_MESSAGE', 'Message LCD',      'Affiche un message sur l\'écran LCD PiFace'),
('LOG_ONLY',    'Log uniquement',   'Enregistre l\'action sans effet physique');

-- Capteurs (idx à adapter selon Domoticz)
INSERT INTO capteur (nom_capteur, type_capteur, domoticz_idx, emplacement) VALUES
('Fibaro Button',  'button',       5,  'Bureau'),
('Door Sensor',    'door_sensor',  8,  'Porte principale'),
('Multisensor',    'motion_sensor',10, 'Centre pièce');

-- Actionneurs
INSERT INTO actionneur (nom_actionneur, type_actionneur, domoticz_idx, emplacement) VALUES
('Wall Plug',        'plug', 4,    'Bureau'),
('LCD PiFace',       'lcd',  NULL, 'Bureau');

-- Scénario de démo
INSERT INTO scenario (nom_scenario, description, theme) VALUES
('DomEscape Lab 01', 'Réactivez le système de sécurité du laboratoire en 4 étapes.', 'Laboratoire sécurisé');

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
-- ETAPE_ATTEND(id_etape, id_capteur, id_type_evenement)
INSERT INTO etape_attend (id_etape, id_capteur, id_type_evenement) VALUES
(1, 1, 1),   -- étape 1 → BUTTON_PRESS (id=1) sur Fibaro Button
(2, 2, 4),   -- étape 2 → DOOR_OPEN (id=4) sur Door Sensor
(3, 3, 6),   -- étape 3 → MOTION_DETECTED (id=6) sur Multisensor
(4, 1, 2);   -- étape 4 → BUTTON_DOUBLE_PRESS (id=2) sur Fibaro Button

-- Actions déclenchées par étape
-- ETAPE_DECLENCHE(id_etape, id_actionneur, id_type_action, ordre, valeur, moment)
INSERT INTO etape_declenche (id_etape, id_actionneur, id_type_action, ordre_action, valeur_action, moment_declenchement) VALUES
-- Étape 1  (actionneur 2=LCD, action 3=LCD_MESSAGE | actionneur 1=Wall Plug, action 1=PLUG_ON)
(1, 2, 3, 1, 'Appuyez sur le bouton',      'on_enter'),
(1, 2, 3, 1, 'Système en ligne.',           'on_success'),
(1, 1, 1, 2, NULL,                          'on_success'),
(1, 2, 3, 1, 'Action incorrecte.',          'on_failure'),
-- Étape 2
(2, 2, 3, 1, 'Ouvrez la porte sécurisée',  'on_enter'),
(2, 2, 3, 1, 'Accès autorisé.',             'on_success'),
(2, 2, 3, 1, 'Action incorrecte.',          'on_failure'),
-- Étape 3
(3, 2, 3, 1, 'Traversez la zone de scan',  'on_enter'),
(3, 2, 3, 1, 'Scan validé.',               'on_success'),
(3, 1, 1, 2, NULL,                          'on_success'),
(3, 2, 3, 1, 'Hors zone.',                 'on_failure'),
-- Étape 4
(4, 2, 3, 1, 'Refermez la porte',          'on_enter'),
(4, 2, 3, 1, 'ESCAPE SUCCESSFUL !',        'on_success'),
(4, 1, 1, 2, NULL,                          'on_success'),
(4, 2, 3, 1, 'Action incorrecte.',         'on_failure');
