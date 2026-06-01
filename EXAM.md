# DomEscape — Préparation contrôle sur table 20/20
## 2h · 4 parties : PHP/SQL · Domoticz · Web · Gestion

> Objectif : répondre sans hésitation, écrire du code correct, justifier chaque choix.
> Format écrit = précision absolue. Pas de "à peu près". Pas de reformulation.

---

## RÈGLES D'OR POUR 20/20 À L'ÉCRIT

```
1. Toujours définir avant d'expliquer.
2. Toujours justifier un choix par un risque évité ou un avantage concret.
3. Ne jamais écrire de SQL sans WHERE sur un UPDATE.
4. Ne jamais écrire de PHP sans mentionner les prepare/execute.
5. Structurer chaque réponse : définition → fonctionnement → exemple dans DomEscape.
6. Une question sur l'architecture = répondre en termes de couplage, responsabilité, maintenabilité.
```

---

## PARTIE 1 — PHP / SQL

### Ce qu'il faut savoir écrire sans réfléchir

#### Connexion PDO
```php
$pdo = new PDO(
    'mysql:host=localhost;dbname=domescape;charset=utf8mb4',
    'user',
    'password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
```

#### Requête préparée — SELECT
```php
$stmt = $pdo->prepare('SELECT * FROM capteur WHERE domoticz_idx = ? AND actif = 1');
$stmt->execute([$idx]);
$capteur = $stmt->fetch(); // null si pas de résultat
```

#### Requête préparée — INSERT
```php
$pdo->prepare("
    INSERT INTO evenement_session (id_session, id_capteur, id_type_evenement, valeur_brute, evenement_attendu, valide, date_evenement)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
")->execute([$idSession, $idCapteur, $idTypeEvenement, json_encode($raw), $estValide ? 1 : 0]);
```

#### Transaction avec rollback
```php
$pdo->beginTransaction();
try {
    // opérations
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
}
```

#### SELECT FOR UPDATE
```php
$stmt = $pdo->query("
    SELECT * FROM session
    WHERE statut_session = 'en_cours'
    ORDER BY date_debut DESC
    LIMIT 1
    FOR UPDATE
");
```

---

### Connaissances PHP à maîtriser

| Concept | Définition | Usage dans DomEscape |
|---|---|---|
| `prepare()` / `execute()` | Requête paramétrée — sépare SQL et données | Partout. Empêche l'injection SQL |
| `PDO::FETCH_ASSOC` | Retourne un tableau associatif (clé = nom colonne) | Toutes les requêtes SELECT |
| `beginTransaction()` | Démarre une transaction ACID | GameEngine::process() |
| `FOR UPDATE` | Verrou pessimiste sur la ligne lue | Évite la race condition sur session |
| `rollBack()` | Annule toutes les opérations depuis beginTransaction | En cas d'exception dans process() |
| `json_encode()` | Sérialise un tableau PHP en JSON | Stockage de valeur_brute dans evenement_session |
| `microtime(true)` | Timestamp en microsecondes (float) | Debounce — mesure la fenêtre de 500ms |
| `file_get_contents()` / `file_put_contents()` | Lecture/écriture fichier | Debounce — lecture/écriture des .tmp |
| `stream_context_create()` | Créer un contexte HTTP pour file_get_contents | Appel au service LCD (timeout 2s) |
| `password_hash()` / `password_verify()` | Hash bcrypt + vérification | Auth utilisateur |

---

### Connaissances SQL à maîtriser

#### JOINs critiques à savoir écrire

```sql
-- Actions d'une étape pour un moment donné (ActionManager)
SELECT ed.valeur_action, at.code_action, a.domoticz_idx, a.nom_actionneur
FROM etape_declenche ed
JOIN actionneur a   ON ed.id_actionneur  = a.id_actionneur
JOIN action_type at ON ed.id_type_action = at.id_type_action
WHERE ed.id_etape = 5 AND ed.moment_declenchement = 'on_success'
ORDER BY ed.ordre_action ASC;

-- Étape suivante (GameEngine)
SELECT * FROM etape
WHERE id_scenario = ? AND numero_etape > ?
ORDER BY numero_etape ASC
LIMIT 1;

-- Ce qu'une étape attend (GameEngine)
SELECT * FROM etape_attend
WHERE id_etape = ? AND obligatoire = 1
LIMIT 1;

-- Session active
SELECT s.*, sc.duree_max_secondes
FROM session s
JOIN scenario sc ON s.id_scenario = sc.id_scenario
WHERE s.statut_session = 'en_cours'
ORDER BY s.date_debut DESC
LIMIT 1
FOR UPDATE;
```

#### UPDATEs critiques
```sql
-- Victoire
UPDATE session
SET statut_session = 'gagnee', date_fin = NOW(),
    score = ?, duree_secondes = ?
WHERE id_session = ?;

-- Avancer à l'étape suivante
UPDATE session SET id_etape_courante = ?, score = ?
WHERE id_session = ?;

-- Incrémenter les erreurs
UPDATE session SET nb_erreurs = nb_erreurs + 1
WHERE id_session = ?;

-- Abandonner toutes les sessions en cours
UPDATE session
SET statut_session = 'abandonnee', date_fin = NOW(),
    duree_secondes = TIMESTAMPDIFF(SECOND, date_debut, NOW())
WHERE statut_session = 'en_cours';
```

---

### Questions type PHP/SQL avec réponses modèles

**Q1 — Qu'est-ce qu'une injection SQL et comment DomEscape s'en protège ?**

> Une injection SQL consiste à insérer du code SQL malveillant dans une entrée utilisateur pour manipuler la requête. Par exemple : `' OR 1=1 --`. DomEscape utilise exclusivement des requêtes préparées PDO (`prepare()` + `execute()`). Les valeurs sont passées comme paramètres liés, jamais concaténées dans la chaîne SQL. Le driver PDO échappe automatiquement les valeurs avant de les envoyer à MariaDB.

**Q2 — Pourquoi utilise-t-on `SELECT FOR UPDATE` dans GameEngine ?**

> `SELECT FOR UPDATE` pose un verrou exclusif sur la ligne lue jusqu'au `COMMIT`. Si deux webhooks arrivent en même temps (double appui rapide), le second attend que le premier ait terminé sa transaction. Sans ce verrou, les deux pourraient lire la même session "en cours" et valider la même étape deux fois — résultat : avancement incorrect du scénario (race condition). Le verrou garantit qu'une seule exécution modifie la session à un instant donné.

**Q3 — Expliquez le rôle de la transaction dans GameEngine::process()**

> La transaction (`beginTransaction` / `commit` / `rollBack`) regroupe plusieurs opérations en une unité atomique : lire la session, valider l'événement, loguer dans evenement_session, mettre à jour le score et l'étape courante. Si une opération échoue (erreur BDD, exception), le `rollBack()` annule tout — la session reste dans l'état cohérent d'avant l'appel. Sans transaction, une erreur à mi-chemin laisserait la BDD dans un état incohérent (étape avancée sans log, score mis à jour sans validation).

**Q4 — Écrire la fonction qui vérifie le debounce Z-Wave**

```php
private static function isDuplicate(int $idx, int $nvalue): bool
{
    $file = __DIR__ . '/../logs/debounce_' . $idx . '_' . $nvalue . '.tmp';
    $now  = microtime(true);

    if (is_file($file)) {
        $last = (float) file_get_contents($file);
        if (($now - $last) * 1_000_000 < 500_000) { // 500ms en µs
            return true; // doublon
        }
    }

    file_put_contents($file, $now);
    return false;
}
```

**Q5 — Qu'est-ce qu'une clé étrangère avec `ON DELETE CASCADE` ? Donner un exemple du projet.**

> `ON DELETE CASCADE` signifie que supprimer une ligne dans la table parente supprime automatiquement toutes les lignes liées dans les tables enfants. Dans DomEscape : `etape` a `ON DELETE CASCADE` vers `scenario`. Si on supprime le scénario 1, toutes ses étapes sont supprimées automatiquement — ainsi que les `etape_attend` et `etape_declenche` liées (elles aussi en CASCADE). Cela maintient l'intégrité référentielle sans manipulation manuelle.

**Q6 — Quelle est la différence entre `evenement_attendu` et `valide` dans `evenement_session` ?**

> `evenement_attendu` (BOOLEAN) indique si l'événement correspondait à ce que l'étape courante attendait — `1` si c'était le bon capteur + le bon type d'événement, `0` sinon (mauvaise action du joueur). `valide` est toujours `1` en Core Edition : il signifie que l'événement a été correctement reçu et traité. La colonne `id_session NOT NULL` garantit qu'on n'insère dans `evenement_session` que lorsqu'une session est active — les événements hors-session ne sont pas enregistrés.

**Q7 — Pourquoi stocker `duree_secondes` plutôt que la calculer depuis `date_debut` et `date_fin` ?**

> Calculer à la volée `TIMESTAMPDIFF(SECOND, date_debut, date_fin)` est possible mais dépend de la présence de `date_fin`. Si `date_fin` est NULL (session en cours), le calcul est impossible. Stocker `duree_secondes` à la clôture de session simplifie les requêtes de classement, de statistiques et d'affichage — une lecture directe d'une colonne INT est plus rapide qu'un calcul et fonctionne même après archivage.

---

### Schéma des 13 tables à connaître par cœur

```
CATALOGUES          : evenement_type · action_type
PHYSIQUE            : capteur · actionneur
SCÉNARIO            : scenario → etape → etape_attend (ternaire)
                                       → etape_declenche (entité-asso)
AUTH                : utilisateur (is_admin BOOLEAN)
RUNTIME             : session → evenement_session · action_executee
TÉLÉMÉTRIE          : mesure_capteur

SUPPRESSIONS Core Edition (8 tables) :
  scenario_version · equipe · equipe_utilisateur · session_utilisateur
  demande_rejoindre_session · log_rebond · role · utilisateur_role
```

---

## PARTIE 2 — Domoticz / IoT / Z-Wave

### Ce qu'il faut savoir définir

| Terme | Définition à connaître |
|---|---|
| **Z-Wave** | Protocole radio basse fréquence (868 MHz Europe). Réseau maillé. Chaque device = un nœud (Node). Contrôleur = Z-Stick USB. |
| **Domoticz** | Logiciel domotique open source qui gère le réseau Z-Wave. Interface les devices physiques avec une API HTTP. |
| **idx** | Identifiant unique d'un device dans Domoticz. Un device physique peut avoir plusieurs idx (ex: Fibaro Button → idx 9, 27, 30). |
| **nvalue** | Valeur numérique de l'état du device (entier). Ex: 0=fermé, 255=ouvert, 1=appui. |
| **svalue** | Valeur texte complémentaire. Ex: 'Open', 'Closed', 'tamper', température float. |
| **dzVents** | Framework Lua intégré à Domoticz. Permet de réagir aux changements d'état des devices. |
| **Webhook** | Appel HTTP POST déclenché automatiquement par un événement. Ici : dzVents → handle_event.php. |
| **Node** | Chaque équipement Z-Wave est un nœud du réseau. Identifié par son Node ID. |

---

### Mapping des équipements — à connaître par cœur

| Device | Type | idx | nvalue | Événement DomEscape |
|---|---|---|---|---|
| Fibaro Button — simple | button | 9 | >0 | BUTTON_PRESS |
| Fibaro Button — double | button_double | 30 | >0 | BUTTON_DOUBLE_PRESS |
| Fibaro Button — triple | button_triple | 27 | >0 | BUTTON_TRIPLE_PRESS |
| Capteur de porte | door_sensor | 25 | 0=fermée / 255=ouverte | DOOR_CLOSE / DOOR_OPEN |
| Multisensor — motion | motion_sensor | 7 | 0=pas de mvt / >0=mvt | NO_MOTION / MOTION_DETECTED |
| Multisensor — temp/hum | temp_humidity | 8 | — | (télémétrie seulement) |
| Wall Plug | plug | 13 | — | PLUG_ON / PLUG_OFF |
| LCD PiFace | lcd | NULL | — | LCD_MESSAGE (hors Domoticz) |

---

### Questions type Domoticz avec réponses modèles

**Q1 — Quel est le rôle de dzVents dans DomEscape ?**

> dzVents est le framework de scripting Lua intégré à Domoticz. Dans DomEscape, il joue le rôle de pont entre la couche Z-Wave et le moteur PHP. Quand un device Z-Wave change d'état, Domoticz appelle le script dzVents. Celui-ci filtre les devices surveillés (par nom dans `SENSOR_NAMES` et par idx dans `WATCHED_IDX`), extrait `idx`, `nvalue` et `svalue`, puis envoie un POST HTTP à `handle_event.php` avec un token d'authentification.

**Q2 — Pourquoi un double filtre dans le script dzVents (SENSOR_NAMES + WATCHED_IDX) ?**

> Le filtre `on.devices = SENSOR_NAMES` est obligatoire côté Domoticz : c'est lui qui décide quand appeler le script. Mais les noms de devices peuvent changer ou se dupliquer dans Domoticz. Le second filtre `WATCHED_IDX[device.id]` utilise l'idx — identifiant stable et unique — pour éviter des faux positifs si un device tiers a le même nom. La combinaison des deux garantit que seuls les capteurs DomEscape déclenchent le webhook.

**Q3 — Pourquoi le Fibaro Button a-t-il trois idx différents dans Domoticz ?**

> Domoticz crée un device virtuel distinct pour chaque type d'appui du Fibaro Button FGPB-101. Le simple appui est sur idx 9, le double sur idx 30, le triple sur idx 27. Ce sont trois "devices logiques" dans Domoticz, chacun avec son propre nvalue. EventManager associe chaque idx à un `type_capteur` différent (`button`, `button_double`, `button_triple`) et mappe vers le code événement correspondant (`BUTTON_PRESS`, `BUTTON_DOUBLE_PRESS`, `BUTTON_TRIPLE_PRESS`).

**Q4 — Qu'est-ce que le debounce et pourquoi est-il nécessaire avec Z-Wave ?**

> Le réseau Z-Wave peut émettre plusieurs fois le même signal pour un seul appui physique (rebonds électroniques, retransmissions du protocole). Sans debounce, EventManager recevrait deux webhooks identiques en quelques millisecondes, ce qui validerait deux fois la même étape. DomEscape implémente un debounce de 500ms via fichier tmp : si le même couple (idx, nvalue) est reçu dans la fenêtre de 500ms, le second est ignoré. Le fichier tmp est choisi pour sa rapidité (microsecondes) vs un aller-retour BDD (1-5ms).

**Q5 — Comment DomEscape filtre-t-il les faux positifs du Multisensor ?**

> Le Multisensor (idx 7) peut émettre des événements "tamper" (déplacement du boîtier) avec un svalue contenant la chaîne "tamper" ou "product moved". Ces événements ont le même idx que les détections de mouvement légitimes. Dans `EventManager::mapToCodeEvenement()`, avant de mapper vers `MOTION_DETECTED`, le code teste `strpos(strtolower($svalue), 'tamper')` et retourne `null` si trouvé — l'événement est ignoré sans traitement.

---

## PARTIE 3 — Web

### Ce qu'il faut savoir définir

| Concept | Définition |
|---|---|
| **HTTP** | Protocole client-serveur sans état. Chaque requête est indépendante. |
| **REST** | Style architectural : ressources identifiées par URL, méthodes HTTP (GET/POST/PUT/DELETE), réponses JSON. |
| **Webhook** | Mécanisme push : le serveur appelé (Domoticz via dzVents) notifie proactivement un endpoint HTTP. |
| **Polling** | Mécanisme pull : le client interroge périodiquement le serveur. DomEscape : `session_status.php` toutes les secondes. |
| **Session PHP** | Mécanisme de persistance d'état côté serveur entre requêtes HTTP. Identifiée par un cookie `PHPSESSID`. |
| **Stateless** | Un serveur stateless ne stocke aucun état en mémoire entre requêtes. PHP+MariaDB : l'état est en BDD. |
| **CORS** | Cross-Origin Resource Sharing — politique de sécurité navigateur sur les requêtes cross-domain. |
| **XSS** | Cross-Site Scripting — injection de code JS malveillant dans une page. |
| **CSRF** | Cross-Site Request Forgery — forcer un utilisateur authentifié à exécuter une action non désirée. |
| **bcrypt** | Algorithme de hash adaptatif (coût configurable). Résistant aux attaques bruteforce. Utilisé pour les mots de passe. |
| **Token d'auth** | Secret partagé entre Domoticz et PHP. Vérifié à chaque webhook. Empêche les appels non autorisés à handle_event.php. |

---

### Architecture web de DomEscape

```
PAGES PUBLIQUES (auth requise)
  /public/connexion.php    → formulaire POST → Auth::login() → session PHP
  /public/inscription.php  → formulaire POST → UserRepository::create()
  /public/index.php        → tableau de bord → RoleGuard::requireLogin()
  /public/player.php       → interface joueur → polling session_status.php
  /public/gamemaster.php   → supervision GM  → RoleGuard::requireAdmin()
  /public/deconnexion.php  → session_destroy()

APIs JSON (appelées en AJAX ou webhook)
  /api/handle_event.php    → POST webhook Domoticz → EventManager → GameEngine
  /api/session_status.php  → GET → état session pour polling joueur/GM
  /api/start_game.php      → POST → GameEngine::startSession()
  /api/send_hint.php       → POST → ActionManager (LCD_MESSAGE, on_hint)
  /api/reset_game.php      → POST → GameEngine::resetActiveSession()
  /api/abandon_game.php    → POST → statut='abandonnee'

ADMIN (admin uniquement)
  /admin/scenarios.php      → liste des scénarios
  /admin/scenario_edit.php  → création/modification
  /admin/dashboard.php      → tableau de bord admin
```

---

### Sécurité web — ce qu'il faut justifier

**Authentification du webhook**
```php
// handle_event.php
$token = $_POST['token'] ?? '';
if ($token !== WEBHOOK_TOKEN) {
    http_response_code(401);
    exit;
}
```
> Le token est stocké dans `config/secrets.php` (absent du Git). Il doit correspondre à `WEBHOOK_TOKEN` dans `dzvents/domescape_webhook.lua`. Sans ce token, n'importe qui pourrait déclencher des événements sur le moteur.

**Contrôle d'accès par rôle**
```php
// Exemple RoleGuard
RoleGuard::requireAdmin(); // redirige si !is_admin
RoleGuard::requireLogin(); // redirige si !connecté
```
> `is_admin BOOLEAN` sur `utilisateur` remplace le RBAC (tables `role` + `utilisateur_role` supprimées en Core Edition). Suffisant pour deux niveaux d'accès.

**Mots de passe**
```php
// Création
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Vérification
if (!password_verify($input, $hash)) { /* échec */ }
```
> bcrypt avec coût 12 : chaque vérification prend ~300ms côté serveur, rendant les attaques bruteforce prohibitivement lentes.

---

### Questions type Web avec réponses modèles

**Q1 — Pourquoi DomEscape utilise-t-il du polling et non des WebSockets ?**

> Le polling envoie une requête GET à `session_status.php` toutes les secondes. C'est simple à implémenter en PHP pur (pas de serveur persistant nécessaire), compatible avec n'importe quel serveur HTTP (Apache sur le Pi), et suffisant pour 1 salle et 1 à 5 clients. WebSockets auraient nécessité une bibliothèque comme Ratchet (PHP) ou un processus Node.js persistant — complexité opérationnelle injustifiée pour ce périmètre. La latence d'une seconde est acceptable pour une expérience de jeu.

**Q2 — Qu'est-ce qu'un serveur stateless ? DomEscape l'est-il ?**

> Un serveur stateless ne conserve aucun état en mémoire entre deux requêtes HTTP. DomEscape est stateless : PHP ne stocke rien en mémoire concernant l'état du jeu. À chaque appel au webhook, GameEngine relit la session active depuis MariaDB, vérifie l'étape courante, effectue les opérations, puis termine. Si Apache redémarre, aucun état de jeu n'est perdu — tout est en BDD. L'authentification utilisateur utilise les sessions PHP (cookie PHPSESSID), mais c'est la couche présentation, pas la logique métier.

**Q3 — Comment protéger une API PHP contre les accès non autorisés ?**

> Trois niveaux dans DomEscape :
> 1. **Webhook** : token secret partagé (WEBHOOK_TOKEN), vérifié en tête de `handle_event.php` → HTTP 401 si invalide.
> 2. **Pages utilisateur** : `RoleGuard::requireLogin()` vérifie la session PHP. Redirection vers connexion.php si absent.
> 3. **Pages admin** : `RoleGuard::requireAdmin()` vérifie `is_admin = 1` en BDD. Redirection si joueur simple.

---

## PARTIE 4 — Gestion de projet

### Ce qu'il faut savoir justifier

#### Choix techniques — réponses courtes

| Question | Réponse |
|---|---|
| Pourquoi PHP ? | Disponible nativement sur Apache/Pi. Pas de runtime supplémentaire. Compatible MariaDB via PDO. |
| Pourquoi MariaDB ? | Fork MySQL, stable, léger sur Pi. Transactions InnoDB. Relationnel adapté à la logique métier. |
| Pourquoi Domoticz ? | Supporte Z-Wave nativement. dzVents intégré. Pas de driver custom à écrire. |
| Pourquoi Raspberry Pi ? | Faible consommation, embarqué, Linux complet, GPIO, USB pour Z-Stick. |
| Pourquoi séparer EventManager / GameEngine / ActionManager ? | Principe de responsabilité unique : chaque classe a une raison de changer. Testabilité, maintenabilité. |
| Pourquoi Core Edition (suppression 8 tables) ? | Périmètre mono-salle. Complexité non justifiée. Moteur plus lisible, testable, défendable. |
| Pourquoi pas de tests automatisés ? | Matériel réel difficile à simuler sans mocks. Simulation via BDD et Domoticz manuel. |
| Pourquoi `is_admin BOOLEAN` plutôt que RBAC ? | Deux états suffisent (admin/joueur). Une table de plus = une complexité de plus sans valeur ajoutée. |

---

### Limites à assumer (ne pas esquiver)

```
1. Mono-salle : une seule session à la fois → bloquant si plusieurs salles nécessaires.
   Réponse : choix délibéré pour le périmètre de démonstration.
   Extension possible : ajouter id_salle sur session + séparer les sessions par salle.

2. Polling 1s : latence maximale d'une seconde sur l'interface joueur.
   Réponse : acceptable pour l'expérience jeu. WebSockets = complexité non justifiée.

3. Pas de tests automatisés : validation manuelle sur matériel réel.
   Réponse : scripts de simulation SQL possibles, mais le vrai test = hardware réel.

4. Service LCD non redondant : si le service Python plante, le LCD ne répond plus.
   Réponse : ActionManager catch l'erreur, log 'erreur' dans action_executee et continue.
   Le jeu ne s'arrête pas. Pas d'effet sur la progression.

5. Debounce fichier tmp : si le Pi est à court d'inodes ou en lecture seule, ça casse.
   Réponse : cas très improbable. Alternative : BDD ou mémoire partagée (APCu).
```

---

### Définitions architecture à maîtriser

**Couplage faible**
> Des modules sont faiblement couplés quand changer l'un n'oblige pas à modifier l'autre. Dans DomEscape : EventManager peut être modifié (ajouter un nouveau type de capteur) sans toucher à GameEngine. GameEngine peut évoluer sans toucher à ActionManager. Le contrat entre eux = le tableau `$event`.

**Responsabilité unique (SRP)**
> Chaque classe n'a qu'une seule raison de changer.
> - EventManager : raison de changer = nouveau type de capteur ou nouveau mapping.
> - GameEngine : raison de changer = nouvelle règle de validation ou de scoring.
> - ActionManager : raison de changer = nouveau type d'action ou nouveau hardware.

**Architecture événementielle**
> Le système réagit à des événements plutôt que de poller l'état du monde. Un appui bouton = un événement → le moteur décide. Opposé à un polling sur les capteurs toutes les X secondes. Avantages : réactivité immédiate, traçabilité naturelle (chaque événement est logué), découplage entre émetteur et traiteur.

**Architecture pilotée par la base de données (data-driven)**
> La logique métier (quelles étapes, quels capteurs, quelles actions) est en BDD, pas dans le code. Ajouter un scénario = INSERT SQL. Modifier un message LCD = UPDATE SQL. Le moteur (GameEngine + ActionManager) ne change pas. Avantage : évolutivité sans déploiement. Inconvénient : erreurs de config BDD = comportement inattendu (pas d'erreur de compilation).

---

### Questions type Gestion avec réponses modèles

**Q1 — Quel est l'apport principal de l'architecture événementielle dans DomEscape ?**

> L'architecture événementielle permet au système de réagir immédiatement à chaque interaction physique sans polling. Quand le joueur appuie sur le bouton, l'événement remonte en quelques millisecondes (Z-Wave → Domoticz → dzVents → PHP). La traçabilité est naturelle : chaque événement est enregistré dans `evenement_session` avec son horodatage, son capteur et si c'était la bonne action. Le découplage entre émetteur (capteur) et traiteur (GameEngine) permet d'ajouter un nouveau capteur sans modifier le moteur.

**Q2 — Comment ajouter un nouveau scénario sans modifier le code ?**

> Il suffit d'insérer des données en BDD : un `INSERT INTO scenario`, des `INSERT INTO etape`, des `INSERT INTO etape_attend` (capteur + type d'événement attendu par étape) et des `INSERT INTO etape_declenche` (actions à déclencher). Le moteur PHP lit ces tables à l'exécution — il est générique. C'est le principe data-driven : la logique est dans les données, pas dans le code.

**Q3 — Pourquoi avoir choisi de supprimer les tables RBAC et multi-sites ?**

> Le projet est déployé sur une salle unique avec deux rôles (admin et joueur). Une table `role` + `utilisateur_role` n'apporte rien quand il y a deux états binaires — un champ `is_admin BOOLEAN` est plus simple, plus lisible, et sans jointure supplémentaire à chaque vérification d'accès. Les tables multi-sites (`site`, `salle`, `scenario_version`) étaient préparées pour une extension future non requise par le périmètre. Les supprimer réduit la complexité sans perte fonctionnelle.

**Q4 — Quelles sont les limites du système actuel et comment les lever ?**

> **Mono-salle** : une seule session à la fois. Extension : ajouter `id_salle` sur `session` et filtrer par salle. **Polling** : latence de 1 seconde. Extension : WebSockets avec Ratchet PHP. **Pas de tests auto** : couverture manuelle uniquement. Extension : script de simulation qui insère des événements fictifs en BDD et vérifie les transitions. **LCD non redondant** : service Python à point unique de défaillance. Extension : health-check périodique + alerte Game Master.

---

## Planning de révision — 4 × 30 minutes

| Session | Contenu | Exercice |
|---|---|---|
| S1 | Écrire de mémoire les 4 requêtes SQL critiques (session active, étape suivante, etape_attend, actions) | Sur papier, sans regarder |
| S2 | Expliquer le pipeline en 10 lignes écrites, précises | Rédiger sans aide |
| S3 | Répondre par écrit à 5 questions tirées aléatoirement dans les 4 parties | Timing : 6 min/question |
| S4 | Dessiner le schéma des 13 tables avec les FK principales | Sur papier, de mémoire |

---

## Antisèche finale — mots-clés à placer dans les réponses

```
PHP/SQL     → prepare · execute · beginTransaction · FOR UPDATE · rollBack · FETCH_ASSOC
              injection SQL · paramètre lié · intégrité référentielle · ON DELETE CASCADE

Domoticz    → idx · nvalue · svalue · dzVents · webhook · debounce · WATCHED_IDX
              Node Z-Wave · Z-Stick · device Domoticz · tamper filtré

Web         → stateless · polling · session PHP · bcrypt · token d'auth · RoleGuard
              HTTP 401 · JSON · AJAX · séparation présentation/métier

Gestion     → responsabilité unique · couplage faible · data-driven · architecture événementielle
              Core Edition · mono-salle · scalabilité · justification des suppressions
```

---

*DomEscape — Contrôle sur table · 2h · MIAGE 2025-2026*
