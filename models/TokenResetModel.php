<?php
declare(strict_types=1);

class TokenResetModel extends Model
{
    protected string $table = 'tokens_reinitialisation';

    public function creer(int $userId, int $dureeMinutes = 60): string
    {
        $dureeMinutes = max(5, min(1440, $dureeMinutes));
        $token = bin2hex(random_bytes(32));
        $expire = (new DateTimeImmutable())->modify("+{$dureeMinutes} minutes")->format('Y-m-d H:i:s');
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Le verrou utilisateur serialise deux demandes simultanees, meme
            // lorsque ce compte ne possede encore aucun jeton.
            $lock = $this->db->prepare('SELECT id FROM utilisateurs WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Compte utilisateur introuvable pour la reinitialisation.');
            }

            $this->invaliderTous($userId);
            $this->insert([
                'utilisateur_id' => $userId,
                'token_hash'     => self::empreinte($token),
                'expire_le'      => $expire,
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }
            return $token;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function valide(string $token, bool $pourMiseAJour = false): ?array
    {
        if (!self::formatValide($token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, utilisateur_id, token_hash, expire_le, utilise, cree_le
             FROM tokens_reinitialisation
             WHERE token_hash = :token_hash AND utilise = 0 AND expire_le >= NOW()
             LIMIT 1' . ($pourMiseAJour ? ' FOR UPDATE' : '')
        );
        $empreinte = self::empreinte($token);
        $stmt->execute(['token_hash' => $empreinte]);
        $row = $stmt->fetch();
        if ($row === false || !hash_equals((string) $row['token_hash'], $empreinte)) {
            return null;
        }
        return $row;
    }

    public function invaliderTous(int $userId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE tokens_reinitialisation SET utilise = 1
             WHERE utilisateur_id = :utilisateur_id AND utilise = 0'
        );
        $stmt->execute(['utilisateur_id' => $userId]);
        return $stmt->rowCount();
    }

    /** Supprime les secrets inutilisables au lieu de laisser la table grossir. */
    public function purgerExpires(int $conserverUtilisesJours = 1): int
    {
        $jours = max(0, min(30, $conserverUtilisesJours));
        $stmt = $this->db->prepare(
            "DELETE FROM tokens_reinitialisation
             WHERE expire_le < NOW()
                OR (utilise = 1 AND cree_le < (NOW() - INTERVAL {$jours} DAY))"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private static function formatValide(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private static function empreinte(string $token): string
    {
        return hash('sha256', $token);
    }
}
