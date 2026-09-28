<?php
declare(strict_types=1);

/**
 * Registre central des routes HTTP.
 *
 * Format de chaque entree : [methode, chemin, controleur, action].
 * Les URL publiques restent stables lorsque les controleurs sont decoupes.
 */
return [
    'authentification' => [
        ['GET', '/', 'AuthController', 'redirectRoot'],
        ['GET', '/login', 'AuthController', 'showLogin'],
        ['POST', '/login', 'AuthController', 'login'],
        ['GET', '/verification-code', 'AuthController', 'showLoginCode'],
        ['POST', '/verification-code', 'AuthController', 'verifyLoginCode'],
        ['POST', '/verification-code/renvoyer', 'AuthController', 'resendLoginCode'],
        ['POST', '/verification-code/annuler', 'AuthController', 'cancelLoginCode'],
        ['POST', '/logout', 'AuthController', 'logout'],
        ['GET', '/mot-de-passe-oublie', 'AuthController', 'showForgot'],
        ['POST', '/mot-de-passe-oublie', 'AuthController', 'forgot'],
        ['GET', '/reinitialiser/{token}', 'AuthController', 'showReset'],
        ['GET', '/reinitialiser', 'AuthController', 'showResetForm'],
        ['POST', '/reinitialiser', 'AuthController', 'reset'],
        ['POST', '/reinitialiser/{token}', 'AuthController', 'reset'],
    ],
    'tableaux_de_bord' => [
        ['GET', '/dashboard', 'DashboardController', 'index'],
    ],
    'profil' => [
        ['GET', '/profil', 'ProfilController', 'show'],
        ['POST', '/profil', 'ProfilController', 'update'],
        ['POST', '/profil/mot-de-passe', 'ProfilController', 'updatePassword'],
    ],
    'utilisateurs' => [
        ['GET', '/utilisateurs', 'UserController', 'index'],
        ['GET', '/utilisateurs/ajouter', 'UserController', 'create'],
        ['POST', '/utilisateurs/ajouter', 'UserController', 'store'],
        ['GET', '/utilisateurs/modifier/{id}', 'UserController', 'edit'],
        ['POST', '/utilisateurs/modifier/{id}', 'UserController', 'update'],
        ['POST', '/utilisateurs/supprimer/{id}', 'UserController', 'destroy'],
        ['POST', '/utilisateurs/statut/{id}', 'UserController', 'toggleStatus'],
        ['POST', '/utilisateurs/reinitialiser/{id}', 'UserController', 'resetPassword'],
        ['POST', '/utilisateurs/mot-de-passe/{id}', 'UserController', 'manualPasswordChange'],
    ],
    'formulaires' => [
        ['GET', '/formulaires', 'FormulaireController', 'index'],
        ['GET', '/formulaires/archives', 'FormulaireController', 'archives'],
        ['GET', '/formulaires/importer', 'ImportController', 'index'],
        ['POST', '/formulaires/importer/analyser', 'ImportController', 'analyse'],
        ['POST', '/formulaires/importer/confirmer', 'ImportController', 'confirm'],
        ['POST', '/formulaires/importer/annuler', 'ImportController', 'cancel'],
        ['GET', '/formulaires/importer/rapport', 'ImportController', 'report'],
        ['GET', '/formulaires/importer/modele/{format}', 'ImportController', 'template'],
        ['GET', '/formulaires/ajouter', 'FormulaireController', 'create'],
        ['POST', '/formulaires/ajouter', 'FormulaireController', 'store'],
        ['GET', '/formulaires/voir/{id}', 'FormulaireController', 'show'],
        ['POST', '/formulaires/modifier/{id}', 'FormulaireController', 'update'],
        ['POST', '/formulaires/affecter/{id}', 'MissionRechercheController', 'assign'],
        ['POST', '/missions-recherche/annuler/{id}', 'MissionRechercheController', 'cancel'],
        ['POST', '/missions-recherche/reaffecter/{id}', 'MissionRechercheController', 'reassign'],
        ['POST', '/missions-recherche/resultat/{id}', 'MissionRechercheController', 'recordResult'],
        ['POST', '/formulaires/recherche/ajouter/{id}', 'MissionRechercheController', 'recordLegacyResult'],
        ['POST', '/formulaires/finaliser/{id}', 'FinalisationController', 'advance'],
        ['POST', '/formulaires/reouvrir/{id}', 'FinalisationController', 'reopen'],
        ['POST', '/formulaires/archiver/{id}', 'ArchivageController', 'archive'],
        ['POST', '/formulaires/restaurer/{id}', 'ArchivageController', 'restore'],
        ['POST', '/formulaires/piece-jointe/{id}', 'PieceJointeController', 'upload'],
        ['GET', '/formulaires/piece-jointe/telecharger/{id}', 'PieceJointeController', 'download'],
        ['POST', '/formulaires/piece-jointe/supprimer/{id}', 'PieceJointeController', 'delete'],
        ['GET', '/recherche', 'FormulaireController', 'redirectLegacySearch'],
    ],
    'statistiques' => [
        ['GET', '/statistiques', 'StatistiqueController', 'index'],
    ],
    'journal' => [
        ['GET', '/journal', 'JournalController', 'index'],
        ['GET', '/connexions', 'ConnexionController', 'index'],
    ],
    'notifications' => [
        ['GET', '/notifications', 'NotificationController', 'index'],
        ['POST', '/notifications/lu/{id}', 'NotificationController', 'markRead'],
        ['POST', '/notifications/tout-lire', 'NotificationController', 'markAllRead'],
    ],
    'parametres' => [
        ['GET', '/parametres', 'ParametreController', 'index'],
        ['POST', '/parametres/general', 'ParametreController', 'updateGeneral'],
        ['POST', '/parametres/liste/{type}/ajouter', 'ParametreController', 'addListItem'],
        ['POST', '/parametres/liste/{type}/modifier/{id}', 'ParametreController', 'updateListItem'],
        ['POST', '/parametres/liste/{type}/statut/{id}', 'ParametreController', 'toggleListItem'],
    ],
    'sauvegardes' => [
        ['GET', '/sauvegardes', 'SauvegardeController', 'index'],
        ['POST', '/sauvegardes/creer', 'SauvegardeController', 'create'],
        ['GET', '/sauvegardes/telecharger/{fichier}', 'SauvegardeController', 'download'],
        ['POST', '/sauvegardes/restaurer', 'SauvegardeController', 'restore'],
        ['POST', '/sauvegardes/supprimer/{fichier}', 'SauvegardeController', 'destroy'],
    ],
    'api' => [
        ['GET', '/api/formulaires-datatable', 'ApiController', 'formulairesDatatable'],
        ['GET', '/api/formulaires/{id}/localisations-recherchees', 'ApiController', 'formulaireSearchedLocations'],
        ['GET', '/api/dashboard-stats', 'ApiController', 'dashboardStats'],
        ['GET', '/api/notifications-count', 'ApiController', 'notificationsCount'],
        ['GET', '/api/utilisateurs-datatable', 'ApiController', 'usersDatatable'],
        ['GET', '/api/journal-datatable', 'ApiController', 'journalDatatable'],
        ['GET', '/api/journal-detail/{id}', 'ApiController', 'journalDetail'],
        ['GET', '/api/connexions-datatable', 'ApiController', 'connexionsDatatable'],
    ],
    'exports' => [
        ['GET', '/exports/statistiques/excel', 'ExportController', 'statistiquesExcel'],
        ['GET', '/exports/formulaires/excel', 'ExportController', 'formulairesExcel'],
        ['GET', '/exports/formulaires/pdf', 'ExportController', 'formulairesPdf'],
        ['GET', '/exports/formulaires/word', 'ExportController', 'formulairesWord'],
        ['GET', '/exports/formulaires/csv', 'ExportController', 'formulairesCsv'],
    ],
];
