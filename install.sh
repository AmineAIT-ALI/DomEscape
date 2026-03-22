#!/bin/bash

# =============================================================
# install.sh — Installation automatique de DomEscape
# Compatible : Ubuntu 24.04 / 25.04 (Debian-based)
# Usage      : sudo bash install.sh
# =============================================================

set -e

# --- Variables -----------------------------------------------
DB_NAME="domescape"
DB_USER="domescape"
DB_PASS="domescape123"
WEBHOOK_TOKEN="domescape_secret_2025"
WEBROOT="/var/www/html/domescape"
PROJECT_DIR="$(cd "$(dirname "$0")/domescape" && pwd)"

# --- Couleurs ------------------------------------------------
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- Vérification root ---------------------------------------
if [ "$EUID" -ne 0 ]; then
    error "Ce script doit être exécuté en tant que root : sudo bash install.sh"
fi

info "=== Installation de DomEscape ==="

# --- 1. Dépendances ------------------------------------------
info "Installation des dépendances..."
apt update -q
apt install -y -q apache2 mariadb-server php8.4 php8.4-mysql php8.4-mbstring libapache2-mod-php8.4

# --- 2. Déploiement ------------------------------------------
info "Déploiement du projet dans $WEBROOT..."
cp -R "$PROJECT_DIR" "$WEBROOT"
chown -R www-data:www-data "$WEBROOT"
find "$WEBROOT" -type d -exec chmod 755 {} \;
find "$WEBROOT" -type f -exec chmod 644 {} \;

# --- 3. Base de données --------------------------------------
info "Création de la base de données..."
mariadb <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

info "Import du schéma..."
mariadb "$DB_NAME" < "$PROJECT_DIR/sql/schema.sql"
mariadb "$DB_NAME" < "$PROJECT_DIR/sql/auth_extension.sql"

# --- 4. secrets.php ------------------------------------------
info "Création de secrets.php..."
cat > "$WEBROOT/config/secrets.php" <<EOF
<?php
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
define('WEBHOOK_TOKEN', '$WEBHOOK_TOKEN');
EOF
chmod 640 "$WEBROOT/config/secrets.php"
chown www-data:www-data "$WEBROOT/config/secrets.php"

# --- 5. Apache -----------------------------------------------
info "Configuration d'Apache..."
a2enmod php8.4 rewrite -q
systemctl restart apache2

# --- 6. Mot de passe admin -----------------------------------
info "Initialisation du compte admin..."
php -r "
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '$DB_NAME');
define('DB_CHARSET', 'utf8mb4');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
\$pdo = new PDO('mysql:host=127.0.0.1;dbname=$DB_NAME;charset=utf8mb4', '$DB_USER', '$DB_PASS');
\$hash = password_hash('Admin1234!', PASSWORD_BCRYPT);
\$pdo->prepare(\"UPDATE utilisateur SET mot_de_passe = ? WHERE email = 'admin@domescape.local'\")->execute([\$hash]);
echo 'Compte admin initialisé.' . PHP_EOL;
"

# --- 7. Healthcheck ------------------------------------------
info "Vérification..."
sleep 1
RESULT=$(curl -s http://localhost/domescape/api/healthcheck.php)
DB_STATUS=$(echo "$RESULT" | grep -o '"database":{"status":"[^"]*"' | grep -o 'ok\|error')

if [ "$DB_STATUS" = "ok" ]; then
    echo ""
    echo -e "${GREEN}============================================${NC}"
    echo -e "${GREEN}  DomEscape installé avec succès !${NC}"
    echo -e "${GREEN}============================================${NC}"
    echo ""
    echo "  URL     : http://$(hostname -I | awk '{print $1}')/domescape/public/tableau-de-bord.php"
    echo "  Email   : admin@domescape.local"
    echo "  Mot de passe : Admin1234!"
    echo ""
else
    warning "Installation terminée mais la base de données ne répond pas."
    echo "Vérifiez manuellement : curl http://localhost/domescape/api/healthcheck.php"
fi
