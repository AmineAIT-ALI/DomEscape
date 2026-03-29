#!/bin/bash

# =============================================================
# DomEscape — Script de déploiement
# Usage : sudo bash scripts/deploy.sh
# À lancer depuis la racine du repo cloné
# =============================================================

set -e

DEPLOY_DIR="/var/www/html/domescape"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
SECRETS_FILE="$DEPLOY_DIR/config/secrets.php"

# Couleurs
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[!!]${NC} $1"; }
fail() { echo -e "${RED}[KO]${NC} $1"; exit 1; }

echo ""
echo "  DomEscape — Déploiement"
echo "  ========================"
echo ""

# --- 1. Vérifier root ---
if [ "$EUID" -ne 0 ]; then
    fail "Lance ce script avec sudo : sudo bash scripts/deploy.sh"
fi

# --- 2. Copier les fichiers ---
echo ">>> Copie des fichiers vers $DEPLOY_DIR..."
rm -rf "$DEPLOY_DIR"
cp -r "$PROJECT_DIR" "$DEPLOY_DIR"
ok "Fichiers copiés"

# --- 3. Permissions ---
echo ">>> Permissions..."
chown -R www-data:www-data "$DEPLOY_DIR"
find "$DEPLOY_DIR" -type d -exec chmod 755 {} \;
find "$DEPLOY_DIR" -type f -exec chmod 644 {} \;
ok "Permissions appliquées"

# --- 4. secrets.php ---
echo ">>> Configuration base de données..."
if [ -f "$SECRETS_FILE" ]; then
    warn "config/secrets.php existe déjà — conservé tel quel"
else
    echo ""
    read -rp "    Nom d'utilisateur MariaDB [domescape] : " DB_USER
    DB_USER="${DB_USER:-domescape}"
    read -rsp "    Mot de passe MariaDB : " DB_PASS
    echo ""

    cat > "$SECRETS_FILE" <<PHP
<?php
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
PHP
    chown www-data:www-data "$SECRETS_FILE"
    chmod 640 "$SECRETS_FILE"
    ok "config/secrets.php créé"
fi

# Lire les credentials depuis secrets.php pour la suite
DB_USER=$(grep "DB_USER" "$SECRETS_FILE" | sed "s/.*'\(.*\)'.*/\1/")
DB_PASS=$(grep "DB_PASS" "$SECRETS_FILE" | sed "s/.*'\(.*\)'.*/\1/")

# --- 5. MariaDB : créer user + base + importer schéma ---
echo ">>> Base de données MariaDB..."

mariadb -e "CREATE DATABASE IF NOT EXISTS domescape CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null \
    && ok "Base 'domescape' prête" || warn "Impossible de créer la base (existe peut-être déjà)"

mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null
mariadb -e "GRANT ALL PRIVILEGES ON domescape.* TO '${DB_USER}'@'localhost';" 2>/dev/null
mariadb -e "FLUSH PRIVILEGES;" 2>/dev/null
ok "Utilisateur '${DB_USER}' configuré"

echo ">>> Import du schéma SQL..."
if mariadb domescape < "$DEPLOY_DIR/sql/schema.sql" 2>/dev/null; then
    ok "Schéma importé"
else
    warn "Import schéma échoué (tables existent peut-être déjà)"
fi

if mariadb domescape < "$DEPLOY_DIR/sql/auth_extension.sql" 2>/dev/null; then
    ok "Auth extension importée (utilisateur, role, utilisateur_role)"
else
    warn "Import auth_extension échoué (tables existent peut-être déjà)"
fi

# --- 5b. Créer le dossier logs/ avec les bonnes permissions ---
echo ">>> Dossier logs/..."
mkdir -p "$DEPLOY_DIR/logs"
chown www-data:www-data "$DEPLOY_DIR/logs"
chmod 755 "$DEPLOY_DIR/logs"
ok "logs/ prêt"

# --- 6. Tester la connexion PHP → MariaDB ---
echo ">>> Test connexion PHP..."
php -r "
try {
    new PDO('mysql:host=127.0.0.1;dbname=domescape;charset=utf8mb4', '$DB_USER', '$DB_PASS');
    echo 'ok';
} catch (Exception \$e) {
    echo 'fail:' . \$e->getMessage();
}
" | grep -q "^ok" && ok "Connexion PHP → MariaDB OK" || fail "Connexion PHP → MariaDB échouée. Vérifie les credentials."

# --- 7. Apache ---
echo ">>> Apache..."

# Activer mod_php (détection automatique de version)
PHP_MOD=$(ls /etc/apache2/mods-available/ | grep "^php" | grep "\.load$" | head -1 | sed 's/\.load//')
if [ -n "$PHP_MOD" ]; then
    a2enmod "$PHP_MOD" > /dev/null 2>&1
    ok "Module $PHP_MOD activé"
else
    warn "Module PHP pour Apache non trouvé"
fi

# Vérifier/créer le bloc Directory dans la config Apache
APACHE_CONF="/etc/apache2/sites-enabled/000-default.conf"
if ! grep -q "AllowOverride All" "$APACHE_CONF"; then
    warn "Bloc <Directory> manquant dans Apache — ajout automatique..."
    sed -i "s|</VirtualHost>|    <Directory /var/www/html>\n        AllowOverride All\n        Require all granted\n        Options -Indexes\n    </Directory>\n</VirtualHost>|" "$APACHE_CONF"
    ok "Config Apache mise à jour"
else
    ok "Config Apache OK"
fi

apache2ctl configtest > /dev/null 2>&1 && ok "Syntaxe Apache valide" || fail "Erreur syntaxe Apache"
systemctl reload apache2
ok "Apache rechargé"

# --- 8. Service LCD systemd ---
echo ">>> Service LCD..."
if [ -f /etc/systemd/system/domescape-lcd.service ]; then
    warn "Service domescape-lcd déjà installé — rechargement..."
    systemctl daemon-reload
    systemctl restart domescape-lcd 2>/dev/null || true
else
    cp "$DEPLOY_DIR/scripts/domescape-lcd.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable domescape-lcd
    systemctl start domescape-lcd 2>/dev/null || warn "LCD service non démarré (pifacecad absent ? Normal hors Raspberry Pi)"
fi
ok "Service LCD configuré"

# --- 9. Résumé ---
echo ""
echo "  ================================="
ok "Déploiement terminé !"
echo ""
echo "  Accès    : http://$(hostname -I | awk '{print $1}')/domescape/public/connexion.php"
echo "  Admin    : admin@domescape.local / Admin1234!"
echo "  IMPORTANT: Changer le mot de passe admin après connexion !"
echo "  LCD      : systemctl status domescape-lcd"
echo "  Logs     : tail -f $DEPLOY_DIR/logs/debug.log"
echo "  ================================="
echo ""
