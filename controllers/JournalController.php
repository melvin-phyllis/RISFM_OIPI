<?php
declare(strict_types=1);

class JournalController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $activityModel = new ActiviteModel();
        $typesActions = array_map(static function (array $row): array {
            return array_merge($row, AuditAction::meta((string) $row['type_action']));
        }, $activityModel->typesDisponibles());

        $this->render('journal/list', [
            '__title' => "Journal d'activite",
            '__active' => 'journal',
            '__hide_page_header' => true,
            'utilisateurs' => (new UserModel())->all('nom', 'ASC'),
            'typesActions' => $typesActions,
            'journalStats' => $activityModel->statsJournal(),
        ]);
    }
}
