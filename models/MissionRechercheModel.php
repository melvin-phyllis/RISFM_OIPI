<?php
declare(strict_types=1);

class MissionRechercheModel extends Model
{
    protected string $table = 'missions_recherche';

    public function findWithRelations(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT m.*, f.numero_auto, f.numero_formulaire, f.est_archive,
                       f.cycle_suivi AS formulaire_cycle_suivi,
                       l.libelle AS localisation_libelle,
                       CONCAT_WS(" ", u.nom, u.prenoms) AS responsable_nom,
                       u.email AS responsable_email
                FROM missions_recherche m
                JOIN formulaires_manquants f ON f.id = m.formulaire_id
                JOIN localisations l ON l.id = m.localisation_id
                JOIN utilisateurs u ON u.id = m.responsable_id
                WHERE m.id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function activesPourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, l.libelle AS localisation_libelle,
                    CONCAT_WS(' ', u.nom, u.prenoms) AS responsable_nom
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             JOIN localisations l ON l.id = m.localisation_id
             JOIN utilisateurs u ON u.id = m.responsable_id
             WHERE m.formulaire_id = :formulaire_id
               AND m.etat IN ('affectee', 'en_cours')
             ORDER BY FIELD(m.priorite, 'Urgente','Haute','Normale','Basse'),
                      m.date_echeance IS NULL, m.date_echeance, m.id"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    public function pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, l.libelle AS localisation_libelle,
                    CONCAT_WS(' ', u.nom, u.prenoms) AS responsable_nom,
                    CONCAT_WS(' ', c.nom, c.prenoms) AS cloture_par_nom
             FROM missions_recherche m
             JOIN localisations l ON l.id = m.localisation_id
             JOIN utilisateurs u ON u.id = m.responsable_id
             LEFT JOIN utilisateurs c ON c.id = m.cloture_par
             WHERE m.formulaire_id = :formulaire_id
             ORDER BY CASE WHEN m.etat IN ('affectee','en_cours') THEN 0 ELSE 1 END,
                      m.date_affectation DESC, m.id DESC"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    public function activesPourResponsable(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT m.*, f.numero_auto, f.numero_formulaire, t.libelle AS type_libelle,
                    l.libelle AS localisation_libelle,
                    CASE WHEN m.date_echeance IS NOT NULL AND m.date_echeance < CURDATE() THEN 1 ELSE 0 END AS est_en_retard
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN localisations l ON l.id = m.localisation_id
             WHERE m.responsable_id = :user_id
               AND m.etat IN ('affectee', 'en_cours')
             ORDER BY m.date_echeance IS NULL, m.date_echeance,
                      FIELD(m.priorite, 'Urgente','Haute','Normale','Basse'), m.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function statsActives(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total_actives,
                    COALESCE(SUM(m.date_echeance IS NOT NULL AND m.date_echeance < CURDATE()), 0) AS total_en_retard,
                    COALESCE(SUM(m.priorite = 'Urgente'), 0) AS total_urgentes,
                    MIN(CASE WHEN m.date_echeance >= CURDATE() THEN m.date_echeance END) AS prochaine_echeance
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             WHERE m.responsable_id = :user_id
               AND m.etat IN ('affectee', 'en_cours')"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total_actives' => (int) ($row['total_actives'] ?? 0),
            'total_en_retard' => (int) ($row['total_en_retard'] ?? 0),
            'total_urgentes' => (int) ($row['total_urgentes'] ?? 0),
            'prochaine_echeance' => !empty($row['prochaine_echeance'])
                ? (string) $row['prochaine_echeance']
                : null,
        ];
    }

    /**
     * Indicateurs operationnels de toutes les missions du cycle courant.
     * Les missions d'un ancien cycle ou d'un dossier archive ne doivent jamais
     * alimenter les alertes du tableau de bord de pilotage.
     *
     * @return array{total_actives:int,total_en_retard:int,total_urgentes:int,total_a_7_jours:int}
     */
    public function statsGlobales(): array
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) AS total_actives,
                    COALESCE(SUM(m.date_echeance IS NOT NULL AND m.date_echeance < CURDATE()), 0) AS total_en_retard,
                    COALESCE(SUM(m.priorite = 'Urgente'), 0) AS total_urgentes,
                    COALESCE(SUM(
                        m.date_echeance IS NOT NULL
                        AND m.date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    ), 0) AS total_a_7_jours
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             WHERE m.etat IN ('affectee', 'en_cours')"
        );
        $row = $stmt->fetch() ?: [];

        return [
            'total_actives' => (int) ($row['total_actives'] ?? 0),
            'total_en_retard' => (int) ($row['total_en_retard'] ?? 0),
            'total_urgentes' => (int) ($row['total_urgentes'] ?? 0),
            'total_a_7_jours' => (int) ($row['total_a_7_jours'] ?? 0),
        ];
    }

    /** @return array{total_dossiers:int,total_resolus:int} */
    public function statsDossiersPourResponsable(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT f.id) AS total_dossiers,
                    COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_resolus
             FROM missions_recherche m
             JOIN formulaires_manquants f ON f.id = m.formulaire_id AND f.est_archive = 0
             JOIN statuts s ON s.id = f.statut_id
             WHERE m.responsable_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total_dossiers' => (int) ($row['total_dossiers'] ?? 0),
            'total_resolus' => (int) ($row['total_resolus'] ?? 0),
        ];
    }

    public function aDesMissionsActives(int $formulaireId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             WHERE m.formulaire_id = :formulaire_id
               AND m.etat IN ('affectee','en_cours')"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function aUnResultatAVerifier(int $formulaireId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             WHERE m.formulaire_id = :formulaire_id
               AND m.etat = 'terminee'
               AND m.resultat_code = 'a_verifier'"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function autresActives(int $formulaireId, int $missionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, CONCAT_WS(' ', u.nom, u.prenoms) AS responsable_nom
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             JOIN utilisateurs u ON u.id = m.responsable_id
             WHERE m.formulaire_id = :formulaire_id AND m.id <> :mission_id
               AND m.etat IN ('affectee','en_cours') FOR UPDATE"
        );
        $stmt->execute(['formulaire_id' => $formulaireId, 'mission_id' => $missionId]);
        return $stmt->fetchAll();
    }

    public function annulerAutresActives(int $formulaireId, int $missionTrouveeId, int $acteurId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE missions_recherche
             SET etat = 'annulee', date_cloture = NOW(), cloture_par = :acteur_id,
                 motif_annulation = 'Formulaire retrouve par une autre mission'
             WHERE formulaire_id = :formulaire_id
               AND id <> :mission_id
               AND cycle_suivi = (
                   SELECT cycle_suivi FROM formulaires_manquants WHERE id = :formulaire_cycle_id
               )
               AND etat IN ('affectee','en_cours')"
        );
        $stmt->execute([
            'acteur_id' => $acteurId,
            'formulaire_id' => $formulaireId,
            'formulaire_cycle_id' => $formulaireId,
            'mission_id' => $missionTrouveeId,
        ]);
        return $stmt->rowCount();
    }

    public function annulerToutesActives(int $formulaireId, int $acteurId, string $motif): int
    {
        $stmt = $this->db->prepare(
            "UPDATE missions_recherche
             SET etat = 'annulee', date_cloture = NOW(), cloture_par = :acteur_id,
                 motif_annulation = :motif
             WHERE formulaire_id = :formulaire_id
               AND etat IN ('affectee','en_cours')"
        );
        $stmt->execute([
            'acteur_id' => $acteurId,
            'motif' => $motif,
            'formulaire_id' => $formulaireId,
        ]);
        return $stmt->rowCount();
    }

    public function annulerMission(int $missionId, int $actorId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE missions_recherche
             SET etat = 'annulee',
                 date_cloture = NOW(),
                 cloture_par = :acteur_id,
                 motif_annulation = :motif
             WHERE id = :id
               AND etat IN ('affectee','en_cours')"
        );
        $stmt->execute([
            'acteur_id' => $actorId,
            'motif' => $reason,
            'id' => $missionId,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function premiereActive(int $formulaireId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             WHERE m.formulaire_id = :formulaire_id
               AND m.etat IN ('affectee','en_cours')
             ORDER BY FIELD(m.priorite, 'Urgente','Haute','Normale','Basse'),
                      m.date_echeance IS NULL, m.date_echeance, m.id
             LIMIT 1"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function derniereTerminee(int $formulaireId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             WHERE m.formulaire_id = :formulaire_id
               AND m.etat = 'terminee'
             ORDER BY m.date_cloture DESC, m.id DESC
             LIMIT 1"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function estResponsableDuFormulaire(int $formulaireId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id AND f.cycle_suivi = m.cycle_suivi
             WHERE m.formulaire_id = :formulaire_id
               AND m.responsable_id = :responsable_id
               AND m.etat IN ('affectee','en_cours')"
        );
        $stmt->execute([
            'formulaire_id' => $formulaireId,
            'responsable_id' => $userId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
