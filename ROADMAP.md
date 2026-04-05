# DomEscape — Roadmap Finale Objectif 20/20

## Contexte

Le projet DomEscape a atteint une étape critique :

- la chaîne complète fonctionne en conditions réelles
- le moteur est stable (EventManager / GameEngine / ActionManager)
- la base de données est cohérente (20 tables)
- plusieurs sessions ont été exécutées avec succès (750/750)

Nous ne sommes plus en phase de construction du cœur, mais en phase de **consolidation + amélioration + mise en valeur**.

---

## Objectif

Produire une version finale :

- **robuste**
- **cohérente**
- **démontrable sans risque**
- **visuellement impactante**
- **techniquement défendable**

Objectif clair : **20/20**

---

## Règle fondamentale

> Ne jamais casser ce qui fonctionne.

Le moteur actuel est validé sur hardware réel.
Toute évolution doit respecter cette contrainte.

---

## Périmètre technique gelé (NE PAS MODIFIER)

- `GameEngine::process()`
- `EventManager::mapToCodeEvenement()`
- script dzVents `domescape_webhook.lua`
- logique transactionnelle SQL (`SELECT FOR UPDATE`)

---

## Phase 1 — Finalisation Core ✅ TERMINÉ

| Item | Statut |
|---|---|
| Button Double (idx 30) dans schema.sql | ✅ FAIT |
| etape_attend étape 4 corrigée (capteur 4) | ✅ FAIT |
| PK surrogate etape_declenche | ✅ FAIT |
| LCD + prise connectée dans le scénario | ✅ FAIT |
| Bouton simple + double + porte + mouvement | ✅ FAIT |
| `capteur.id_salle` NOT NULL | ✅ FAIT |
| `actionneur.id_salle` NOT NULL | ✅ FAIT |
| `session.id_salle` NOT NULL | ✅ FAIT |
| Migration NOT NULL appliquée sur Pi | ✅ FAIT |
| `GameEngine::startSession()` — paramètre id_salle | ✅ FAIT |
| Seed site + salle dans schema.sql | ✅ FAIT |

---

## Phase 2 — Expérience de jeu ✅ TERMINÉ

Messages LCD narratifs ≤ 16 chars (écran 2×16) :

| Étape | on_enter | on_success | on_failure |
|---|---|---|---|
| 1 — Boot Sequence | En veille... | Niveau 1 OK ! | Invalide ! |
| 2 — Secret Door | Zone restreinte | Acces autorise! | Intrus detecte! |
| 3 — Motion Scan | Scan en attente | Scan valide ! | Hors perimetre! |
| 4 — Final Code | Double appui ! | ESCAPE SUCCESS! | Code invalide ! |

Service LCD : `lcd_service.py` — Python 3 stdlib (`http.server`), zéro dépendance externe.

---

## Phase 3 — UI / UX ✅ TERMINÉ

- `player.php` — dark mode, timer, progression, polling 2s
- `gamemaster.php` — timeline événements + actions, contrôles superviseur, polling 2s
- Design : `#0d1117` / vert `#00ff88` / CSS natif, pas de librairie lourde

---

## Phase 4 — Observabilité ✅ TERMINÉ

- `historique.php` — chronologie complète d'une session (événements + actions, par étape)
- `stats.php` — KPIs globaux, taux de victoire, difficulté par étape, top sessions
- `gamemaster_status.php` — API temps réel pour gamemaster.php

---

## Phase 5 — Intégration scenario_version ✅ TERMINÉ

Chaque session exécute une version figée du scénario.

| Item | Statut |
|---|---|
| `etape.id_scenario_version` + FK | ✅ FAIT |
| `session.id_scenario_version` + FK | ✅ FAIT |
| `start_game.php` — résolution version active | ✅ FAIT |
| `GameEngine::startSession()` — charge étapes par version | ✅ FAIT |
| `GameEngine::getEtapeSuivante()` — navigation par version | ✅ FAIT |
| Fallback `id_scenario` si version NULL | ✅ FAIT |
| Migration Pi appliquée + backfill 14 sessions | ✅ FAIT |
| Session validée sur hardware réel (session 16 — 750/750 — v1.01) | ✅ FAIT |

Argument oral : *"Chaque session est liée à une version figée du scénario au moment de son démarrage — la reproductibilité et la traçabilité sont garanties même si le scénario évolue entre deux parties."*

---

## Phase 6 — Vidéo de démonstration ❌ À FAIRE

Objectif : convaincre immédiatement, sans ambiguïté

### Structure (< 3 minutes)

1. Plan large : le Raspberry Pi + les capteurs visibles
2. Lancement d'une session depuis l'interface
3. Déroulé des 4 étapes en conditions réelles :
   - Appui bouton → LCD réagit
   - Ouverture porte → LCD réagit
   - Passage devant le capteur → prise s'allume
   - Double appui → LCD "ESCAPE SUCCESS!" + victoire affichée
4. Plan sur l'interface game master en temps réel
5. Affichage de la timeline post-session (historique.php)

> La vidéo doit prouver que le système fonctionne réellement — aucune simulation.

---

## Phase 7 — Rapport final ✅ TERMINÉ

RAPPORT.md aligné avec l'implémentation finale :

- Stack technique : Python 3 stdlib (pas Flask)
- DD capteur/actionneur/session : id_salle NOT NULL
- MLD : annotations [NULL Phase 1] supprimées
- Exemples : id_salle, id_etape_declenche, messages 16 chars, tables site + salle
- Pages stats.php + historique.php documentées
- API gamemaster_status.php documentée

---

## Nettoyage ✅ TERMINÉ (audit 2026-04-05)

| Fichier supprimé | Raison |
|---|---|
| `domescape/public/debug.html` | Outil debug accessible sans auth — risque soutenance |
| `domescape/sql/auth_extension.sql` | Explicitement déprécié — doublon de schema.sql |

---

## Critères de réussite finale

- [x] Système fonctionne sans bug en démo
- [x] Tous les capteurs et actionneurs utilisés et visibles
- [x] Interface player.php immersive
- [x] Interface gamemaster.php exploitable en temps réel
- [x] Timeline post-session lisible
- [x] scenario_version intégré et validé sur hardware
- [ ] Vidéo convaincante (hardware réel, pas de simulation)
- [x] RAPPORT.md cohérent avec l'implémentation finale

---

## Avant gel final — 3 points à vérifier sur le Pi

1. `logs/` writable : `chmod 775 domescape/logs`
2. `lcd_service` actif au démarrage : `sudo systemctl enable domescape-lcd`
3. Test complet : démarrage à froid → session 4 étapes → victoire
