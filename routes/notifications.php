<?php
declare(strict_types=1);

use App\Http\Controllers\Notification\NotificationController;

/**
 * Routes du module Notification — Notifications internes.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    ['GET',  '/notifications',                                  NotificationController::class, 'ctrl_index'],
    ['POST', '/notifications/lu/{id}',                          NotificationController::class, 'ctrl_markRead'],
    ['POST', '/notifications/tout-lire',                        NotificationController::class, 'ctrl_markAllRead'],
];
