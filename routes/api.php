<?php
declare(strict_types=1);

use App\Http\Controllers\Api\ApiController;

/**
 * Routes du module Api — Donnees JSON des tableaux (DataTables) et compteurs.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    ['GET',  '/api/formulaires-datatable',                      ApiController::class, 'ctrl_formulairesDatatable'],
    ['GET',  '/api/formulaires/{id}/localisations-recherchees', ApiController::class, 'ctrl_formulaireSearchedLocations'],
    ['GET',  '/api/dashboard-stats',                            ApiController::class, 'ctrl_dashboardStats'],
    ['GET',  '/api/notifications-count',                        ApiController::class, 'ctrl_notificationsCount'],
    ['GET',  '/api/utilisateurs-datatable',                     ApiController::class, 'ctrl_usersDatatable'],
    ['GET',  '/api/journal-datatable',                          ApiController::class, 'ctrl_journalDatatable'],
    ['GET',  '/api/journal-detail/{id}',                        ApiController::class, 'ctrl_journalDetail'],
    ['GET',  '/api/connexions-datatable',                       ApiController::class, 'ctrl_connexionsDatatable'],
];
