<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ActionManager.php';

// =============================================================
// GameEngine — Cœur du moteur de scénarios
//
// Stateless : tout l'état est lu/écrit en base de données.
//
// Tables : session, etape, etape_attend, evenement_session
//
// Valeurs de statut_session :
//   en_attente | en_cours | gagnee | perdue | abandonnee
// =============================================================

class GameEngine
{
    /**
     * Point d'entrée principal.
     * Appelé par handle_event.php après normalisation de l'événement.
     *
     * $event : tableau retourné par EventManager::fromWebhook()
     */
    public static function process(array $event): void
    {
        $pdo = getDB();
        $pdo->beginTransaction();

        try {
            // 1. Récupérer et verrouiller la session active (évite la race condition)
            $stmt = $pdo->query("
                SELECT * FROM session
                WHERE statut_session = 'en_cours'
                ORDER BY date_debut DESC
                LIMIT 1
                FOR UPDATE
            ");
            $session = $stmt->fetch() ?: null;

            if ($session === null) {
                self::logEvenement($event, null, false, null);
                error_log('[GameEngine] Aucune session active — événement ignoré.');
                $pdo->commit();
                return;
            }

            $idEtape = $session['id_etape_courante'];
            $etape   = self::getEtape($idEtape);
            if ($etape === null) {
                error_log("[GameEngine] Étape $idEtape introuvable.");
                $pdo->commit();
                return;
            }

            // 2. Vérifier si l'événement correspond à ce qui est attendu
            $attendu   = self::getEtapeAttend($idEtape);
            $estValide = self::matchesAttend($event, $attendu);

            // 3. Enregistrer l'événement dans l'historique
            self::logEvenement($event, $session['id_session'], $estValide, $idEtape);

            // 4. Traiter selon le résultat
            if ($estValide) {
                self::onSucces($session, $etape);
            } else {
                self::onEchec($session, $etape);
            }

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[GameEngine] Erreur dans process() : ' . $e->getMessage());
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // Succès : étape validée
    // ----------------------------------------------------------
    private static function onSucces(array $session, array $etape): void
    {
        $pdo = getDB();

        $idSession = $session['id_session'];

        ActionManager::executeForEtape($etape['id_etape'], 'on_success', $idSession);

        $nouveauScore = $session['score'] + $etape['points'];

        if ($etape['finale']) {
            // Fin de partie — victoire
            $now    = date('Y-m-d H:i:s');
            $duree  = self::calculerDuree($session['date_debut'], $now);

            $pdo->prepare("
                UPDATE session
                SET statut_session = 'gagnee',
                    date_fin        = ?,
                    score           = ?,
                    duree_secondes  = ?
                WHERE id_session = ?
            ")->execute([$now, $nouveauScore, $duree, $idSession]);

            error_log("[GameEngine] Session {$idSession} — VICTOIRE en {$duree}s.");

        } else {
            // Passer à l'étape suivante
            $etapeSuivante = self::getEtapeSuivante($etape['id_scenario'], $etape['numero_etape']);

            if ($etapeSuivante === null) {
                error_log('[GameEngine] Aucune étape suivante trouvée malgré finale=false.');
                return;
            }

            $pdo->prepare("
                UPDATE session
                SET id_etape_courante = ?,
                    score             = ?
                WHERE id_session = ?
            ")->execute([$etapeSuivante['id_etape'], $nouveauScore, $idSession]);

            ActionManager::executeForEtape($etapeSuivante['id_etape'], 'on_enter', $idSession);

            error_log("[GameEngine] Session {$idSession} — étape {$etapeSuivante['id_etape']}.");
        }
    }

    // ----------------------------------------------------------
    // Échec : mauvaise action
    // ----------------------------------------------------------
    private static function onEchec(array $session, array $etape): void
    {
        $pdo = getDB();

        ActionManager::executeForEtape($etape['id_etape'], 'on_failure', $session['id_session']);

        $pdo->prepare("
            UPDATE session
            SET nb_erreurs = nb_erreurs + 1
            WHERE id_session = ?
        ")->execute([$session['id_session']]);

        error_log("[GameEngine] Session {$session['id_session']} — erreur sur étape {$etape['id_etape']}.");
    }

    // ----------------------------------------------------------
    // Vérification de correspondance événement / attendu
    // ----------------------------------------------------------
    private static function matchesAttend(array $event, ?array $attendu): bool
    {
        if ($attendu === null) return false;

        // Même capteur ?
        if ((int)$attendu['id_capteur'] !== (int)$event['capteur']['id_capteur']) {
            return false;
        }

        // Même type d'événement ?
        if ((int)$attendu['id_type_evenement'] !== (int)$event['evenement_type']['id_type_evenement']) {
            return false;
        }

        return true;
    }

    // ----------------------------------------------------------
    // Helpers BDD
    // ----------------------------------------------------------

    /**
     * Retourne l'unique session en cours (statut_session = 'en_cours').
     */
    public static function getActiveSession(): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->query("
            SELECT * FROM session
            WHERE statut_session = 'en_cours'
            ORDER BY date_debut DESC
            LIMIT 1
        ");
        return $stmt->fetch() ?: null;
    }

    private static function getEtape(int $id): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT * FROM etape WHERE id_etape = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function getEtapeSuivante(int $idScenario, int $numeroActuel): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT * FROM etape
            WHERE id_scenario = ? AND numero_etape > ?
            ORDER BY numero_etape ASC
            LIMIT 1
        ");
        $stmt->execute([$idScenario, $numeroActuel]);
        return $stmt->fetch() ?: null;
    }

    private static function getEtapeAttend(int $idEtape): ?array
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT * FROM etape_attend
            WHERE id_etape = ? AND obligatoire = 1
            LIMIT 1
        ");
        $stmt->execute([$idEtape]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Démarre une nouvelle session pour un scénario et un joueur.
     * Exécute les actions on_enter de la première étape.
     */
    public static function startSession(int $idScenario, int $idJoueur): int
    {
        $pdo = getDB();

        // Première étape du scénario
        $stmt = $pdo->prepare("
            SELECT * FROM etape
            WHERE id_scenario = ?
            ORDER BY numero_etape ASC
            LIMIT 1
        ");
        $stmt->execute([$idScenario]);
        $premiereEtape = $stmt->fetch();

        if (!$premiereEtape) {
            throw new RuntimeException("Aucune étape trouvée pour le scénario $idScenario.");
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare("
            INSERT INTO session
                (id_scenario, id_joueur, statut_session, date_debut, id_etape_courante)
            VALUES (?, ?, 'en_cours', ?, ?)
        ")->execute([$idScenario, $idJoueur, $now, $premiereEtape['id_etape']]);

        $idSession = (int)$pdo->lastInsertId();

        ActionManager::executeForEtape($premiereEtape['id_etape'], 'on_enter', $idSession);

        error_log("[GameEngine] Session $idSession démarrée — scénario $idScenario, joueur $idJoueur.");

        return $idSession;
    }

    /**
     * Réinitialise la session active (Game Master).
     */
    public static function resetActiveSession(): void
    {
        $pdo = getDB();
        $pdo->exec("
            UPDATE session
            SET statut_session  = 'abandonnee',
                date_fin        = NOW(),
                duree_secondes  = TIMESTAMPDIFF(SECOND, date_debut, NOW())
            WHERE statut_session = 'en_cours'
        ");
    }

    // ----------------------------------------------------------
    // Historique des événements
    // ----------------------------------------------------------
    private static function logEvenement(
        array $event,
        ?int  $idSession,
        bool  $evenementAttendu,
        ?int  $idEtape
    ): void {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO evenement_session
                (id_session, id_capteur, id_type_evenement, valeur_brute,
                 evenement_attendu, valide, id_etape, date_evenement)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $idSession,
            $event['capteur']['id_capteur']               ?? null,
            $event['evenement_type']['id_type_evenement']  ?? null,
            json_encode($event['raw']),
            $evenementAttendu ? 1 : 0,   // correspondait à l'attendu de l'étape ?
            $idSession !== null ? 1 : 0, // session active au moment de l'événement ?
            $idEtape,
        ]);
    }

    private static function calculerDuree(?string $debut, string $fin): int
    {
        if ($debut === null) return 0;
        return (int)(strtotime($fin) - strtotime($debut));
    }
}
