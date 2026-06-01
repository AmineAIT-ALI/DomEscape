<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../domoticz/DomoticzClient.php';

// ActionManager : Exécution des actions de feedback
// Lit les etape_declenche depuis la BDD et les exécute dans l'ordre.
// Tables : etape_declenche, actionneur, action_type
// Codes d'actions supportés (action_type.code_action) :
//   LCD_MESSAGE  → envoie un message au service LCD Python
//   PLUG_ON      → active une prise via Domoticz
//   PLUG_OFF     → désactive une prise via Domoticz
//   PLUG_FESTIF  → séquence de clignotement festif (feux d'artifice)
//   LOG_ONLY     → journalise sans action physique

class ActionManager
{
    private static ?DomoticzClient $domoticz = null;

    // Délai en secondes entre deux commandes relais consécutives (PLUG_ON / PLUG_OFF).
    // Le relais physique a besoin de temps pour commuter mécaniquement.
    private const RELAY_DELAY_S = 3;

    
    // Exécute toutes les actions configurées pour une étape à un moment donné (on_enter, on_success, on_failure, on_hint).
    // Chaque action est journalisée dans action_executee avec son statut ok ou erreur.
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

        $codesRelais = ['PLUG_ON', 'PLUG_OFF'];
        $prevCode    = null;

        foreach ($actions as $action) {
            // Pause entre deux commandes relais consécutives pour laisser le temps au relais de commuter
            if ($prevCode !== null
                && in_array($prevCode, $codesRelais, true)
                && in_array($action['code_action'], $codesRelais, true)
            ) {
                sleep(self::RELAY_DELAY_S);
            }

            $statut = 'ok';
            try {
                self::execute($action);
            } catch (Throwable $e) {
                $statut = 'erreur';
                error_log("[ActionManager] Erreur action {$action['code_action']} : " . $e->getMessage());
            }

            $prevCode = $action['code_action'];

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

    
    // Dispatche une action physique selon son code_action (LCD_MESSAGE, PLUG_ON, PLUG_OFF, LOG_ONLY).
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

            case 'PLUG_ON':
                self::getDomoticz()->turnOn($idx);
                break;

            case 'PLUG_OFF':
                self::getDomoticz()->turnOff($idx);
                break;

            case 'PLUG_FESTIF':
                self::runFestifPattern($idx);
                break;

            case 'LOG_ONLY':
                // Rien à exécuter, juste journalisé
                break;

            default:
                error_log("[ActionManager] Code d'action inconnu : $code");
        }
    }

    // Séquence de clignotement festif : accélère puis ralentit, termine prise allumée.
    // Durée totale approximative : 6 secondes.
    private static function runFestifPattern(int $idx): void
    {
        $domoticz = self::getDomoticz();

        // Format : ['on'|'off', délai_après_en_µs]
        // Rythme lent → accélère → ralentit pour que le relais soit visible à l'oeil nu
        // Minimum 800ms entre chaque commande pour que la prise soit clairement vue éteinte ou allumée
        $steps = [
            ['on',  1_200_000],
            ['off', 1_000_000],
            ['on',  900_000],
            ['off', 800_000],
            ['on',  1_000_000],
            ['off', 1_200_000],
            ['on',  0],
        ];

        foreach ($steps as [$cmd, $delay]) {
            if ($cmd === 'on') $domoticz->turnOn($idx);
            else               $domoticz->turnOff($idx);
            if ($delay > 0) usleep($delay);
        }

        error_log("[ActionManager] Séquence festive terminée — idx=$idx prise allumée.");
    }

    // LCD — appel HTTP vers le service Python Flask
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

    // Singleton DomoticzClient
    private static function getDomoticz(): DomoticzClient
    {
        if (self::$domoticz === null) {
            self::$domoticz = new DomoticzClient();
        }
        return self::$domoticz;
    }
}
