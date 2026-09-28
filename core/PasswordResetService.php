<?php
declare(strict_types=1);

/** Orchestre la consommation atomique d'un lien de reinitialisation. */
final class PasswordResetService
{
    /**
     * Retourne l'identifiant du compte modifie, ou null si le jeton a expire,
     * a deja ete consomme ou appartient a un compte desactive.
     */
    public function reset(string $token, string $newPassword): ?int
    {
        $passwordError = Security::passwordPolicyError($newPassword);
        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        $db = Database::getConnection();
        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $tokens = new TokenResetModel();
            $tokenRow = $tokens->valide($token, true);
            if ($tokenRow === null) {
                if ($startedTransaction) {
                    $db->rollBack();
                }
                return null;
            }

            $userId = (int) $tokenRow['utilisateur_id'];
            $users = new UserModel();
            $user = $users->find($userId);
            if ($user === null || (int) $user['actif'] !== 1) {
                $tokens->invaliderTous($userId);
                if ($startedTransaction) {
                    $db->commit();
                }
                return null;
            }

            // setPassword incremente session_version, ferme toutes les
            // connexions actives et invalide tous les jetons du compte.
            $users->setPassword($userId, $newPassword, false);
            $users->clearFailedLoginAttempts($userId);

            if ($startedTransaction) {
                $db->commit();
            }
            return $userId;
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
