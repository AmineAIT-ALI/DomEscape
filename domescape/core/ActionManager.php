<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../domoticz/DomoticzClient.php';

// =============================================================
// ActionManager — Exécution des actions de feedback
//
// Lit les etape_declenche depuis la BDD et les exécute dans l'ordre.
//
// Tables : etape_declenche, actionneur, action_type
//
// Codes d'actions supportés (action_type.code_action) :
//   LCD_MESSAGE  → envoie un message au service LCD Python
//   LAMP_ON      → allume une lampe via Domoticz
//   LAMP_OFF     → éteint une lampe via Domoticz
//   PLUG_ON      → active une prise via Domoticz
//   PLUG_OFF     → désactive une prise via Domoticz
//   LOG_ONLY     → journalise sans action physique
// =============================================================

class ActionManager
{
    private static ?DomoticzClient $domoticz = null;

    /**
     * Exécute toutes les actions d'une étape pour un moment donné.
     * $moment : 'on_enter' | 'on_success' | 'on_failure' | 'on_hint'
     */
    public static function executeForEtape(int $idEtape, string $moment, int $idSession = 0): void
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT ed.id_actionneur, ed.id_type_action, ed.valeur_action, ed.ordre_action,
                   at.code_action,
                   a.domoticz_idx, a.nom_actionneur
            FROM etape_declenche ed
            JOIN actionneur a   ON ed.id_actionneur  = a.id_actionneur
            JOIN action_type at ON ed.id_type_action  = at.id_type_action
            WHERE ed.id_etape = ? AND ed.moment_declenchement = ?
            ORDER BY ed.ordre_action ASC
        ");
        $stmt->execute([$idEtape, $moment]);
        $actions = $stmt->fetchAll();

        foreach ($actions as $action) {
            $statut = 'ok';
            try {
                self::execute($action);
            } catch (Throwable $e) {
                $statut = 'erreur';
                error_log("[ActionManager] Erreur action {$action['code_action']} : " . $e->getMessage());
            }

            if ($idSession > 0) {
                $pdo->prepare("
                    INSERT INTO action_executee
                        (id_session, id_actionneur, id_type_action, id_etape,
                         date_execution, valeur_action, statut_execution)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?)
                ")->execute([
                    $idSession,
                    $action['id_actionneur'],
                    $action['id_type_action'],
                    $idEtape,
                    $action['valeur_action'],
                    $statut,
                ]);
            }
        }
    }

    /**
     * Exécute une action unique.
     */
    public static function execute(array $action): void
    {
        $code  = $action['code_action'];
        $idx   = (int)($action['domoticz_idx'] ?? 0);
        $value = $action['valeur_action'] ?? '';

        error_log("[ActionManager] Exécution : $code idx=$idx val=$value");

        switch ($code) {
            case 'LCD_MESSAGE':
                self::sendLcdMessage($value);
                break;

            case 'LAMP_ON':
                self::getDomoticz()->turnOn($idx);
                break;

            case 'LAMP_OFF':
                self::getDomoticz()->turnOff($idx);
                break;

            case 'PLUG_ON':
                self::getDomoticz()->turnOn($idx);
                break;

            case 'PLUG_OFF':
                self::getDomoticz()->turnOff($idx);
                break;

            case 'LOG_ONLY':
                // Rien à exécuter, juste journalisé
                break;

            default:
                error_log("[ActionManager] Code d'action inconnu : $code");
        }
    }

    // ----------------------------------------------------------
    // LCD — appel HTTP vers le service Python Flask
    // ----------------------------------------------------------
    private static function sendLcdMessage(string $message): void
    {
        $url = LCD_SERVICE_URL . '/lcd?msg=' . urlencode($message);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'method'  => 'GET',
            ]
        ]);

        $result = @file_get_contents($url, false, $ctx);

        if ($result === false) {
            error_log("[ActionManager] Service LCD inaccessible. Message perdu : $message");
        }
    }

    // ----------------------------------------------------------
    // Singleton DomoticzClient
    // ----------------------------------------------------------
    private static function getDomoticz(): DomoticzClient
    {
        if (self::$domoticz === null) {
            self::$domoticz = new DomoticzClient();
        }
        return self::$domoticz;
    }
}
