<?php
declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfilController;

/**
 * Routes du module Auth — Authentification, mot de passe oublie et profil personnel.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    // Authentification
    ['GET',  '/',                                               AuthController::class, 'ctrl_redirectRoot'],
    ['GET',  '/login',                                          AuthController::class, 'ctrl_showLogin'],
    ['POST', '/login',                                          AuthController::class, 'ctrl_login'],
    ['GET',  '/verification-code',                              AuthController::class, 'ctrl_showLoginCode'],
    ['POST', '/verification-code',                              AuthController::class, 'ctrl_verifyLoginCode'],
    ['POST', '/verification-code/renvoyer',                     AuthController::class, 'ctrl_resendLoginCode'],
    ['POST', '/verification-code/annuler',                      AuthController::class, 'ctrl_cancelLoginCode'],
    ['POST', '/logout',                                         AuthController::class, 'ctrl_logout'],
    ['GET',  '/mot-de-passe-oublie',                            AuthController::class, 'ctrl_showForgot'],
    ['POST', '/mot-de-passe-oublie',                            AuthController::class, 'ctrl_forgot'],
    ['GET',  '/reinitialiser/{token}',                          AuthController::class, 'ctrl_showReset'],
    ['GET',  '/reinitialiser',                                  AuthController::class, 'ctrl_showResetForm'],
    ['POST', '/reinitialiser',                                  AuthController::class, 'ctrl_reset'],
    ['POST', '/reinitialiser/{token}',                          AuthController::class, 'ctrl_reset'],

    // Profil personnel
    ['GET',  '/profil',                                         ProfilController::class, 'ctrl_show'],
    ['POST', '/profil',                                         ProfilController::class, 'ctrl_update'],
    ['POST', '/profil/mot-de-passe',                            ProfilController::class, 'ctrl_updatePassword'],
];
