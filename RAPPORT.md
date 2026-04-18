# Rapport de projet — DomEscape
### Conception et modélisation d'une plateforme d'escape game domotique

---

**Auteur :** Amine AIT-ALI
**Date :** Avril 2026
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
| Langage backend | PHP | 7.4+ (développé sous 8.4, déployé sur 7.4 Raspberry Pi) |
| Serveur web | Apache2 + mod_php | 2.4 |
| Base de données | MariaDB | 10.5+ |
| Domotique | Domoticz V2024.4 + dzVents + Scenes | 3.1.8 |
| Service LCD | Python 3 stdlib (http.server) | — |
| Frontend applicatif | Bootstrap + JavaScript vanilla | 5.3 |
| Site vitrine | HTML / CSS / JavaScript statiques | — |

### 3.3. Principe de fonctionnement

Lorsqu'un joueur effectue une action physique, par exemple en appuyant sur un bouton Z-Wave, cette action suit le flux suivant :

1. le capteur envoie un signal Z-Wave ;
2. Domoticz centralise le changement d'état du device ;
3. un script dzVents déclenche un webhook HTTP POST vers `/api/handle_event.php` (flux temps réel) ;
4. `EventManager` convertit le payload brut (`idx` + `nvalue`) en événement métier normalisé (`BUTTON_PRESS`, `DOOR_OPEN`…) ;
5. `GameEngine` charge la configuration des étapes via `session.id_scenario_version`, puis compare cet événement à ce qui est attendu pour l'étape courante, dans une transaction SQL verrouillée ;
6. si l'événement est correct, la session progresse vers l'étape suivante ;
7. `ActionManager` exécute les retours physiques définis pour l'étape (LCD, lampe, prise) ;
8. les événements et actions sont archivés dans `evenement_session` et `action_executee`.

Pour les interactions ne nécessitant pas de réactivité immédiate (déclenchement de scènes, supervision, lecture de capteurs), DomEscape exploite également l'**API REST Domoticz** via les Scenes configurées dans l'interface Domoticz. Cette double intégration permet de combiner réactivité événementielle (dzVents) et interrogation à la demande (API REST).

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

**g) Versionnage des scénarios**
Lors du démarrage d'une session, `start_game.php` résout la version active du scénario et initialise `session.id_scenario_version`. Le moteur charge ensuite les étapes exclusivement via ce champ, garantissant l'isolation complète des versions : une session n'est jamais impactée par une modification ultérieure du scénario.

### 3.5. Authentification et gestion des rôles

En complément du moteur de jeu, DomEscape intègre une couche applicative d'authentification et de gestion des rôles. Celle-ci permet de distinguer les accès joueur, supervision et administration à travers un modèle RBAC (Role-Based Access Control) stocké en base, sans configuration statique dans le code.

> **Vocabulaire :** dans ce rapport, le mot *joueur* est utilisé dans son sens métier naturel (la personne physique qui joue). Le terme *participant* désigne le **rôle technique** RBAC attribué en base (`role.nom = 'participant'`). Un compte utilisateur avec le rôle `participant` peut démarrer ou rejoindre une session ; les rôles `superviseur` et `administrateur` disposent de droits applicatifs plus étendus.

### 3.6. Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **participant** | Interface de jeu, suivi de sa progression, historique personnel |
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
| `/public/inscription.php` | Création de compte (rôle participant par défaut) |
| `/public/tableau-de-bord.php` | Hub central adapté au rôle de l'utilisateur |
| `/public/player.php` | Interface joueur : étape courante, progression, indice, abandon |
| `/public/gamemaster.php` | Interface superviseur : suivi temps réel, indices, reset |
| `/public/demandes.php` | Gestion des demandes de rejoindre une session (superviseur) |
| `/public/mes-sessions.php` | Historique personnel des parties |
| `/admin/dashboard.php` | Tableau de bord administrateur |
| `/admin/scenarios.php` | Gestion des scénarios (création, activation, suppression) |
| `/admin/scenario_edit.php` | Édition d'un scénario et de ses étapes (CRUD complet) |
| `/admin/utilisateurs.php` | Gestion des comptes et des rôles |
| `/admin/versions.php` | Gestion des versions de scénario (draft / active / archived) |
| `/public/historique.php` | Chronologie détaillée des événements et actions d'une session (superviseur) |
| `/public/stats.php` | Indicateurs globaux : taux de victoire, meilleur temps, difficulté par étape |
| `/dev/simulate.php` | Simulateur d'événements capteurs (sans hardware) |

### 4.3. APIs exposées

| Endpoint | Méthode | Description |
|---|---|---|
| `/api/handle_event.php` | POST | Réception des webhooks Domoticz |
| `/api/start_game.php` | POST | Démarrage d'une session |
| `/api/session_status.php` | GET | Polling de l'état de la session (toutes les 2 secondes) |
| `/api/send_hint.php` | POST | Envoi de l'indice de l'étape courante (superviseur) |
| `/api/reset_game.php` | POST | Réinitialisation (superviseur) |
| `/api/abandon_game.php` | POST | Abandon de partie |
| `/api/join_session.php` | POST | Rejoindre directement une session en_attente |
| `/api/request_join_session.php` | POST | Envoyer une demande pour rejoindre une session |
| `/api/handle_join_request.php` | POST | Accepter ou refuser une demande (superviseur) |
| `/api/debug_event.php` | POST / GET | Simulation d'un événement capteur sans matériel réel |
| `/api/gamemaster_status.php` | GET | Données temps réel pour le gamemaster : session, événements, actions |
| `/api/healthcheck.php` | GET | État du système (BDD, Domoticz, LCD) |

L'interface `/dev/simulate.php` s'appuie sur l'endpoint `/api/debug_event.php` pour injecter des événements capteurs simulés directement dans le moteur de jeu, sans dépendre du réseau Z-Wave.

### 4.4. Intérêt de la base de données

La base de données constitue le **point de vérité unique** du système. Elle sert à :

**1. Stocker l'état courant de la partie**
La table `session` contient l'étape courante, le score, le nombre d'erreurs et le statut. Le frontend interroge périodiquement `/api/session_status.php` (polling toutes les 2 secondes) afin de rafraîchir l'affichage sans rechargement de page.

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

La base de données de DomEscape est composée de **20 tables** réparties en sept groupes fonctionnels, conformément à l'ordre de création du schéma SQL :

| Groupe | Tables | Nb |
|---|---|---|
| Catalogues | `evenement_type`, `action_type` | 2 |
| Référentiels physiques | `capteur`, `actionneur` | 2 |
| Configuration de scénario | `scenario`, `scenario_version`, `etape` | 3 |
| Associations ternaires | `etape_attend`, `etape_declenche` | 2 |
| Auth / RBAC | `utilisateur`, `role`, `utilisateur_role` | 3 |
| Runtime | `equipe`, `equipe_utilisateur`, `session`, `session_utilisateur`, `demande_rejoindre_session`, `evenement_session`, `action_executee` | 7 |
| Télémétrie | `mesure_capteur` | 1 |

L'architecture est strictement mono-salle : aucune table multi-sites ou multi-salles n'est présente dans ce schéma. Le déploiement multi-salles constitue une évolution future hors scope ; le modèle actuel ne contient aucune abstraction de salle.

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
| type_actionneur | VARCHAR(50) | NOT NULL | Famille : `plug`, `lcd` |
| domoticz_idx | INT | UNIQUE, NULL | Identifiant Domoticz (NULL pour le LCD, géré hors Domoticz) |
| emplacement | VARCHAR(100) | — | Localisation physique |
| actif | BOOLEAN | DEFAULT TRUE | Indique si l'actionneur est opérationnel |

#### Table : mesure_capteur

Stocke les relevés télémétriques périodiques des capteurs environnementaux (température, humidité). Bien que non exploitée dans la version actuelle du moteur, cette table est déjà alimentable via l'API Domoticz et constitue une extension directe vers des scénarios adaptatifs basés sur des conditions environnementales.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_mesure | INT | PK, AUTO_INCREMENT | Identifiant du relevé |
| id_capteur | INT | FK → capteur | Capteur source de la mesure |
| date_mesure | DATETIME | NOT NULL | Horodatage du relevé |
| temperature | DECIMAL(5,2) | NULL | Température en °C |
| humidite | DECIMAL(5,2) | NULL | Humidité relative en % |

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
| code_action | VARCHAR(50) | NOT NULL, UNIQUE | Code métier : `PLUG_ON`, `LCD_MESSAGE`… |
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
| nb_joueurs_min | INT | NULL | Nombre minimum de joueurs pour démarrer la session |
| nb_joueurs_max | INT | NULL | Nombre maximum de joueurs autorisés |
| duree_max_secondes | INT | NULL | Durée limite en secondes (NULL = illimitée) |
| cree_le | TIMESTAMP | DEFAULT NOW() | Date de création |

#### Table : etape

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_etape | INT | PK, AUTO_INCREMENT | Identifiant de l'étape |
| id_scenario | INT | FK → scenario | Scénario auquel appartient l'étape |
| id_scenario_version | INT | FK → scenario_version, NULL | Version du scénario à laquelle appartient l'étape |
| numero_etape | INT | NOT NULL | Ordre de l'étape dans le scénario |
| titre_etape | VARCHAR(150) | NOT NULL | Titre affiché |
| description_etape | TEXT | — | Consigne affichée au joueur |
| message_succes | TEXT | — | Message affiché en cas de réussite |
| message_echec | TEXT | — | Message affiché en cas d'erreur |
| indice | TEXT | — | Indice disponible à la demande |
| points | INT | DEFAULT 100 | Points accordés en cas de réussite |
| finale | BOOLEAN | DEFAULT FALSE | TRUE pour la dernière étape (victoire) |

> **Versionnage des étapes :** `etape.id_scenario_version` rattache chaque étape à une version précise du scénario. Le moteur (`GameEngine`) charge les étapes via `id_scenario_version` quand celui-ci est renseigné, garantissant qu'une session exécute toujours la version figée au moment de son démarrage. Un fallback sur `id_scenario` assure la compatibilité descendante avec les sessions antérieures à l'intégration du versionnage.

> **Compatibilité descendante — double rattachement :** la présence simultanée de `id_scenario` et `id_scenario_version` correspond à une phase de transition maîtrisée. Les étapes historiques restent rattachées directement au scénario, tandis que les nouvelles étapes versionnées sont rattachées à `scenario_version`. La cohérence est garantie au niveau applicatif par le moteur, qui privilégie toujours `id_scenario_version` lorsque ce champ est renseigné.

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
| id_etape_declenche | INT | PK, AUTO_INCREMENT | Identifiant technique de la ligne |
| id_etape | INT | FK → etape | Étape concernée |
| id_actionneur | INT | FK → actionneur | Actionneur ciblé |
| id_type_action | INT | FK → action_type | Type d'action à exécuter |
| ordre_action | INT | DEFAULT 1 | Ordre d'exécution |
| valeur_action | TEXT | — | Paramètre de l'action (ex : texte LCD) |
| moment_declenchement | VARCHAR(20) | NOT NULL | `on_enter`, `on_success`, `on_failure`, `on_hint` |

### Tables de session (runtime)

#### Table : equipe

Représente une équipe créée pour jouer une session. Elle est identifiée par son nom et rattachée à l'utilisateur qui l'a créée.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_equipe | INT | PK, AUTO_INCREMENT | Identifiant de l'équipe |
| nom_equipe | VARCHAR(100) | NOT NULL | Nom de l'équipe |
| type_equipe | VARCHAR(50) | DEFAULT 'equipe' | `equipe`, `individuel`, `jury` — distingue une équipe classique, une session individuelle et une session de démonstration/jury, sans modifier le modèle relationnel |
| id_utilisateur | INT UNSIGNED | FK → utilisateur, NULL | Utilisateur à l'origine de la création (référence optionnelle) |
| cree_le | TIMESTAMP | DEFAULT NOW() | Date de création |

#### Table : equipe_utilisateur *(association)*

Lie les utilisateurs à leur équipe. Sans hiérarchie métier : tous les membres sont équivalents.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_equipe | INT | PK, FK → equipe | Équipe concernée |
| id_utilisateur | INT UNSIGNED | PK, FK → utilisateur | Utilisateur membre |
| rejoint_le | DATETIME | DEFAULT NOW() | Date d'adhésion |

#### Table : session

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_session | INT | PK, AUTO_INCREMENT | Identifiant de la session |
| id_scenario | INT | FK → scenario | Scénario joué |
| id_scenario_version | INT | FK → scenario_version, NULL | Version du scénario figée au démarrage de la session |
| id_equipe | INT | FK → equipe | Équipe participante |
| id_utilisateur_createur | INT UNSIGNED | FK → utilisateur, NULL | Utilisateur ayant lancé la session |
| id_etape_courante | INT | FK → etape, NULL | Étape en cours (NULL en fin de partie) |
| statut_session | VARCHAR(20) | NOT NULL | `en_attente`, `en_cours`, `gagnee`, `perdue`, `abandonnee` |
| date_debut | DATETIME | — | Horodatage du démarrage (NULL si en_attente) |
| date_fin | DATETIME | — | Horodatage de la fin |
| score | INT | DEFAULT 0 | Score cumulé |
| nb_erreurs | INT | DEFAULT 0 | Nombre d'erreurs commises |
| nb_indices | INT | DEFAULT 0 | Nombre d'indices demandés |
| duree_secondes | INT | — | Durée totale en secondes (calculée à la fin) |

> **Redondance assumée — `id_scenario` vs `id_scenario_version` :** `session.id_scenario` est techniquement dérivable via `scenario_version.id_scenario`. Cette redondance est conservée délibérément pour deux raisons : (1) les sessions legacy antérieures au versionnage ont `id_scenario_version = NULL` et nécessitent un accès direct au scénario ; (2) cela évite une jointure supplémentaire dans le chemin critique du moteur (`GameEngine::process()`), où chaque milliseconde compte sur Raspberry Pi.

> **Statut `en_attente` :** Quand `nb_joueurs_min > 1`, la session est créée en `en_attente` et `date_debut` reste NULL. Le moteur (`tryLaunchSession`) surveille le nombre de participants dans `session_utilisateur` et déclenche automatiquement la transition vers `en_cours` dès que le minimum est atteint.

#### Table : session_utilisateur *(association)*

Lie les utilisateurs participants à une session. Le créateur est tracé dans `session.id_utilisateur_createur`.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_session | INT | PK, FK → session | Session concernée |
| id_utilisateur | INT UNSIGNED | PK, FK → utilisateur | Participant |
| rejoint_le | DATETIME | DEFAULT NOW() | Date de participation |

#### Table : demande_rejoindre_session

Enregistre les demandes d'un utilisateur pour rejoindre une session existante. Contrainte UNIQUE sur `(id_session, id_utilisateur, statut_demande)` pour bloquer les doublons de demandes en attente.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_demande | INT | PK, AUTO_INCREMENT | Identifiant de la demande |
| id_session | INT | FK → session | Session visée |
| id_utilisateur | INT UNSIGNED | FK → utilisateur | Demandeur |
| message_demande | TEXT | — | Message optionnel du demandeur |
| statut_demande | VARCHAR(20) | NOT NULL, DEFAULT 'en_attente' | `en_attente`, `acceptee`, `refusee`, `annulee` |
| demande_le | DATETIME | DEFAULT NOW() | Date de la demande |
| traitee_le | DATETIME | — | Date de traitement |
| traitee_par | INT UNSIGNED | FK → utilisateur, NULL | Superviseur ou administrateur ayant traité la demande |

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
| evenement_attendu | BOOLEAN | — | TRUE si l'événement correspondait à l'attendu de l'étape |
| valide | BOOLEAN | — | TRUE si l'événement a été retenu comme exploitable par le moteur |

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
| nom | VARCHAR(50) | NOT NULL, UNIQUE | `participant`, `superviseur`, `administrateur` |

#### Table : utilisateur_role

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_utilisateur | INT UNSIGNED | PK, FK → utilisateur | Utilisateur concerné |
| id_role | INT UNSIGNED | PK, FK → role | Rôle attribué |

### Table de versionnage

#### Table : scenario_version

Permet de gérer plusieurs versions d'un même scénario sans impacter les sessions en cours ou l'historique existant. Élément central du moteur : au démarrage, `start_game.php` résout la version active et initialise `session.id_scenario_version`.

| Attribut | Type | Contrainte | Description |
|---|---|---|---|
| id_scenario_version | INT | PK, AUTO_INCREMENT | Identifiant de la version |
| id_scenario | INT | FK → scenario | Scénario parent |
| numero_version | VARCHAR(20) | NOT NULL | Label de version (ex : `v1.0`, `v2.1-beta`) |
| statut_version | VARCHAR(20) | DEFAULT 'draft' | `draft`, `active`, `archived` |
| commentaire | TEXT | — | Notes de version |
| cree_le | DATETIME | DEFAULT NOW() | Date de création |

> **Note mono-salle :** DomEscape fonctionne sur un Raspberry Pi unique sans notion de site ou de salle. Les tables multi-sites (`site`, `salle`, `salle_scenario`) ne font pas partie du schéma actuel ; elles constituent une piste d'évolution future hors scope de ce projet.

---

## 6. Modélisation Entité-Association (MCD)

> **Note :** Le schéma MCD présenté ci-dessous est une représentation textuelle des entités et associations. Un diagramme Merise complet (draw.io / diagrams.net) est disponible en complément.

> **Note de lecture :** le MCD ci-dessous présente le cœur métier du système. Pour préserver la lisibilité, les entités d'historisation et de gestion opérationnelle (`evenement_session`, `action_executee`, `session_utilisateur`, `demande_rejoindre_session`) ne sont pas détaillées graphiquement ici, mais elles figurent bien dans le schéma relationnel (MLD) et dans le dictionnaire des données.

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

┌─────────────────────┐    ┌─────────────────────┐    ┌───────────────────┐
│      SCENARIO       │    │  SCENARIO_VERSION    │    │      ETAPE         │
│─────────────────────│    │─────────────────────│    │───────────────────│
│ # id_scenario       │    │ # id_scenario_version│    │ # id_etape        │
│   nom_scenario      │    │   numero_version    │    │   numero_etape    │
│   theme             │    │   statut_version    │    │   titre_etape     │
│   actif             │    │   commentaire       │    │   description_etape │
│   nb_joueurs_min    │    └─────────────────────┘    │   points          │
│   nb_joueurs_max    │                               │   finale          │
│   duree_max_secondes│                               └───────────────────┘
└─────────────────────┘

┌───────────────────┐    ┌───────────────────┐
│     EQUIPE        │    │     SESSION        │
│───────────────────│    │───────────────────│
│ # id_equipe       │    │ # id_session      │
│   nom_equipe      │    │   statut_session  │
│   type_equipe     │    │   date_debut      │
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
SCENARIO ──(1,n)── SCENARIO_VERSION ──(1,n)── ETAPE
    │                    │                        │
   (1,n)               (1,n)          ┌──────────┴────────────┐
    │                    │            │                        │
  SESSION ──(0,1)────────┘          ATTEND               DECLENCHE
    │    └──(n,n)── UTILISATEUR    (ternaire)           (ternaire)
  /   \        [via session_utilisateur]  /       \    /          \
(1,n)(1,n)                        CAPTEUR  EVENT_TYPE  ACTIONNEUR  ACTION_TYPE
  │     │
EQUIPE  ETAPE
  │
(n,n)── UTILISATEUR ──(n,n)── ROLE
[via equipe_utilisateur]   [via utilisateur_role]
```

### Description des associations

| Association | Entités | Cardinalités | Description |
|---|---|---|---|
| contient | SCENARIO — SCENARIO_VERSION | 1,n — 1,1 | Un scénario peut avoir plusieurs versions (draft / active / archived) |
| version_de | SCENARIO_VERSION — ETAPE | 1,n — 1,1 | Une version regroupe les étapes qui lui appartiennent |
| fige | SESSION — SCENARIO_VERSION | n,0..1 | Une session peut être figée sur au plus une version (0..1 côté SESSION, 1..n côté VERSION) |
| joue | SESSION — SCENARIO | n,1 | Une session correspond à l'exécution d'un scénario précis |
| appartient_à | SESSION — EQUIPE | n,1 | Une session est associée à une équipe |
| est_à | SESSION — ETAPE | n,0..1 | Une session pointe vers l'étape active (NULL en fin de partie) |
| attend | ETAPE — CAPTEUR — EVENEMENT_TYPE | ternaire | Définit l'événement à recevoir sur quel capteur pour valider l'étape |
| déclenche | ETAPE — ACTIONNEUR — ACTION_TYPE | ternaire | Définit les actions à exécuter selon le moment (on_enter, on_success…) |
| génère | SESSION — EVENEMENT_SESSION | 1,n | Chaque signal capté durant une session est tracé |
| produit | SESSION — ACTION_EXECUTEE | 1,n | Chaque action physique déclenchée est tracée |
| composée_de | EQUIPE — UTILISATEUR | n,n | Une équipe regroupe plusieurs utilisateurs (via equipe_utilisateur) |
| participe_à | SESSION — UTILISATEUR | n,n | Les participants d'une session sont tracés dans session_utilisateur |
| possède | UTILISATEUR — ROLE | n,n | Un utilisateur peut avoir plusieurs rôles |
| demande | UTILISATEUR — SESSION | n,n | Un utilisateur peut envoyer une demande pour rejoindre une session (via demande_rejoindre_session) |

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

mesure_capteur (
    <u>id_mesure</u>,
    _id_capteur_ → capteur(id_capteur),
    date_mesure,
    temperature,
    humidite
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
    nb_joueurs_min,
    nb_joueurs_max,
    duree_max_secondes,
    cree_le
)

scenario_version (
    <u>id_scenario_version</u>,
    _id_scenario_ → scenario(id_scenario),
    numero_version,
    statut_version,
    commentaire,
    cree_le
)

etape (
    <u>id_etape</u>,
    _id_scenario_ → scenario(id_scenario),
    _id_scenario_version_ → scenario_version(id_scenario_version),
    numero_etape,
    titre_etape,
    description_etape,
    message_succes,
    message_echec,
    indice,
    points,
    finale
)

equipe (
    <u>id_equipe</u>,
    nom_equipe,
    type_equipe,
    cree_le,
    _id_utilisateur_ → utilisateur(id)
)

equipe_utilisateur (
    <u>id_equipe</u> → equipe(id_equipe),
    <u>id_utilisateur</u> → utilisateur(id),
    rejoint_le
)

session (
    <u>id_session</u>,
    _id_scenario_ → scenario(id_scenario),
    _id_scenario_version_ → scenario_version(id_scenario_version),
    _id_equipe_ → equipe(id_equipe),
    _id_utilisateur_createur_ → utilisateur(id),
    _id_etape_courante_ → etape(id_etape),
    statut_session,
    date_debut,
    date_fin,
    score,
    nb_erreurs,
    nb_indices,
    duree_secondes
)

session_utilisateur (
    <u>id_session</u> → session(id_session),
    <u>id_utilisateur</u> → utilisateur(id),
    rejoint_le
)

demande_rejoindre_session (
    <u>id_demande</u>,
    _id_session_ → session(id_session),
    _id_utilisateur_ → utilisateur(id),
    message_demande,
    statut_demande,
    demande_le,
    traitee_le,
    _traitee_par_ → utilisateur(id),
    UNIQUE (id_session, id_utilisateur, statut_demande)
)

etape_attend (
    <u>id_etape</u> → etape(id_etape),
    <u>id_capteur</u> → capteur(id_capteur),
    <u>id_type_evenement</u> → evenement_type(id_type_evenement),
    obligatoire
)

etape_declenche (
    <u>id_etape_declenche</u>,
    _id_etape_ → etape(id_etape),
    _id_actionneur_ → actionneur(id_actionneur),
    _id_type_action_ → action_type(id_type_action),
    ordre_action,
    moment_declenchement,
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
| 1 | Button | button | 9 | Bureau | 1 |
| 2 | Porte | door_sensor | 25 | Porte principale | 1 |
| 3 | Multisensor | motion_sensor | 7 | Centre pièce | 1 |
| 4 | Button Double | button | 30 | Bureau | 1 |
| 5 | Air Temperature/Humidity | temp_humidity | 8 | Centre pièce | 1 |

> **Note :** Le Fibaro Button FGPB-101 crée un device Domoticz distinct (`idx 30`) pour le double appui et partage le même type `button` que le capteur `idx 9`. Cette particularité du protocole Z-Wave impose deux entrées capteur distinctes dans le modèle. Le capteur 5 est un capteur de télémétrie environnementale (température / humidité) dont les relevés sont collectés périodiquement via un script cron et stockés dans `mesure_capteur`.

### actionneur

| id_actionneur | nom_actionneur | type_actionneur | domoticz_idx | emplacement | actif |
|---|---|---|---|---|---|
| 1 | Wall Plug | plug | 13 | Bureau | 1 |
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

| id_scenario | nom_scenario | theme | nb_joueurs_min | nb_joueurs_max | duree_max_secondes | actif |
|---|---|---|---|---|---|---|
| 1 | DomEscape Lab 01 | Laboratoire sécurisé | 1 | 6 | 3600 | 1 |
| 2 | Mission Infiltration | Espionnage | 2 | 4 | 2700 | 1 |
| 3 | L'Héritage du Savant | Mystère | NULL | NULL | NULL | 0 |

> **Note :** Le scénario `DomEscape Lab 01` est le seul déployé et testé sur hardware réel. Les scénarios 2 et 3 sont des exemples illustratifs du modèle multi-scénarios.

### etape

| id_etape | id_scenario | id_scenario_version | numero_etape | titre_etape | points | finale |
|---|---|---|---|---|---|---|
| 1 | 1 | 1 | 1 | Boot Sequence | 100 | 0 |
| 2 | 1 | 1 | 2 | Secret Door | 150 | 0 |
| 3 | 1 | 1 | 3 | Motion Scan | 200 | 0 |
| 4 | 1 | 1 | 4 | Final Code | 300 | 1 |
| 5 | 2 | NULL | 1 | Neutraliser l'alarme | 100 | 0 |

### etape_attend

| id_etape | id_capteur | id_type_evenement | obligatoire |
|---|---|---|---|
| 1 | 1 | 1 | 1 |
| 2 | 2 | 5 | 1 |
| 3 | 3 | 7 | 1 |
| 4 | 4 | 1 | 1 |
| 5 | 1 | 1 | 1 |

> **Note :** L'étape 4 attend l'événement `BUTTON_PRESS` (id=1) sur le capteur `Button Double` (id=4, idx=30). Le device Domoticz idx=30 correspond physiquement au double appui sur le Fibaro Button, mais Domoticz l'expose comme un Switch classique émettant `nvalue=1` (BUTTON_PRESS) à chaque déclenchement. C'est la combinaison capteur + type d'événement qui encode la sémantique "double appui", non la valeur `nvalue`.

### etape_declenche

| id_etape_declenche | id_etape | id_actionneur | id_type_action | ordre_action | valeur_action | moment_declenchement |
|---|---|---|---|---|---|---|
| 1 | 1 | 2 | 3 | 1 | En veille... | on_enter |
| 2 | 1 | 2 | 3 | 1 | Niveau 1 OK ! | on_success |
| 3 | 1 | 1 | 1 | 2 | NULL | on_success |
| 4 | 1 | 2 | 3 | 1 | Invalide ! | on_failure |
| 5 | 2 | 2 | 3 | 1 | Zone restreinte | on_enter |

### equipe

| id_equipe | nom_equipe | type_equipe | id_utilisateur |
|---|---|---|---|
| 1 | Équipe Alpha | equipe | 2 |
| 2 | Équipe Beta | equipe | 3 |
| 3 | Jury IUT | jury | NULL |

### equipe_utilisateur

| id_equipe | id_utilisateur | rejoint_le |
|---|---|---|
| 1 | 2 | 2026-03-21 10:00:00 |
| 1 | 3 | 2026-03-21 10:05:00 |
| 2 | 4 | 2026-03-21 12:00:00 |

### session

| id_session | id_scenario | id_scenario_version | id_equipe | id_utilisateur_createur | statut_session | score | nb_erreurs | duree_secondes |
|---|---|---|---|---|---|---|---|---|
| 1 | 1 | 1 | 1 | 2 | gagnee | 750 | 2 | 1842 |
| 2 | 1 | 1 | 2 | 3 | perdue | 250 | 8 | 3600 |
| 3 | 1 | 1 | 1 | 2 | gagnee | 750 | 0 | 194 |
| 4 | 2 | NULL | 2 | 4 | abandonnee | 100 | 1 | NULL |
| 5 | 1 | 1 | 1 | 2 | gagnee | 750 | 5 | 54 |

> **Note :** `id_scenario_version` est renseigné dès le démarrage dès lors que le scénario dispose d'une version active. Les sessions antérieures à l'intégration du versionnage, ou pour des scénarios sans version définie, conservent `NULL` dans ce champ.

### session_utilisateur

| id_session | id_utilisateur | rejoint_le |
|---|---|---|
| 1 | 2 | 2026-03-21 10:00:00 |
| 1 | 3 | 2026-03-21 10:02:00 |
| 2 | 3 | 2026-03-21 14:00:00 |
| 5 | 2 | 2026-04-06 09:00:00 |

### demande_rejoindre_session

| id_demande | id_session | id_utilisateur | statut_demande | traitee_par | demande_le |
|---|---|---|---|---|---|
| 1 | 1 | 4 | acceptee | 1 | 2026-03-21 10:01:00 |
| 2 | 2 | 5 | refusee | 1 | 2026-03-21 14:05:00 |
| 3 | 5 | 3 | en_attente | NULL | 2026-04-06 09:01:00 |

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
| 1 | 1 | 2 | 3 | 1 | En veille... | ok |
| 2 | 1 | 2 | 3 | 1 | Niveau 1 OK ! | ok |
| 3 | 1 | 1 | 1 | 1 | NULL | ok |
| 4 | 1 | 2 | 3 | 2 | Acces autorise! | ok |
| 5 | 1 | 2 | 3 | 4 | ESCAPE SUCCESS! | ok |
| 6 | 2 | 2 | 3 | 1 | Invalide ! | ok |

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
| 1 | participant |
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

La modélisation retenue permet d'obtenir un système à la fois robuste, extensible et analysable. Elle ouvre la voie à des évolutions concrètes : étapes chronométrées, embranchements de scénario, validations multi-conditions, exécution différée des actions physiques, et déploiement multi-salles.

Le modèle basé sur `scenario_version` est désormais intégré au système et permet de figer une version de scénario au démarrage de chaque session, garantissant la reproductibilité et la traçabilité des parcours. Ce couplage assure une **invariance temporelle** du système : une session n'est jamais impactée par une modification ultérieure du scénario. Il permet en outre d'exécuter simultanément plusieurs variantes d'un même scénario, et d'effectuer des mises à jour de contenu sans interrompre les sessions en cours.

La chaîne complète a été validée sur hardware réel : capteurs Z-Wave → Domoticz → dzVents → handle_event.php → GameEngine → base de données. Plusieurs sessions ont été jouées et remportées sur le Raspberry Pi de production, confirmant la robustesse du système en conditions réelles.

La Phase 6 du projet a consolidé la gestion multi-joueurs : les contraintes `nb_joueurs_min`, `nb_joueurs_max` et `duree_max_secondes` sont désormais enforced par le moteur (lobby `en_attente`, blocage au-delà du maximum, défaite automatique à expiration). Le flow de demande (`demande_rejoindre_session`) permet aux superviseurs de contrôler les accès via une interface dédiée (`demandes.php`). Le modèle initial centré sur `joueur` a été remplacé par `equipe`, `equipe_utilisateur` et `session_utilisateur`, afin de mieux distinguer l'appartenance à une équipe et la participation effective à une session.

Si DomEscape est aujourd'hui démontré à travers un escape game domotique sur Raspberry Pi mono-salle, l'architecture conçue dépasse ce seul cadre. Le moteur de scénarios événementiels, la double intégration Domoticz (dzVents temps réel + API REST), et le modèle de données extensible constituent les fondations d'une plateforme générique de scénarios physiques interactifs. La modélisation retenue — moteur stateless, versionnage des scénarios, RBAC en base, gestion multi-joueurs avec lobby — constitue les fondations d'une plateforme générique de scénarios physiques interactifs, extensible, au prix de l'introduction d'entités supplémentaires dédiées au déploiement physique (site, salle, affectation des scénarios), vers un futur fonctionnement multi-salles ou multi-sites.

DomEscape illustre ainsi comment une modélisation rigoureuse et une architecture orientée événements permettent de construire un système interactif physique cohérent, configurable et robuste — à l'interface entre logiciel, base de données et environnement réel.
