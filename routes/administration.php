<?php
declare(strict_types=1);

use App\Http\Controllers\Administration\ConnexionController;
use App\Http\Controllers\Administration\JournalController;
use App\Http\Controllers\Administration\ParametreController;
use App\Http\Controllers\Administration\SauvegardeController;

/**
 * Routes du module Administration — Parametres, sauvegardes, journal d audit et connexions.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    // Journal et connexions
    ['GET',  '/journal',                                        JournalController::class, 'ctrl_index'],
    ['GET',  '/connexions',                                     ConnexionController::class, 'ctrl_index'],

    // Parametres
    ['GET',  '/parametres',                                     ParametreController::class, 'ctrl_index'],
    ['POST', '/parametres/general',                             ParametreController::class, 'ctrl_updateGeneral'],
    ['POST', '/parametres/liste/{type}/ajouter',                ParametreController::class, 'ctrl_addListItem'],
    ['POST', '/parametres/liste/{type}/modifier/{id}',          ParametreController::class, 'ctrl_updateListItem'],
    ['POST', '/parametres/liste/{type}/statut/{id}',            ParametreController::class, 'ctrl_toggleListItem'],

    // Sauvegardes
    ['GET',  '/sauvegardes',                                    SauvegardeController::class, 'ctrl_index'],
    ['POST', '/sauvegardes/creer',                              SauvegardeController::class, 'ctrl_create'],
    ['GET',  '/sauvegardes/telecharger/{fichier}',              SauvegardeController::class, 'ctrl_download'],
    ['POST', '/sauvegardes/restaurer',                          SauvegardeController::class, 'ctrl_restore'],
    ['POST', '/sauvegardes/supprimer/{fichier}',                SauvegardeController::class, 'ctrl_destroy'],
];
