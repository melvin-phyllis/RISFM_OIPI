<?php
declare(strict_types=1);

use App\Http\Controllers\Formulaire\ArchivageController;
use App\Http\Controllers\Formulaire\ExportController;
use App\Http\Controllers\Formulaire\FinalisationController;
use App\Http\Controllers\Formulaire\FormulaireController;
use App\Http\Controllers\Formulaire\ImportController;
use App\Http\Controllers\Formulaire\PieceJointeController;

/**
 * Routes du module Formulaire — Registre des formulaires : fiche, import, finalisation, archivage, pieces jointes et exports.
 *
 * Format : [methode, chemin, controleur, action]. Les actions portent le
 * prefixe ctrl_ ; les parametres {x} sont transmis dans l ordre a l action.
 */
return [
    // Registre
    ['GET',  '/formulaires',                                    FormulaireController::class, 'ctrl_index'],
    ['GET',  '/formulaires/archives',                           FormulaireController::class, 'ctrl_archives'],
    ['GET',  '/formulaires/importer',                           ImportController::class, 'ctrl_index'],
    ['POST', '/formulaires/importer/analyser',                  ImportController::class, 'ctrl_analyse'],
    ['POST', '/formulaires/importer/confirmer',                 ImportController::class, 'ctrl_confirm'],
    ['POST', '/formulaires/importer/annuler',                   ImportController::class, 'ctrl_cancel'],
    ['GET',  '/formulaires/importer/rapport',                   ImportController::class, 'ctrl_report'],
    ['GET',  '/formulaires/importer/modele/{format}',           ImportController::class, 'ctrl_template'],
    ['GET',  '/formulaires/ajouter',                            FormulaireController::class, 'ctrl_create'],
    ['POST', '/formulaires/ajouter',                            FormulaireController::class, 'ctrl_store'],
    ['GET',  '/formulaires/voir/{id}',                          FormulaireController::class, 'ctrl_show'],
    ['POST', '/formulaires/modifier/{id}',                      FormulaireController::class, 'ctrl_update'],
    ['POST', '/formulaires/finaliser/{id}',                     FinalisationController::class, 'ctrl_advance'],
    ['POST', '/formulaires/reouvrir/{id}',                      FinalisationController::class, 'ctrl_reopen'],
    ['POST', '/formulaires/archiver/{id}',                      ArchivageController::class, 'ctrl_archive'],
    ['POST', '/formulaires/restaurer/{id}',                     ArchivageController::class, 'ctrl_restore'],
    ['POST', '/formulaires/piece-jointe/{id}',                  PieceJointeController::class, 'ctrl_upload'],
    ['GET',  '/formulaires/piece-jointe/telecharger/{id}',      PieceJointeController::class, 'ctrl_download'],
    ['POST', '/formulaires/piece-jointe/supprimer/{id}',        PieceJointeController::class, 'ctrl_delete'],
    ['GET',  '/recherche',                                      FormulaireController::class, 'ctrl_redirectLegacySearch'],

    // Exports
    ['GET',  '/exports/statistiques/excel',                     ExportController::class, 'ctrl_statistiquesExcel'],
    ['GET',  '/exports/formulaires/excel',                      ExportController::class, 'ctrl_formulairesExcel'],
    ['GET',  '/exports/formulaires/pdf',                        ExportController::class, 'ctrl_formulairesPdf'],
    ['GET',  '/exports/formulaires/word',                       ExportController::class, 'ctrl_formulairesWord'],
    ['GET',  '/exports/formulaires/csv',                        ExportController::class, 'ctrl_formulairesCsv'],
];
