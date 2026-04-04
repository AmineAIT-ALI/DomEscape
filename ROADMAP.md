# DomEscape — Roadmap Finale Objectif 20/20

## Contexte

Le projet DomEscape a atteint une étape critique :

- la chaîne complète fonctionne en conditions réelles
- le moteur est stable (EventManager / GameEngine / ActionManager)
- la base de données est cohérente (20 tables)
- plusieurs sessions ont été exécutées avec succès (750/750)

Nous ne sommes plus en phase de construction du cœur, mais en phase de **consolidation + amélioration + mise en valeur**.

---

## 🎯 Objectif

Produire une version finale :

- **robuste**
- **cohérente**
- **démontrable sans risque**
- **visuellement impactante**
- **techniquement défendable**

👉 Objectif clair : **20/20**

---

## ⚠️ Règle fondamentale

> Ne jamais casser ce qui fonctionne.

Le moteur actuel est validé sur hardware réel.
Toute évolution doit respecter cette contrainte.

---

## 🧱 Périmètre technique gelé (NE PAS MODIFIER)

- `GameEngine::process()`
- `EventManager::mapToCodeEvenement()`
- script dzVents `domescape_webhook.lua`
- logique transactionnelle SQL (`SELECT FOR UPDATE`)

---

## ✅ Phase 1 — Finalisation Core

### État actuel (au 2026-04-04)

| Item | Statut |
|---|---|
| Button Double (idx 30) dans schema.sql | ✅ FAIT |
| etape_attend étape 4 corrigée (capteur 4) | ✅ FAIT |
| PK surrogate etape_declenche | ✅ FAIT |
| LCD + prise connectée dans le scénario | ✅ DÉJÀ EN PLACE |
| Bouton simple + double + porte + mouvement | ✅ DÉJÀ EN PLACE |

### À faire

**Contraintes physiques `id_salle` NOT NULL**

Rendre NOT NULL dans `schema.sql` (fresh install uniquement) :
- `capteur.id_salle`
- `actionneur.id_salle`
- `session.id_salle`

> ⚠️ NE PAS migrer sur le Pi existant — les lignes ont des valeurs NULL.
> Cette contrainte s'applique uniquement à un déploiement depuis schema.sql.
> Le Pi de production continue de fonctionner tel quel.

Pour activer NOT NULL dans schema.sql, il faut :
1. Ajouter un site + une salle dans le seed data
2. Mettre à jour les INSERT capteur/actionneur avec l'id_salle correspondant
3. Adapter `GameEngine::startSession()` pour passer un `id_salle`

---

## 🎮 Phase 2 — Expérience de jeu

Objectif : rendre la démo **immersive**

Messages LCD narratifs (pas techniques) :

| ❌ Avant | ✅ Après |
|---|---|
| "Étape validée" | "Accès autorisé. Porte déverrouillée." |
| "Action incorrecte" | "Code erroné. Réessayez." |
| "Système en ligne." | "Système de sécurité activé. Bonne chance." |

→ Modifier uniquement les `valeur_action` dans le seed `etape_declenche` — aucun risque code.

---

## 🎨 Phase 3 — UI / UX (impact jury maximal)

Objectif : interface propre, lisible, impactante

### Design système

- Dark mode (`#0d1117` background)
- Couleur principale : vert `#00ff88`
- Typographie : monospace ou sans-serif moderne
- Aucune librairie lourde — CSS natif

### Pages prioritaires

#### 1. `player.php` — Vue joueur
- Étape actuelle + numérotation (ex : "Étape 2 / 4")
- Message narratif de l'étape
- Score + erreurs en temps réel
- Polling toutes les 2s (existant, à conserver)

#### 2. `gamemaster.php` — Vue game master
- Session active : joueur, étape courante, score, temps écoulé
- Timeline des derniers événements (dernières 10 lignes)
- Boutons : Indice / Reset session
- Statut du système (dernière activité capteurs)

#### 3. `dashboard.php` — Admin
- Statistiques globales (sessions jouées, taux de victoire, meilleur temps)
- Accès rapide aux CRUD
- Lisible, pas surchargé

---

## 📊 Phase 4 — Observabilité

Objectif : montrer la maîtrise technique au jury

### Page `historique.php`

Timeline lisible des événements d'une session :

```
[12:01:04] BUTTON_PRESS     — Button       — ✅ valide
[12:01:07] DOOR_OPEN        — Porte        — ✅ valide
[12:01:07] PLUG_ON          — Wall Plug    — ✅ exécuté
[12:01:09] MOTION_DETECTED  — Multisensor  — ✅ valide
[12:01:11] BUTTON_DOUBLE    — Button Double— ✅ valide → VICTOIRE
```

### Page `stats.php`

- Sessions par scénario
- Taux de victoire
- Temps moyen
- Distribution des erreurs par étape

→ Données déjà en base (`evenement_session`, `action_executee`, `session`) — juste du SQL + affichage.

---

## 🎬 Phase 5 — Vidéo de démonstration

Objectif : convaincre immédiatement, sans ambiguïté

### Structure (< 3 minutes)

1. Plan large : le Raspberry Pi + les capteurs visibles
2. Lancement d'une session depuis l'interface
3. Déroulé des 4 étapes en conditions réelles :
   - Appui bouton → LCD réagit
   - Ouverture porte → LCD réagit
   - Passage devant le capteur → prise s'allume
   - Double appui → LCD "ESCAPE SUCCESSFUL" + victoire affichée
4. Plan sur l'interface game master en temps réel
5. Affichage de la timeline post-session

> La vidéo doit prouver que le système fonctionne réellement — aucune simulation.

---

## 📄 Phase 6 — Rapport final

Points à mettre en avant :

- moteur **stateless** (justification + avantages)
- architecture **événementielle** (dzVents → PHP → BDD)
- BDD comme **moteur de décision** (configuration 100% data-driven)
- séparation des couches (EventManager / GameEngine / ActionManager)
- validation sur **hardware réel** (résultats chiffrés : 3 sessions, 750/750, 42s)
- extensibilité : `scenario_version`, multi-sites, multi-salles (prêts mais Phase 2)

---

## 🚫 Hors périmètre

- migration vers `scenario_version` dans le moteur
- multi-conditions par étape dans GameEngine
- refonte backend
- nouvelles abstractions

---

## 🧠 Philosophie

> DomEscape n'est pas un simple escape game.

C'est :
- un **moteur de scénarios physiques interactifs**
- piloté par **événements**
- configurable via **base de données**
- connecté à un **environnement réel**

---

## 📅 Ordre recommandé (2 mois)

| Semaine | Phase | Priorité |
|---|---|---|
| S1-S2 | Phase 1 — NOT NULL id_salle dans schema.sql | Haute |
| S2-S3 | Phase 3 — player.php + gamemaster.php | Très haute |
| S3-S4 | Phase 4 — historique.php + stats.php | Haute |
| S4 | Phase 2 — messages LCD narratifs | Moyenne |
| S5 | Phase 3 — dashboard admin | Moyenne |
| S6-S7 | Phase 5 — vidéo de démo | Haute |
| S8 | Phase 6 — rapport final | Très haute |

---

## ✅ Critères de réussite finale

- [ ] Système fonctionne sans bug en démo
- [ ] Tous les capteurs et actionneurs utilisés et visibles
- [ ] Interface player.php immersive
- [ ] Interface gamemaster.php exploitable en temps réel
- [ ] Timeline post-session lisible
- [ ] Vidéo convaincante (hardware réel, pas de simulation)
- [ ] RAPPORT.md cohérent avec l'implémentation finale
