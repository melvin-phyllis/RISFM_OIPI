<?php
declare(strict_types=1);

class UserModel extends Model
{
    protected string $table = 'utilisateurs';
    private const RESEARCH_ROLES = ['administrateur', 'responsable', 'agent'];

    public static function generatedIdentifiant(int $userId): string
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Un identifiant utilisateur positif est requis.');
        }

        return sprintf('OIPI-RISFM-%06d', $userId);
    }

    /**
     * Cree un utilisateur et derive son identifiant public de la cle primaire.
     * Le marqueur temporaire aleatoire permet d'obtenir l'AUTO_INCREMENT sans
     * collision, puis les deux ecritures sont validees dans une transaction.
     * Ainsi, deux creations simultanees ne peuvent jamais recevoir le meme code.
     *
     * @return array{id:int, identifiant:string}
     */
    public function insertWithGeneratedIdentifiant(array $data): array
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $data['identifiant'] = 'TMP-RISFM-' . bin2hex(random_bytes(16));
            $id = $this->insert($data);
            $identifiant = self::generatedIdentifiant($id);

            $stmt = $this->db->prepare(
                'UPDATE utilisateurs SET identifiant = :identifiant WHERE id = :id'
            );
            $stmt->execute([
                'identifiant' => $identifiant,
                'id' => $id,
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }

            return ['id' => $id, 'identifiant' => $identifiant];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function findByIdentifiant(string $identifiant): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM utilisateurs WHERE identifiant = :identifiant LIMIT 1');
        $stmt->execute(['identifiant' => $identifiant]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM utilisateurs WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function identifiantExists(string $identifiant, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS n FROM utilisateurs WHERE identifiant = :identifiant';
        $params = ['identifiant' => $identifiant];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params['excludeId'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return ((int) $stmt->fetch()['n']) > 0;
    }

    /**
     * Efface le verrou anti-brute-force apres un changement de mot de passe
     * valide. Le statut administratif actif/inactif du compte n'est pas touche.
     */
    public function clearFailedLoginAttempts(int $userId): void
    {
        $stmt = $this->db->prepare(
            'DELETE tc FROM tentatives_connexion tc
             JOIN utilisateurs u ON u.identifiant = tc.identifiant
             WHERE u.id = :id AND tc.succes = 0'
        );
        $stmt->execute(['id' => $userId]);
    }

    public function paginate(int $page, int $perPage, string $search = ''): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE nom LIKE :s OR prenoms LIKE :s OR identifiant LIKE :s OR email LIKE :s';
            $params['s'] = "%{$search}%";
        }
        $sql = "SELECT * FROM utilisateurs {$where} ORDER BY cree_le DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function setPassword(
        int $id,
        string $plainPassword,
        bool $forceChange = true,
        ?int $keepConnectionId = null
    ): int
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE utilisateurs
                 SET mot_de_passe = :hash,
                     doit_changer_mdp = :force_change,
                     session_version = session_version + 1
                 WHERE id = :id'
            );
            $stmt->execute([
                'hash' => $hash,
                'force_change' => $forceChange ? 1 : 0,
                'id' => $id,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Compte utilisateur introuvable pour le changement de mot de passe.');
            }

            $this->closeActiveConnections($id, $keepConnectionId);
            $tokens = $this->db->prepare(
                'UPDATE tokens_reinitialisation SET utilise = 1
                 WHERE utilisateur_id = :id AND utilise = 0'
            );
            $tokens->execute(['id' => $id]);

            $versionStmt = $this->db->prepare('SELECT session_version FROM utilisateurs WHERE id = :id');
            $versionStmt->execute(['id' => $id]);
            $newVersion = (int) $versionStmt->fetchColumn();

            if ($startedTransaction) {
                $this->db->commit();
            }
            return $newVersion;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retourne les utilisateurs avec les compteurs necessaires aux decisions
     * d'administration. Une mission historique rend le compte non supprimable.
     */
    public function allWithMissionSummary(): array
    {
        $sessionMinutes = sessionLifetimeMinutes();

        return $this->db->query(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM missions_recherche ma
                     WHERE ma.responsable_id = u.id
                       AND ma.etat IN ('affectee','en_cours')) AS missions_actives,
                    (SELECT COUNT(*) FROM missions_recherche mh
                     WHERE mh.responsable_id = u.id
                        OR mh.affecte_par = u.id
                        OR mh.cloture_par = u.id) AS missions_liees,
                    EXISTS(
                        SELECT 1
                        FROM connexions c
                        WHERE c.utilisateur_id = u.id
                          AND c.statut = 'actif'
                          AND c.derniere_activite >= (NOW() - INTERVAL {$sessionMinutes} MINUTE)
                    ) AS est_connecte
             FROM utilisateurs u
             ORDER BY u.cree_le DESC"
        )->fetchAll();
    }

    public function findWithMissionSummary(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM missions_recherche ma
                     WHERE ma.responsable_id = u.id
                       AND ma.etat IN ('affectee','en_cours')) AS missions_actives,
                    (SELECT COUNT(*) FROM missions_recherche mh
                     WHERE mh.responsable_id = u.id
                        OR mh.affecte_par = u.id
                        OR mh.cloture_par = u.id) AS missions_liees
             FROM utilisateurs u
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{missions_actives:int,missions_liees:int} */
    public function missionLifecycleSummary(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM missions_recherche ma
                 WHERE ma.responsable_id = :active_id
                   AND ma.etat IN ('affectee','en_cours')) AS missions_actives,
                (SELECT COUNT(*) FROM missions_recherche mh
                 WHERE mh.responsable_id = :related_responsible
                    OR mh.affecte_par = :related_assigner
                    OR mh.cloture_par = :related_closer) AS missions_liees"
        );
        $stmt->execute([
            'active_id' => $id,
            'related_responsible' => $id,
            'related_assigner' => $id,
            'related_closer' => $id,
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'missions_actives' => (int) ($row['missions_actives'] ?? 0),
            'missions_liees' => (int) ($row['missions_liees'] ?? 0),
        ];
    }

    /**
     * Met a jour un compte sous verrou. Un responsable ayant une mission
     * active doit conserver un role apte a traiter cette mission.
     */
    public function updateWithLifecycleGuard(int $id, array $data): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $user = $this->lockUser($id);
            if (!$user) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            $newRole = (string) ($data['role'] ?? $user['role']);
            if (
                (int) $user['actif'] === 1
                && !in_array($newRole, self::RESEARCH_ROLES, true)
            ) {
                $summary = $this->missionLifecycleSummary($id);
                if ($summary['missions_actives'] > 0) {
                    throw new DomainException(
                        $this->activeMissionMessage(
                            $summary['missions_actives'],
                            'changer ce compte vers un role de consultation'
                        )
                    );
                }
            }

            $roleChanged = $newRole !== (string) $user['role'];
            $updated = parent::update($id, $data);
            if ($roleChanged) {
                $this->db->prepare(
                    'UPDATE utilisateurs SET session_version = session_version + 1 WHERE id = :id'
                )->execute(['id' => $id]);
                $this->closeActiveConnections($id);
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return $updated;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function toggleActive(int $id): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $user = $this->lockUser($id);
            if (!$user) {
                throw new RuntimeException('Utilisateur introuvable.');
            }
            $newState = ((int) $user['actif'] === 1) ? 0 : 1;
            if ($newState === 0) {
                $summary = $this->missionLifecycleSummary($id);
                if ($summary['missions_actives'] > 0) {
                    $activeMissions = $this->db->prepare(
                        "SELECT id, formulaire_id FROM missions_recherche
                         WHERE responsable_id = :id AND etat IN ('affectee', 'en_cours')"
                    );
                    $activeMissions->execute(['id' => $id]);
                    $rows = $activeMissions->fetchAll();

                    $actorId = Auth::id() ?: $id;
                    $updateMission = $this->db->prepare(
                        "UPDATE missions_recherche
                         SET etat = 'annulee',
                             cloture_par = :actor_id,
                             date_cloture = NOW(),
                             motif_annulation = 'Compte utilisateur désactivé par l\'administrateur'
                         WHERE id = :mission_id"
                    );

                    $affectedFormIds = [];
                    foreach ($rows as $row) {
                        $updateMission->execute([
                            'actor_id' => $actorId,
                            'mission_id' => (int) $row['id'],
                        ]);
                        $affectedFormIds[(int) $row['formulaire_id']] = true;
                    }

                    $missionModel = new MissionRechercheModel();
                    $formModel = new FormulaireModel();
                    $statutModel = new StatutModel();
                    $statutEnRecherche = $statutModel->findByCode('en_recherche');
                    $statutIntrouvable = $statutModel->findByCode('introuvable');

                    foreach (array_keys($affectedFormIds) as $formId) {
                        $active = $missionModel->premiereActive((int) $formId);
                        if ($active && $statutEnRecherche) {
                            $formModel->update((int) $formId, [
                                'statut_id' => (int) $statutEnRecherche['id'],
                                'localisation_id' => (int) $active['localisation_id'],
                                'responsable_id' => (int) $active['responsable_id'],
                                'date_echeance_recherche' => $active['date_echeance'],
                                'priorite' => $active['priorite'],
                            ]);
                        } elseif ($statutIntrouvable) {
                            $formModel->update((int) $formId, [
                                'statut_id' => (int) $statutIntrouvable['id'],
                                'responsable_id' => null,
                                'date_echeance_recherche' => null,
                            ]);
                        }
                    }
                }
            }

            $stmt = $this->db->prepare(
                'UPDATE utilisateurs
                 SET actif = :actif, session_version = session_version + 1
                 WHERE id = :id'
            );
            $ok = $stmt->execute(['actif' => $newState, 'id' => $id]);
            $this->closeActiveConnections($id);
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $ok;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteWithRevocation(int $id): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $user = $this->lockUser($id);
            if (!$user) {
                throw new RuntimeException('Utilisateur introuvable.');
            }
            $summary = $this->missionLifecycleSummary($id);
            if ($summary['missions_liees'] > 0) {
                throw new DomainException(
                    'Ce compte est lie a ' . $summary['missions_liees']
                    . ' mission' . ($summary['missions_liees'] > 1 ? 's' : '')
                    . ' de recherche. Desactivez-le pour conserver la tracabilite.'
                );
            }

            $this->closeActiveConnections($id);
            $deleted = parent::delete($id);
            if ($startedTransaction) {
                $this->db->commit();
            }
            return $deleted;
        } catch (Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function closeActiveConnections(int $userId, ?int $keepConnectionId = null): void
    {
        $sql = 'UPDATE connexions
                SET statut = "termine",
                    deconnecte_le = NOW(),
                    derniere_activite = NOW(),
                    duree_secondes = TIMESTAMPDIFF(SECOND, connecte_le, NOW())
                WHERE utilisateur_id = :user_id AND statut = "actif"';
        $params = ['user_id' => $userId];
        if ($keepConnectionId !== null) {
            $sql .= ' AND id <> :keep_connection_id';
            $params['keep_connection_id'] = $keepConnectionId;
        }
        $this->db->prepare($sql)->execute($params);
    }

    private function lockUser(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM utilisateurs WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function activeMissionMessage(int $count, string $action): string
    {
        return 'Impossible de ' . $action . ' : '
            . $count . ' mission' . ($count > 1 ? 's sont encore actives.' : ' est encore active.')
            . ' Reaffectez ou cloturez '
            . ($count > 1 ? 'ces missions' : 'cette mission')
            . ' avant de continuer.';
    }

    public function connectedCount(): int
    {
        $minutes = sessionLifetimeMinutes();
        $stmt = $this->db->query(
            "SELECT COUNT(DISTINCT utilisateur_id) AS n
             FROM connexions
             WHERE statut = 'actif'
               AND derniere_activite >= (NOW() - INTERVAL {$minutes} MINUTE)"
        );
        return (int) $stmt->fetch()['n'];
    }

    public function activeCount(): int
    {
        return $this->count('actif = 1');
    }

    public function adminCount(): int
    {
        return $this->count('role = :role', ['role' => 'administrateur']);
    }

    public function activeAdminCount(): int
    {
        return $this->count(
            'role = :role AND actif = 1',
            ['role' => 'administrateur']
        );
    }

    /** Utilisateurs actifs pouvant etre selectionnes comme responsables. */
    public function activeUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM utilisateurs
             WHERE actif = 1
               AND role IN (\'administrateur\', \'responsable\', \'agent\')
             ORDER BY nom ASC, prenoms ASC'
        );
        return $stmt->fetchAll();
    }
}
