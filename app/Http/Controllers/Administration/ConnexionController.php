<?php
declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Repositories\Utilisateur\ConnexionRepository;
use App\Repositories\Utilisateur\UserRepository;

class ConnexionController extends Controller
{
    public function ctrl_index(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $model = new ConnexionRepository();
        $this->render('connexions/list', [
            '__title' => 'Historique des connexions',
            '__active' => 'connexions',
            '__hide_page_header' => true,
            'utilisateurs' => (new UserRepository())->repo_all('nom', 'ASC'),
            'connexionStats' => $model->repo_statsHistorique(),
            'connexionTrend' => $model->repo_tendanceRecente(7),
            'sessionLifetimeMinutes' => sessionLifetimeMinutes(),
        ]);
    }
}
