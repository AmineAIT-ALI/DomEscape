# DomEscape — Plan de soutenance 20/20
## 7 jours · Mode militaire

---

## Ordre de lecture des fichiers — du plus critique au moins critique

### NIVEAU 1 — Ligne par ligne, sans excuse
| Fichier | Pourquoi |
|---|---|
| `core/EventManager.php` | Cœur du pipeline entrant — debounce, mapping, normalisation |
| `core/GameEngine.php` | Logique centrale — transaction, validation étape, score, victoire |
| `core/ActionManager.php` | Feedback physique — LCD, Wall Plug, traçabilité |
| `dzvents/domescape_webhook.lua` | Pont IoT/PHP — seul code non-PHP du projet |
| `api/handle_event.php` | Point d'entrée du webhook — auth token, orchestration |
| `sql/schema.sql` (tables métier) | Structure de toute la logique |

### NIVEAU 2 — Comprendre globalement, expliquer le flux
| Fichier | Ce qu'on doit en dire |
|---|---|
| `core/Auth.php` | Sessions PHP, hash bcrypt, rôles |
| `core/RoleGuard.php` | Contrôle d'accès par rôle sur chaque page |
| `core/UserRepository.php` | CRUD utilisateurs, séparation des responsabilités |
| `domoticz/DomoticzClient.php` | Appels HTTP vers Domoticz pour piloter les actionneurs |
| `api/start_game.php` | Création de session, règle mono-salle |
| `api/session_status.php` | Polling temps réel — ce que le joueur et le GM voient |
| `api/send_hint.php` | Envoi d'indice Game Master → LCD |
| `config/app.php`, `config/database.php` | Configuration centralisée |

### NIVEAU 3 — Mentionner, ne pas détailler
| Fichier | Ce qu'on dit |
|---|---|
| `public/player.php` | Interface joueur — polling JS, affichage étapes |
| `public/gamemaster.php` | Interface GM — supervision, télémétrie, actions |
| `public/connexion.php`, `inscription.php` | Auth standard PHP sessions |
| `admin/scenario_edit.php`, `scenarios.php` | Configuration BDD des scénarios |
| `scripts/lcd_service.py` | Service Flask Python, reçoit les messages LCD |
| `scripts/poll_telemetry.php` | Cron toutes les 5 min — température/humidité → BDD |
| `website/` | Site vitrine — HTML/CSS/JS statique, 3 pages |

---

## JOUR 1 — Architecture globale + pipeline complet

### Objectif
Être capable de dessiner et expliquer le pipeline complet sans regarder le code.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–11h | Dessiner l'architecture sur papier : Z-Wave → Domoticz → dzVents → PHP → MariaDB → LCD/UI |
| 11h–13h | Lire `dzvents/domescape_webhook.lua` ligne par ligne. Rédiger la fiche ci-dessous |
| 14h–16h | Lire `api/handle_event.php` + `core/EventManager.php`. Rédiger les fiches |
| 16h–18h | Lire `core/GameEngine.php` intégralement. Rédiger la fiche |
| 18h–19h | Oral seul : expliquer le pipeline à voix haute, sans notes, en 3 minutes |
| 19h–20h | Corriger ce qu'on n'a pas su dire. Refaire |

### Fiche à produire — Pipeline complet

```
PIPELINE DOMESCAPE — UN ÉVÉNEMENT DE A À Z

1. CAPTEUR Z-Wave
   Fibaro Button pressé 3 fois → signal radio Z-Wave

2. DOMOTICZ
   Z-Stick reçoit le signal → Domoticz crée/met à jour le device idx=27
   → nValue change → déclenche le script dzVents

3. DZVENTS (Lua)
   device.name dans SENSOR_NAMES → execute() déclenché
   Filtre WATCHED_IDX[27] = true → pas ignoré
   domoticz.openURL() → POST vers http://localhost/domescape/api/handle_event.php
   Payload : token, idx=27, nvalue=1, svalue=''

4. HANDLE_EVENT.PHP
   Vérifie WEBHOOK_TOKEN (401 si invalide)
   Appelle EventManager::fromWebhook($payload)

5. EVENTMANAGER
   isDuplicate(27, 1) → vérifie logs/debounce_27_1.tmp (500ms)
   findCapteurByIdx(27) → SELECT capteur WHERE domoticz_idx=27 AND actif=1
   mapToCodeEvenement('button_triple', 1, '') → 'BUTTON_TRIPLE_PRESS'
   findEvenementType('BUTTON_TRIPLE_PRESS') → ligne evenement_type
   Retourne : [capteur, code_evenement, evenement_type, raw]

6. GAMEENGINE::process()
   BEGIN TRANSACTION
   SELECT session WHERE statut='en_cours' FOR UPDATE
   Vérifie durée max → pas dépassée
   getEtapeAttend($idEtape) → capteur_attendu + type_evenement_attendu
   matchesAttend() → id_capteur OK + id_type_evenement OK → true
   logEvenement() → INSERT evenement_session
   onSucces() → ActionManager::executeForEtape(idEtape, 'on_success')
              → UPDATE session SET id_etape_courante = suivante
              → ActionManager::executeForEtape(suivante, 'on_enter')
   COMMIT

7. ACTIONMANAGER
   SELECT etape_declenche JOIN actionneur JOIN action_type
   WHERE id_etape=X AND moment='on_success'
   Pour LCD_MESSAGE → GET http://localhost:5000/lcd?msg=ACCESS+GRANTED
   Pour PLUG_ON → DomoticzClient::turnOn(13)
   INSERT action_executee (statut ok/erreur)

8. DOMOTICZ (retour)
   Wall Plug idx=13 → allumé via API HTTP Domoticz

9. INTERFACE JOUEUR
   poll session_status.php toutes les secondes
   → voit l'étape avancer, le message changer
```

### Livrables J1
- [ ] Schéma pipeline dessiné à la main (photo)
- [ ] Fiche EventManager (entrée → sortie → dépendances)
- [ ] Fiche dzVents Lua (rôle + 5 lignes critiques expliquées)
- [ ] Oral pipeline 3 minutes chronométré ✓

---

## JOUR 2 — Base de données

### Objectif
Justifier chaque table, chaque relation, chaque choix de modélisation.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–11h | Lire `sql/schema.sql` intégralement. Une fiche par table métier |
| 11h–13h | Tracer le schéma ER sur papier (toutes les FK) |
| 14h–16h | Justifier pourquoi chaque table existe — oral seul |
| 16h–18h | Tables supprimées — justifier la Core Edition |
| 18h–20h | 10 questions jury BDD (voir liste J2) |

### Fiches à produire — Tables critiques

```
TABLE : session
Rôle : enregistre une partie en cours ou terminée
Colonnes clés :
  id_session          → PK auto
  id_scenario         → FK → scenario (quel jeu)
  nom_equipe          → nom libre saisi au lancement
  statut_session      → ENUM en_cours/gagnee/perdue/abandonnee
  id_etape_courante   → FK → etape (où on en est)
  score               → accumulé à chaque étape validée
  nb_erreurs          → incrémenté à chaque onEchec
  date_debut          → timestamp de démarrage
  date_fin            → NULL tant que en_cours
  duree_secondes      → calculé à la fin
Relations : → scenario, → etape
Pourquoi elle existe : c'est l'état runtime du jeu. GameEngine la lit et la modifie à chaque événement.

TABLE : etape
Rôle : une étape du scénario (puzzle)
Colonnes clés :
  id_etape        → PK
  id_scenario     → FK → scenario
  numero_etape    → ordre de passage (ORDER BY numero_etape ASC)
  nom_etape       → label affiché
  points          → score accordé si validée
  finale          → BOOLEAN — si true, la victoire est déclenchée
Relations : ← etape_attend (ce qu'on attend), ← etape_declenche (ce qu'on déclenche)
Pourquoi elle existe : séparer la config du scénario de la logique du moteur.

TABLE : etape_attend
Rôle : définit l'événement qui valide l'étape
Colonnes clés :
  id_etape          → FK → etape
  id_capteur        → FK → capteur (quel device Z-Wave)
  id_type_evenement → FK → evenement_type (quel code attendu)
  obligatoire       → BOOLEAN (seuls les obligatoires sont vérifiés par matchesAttend)
Pourquoi elle existe : le moteur compare l'événement reçu à cette table. Zéro hardcode.

TABLE : etape_declenche
Rôle : liste les actions à exécuter selon le moment
Colonnes clés :
  id_etape              → FK → etape
  id_actionneur         → FK → actionneur (quel device physique)
  id_type_action        → FK → action_type (LCD_MESSAGE, PLUG_ON, PLUG_OFF...)
  valeur_action         → paramètre (ex: "ACCESS GRANTED")
  moment_declenchement  → ENUM on_enter/on_success/on_failure/on_hint
  ordre_action          → ordre d'exécution
Pourquoi elle existe : dissocier les actions de la logique. Changer un message LCD = UPDATE BDD.

TABLE : evenement_session
Rôle : audit complet de chaque événement reçu pendant la partie
Colonnes clés :
  id_session          → FK → session
  id_capteur          → quel capteur a émis
  id_type_evenement   → quel code (DOOR_OPEN, BUTTON_TRIPLE_PRESS...)
  valeur_brute        → JSON du payload original
  evenement_attendu   → BOOLEAN — 1 si l'événement correspondait à l'étape courante, 0 sinon
  valide              → BOOLEAN — toujours 1 en Core Edition
                        Pourquoi toujours 1 : id_session est NOT NULL sur evenement_session.
                        On n'insère que si une session est active. Un événement hors session
                        ne génère aucun INSERT (GameEngine retourne sans rien logger).
                        La colonne existe pour compatibilité future — elle ne sert pas
                        à distinguer bonne/mauvaise action (c'est le rôle d'evenement_attendu).
  id_etape            → étape courante au moment de l'événement
  date_evenement      → timestamp précis
Pourquoi elle existe : traçabilité totale. Debug, analyse post-partie, audit.

TABLE : action_executee
Rôle : trace chaque action physique déclenchée
Colonnes clés :
  id_session        → FK → session
  id_actionneur     → quel device (LCD, Wall Plug)
  id_type_action    → LCD_MESSAGE, PLUG_ON...
  id_etape          → à quelle étape
  date_execution    → timestamp
  valeur_action     → paramètre envoyé
  statut_execution  → ENUM ok/erreur
Pourquoi elle existe : savoir si le LCD a bien reçu le message, si le Wall Plug a répondu.

TABLE : capteur
Rôle : référentiel des équipements Z-Wave entrants
Colonnes clés :
  id_capteur      → PK
  nom_capteur     → label
  type_capteur    → door_sensor / motion_sensor / button / button_double / button_triple
  domoticz_idx    → clé de jointure avec Domoticz (le lien IoT/PHP)
  actif           → BOOLEAN (filtre les capteurs désactivés)

TABLE : evenement_type
Rôle : liste des codes événements connus du système
Colonnes clés :
  id_type_evenement → PK
  code_evenement    → DOOR_OPEN, DOOR_CLOSE, MOTION_DETECTED, BUTTON_PRESS...
  description       → libellé humain
Pourquoi elle existe : le moteur ne hardcode jamais de strings — il compare des IDs.

TABLE : mesure_capteur
Rôle : télémétrie environnementale
Colonnes clés :
  id_capteur      → FK → capteur (Multisensor)
  type_mesure     → temperature / humidity
  valeur          → float
  date_mesure     → timestamp
Pourquoi elle existe : cron toutes les 5 min → Game Master affiche temp/humidité en direct.
```

### Tables supprimées — Core Edition

```
VERSION PRÉCÉDENTE → CORE EDITION : 8 tables supprimées

Tables supprimées :
  scenario_version      → versionnage des scénarios — hors périmètre démo
  equipe                → entité groupe de joueurs — remplacée par nom_equipe VARCHAR direct sur session
  equipe_utilisateur    → association equipe/utilisateur — inutile sans equipe
  session_utilisateur   → suivi individuel des joueurs en session — inutile sans equipe
  demande_rejoindre_session → système de lobby multi-joueurs — supprimé (lancement direct)
  log_rebond            → log des events Domoticz hors session — inutile (id_session NOT NULL)
  role                  → table RBAC — remplacée par is_admin BOOLEAN sur utilisateur
  utilisateur_role      → association utilisateur/role — inutile sans table role

Justification Core Edition (à avoir en tête pour le jury) :
  "DomEscape est déployé sur une salle unique, avec une session à la fois.
   La complexité multi-équipes, le lobby et le RBAC fin n'apportent rien
   pour une démonstration mono-salle. J'ai simplifié délibérément pour
   garder un moteur lisible, testable et défendable.

   Concrètement :
   - is_admin BOOLEAN suffit : on distingue admin (Game Master) et joueur.
     Pas besoin d'une table role + utilisateur_role pour deux états binaires.
   - nom_equipe VARCHAR dans session suffit : pas besoin de FK vers une
     table equipe pour stocker 'Équipe Alpha'.
   - id_session NOT NULL dans evenement_session suffit : on n'enregistre
     que les événements reçus pendant une session active. Plus besoin
     de log_rebond pour les events hors contexte."

Schéma final Core Edition — 13 tables :
  Catalogues (2) : evenement_type, action_type
  Physique (2)   : capteur, actionneur
  Scénario (4)   : scenario, etape, etape_attend, etape_declenche
  Auth (1)       : utilisateur (is_admin BOOLEAN)
  Runtime (3)    : session, evenement_session, action_executee
  Télémétrie (1) : mesure_capteur
```

### Questions jury J2
1. Pourquoi une table `etape_attend` séparée d'`etape` ?
2. Pourquoi `evenement_attendu` est dans `evenement_session` et non juste dans les logs ?
3. Que se passe-t-il si on insère deux lignes `etape_attend` pour la même étape avec `obligatoire=1` ?
4. Pourquoi `SELECT FOR UPDATE` dans GameEngine ?
5. Quel est le risque sans le `FOR UPDATE` ?
6. Pourquoi `duree_secondes` est calculé et stocké plutôt que calculé à la volée ?
7. Que contient `valeur_brute` dans `evenement_session` ?
8. Comment le moteur sait-il quelle est la première étape d'un scénario ?
9. Pourquoi `finale = true` sur la dernière étape plutôt qu'un compteur ?
10. Comment garantir qu'un seul scénario tourne à la fois ?

### Livrables J2
- [ ] Fiche par table métier (rôle + colonnes clés + pourquoi)
- [ ] Schéma ER papier avec toutes les FK
- [ ] Réponses orales aux 10 questions ci-dessus

---

## JOUR 3 — Fichiers critiques ligne par ligne

### Objectif
Expliquer chaque décision de code dans EventManager, GameEngine, ActionManager.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–11h | EventManager.php — lire, annoter, produire la fiche |
| 11h–13h | GameEngine.php — lire, annoter, produire la fiche |
| 14h–15h | ActionManager.php — lire, annoter, produire la fiche |
| 15h–17h | Questions pièges sur les 3 fichiers (voir liste J3) |
| 17h–19h | Expliquer `process()` ligne par ligne à voix haute, sans le code |
| 19h–20h | Refaire l'oral. S'enregistrer. Réécouter. |

### Fiches à produire

```
FICHIER : core/EventManager.php
Rôle    : normaliser un payload webhook brut en événement structuré
Entrée  : array $payload — [idx, nvalue, svalue]
Sortie  : array|null — [capteur, code_evenement, evenement_type, raw]
Dépendances : capteur (BDD), evenement_type (BDD), logs/ (debounce)

Étapes internes de fromWebhook() :
  1. Valider que idx > 0
  2. isDuplicate(idx, nvalue) → vérifier logs/debounce_idx_nvalue.tmp
     - Si fichier < 500ms → return null (doublon filtré)
     - Sinon → écrire microtime() dans le fichier
  3. findCapteurByIdx(idx) → SELECT capteur WHERE domoticz_idx=idx AND actif=1
     - null → log + return null
  4. mapToCodeEvenement(type_capteur, nvalue, svalue) → switch
     - door_sensor : nvalue=0 ou svalue contient 'clos/closed' → DOOR_CLOSE sinon DOOR_OPEN
     - motion_sensor : filtrer tamper → MOTION_DETECTED si nvalue>0
     - button : nvalue>0 → BUTTON_PRESS
     - button_double : nvalue>0 → BUTTON_DOUBLE_PRESS
     - button_triple : nvalue>0 → BUTTON_TRIPLE_PRESS
  5. findEvenementType(code) → SELECT evenement_type WHERE code_evenement=code
  6. Retourner le tableau structuré

Erreurs possibles :
  - idx inconnu en BDD → null (capteur non configuré)
  - Doublon < 500ms → null (debounce)
  - Type capteur non mappé → null (default switch)
  - Code événement absent de BDD → null (configuration incohérente)

---

FICHIER : core/GameEngine.php
Rôle    : décider ce qui se passe à la réception d'un événement normalisé
Entrée  : array $event (retour EventManager::fromWebhook)
Sortie  : void (tout passe par BDD + ActionManager)
Dépendances : session, etape, etape_attend, evenement_session (BDD), ActionManager

Étapes internes de process() :
  1. BEGIN TRANSACTION
  2. SELECT session WHERE statut='en_cours' FOR UPDATE
     → Verrou pessimiste : empêche deux webhooks simultanés de modifier la même session
     → Si aucune session → COMMIT + return (événement ignoré silencieusement)
  3. Vérifier duree_max_secondes
     → Si dépassé → UPDATE statut='perdue' + COMMIT + return
  4. getEtapeAttend(id_etape_courante) → SELECT etape_attend WHERE obligatoire=1
  5. matchesAttend(event, attendu)
     → Comparer id_capteur ET id_type_evenement
     → Les deux doivent correspondre exactement
  6. logEvenement() → INSERT evenement_session (audit systématique)
  7. Si valide → onSucces()
     → ActionManager::executeForEtape(idEtape, 'on_success')
     → Si finale=true → UPDATE session statut='gagnee', date_fin, score, duree_secondes
     → Sinon → getEtapeSuivante() → UPDATE session id_etape_courante=suivante
              → ActionManager::executeForEtape(suivante, 'on_enter')
  8. Si invalide → onEchec()
     → ActionManager::executeForEtape(idEtape, 'on_failure')
     → UPDATE session nb_erreurs+1
  9. COMMIT
  10. En cas d'exception → ROLLBACK + error_log (pas de throw → pas de 500 HTTP)

---

FICHIER : core/ActionManager.php
Rôle    : exécuter les actions physiques définies en base pour une étape/moment
Entrée  : int $idEtape, string $moment ('on_enter'|'on_success'|'on_failure'|'on_hint'), int $idSession
Sortie  : void (actions physiques + INSERT action_executee)
Dépendances : etape_declenche, actionneur, action_type (BDD), DomoticzClient, service LCD Python

Étapes internes de executeForEtape() :
  1. SELECT etape_declenche JOIN actionneur JOIN action_type
     WHERE id_etape=X AND moment_declenchement=moment
     ORDER BY ordre_action ASC
  2. Pour chaque action → execute($action)
     → LCD_MESSAGE : GET http://localhost:5000/lcd?msg=... (timeout 2s)
     → PLUG_ON : DomoticzClient::turnOn(idx)
     → PLUG_OFF : DomoticzClient::turnOff(idx)
     → LOG_ONLY : rien (journalise uniquement)
  3. Try/catch sur chaque action → statut = 'ok' ou 'erreur'
  4. INSERT action_executee (traçabilité même en cas d'erreur LCD)

Choix de conception importants :
  - Le LCD ne bloque pas en cas d'échec (timeout 2s → on continue)
  - DomoticzClient est un singleton (une seule connexion par requête)
  - L'ordre d'exécution est contrôlé par ordre_action en BDD
```

### Questions pièges J3
1. Que se passe-t-il si le service LCD est éteint pendant la démo ?
2. Pourquoi ne pas utiliser `sleep()` pour attendre la réponse du LCD ?
3. Qu'est-ce qu'une race condition et comment GameEngine la résout ?
4. Pourquoi `rollBack()` et pas un message d'erreur HTTP ?
5. Que retourne `fromWebhook()` si le capteur n'est pas dans la BDD ?
6. Pourquoi le debounce utilise des fichiers tmp et non la BDD ?
7. Que fait le moteur si `finale=false` mais qu'il n'y a pas d'étape suivante ?
8. Pourquoi `matchesAttend` compare l'`id_capteur` et pas le `domoticz_idx` directement ?
9. Quel est l'avantage de `LOG_ONLY` comme type d'action ?
10. Que se passe-t-il si deux webhooks arrivent en même temps pour la même session ?

---

## JOUR 4 — dzVents Lua + Domoticz + Z-Wave

### Objectif
Comprendre et défendre la couche IoT sans être expert Lua ni Z-Wave.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–10h | Lire `dzvents/domescape_webhook.lua` ligne par ligne. Fiche |
| 10h–12h | Comprendre Domoticz : devices, idx, nvalue/svalue, dzVents |
| 12h–13h | Comprendre Z-Wave : réseau maillé, contrôleur, nodes |
| 14h–16h | Lire `domoticz/DomoticzClient.php` + comprendre l'API HTTP Domoticz |
| 16h–18h | Questions jury couche IoT |
| 18h–20h | Oral : expliquer le chemin d'un appui bouton jusqu'au PHP |

### Fiche dzVents

```
FICHIER : dzvents/domescape_webhook.lua
Rôle    : pont entre Domoticz et le moteur PHP
Langage : Lua (dzVents — framework Domoticz intégré)

Double filtre (sécurité) :
  1. on.devices = SENSOR_NAMES → Domoticz n'appelle le script que pour ces devices
  2. WATCHED_IDX[device.id] → filtre par idx dans execute() (évite les faux noms)

Payload envoyé en POST :
  token  = WEBHOOK_TOKEN (doit matcher WEBHOOK_TOKEN dans secrets.php)
  idx    = device.id (identifiant Domoticz du capteur)
  nvalue = device.nValue (valeur numérique de l'état)
  svalue = device.sValue (valeur texte — peut contenir 'Open', 'Closed', 'tamper'...)

Pourquoi Lua et pas directement PHP ?
  → Domoticz embarque dzVents comme scripting natif
  → On ne peut pas appeler PHP directement depuis le réseau Z-Wave
  → dzVents est le seul point d'intégration entre la couche Z-Wave et HTTP

Pourquoi idx et pas le nom du device ?
  → Le nom peut changer. L'idx est un identifiant stable dans Domoticz.
  → On garde SENSOR_NAMES pour le déclenchement dzVents (obligatoire)
    mais on refiltre par idx pour la logique métier.
```

### Ce qu'il faut savoir sur Z-Wave

```
Z-Wave est un protocole radio basse fréquence (868 MHz en Europe)
  → Réseau maillé : chaque device relaye le signal
  → Contrôleur : Z-Stick USB connecté au Raspberry Pi
  → Domoticz gère le réseau Z-Wave via le Z-Stick

Chaque device Z-Wave a un "Node ID" dans le réseau.
Domoticz attribue un "idx" à chaque valeur d'un device.
Un device physique peut avoir plusieurs idx (ex: Fibaro Button → idx 9, 27, 30).

Fibaro Button FGPB-101 :
  → Simple appui : nvalue sur idx 9
  → Double appui : nvalue sur idx 30 (device séparé dans Domoticz)
  → Triple appui : nvalue sur idx 27 (device séparé dans Domoticz)

Capteur de porte :
  → Envoie nvalue=0 (fermée) ou nvalue=255 (ouverte) sur idx 25
  → Type "Alarm Type: Access Control 6 (0x06)" dans Domoticz

Multisensor :
  → Motion : nvalue>0 sur idx 7 (Home Security)
  → Température : idx 8 (valeur float dans svalue)
  → Tamper = movement du device → filtré dans EventManager (svalue contient 'tamper')
```

---

## JOUR 5 — Scénarios + Tests + Démonstration

### Objectif
Maîtriser Protocole Omega étape par étape. Préparer le plan de secours.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–11h | Interroger la BDD du Pi pour sortir les données réelles de Protocole Omega |
| 11h–13h | Compléter la fiche scénario avec les vraies données |
| 14h–16h | Répétition démo complète — chronométrer |
| 16h–17h | Préparer le plan de secours |
| 17h–20h | Jouer la démo 3 fois de suite en conditions réelles |

### Commandes BDD à lancer sur le Pi
```sql
-- Lister les scénarios
SELECT * FROM scenario;

-- Étapes de Protocole Omega
SELECT e.*, ea.id_capteur, ea.id_type_evenement, et.code_evenement,
       c.nom_capteur, c.domoticz_idx
FROM etape e
JOIN etape_attend ea ON e.id_etape = ea.id_etape
JOIN evenement_type et ON ea.id_type_evenement = et.id_type_evenement
JOIN capteur c ON ea.id_capteur = c.id_capteur
WHERE e.id_scenario = 1
ORDER BY e.numero_etape;

-- Actions associées à chaque étape
SELECT e.numero_etape, ed.moment_declenchement, at.code_action,
       ed.valeur_action, a.nom_actionneur, ed.ordre_action
FROM etape e
JOIN etape_declenche ed ON e.id_etape = ed.id_etape
JOIN action_type at ON ed.id_type_action = at.id_type_action
JOIN actionneur a ON ed.id_actionneur = a.id_actionneur
WHERE e.id_scenario = 1
ORDER BY e.numero_etape, ed.moment_declenchement, ed.ordre_action;
```

### Fiche scénario — données réelles BDD

```
SCÉNARIO 1 : DomEscape Lab 01 (id_scenario=1)
Thème      : Laboratoire sécurisé
Durée max  : 3600 secondes (1h)
Étapes     : 4

ÉTAPE 1 — Boot Sequence (id_etape=1, points=100)
  Attendu         : BUTTON_PRESS sur Button (idx 9)
  on_enter        : LCD → "En veille..."
  on_success      : LCD → "Niveau 1 OK !" + PLUG_ON (Wall Plug)
  on_failure      : LCD → "Invalide !"

ÉTAPE 2 — Secret Door (id_etape=2, points=150)
  Attendu         : DOOR_OPEN sur Porte (idx 25)
  on_enter        : LCD → "Zone restreinte"
  on_success      : LCD → "Acces autorise!"
  on_failure      : LCD → "Intrus detecte!"

ÉTAPE 3 — Motion Scan (id_etape=3, points=200)
  Attendu         : MOTION_DETECTED sur Multisensor (idx 7)
  on_enter        : LCD → "Scan en attente"
  on_success      : LCD → "Scan valide !" + PLUG_ON
  on_failure      : LCD → "Hors perimetre!"

ÉTAPE 4 — Final Code (id_etape=4, points=300, finale=TRUE)
  Attendu         : BUTTON_DOUBLE_PRESS sur Button Double (idx 30)
  on_enter        : LCD → "Double appui !"
  on_success      : LCD → "ESCAPE SUCCESS!" + PLUG_ON → statut='gagnee'
  on_failure      : LCD → "Code invalide !"

---

SCÉNARIO 2 : Protocole Omega — AI Containment (id_scenario=2)
Thème      : Confinement IA
Durée max  : 3600 secondes
Étapes     : 5

ÉTAPE 1 — AI Core Initialization (id_etape=5, points=100)
  Attendu         : BUTTON_TRIPLE_PRESS sur Button Triple (idx 27)
  on_enter        : LCD → "AI CORE OFFLINE"
  on_success      : LCD → "AI CORE ONLINE" + PLUG_ON
  on_failure      : LCD → "CORE ERROR"

ÉTAPE 2 — Power Grid Recovery (id_etape=6, points=150)
  Attendu         : BUTTON_PRESS sur Button (idx 9)
  on_enter        : LCD → "MAIN POWER LOST"
  on_success      : LCD → "POWER RESTORED" + PLUG_ON
  on_failure      : LCD → "POWER FAILURE"

ÉTAPE 3 — Security Gate (id_etape=7, points=200)
  Attendu         : DOOR_OPEN sur Porte (idx 25)
  on_enter        : LCD → "SECURITY LOCKED"
  on_success      : LCD → "ACCESS GRANTED"
  on_failure      : LCD → "ACCESS DENIED"

ÉTAPE 4 — Biometric Scan (id_etape=8, points=200)
  Attendu         : MOTION_DETECTED sur Multisensor (idx 7)
  on_enter        : LCD → "BIOMETRIC SCAN"
  on_success      : LCD → "SCAN COMPLETE" + PLUG_OFF + PLUG_ON
  on_failure      : LCD → "SCAN FAILED"

ÉTAPE 5 — Omega Containment (id_etape=9, points=300, finale=TRUE)
  Attendu         : BUTTON_DOUBLE_PRESS sur Button Double (idx 30)
  on_enter        : LCD → "DOUBLE CONFIRM"
  on_success      : LCD → "CONTAINMENT OK" + PLUG_OFF + PLUG_ON + PLUG_OFF
                    → statut='gagnee', date_fin, duree_secondes calculée
  on_failure      : LCD → "OMEGA FAILED"
```

### Plan de secours matériel

```
PROBLÈME : LCD éteint ou service Python planté
SOLUTION :
  1. ssh pi@192.168.4.1
  2. sudo systemctl restart domescape-lcd
  3. Vérifier : curl http://localhost:5000/lcd?msg=TEST
  Si toujours KO → expliquer au jury : "Le service LCD est découplé du moteur,
  une erreur LCD n'arrête pas la partie — l'ActionManager log 'erreur' et continue."

PROBLÈME : Domoticz ne répond plus
SOLUTION :
  1. sudo systemctl restart domoticz
  2. Attendre 30s, vérifier http://192.168.4.1:8080
  Si toujours KO → montrer le pipeline en BDD :
  "Je vais montrer les logs evenement_session qui prouvent que le moteur fonctionne."

PROBLÈME : Capteur Z-Wave ne répond pas
SOLUTION :
  1. Vérifier Domoticz Setup > Devices → device actif ?
  2. Exclure/réinclure le device si nécessaire
  Si pas le temps → lancer l'événement manuellement via Domoticz :
  "En conditions de test, je peux simuler l'événement directement dans Domoticz."

PROBLÈME : Le Pi n'est pas joignable
SOLUTION :
  1. Vérifier réseau Wi-Fi
  2. Redémarrer le Pi (bouton power)
  En dernier recours → montrer le code et la BDD en local, expliquer l'architecture.

PROBLÈME : La démo plante au milieu
SOLUTION :
  1. ssh pi@192.168.4.1 → SQL : UPDATE session SET statut_session='abandonnee' WHERE statut_session='en_cours';
  2. Relancer depuis le tableau de bord admin
  Si jury stressé → "La réinitialisation est une fonctionnalité normale du Game Master."
```

---

## JOUR 6 — Questions/Réponses + Oral

### Objectif
Répondre à n'importe quelle question sans bégayer.

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–12h | Parcourir les 50 questions — rédiger les réponses clés |
| 14h–17h | Simulation d'oral avec quelqu'un (ou enregistrement) |
| 17h–20h | Travailler les 10 questions les plus dangereuses |

### Les 50 questions potentielles du jury

**Architecture**
1. Expliquez le rôle de chaque couche du pipeline.
2. Pourquoi avoir choisi PHP et non Node.js ou Python ?
3. Pourquoi Domoticz et non une solution custom ?
4. Qu'est-ce qu'un réseau Z-Wave ?
5. Pourquoi avoir séparé EventManager, GameEngine et ActionManager ?
6. Qu'est-ce que le principe de responsabilité unique ici ?
7. Votre serveur PHP est-il stateful ou stateless ?
8. Comment la session est-elle persistée entre deux requêtes HTTP ?
9. Pourquoi dzVents et non un script Python ou une intégration directe ?
10. Qu'est-ce qu'un webhook ?

**Base de données**
11. Pourquoi une table `etape_attend` séparée ?
12. Que contient `valeur_brute` dans `evenement_session` ?
13. Pourquoi `SELECT FOR UPDATE` dans GameEngine ?
14. Que se passe-t-il si deux événements arrivent simultanément ?
15. Comment le moteur sait-il quelle étape valider ensuite ?
16. Pourquoi stocker `duree_secondes` et non la calculer à la volée ?
17. Qu'est-ce que le champ `obligatoire` dans `etape_attend` ?
18. Comment ajouter un nouveau scénario sans toucher au code ?
19. Pourquoi une table `evenement_type` plutôt que des constantes PHP ?
20. Comment la télémétrie est-elle collectée et stockée ?

**Code**
21. Expliquez `isDuplicate()` ligne par ligne.
22. Pourquoi fichier tmp pour le debounce et non Redis ou BDD ?
23. Que fait `matchesAttend()` exactement ?
24. Que se passe-t-il si `getEtapeAttend()` retourne null ?
25. Pourquoi `onSucces()` déclenche `on_success` puis `on_enter` de l'étape suivante ?
26. Pourquoi le catch dans `process()` ne throw pas l'exception ?
27. Qu'est-ce que le singleton dans ActionManager ?
28. Pourquoi un timeout de 2 secondes sur le LCD ?
29. Comment le token Webhook protège-t-il l'API ?
30. Que se passe-t-il si le service LCD est hors ligne ?

**Sécurité**
31. Comment authentifiez-vous les requêtes du webhook Domoticz ?
32. Comment protégez-vous l'interface admin ?
33. Que fait `RoleGuard::requireAdmin()` exactement ?
34. Avez-vous des injections SQL possibles ?
35. Où sont stockés les secrets ? Sont-ils dans Git ?

**Tests**
36. Comment avez-vous testé le pipeline sans le matériel ?
37. Avez-vous des tests automatisés ?
38. Comment avez-vous validé le debounce ?
39. Que se passe-t-il si un capteur envoie un idx inconnu ?
40. Comment avez-vous géré les faux positifs du Multisensor (tamper) ?

**Limites et évolutions**
41. Quelle est la limite principale de votre système actuel ?
42. Peut-on gérer plusieurs salles en parallèle ?
43. Pourquoi une salle à la fois (mono-salle) ?
44. Comment scalez-vous si vous avez 10 scénarios en même temps ?
45. Votre système fonctionne-t-il sans Internet ?

**Conception**
46. Qu'avez-vous appris de plus important dans ce projet ?
47. Si vous recommenciez, que feriez-vous différemment ?
48. Pourquoi avoir choisi MariaDB plutôt que PostgreSQL ?
49. Avez-vous envisagé WebSockets plutôt que polling ?
50. Comment ce projet pourrait-il être industrialisé ?

---

### Réponses types aux questions les plus dangereuses

**Q : Votre serveur est-il stateful ou stateless ?**
> "Stateless. PHP ne stocke aucun état en mémoire entre deux requêtes. À chaque appel à `process()`, GameEngine relit l'intégralité de l'état depuis MariaDB — session active, étape courante, score. C'est un choix délibéré : si le Pi redémarre pendant une partie, la session est intacte en BDD. La reprise est possible."

**Q : Que se passe-t-il si deux événements arrivent simultanément ?**
> "GameEngine ouvre une transaction et exécute `SELECT ... FOR UPDATE`. Cela pose un verrou pessimiste sur la ligne de session en cours. Le second appel concurrent attend que le premier commit. Cela garantit qu'une seule étape est validée à la fois, même si deux capteurs déclenchent simultanement."

**Q : Avez-vous des injections SQL ?**
> "Non. Toutes les requêtes SQL utilisent des `prepare()` / `execute()` avec des paramètres positionnels. Les valeurs utilisateur ne sont jamais concaténées dans une chaîne SQL. C'est visible dans EventManager `findCapteurByIdx()`, GameEngine `getEtape()`, et partout dans le code."

**Q : Pourquoi fichier tmp pour le debounce et non la BDD ?**
> "Le debounce doit fonctionner en microsecondes. Un aller-retour BDD (même MariaDB sur le même Pi) coûte 1 à 5ms minimum. En utilisant un fichier local, `is_file()` + `file_get_contents()` s'exécutent en microsecondes — suffisant pour filtrer une fenêtre de 500ms. C'est un choix de performance adapté au contexte embarqué."

**Q : Si vous recommenciez, que feriez-vous différemment ?**
> "J'aurais structuré les tests d'intégration plus tôt — notamment un script de simulation qui rejoue une séquence d'événements sans le matériel. J'aurais aussi considéré WebSockets pour le polling temps réel, mais pour 1 salle et 1 session, le polling toutes les secondes est parfaitement suffisant."

**Q : Avez-vous envisagé WebSockets ?**
> "Oui. Le polling JSON toutes les secondes génère environ 1 requête/s par client. Sur une démonstration avec 3 à 5 écrans, c'est 3 à 5 requêtes légères par seconde — négligeable pour un Pi. WebSockets aurait ajouté une dépendance (Ratchet ou un serveur Node) et une complexité opérationnelle non justifiée pour ce contexte."

---

### Stratégie "vibe coding assumé mais défendable"

**Ne pas mentir. Ne pas fuir. Retourner l'avantage.**

```
Si le jury demande : "Avez-vous écrit tout ce code vous-même ?"

Réponse honnête et forte :
"J'ai développé ce projet avec assistance IA pour accélérer certaines
parties du code. Ce qui compte, c'est que je comprends chaque décision
architecturale et que je peux défendre chaque ligne.

Par exemple, le SELECT FOR UPDATE dans GameEngine — je saurais l'écrire
sans aide, parce que j'ai compris pourquoi la race condition existe et
comment la résoudre. Le debounce via fichier tmp — c'est un choix que
j'ai fait et que je justifie par la latence BDD vs filesystem.

L'assistance IA m'a aidé à aller plus vite. Elle ne m'a pas appris
à quoi servent les transactions ou pourquoi on sépare EventManager
de GameEngine. Ça, c'est de la conception, pas de la génération."

Règle : Ne jamais dire "je ne sais pas" sur un fichier critique.
Si vous êtes bloqué → "Laissez-moi reformuler la question pour être précis."
```

---

## JOUR 7 — Répétition générale + Checklist finale

### Blocs horaires

| Heure | Travail |
|---|---|
| 9h–11h | Oral complet devant quelqu'un ou caméra — 15 minutes chrono |
| 11h–12h | Démo complète Protocole Omega — deux fois |
| 14h–16h | Questions surprise — choisies aléatoirement dans la liste des 50 |
| 16h–17h | Schéma pipeline au tableau sans support |
| 17h–18h | Dernière relecture fiches |
| 18h–19h | Vérification matériel et Pi |
| 19h–20h | Arrêt. Repos. |

### Méthode de relecture rapide du code

```
Pour chaque fichier NIVEAU 1 :
  1. Lire les commentaires en tête de fichier (rôle, tables, entrée/sortie)
  2. Identifier les méthodes publiques (ce que les autres appellent)
  3. Suivre le flux de la méthode principale de haut en bas
  4. Repérer les 3 lignes les plus importantes (souvent : transaction, validation, log)
  5. Pas besoin de mémoriser les helpers — savoir qu'ils existent et ce qu'ils font

Temps cible par fichier NIVEAU 1 : 20 minutes
Temps cible par fichier NIVEAU 2 : 10 minutes
Fichiers NIVEAU 3 : lecture en diagonale, 5 minutes max
```

---

## Grille d'auto-évaluation quotidienne /20

À remplir chaque soir. Soyez honnête.

| Critère | /20 | Note J1 | J2 | J3 | J4 | J5 | J6 | J7 |
|---|---|---|---|---|---|---|---|---|
| Pipeline : expliquer de bout en bout sans hésitation | 4 | | | | | | | |
| BDD : justifier chaque table et relation | 3 | | | | | | | |
| Code : EventManager + GameEngine + ActionManager | 5 | | | | | | | |
| Couche IoT : dzVents + Domoticz + Z-Wave | 2 | | | | | | | |
| Oral : fluidité, structure, pas de "euh" | 3 | | | | | | | |
| Plan de secours : réponse calme sur panne matériel | 2 | | | | | | | |
| Limites assumées + questions pièges | 1 | | | | | | | |

**Seuil minimal avant soutenance : 16/20 sur chaque critère.**

---

## Checklist finale — 20/20

### Maîtrise technique
- [ ] Je peux dessiner le pipeline complet au tableau en 2 minutes
- [ ] Je peux expliquer `process()` ligne par ligne sans le code
- [ ] Je peux justifier `SELECT FOR UPDATE` et la transaction
- [ ] Je peux expliquer le debounce et pourquoi fichier tmp
- [ ] Je peux justifier chaque table de la BDD
- [ ] Je connais les idx Z-Wave (7, 9, 25, 27, 30) et leur device associé
- [ ] Je sais ce que dzVents fait exactement (double filtre, payload, callback)
- [ ] Je peux expliquer les 4 moments d'ActionManager (on_enter/success/failure/hint)
- [ ] Je sais ce que contient `valeur_brute` dans `evenement_session`
- [ ] Je connais les 4 codes d'action (LCD_MESSAGE, PLUG_ON, PLUG_OFF, LOG_ONLY)

### Démonstration
- [ ] Pi allumé, Domoticz actif, LCD actif, réseau OK
- [ ] Scénario Protocole Omega en BDD, aucune session en cours
- [ ] Je peux lancer la démo en moins de 30 secondes
- [ ] Je connais le plan de secours par cœur pour chaque type de panne
- [ ] J'ai fait la démo 3 fois en conditions réelles

### Oral
- [ ] J'ai répondu aux 50 questions à voix haute au moins une fois
- [ ] Je connais les réponses aux 5 questions les plus dangereuses
- [ ] J'ai préparé ma réponse sur le vibe coding
- [ ] Je peux expliquer ce que j'aurais fait différemment (crédibilité)
- [ ] Je suis capable de rester calme sur une question à laquelle je ne sais pas répondre

---

*DomEscape — Préparation soutenance · 7 jours · MIAGE 2025-2026*
