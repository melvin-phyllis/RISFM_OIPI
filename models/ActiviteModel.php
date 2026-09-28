<?php
declare(strict_types=1);

class ActiviteModel extends Model
{
    protected string $table = 'activites';

    /**
     * Retourne uniquement les actions metier rattachees a un dossier.
     * Les actions d'une mission sont retrouvees par la relation mission -> formulaire.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pourFormulaire(int $formulaireId, int $limit = 150): array
    {
        $limit = max(1, min(300, $limit));
        $stmt = $this->db->prepare(
            "SELECT a.*,
                    COALESCE(NULLIF(a.acteur_nom, ''), NULLIF(CONCAT_WS(' ', au.nom, au.prenoms), '')) AS acteur_nom_affiche,
                    m.formulaire_id AS mission_formulaire_id,
                    m.mission_parent_id,
                    m.priorite AS mission_priorite,
                    m.date_echeance AS mission_echeance,
                    l.libelle AS mission_localisation,
                    NULLIF(CONCAT_WS(' ', ru.nom, ru.prenoms), '') AS mission_responsable,
                    NULLIF(CONCAT_WS(' ', pru.nom, pru.prenoms), '') AS mission_ancien_responsable
             FROM activites a
             LEFT JOIN utilisateurs au ON au.id = a.utilisateur_id
             LEFT JOIN missions_recherche m
               ON a.entite_type = 'mission_recherche' AND m.id = a.entite_id
             LEFT JOIN localisations l ON l.id = m.localisation_id
             LEFT JOIN utilisateurs ru ON ru.id = m.responsable_id
             LEFT JOIN missions_recherche pm ON pm.id = m.mission_parent_id
             LEFT JOIN utilisateurs pru ON pru.id = pm.responsable_id
             WHERE (
                    (a.entite_type = 'formulaire' AND a.entite_id = :formulaire_direct)
                    OR (a.entite_type = 'mission_recherche' AND m.formulaire_id = :formulaire_mission)
                   )
               AND a.type_action IN (
                    'ajout', 'modification', 'changement_statut', 'affectation',
                    'annulation_mission', 'reaffectation', 'recherche', 'finalisation',
                    'reouverture', 'archivage', 'restauration', 'suppression'
               )
             ORDER BY a.cree_le DESC, a.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':formulaire_direct', $formulaireId, PDO::PARAM_INT);
        $stmt->bindValue(':formulaire_mission', $formulaireId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recentes(int $limit = 10, ?int $userId = null): array
    {
        $sql = "SELECT a.*,
                       COALESCE(NULLIF(CONCAT_WS(' ', u.nom, u.prenoms), ''), a.acteur_nom) AS utilisateur_nom,
                       COALESCE(u.identifiant, a.acteur_identifiant) AS utilisateur_identifiant
                FROM activites a
                LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id";
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.utilisateur_id = :uid';
            $params['uid'] = $userId;
        }
        $sql .= ' ORDER BY a.cree_le DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = "SELECT a.*,
                       COALESCE(NULLIF(CONCAT_WS(' ', u.nom, u.prenoms), ''), a.acteur_nom) AS utilisateur_nom,
                       COALESCE(u.identifiant, a.acteur_identifiant) AS utilisateur_identifiant
                FROM activites a
                LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
                {$where}
                ORDER BY a.cree_le DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithActor(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*,
                    COALESCE(NULLIF(CONCAT_WS(' ', u.nom, u.prenoms), ''), a.acteur_nom) AS utilisateur_nom,
                    COALESCE(u.identifiant, a.acteur_identifiant) AS utilisateur_identifiant
             FROM activites a
             LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
             WHERE a.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array{type_action:string,total:int}> */
    public function typesDisponibles(): array
    {
        $rows = $this->db->query(
            'SELECT type_action, COUNT(*) AS total
             FROM activites
             GROUP BY type_action
             ORDER BY type_action'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'type_action' => (string) $row['type_action'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /** @return array{total:int,aujourdhui:int,acteurs_aujourdhui:int,echecs_24h:int,sensibles_24h:int} */
    public function statsJournal(): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(cree_le >= CURDATE()), 0) AS aujourd_hui,
                    COUNT(DISTINCT CASE WHEN cree_le >= CURDATE() THEN COALESCE(acteur_id, utilisateur_id) END) AS acteurs_aujourdhui,
                    COALESCE(SUM(
                        cree_le >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND type_action IN ('connexion_echouee','connexion_refusee')
                    ), 0) AS echecs_24h,
                    COALESCE(SUM(
                        cree_le >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND type_action IN (
                            'suppression', 'archivage', 'restauration',
                            'securite', 'desactivation'
                        )
                    ), 0) AS sensibles_24h
             FROM activites"
        )->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'aujourdhui' => (int) ($row['aujourd_hui'] ?? 0),
            'acteurs_aujourdhui' => (int) ($row['acteurs_aujourdhui'] ?? 0),
            'echecs_24h' => (int) ($row['echecs_24h'] ?? 0),
            'sensibles_24h' => (int) ($row['sensibles_24h'] ?? 0),
        ];
    }

    public function searchCount(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = "SELECT COUNT(*) AS n FROM activites a {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['n'];
    }

    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];
        if (!empty($filters['type_action'])) {
            $conditions[] = 'a.type_action = :type_action';
            $params['type_action'] = $filters['type_action'];
        }
        if (!empty($filters['utilisateur_id'])) {
            $conditions[] = 'a.utilisateur_id = :utilisateur_id';
            $params['utilisateur_id'] = (int) $filters['utilisateur_id'];
        }
        if (!empty($filters['date_debut'])) {
            $conditions[] = 'a.cree_le >= :date_debut';
            $params['date_debut'] = $filters['date_debut'] . ' 00:00:00';
        }
        if (!empty($filters['date_fin'])) {
            $conditions[] = 'a.cree_le <= :date_fin';
            $params['date_fin'] = $filters['date_fin'] . ' 23:59:59';
        }
        if (!empty($filters['mot_cle'])) {
            $conditions[] = '(a.description LIKE :mot_cle
                              OR a.acteur_nom LIKE :mot_cle
                              OR a.acteur_identifiant LIKE :mot_cle
                              OR a.entite_type LIKE :mot_cle)';
            $params['mot_cle'] = '%' . mb_substr(trim((string) $filters['mot_cle']), 0, 100) . '%';
        }
        $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
        return [$where, $params];
    }
}
