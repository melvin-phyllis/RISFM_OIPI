<?php
declare(strict_types=1);

/**
 * Rappel non bloquant des missions apres une nouvelle authentification.
 *
 * Le numero de connexion en base sert de jeton : un rappel ne peut donc etre
 * consomme qu'une fois pour une connexion donnee, meme si plusieurs pages sont
 * ouvertes. Une erreur de lecture ne doit jamais empecher l'acces au logiciel.
 */
final class MissionLoginReminder
{
    private const SESSION_KEY = '_mission_login_reminder_connection_id';
    private const ELIGIBLE_ROLES = ['administrateur', 'responsable', 'agent'];

    public static function arm(int $connectionId): void
    {
        if ($connectionId > 0) {
            $_SESSION[self::SESSION_KEY] = $connectionId;
        }
    }

    /**
     * @return array{
     *     total_actives:int,
     *     total_en_retard:int,
     *     total_urgentes:int,
     *     prochaine_echeance:?string,
     *     action_url:string,
     *     action_label:string
     * }|null
     */
    public static function consume(): ?array
    {
        if (!Auth::check() || !empty($_SESSION['must_change_password'])) {
            return null;
        }

        $pendingConnectionId = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
        $currentConnectionId = (int) (Auth::connectionId() ?? 0);
        if ($pendingConnectionId <= 0 || $pendingConnectionId !== $currentConnectionId) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        // La consommation precede la requete : une indisponibilite secondaire
        // ne doit pas provoquer un rappel en boucle sur chaque page.
        unset($_SESSION[self::SESSION_KEY]);
        $role = (string) Auth::role();
        if (!in_array($role, self::ELIGIBLE_ROLES, true)) {
            return null;
        }

        try {
            $stats = (new MissionRechercheModel())->statsActives((int) Auth::id());
        } catch (Throwable $exception) {
            error_log('[MissionLoginReminder] Rappel ignore : ' . $exception->getMessage());
            return null;
        }

        if ((int) ($stats['total_actives'] ?? 0) < 1) {
            return null;
        }

        return [
            'total_actives' => (int) $stats['total_actives'],
            'total_en_retard' => (int) ($stats['total_en_retard'] ?? 0),
            'total_urgentes' => (int) ($stats['total_urgentes'] ?? 0),
            'prochaine_echeance' => $stats['prochaine_echeance'] ?? null,
            'action_url' => $role === 'administrateur'
                ? url('formulaires')
                : url('dashboard#mes-missions-actives'),
            'action_label' => $role === 'administrateur'
                ? 'Ouvrir le registre'
                : 'Voir mes missions',
        ];
    }
}
