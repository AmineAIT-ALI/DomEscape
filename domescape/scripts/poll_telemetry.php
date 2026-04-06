#!/usr/bin/php
<?php
// =============================================================
// poll_telemetry.php — Polling température/humidité Domoticz
//
// Interroge l'API Domoticz (idx=8) via curl et insère dans mesure_capteur.
//
// Cron : */5 * * * * php /var/www/html/domescape/scripts/poll_telemetry.php
// =============================================================

define('DOMOTICZ_HOST', 'http://127.0.0.1:8080');
define('DOMOTICZ_USER', 'admin');
define('DOMOTICZ_PASS', 'domoticz');
define('DEVICE_IDX',    8);
define('ID_CAPTEUR',    5);

define('DB_HOST', 'localhost');
define('DB_NAME', 'domescape');
define('DB_USER', 'domescape');
define('DB_PASS', 'domescape2025');

function fetch_device($idx) {
    $url = DOMOTICZ_HOST . '/json.htm?type=devices&rid=' . $idx;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($ch, CURLOPT_USERPWD, DOMOTICZ_USER . ':' . DOMOTICZ_PASS);
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($output === false || $httpCode !== 200) {
        throw new RuntimeException("Domoticz HTTP $httpCode");
    }
    $data = json_decode($output, true);
    $results = $data['result'] ?? [];
    if (empty($results)) {
        throw new RuntimeException("Device idx=$idx introuvable");
    }
    return $results[0];
}

function insert_mesure($temperature, $humidite) {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare(
        'INSERT INTO mesure_capteur (id_capteur, temperature, humidite) VALUES (?, ?, ?)'
    );
    $stmt->execute([ID_CAPTEUR, $temperature, $humidite]);
}

try {
    $device = fetch_device(DEVICE_IDX);
    $temp   = (float) ($device['Temp']     ?? 0);
    $humid  = (float) ($device['Humidity'] ?? 0);
    insert_mesure($temp, $humid);
    echo "[OK] {$temp}°C, {$humid}%" . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, '[ERREUR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
