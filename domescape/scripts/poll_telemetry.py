#!/usr/bin/env python3
# =============================================================
# poll_telemetry.py — Polling température/humidité Domoticz
#
# Interroge l'API Domoticz (idx=8) et insère dans mesure_capteur.
# Aucune dépendance externe — stdlib + mysql CLI.
#
# Cron : */5 * * * * python3 /var/www/html/domescape/scripts/poll_telemetry.py
# =============================================================

import urllib.request
import json
import subprocess
import sys

DOMOTICZ_HOST = 'http://127.0.0.1:8080'
DEVICE_IDX    = 8
ID_CAPTEUR    = 5   # id_capteur de Air Temperature/Humidity

DB_USER = 'domescape'
DB_PASS = 'domescape2025'
DB_NAME = 'domescape'

def fetch_device(idx):
    url = f'{DOMOTICZ_HOST}/json.htm?type=devices&rid={idx}'
    with urllib.request.urlopen(url, timeout=5) as r:
        data = json.loads(r.read().decode())
    results = data.get('result', [])
    if not results:
        raise ValueError(f'Device idx={idx} introuvable')
    return results[0]

def insert_mesure(temperature, humidite):
    sql = (
        f"INSERT INTO mesure_capteur (id_capteur, temperature, humidite) "
        f"VALUES ({ID_CAPTEUR}, {temperature}, {humidite});"
    )
    result = subprocess.run(
        ['mysql', f'-u{DB_USER}', f'-p{DB_PASS}', DB_NAME, '-e', sql],
        capture_output=True, text=True
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip())

if __name__ == '__main__':
    try:
        device = fetch_device(DEVICE_IDX)
        temp   = float(device.get('Temp', 0))
        humid  = float(device.get('Humidity', 0))
        insert_mesure(temp, humid)
        print(f'[OK] {temp}°C, {humid}%')
    except Exception as e:
        print(f'[ERREUR] {e}', file=sys.stderr)
        sys.exit(1)
