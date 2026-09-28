<?php
declare(strict_types=1);

class NotificationModel extends Model
{
    protected string $table = 'notifications';

    public function pourUtilisateur(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.*,
                    CASE WHEN nl.notification_id IS NULL THEN 0 ELSE 1 END AS lu,
                    nl.lu_le
             FROM notifications n
             LEFT JOIN notification_lectures nl
               ON nl.notification_id = n.id AND nl.utilisateur_id = :reader_id
             WHERE n.utilisateur_id = :target_id OR n.utilisateur_id IS NULL
             ORDER BY n.cree_le DESC, n.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':reader_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':target_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function totalPourUtilisateur(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE utilisateur_id = :uid OR utilisateur_id IS NULL'
        );
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function nonLuesCount(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n
             FROM notifications n
             LEFT JOIN notification_lectures nl
               ON nl.notification_id = n.id AND nl.utilisateur_id = :reader_id
             WHERE (n.utilisateur_id = :target_id OR n.utilisateur_id IS NULL)
               AND nl.notification_id IS NULL'
        );
        $stmt->execute(['reader_id' => $userId, 'target_id' => $userId]);
        return (int) $stmt->fetch()['n'];
    }

    public function marquerLue(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notification_lectures (notification_id, utilisateur_id, lu_le)
             SELECT n.id, :reader_id, NOW()
             FROM notifications n
             WHERE n.id = :notification_id
               AND (n.utilisateur_id = :target_id OR n.utilisateur_id IS NULL)
             ON DUPLICATE KEY UPDATE lu_le = VALUES(lu_le)'
        );
        return $stmt->execute([
            'reader_id' => $userId,
            'notification_id' => $id,
            'target_id' => $userId,
        ]);
    }

    public function marquerToutesLues(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notification_lectures (notification_id, utilisateur_id, lu_le)
             SELECT n.id, :reader_id, NOW()
             FROM notifications n
             WHERE n.utilisateur_id = :target_id OR n.utilisateur_id IS NULL
             ON DUPLICATE KEY UPDATE lu_le = VALUES(lu_le)'
        );
        return $stmt->execute(['reader_id' => $userId, 'target_id' => $userId]);
    }

    public function creer(?int $userId, string $titre, string $message, string $type = 'info', ?string $lien = null): int
    {
        return $this->insert([
            'utilisateur_id' => $userId,
            'titre'          => $titre,
            'message'        => $message,
            'type'           => $type,
            'lien'           => $lien,
        ]);
    }
}
