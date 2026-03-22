#!/usr/bin/env python3
"""
DomEscape — Service LCD PiFace
==============================
Micro-serveur Flask qui reçoit les messages depuis le backend PHP
et les affiche sur l'écran LCD PiFace.

Pourquoi un service séparé ?
  - Le processus Apache (www-data) n'a pas accès au bus SPI du LCD.
  - Ce service tourne avec les droits nécessaires (utilisateur pi).
  - PHP appelle simplement http://localhost:5000/lcd?msg=...

Lancement :
  python3 lcd_service.py

Lancement au démarrage (optionnel) :
  Ajouter dans /etc/rc.local :
  sudo -u pi python3 /home/pi/domescape/scripts/lcd_service.py &
"""

import subprocess
import sys
from flask import Flask, request, jsonify

app = Flask(__name__)

# Chemin vers l'exécutable pifacecad
PIFACECAD = '/home/pi/piface/libpifacecad/pifacecad'

# Nombre de caractères max affichables par ligne sur le LCD
LCD_LINE_WIDTH = 16


def lcd_write(message: str) -> bool:
    """Envoie un message sur l'écran LCD via pifacecad."""
    try:
        # Ouvrir le LCD
        subprocess.run([PIFACECAD, 'open', 'blinkoff', 'cursoroff'],
                       timeout=2, check=True)

        # Effacer l'écran
        subprocess.run([PIFACECAD, 'clear'], timeout=2, check=True)

        # Découper le message en deux lignes si nécessaire
        line1 = message[:LCD_LINE_WIDTH]
        line2 = message[LCD_LINE_WIDTH:LCD_LINE_WIDTH * 2]

        # Afficher ligne 1
        subprocess.run([PIFACECAD, 'write', line1], timeout=2, check=True)

        # Afficher ligne 2 si elle existe
        if line2:
            subprocess.run([PIFACECAD, 'cursor', '0', '1'], timeout=2, check=True)
            subprocess.run([PIFACECAD, 'write', line2], timeout=2, check=True)

        return True

    except subprocess.TimeoutExpired:
        print(f'[LCD] Timeout lors de l\'écriture : {message}', file=sys.stderr)
        return False
    except subprocess.CalledProcessError as e:
        print(f'[LCD] Erreur pifacecad : {e}', file=sys.stderr)
        return False
    except FileNotFoundError:
        # pifacecad non trouvé — mode simulation
        print(f'[LCD SIMULATION] {message}')
        return True


@app.route('/lcd', methods=['GET'])
def lcd_endpoint():
    """
    Affiche un message sur le LCD.
    Paramètre : msg (string)
    Exemple   : GET /lcd?msg=Access%20Granted
    """
    message = request.args.get('msg', '').strip()

    if not message:
        return jsonify({'status': 'error', 'message': 'Paramètre msg manquant.'}), 400

    success = lcd_write(message)

    if success:
        return jsonify({'status': 'ok', 'displayed': message})
    else:
        return jsonify({'status': 'error', 'message': 'Échec écriture LCD.'}), 500


@app.route('/lcd/clear', methods=['GET'])
def lcd_clear():
    """Efface l'écran LCD."""
    try:
        subprocess.run([PIFACECAD, 'open'], timeout=2, check=True)
        subprocess.run([PIFACECAD, 'clear'], timeout=2, check=True)
        return jsonify({'status': 'ok'})
    except Exception:
        return jsonify({'status': 'ok', 'note': 'simulation'})


@app.route('/health', methods=['GET'])
def health():
    """Vérification que le service est actif."""
    return jsonify({'status': 'ok', 'service': 'DomEscape LCD Service'})


if __name__ == '__main__':
    print('[LCD Service] Démarrage sur http://localhost:5000')
    app.run(host='127.0.0.1', port=5000, debug=False)
