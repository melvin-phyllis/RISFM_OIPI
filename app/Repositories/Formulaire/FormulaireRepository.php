<?php
declare(strict_types=1);

namespace App\Repositories\Formulaire;

use App\Core\Repository;
use App\Repositories\Referentiel\StatutRepository;
use DateTimeImmutable;
use Generator;
use PDO;
use RuntimeException;

class FormulaireRepository extends Repository
{
    protected string $table = 'formulaires_manquants';

    /**
     * Normalise le perimetre partage par la page Statistiques et son export.
     * Les valeurs inconnues sont ignorees ; une periode inversee est signalee
     * pour eviter qu'un export paraisse vide sans explication.
     *
     * @return array{filters:array<string,int|string>,error:?string}
     */
    public static function repo_normaliserFiltresStatistiques(array $raw): array
    {
        $positiveInteger = static function (mixed $value): int {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            return $validated !== false && $validated > 0 ? (int) $validated : 0;
        };
        $validDate = static function (mixed $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
        };

        $filters = [
            'annee' => $positiveInteger($raw['annee'] ?? 0),
            'type_titre_id' => $positiveInteger($raw['type_titre_id'] ?? 0),
            'statut_id' => $positiveInteger($raw['statut_id'] ?? 0),
            'responsable_id' => $positiveInteger($raw['responsable_id'] ?? 0),
            'localisation_id' => $positiveInteger($raw['localisation_id'] ?? 0),
            'priorite' => '',
            'date_debut' => $validDate($raw['date_debut'] ?? ''),
            'date_fin' => $validDate($raw['date_fin'] ?? ''),
        ];

        $priority = trim((string) ($raw['priorite'] ?? ''));
        if (in_array($priority, ['Basse', 'Normale', 'Haute', 'Urgente'], true)) {
            $filters['priorite'] = $priority;
        }

        $error = null;
        if (
            $filters['date_debut'] !== ''
            && $filters['date_fin'] !== ''
            && $filters['date_debut'] > $filters['date_fin']
        ) {
            $filters['date_debut'] = '';
            $filters['date_fin'] = '';
            $error = 'La date de debut doit etre anterieure ou egale a la date de fin. Le filtre de periode a ete ignore.';
        }

        return ['filters' => $filters, 'error' => $error];
    }

    public function repo_insert(array $data): int
    {
        // La date est determinee par le statut et jamais acceptee depuis un
        // formulaire HTTP. Un dossier cree directement comme resolu obtient sa
        // premiere date de resolution au moment de sa creation.
        unset($data['date_resolution']);
        if ($this->statusIsResolved((int) ($data['statut_id'] ?? 0))) {
            $data['date_resolution'] = date('Y-m-d H:i:s');
        }
        return parent::repo_insert($data);
    }

    public function repo_update(int $id, array $data): bool
    {
        // Une date deja renseignee est immuable. Lors du premier passage vers
        // un statut resolu, MySQL la fixe atomiquement afin que deux requetes
        // concurrentes ne puissent pas la deplacer.
        unset($data['date_resolution']);
        if (!array_key_exists('statut_id', $data)) {
            return parent::repo_update($id, $data);
        }

        $sets = array_map(fn (string $column): string => "{$column} = :{$column}", array_keys($data));
        $sets[] = 'date_resolution = CASE
            WHEN date_resolution IS NOT NULL THEN date_resolution
            WHEN EXISTS (SELECT 1 FROM statuts sr WHERE sr.id = :__resolution_statut_id AND sr.resolu = 1) THEN NOW()
            ELSE NULL
        END';
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE {$this->primaryKey} = :__id";
        $data['__resolution_statut_id'] = (int) $data['statut_id'];
        $data['__id'] = $id;
        return $this->db->prepare($sql)->execute($data);
    }

    public function repo_findWithRelations(int $id): ?array
    {
        $sql = "SELECT f.*, t.libelle AS type_libelle, s.code AS statut_code,
                       s.libelle AS statut_libelle, s.couleur AS statut_couleur,
                       s.resolu AS statut_resolu,
                       l.libelle AS localisation_libelle,
                       CONCAT(u.nom, ' ', u.prenoms) AS responsable_nom,
                       CONCAT(c.nom, ' ', c.prenoms) AS cree_par_nom,
                       CONCAT(a.nom, ' ', a.prenoms) AS archive_par_nom
                FROM formulaires_manquants f
                JOIN types_titres t ON t.id = f.type_titre_id
                JOIN statuts s ON s.id = f.statut_id
                LEFT JOIN localisations l ON l.id = f.localisation_id
                LEFT JOIN utilisateurs u ON u.id = f.responsable_id
                LEFT JOIN utilisateurs c ON c.id = f.cree_par
                LEFT JOIN utilisateurs a ON a.id = f.archive_par
                WHERE f.id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Verrouille la ligne du formulaire jusqu'a la fin de la transaction en
     * cours et la complete avec son statut (statut_code, statut_libelle,
     * statut_resolu). Seule la ligne du formulaire est verrouillee : les
     * statuts sont une table de reference partagee par tous les dossiers.
     */
    public function repo_verrouiller(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $statut = (new StatutRepository())->repo_find((int) $row['statut_id']);
        $row['statut_code'] = $statut['code'] ?? null;
        $row['statut_libelle'] = $statut['libelle'] ?? null;
        $row['statut_resolu'] = (int) ($statut['resolu'] ?? 0);
        return $row;
    }

    public function repo_generateNumeroAuto(int $annee): string
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n FROM formulaires_manquants WHERE annee = :annee"
        );
        $stmt->execute(['annee' => $annee]);
        $n = (int) $stmt->fetch()['n'] + 1;

        do {
            $candidate = sprintf('FM-%d-%06d', $annee, $n);
            $check = $this->db->prepare('SELECT COUNT(*) AS n FROM formulaires_manquants WHERE numero_auto = :na');
            $check->execute(['na' => $candidate]);
            $exists = ((int) $check->fetch()['n']) > 0;
            $n++;
        } while ($exists);

        return $candidate;
    }

    /**
     * Reserve et insere atomiquement la prochaine reference d'une annee.
     * Le verrou nomme MySQL evite que deux requetes simultanees produisent la
     * meme reference, y compris lorsqu'aucune ligne n'existe encore.
     *
     * @return array{id:int, numero_auto:string}
     */
    public function repo_insertWithGeneratedNumero(array $data): array
    {
        $annee = (int) ($data['annee'] ?? 0);
        $lockName = 'risfm_numero_' . $annee;
        $lock = $this->db->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lock->execute(['lock_name' => $lockName]);

        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Impossible de reserver la prochaine reference de formulaire.');
        }

        try {
            $prefix = sprintf('FM-%d-', $annee);
            $numberStart = strlen($prefix) + 1;
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(numero_auto, {$numberStart}) AS UNSIGNED)), 0)
                 FROM formulaires_manquants
                 WHERE numero_auto LIKE :prefix"
            );
            $stmt->execute(['prefix' => $prefix . '%']);
            $next = (int) $stmt->fetchColumn() + 1;

            $data['numero_auto'] = sprintf('%s%06d', $prefix, $next);
            $id = $this->repo_insert($data);

            return ['id' => $id, 'numero_auto' => $data['numero_auto']];
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }

    /**
     * Detecte le doublon metier protege aussi par uk_fm_annee_type_numero.
     * Le controle applicatif permet un message clair ; la contrainte SQL reste
     * indispensable pour couvrir deux requetes concurrentes.
     */
    public function repo_duplicateExists(
        int $annee,
        int $typeTitreId,
        string $numeroFormulaire,
        ?int $excludeId = null
    ): bool {
        $sql = 'SELECT COUNT(*)
                FROM formulaires_manquants
                WHERE annee = :annee
                  AND type_titre_id = :type_titre_id
                  AND numero_formulaire = :numero_formulaire';
        $params = [
            'annee' => $annee,
            'type_titre_id' => $typeTitreId,
            'numero_formulaire' => $numeroFormulaire,
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Construit une requete filtree reutilisee par le listing DataTables,
     * la recherche avancee et les exports.
     *
     * @return array{sql: string, params: array}
     */
    public function repo_buildFilterQuery(array $filters, ?array $currentUser = null): array
    {
        $conditions = [!empty($filters['archives_uniquement']) ? 'f.est_archive = 1' : 'f.est_archive = 0'];
        $params = [];

        if (!empty($filters['annee'])) {
            $conditions[] = 'f.annee = :annee';
            $params['annee'] = (int) $filters['annee'];
        }
        if (!empty($filters['type_titre_id'])) {
            $conditions[] = 'f.type_titre_id = :type_titre_id';
            $params['type_titre_id'] = (int) $filters['type_titre_id'];
        }
        if (!empty($filters['statut_id'])) {
            $conditions[] = 'f.statut_id = :statut_id';
            $params['statut_id'] = (int) $filters['statut_id'];
        }
        if (!empty($filters['responsable_id'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM missions_recherche mr_filtre
                                      WHERE mr_filtre.formulaire_id = f.id
                                        AND mr_filtre.responsable_id = :responsable_id)';
            $params['responsable_id'] = (int) $filters['responsable_id'];
        }
        if (!empty($filters['numero_formulaire'])) {
            $conditions[] = 'f.numero_formulaire LIKE :numero_formulaire';
            $params['numero_formulaire'] = '%' . $filters['numero_formulaire'] . '%';
        }
        if (!empty($filters['date_debut'])) {
            $conditions[] = 'f.date_recherche >= :date_debut';
            $params['date_debut'] = $filters['date_debut'];
        }
        if (!empty($filters['date_fin'])) {
            $conditions[] = 'f.date_recherche <= :date_fin';
            $params['date_fin'] = $filters['date_fin'];
        }
        if (!empty($filters['mot_cle'])) {
            $conditions[] = '(f.deposant LIKE :mot_cle_deposant
                              OR f.mandataire LIKE :mot_cle_mandataire
                              OR f.observations LIKE :mot_cle_observations
                              OR f.resultat LIKE :mot_cle_resultat)';
            $keyword = '%' . $filters['mot_cle'] . '%';
            $params['mot_cle_deposant'] = $keyword;
            $params['mot_cle_mandataire'] = $keyword;
            $params['mot_cle_observations'] = $keyword;
            $params['mot_cle_resultat'] = $keyword;
        }

        // Un agent ne voit / ne filtre que ses propres dossiers pour l'edition,
        // mais la consultation en lecture reste globale (gere au niveau controleur).
        if (!empty($filters['mes_dossiers']) && $currentUser) {
            $conditions[] = 'EXISTS (SELECT 1 FROM missions_recherche mr_moi
                                      WHERE mr_moi.formulaire_id = f.id
                                        AND mr_moi.responsable_id = :mes_dossiers_id)';
            $params['mes_dossiers_id'] = (int) $currentUser['id'];
        }

        $sql = "SELECT f.*, t.libelle AS type_libelle, s.code AS statut_code,
                       s.libelle AS statut_libelle, s.couleur AS statut_couleur,
                       s.resolu AS statut_resolu,
                       l.libelle AS localisation_libelle,
                       CONCAT(u.nom, ' ', u.prenoms) AS responsable_nom,
                       (SELECT mt.resultat_code
                          FROM missions_recherche mt
                         WHERE mt.formulaire_id = f.id
                           AND mt.cycle_suivi = f.cycle_suivi
                           AND mt.etat = 'terminee'
                         ORDER BY mt.date_cloture DESC, mt.id DESC
                         LIMIT 1) AS dernier_resultat_code,
                       (SELECT COUNT(*) FROM missions_recherche ma
                         WHERE ma.formulaire_id = f.id AND ma.etat IN ('affectee','en_cours')) AS missions_actives_count,
                       (SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ', mu.nom, mu.prenoms) ORDER BY mu.nom SEPARATOR ', ')
                          FROM missions_recherche mm JOIN utilisateurs mu ON mu.id = mm.responsable_id
                         WHERE mm.formulaire_id = f.id AND mm.etat IN ('affectee','en_cours')) AS responsables_actifs,
                       (SELECT GROUP_CONCAT(DISTINCT ml.libelle ORDER BY ml.libelle SEPARATOR ', ')
                          FROM missions_recherche mm JOIN localisations ml ON ml.id = mm.localisation_id
                         WHERE mm.formulaire_id = f.id AND mm.etat IN ('affectee','en_cours')) AS localisations_actives,
                       CONCAT(a.nom, ' ', a.prenoms) AS archive_par_nom
                FROM formulaires_manquants f
                JOIN types_titres t ON t.id = f.type_titre_id
                JOIN statuts s ON s.id = f.statut_id
                LEFT JOIN localisations l ON l.id = f.localisation_id
                LEFT JOIN utilisateurs u ON u.id = f.responsable_id
                LEFT JOIN utilisateurs a ON a.id = f.archive_par";

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    public function repo_search(array $filters, ?array $currentUser = null, string $orderBy = 'f.mis_a_jour_le', string $direction = 'DESC', int $limit = 0, int $offset = 0): array
    {
        $built = $this->repo_buildFilterQuery($filters, $currentUser);
        $sql = $built['sql'] . ' ORDER BY ' . $this->safeColumn($orderBy) . ' ' . (strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC');

        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);
        foreach ($built['params'] as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function repo_searchCount(array $filters, ?array $currentUser = null): int
    {
        $built = $this->repo_buildFilterQuery($filters, $currentUser);
        // Ne pas compter depuis le SELECT complet du tableau : celui-ci calcule
        // trois agregats de missions par dossier, inutiles pour un COUNT(*).
        $mainFrom = "\n                FROM formulaires_manquants f";
        $fromPosition = strpos($built['sql'], $mainFrom);
        if ($fromPosition === false) {
            throw new RuntimeException('Requete de comptage du registre invalide.');
        }
        $countSql = 'SELECT COUNT(*) AS n' . substr($built['sql'], $fromPosition);
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($built['params']);
        return (int) $stmt->fetch()['n'];
    }

    /**
     * Parcourt tout le registre filtre sans charger toutes les lignes en RAM.
     *
     * La pagination par curseur (annee DESC, id ASC) reste stable et evite le
     * cout croissant de OFFSET. L'appelant peut ouvrir une transaction en
     * REPEATABLE READ pour garantir que le total et les lots voient le meme
     * instantane de la base pendant un export.
     *
     * @return Generator<int, array>
     */
    public function repo_iterateForExport(
        array $filters,
        ?array $currentUser = null,
        int $batchSize = 500
    ): Generator {
        $batchSize = max(1, min(2000, $batchSize));
        $built = $this->repo_buildFilterQuery($filters, $currentUser);
        $lastYear = null;
        $lastId = null;

        do {
            $sql = $built['sql'];
            if ($lastYear !== null && $lastId !== null) {
                $sql .= ' AND (f.annee < :cursor_annee_before
                           OR (f.annee = :cursor_annee_same AND f.id > :cursor_id))';
            }
            $sql .= ' ORDER BY f.annee DESC, f.id ASC LIMIT :batch_size';

            $stmt = $this->db->prepare($sql);
            foreach ($built['params'] as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            if ($lastYear !== null && $lastId !== null) {
                $stmt->bindValue(':cursor_annee_before', $lastYear, PDO::PARAM_INT);
                $stmt->bindValue(':cursor_annee_same', $lastYear, PDO::PARAM_INT);
                $stmt->bindValue(':cursor_id', $lastId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                yield $row;
            }

            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            if ($last !== null) {
                $lastYear = (int) $last['annee'];
                $lastId = (int) $last['id'];
            }
            $rowCount = count($rows);
            unset($rows);
        } while ($rowCount === $batchSize);
    }

    public function repo_kpiGlobaux(): array
    {
        $minutes = (int) ($this->db->query(
            "SELECT COALESCE(MAX(CASE WHEN cle = 'session_lifetime_minutes' THEN CAST(valeur AS UNSIGNED) END), 20)
             FROM parametres"
        )->fetchColumn() ?: 20);
        $minutes = max(5, min(120, $minutes));

        // Requete applicative volontairement autonome : le tableau de bord ne
        // depend pas des privileges necessaires pour recreer une procedure SQL
        // appartenant a root lors d'une mise a niveau.
        $stmt = $this->db->query(
            "SELECT
                (SELECT COUNT(*) FROM formulaires_manquants WHERE est_archive = 0) AS total_formulaires,
                (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                  WHERE f.est_archive = 0 AND s.resolu = 1) AS total_retrouves,
                (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                  WHERE f.est_archive = 0 AND s.resolu = 0) AS total_restants,
                (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                  WHERE f.est_archive = 0 AND s.code IN ('numerise','saisi')) AS total_numerises,
                (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                  WHERE f.est_archive = 0 AND s.code = 'saisi') AS total_saisis,
                (SELECT COUNT(*)
                   FROM formulaires_manquants f
                   JOIN statuts s ON s.id = f.statut_id
                  WHERE f.est_archive = 0
                    AND s.resolu = 0
                    AND NOT EXISTS (
                        SELECT 1 FROM missions_recherche m
                         WHERE m.formulaire_id = f.id
                           AND m.cycle_suivi = f.cycle_suivi
                           AND m.etat IN ('affectee','en_cours')
                    )) AS total_non_assignes,
                (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
                (SELECT COUNT(DISTINCT utilisateur_id) FROM connexions
                  WHERE statut = 'actif'
                    AND derniere_activite >= (NOW() - INTERVAL {$minutes} MINUTE)) AS total_connectes"
        );
        $row = $stmt->fetch();
        return $row ?: [
            'total_formulaires' => 0, 'total_retrouves' => 0, 'total_restants' => 0,
            'total_numerises' => 0, 'total_saisis' => 0,
            'total_non_assignes' => 0,
            'total_utilisateurs_actifs' => 0, 'total_connectes' => 0,
        ];
    }

    /**
     * Statistiques detaillees construites directement depuis les tables metier.
     *
     * Les vues historiques restent utilisees par le tableau de bord pour la
     * compatibilite, mais elles ne peuvent pas recevoir de filtres. Cette
     * methode applique le meme perimetre a toutes les series de la page
     * Statistiques. Les indicateurs principaux restent centres sur les
     * formulaires ; seuls quatre compteurs de missions expliquent les dossiers
     * encore a traiter.
     *
     * @return array{
     *     kpi:array,
     *     par_annee:array,
     *     par_type:array,
     *     par_statut:array,
     *     mensuelles:array,
     *     ajouts_mensuels:array,
     *     anciennete_stock:array,
     *     missions:array,
     *     par_responsable:array,
     *     par_localisation:array
     * }
     */
    public function repo_statistiquesDetaillees(array $filters = []): array
    {
        $scope = $this->buildStatisticsScope($filters);
        $where = $scope['where'];
        $params = $scope['params'];

        $kpi = $this->statisticsRow(
            "SELECT
                COUNT(DISTINCT f.id) AS total_formulaires,
                COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_resolus,
                COUNT(DISTINCT CASE WHEN s.resolu = 0 THEN f.id END) AS total_restants,
                COUNT(DISTINCT CASE WHEN s.code IN ('retrouve','numerise','saisi') THEN f.id END) AS total_retrouves,
                COUNT(DISTINCT CASE WHEN s.code IN ('numerise','saisi') THEN f.id END) AS total_numerises,
                COUNT(DISTINCT CASE WHEN s.code = 'saisi' THEN f.id END) AS total_saisis,
                COUNT(DISTINCT CASE
                    WHEN s.resolu = 0 AND f.priorite = 'Urgente' THEN f.id
                END) AS dossiers_urgents,
                COUNT(DISTINCT CASE
                    WHEN s.resolu = 0 AND NOT EXISTS (
                        SELECT 1
                        FROM missions_recherche ma
                        WHERE ma.formulaire_id = f.id
                          AND ma.cycle_suivi = f.cycle_suivi
                          AND ma.etat IN ('affectee','en_cours')
                    ) THEN f.id
                END) AS dossiers_non_assignes,
                COALESCE(SUM((
                    SELECT COUNT(*)
                    FROM missions_recherche ma
                    WHERE ma.formulaire_id = f.id
                      AND ma.cycle_suivi = f.cycle_suivi
                      AND ma.etat IN ('affectee','en_cours')
                )), 0) AS missions_actives,
                COALESCE(SUM((
                    SELECT COUNT(*)
                    FROM missions_recherche mr
                    WHERE mr.formulaire_id = f.id
                      AND mr.cycle_suivi = f.cycle_suivi
                      AND mr.etat IN ('affectee','en_cours')
                      AND mr.date_echeance IS NOT NULL
                      AND mr.date_echeance < CURDATE()
                )), 0) AS missions_en_retard,
                ROUND(AVG(CASE
                    WHEN f.date_resolution IS NOT NULL
                    THEN TIMESTAMPDIFF(DAY, f.cree_le, f.date_resolution)
                END), 1) AS delai_moyen_resolution
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}",
            $params
        );

        $parAnnee = $this->statisticsRows(
            "SELECT
                f.annee,
                COUNT(DISTINCT f.id) AS total_formulaires,
                COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_resolus,
                COUNT(DISTINCT CASE WHEN s.resolu = 0 THEN f.id END) AS total_restants,
                ROUND(
                    COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END)
                    / NULLIF(COUNT(DISTINCT f.id), 0) * 100,
                    1
                ) AS taux_resolution
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}
             GROUP BY f.annee
             ORDER BY f.annee DESC",
            $params
        );

        $parType = $this->statisticsRows(
            "SELECT
                t.id AS type_titre_id,
                t.libelle AS type_titre,
                COUNT(DISTINCT f.id) AS total_formulaires,
                COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_resolus,
                COUNT(DISTINCT CASE WHEN s.resolu = 0 THEN f.id END) AS total_restants,
                ROUND(
                    COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END)
                    / NULLIF(COUNT(DISTINCT f.id), 0) * 100,
                    1
                ) AS taux_resolution
             FROM formulaires_manquants f
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}
             GROUP BY t.id, t.libelle
             ORDER BY total_formulaires DESC, t.libelle ASC",
            $params
        );

        $parStatut = $this->statisticsRows(
            "SELECT
                s.id AS statut_id,
                s.code AS statut_code,
                s.libelle AS statut,
                s.couleur,
                COUNT(DISTINCT f.id) AS total_formulaires
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}
             GROUP BY s.id, s.code, s.libelle, s.couleur, s.ordre
             ORDER BY s.ordre ASC, s.libelle ASC",
            $params
        );

        $mensuelles = $this->statisticsRows(
            "SELECT
                DATE_FORMAT(f.date_resolution, '%Y-%m') AS mois,
                COUNT(DISTINCT f.id) AS resolutions_dans_le_mois
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}
               AND f.date_resolution IS NOT NULL
             GROUP BY DATE_FORMAT(f.date_resolution, '%Y-%m')
             ORDER BY mois ASC",
            $params
        );

        $ajoutsMensuels = $this->statisticsRows(
            "SELECT
                DATE_FORMAT(f.cree_le, '%Y-%m') AS mois,
                COUNT(DISTINCT f.id) AS ajouts_dans_le_mois
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where}
             GROUP BY DATE_FORMAT(f.cree_le, '%Y-%m')
             ORDER BY mois ASC",
            $params
        );

        $ancienneteStock = $this->statisticsRows(
            "SELECT
                CASE
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 2 THEN '0-2 ans'
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 5 THEN '3-5 ans'
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 10 THEN '6-10 ans'
                    ELSE 'Plus de 10 ans'
                END AS tranche,
                CASE
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 2 THEN 1
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 5 THEN 2
                    WHEN GREATEST(YEAR(CURDATE()) - f.annee, 0) <= 10 THEN 3
                    ELSE 4
                END AS ordre_tranche,
                COUNT(DISTINCT f.id) AS total_ouverts,
                COUNT(DISTINCT CASE WHEN f.priorite = 'Urgente' THEN f.id END) AS total_urgents
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$where} AND s.resolu = 0
             GROUP BY tranche, ordre_tranche
             ORDER BY ordre_tranche ASC",
            $params
        );

        $missionWhere = $where;
        $missionParams = $params;
        if (!empty($filters['responsable_id'])) {
            $missionWhere .= ' AND m.responsable_id = :stats_mission_responsable_id';
            $missionParams['stats_mission_responsable_id'] = (int) $filters['responsable_id'];
        }
        if (!empty($filters['localisation_id'])) {
            $missionWhere .= ' AND m.localisation_id = :stats_mission_localisation_id';
            $missionParams['stats_mission_localisation_id'] = (int) $filters['localisation_id'];
        }

        $missionStats = $this->statisticsRow(
            "SELECT
                COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END) AS total_terminees,
                COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END) AS total_reussies,
                COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'non_retrouve' THEN 1 END) AS total_infructueuses,
                COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'a_verifier' THEN 1 END) AS total_a_verifier,
                COUNT(CASE WHEN m.etat = 'annulee' THEN 1 END) AS total_annulees,
                ROUND(
                    COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END)
                    / NULLIF(COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END), 0) * 100,
                    1
                ) AS taux_succes,
                ROUND(AVG(CASE
                    WHEN m.etat = 'terminee' AND m.date_cloture IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, m.date_affectation, m.date_cloture) / 24
                END), 1) AS delai_moyen_jours
             FROM missions_recherche m
             JOIN formulaires_manquants f ON f.id = m.formulaire_id
             JOIN statuts s ON s.id = f.statut_id
             WHERE {$missionWhere}",
            $missionParams
        );

        $parResponsable = $this->statisticsRows(
            "SELECT
                u.id AS responsable_id,
                CONCAT_WS(' ', u.nom, u.prenoms) AS responsable,
                COUNT(CASE WHEN m.etat IN ('affectee','en_cours') THEN 1 END) AS missions_actives,
                COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END) AS missions_terminees,
                COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END) AS missions_reussies,
                ROUND(
                    COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END)
                    / NULLIF(COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END), 0) * 100,
                    1
                ) AS taux_succes,
                ROUND(AVG(CASE
                    WHEN m.etat = 'terminee' AND m.date_cloture IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, m.date_affectation, m.date_cloture) / 24
                END), 1) AS delai_moyen_jours
             FROM missions_recherche m
             JOIN formulaires_manquants f ON f.id = m.formulaire_id
             JOIN statuts s ON s.id = f.statut_id
             JOIN utilisateurs u ON u.id = m.responsable_id
             WHERE {$missionWhere}
             GROUP BY u.id, u.nom, u.prenoms
             HAVING missions_actives > 0 OR missions_terminees > 0
             ORDER BY missions_actives DESC, missions_terminees DESC, responsable ASC
             LIMIT 10",
            $missionParams
        );

        $parLocalisation = $this->statisticsRows(
            "SELECT
                l.id AS localisation_id,
                l.libelle AS localisation,
                COUNT(CASE WHEN m.etat IN ('affectee','en_cours') THEN 1 END) AS missions_actives,
                COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END) AS missions_terminees,
                COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END) AS formulaires_retrouves,
                ROUND(
                    COUNT(CASE WHEN m.etat = 'terminee' AND m.resultat_code = 'retrouve' THEN 1 END)
                    / NULLIF(COUNT(CASE WHEN m.etat = 'terminee' THEN 1 END), 0) * 100,
                    1
                ) AS taux_succes
             FROM missions_recherche m
             JOIN formulaires_manquants f ON f.id = m.formulaire_id
             JOIN statuts s ON s.id = f.statut_id
             JOIN localisations l ON l.id = m.localisation_id
             WHERE {$missionWhere}
             GROUP BY l.id, l.libelle
             HAVING missions_actives > 0 OR missions_terminees > 0
             ORDER BY missions_terminees DESC, formulaires_retrouves DESC, localisation ASC
             LIMIT 10",
            $missionParams
        );

        return [
            'kpi' => $kpi,
            'par_annee' => $parAnnee,
            'par_type' => $parType,
            'par_statut' => $parStatut,
            'mensuelles' => $mensuelles,
            'ajouts_mensuels' => $ajoutsMensuels,
            'anciennete_stock' => $ancienneteStock,
            'missions' => $missionStats,
            'par_responsable' => $parResponsable,
            'par_localisation' => $parLocalisation,
        ];
    }

    /** @return array{annees:array,types:array,statuts:array,responsables:array,localisations:array} */
    public function repo_optionsStatistiques(): array
    {
        return [
            'annees' => $this->db->query(
                'SELECT DISTINCT annee FROM formulaires_manquants WHERE est_archive = 0 ORDER BY annee DESC'
            )->fetchAll(),
            'types' => $this->db->query(
                'SELECT id, libelle, actif FROM types_titres ORDER BY ordre ASC, libelle ASC'
            )->fetchAll(),
            'statuts' => $this->db->query(
                'SELECT id, code, libelle, couleur, actif FROM statuts ORDER BY ordre ASC, libelle ASC'
            )->fetchAll(),
            'responsables' => $this->db->query(
                "SELECT DISTINCT u.id, u.nom, u.prenoms, u.actif
                 FROM utilisateurs u
                 WHERE u.role IN ('administrateur','responsable','agent')
                    OR EXISTS (
                        SELECT 1 FROM missions_recherche m WHERE m.responsable_id = u.id
                    )
                 ORDER BY u.nom ASC, u.prenoms ASC"
            )->fetchAll(),
            'localisations' => $this->db->query(
                'SELECT id, libelle, actif FROM localisations ORDER BY libelle ASC'
            )->fetchAll(),
        ];
    }

    /** @return array{where:string,params:array<string,mixed>} */
    private function buildStatisticsScope(array $filters): array
    {
        $conditions = ['f.est_archive = 0'];
        $params = [];

        if (!empty($filters['annee'])) {
            $conditions[] = 'f.annee = :stats_annee';
            $params['stats_annee'] = (int) $filters['annee'];
        }
        if (!empty($filters['type_titre_id'])) {
            $conditions[] = 'f.type_titre_id = :stats_type_titre_id';
            $params['stats_type_titre_id'] = (int) $filters['type_titre_id'];
        }
        if (!empty($filters['statut_id'])) {
            $conditions[] = 'f.statut_id = :stats_statut_id';
            $params['stats_statut_id'] = (int) $filters['statut_id'];
        }
        if (!empty($filters['priorite'])) {
            $conditions[] = 'f.priorite = :stats_priorite';
            $params['stats_priorite'] = (string) $filters['priorite'];
        }
        if (!empty($filters['responsable_id'])) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM missions_recherche msr
                WHERE msr.formulaire_id = f.id
                  AND msr.responsable_id = :stats_responsable_id
            )';
            $params['stats_responsable_id'] = (int) $filters['responsable_id'];
        }
        if (!empty($filters['localisation_id'])) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM missions_recherche msl
                WHERE msl.formulaire_id = f.id
                  AND msl.localisation_id = :stats_localisation_id
            )';
            $params['stats_localisation_id'] = (int) $filters['localisation_id'];
        }
        if (!empty($filters['date_debut'])) {
            $conditions[] = 'f.cree_le >= :stats_date_debut';
            $params['stats_date_debut'] = (string) $filters['date_debut'] . ' 00:00:00';
        }
        if (!empty($filters['date_fin'])) {
            $conditions[] = 'f.cree_le < DATE_ADD(:stats_date_fin, INTERVAL 1 DAY)';
            $params['stats_date_fin'] = (string) $filters['date_fin'];
        }

        return [
            'where' => implode(' AND ', $conditions),
            'params' => $params,
        ];
    }

    private function statisticsRow(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    private function statisticsRows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function repo_statsParAnnee(): array
    {
        return $this->db->query('SELECT * FROM vue_stats_annuelle')->fetchAll();
    }

    public function repo_statsParType(): array
    {
        return $this->db->query('SELECT * FROM vue_stats_type_titre')->fetchAll();
    }

    public function repo_statsParStatut(): array
    {
        return $this->db->query('SELECT * FROM vue_stats_statut')->fetchAll();
    }

    public function repo_statsParResponsable(): array
    {
        return $this->db->query('SELECT * FROM vue_stats_responsable HAVING total_dossiers > 0')->fetchAll();
    }

    public function repo_statsMensuelles(): array
    {
        return $this->db->query('SELECT * FROM vue_stats_mensuelle')->fetchAll();
    }

    /** @return array{total_dossiers:int, total_resolus:int} */
    public function repo_statsPourResponsable(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total_dossiers,
                    COALESCE(SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END), 0) AS total_resolus
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE f.est_archive = 0 AND f.responsable_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total_dossiers' => (int) ($row['total_dossiers'] ?? 0),
            'total_resolus' => (int) ($row['total_resolus'] ?? 0),
        ];
    }

    /**
     * Missions actuellement affectees a un utilisateur et encore en attente
     * de leur resultat. Cette requete dediee evite de confondre une ancienne
     * recherche conservee sur le dossier avec une affectation active.
     */
    public function repo_missionsActivesPourResponsable(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT f.*, t.libelle AS type_libelle,
                    s.libelle AS statut_libelle, s.couleur AS statut_couleur,
                    l.libelle AS localisation_libelle,
                    CASE
                        WHEN f.date_echeance_recherche IS NOT NULL
                         AND f.date_echeance_recherche < CURDATE() THEN 1
                        ELSE 0
                    END AS est_en_retard
             FROM formulaires_manquants f
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN statuts s ON s.id = f.statut_id
             LEFT JOIN localisations l ON l.id = f.localisation_id
             WHERE f.est_archive = 0
               AND f.responsable_id = :user_id
               AND s.code = 'en_recherche'
               AND f.date_recherche IS NULL
             ORDER BY
               CASE WHEN f.date_echeance_recherche IS NULL THEN 1 ELSE 0 END,
               f.date_echeance_recherche ASC,
               FIELD(f.priorite, 'Urgente', 'Haute', 'Normale', 'Basse'),
               f.mis_a_jour_le DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array{total_actives:int,total_en_retard:int} */
    public function repo_statsMissionsActives(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total_actives,
                    COALESCE(SUM(CASE
                        WHEN f.date_echeance_recherche IS NOT NULL
                         AND f.date_echeance_recherche < CURDATE() THEN 1
                        ELSE 0
                    END), 0) AS total_en_retard
             FROM formulaires_manquants f
             JOIN statuts s ON s.id = f.statut_id
             WHERE f.est_archive = 0
               AND f.responsable_id = :user_id
               AND s.code = 'en_recherche'
               AND f.date_recherche IS NULL"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total_actives' => (int) ($row['total_actives'] ?? 0),
            'total_en_retard' => (int) ($row['total_en_retard'] ?? 0),
        ];
    }

    public function repo_archives(): array
    {
        return $this->repo_search(['archives_uniquement' => 1], null, 'f.archive_le', 'DESC');
    }

    public function repo_activeCount(): int
    {
        return $this->repo_count('est_archive = 0');
    }

    public function repo_belongsToUser(int $formulaireId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM formulaires_manquants
             WHERE id = :id AND (cree_par = :uid OR EXISTS (
                 SELECT 1 FROM missions_recherche m
                 WHERE m.formulaire_id = formulaires_manquants.id AND m.responsable_id = :mission_uid
             ))'
        );
        $stmt->execute(['id' => $formulaireId, 'uid' => $userId, 'mission_uid' => $userId]);
        return ((int) $stmt->fetch()['n']) > 0;
    }

    private function statusIsResolved(int $statusId): bool
    {
        if ($statusId < 1) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT resolu FROM statuts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $statusId]);
        return (int) $stmt->fetchColumn() === 1;
    }
}
