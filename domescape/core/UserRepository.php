<?php
// DomEscape — Accès aux données utilisateurs (Core Edition)

require_once __DIR__ . '/../config/database.php';

class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    // Recherche

    // Retourne l'utilisateur correspondant à l'email ou null si introuvable.
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nom, email, mot_de_passe, is_admin, actif FROM utilisateur WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    // Retourne l'utilisateur par son id ou null si introuvable.
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nom, email, is_admin, actif, cree_le, derniere_connexion FROM utilisateur WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // Retourne true si l'adresse email est déjà enregistrée en base.
    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM utilisateur WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return (bool) $stmt->fetchColumn();
    }

    // Retourne tous les utilisateurs triés par date de création décroissante.
    public function listAll(): array
    {
        return $this->db->query(
            'SELECT id, nom, email, is_admin, actif, cree_le, derniere_connexion
             FROM utilisateur
             ORDER BY cree_le DESC'
        )->fetchAll();
    }

    // Écriture

    // Crée un utilisateur avec le mot de passe hashé en bcrypt (coût 12). Retourne l'id généré.
    public function create(string $nom, string $email, string $password, bool $isAdmin = false): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->prepare(
            'INSERT INTO utilisateur (nom, email, mot_de_passe, is_admin) VALUES (?, ?, ?, ?)'
        )->execute([trim($nom), trim($email), $hash, $isAdmin ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    // Met à jour les champs autorisés d'un utilisateur. Les clés non autorisées sont ignorées silencieusement.
    public function update(int $id, array $fields): void
    {
        $allowed = ['nom', 'email', 'actif', 'mot_de_passe', 'is_admin'];
        $sets    = [];
        $values  = [];

        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[]   = "$k = ?";
                $values[] = $v;
            }
        }

        if (empty($sets)) return;

        $values[] = $id;
        $this->db->prepare(
            'UPDATE utilisateur SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($values);
    }

    // Met à jour la date de dernière connexion à maintenant.
    public function updateLastLogin(int $id): void
    {
        $this->db->prepare(
            'UPDATE utilisateur SET derniere_connexion = NOW() WHERE id = ?'
        )->execute([$id]);
    }
}
