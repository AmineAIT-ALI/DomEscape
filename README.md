# DomEscape

Plateforme de pilotage d'un escape game domotique physique.
Des joueurs interagissent avec de vrais capteurs Z-Wave pour progresser dans un scénario. Un Raspberry Pi orchestre les événements via Domoticz, et DomEscape gère la logique de jeu, les feedbacks physiques (LCD, lampes, prises) et le suivi des sessions.

---

## Architecture

```
Capteurs Z-Wave (Fibaro, Aeotec)
        ↓
   Domoticz + dzVents (Lua)          ← Raspberry Pi
        ↓  webhook HTTP
   DomEscape (PHP + MySQL)           ← Raspberry Pi / Serveur
        ↓
   Interfaces web (joueur / game master / admin)
```

### Stack technique

| Couche | Technologie | Version |
|---|---|---|
| Backend | PHP | 8.4 |
| Base de données | MariaDB / MySQL | 11+ |
| Serveur web | Apache2 | 2.4 |
| Domotique | Domoticz + dzVents | — |
| Hardware | Z-Wave (Fibaro, Aeotec) | — |
| Frontend | Bootstrap + JS vanilla | 5.3 |

---

## Structure du projet

```
DomEscape/
├── domescape/
│   ├── admin/          ← Administration (utilisateurs, scénarios, étapes)
│   ├── api/            ← Endpoints HTTP (webhook, sessions, indices, healthcheck)
│   ├── config/         ← Configuration BDD, app, secrets
│   ├── core/           ← Moteur de jeu, auth, gestion des événements
│   ├── dev/            ← Simulateur (sans hardware)
│   ├── domoticz/       ← Client HTTP Domoticz
│   ├── dzvents/        ← Script Lua pour Domoticz
│   ├── partials/       ← Composants HTML partagés
│   ├── public/         ← Pages accessibles (joueur, game master, auth)
│   ├── scripts/        ← Service LCD Python + script deploy.sh
│   ├── sql/            ← Schéma BDD + migration auth
│   └── website/        ← Documentation du projet (HTML statique)
└── sync.sh             ← Synchronisation Desktop → Apache (dev macOS)
```

---

## Installation rapide (Ubuntu / Raspberry Pi OS)

```bash
git clone https://github.com/AmineAIT-ALI/DomEscape.git
cd DomEscape
sudo bash domescape/scripts/deploy.sh
```

Le script configure automatiquement Apache, MariaDB, PHP, importe le schéma, crée `config/secrets.php` et vérifie la connexion PDO.

---

## Installation manuelle (étape par étape)

### 1. Dépendances

```bash
sudo apt update && sudo apt install -y \
    apache2 mariadb-server \
    php8.4 php8.4-mysql php8.4-mbstring \
    libapache2-mod-php8.4
```

### 2. Déploiement

```bash
sudo cp -R ~/DomEscape/domescape /var/www/html/domescape
sudo chown -R www-data:www-data /var/www/html/domescape
sudo find /var/www/html/domescape -type d -exec chmod 755 {} \;
sudo find /var/www/html/domescape -type f -exec chmod 644 {} \;
```

### 3. Base de données

```bash
sudo mariadb <<EOF
CREATE DATABASE domescape CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'domescape'@'localhost' IDENTIFIED BY 'domescape123';
GRANT ALL PRIVILEGES ON domescape.* TO 'domescape'@'localhost';
FLUSH PRIVILEGES;
EOF

sudo mariadb domescape < ~/DomEscape/domescape/sql/schema.sql
sudo mariadb domescape < ~/DomEscape/domescape/sql/auth_extension.sql
```

### 4. Configuration

Créer `/var/www/html/domescape/config/secrets.php` :

```php
<?php
define('DB_USER', 'domescape');
define('DB_PASS', 'domescape123');
define('WEBHOOK_TOKEN', 'domescape_secret_2025');
```

### 5. Apache

```bash
sudo a2enmod php8.4 rewrite
sudo systemctl restart apache2
```

### 6. Vérification

```bash
curl -s http://localhost/domescape/api/healthcheck.php
```

`"database": {"status": "ok"}` → installation réussie.

---

## Schéma de la base de données

15 tables réparties en 3 domaines :

**Hardware**
- `capteur` — capteurs Z-Wave (idx Domoticz, type, localisation)
- `actionneur` — actionneurs Z-Wave (lampes, prises, LCD)
- `evenement_type` — types d'événements (DOOR_OPEN, BUTTON_PRESS…)
- `action_type` — types d'actions (LAMP_ON, LCD_MESSAGE…)

**Scénario**
- `scenario` — scénarios de jeu
- `etape` — étapes d'un scénario (ordre, points, finale)
- `etape_attend` — événement attendu pour valider une étape
- `etape_declenche` — actions à exécuter à chaque moment (on_enter, on_success…)

**Session & Utilisateurs**
- `joueur` — profil joueur (pseudo, email)
- `session` — session de jeu en cours ou terminée
- `evenement_session` — historique complet des événements capteurs
- `action_executee` — historique des actions physiques déclenchées
- `utilisateur` — comptes utilisateurs (admin, game master, joueur)
- `role` — rôles (admin, gamemaster, joueur)
- `utilisateur_role` — association utilisateur ↔ rôle

---

## Interfaces

| Page | Rôle | Accès |
|---|---|---|
| `/public/connexion.php` | Connexion | Tous |
| `/public/tableau-de-bord.php` | Tableau de bord | Tous |
| `/public/player.php` | Vue joueur en partie | Joueur |
| `/public/gamemaster.php` | Contrôle en direct + envoi d'indices | Game Master |
| `/admin/dashboard.php` | Tableau de bord plateforme | Admin |
| `/admin/scenarios.php` | Gestion des scénarios | Admin |
| `/admin/scenario_edit.php` | Édition scénario + étapes | Admin |
| `/admin/utilisateurs.php` | Gestion des comptes | Admin |
| `/dev/simulate.php` | Simulateur sans hardware | Admin |

---

## APIs

| Endpoint | Méthode | Description |
|---|---|---|
| `/api/handle_event.php` | POST | Webhook Domoticz (événement capteur) |
| `/api/start_game.php` | POST | Démarrer une session |
| `/api/session_status.php` | GET | État temps réel de la session (polling 2s) |
| `/api/send_hint.php` | POST | Envoyer l'indice de l'étape courante (Game Master) |
| `/api/reset_game.php` | POST | Réinitialiser la session (Game Master) |
| `/api/abandon_game.php` | POST | Abandonner une partie |
| `/api/debug_event.php` | POST | Simuler un événement (sans hardware) |
| `/api/healthcheck.php` | GET | État du système (BDD, Domoticz, LCD) |

---

## Déploiement Raspberry Pi

Sur le Raspberry Pi, les étapes sont identiques à l'installation Ubuntu.
Ajustements spécifiques :

1. **`config/app.php`** — vérifier que `DOMOTICZ_URL` pointe vers `http://localhost:8080`
2. **Table `capteur`** — mettre à jour les `domoticz_idx` avec les vrais idx de l'installation Domoticz
3. **`dzvents/domescape_webhook.lua`** — déployer dans `~/domoticz/scripts/dzVents/scripts/`
4. **`scripts/lcd_service.py`** — lancer le service LCD : `python3 lcd_service.py`

---

## Auteur

Amine AIT-ALI — Projet domotique Z-Wave / Escape Game
[github.com/AmineAIT-ALI/DomEscape](https://github.com/AmineAIT-ALI/DomEscape)
