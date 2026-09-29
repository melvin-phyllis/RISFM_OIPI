<?php
declare(strict_types=1);

use App\Http\Controllers\Utilisateur\UserController;

/**
 * Routes du module Utilisateur — Gestion des comptes utilisateurs.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    ['GET',  '/utilisateurs',                                   UserController::class, 'ctrl_index'],
    ['GET',  '/utilisateurs/ajouter',                           UserController::class, 'ctrl_create'],
    ['POST', '/utilisateurs/ajouter',                           UserController::class, 'ctrl_store'],
    ['GET',  '/utilisateurs/modifier/{id}',                     UserController::class, 'ctrl_edit'],
    ['POST', '/utilisateurs/modifier/{id}',                     UserController::class, 'ctrl_update'],
    ['POST', '/utilisateurs/supprimer/{id}',                    UserController::class, 'ctrl_destroy'],
    ['POST', '/utilisateurs/statut/{id}',                       UserController::class, 'ctrl_toggleStatus'],
    ['POST', '/utilisateurs/reinitialiser/{id}',                UserController::class, 'ctrl_resetPassword'],
    ['POST', '/utilisateurs/mot-de-passe/{id}',                 UserController::class, 'ctrl_manualPasswordChange'],
];
