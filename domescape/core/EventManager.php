<?php

require_once __DIR__ . '/../config/database.php';

// =============================================================
// EventManager
//
// Responsabilités :
//   1. Recevoir le payload brut du webhook Domoticz
//   2. Identifier le capteur via son domoticz_idx
//   3. Mapper nvalue/svalue → code_evenement normalisé
//   4. Retourner un tableau structuré utilisable par le GameEngine
//
// Tables : capteur, evenement_type
// =============================================================

class EventManager
{
    /**
     * Construit un événement normalisé depuis le payload du webhook.
     *
     * Payload attendu (POST Domoticz) :
     *   idx    => int    (identifiant du device dans Domoticz)
     *   nvalue => int    (valeur numérique de l'état)
     *   svalue => string (valeur textuelle de l'état)
     *
     * Retourne :
     *   [
     *     'capteur'       => array (ligne BDD capteur),
     *     'code_evenement'=> string (ex: 'DOOR_OPEN'),
     *     'evenement_type'=> array (ligne BDD evenement_type),
     *     'raw'           => array (payload original),
     *   ]
     * Retourne null si l'événement ne peut pas être identifié.
     */
    // Délai minimum entre deux événements identiques (microsecondes)
    private const DEBOUNCE_US = 500_000; // 500 ms

    public static function fromWebhook(array $payload): ?array
    {
        $idx    = (int)($payload['idx']    ?? 0);
        $nvalue = (int)($payload['nvalue'] ?? 0);
        $svalue = (string)($payload['svalue'] ?? '');

        if ($idx === 0) {
            error_log('[EventManager] Payload sans idx valide.');
            return null;
        }

        // Debounce Z-Wave : ignorer les doublons dans la fenêtre de 500 ms
        if (self::isDuplicate($idx, $nvalue)) {
            error_log("[EventManager] Debounce — doublon ignoré idx=$idx nvalue=$nvalue");
            return null;
        }

        // 1. Retrouver le capteur par son idx Domoticz
        $capteur = self::findCapteurByIdx($idx);
        if ($capteur === null) {
            error_log("[EventManager] Aucun capteur connu pour idx=$idx");
            return null;
        }

        // 2. Mapper vers un code d'événement normalisé
        $codeEvenement = self::mapToCodeEvenement($capteur['type_capteur'], $nvalue, $svalue);
        if ($codeEvenement === null) {
            error_log("[EventManager] Impossible de mapper type_capteur={$capteur['type_capteur']} nvalue=$nvalue");
            return null;
        }

        // 3. Récupérer le type d'événement en BDD
        $evenementType = self::findEvenementType($codeEvenement);
        if ($evenementType === null) {
            error_log("[EventManager] code '$codeEvenement' absent de evenement_type.");
            return null;
        }

        return [
            'capteur'        => $capteur,
            'code_evenement' => $codeEvenement,
            'evenement_type' => $evenementType,
            'raw'            => $payload,
        ];
    }

    // ----------------------------------------------------------
    // Mapping type_capteur + nvalue/svalue → code normalisé
    //
    //   door_sensor   nvalue=1 → DOOR_OPEN   | nvalue=0 → DOOR_CLOSE
    //   motion_sensor nvalue=1 → MOTION_DETECTED | nvalue=0 → NO_MOTION
    //   button        nvalue=1 → BUTTON_PRESS | nvalue=2 → BUTTON_DOUBLE_PRESS | nvalue=3 → BUTTON_TRIPLE_PRESS
    // ----------------------------------------------------------
    private static function mapToCodeEvenement(string $typeCapteur, int $nvalue, string $svalue): ?string
    {
        switch ($typeCapteur) {
            case 'door_sensor':
                return $nvalue === 1 ? 'DOOR_OPEN' : 'DOOR_CLOSE';

            case 'motion_sensor':
                return $nvalue === 1 ? 'MOTION_DETECTED' : 'NO_MOTION';

            case 'button':
                return match($nvalue) {
                    1 => 'BUTTON_PRESS',
                    2 => 'BUTTON_DOUBLE_PRESS',
                    3 => 'BUTTON_TRIPLE_PRESS',
                    default => null,
                };

            default:
                return null;
        }
    }

    // ----------------------------------------------------------
    // Helpers BDD
    // ----------------------------------------------------------

    private static function findCapteurByIdx(int $idx): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT * FROM capteur WHERE domoticz_idx = ? AND actif = 1 LIMIT 1');
        $stmt->execute([$idx]);
        return $stmt->fetch() ?: null;
    }

    private static function findEvenementType(string $code): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT * FROM evenement_type WHERE code_evenement = ? LIMIT 1');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    // ----------------------------------------------------------
    // Debounce Z-Wave
    // Retourne true si le même (idx, nvalue) a déjà été reçu
    // dans la fenêtre DEBOUNCE_US. Sinon, enregistre le timestamp
    // et retourne false.
    // ----------------------------------------------------------
    private static function isDuplicate(int $idx, int $nvalue): bool
    {
        $dir  = __DIR__ . '/../logs';
        $file = $dir . '/debounce_' . $idx . '_' . $nvalue . '.tmp';

        $now = microtime(true);

        if (is_file($file)) {
            $last = (float)file_get_contents($file);
            if (($now - $last) * 1_000_000 < self::DEBOUNCE_US) {
                return true;
            }
        }

        @file_put_contents($file, $now);
        return false;
    }
}
