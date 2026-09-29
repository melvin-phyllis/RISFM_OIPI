<?php
declare(strict_types=1);

use App\Http\Controllers\Pilotage\DashboardController;
use App\Http\Controllers\Pilotage\StatistiqueController;

/**
 * Routes du module Pilotage — Tableaux de bord et statistiques.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    // Tableaux de bord
    ['GET',  '/dashboard',                                      DashboardController::class, 'ctrl_index'],

    // Statistiques
    ['GET',  '/statistiques',                                   StatistiqueController::class, 'ctrl_index'],
];
