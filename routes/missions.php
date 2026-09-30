<?php
declare(strict_types=1);

use App\Http\Controllers\Mission\MissionRechercheController;

/**
 * Routes du module Mission — Missions de recherche : affectation, annulation, reaffectation et resultat.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    ['POST', '/formulaires/affecter/{id}',                      MissionRechercheController::class, 'ctrl_assign'],
    ['POST', '/missions-recherche/annuler/{id}',                MissionRechercheController::class, 'ctrl_cancel'],
    ['POST', '/missions-recherche/reaffecter/{id}',             MissionRechercheController::class, 'ctrl_reassign'],
    ['POST', '/missions-recherche/resultat/{id}',               MissionRechercheController::class, 'ctrl_recordResult'],
    ['POST', '/formulaires/declarer-retrouve/{id}',             MissionRechercheController::class, 'ctrl_declareFound'],
    ['POST', '/formulaires/recherche/ajouter/{id}',             MissionRechercheController::class, 'ctrl_recordLegacyResult'],
];
