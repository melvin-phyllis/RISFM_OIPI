<?php
declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Core\AuditAction;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Repositories\Administration\ActiviteRepository;
use App\Repositories\Utilisateur\UserRepository;

class JournalController extends Controller
{
    public function ctrl_index(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $activityRepository = new ActiviteRepository();
        $typesActions = array_map(static function (array $row): array {
            return array_merge($row, AuditAction::meta((string) $row['type_action']));
        }, $activityRepository->repo_typesDisponibles());

        $this->render('journal/list', [
            '__title' => "Journal d'activite",
            '__active' => 'journal',
            '__hide_page_header' => true,
            'utilisateurs' => (new UserRepository())->repo_all('nom', 'ASC'),
            'typesActions' => $typesActions,
            'journalStats' => $activityRepository->repo_statsJournal(),
        ]);
    }
}
