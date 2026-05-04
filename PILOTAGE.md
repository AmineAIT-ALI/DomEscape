# PILOTAGE — DomEscape
> Mise à jour : 2026-04-19 · Livraison vidéo + Pi : **3 juin 2026** (J-45)

---

## 1. Planning jusqu'à la livraison

### Complété ✅
| Date | Jalon |
|------|-------|
| 2026-01 | Schéma BDD v1 — 12 tables |
| 2026-02 | GameEngine.php — automate d'états |
| 2026-03-21 | BDD gelée — structure finale |
| 2026-03-22 | Audit qualité résolu |
| 2026-04-19 | Refonte frontend — design system, CSRF, Bootstrap supprimé |
| 2026-04-19 | **Pi déployé, scénario fonctionnel, capteurs Z-Wave OK** |

### S1 — 19–26 avril : Stabilisation & rapport
| Tâche | Statut |
|-------|--------|
| Pi mis à jour (rsync, secrets.php protégé) | ✅ |
| Backup SD card Pi créé et testé (boot depuis la copie) | ⬜ |
| Passer la checklist §4 (tests fonctionnels complets) | ⬜ |
| Finaliser RAPPORT.md — sections manquantes, chiffres réels (~10 000 lignes PHP) | ⬜ |

### S2 — 27 avril–10 mai : Script & préparation vidéo
| Tâche | Statut |
|-------|--------|
| Écrire le script voix off (séquence par séquence) | ⬜ |
| Préparer environnement tournage (résolution 1080p, micro testé) | ⬜ |
| Répétition à blanc — chrono, transitions fluides | ⬜ |
| Préparer données démo propres sur Pi (comptes test, scénario Lab 01 reset) | ⬜ |

### S3 — 11–20 mai : Tournage
| Tâche | Statut |
|-------|--------|
| Séquence 1 — Intro + architecture (`website/architecture.html`) | ⬜ |
| Séquence 2 — Démo admin (dashboard, utilisateurs, scénarios) | ⬜ |
| Séquence 3 — Démo jeu (gamemaster, capteurs Fibaro, victoire) | ⬜ |
| Séquence 4 — Bilan technique (chiffres, sécurité, design system) | ⬜ |
| Montage brut + vérification qualité audio/vidéo | ⬜ |

### S4 — 21–28 mai : Montage final
| Tâche | Statut |
|-------|--------|
| Montage final (découpes, transitions, titres) | ⬜ |
| Export MP4 HD — version livrable | ⬜ |
| Relecture RAPPORT.md — version finale | ⬜ |
| Pi propre pour livraison (config clean, démarrage auto Apache + Domoticz) | ⬜ |

### S5 — 29 mai–3 juin : Buffer & livraison
| Tâche | Statut |
|-------|--------|
| Corrections de dernière minute | ⬜ |
| **Livraison vidéo** | ⬜ |
| **Livraison Pi** (3 juin) | ⬜ |

---

## 2. Risques

| ID | Risque | Score | Mitigation |
|----|--------|-------|------------|
| R01 | Pi tombe en démo | 🔴 | Snapshot SD card de backup |
| R02 | Domoticz inaccessible | 🟠 | Mode dégradé — jeu continue sans capteurs |
| R03 | Capteur Z-Wave muet | 🟡 | Valeurs simulées en fallback (`dev/simulate.php`) |
| R04 | Wi-Fi défaillant sur site | 🟠 | Câble Ethernet de backup |
| R05 | Jury demande feature hors scope | 🟢 | "Scope défini dès le départ, documenté" |
| R06 | `secrets.php` exposé | 🔴 | Exclu de git + exclu du rsync |

**Checklist risques avant J-1**
- [ ] Backup SD card créé et testé
- [ ] Câble Ethernet dans le sac
- [ ] `secrets.php` absent du repo git (`git status` vérifié)

---

## 3. Déploiement

### Dev → Apache local
```bash
rsync -av --delete \
  /Users/amineaitali/Desktop/DomEscape/domescape/ \
  /Library/WebServer/Documents/domescape/ \
  --exclude='.git' \
  --exclude='config/secrets.php'
```

### Dev → Raspberry Pi (prod)
```bash
scp -r /Users/amineaitali/Desktop/DomEscape/domescape/ pi@192.168.4.1:~/
# Puis sur le Pi :
sudo mv ~/domescape /var/www/html/
```

**Checklist avant SCP vers Pi**
- [ ] `config/secrets.php` non inclus
- [ ] `domescape/sql/schema.sql` à jour
- [ ] Tests locaux OK (login, gamemaster, admin)
- [ ] Domoticz accessible sur `192.168.4.1:8080`

---

## 4. Tests fonctionnels

### Auth
- [ ] Login valide → redirigé selon rôle
- [ ] Mauvais mot de passe → message d'erreur
- [ ] Accès page admin sans rôle → 403
- [ ] Déconnexion → session détruite

### Admin — Utilisateurs
- [ ] Liste avec rôles et statuts
- [ ] Modifier nom → sauvegardé
- [ ] Désactiver compte → login bloqué
- [ ] Admin ne peut pas se désactiver lui-même

### Admin — Scénarios
- [ ] Créer scénario + version → apparaît dans la liste
- [ ] Activer / désactiver version
- [ ] Supprimer version (avec confirmation)

### Session de jeu
- [ ] Démarrer → état `en_cours`, timer lancé
- [ ] Avancer étape → progression mise à jour
- [ ] Victoire → état `gagnee`, score calculé
- [ ] Défaite → état `perdue`

### Capteurs
- [ ] Données Domoticz affichées sur gamemaster
- [ ] Si Domoticz down → pas de crash, mode dégradé visible

### Sécurité
- [ ] POST sans CSRF token → 403
- [ ] POST avec token valide → OK
- [ ] `dev/simulate.php` depuis réseau extérieur → 403

---

## 5. Vidéo de présentation

**Livraison :** 3 juin 2026 · **Format :** vidéo enregistrée (screen + voix off)

### Structure vidéo (~8-10 min)
| Séquence | Durée | Contenu |
|----------|-------|---------|
| Intro | 1 min | Contexte escape room connectée, stack, objectif |
| Architecture | 2 min | `website/architecture.html` — flux PHP→MySQL→Domoticz→Z-Wave |
| Démo admin | 2 min | Dashboard, utilisateurs, scénarios Lab 01 |
| Démo jeu | 3 min | Démarrer session, gamemaster capteurs Fibaro, victoire |
| Bilan | 1 min | ~10 000 lignes PHP, sécurité CSRF/bcrypt, design system custom |
| Conclusion | 30 s | Ce qui a été appris, pistes d'évolution |

### Script démo jeu (cœur de la vidéo)
1. Login superviseur → démarrer session Lab 01
2. Gamemaster : montrer capteurs temps réel (température/humidité Fibaro)
3. Avancer 3 étapes en commentant le fonctionnement
4. Terminer en victoire → score affiché
5. Login admin → historique + stats globales

### Checklist avant tournage
- [ ] Pi déployé, Domoticz actif, capteurs Z-Wave répondent
- [ ] Comptes démo créés (admin, superviseur, participant)
- [ ] Scénario Lab 01 configuré avec version active
- [ ] Résolution écran fixée (1920×1080 min)
- [ ] Micro testé (pas d'écho, niveau correct)
- [ ] Script voix off écrit et répété

### Checklist livraison Pi (3 juin)
- [ ] Code à jour (`sql/schema.sql` synchronisé avec la DB)
- [ ] Données de démo présentes et fonctionnelles
- [ ] `config/secrets.php` présent sur le Pi (mais hors git)
- [ ] Domoticz configuré et démarrant automatiquement au boot
- [ ] Apache démarrant automatiquement au boot
- [ ] Backup SD card créé (garder une copie)

---

## 6. Décisions actées (ne pas revenir dessus)

| ID | Date | Décision |
|----|------|----------|
| DEC-001 | 2026-01 | PHP natif sans framework (contrainte pédagogique) |
| DEC-002 | 2026-01 | MySQL 8 sur Raspberry Pi 5 |
| DEC-003 | 2026-02 | Domoticz comme middleware Z-Wave (API REST curl) |
| DEC-004 | 2026-03-21 | **BDD gelée** — 12 tables, ne plus modifier la structure |
| DEC-005 | 2026-04-19 | Bootstrap supprimé — `components.css` unique source de styles |
| DEC-006 | 2026-04-19 | CSRF obligatoire sur tout formulaire POST (`Csrf::verify()`) |
