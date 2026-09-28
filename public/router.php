<?php
declare(strict_types=1);

// Routeur destine uniquement au serveur de developpement integre de PHP.
// Les fichiers statiques existants sont servis directement par PHP ; toutes
// les autres requetes sont transmises au controleur frontal de l'application.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
