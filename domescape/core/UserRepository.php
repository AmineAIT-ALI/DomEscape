<?php
// ============================================================
// DomEscape — Accès aux données utilisateurs
// ============================================================

require_once __DIR__ . '/../config/database.php';

class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    // ----------------------------------------------------------
    // Recherche
    // ----------------------------------------------------------

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nom, email, mot_de_passe, actif FROM utilisateur WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nom, email, mot_de_passe, actif, cree_le, derniere_connexion FROM utilisateur WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM utilisateur WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return (bool) $stmt->fetchColumn();
    }

    public function listAll(): array
    {
        $stmt = $this->db->query(
            'SELECT u.id, u.nom, u.email, u.actif, u.cree_le, u.derniere_connexion,
                    GROUP_CONCAT(r.nom ORDER BY r.nom SEPARATOR \',\') AS roles
             FROM utilisateur u
             LEFT JOIN utilisateur_role ur ON ur.id_utilisateur = u.id
             LEFT JOIN role r ON r.id = ur.id_role
             GROUP BY u.id
             ORDER BY u.cree_le DESC'
        );
        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------
    // Rôles
    // ----------------------------------------------------------

    public function getRoles(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.nom FROM role r
             JOIN utilisateur_role ur ON ur.id_role = r.id
             WHERE ur.id_utilisateur = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAllRoles(): array
    {
        return $this->db->query('SELECT id, nom FROM role ORDER BY id')->fetchAll();
    }

    public function assignRole(int $userId, int $roleId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO utilisateur_role (id_utilisateur, id_role) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $roleId]);
    }

    public function removeRole(int $userId, int $roleId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM utilisateur_role WHERE id_utilisateur = ? AND id_role = ?'
        );
        $stmt->execute([$userId, $roleId]);
    }

    public function clearRoles(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM utilisateur_role WHERE id_utilisateur = ?');
        $stmt->execute([$userId]);
    }

    // ----------------------------------------------------------
    // Écriture
    // ----------------------------------------------------------

    /**
     * Crée un utilisateur et retourne son id.
     */
    public function create(string $nom, string $email, string $password): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO utilisateur (nom, email, mot_de_passe) VALUES (?, ?, ?)'
        );
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt->execute([trim($nom), trim($email), $hash]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['nom', 'email', 'actif', 'mot_de_passe'];
        $sets    = [];
        $values  = [];

        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[]   = "$k = ?";
                $values[] = $v;
            }
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE utilisateur SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($values);
    }

    public function updateLastLogin(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE utilisateur SET derniere_connexion = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    // ----------------------------------------------------------
    // Sessions liées à l'utilisateur (via equipe.id_utilisateur)
    // ----------------------------------------------------------

    public function getSessionsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id_session AS id,
                    s.date_debut  AS debut,
                    s.date_fin    AS fin,
                    s.statut_session AS statut,
                    s.score,
                    s.nb_erreurs,
                    s.duree_secondes,
                    sc.nom_scenario AS scenario_nom,
                    e.nom_equipe    AS joueur_nom
             FROM session s
             JOIN equipe   e  ON e.id_equipe   = s.id_equipe
             JOIN scenario sc ON sc.id_scenario = s.id_scenario
             WHERE e.id_utilisateur = ?
             ORDER BY s.date_debut DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
