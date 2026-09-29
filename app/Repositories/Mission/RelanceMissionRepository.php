<?php
declare(strict_types=1);

namespace App\Repositories\Mission;

use App\Core\Repository;

final class RelanceMissionRepository extends Repository
{
    private const LOCK_NAME = 'risfm_relances_missions';

    protected string $table = 'relances_missions';

    public function repo_acquerirVerrou(): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => self::LOCK_NAME]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function repo_libererVerrou(): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => self::LOCK_NAME]);
    }

    public function repo_existe(int $missionId, int $recipientId, string $type, string $date): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM relances_missions
             WHERE mission_id = :mission_id
               AND destinataire_id = :destinataire_id
               AND type_relance = :type_relance
               AND date_relance = :date_relance'
        );
        $stmt->execute([
            'mission_id' => $missionId,
            'destinataire_id' => $recipientId,
            'type_relance' => $type,
            'date_relance' => $date,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function repo_compterEmailsEnAttente(string $since): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM relances_missions r
             JOIN missions_recherche m
               ON m.id = r.mission_id AND m.etat IN ('affectee','en_cours')
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN utilisateurs u ON u.id = r.destinataire_id AND u.actif = 1
             WHERE r.email_envoye = 0 AND r.date_relance >= :depuis"
        );
        $stmt->execute(['depuis' => $since]);
        return (int) $stmt->fetchColumn();
    }

    public function repo_creerEvenement(
        int $missionId,
        int $recipientId,
        string $type,
        string $date,
        int $daysUntil
    ): ?int {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO relances_missions
                (mission_id, destinataire_id, type_relance, date_relance, jours_ecart)
             VALUES
                (:mission_id, :destinataire_id, :type_relance, :date_relance, :jours_ecart)'
        );
        $stmt->execute([
            'mission_id' => $missionId,
            'destinataire_id' => $recipientId,
            'type_relance' => $type,
            'date_relance' => $date,
            'jours_ecart' => $daysUntil,
        ]);
        return $stmt->rowCount() === 1 ? (int) $this->db->lastInsertId() : null;
    }

    public function repo_associerNotification(int $eventId, int $notificationId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE relances_missions SET notification_id = :notification_id WHERE id = :id'
        );
        $stmt->execute(['notification_id' => $notificationId, 'id' => $eventId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function repo_emailsEnAttente(string $since): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id AS relance_id, r.type_relance, r.jours_ecart,
                    m.id AS mission_id, m.formulaire_id, m.responsable_id,
                    m.date_echeance, m.priorite,
                    f.numero_auto, f.numero_formulaire,
                    t.libelle AS type_libelle, l.libelle AS localisation_libelle,
                    u.id AS destinataire_id, u.identifiant, u.nom, u.prenoms, u.email, u.actif
             FROM relances_missions r
             JOIN missions_recherche m
               ON m.id = r.mission_id AND m.etat IN ('affectee','en_cours')
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN localisations l ON l.id = m.localisation_id
             JOIN utilisateurs u ON u.id = r.destinataire_id AND u.actif = 1
             WHERE r.email_envoye = 0 AND r.date_relance >= :depuis
             ORDER BY r.id"
        );
        $stmt->execute(['depuis' => $since]);
        return $stmt->fetchAll();
    }

    public function repo_emailEnvoye(int $eventId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT email_envoye FROM relances_missions WHERE id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function repo_marquerEmailEnvoye(int $eventId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE relances_missions
             SET email_envoye = 1, email_tente_le = NOW(), email_erreur = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $eventId]);
    }

    public function repo_marquerErreurEmail(int $eventId, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE relances_missions
             SET email_tente_le = NOW(), email_erreur = :erreur
             WHERE id = :id'
        );
        $stmt->execute([
            'erreur' => mb_substr($error, 0, 500),
            'id' => $eventId,
        ]);
    }
}
