<?php
declare(strict_types=1);

class ConnexionController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $model = new ConnexionModel();
        $this->render('connexions/list', [
            '__title' => 'Historique des connexions',
            '__active' => 'connexions',
            '__hide_page_header' => true,
            'utilisateurs' => (new UserModel())->all('nom', 'ASC'),
            'connexionStats' => $model->statsHistorique(),
            'connexionTrend' => $model->tendanceRecente(7),
            'sessionLifetimeMinutes' => sessionLifetimeMinutes(),
        ]);
    }
}
