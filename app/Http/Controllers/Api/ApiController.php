<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\AuditAction;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Core\UserAgentInfo;
use App\Repositories\Administration\ActiviteRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Formulaire\RechercheFormulaireRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Repositories\Utilisateur\ConnexionRepository;
use App\Repositories\Utilisateur\UserRepository;
use DateTimeImmutable;

/**
 * Points d'entree AJAX (JSON) : DataTables server-side, statistiques temps reel,
 * compteur de notifications. Toutes les actions verifient la session et les permissions.
 */
class ApiController extends Controller
{
    private const FORMULAIRES_COLUMNS = [
        1 => 't.libelle', 2 => 'f.annee', 3 => 'f.numero_formulaire', 4 => 's.libelle',
        5 => 'l.libelle', 6 => 'responsable_nom', 7 => 'f.date_recherche',
    ];

    public function ctrl_formulairesDatatable(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.view');

        $draw = (int) $this->input('draw', 1);
        $start = max(0, (int) $this->input('start', 0));
        // Page bornee : length=-1 ou une valeur enorme renverrait tout le registre.
        $length = max(10, min(100, (int) $this->input('length', 25)));
        $orderCol = (int) ($this->input('order', [])['column'] ?? 2);
        $orderDir = $this->input('order', [])['dir'] ?? 'desc';
        $orderColumnSql = self::FORMULAIRES_COLUMNS[$orderCol] ?? 'f.annee';

        $filters = [
            'annee'             => $this->input('annee'),
            'type_titre_id'     => $this->input('type_titre_id'),
            'statut_id'         => $this->input('statut_id'),
            'responsable_id'    => $this->input('responsable_id'),
            'numero_formulaire' => $this->input('numero_formulaire'),
            'mot_cle'           => $this->input('mot_cle'),
            'date_debut'        => $this->input('date_debut'),
            'date_fin'          => $this->input('date_fin'),
            'mes_dossiers'      => $this->input('mes_dossiers'),
        ];

        $role = (string) Auth::role();
        $currentUser = ['id' => Auth::id()];

        $model = new FormulaireRepository();
        $rows = $model->repo_search($filters, $currentUser, $orderColumnSql, (string) $orderDir, $length, $start);
        $filteredCount = $model->repo_searchCount($filters, $currentUser);
        $totalCount = $model->repo_activeCount();

        $canEdit = Permission::has($role, 'formulaires.update_metadata');
        $canAssign = Permission::has($role, 'formulaires.assign');

        $data = array_map(function (array $f, int $index) use ($canEdit, $canAssign, $role, $start) {
            $priority = (string) ($f['priorite'] ?? 'Normale');
            $rowNumber = $start + $index + 1;
            $actionItems = '<a href="' . url('formulaires/voir/' . $f['id']) . '" class="dropdown-item">'
                . '<i class="fas fa-eye text-info mr-2" aria-hidden="true"></i>Consulter</a>';
            $canEditRow = Permission::has($role, 'formulaires.update_metadata');
            if ($canEdit && $canEditRow) {
                $actionItems .= '<a href="' . url('formulaires/voir/' . $f['id'] . '#modifier-formulaire') . '" class="dropdown-item">'
                    . '<i class="fas fa-edit text-primary mr-2" aria-hidden="true"></i>Modifier</a>';
            }
            if ($canAssign && (int) ($f['statut_resolu'] ?? 0) !== 1 && (int) ($f['est_archive'] ?? 0) !== 1) {
                $actionItems .= '<button type="button"'
                    . ' class="dropdown-item js-affecter-recherche"'
                    . ' data-id="' . (int) $f['id'] . '"'
                    . ' data-reference="' . e($f['numero_auto'] ?? $f['numero_formulaire']) . '"'
                    . ' data-numero-formulaire="' . e($f['numero_formulaire']) . '"'
                    . ' data-localisation-id="' . (int) ($f['localisation_id'] ?? 0) . '"'
                    . ' data-responsable-id="' . (int) ($f['responsable_id'] ?? 0) . '"'
                    . ' data-date-echeance="' . e($f['date_echeance_recherche'] ?? '') . '"'
                    . ' data-priorite="' . e($f['priorite'] ?? 'Normale') . '">'
                    . '<i class="fas fa-search-location text-success mr-2" aria-hidden="true"></i>Affecter une recherche</button>';
            }
            $actions = '<div class="dropdown d-inline-block risfm-actions-dropdown">'
                . '<button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"'
                . ' data-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false"'
                . ' title="Afficher les actions">'
                . '<i class="fas fa-ellipsis-v" aria-hidden="true"></i><span class="sr-only">Actions</span></button>'
                . '<div class="dropdown-menu dropdown-menu-right">' . $actionItems . '</div></div>';
            return [
                'numero'            => '<span class="registry-number" title="Priorité ' . e(strtolower($priority)) . '">'
                    . '<span class="priority-dot ' . e(priorityLevelClass($priority)) . '" aria-hidden="true"></span>'
                    . '<span>' . $rowNumber . '</span><span class="sr-only">, priorité ' . e(strtolower($priority)) . '</span></span>',
                'type_libelle'      => e($f['type_libelle']),
                'annee'             => (int) $f['annee'],
                'numero_formulaire' => e($f['numero_formulaire']),
                'statut_badge'      => $this->registryStatusHtml($f),
                'localisation'      => (int) ($f['missions_actives_count'] ?? 0) > 0
                    ? e($f['localisations_actives'])
                    : e($f['localisation_libelle'] ?? '-'),
                'responsable_nom'   => (int) ($f['missions_actives_count'] ?? 0) > 0
                    ? e($f['responsables_actifs']) . '<br><span class="badge badge-light">' . (int) $f['missions_actives_count'] . ' mission(s)</span>'
                    : e($f['responsable_nom'] ?? 'Non assigne'),
                'date_recherche'    => (int) ($f['missions_actives_count'] ?? 0) > 0
                    ? '-'
                    : formatDate($f['date_recherche'] ?? null, 'd/m/Y'),
                'actions'           => $actions,
            ];
        }, $rows, array_keys($rows));

        $this->json([
            'draw' => $draw,
            'recordsTotal' => $totalCount,
            'recordsFiltered' => $filteredCount,
            'data' => $data,
        ]);
    }

    /**
     * Le statut reste celui du workflow. Pour « Introuvable », le contexte
     * precise s'il s'agit d'un dossier encore jamais recherche ou d'une
     * conclusion negative apres une recherche effective.
     */
    private function registryStatusHtml(array $formulaire): string
    {
        $html = '<span class="badge badge-' . e($formulaire['statut_couleur']) . '">'
            . e($formulaire['statut_libelle']) . '</span>';

        if ((string) ($formulaire['statut_code'] ?? '') !== 'introuvable') {
            return $html;
        }

        $hasResearch = !empty($formulaire['dernier_resultat_code'])
            || !empty($formulaire['date_recherche'])
            || trim((string) ($formulaire['resultat'] ?? '')) !== '';
        $context = $hasResearch ? 'Après recherche' : 'Pas encore recherché';
        $contextClass = $hasResearch ? 'text-danger' : 'text-muted';

        return $html . '<div class="registry-status-context ' . $contextClass . '">'
            . e($context) . '</div>';
    }

    public function ctrl_formulaireSearchedLocations(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.view');

        $formulaireId = (int) $id;
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) === 1) {
            $this->json([
                'success' => false,
                'message' => 'Formulaire introuvable.',
                'localisations' => [],
            ], 404);
            return;
        }

        $this->json([
            'success' => true,
            'localisations' => (new RechercheFormulaireRepository())->repo_localisationsDejaRecherchees($formulaireId),
        ]);
    }

    public function ctrl_journalDatatable(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $draw = (int) $this->input('draw', 1);
        $start = max(0, (int) $this->input('start', 0));
        $length = max(10, min(100, (int) $this->input('length', 25)));

        $validDate = static function (mixed $value): string {
            $value = trim((string) $value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
        };
        $dateStart = $validDate($this->input('date_debut'));
        $dateEnd = $validDate($this->input('date_fin'));
        if ($dateStart !== '' && $dateEnd !== '' && $dateStart > $dateEnd) {
            $dateStart = '';
            $dateEnd = '';
        }

        $filters = [
            'type_action'    => mb_substr(trim((string) $this->input('type_action')), 0, 50),
            'utilisateur_id' => max(0, (int) $this->input('utilisateur_id')),
            'date_debut'     => $dateStart,
            'date_fin'       => $dateEnd,
            'mot_cle'        => mb_substr(trim((string) $this->input('mot_cle')), 0, 100),
        ];

        $model = new ActiviteRepository();
        $rows = $model->repo_search($filters, $length, $start);
        $filteredCount = $model->repo_searchCount($filters);
        $totalCount = $model->repo_count();

        $data = array_map(fn ($a) => [
            'cree_le' => '<span class="journal-event-date"><i class="far fa-clock" aria-hidden="true"></i>'
                . e(formatDate($a['cree_le'])) . '</span>',
            'utilisateur_nom' => $this->auditActorHtml($a),
            'type_action' => $this->auditActionHtml((string) $a['type_action']),
            'cible' => $this->auditTargetHtml($a),
            'description' => '<span class="journal-event-description" title="' . e($a['description']) . '">'
                . e($a['description']) . '</span>',
            'details' => $this->auditDetailsButton($a),
            'adresse_ip' => '<span class="journal-ip-address">' . e($a['adresse_ip'] ?? '-') . '</span>',
        ], $rows);

        $this->json(['draw' => $draw, 'recordsTotal' => $totalCount, 'recordsFiltered' => $filteredCount, 'data' => $data]);
    }

    public function ctrl_journalDetail(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $activityId = (int) $id;
        $activity = $activityId > 0 ? (new ActiviteRepository())->repo_findWithActor($activityId) : null;
        if (!$activity) {
            $this->json(['success' => false, 'message' => 'Evenement d’audit introuvable.'], 404);
            return;
        }

        $this->json([
            'success' => true,
            'activity' => [
                'date' => formatDate($activity['cree_le']),
                'actor_html' => $this->auditActorHtml($activity),
                'action_html' => $this->auditActionHtml((string) $activity['type_action']),
                'target_html' => $this->auditTargetHtml($activity),
                'description' => (string) $activity['description'],
                'ip' => (string) ($activity['adresse_ip'] ?? '-'),
                'details_html' => $this->auditDetailsHtml($activity),
            ],
        ]);
    }

    public function ctrl_connexionsDatatable(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('journal.view');

        $start = max(0, (int) $this->input('start', 0));
        $length = max(10, min(100, (int) $this->input('length', 25)));
        $draw = (int) $this->input('draw', 1);

        $validDate = static function (mixed $value): string {
            $value = trim((string) $value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
        };
        $dateStart = $validDate($this->input('date_debut'));
        $dateEnd = $validDate($this->input('date_fin'));
        if ($dateStart !== '' && $dateEnd !== '' && $dateStart > $dateEnd) {
            $dateStart = '';
            $dateEnd = '';
        }
        $status = trim((string) $this->input('statut'));
        if (!in_array($status, ['actif', 'expire', 'termine'], true)) {
            $status = '';
        }
        $filters = [
            'mot_cle' => mb_substr(trim((string) $this->input('mot_cle')), 0, 100),
            'utilisateur_id' => max(0, (int) $this->input('utilisateur_id')),
            'statut' => $status,
            'date_debut' => $dateStart,
            'date_fin' => $dateEnd,
        ];

        $model = new ConnexionRepository();
        $rows = $model->repo_historique($filters, $length, $start);
        $filtered = $model->repo_historiqueCount($filters);
        $total = $model->repo_total();

        $data = array_map(function (array $c): array {
            $environment = UserAgentInfo::parse($c['navigateur'] ?? null);
            $status = (string) ($c['statut_effectif'] ?? 'termine');
            $statusMeta = match ($status) {
                'actif' => ['label' => 'Active maintenant', 'class' => 'success', 'icon' => 'fas fa-circle'],
                'expire' => ['label' => 'Expiree', 'class' => 'warning', 'icon' => 'fas fa-clock'],
                default => ['label' => 'Terminee', 'class' => 'secondary', 'icon' => 'fas fa-check'],
            };
            return [
            'utilisateur_nom' => '<strong class="audit-actor-name">' . e($c['utilisateur_nom']) . '</strong>'
                . '<small class="audit-actor-id">' . e($c['identifiant']) . '</small>',
            'identifiant' => e($c['identifiant']),
            'adresse_ip' => e($c['adresse_ip'] ?? '-'),
            'navigateur' => e($c['navigateur'] ?? '-'),
            'environnement' => '<span class="connection-environment" title="' . e($c['navigateur'] ?? '') . '">'
                . '<i class="' . e($environment['icon']) . '" aria-hidden="true"></i><span><strong>'
                . e($environment['browser']) . '</strong><small>' . e($environment['system'] . ' · ' . $environment['device'])
                . '</small></span></span>',
            'connecte_le' => formatDate($c['connecte_le']),
            'derniere_activite' => formatDate($c['derniere_activite']),
            'deconnecte_le' => formatDate($c['deconnecte_le']),
            'duree' => $this->formatConnectionDuration((int) ($c['duree_effective_secondes'] ?? 0)),
            'statut' => '<span class="badge badge-' . $statusMeta['class'] . ' connection-status-badge"><i class="'
                . $statusMeta['icon'] . ' mr-1" aria-hidden="true"></i>' . $statusMeta['label'] . '</span>',
            'statut_code' => $status,
            ];
        }, $rows);

        $this->json(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data]);
    }

    private function formatConnectionDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' j';
        }
        if ($hours > 0) {
            $parts[] = $hours . ' h';
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . ' min';
        }
        return implode(' ', $parts);
    }

    public function ctrl_usersDatatable(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('utilisateurs.manage');

        $draw = (int) $this->input('draw', 1);
        $model = new UserRepository();
        $rows = $model->repo_all('cree_le', 'DESC');
        $rolesConfig = require BASE_PATH . '/config/roles.php';

        $data = array_map(fn ($u) => [
            'identifiant' => e($u['identifiant']),
            'nom_complet' => e($u['nom'] . ' ' . $u['prenoms']),
            'email' => e($u['email']),
            'role' => e($rolesConfig[$u['role']]['label'] ?? $u['role']),
            'statut' => (int) $u['actif'] === 1 ? '<span class="badge badge-success">Actif</span>' : '<span class="badge badge-secondary">Desactive</span>',
            'derniere_connexion' => formatDate($u['derniere_connexion']),
        ], $rows);

        $this->json(['draw' => $draw, 'recordsTotal' => count($rows), 'recordsFiltered' => count($rows), 'data' => $data]);
    }

    public function ctrl_dashboardStats(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('dashboard.view');

        $model = new FormulaireRepository();
        $this->json([
            'kpi' => $model->repo_kpiGlobaux(),
            'par_annee' => $model->repo_statsParAnnee(),
            'par_type' => $model->repo_statsParType(),
            'par_statut' => $model->repo_statsParStatut(),
        ]);
    }

    public function ctrl_notificationsCount(): void
    {
        Auth::requireLogin();
        $model = new NotificationRepository();
        $this->json(['count' => $model->repo_nonLuesCount((int) Auth::id())]);
    }

    private function auditTargetHtml(array $activity): string
    {
        $type = (string) ($activity['entite_type'] ?? '');
        $id = (int) ($activity['entite_id'] ?? 0);
        if ($type === '') {
            return '-';
        }
        if ($type === 'formulaire' && $id > 0) {
            return '<a href="' . e(url('formulaires/voir/' . $id)) . '">Formulaire #' . $id . '</a>';
        }
        $labels = [
            'mission_recherche' => 'Mission de recherche',
            'utilisateur' => 'Utilisateur',
            'piece_jointe' => 'Piece jointe',
            'parametrage_types_titres' => 'Type de titre',
            'parametrage_statuts' => 'Statut',
            'parametrage_localisations' => 'Localisation',
            'import_formulaires' => 'Import de formulaires',
            'relances_missions' => 'Relances de missions',
        ];
        $label = $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
        return e($label . ($id > 0 ? ' #' . $id : ''));
    }

    private function auditActorHtml(array $activity): string
    {
        $name = trim((string) ($activity['utilisateur_nom'] ?? ''));
        $identifier = trim((string) ($activity['utilisateur_identifiant'] ?? ''));
        if ($name === '' && $identifier === '') {
            return '<span class="audit-actor"><span class="audit-actor-avatar is-system">SY</span>'
                . '<span class="audit-actor-copy"><strong class="audit-actor-name">Système</strong>'
                . '<small class="audit-actor-id">Automatisation</small></span></span>';
        }
        $label = $name !== '' ? $name : $identifier;
        $parts = preg_split('/\s+/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_substr($part, 0, 1);
        }
        $initials = mb_strtoupper($initials !== '' ? $initials : 'U');
        $html = '<span class="audit-actor"><span class="audit-actor-avatar">' . e($initials) . '</span>'
            . '<span class="audit-actor-copy"><strong class="audit-actor-name">' . e($label) . '</strong>';
        if ($identifier !== '' && $identifier !== $label) {
            $html .= '<small class="audit-actor-id">' . e($identifier) . '</small>';
        }
        return $html . '</span></span>';
    }

    private function auditActionHtml(string $type): string
    {
        $meta = AuditAction::meta($type);
        return '<span class="badge badge-' . e($meta['class']) . ' audit-action-badge">'
            . '<i class="' . e($meta['icon']) . ' mr-1" aria-hidden="true"></i>'
            . e($meta['label']) . '</span>';
    }

    private function auditDetailsButton(array $activity): string
    {
        $before = $this->decodeAuditJson($activity['donnees_avant'] ?? null);
        $after = $this->decodeAuditJson($activity['donnees_apres'] ?? null);
        if ($before === [] && $after === []) {
            return '<button type="button" class="btn btn-xs btn-outline-secondary js-journal-detail" data-id="'
                . (int) $activity['id'] . '" title="Voir l’evenement"><i class="fas fa-eye"></i></button>';
        }
        return '<button type="button" class="btn btn-xs btn-outline-primary js-journal-detail" data-id="'
            . (int) $activity['id'] . '"><i class="fas fa-code-branch mr-1"></i>Comparer</button>';
    }

    private function auditDetailsHtml(array $activity): string
    {
        $before = $this->decodeAuditJson($activity['donnees_avant'] ?? null);
        $after = $this->decodeAuditJson($activity['donnees_apres'] ?? null);
        if ($before === [] && $after === []) {
            return '<div class="audit-no-changes"><i class="fas fa-info-circle" aria-hidden="true"></i>Aucune valeur avant/apres n’est associee a cet evenement.</div>';
        }

        $labels = [
            'numero_auto' => 'Reference',
            'type_titre_id' => 'Type de titre',
            'annee' => 'Annee',
            'numero_formulaire' => 'Numero du formulaire',
            'date_depot' => 'Date de depot',
            'deposant' => 'Deposant',
            'mandataire' => 'Mandataire',
            'statut_id' => 'Statut',
            'statut' => 'Statut',
            'localisation_id' => 'Localisation',
            'responsable_id' => 'Responsable',
            'responsable' => 'Responsable',
            'date_recherche' => 'Date de recherche',
            'date_resolution' => 'Date de resolution',
            'resultat' => 'Resultat',
            'observations' => 'Difficultés / notes complémentaires',
            'priorite' => 'Priorite',
            'est_archive' => 'Archive',
            'motif_archivage' => 'Motif d’archivage',
            'archive_par' => 'Archive par',
            'archive_le' => 'Archive le',
        ];

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $changes = [];
        foreach ($keys as $key) {
            // Champ historique conserve uniquement pour compatibilite SQL ;
            // il n'appartient plus au metier affiche depuis le 22/07/2026.
            if ($key === 'niveau_urgence') {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($before !== [] && $after !== [] && $old == $new) {
                continue;
            }
            $changes[] = '<div class="audit-change-row">'
                . '<strong>' . e($labels[$key] ?? str_replace('_', ' ', ucfirst($key))) . '</strong>'
                . '<span class="audit-change-before"><small>Avant</small>' . e($this->auditValue($old)) . '</span>'
                . '<i class="fas fa-long-arrow-alt-right audit-change-arrow" aria-hidden="true"></i>'
                . '<span class="audit-change-after"><small>Apres</small>' . e($this->auditValue($new)) . '</span>'
                . '</div>';
        }
        if ($changes === []) {
            return '-';
        }
        return '<div class="audit-change-summary">' . count($changes) . ' changement(s) detecte(s)</div>'
            . '<div class="audit-change-list">' . implode('', $changes) . '</div>';
    }

    private function decodeAuditJson(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function auditValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '[valeur complexe]';
    }
}
