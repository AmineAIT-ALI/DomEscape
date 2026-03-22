# Rapport de projet — DomEscape
### Conception et modélisation d'une plateforme d'escape game domotique

---

**Auteur :** Amine AIT-ALI
**Formation :** BUT Informatique
**Date :** Mars 2026
**Dépôt GitHub :** https://github.com/AmineAIT-ALI/DomEscape

---

## Table des matières

1. Introduction
2. Description du sujet
3. Description générale du système
4. Description du site web et intérêt de la base de données
5. Dictionnaire des Données (DD)
6. Modélisation Entité-Association (MCD)
7. Schéma Relationnel (MLD)
8. Exemples de données dans les tables
9. Conclusion

---

## 1. Introduction

Dans le cadre de notre projet de développement logiciel orienté domotique, nous avons conçu et développé **DomEscape**, une plateforme applicative permettant de piloter un escape game physique instrumenté par des capteurs et des actionneurs Z-Wave.

L'objectif du projet est de transformer une pièce réelle en environnement interactif scénarisé. Les joueurs doivent effectuer des actions physiques sur des équipements connectés, tandis qu'un moteur de jeu interprète ces événements en temps réel, vérifie leur cohérence avec le scénario en cours, puis déclenche des réactions adaptées.

Le choix architectural central de DomEscape est de reposer sur un **moteur stateless** : l'application PHP ne conserve aucun état de jeu en mémoire ; l'intégralité de l'état courant est persistée en base de données. Cette décision renforce la robustesse du système, simplifie la reprise après incident et garantit la cohérence entre les différentes interfaces.

Ce rapport présente les principaux éléments de conception du projet, avec un accent particulier sur la base de données, qui constitue à la fois :
- le support de configuration des scénarios,
- le registre de l'état courant des parties,
- et l'historique complet des événements et actions exécutés.

Le document couvre ainsi la description fonctionnelle du projet, l'architecture générale, le rôle du site web, le dictionnaire des données, la modélisation entité-association, le schéma relationnel ainsi que des exemples représentatifs de données.

---

## 2. Description du sujet

### 2.1. Contexte

Un escape game est un jeu physique dans lequel des participants doivent résoudre une série d'énigmes dans un ordre donné afin de progresser dans un scénario, généralement dans un temps limité. DomEscape transpose ce principe dans un environnement domotique réel : les énigmes ne reposent plus uniquement sur des mécanismes statiques, mais sur des interactions avec des équipements connectés.

Dans notre cas, les joueurs interagissent avec des dispositifs physiques tels qu'un bouton, un capteur de porte ou un détecteur de mouvement Z-Wave. Ces interactions sont captées, centralisées, puis interprétées par un moteur logiciel.

### 2.2. Problématique

La problématique principale du projet est la suivante :

> *Comment concevoir une plateforme capable de gérer de manière robuste, cohérente et traçable le déroulement d'un escape game physique piloté par des événements issus de capteurs domotiques ?*

Cette problématique implique plusieurs enjeux :
- recevoir et interpréter des événements matériels en temps réel ;
- garantir la cohérence de l'état de jeu malgré les rebonds matériels ou les événements concurrents ;
- déclencher des actions physiques adaptées à la progression ;
- conserver un historique complet pour le suivi, le débogage et l'analyse ;
- proposer des interfaces distinctes selon les profils utilisateur.

### 2.3. Objectifs

Les objectifs fonctionnels de DomEscape sont les suivants :
- recevoir en temps réel les événements issus des capteurs Z-Wave via Domoticz ;
- faire progresser automatiquement les joueurs dans un scénario entièrement configurable en base de données ;
- déclencher des retours physiques (messages LCD, lampes, prises commandées) à chaque étape ;
- conserver la trace complète des événements reçus et des actions exécutées ;
- fournir une interface distincte pour les joueurs, les superviseurs et les administrateurs ;
- permettre le test du système même en l'absence de matériel réel, grâce à une couche de simulation.

### 2.4. Périmètre matériel

| Équipement | Marque | Rôle |
|---|---|---|
| Raspberry Pi 4 | — | Serveur applicatif + hub Z-Wave |
| Z-Stick Gen5+ | Aeotec | Contrôleur Z-Wave USB |
| Button FGPB-101 | Fibaro | Capteur bouton poussoir |
| Door Sensor 2 FGDW-002 | Fibaro | Capteur d'ouverture de porte |
| Multisensor 6 | Aeotec | Capteur de mouvement |
| Wall Plug FGWPEF-102 | Fibaro | Actionneur prise connectée |
| Écran LCD PiFace | — | Affichage des messages de jeu |

---

## 3. Description générale du système

### 3.1. Architecture globale

```
┌─────────────────────────────────────────────────────────────┐
│                     RASPBERRY PI                            │
│                                                             │
│  Capteurs Z-Wave ──► Domoticz ──► dzVents (Lua)            │
│                                        │                    │
│                                   Webhook HTTP POST         │
│                                        │                    │
│                                   DomEscape (PHP)           │
│                              ┌─────────┴────────┐          │
│                         GameEngine         ActionManager    │
│                              │                   │          │
│                           MariaDB          Domoticz API     │
│                                             + LCD Service   │
└─────────────────────────────────────────────────────────────┘
                                   │
                            Interfaces Web
                    ┌──────────────┼──────────────┐
                 Joueur      Superviseur        Admin
```

### 3.2. Stack technique

| Couche | Technologie | Version |
|---|---|---|
| Langage backend | PHP | 8.4 |
| Serveur web | Apache2 + PHP-FPM | 2.4 / 8.4 |
| Base de données | MariaDB | 11.8 |
| Domotique | Domoticz + dzVents | — |
| Service LCD | Python Flask | — |
| Frontend applicatif | Bootstrap + JavaScript vanilla | 5.3 |
| Site vitrine | HTML / CSS / JavaScript statiques | — |

### 3.3. Principe de fonctionnement

Lorsqu'un joueur effectue une action physique, par exemple en appuyant sur un bouton Z-Wave, cette action suit le flux suivant :

1. le capteur envoie un signal Z-Wave ;
2. Domoticz centralise le changement d'état du device ;
3. un script dzVents déclenche un webhook HTTP POST vers `/api/handle_event.php` ;
4. `EventManager` convertit le payload brut (`idx` + `nvalue`) en événement métier normalisé (`BUTTON_PRESS`, `DOOR_OPEN`…) ;
5. `GameEngine` compare cet événement à ce qui est attendu pour l'étape courante, dans une transaction SQL verrouillée ;
6. si l'événement est correct, la session progresse vers l'étape suivante ;
7. `ActionManager` exécute les retours physiques définis pour l'étape (LCD, lampe, prise) ;
8. les événements et actions sont archivés dans `evenement_session` et `action_executee`.

### 3.4. Choix de conception majeurs

Plusieurs décisions de conception structurent le projet :

**a) Moteur stateless**
Le backend PHP ne conserve aucun état de session en mémoire entre deux requêtes. L'état courant de la partie est relu en base à chaque événement. Ce choix renforce la robustesse du système, simplifie la reprise après incident et garantit la cohérence entre les différentes interfaces.

**b) Configuration en base de données**
Les tables `etape_attend` et `etape_declenche` permettent de décrire n'importe quel scénario sans modifier le code. Le moteur de jeu lit dynamiquement cette configuration à chaque appel.

**c) Séparation des responsabilités**
Le projet distingue clairement :
- la normalisation des événements (`EventManager`) ;
- la logique métier (`GameEngine`) ;
- l'exécution des effets physiques (`ActionManager`).

**d) Observabilité**
Chaque événement reçu et chaque action exécutée sont tracés en base avec horodatage et statut, ce qui facilite le débogage, la supervision et l'analyse post-session.

**e) Protection contre les événements concurrents**
Le moteur traite les événements dans une transaction SQL avec verrouillage (`SELECT ... FOR UPDATE`) afin d'éviter les incohérences dues aux rebonds matériels Z-Wave ou aux déclenchements multiples.

**f) Couche de simulation**
Afin de permettre le test et la démonstration indépendamment du matériel réel, une couche de simulation a été mise en place (`/dev/simulate.php`, `/api/debug_event.php`). Elle permet de reproduire le comportement des capteurs et de valider la logique de jeu sans dépendre du réseau Z-Wave.

### 3.5. Authentification et gestion des rôles

En complément du moteur de jeu, DomEscape intègre une couche applicative d'authentification et de gestion des rôles. Celle-ci permet de distinguer les accès joueur, supervision et administration à travers un modèle RBAC (Role-Based Access Control) stocké en base, sans configuration statique dans le code.

### 3.6. Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **joueur** | Interface de jeu, suivi de sa progression, historique personnel |
| **superviseur** | Lancement / reset de session, suivi temps réel, délivrance d'indices |
| **administrateur** | Gestion complète : utilisateurs, scénarios, configuration |

---

## 4. Description du site web et intérêt de la base de données

### 4.1. Deux composantes complémentaires

Le projet comprend deux ensembles web distincts mais complémentaires.

**a) Le site vitrine (`/website/`)**
Il présente DomEscape comme plateforme et expose son architecture, son positionnement, ses cas d'usage, sa documentation technique et sa feuille de route. Il s'agit d'un ensemble de pages HTML statiques, indépendantes de l'application métier.

**b) L'application métier (`/public/`, `/admin/`, `/api/`)**
Elle permet l'authentification, le lancement des sessions, le suivi du jeu en temps réel, la supervision et l'administration complète de la plateforme.

### 4.2. Pages principales de l'application métier

| Page | Rôle |
|---|---|
| `/public/connexion.php` | Authentification des utilisateurs |
| `/public/inscription.php` | Création de compte joueur |
| `/public/tableau-de-bord.php` | Hub central adapté au rôle de l'utilisateur |
| `/public/player.php` | Interface joueur : étape courante, progression, bouton abandon |
| `/public/gamemaster.php` | Interface superviseur : démarrage, reset, suivi temps réel |
| `/public/mes-sessions.php` | Historique personnel des parties |
| `/admin/dashboard.php` | Tableau de bord administrateur |
| `/admin/utilisateurs.php` | Gestion des comptes et des rôles |
| `/dev/simulate.php` | Simulateur d'événements capteurs (sans hardware) |

### 4.3. APIs exposées

| Endpoint | Méthode | Description |
|---|---|---|
| `/api/handle_event.php` | POST | Réception des webhooks Domoticz |
| `/api/start_game.php` | POST | Démarrage d'une session |
| `/api/session_status.php` | GET | Polling de l'état de la session (toutes les secondes) |
| `/api/reset_game.php` | POST | Réinitialisation (superviseur) |
| `/api/abandon_game.php` | POST | Abandon de partie |
| `/api/debug_event.php` | POST / GET | Simulation d'un événement capteur sans matériel réel |
| `/api/healthcheck.php` | GET | État du système (BDD, Domoticz, LCD) |

L'interface `/dev/simulate.php` s'appuie sur l'endpoint `/api/debug_event.php` pour injecter des événements capteurs simulés directement dans le moteur de jeu, sans dépendre du réseau Z-Wave.

### 4.4. Intérêt de la base de données

La base de données constitue le **point de vérité unique** du système. Elle sert à :

**1. Stocker l'état courant de la partie**
La table `session` contient l'étape courante, le score, le nombre d'erreurs et le statut. Le frontend interroge périodiquement `/api/session_status.php` (polling toutes les secondes) afin de rafraîchir l'affichage sans rechargement de page.

**2. Configurer les scénarios sans toucher au code**
Les tables `scenario`, `etape`, `etape_attend` et `etape_declenche` permettent de créer et modifier n'importe quel scénario directement en base. Le moteur de jeu lit dynamiquement cette configuration à chaque appel.

**3. Tracer l'intégralité des événements et actions**
`evenement_session` enregistre chaque signal capteur reçu. `action_executee` trace chaque action physique déclenchée avec son statut d'exécution. Ces données permettent l'analyse post-session et la détection d'anomalies matérielles.

**4. Garantir la cohérence face aux événements concurrents**
Z-Wave peut générer plusieurs événements identiques en rafale (rebond hardware). `GameEngine::process()` s'exécute dans une transaction avec `SELECT FOR UPDATE` pour éviter les race conditions.

**5. Gérer les accès multi-rôles**
Les tables `utilisateur`, `role` et `utilisateur_role` implémentent un contrôle d'accès basé sur les rôles (RBAC). Cette configuration est entièrement gérée en base, sans aucune configuration statique dans le code.

**6. Supporter la simulation et la validation**
La base est également utilisée dans les outils de simulation, ce qui permet de tester la plateforme en conditions proches du réel sans dépendre immédiatement du matériel Z-Wave.

---

## 5. Dictionnaire des Données (DD)

La base de données de DomEscape est composée de **12 tables métier** dédiées au moteur de jeu, auxquelles s'ajoutent **3 tables applicatives** pour l'authentification et la gestion des rôles, soit **15 tables au total**.

### Tables de référence (catalogues)

#### Table : capteur

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_capteur | INT | PK, AUTO_INCREMENT | Identifiant unique du capteur |
| nom_capteur | VARCHAR(100) | NOT NULL | Nom descriptif du capteur |
| type_capteur | VARCHAR(50) | NOT NULL | Famille : `door_sensor`, `button`, `motion_sensor` |
| domoticz_idx | INT | NOT NULL, UNIQUE | Identifiant Domoticz du device |
| emplacement | VARCHAR(100) | — | Localisation physique dans la pièce |
| actif | BOOLEAN | DEFAULT TRUE | Indique si le capteur est en service |

#### Table : actionneur

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_actionneur | INT | PK, AUTO_INCREMENT | Identifiant unique de l'actionneur |
| nom_actionneur | VARCHAR(100) | NOT NULL | Nom descriptif |
| type_actionneur | VARCHAR(50) | NOT NULL | Famille : `lamp`, `plug`, `lcd` |
| domoticz_idx | INT | UNIQUE, NULL | Identifiant Domoticz (NULL pour le LCD, géré hors Domoticz) |
| emplacement | VARCHAR(100) | — | Localisation physique |
| actif | BOOLEAN | DEFAULT TRUE | Indique si l'actionneur est opérationnel |

#### Table : evenement_type

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_type_evenement | INT | PK, AUTO_INCREMENT | Identifiant du type d'événement |
| code_evenement | VARCHAR(50) | NOT NULL, UNIQUE | Code métier normalisé : `BUTTON_PRESS`, `DOOR_OPEN`… |
| libelle_evenement | VARCHAR(100) | NOT NULL | Libellé lisible |
| description | TEXT | — | Description détaillée |
| type_capteur | VARCHAR(50) | NOT NULL | Famille de capteur associée |

#### Table : action_type

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_type_action | INT | PK, AUTO_INCREMENT | Identifiant du type d'action |
| code_action | VARCHAR(50) | NOT NULL, UNIQUE | Code métier : `LAMP_ON`, `LCD_MESSAGE`… |
| libelle_action | VARCHAR(100) | NOT NULL | Libellé lisible |
| description | TEXT | — | Description de l'effet physique |

### Tables de scénario (configuration)

#### Table : scenario

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_scenario | INT | PK, AUTO_INCREMENT | Identifiant du scénario |
| nom_scenario | VARCHAR(150) | NOT NULL | Titre du scénario |
| description | TEXT | — | Descriptif narratif |
| theme | VARCHAR(100) | — | Thème (ex : Laboratoire, Espionnage…) |
| actif | BOOLEAN | DEFAULT TRUE | Scénario jouable ou archivé |
| cree_le | TIMESTAMP | DEFAULT NOW() | Date de création |

#### Table : etape

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_etape | INT | PK, AUTO_INCREMENT | Identifiant de l'étape |
| id_scenario | INT | FK → scenario | Scénario auquel appartient l'étape |
| numero_etape | INT | NOT NULL | Ordre de l'étape dans le scénario |
| titre_etape | VARCHAR(150) | NOT NULL | Titre affiché |
| description_etape | TEXT | — | Consigne affichée au joueur |
| message_succes | TEXT | — | Message affiché en cas de réussite |
| message_echec | TEXT | — | Message affiché en cas d'erreur |
| indice | TEXT | — | Indice disponible à la demande |
| points | INT | DEFAULT 100 | Points accordés en cas de réussite |
| finale | BOOLEAN | DEFAULT FALSE | TRUE pour la dernière étape (victoire) |

#### Table : etape_attend *(association ternaire)*

Définit quel événement sur quel capteur doit être reçu pour valider une étape.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_etape | INT | PK, FK → etape | Étape concernée |
| id_capteur | INT | PK, FK → capteur | Capteur attendu |
| id_type_evenement | INT | PK, FK → evenement_type | Type d'événement attendu |
| obligatoire | BOOLEAN | DEFAULT TRUE | Condition nécessaire à la validation |

#### Table : etape_declenche *(association ternaire)*

Définit quelles actions exécuter sur quels actionneurs selon le moment du cycle de jeu.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_etape | INT | PK, FK → etape | Étape concernée |
| id_actionneur | INT | PK, FK → actionneur | Actionneur ciblé |
| id_type_action | INT | PK, FK → action_type | Type d'action à exécuter |
| ordre_action | INT | PK, DEFAULT 1 | Ordre d'exécution |
| valeur_action | TEXT | — | Paramètre de l'action (ex : texte LCD) |
| moment_declenchement | VARCHAR(20) | PK | `on_enter`, `on_success`, `on_failure`, `on_hint` |

### Tables de session (runtime)

#### Table : joueur

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_joueur | INT | PK, AUTO_INCREMENT | Identifiant du joueur |
| nom_joueur | VARCHAR(100) | NOT NULL | Nom ou pseudonyme |
| type_joueur | VARCHAR(50) | DEFAULT 'equipe' | `equipe`, `individuel`, `jury` |
| cree_le | TIMESTAMP | DEFAULT NOW() | Date de création |
| id_utilisateur | INT UNSIGNED | FK → utilisateur, NULL | Lien optionnel vers un compte utilisateur |

#### Table : session

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_session | INT | PK, AUTO_INCREMENT | Identifiant de la session |
| id_scenario | INT | FK → scenario | Scénario joué |
| id_joueur | INT | FK → joueur | Joueur ou équipe |
| id_etape_courante | INT | FK → etape, NULL | Étape en cours (NULL en fin de partie) |
| statut_session | VARCHAR(20) | NOT NULL | `en_attente`, `en_cours`, `gagnee`, `perdue`, `abandonnee` |
| date_debut | DATETIME | — | Horodatage du démarrage |
| date_fin | DATETIME | — | Horodatage de la fin |
| score | INT | DEFAULT 0 | Score cumulé |
| nb_erreurs | INT | DEFAULT 0 | Nombre d'erreurs commises |
| nb_indices | INT | DEFAULT 0 | Nombre d'indices demandés |
| duree_secondes | INT | — | Durée totale en secondes (calculée à la fin) |

#### Table : evenement_session

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_evenement_session | INT | PK, AUTO_INCREMENT | Identifiant de l'entrée d'historique |
| id_session | INT | FK → session | Session concernée |
| id_capteur | INT | FK → capteur, NULL | Capteur source de l'événement |
| id_type_evenement | INT | FK → evenement_type, NULL | Type d'événement reçu |
| id_etape | INT | FK → etape, NULL | Étape active au moment de la réception |
| date_evenement | DATETIME | NOT NULL | Horodatage précis |
| valeur_brute | TEXT | — | Payload JSON brut reçu de Domoticz |
| evenement_attendu | BOOLEAN | — | Indique si l'événement reçu correspondait à l'attendu défini pour l'étape courante. |
| valide | BOOLEAN | — | Indique si l'événement a été retenu comme exploitable par le moteur dans le contexte de la session courante. |

#### Table : action_executee

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_action_executee | INT | PK, AUTO_INCREMENT | Identifiant de l'action exécutée |
| id_session | INT | FK → session | Session concernée |
| id_actionneur | INT | FK → actionneur, NULL | Actionneur sollicité |
| id_type_action | INT | FK → action_type, NULL | Type d'action exécutée |
| id_etape | INT | FK → etape, NULL | Étape ayant déclenché l'action |
| date_execution | DATETIME | NOT NULL | Horodatage de l'exécution |
| valeur_action | TEXT | — | Paramètre utilisé (ex : message LCD) |
| statut_execution | VARCHAR(20) | DEFAULT 'ok' | `ok`, `erreur`, `simulation` |

### Tables d'authentification

#### Table : utilisateur

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | Identifiant du compte |
| nom | VARCHAR(100) | NOT NULL | Nom affiché |
| email | VARCHAR(255) | NOT NULL, UNIQUE | Identifiant de connexion |
| mot_de_passe | VARCHAR(255) | NOT NULL | Hash bcrypt (coût 12) |
| actif | TINYINT(1) | DEFAULT 1 | Compte actif ou suspendu |
| cree_le | DATETIME | DEFAULT NOW() | Date de création du compte |
| derniere_connexion | DATETIME | NULL | Dernier login réussi |

#### Table : role

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | Identifiant du rôle |
| nom | VARCHAR(50) | NOT NULL, UNIQUE | `joueur`, `superviseur`, `administrateur` |

#### Table : utilisateur_role

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_utilisateur | INT UNSIGNED | PK, FK → utilisateur | Utilisateur concerné |
| id_role | INT UNSIGNED | PK, FK → role | Rôle attribué |

---

## 6. Modélisation Entité-Association (MCD)

> **Note :** Le schéma MCD présenté ci-dessous est une représentation textuelle des entités et associations. Un diagramme Merise complet (draw.io / diagrams.net) est disponible en complément.

### Entités principales

```
┌───────────────────┐    ┌───────────────────┐
│     CAPTEUR       │    │    ACTIONNEUR      │
│───────────────────│    │───────────────────│
│ # id_capteur      │    │ # id_actionneur   │
│   nom_capteur     │    │   nom_actionneur  │
│   type_capteur    │    │   type_actionneur │
│   domoticz_idx    │    │   domoticz_idx    │
│   emplacement     │    │   emplacement     │
│   actif           │    │   actif           │
└───────────────────┘    └───────────────────┘

┌───────────────────┐    ┌───────────────────┐
│  EVENEMENT_TYPE   │    │   ACTION_TYPE      │
│───────────────────│    │───────────────────│
│ # id_type_evenement │   │ # id_type_action  │
│   code_evenement  │    │   code_action     │
│   libelle_evenement │  │   libelle_action  │
│   type_capteur    │    │   description     │
└───────────────────┘    └───────────────────┘

┌───────────────────┐    ┌───────────────────┐
│    SCENARIO       │    │      ETAPE         │
│───────────────────│    │───────────────────│
│ # id_scenario     │    │ # id_etape        │
│   nom_scenario    │    │   numero_etape    │
│   description     │    │   titre_etape     │
│   theme           │    │   description_etape │
│   actif           │    │   points          │
└───────────────────┘    │   finale          │
                         └───────────────────┘

┌───────────────────┐    ┌───────────────────┐
│     JOUEUR        │    │     SESSION        │
│───────────────────│    │───────────────────│
│ # id_joueur       │    │ # id_session      │
│   nom_joueur      │    │   statut_session  │
│   type_joueur     │    │   date_debut      │
└───────────────────┘    │   date_fin        │
                         │   score           │
                         │   nb_erreurs      │
                         │   duree_secondes  │
                         └───────────────────┘

┌───────────────────┐    ┌───────────────────┐
│   UTILISATEUR     │    │      ROLE          │
│───────────────────│    │───────────────────│
│ # id              │    │ # id              │
│   nom             │    │   nom             │
│   email           │    └───────────────────┘
│   mot_de_passe    │
│   actif           │
└───────────────────┘
```

### Associations et cardinalités

```
SCENARIO ──(1,n)────── contient ──────(1,1)── ETAPE
    │                                              │
   (1,n)                               ┌──────────┴────────────┐
    │                                  │                        │
  SESSION                           ATTEND               DECLENCHE
    │                           (ternaire)             (ternaire)
  /   \                         /          \           /          \
(1,n)(1,n)               CAPTEUR   EVENEMENT_TYPE   ACTIONNEUR  ACTION_TYPE
  │     │
JOUEUR  ETAPE
    │
   (0,1)
    │
UTILISATEUR ──(n,n)── ROLE
          [via utilisateur_role]
```

### Description des associations

| Association | Entités | Cardinalités | Description |
|---|---|---|---|
| contient | SCENARIO — ETAPE | 1,n — 1,1 | Un scénario est composé d'une ou plusieurs étapes ordonnées |
| joue | SESSION — SCENARIO | n,1 | Une session correspond à l'exécution d'un scénario précis |
| appartient_à | SESSION — JOUEUR | n,1 | Une session est associée à un joueur ou une équipe |
| est_à | SESSION — ETAPE | n,0..1 | Une session pointe vers l'étape active (NULL en fin de partie) |
| attend | ETAPE — CAPTEUR — EVENEMENT_TYPE | ternaire | Définit l'événement à recevoir sur quel capteur pour valider l'étape |
| déclenche | ETAPE — ACTIONNEUR — ACTION_TYPE | ternaire | Définit les actions à exécuter selon le moment (on_enter, on_success…) |
| génère | SESSION — EVENEMENT_SESSION | 1,n | Chaque signal capté durant une session est tracé |
| produit | SESSION — ACTION_EXECUTEE | 1,n | Chaque action physique déclenchée est tracée |
| est | JOUEUR — UTILISATEUR | 0..1,1 | Un joueur peut être lié à un compte utilisateur (optionnel) |
| possède | UTILISATEUR — ROLE | n,n | Un utilisateur peut avoir plusieurs rôles |

### Justification des associations ternaires

Les associations `etape_attend` et `etape_declenche` sont **ternaires** car la relation ne peut pas être réduite à une paire d'entités sans perte d'information :

- `etape_attend` relie simultanément une **étape**, un **capteur** et un **type d'événement**. Ce n'est pas "une étape attend un capteur" ni "une étape attend un événement" — c'est précisément "cette étape attend cet événement sur ce capteur".
- `etape_declenche` relie une **étape**, un **actionneur**, un **type d'action** et un **moment** (`on_enter`, `on_success`…). La décomposition binaire ne permettrait pas d'exprimer la combinaison complète.

Ce choix évite toute duplication de données et rend la configuration des scénarios entièrement déclarative.

---

## 7. Schéma Relationnel (MLD)

Les attributs soulignés sont des clés primaires. Les attributs en italique sont des clés étrangères.

```
capteur (
    <u>id_capteur</u>,
    nom_capteur,
    type_capteur,
    domoticz_idx,
    emplacement,
    actif
)

actionneur (
    <u>id_actionneur</u>,
    nom_actionneur,
    type_actionneur,
    domoticz_idx,
    emplacement,
    actif
)

evenement_type (
    <u>id_type_evenement</u>,
    code_evenement,
    libelle_evenement,
    description,
    type_capteur
)

action_type (
    <u>id_type_action</u>,
    code_action,
    libelle_action,
    description
)

scenario (
    <u>id_scenario</u>,
    nom_scenario,
    description,
    theme,
    actif,
    cree_le
)

etape (
    <u>id_etape</u>,
    _id_scenario_ → scenario(id_scenario),
    numero_etape,
    titre_etape,
    description_etape,
    message_succes,
    message_echec,
    indice,
    points,
    finale
)

joueur (
    <u>id_joueur</u>,
    nom_joueur,
    type_joueur,
    cree_le,
    _id_utilisateur_ → utilisateur(id)
)

session (
    <u>id_session</u>,
    _id_scenario_ → scenario(id_scenario),
    _id_joueur_ → joueur(id_joueur),
    _id_etape_courante_ → etape(id_etape),
    statut_session,
    date_debut,
    date_fin,
    score,
    nb_erreurs,
    nb_indices,
    duree_secondes
)

etape_attend (
    <u>id_etape</u> → etape(id_etape),
    <u>id_capteur</u> → capteur(id_capteur),
    <u>id_type_evenement</u> → evenement_type(id_type_evenement),
    obligatoire
)

etape_declenche (
    <u>id_etape</u> → etape(id_etape),
    <u>id_actionneur</u> → actionneur(id_actionneur),
    <u>id_type_action</u> → action_type(id_type_action),
    <u>ordre_action</u>,
    <u>moment_declenchement</u>,
    valeur_action
)

evenement_session (
    <u>id_evenement_session</u>,
    _id_session_ → session(id_session),
    _id_capteur_ → capteur(id_capteur),
    _id_type_evenement_ → evenement_type(id_type_evenement),
    _id_etape_ → etape(id_etape),
    date_evenement,
    valeur_brute,
    evenement_attendu,
    valide
)

action_executee (
    <u>id_action_executee</u>,
    _id_session_ → session(id_session),
    _id_actionneur_ → actionneur(id_actionneur),
    _id_type_action_ → action_type(id_type_action),
    _id_etape_ → etape(id_etape),
    date_execution,
    valeur_action,
    statut_execution
)

utilisateur (
    <u>id</u>,
    nom,
    email,
    mot_de_passe,
    actif,
    cree_le,
    derniere_connexion
)

role (
    <u>id</u>,
    nom
)

utilisateur_role (
    <u>id_utilisateur</u> → utilisateur(id),
    <u>id_role</u> → role(id)
)
```

---

## 8. Exemples de données dans les tables

Les exemples suivants ont pour objectif d'illustrer la structure et l'usage des principales tables. Il ne s'agit pas d'un export exhaustif, mais d'un jeu de données représentatif du fonctionnement de la plateforme.

### capteur

| id_capteur | nom_capteur | type_capteur | domoticz_idx | emplacement | actif |
|---|---|---|---|---|---|
| 1 | Fibaro Button | button | 5 | Bureau | 1 |
| 2 | Door Sensor | door_sensor | 8 | Porte principale | 1 |
| 3 | Multisensor | motion_sensor | 10 | Centre pièce | 1 |

### actionneur

| id_actionneur | nom_actionneur | type_actionneur | domoticz_idx | emplacement | actif |
|---|---|---|---|---|---|
| 1 | Wall Plug | plug | 4 | Bureau | 1 |
| 2 | LCD PiFace | lcd | NULL | Bureau | 1 |

### evenement_type

| id_type_evenement | code_evenement | libelle_evenement | type_capteur |
|---|---|---|---|
| 1 | BUTTON_PRESS | Bouton — appui simple | button |
| 2 | BUTTON_DOUBLE_PRESS | Bouton — double appui | button |
| 3 | BUTTON_TRIPLE_PRESS | Bouton — triple appui | button |
| 4 | BUTTON_HOLD | Bouton — maintenu | button |
| 5 | DOOR_OPEN | Porte ouverte | door_sensor |
| 6 | DOOR_CLOSE | Porte fermée | door_sensor |
| 7 | MOTION_DETECTED | Mouvement détecté | motion_sensor |
| 8 | NO_MOTION | Aucun mouvement | motion_sensor |

### action_type

| id_type_action | code_action | libelle_action | description |
|---|---|---|---|
| 1 | PLUG_ON | Activer prise | Active un Wall Plug via Domoticz |
| 2 | PLUG_OFF | Désactiver prise | Désactive un Wall Plug via Domoticz |
| 3 | LCD_MESSAGE | Message LCD | Affiche un message sur l'écran LCD |
| 4 | LOG_ONLY | Log uniquement | Enregistre sans effet physique |

### scenario

| id_scenario | nom_scenario | theme | actif |
|---|---|---|---|
| 1 | DomEscape Lab 01 | Laboratoire sécurisé | 1 |
| 2 | Mission Infiltration | Espionnage | 1 |
| 3 | L'Héritage du Savant | Mystère | 0 |

### etape

| id_etape | id_scenario | numero_etape | titre_etape | points | finale |
|---|---|---|---|---|---|
| 1 | 1 | 1 | Boot Sequence | 100 | 0 |
| 2 | 1 | 2 | Secret Door | 150 | 0 |
| 3 | 1 | 3 | Motion Scan | 200 | 0 |
| 4 | 1 | 4 | Final Code | 300 | 1 |
| 5 | 2 | 1 | Neutraliser l'alarme | 100 | 0 |

### etape_attend

| id_etape | id_capteur | id_type_evenement | obligatoire |
|---|---|---|---|
| 1 | 1 | 1 | 1 |
| 2 | 2 | 5 | 1 |
| 3 | 3 | 7 | 1 |
| 4 | 1 | 2 | 1 |
| 5 | 1 | 1 | 1 |

### etape_declenche

| id_etape | id_actionneur | id_type_action | ordre_action | valeur_action | moment_declenchement |
|---|---|---|---|---|---|
| 1 | 2 | 3 | 1 | Appuyez sur le bouton | on_enter |
| 1 | 2 | 3 | 1 | Système en ligne. | on_success |
| 1 | 1 | 1 | 2 | NULL | on_success |
| 1 | 2 | 3 | 1 | Action incorrecte. | on_failure |
| 2 | 2 | 3 | 1 | Ouvrez la porte sécurisée | on_enter |

### joueur

| id_joueur | nom_joueur | type_joueur | id_utilisateur |
|---|---|---|---|
| 1 | Équipe Alpha | equipe | NULL |
| 2 | Équipe Beta | equipe | NULL |
| 3 | Marie Dupont | individuel | 2 |
| 4 | Thomas Martin | individuel | 3 |
| 5 | Jury IUT | jury | NULL |

### session

| id_session | id_scenario | id_joueur | statut_session | score | nb_erreurs | duree_secondes |
|---|---|---|---|---|---|---|
| 1 | 1 | 1 | gagnee | 750 | 2 | 1842 |
| 2 | 1 | 2 | perdue | 250 | 8 | 3600 |
| 3 | 1 | 3 | gagnee | 650 | 4 | 2210 |
| 4 | 2 | 4 | abandonnee | 100 | 1 | NULL |
| 5 | 1 | 5 | gagnee | 750 | 0 | 1520 |

### evenement_session

| id_evenement_session | id_session | id_capteur | id_type_evenement | id_etape | evenement_attendu | valide |
|---|---|---|---|---|---|---|
| 1 | 1 | 1 | 1 | 1 | 1 | 1 |
| 2 | 1 | 3 | 7 | 2 | 0 | 1 |
| 3 | 1 | 2 | 5 | 2 | 1 | 1 |
| 4 | 1 | 3 | 7 | 3 | 1 | 1 |
| 5 | 1 | 1 | 2 | 4 | 1 | 1 |
| 6 | 2 | 1 | 1 | 1 | 1 | 1 |
| 7 | 2 | 1 | 1 | 2 | 0 | 1 |
| 8 | 3 | 1 | 1 | 1 | 1 | 1 |

### action_executee

| id_action_executee | id_session | id_actionneur | id_type_action | id_etape | valeur_action | statut_execution |
|---|---|---|---|---|---|---|
| 1 | 1 | 2 | 3 | 1 | Appuyez sur le bouton | ok |
| 2 | 1 | 2 | 3 | 1 | Système en ligne. | ok |
| 3 | 1 | 1 | 1 | 1 | NULL | ok |
| 4 | 1 | 2 | 3 | 2 | Accès autorisé. | ok |
| 5 | 1 | 2 | 3 | 4 | ESCAPE SUCCESSFUL ! | ok |
| 6 | 2 | 2 | 3 | 1 | Action incorrecte. | ok |

### utilisateur

| id | nom | email | actif | cree_le |
|---|---|---|---|---|
| 1 | Administrateur | admin@domescape.local | 1 | 2026-03-21 09:00:00 |
| 2 | Marie Dupont | marie@domescape.local | 1 | 2026-03-21 10:00:00 |
| 3 | Thomas Martin | thomas@domescape.local | 1 | 2026-03-21 10:15:00 |
| 4 | Superviseur 1 | gm1@domescape.local | 1 | 2026-03-21 12:00:00 |
| 5 | Superviseur 2 | gm2@domescape.local | 1 | 2026-03-21 12:05:00 |

### role

| id | nom |
|---|---|
| 1 | joueur |
| 2 | superviseur |
| 3 | administrateur |

### utilisateur_role

| id_utilisateur | id_role |
|---|---|
| 1 | 3 |
| 1 | 2 |
| 2 | 1 |
| 3 | 1 |
| 4 | 2 |
| 5 | 2 |

---

## 9. Conclusion

DomEscape est une plateforme applicative complète illustrant l'intégration d'un système logiciel avec un environnement physique instrumenté. À travers ce projet, nous avons conçu une architecture capable de recevoir des événements matériels, de les interpréter en temps réel, puis de piloter des réactions physiques cohérentes avec un scénario de jeu.

La base de données joue un rôle central dans cette architecture. Elle ne constitue pas un simple espace de stockage, mais un véritable support de configuration, d'exécution et de traçabilité des scénarios, des sessions et des interactions physiques.

Plusieurs choix de conception structurants ressortent du projet :
- un **moteur de jeu stateless**, sans état applicatif conservé en mémoire ;
- une **configuration entièrement pilotée par les données**, via des associations ternaires (`etape_attend`, `etape_declenche`) ;
- une **séparation nette des responsabilités** entre normalisation des événements, logique métier et exécution des effets physiques ;
- une **gestion des accès fondée sur les rôles** (RBAC), centralisée en base ;
- un **mécanisme transactionnel** (`SELECT FOR UPDATE`) garantissant la cohérence du jeu face aux événements concurrents et aux rebonds matériels Z-Wave ;
- une **couche de simulation** permettant de valider la logique de jeu sans dépendre du matériel réel.

La modélisation retenue permet d'obtenir un système à la fois robuste, extensible et analysable. Elle ouvre également la voie à des évolutions futures, telles que les étapes chronométrées, les embranchements de scénario, les événements composites ou le support multi-salles.

À ce stade, le projet est techniquement prêt à être validé en environnement matériel réel sur Raspberry Pi avec les équipements Z-Wave. Les prochaines étapes consistent principalement à finaliser l'intégration physique, à valider les flux en conditions réelles et à consolider l'expérience de démonstration.

DomEscape illustre ainsi comment une modélisation rigoureuse des données permet de construire un système interactif physique cohérent, configurable et robuste, à l'interface entre logiciel, base de données et environnement réel.
