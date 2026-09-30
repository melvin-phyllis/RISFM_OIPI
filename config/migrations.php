<?php
declare(strict_types=1);

/**
 * Ordre officiel des migrations applicatives.
 *
 * Les anciens scripts `fix_sp_kpi_connexions` et `admin_sp_kpi_archives` ne
 * figurent pas ici : le premier est obsolete et le second peut exiger
 * SYSTEM_USER selon son DEFINER. P10 gere la procedure canonique separement.
 */
return [
    ['name' => '20260719_01_historique_recherches', 'file' => '20260719_historique_recherches.sql'],
    ['name' => '20260719_02_journal_metier_archivage', 'file' => '20260719_journal_metier_archivage.sql'],
    ['name' => '20260719_03_date_resolution', 'file' => '20260719_p3_date_resolution.sql'],
    ['name' => '20260719_04_revocation_sessions', 'file' => '20260719_p4_revocation_sessions.sql'],
    ['name' => '20260719_05_palette_oipi', 'file' => '20260719_oipi_palette.sql'],
    ['name' => '20260719_06_identifiants_oipi', 'file' => '20260719_p7_migration_identifiants.sql'],
    ['name' => '20260719_07_tokens_reset_securises', 'file' => '20260719_p9_securisation_tokens_reset.sql'],
    ['name' => '20260719_08_notification_lectures', 'file' => '20260719_p11_notification_lectures.sql'],
    ['name' => '20260720_09_separation_affectation_recherche', 'file' => '20260720_separation_affectation_recherche.sql'],
    ['name' => '20260720_10_permissions_formulaires_granulaires', 'file' => '20260720_permissions_formulaires_granulaires.sql'],
    ['name' => '20260720_11_missions_recherche_simultanees', 'file' => '20260720_missions_recherche_simultanees.sql'],
    ['name' => '20260720_12_stats_missions_recherche', 'file' => '20260720_stats_missions_recherche.sql'],
    ['name' => '20260721_13_backoffice_listes_metier', 'file' => '20260721_backoffice_listes_metier.sql'],
    ['name' => '20260721_14_performance_grand_volume', 'file' => '20260721_performance_grand_volume.sql'],
    ['name' => '20260721_15_relances_missions', 'file' => '20260721_relances_missions.sql'],
    ['name' => '20260729_16_cycle_vie_responsables', 'file' => '20260729_cycle_vie_responsables.sql'],
    ['name' => '20260729_17_finalisation_formulaires', 'file' => '20260729_finalisation_formulaires.sql'],
    ['name' => '20260729_18_annulation_reaffectation_reouverture', 'file' => '20260729_annulation_reaffectation_reouverture.sql'],
    ['name' => '20260729_19_statuts_workflow_systeme', 'file' => '20260729_statuts_workflow_systeme.sql'],
    ['name' => '20260804_20_login_rate_limit_ip', 'file' => '20260804_login_rate_limit_ip.sql'],
    ['name' => '20260805_21_palette_oipi_verte', 'file' => '20260805_palette_oipi_verte.sql'],
    ['name' => '20260930_22_demandes_reinitialisation', 'file' => '20260930_demandes_reinitialisation.sql'],
    ['name' => '20260930_23_types_titres_officiels', 'file' => '20260930_types_titres_officiels.sql'],
    ['name' => '20260930_24_permissions_declaration_retrouve', 'file' => '20260930_permissions_declaration_retrouve.sql'],
    ['name' => '20260930_25_cles_etrangeres_missions', 'file' => '20260930_cles_etrangeres_missions.sql'],
];
