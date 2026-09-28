<?php
declare(strict_types=1);

class ConnexionModel extends Model
{
    protected string $table = 'connexions';

    public function historique(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $minutes = sessionLifetimeMinutes();
        $sql = "SELECT c.*, CONCAT(u.nom, ' ', u.prenoms) AS utilisateur_nom, u.identifiant,
                       CASE
                           WHEN c.statut = 'termine' THEN 'termine'
                           WHEN c.derniere_activite >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE) THEN 'actif'
                           ELSE 'expire'
                       END AS statut_effectif,
                       GREATEST(0, CASE
                           WHEN c.statut = 'termine' THEN COALESCE(c.duree_secondes, TIMESTAMPDIFF(SECOND, c.connecte_le, COALESCE(c.deconnecte_le, c.derniere_activite)))
                           WHEN c.derniere_activite < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE) THEN TIMESTAMPDIFF(SECOND, c.connecte_le, c.derniere_activite)
                           ELSE TIMESTAMPDIFF(SECOND, c.connecte_le, NOW())
                       END) AS duree_effective_secondes
                FROM connexions c
                JOIN utilisateurs u ON u.id = c.utilisateur_id
                {$where}
                ORDER BY c.connecte_le DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function historiqueCount(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM connexions c
             JOIN utilisateurs u ON u.id = c.utilisateur_id
             {$where}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{total:int,actives:int,aujourdhui:int,utilisateurs_aujourdhui:int,expirees:int} */
    public function statsHistorique(): array
    {
        $minutes = sessionLifetimeMinutes();
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(statut = 'actif' AND derniere_activite >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)), 0) AS actives,
                    COALESCE(SUM(connecte_le >= CURDATE()), 0) AS aujourd_hui,
                    COUNT(DISTINCT CASE WHEN connecte_le >= CURDATE() THEN utilisateur_id END) AS utilisateurs_aujourdhui,
                    COALESCE(SUM(statut = 'actif' AND derniere_activite < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)), 0) AS expirees
             FROM connexions"
        )->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'actives' => (int) ($row['actives'] ?? 0),
            'aujourdhui' => (int) ($row['aujourd_hui'] ?? 0),
            'utilisateurs_aujourdhui' => (int) ($row['utilisateurs_aujourdhui'] ?? 0),
            'expirees' => (int) ($row['expirees'] ?? 0),
        ];
    }

    /**
     * Evolution quotidienne recente, completee avec les jours sans connexion.
     *
     * @return array<int,array{date:string,connexions:int,utilisateurs:int}>
     */
    public function tendanceRecente(int $jours = 7): array
    {
        $jours = max(3, min(31, $jours));
        $debut = (new DateTimeImmutable('today'))->modify('-' . ($jours - 1) . ' days');
        $stmt = $this->db->prepare(
            'SELECT DATE(connecte_le) AS jour,
                    COUNT(*) AS connexions,
                    COUNT(DISTINCT utilisateur_id) AS utilisateurs
             FROM connexions
             WHERE connecte_le >= :debut
             GROUP BY DATE(connecte_le)
             ORDER BY jour'
        );
        $stmt->execute(['debut' => $debut->format('Y-m-d 00:00:00')]);

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[(string) $row['jour']] = [
                'connexions' => (int) $row['connexions'],
                'utilisateurs' => (int) $row['utilisateurs'],
            ];
        }

        $trend = [];
        for ($offset = 0; $offset < $jours; $offset++) {
            $date = $debut->modify('+' . $offset . ' days')->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'connexions' => (int) ($values[$date]['connexions'] ?? 0),
                'utilisateurs' => (int) ($values[$date]['utilisateurs'] ?? 0),
            ];
        }
        return $trend;
    }

    public function total(): int
    {
        return $this->count();
    }

    /** @return array{0:string,1:array<string,int|string>} */
    private function buildWhere(array $filters): array
    {
        $minutes = sessionLifetimeMinutes();
        $conditions = [];
        $params = [];

        if (!empty($filters['utilisateur_id'])) {
            $conditions[] = 'c.utilisateur_id = :utilisateur_id';
            $params['utilisateur_id'] = (int) $filters['utilisateur_id'];
        }
        if (!empty($filters['statut'])) {
            if ($filters['statut'] === 'actif') {
                $conditions[] = "c.statut = 'actif' AND c.derniere_activite >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)";
            } elseif ($filters['statut'] === 'expire') {
                $conditions[] = "c.statut = 'actif' AND c.derniere_activite < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)";
            } elseif ($filters['statut'] === 'termine') {
                $conditions[] = "c.statut = 'termine'";
            }
        }
        if (!empty($filters['date_debut'])) {
            $conditions[] = 'c.connecte_le >= :date_debut';
            $params['date_debut'] = $filters['date_debut'] . ' 00:00:00';
        }
        if (!empty($filters['date_fin'])) {
            $conditions[] = 'c.connecte_le <= :date_fin';
            $params['date_fin'] = $filters['date_fin'] . ' 23:59:59';
        }
        if (!empty($filters['mot_cle'])) {
            // Les requetes preparees natives MySQL n'acceptent pas la
            // reutilisation d'un meme parametre nomme plusieurs fois.
            $conditions[] = '(u.nom LIKE :mot_cle_nom OR u.prenoms LIKE :mot_cle_prenoms
                              OR u.identifiant LIKE :mot_cle_identifiant OR c.adresse_ip LIKE :mot_cle_ip
                              OR c.navigateur LIKE :mot_cle_navigateur)';
            $keyword = '%' . mb_substr(trim((string) $filters['mot_cle']), 0, 100) . '%';
            $params['mot_cle_nom'] = $keyword;
            $params['mot_cle_prenoms'] = $keyword;
            $params['mot_cle_identifiant'] = $keyword;
            $params['mot_cle_ip'] = $keyword;
            $params['mot_cle_navigateur'] = $keyword;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function dernieresConnexions(int $limit = 5, ?int $userId = null): array
    {
        $minutes = sessionLifetimeMinutes();
        $sql = "SELECT c.*, CONCAT(u.nom, ' ', u.prenoms) AS utilisateur_nom,
                       CASE
                           WHEN c.statut = 'termine' THEN 'termine'
                           WHEN c.derniere_activite >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE) THEN 'actif'
                           ELSE 'expire'
                       END AS statut_effectif
                FROM connexions c JOIN utilisateurs u ON u.id = c.utilisateur_id";
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE c.utilisateur_id = :uid';
            $params['uid'] = $userId;
        }
        $sql .= ' ORDER BY c.connecte_le DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
