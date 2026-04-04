#!/usr/bin/env python3
"""
DomEscape — Service LCD PiFace
==============================
Micro-serveur HTTP utilisant uniquement la stdlib Python (http.server).
Aucune dépendance externe — fonctionne sans accès internet.

Endpoints :
  GET /lcd?msg=...    — affiche un message sur le LCD
  GET /lcd/clear      — efface l'écran
  GET /health         — vérification que le service est actif

Lancement :
  python3 lcd_service.py

Lancement au démarrage :
  Ajouter dans /etc/rc.local (avant exit 0) :
  sudo -u pi python3 /home/pi/lcd_service.py >> /home/pi/lcd.log 2>&1 &
"""

import subprocess
import sys
import json
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

HOST = '127.0.0.1'
PORT = 5000

# Chemin vers l'exécutable pifacecad
PIFACECAD    = '/home/pi/piface/libpifacecad/pifacecad'
LCD_LINE_WIDTH = 16


def lcd_write(message: str) -> bool:
    """Envoie un message sur l'écran LCD via pifacecad."""
    try:
        subprocess.run([PIFACECAD, 'open', 'blinkoff', 'cursoroff'],
                       timeout=2, check=True)
        subprocess.run([PIFACECAD, 'clear'], timeout=2, check=True)

        line1 = message[:LCD_LINE_WIDTH]
        line2 = message[LCD_LINE_WIDTH:LCD_LINE_WIDTH * 2]

        subprocess.run([PIFACECAD, 'write', line1], timeout=2, check=True)

        if line2:
            subprocess.run([PIFACECAD, 'cursor', '0', '1'], timeout=2, check=True)
            subprocess.run([PIFACECAD, 'write', line2], timeout=2, check=True)

        return True

    except subprocess.TimeoutExpired:
        print(f'[LCD] Timeout : {message}', file=sys.stderr)
        return False
    except subprocess.CalledProcessError as e:
        print(f'[LCD] Erreur pifacecad : {e}', file=sys.stderr)
        return False
    except FileNotFoundError:
        # pifacecad non trouvé — mode simulation (logs uniquement)
        print(f'[LCD SIMULATION] {message}')
        return True


def lcd_clear() -> bool:
    """Efface l'écran LCD."""
    try:
        subprocess.run([PIFACECAD, 'open'], timeout=2, check=True)
        subprocess.run([PIFACECAD, 'clear'], timeout=2, check=True)
        return True
    except Exception:
        print('[LCD SIMULATION] clear')
        return True


class LcdHandler(BaseHTTPRequestHandler):

    def log_message(self, fmt, *args):
        # Surcharge pour afficher les logs proprement
        print(f'[LCD Service] {self.address_string()} — {fmt % args}')

    def send_json(self, code: int, data: dict):
        body = json.dumps(data).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)

        # GET /health
        if parsed.path == '/health':
            self.send_json(200, {'status': 'ok', 'service': 'DomEscape LCD Service'})
            return

        # GET /lcd/clear
        if parsed.path == '/lcd/clear':
            lcd_clear()
            self.send_json(200, {'status': 'ok'})
            return

        # GET /lcd?msg=...
        if parsed.path == '/lcd':
            msg_list = params.get('msg', [])
            if not msg_list or not msg_list[0].strip():
                self.send_json(400, {'status': 'error', 'message': 'Paramètre msg manquant.'})
                return

            message = msg_list[0].strip()
            success = lcd_write(message)

            if success:
                self.send_json(200, {'status': 'ok', 'displayed': message})
            else:
                self.send_json(500, {'status': 'error', 'message': 'Échec écriture LCD.'})
            return

        self.send_json(404, {'status': 'error', 'message': 'Route inconnue.'})


if __name__ == '__main__':
    server = HTTPServer((HOST, PORT), LcdHandler)
    print(f'[LCD Service] Démarrage sur http://{HOST}:{PORT}')
    print(f'[LCD Service] Endpoints : /lcd?msg=... /lcd/clear /health')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n[LCD Service] Arrêt.')
        server.server_close()
