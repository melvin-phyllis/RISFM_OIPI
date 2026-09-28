<?php
declare(strict_types=1);

/**
 * Journal d'audit applicatif (table `activites`).
 * Chaque action sensible (connexion, creation, modification, suppression,
 * export, impression, activation/desactivation d'utilisateur...) doit etre journalisee.
 */
class Logger
{
    public static function log(
        ?int $userId,
        string $type,
        string $description,
        ?string $ip = null,
        ?string $entiteType = null,
        ?int $entiteId = null,
        ?array $avant = null,
        ?array $apres = null
    ): bool {
        try {
            $db = Database::getConnection();
            $acteur = self::resolveActor($db, $userId);
            $stmt = $db->prepare(
                'INSERT INTO activites
                    (utilisateur_id, acteur_id, acteur_identifiant, acteur_nom,
                     type_action, description, adresse_ip,
                     entite_type, entite_id, donnees_avant, donnees_apres, cree_le)
                 VALUES
                    (:uid, :acteur_id, :acteur_identifiant, :acteur_nom,
                     :type, :desc, :ip,
                     :entite_type, :entite_id, :donnees_avant, :donnees_apres, NOW())'
            );
            $stmt->execute([
                'uid'  => $userId,
                'acteur_id' => $acteur['id'],
                'acteur_identifiant' => $acteur['identifiant'],
                'acteur_nom' => $acteur['nom'],
                'type' => $type,
                'desc' => $description,
                'ip'   => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'entite_type' => $entiteType,
                'entite_id' => $entiteId,
                'donnees_avant' => $avant === null ? null : self::encodeContext($avant),
                'donnees_apres' => $apres === null ? null : self::encodeContext($apres),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[Logger] Echec journalisation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conserve l'identite de l'acteur au moment de l'action. Ces valeurs ne
     * dependent pas de la cle etrangere et survivent donc a la suppression ou
     * au renommage ulterieur du compte.
     *
     * @return array{id:?int, identifiant:?string, nom:?string}
     */
    private static function resolveActor(PDO $db, ?int $userId): array
    {
        if ($userId === null) {
            return ['id' => null, 'identifiant' => null, 'nom' => null];
        }

        $stmt = $db->prepare(
            "SELECT identifiant, CONCAT_WS(' ', nom, prenoms) AS nom
             FROM utilisateurs WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        return [
            'id' => $userId,
            'identifiant' => $user['identifiant'] ?? null,
            'nom' => isset($user['nom']) && trim((string) $user['nom']) !== '' ? trim((string) $user['nom']) : null,
        ];
    }

    private static function encodeContext(array $context): string
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json;
    }
}
