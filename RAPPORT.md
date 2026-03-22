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
4. Description du site Web et intérêt de la base de données
5. Dictionnaire des Données (DD)
6. Modélisation Entité-Association (MCD)
7. Schéma Relationnel (MLD)
8. Exemples de données dans les tables
9. Conclusion

---

## 1. Introduction

Dans le cadre de notre projet de développement logiciel orienté domotique, nous avons conçu et développé **DomEscape** : une plateforme applicative complète permettant de piloter un escape game physique instrumenté de capteurs et d'actionneurs Z-Wave.

Ce rapport présente l'ensemble des choix conceptuels et techniques retenus, avec un accent particulier sur la modélisation de la base de données, véritable cœur du système. En effet, DomEscape repose sur une architecture **stateless** : tout l'état de jeu est persisté en base de données. Scénarios, étapes, événements capteurs, actions physiques et sessions joueurs sont intégralement tracés, sans aucun état conservé en mémoire dans le code applicatif.

Le rapport couvre la description fonctionnelle du projet, le dictionnaire des données, la modélisation entité-association, le schéma relationnel et des exemples de données représentatifs.

---

## 2. Description du sujet

### Contexte

Un escape game est un jeu physique dans lequel des participants enfermés dans une pièce doivent résoudre une série d'énigmes dans un temps limité pour « s'évader ». DomEscape transpose ce concept dans un environnement domotique réel : les énigmes sont résolues en interagissant avec des équipements physiques connectés (boutons, capteurs de porte, détecteurs de mouvement, télécommandes Z-Wave).

### Problématique

Comment gérer de manière robuste et traçable le déroulement d'un escape game piloté par des capteurs physiques, en garantissant la cohérence de l'état de jeu face à des événements concurrents, tout en offrant des interfaces adaptées aux différents acteurs (joueur, animateur, administrateur) ?

### Objectifs

- Recevoir en temps réel les événements issus de capteurs Z-Wave via Domoticz
- Faire progresser automatiquement les joueurs dans un scénario entièrement configurable en base de données
- Déclencher des feedbacks physiques (lampes, prises, écran LCD) à chaque étape
- Tracer l'intégralité des événements et actions pour analyse post-session
- Offrir une interface web adaptée à chaque acteur : joueur, game master et administrateur

### Périmètre matériel

| Équipement | Marque | Rôle |
|---|---|---|
| Raspberry Pi 4 | — | Serveur applicatif + hub Z-Wave |
| Z-Stick Gen5+ | Aeotec | Contrôleur Z-Wave USB |
| Button FGPB-101 | Fibaro | Capteur bouton poussoir |
| Door Sensor 2 FGDW-002 | Fibaro | Capteur d'ouverture de porte |
| Multisensor 6 | Aeotec | Capteur de mouvement |
| Keyfob FGKF-601 | Fibaro | Télécommande 6 boutons |
| LED Bulb Gen5 | Aeotec | Actionneur lampe |
| Wall Plug FGWPEF-102 | Fibaro | Actionneur prise connectée |
| Écran LCD PiFace | — | Affichage des messages de jeu |

---

## 3. Description générale du système

### Architecture globale

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
                 Joueur      Game Master        Admin
```

### Stack technique

| Couche | Technologie | Version |
|---|---|---|
| Langage backend | PHP | 8.4 |
| Serveur web | Apache2 + PHP-FPM | 2.4 / 8.4 |
| Base de données | MariaDB | 11.8 |
| Domotique | Domoticz + dzVents | — |
| Communication | Webhooks HTTP + Z-Wave | — |
| Frontend | Bootstrap + JS vanilla | 5.3 |

### Flux d'un événement en jeu

1. Un joueur appuie sur un bouton physique Z-Wave
2. Domoticz détecte le changement d'état du device
3. dzVents (Lua) exécute un script qui envoie un **webhook HTTP POST** vers `/api/handle_event.php`
4. `EventManager` normalise le payload brut (`idx` + `nvalue`) en événement métier (`BUTTON_PRESS`)
5. `GameEngine` compare l'événement à ce qui est attendu pour l'étape courante
6. Si **valide** : passage à l'étape suivante + actions de feedback (LCD, lampe...)
7. Si **invalide** : compteur d'erreurs incrémenté + message d'erreur LCD
8. Tout est enregistré dans `evenement_session` et `action_executee`

### Choix de conception majeurs

Le choix central de l'architecture est un **moteur de jeu entièrement stateless** : aucune progression n'est conservée en mémoire dans le code PHP. Tout l'état est lu et écrit en base de données à chaque requête. Ce choix renforce la robustesse du système, simplifie la reprise après incident et garantit la cohérence entre les différentes interfaces.

Les autres décisions structurantes sont les suivantes :

- **Configuration des scénarios en base de données** : les tables `etape_attend` et `etape_declenche` permettent de définir n'importe quel scénario sans modifier le code. Le moteur de jeu lit dynamiquement la configuration à chaque événement.
- **Journalisation exhaustive** : chaque signal capteur reçu et chaque action physique déclenchée sont tracés dans `evenement_session` et `action_executee`, ce qui permet l'analyse post-session et la détection d'anomalies.
- **Robustesse face aux événements concurrents** : le moteur de jeu traite chaque événement dans une **transaction SQL avec verrouillage** (`SELECT ... FOR UPDATE`), afin d'éviter les incohérences dues aux événements Z-Wave concurrents ou aux rebonds matériels (bouncing).
- **Couche de simulation** : afin de permettre le test et la démonstration indépendamment du matériel réel, une couche de simulation a été mise en place. Elle permet de reproduire le comportement des capteurs et de valider l'intégralité de la logique de jeu sans dépendre du réseau Z-Wave.
- **Séparation stricte des rôles** : la gestion des accès repose sur un modèle RBAC (Role-Based Access Control) géré en base, sans configuration statique dans le code.

### Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **joueur** | Interface de jeu, suivi de sa progression, historique personnel |
| **superviseur** | Lancement / reset de session, suivi temps réel, délivrance d'indices |
| **administrateur** | Gestion complète : utilisateurs, scénarios, configuration |

---

## 4. Description du site Web et intérêt de la base de données

DomEscape se compose de deux parties distinctes : un **site vitrine** statique présentant le concept et l'architecture du projet, et une **application métier** dynamique pilotant le jeu en temps réel.

### Site vitrine (`/website/`)

Le site vitrine est un ensemble de pages HTML statiques présentant :
- la vision et le concept du projet (escape game domotique)
- l'architecture technique et le moteur de jeu
- la base de données et son rôle
- la documentation et les scénarios disponibles

### Application métier (`/public/`, `/admin/`, `/api/`)

| Page | Rôle |
|---|---|
| `/public/connexion.php` | Authentification des utilisateurs |
| `/public/inscription.php` | Création de compte joueur |
| `/public/tableau-de-bord.php` | Hub central adapté au rôle de l'utilisateur |
| `/public/player.php` | Interface joueur : étape courante, progression, bouton abandon |
| `/public/gamemaster.php` | Interface animateur : démarrage, reset, suivi temps réel |
| `/public/mes-sessions.php` | Historique personnel des parties |
| `/admin/dashboard.php` | Tableau de bord administrateur |
| `/admin/utilisateurs.php` | Gestion des comptes et des rôles |
| `/dev/simulate.php` | Simulateur d'événements capteurs (sans hardware) |

### APIs exposées

| Endpoint | Méthode | Description |
|---|---|---|
| `/api/handle_event.php` | POST | Réception des webhooks Domoticz |
| `/api/start_game.php` | POST | Démarrage d'une session |
| `/api/session_status.php` | GET | Polling de l'état de la session (toutes les secondes) |
| `/api/reset_game.php` | POST | Réinitialisation (Game Master) |
| `/api/abandon_game.php` | POST | Abandon de partie |
| `/api/healthcheck.php` | GET | État du système (BDD, Domoticz, LCD) |

### Intérêt de la base de données

La base de données est le **seul point de vérité** du système. Elle sert à :

**1. Stocker l'état courant de la partie**
La table `session` contient l'étape courante, le score, le nombre d'erreurs et le statut. Le frontend poll `/api/session_status.php` toutes les secondes pour rafraîchir l'affichage sans rechargement de page.

**2. Configurer les scénarios sans toucher au code**
Les tables `scenario`, `etape`, `etape_attend` et `etape_declenche` permettent de créer et modifier n'importe quel scénario directement en base. Le moteur de jeu lit dynamiquement cette configuration à chaque appel.

**3. Tracer l'intégralité des événements et actions**
`evenement_session` enregistre chaque signal capteur reçu. `action_executee` trace chaque action physique déclenchée avec son statut d'exécution. Ces données permettent l'analyse post-session et la détection d'anomalies matérielles.

**4. Garantir la cohérence face aux événements concurrents**
Z-Wave peut générer plusieurs événements identiques en rafale (rebond hardware). `GameEngine::process()` s'exécute dans une transaction avec `SELECT FOR UPDATE` pour éviter les race conditions.

**5. Gérer les accès multi-rôles**
Les tables `utilisateur`, `role` et `utilisateur_role` implémentent un contrôle d'accès basé sur les rôles (RBAC). Cette configuration est entièrement gérée en base, sans aucune configuration statique dans le code.

---

## 5. Dictionnaire des Données (DD)

La base de données de DomEscape est composée de **12 tables métier** dédiées au moteur de jeu, auxquelles s'ajoutent **3 tables applicatives** pour l'authentification et la gestion des rôles, soit **15 tables au total**.

### Tables de référence (catalogues)

#### Table : capteur

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_capteur | INT | PK, AUTO_INCREMENT | Identifiant unique du capteur |
| nom_capteur | VARCHAR(100) | NOT NULL | Nom descriptif du capteur |
| type_capteur | VARCHAR(50) | NOT NULL | Famille : `door_sensor`, `button`, `motion_sensor`, `keyfob` |
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
| evenement_attendu | BOOLEAN | — | L'événement correspondait-il à l'attendu de l'étape courante ? |
| valide | BOOLEAN | — | Une session était-elle active au moment de la réception ? |

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
│ # id_type_evt     │    │ # id_type_action  │
│   code_evenement  │    │   code_action     │
│   libelle         │    │   libelle_action  │
│   type_capteur    │    │   description     │
└───────────────────┘    └───────────────────┘

┌───────────────────┐    ┌───────────────────┐
│    SCENARIO       │    │      ETAPE         │
│───────────────────│    │───────────────────│
│ # id_scenario     │    │ # id_etape        │
│   nom_scenario    │    │   numero_etape    │
│   description     │    │   titre_etape     │
│   theme           │    │   description     │
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
   / \                          /          \           /          \
(1,n)(1,n)               CAPTEUR   EVENEMENT    ACTIONNEUR  ACTION
    │    │                TYPE                    TYPE
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

### capteur

| id_capteur | nom_capteur | type_capteur | domoticz_idx | emplacement | actif |
|---|---|---|---|---|---|
| 1 | Fibaro Button | button | 5 | Bureau | 1 |
| 2 | Door Sensor | door_sensor | 8 | Porte principale | 1 |
| 3 | Multisensor | motion_sensor | 10 | Centre pièce | 1 |
| 4 | Keyfob Joueur | keyfob | 7 | Joueur | 1 |
| 5 | Bouton Coffre | button | 12 | Coffre-fort | 1 |

### actionneur

| id_actionneur | nom_actionneur | type_actionneur | domoticz_idx | emplacement | actif |
|---|---|---|---|---|---|
| 1 | Lampe principale | lamp | 1 | Plafond | 1 |
| 2 | Wall Plug | plug | 4 | Bureau | 1 |
| 3 | LCD PiFace | lcd | NULL | Bureau | 1 |
| 4 | Lampe Ambiance | lamp | 6 | Coin lecture | 1 |
| 5 | Prise Coffre | plug | 9 | Coffre-fort | 1 |

### evenement_type

| id_type_evenement | code_evenement | libelle_evenement | type_capteur |
|---|---|---|---|
| 1 | BUTTON_PRESS | Bouton appuyé | button |
| 2 | DOOR_OPEN | Porte ouverte | door_sensor |
| 3 | DOOR_CLOSE | Porte fermée | door_sensor |
| 4 | MOTION_DETECTED | Mouvement détecté | motion_sensor |
| 5 | NO_MOTION | Aucun mouvement | motion_sensor |
| 6 | KEYFOB_BUTTON_1 | Keyfob — Touche 1 | keyfob |
| 7 | KEYFOB_BUTTON_2 | Keyfob — Touche 2 | keyfob |
| 8 | KEYFOB_BUTTON_3 | Keyfob — Touche 3 | keyfob |

### action_type

| id_type_action | code_action | libelle_action | description |
|---|---|---|---|
| 1 | LAMP_ON | Allumer lampe | Active une lampe via Domoticz |
| 2 | LAMP_OFF | Éteindre lampe | Désactive une lampe via Domoticz |
| 3 | PLUG_ON | Activer prise | Active un Wall Plug via Domoticz |
| 4 | PLUG_OFF | Désactiver prise | Désactive un Wall Plug via Domoticz |
| 5 | LCD_MESSAGE | Message LCD | Affiche un message sur l'écran LCD |
| 6 | LOG_ONLY | Log uniquement | Enregistre sans effet physique |

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
| 2 | 2 | 2 | 1 |
| 3 | 3 | 4 | 1 |
| 4 | 4 | 8 | 1 |
| 5 | 1 | 1 | 1 |

### etape_declenche

| id_etape | id_actionneur | id_type_action | ordre_action | valeur_action | moment_declenchement |
|---|---|---|---|---|---|
| 1 | 3 | 5 | 1 | Appuyez sur le bouton | on_enter |
| 1 | 3 | 5 | 1 | Système en ligne. | on_success |
| 1 | 1 | 1 | 2 | NULL | on_success |
| 1 | 3 | 5 | 1 | Action incorrecte. | on_failure |
| 2 | 3 | 5 | 1 | Ouvrez la porte sécurisée | on_enter |

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
| 2 | 1 | 3 | 4 | 2 | 0 | 1 |
| 3 | 1 | 2 | 2 | 2 | 1 | 1 |
| 4 | 1 | 3 | 4 | 3 | 1 | 1 |
| 5 | 1 | 4 | 8 | 4 | 1 | 1 |
| 6 | 2 | 1 | 1 | 1 | 1 | 1 |
| 7 | 2 | 1 | 1 | 2 | 0 | 1 |
| 8 | 3 | 1 | 1 | 1 | 1 | 1 |

### action_executee

| id_action_executee | id_session | id_actionneur | id_type_action | id_etape | valeur_action | statut_execution |
|---|---|---|---|---|---|---|
| 1 | 1 | 3 | 5 | 1 | Appuyez sur le bouton | ok |
| 2 | 1 | 3 | 5 | 1 | Système en ligne. | ok |
| 3 | 1 | 1 | 1 | 1 | NULL | ok |
| 4 | 1 | 3 | 5 | 2 | Accès autorisé. | ok |
| 5 | 1 | 3 | 5 | 4 | ESCAPE SUCCESSFUL ! | ok |
| 6 | 2 | 3 | 5 | 1 | Action incorrecte. | ok |

### utilisateur

| id | nom | email | actif | cree_le |
|---|---|---|---|---|
| 1 | Administrateur | admin@domescape.local | 1 | 2026-03-21 09:00:00 |
| 2 | Marie Dupont | marie@domescape.local | 1 | 2026-03-21 10:00:00 |
| 3 | Thomas Martin | thomas@domescape.local | 1 | 2026-03-21 10:15:00 |
| 4 | Game Master 1 | gm1@domescape.local | 1 | 2026-03-21 12:00:00 |
| 5 | Game Master 2 | gm2@domescape.local | 1 | 2026-03-21 12:05:00 |

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

DomEscape est une plateforme applicative complète illustrant l'intégration d'un système informatique avec un environnement physique instrumenté. La base de données, conçue autour d'un modèle entité-association rigoureux, joue un rôle central : elle est à la fois le moteur de configuration des scénarios, le registre d'état de jeu en temps réel et l'archive exhaustive de l'activité.

Plusieurs choix de conception méritent d'être soulignés :

- L'utilisation d'**associations ternaires** (`etape_attend`, `etape_declenche`) pour modéliser la configuration des puzzles de façon entièrement déclarative, sans duplication de données ni modification du code.
- Le recours aux **transactions avec verrouillage** (`SELECT FOR UPDATE`) dans le moteur de jeu pour garantir la cohérence face aux événements Z-Wave concurrents et aux rebonds hardware.
- Un modèle **stateless** où le code PHP ne maintient aucun état applicatif en mémoire — tout est lu et écrit en base à chaque requête, simplifiant la reprise après incident.
- La séparation claire entre données de **configuration** (scénarios, étapes) et données de **runtime** (sessions, événements, actions), qui permet de modifier les scénarios sans interruption de service.
- Une **couche de simulation** permettant de valider l'intégralité de la logique de jeu sans dépendre du matériel Z-Wave, facilitant le développement et la démonstration.

Le projet est actuellement déployé et fonctionnel sur une VM Ubuntu et sur un environnement macOS de développement. La prochaine étape est l'intégration sur Raspberry Pi avec le hardware Z-Wave réel, pour une validation en conditions opérationnelles.
