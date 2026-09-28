<?php
declare(strict_types=1);

class NotificationController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('notifications.view');

        $model = new NotificationModel();
        $userId = (int) Auth::id();
        $perPage = 30;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = $model->totalPourUtilisateur($userId);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $notifications = $model->pourUtilisateur($userId, $perPage, ($page - 1) * $perPage);

        $this->render('notifications/list', [
            '__title' => 'Mes notifications',
            '__active' => 'notifications',
            'notifications' => $notifications,
            'nonLues' => $model->nonLuesCount($userId),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function markRead(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('notifications.view');
        $model = new NotificationModel();
        $model->marquerLue((int) $id, (int) Auth::id());
        Logger::log(Auth::id(), 'notification', "Notification #{$id} marquee comme lue");

        // Reponse JSON pour un appel AJAX, redirection pour une soumission de formulaire classique
        $wantsJson = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($wantsJson) {
            $this->json(['success' => true]);
            return;
        }
        $this->redirect('notifications');
    }

    public function markAllRead(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('notifications.view');
        $model = new NotificationModel();
        $model->marquerToutesLues((int) Auth::id());
        Logger::log(Auth::id(), 'notification', 'Toutes les notifications ont ete marquees comme lues');
        setFlash('success', 'Toutes les notifications ont été marquées comme lues.');
        $this->redirect('notifications');
    }
}
