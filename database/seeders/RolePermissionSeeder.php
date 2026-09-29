<?php
declare(strict_types=1);

namespace Database\Seeders;

/** Roles, permissions et droits accordes a chaque role. */
final class RolePermissionSeeder extends Seeder
{
    private const ROLES = [
        ['code' => 'administrateur', 'libelle' => 'Administrateur', 'description' => 'Acces complet: utilisateurs, statistiques, archivage, sauvegardes, configuration, journal, export, impression'],
        ['code' => 'responsable', 'libelle' => 'Responsable', 'description' => 'Peut declarer, modifier les metadonnees, affecter, traiter ses missions et exporter'],
        ['code' => 'agent', 'libelle' => 'Agent', 'description' => 'Peut declarer et traiter uniquement les recherches qui lui sont affectees'],
        ['code' => 'consultation', 'libelle' => 'Consultation', 'description' => 'Lecture seule'],
    ];

    /** code => [module, libelle] */
    private const PERMISSIONS = [
        'formulaires.view' => ['formulaires', 'Consulter les formulaires manquants'],
        'formulaires.create' => ['formulaires', 'Ajouter un formulaire manquant'],
        'formulaires.update_metadata' => ['formulaires', 'Modifier les informations generales'],
        'formulaires.assign' => ['formulaires', 'Affecter ou reaffecter une recherche'],
        'formulaires.record_result_any' => ['formulaires', 'Enregistrer tout resultat de recherche'],
        'formulaires.record_result_own' => ['formulaires', 'Enregistrer le resultat d une recherche affectee'],
        'formulaires.finalize' => ['formulaires', 'Valider la numerisation et la saisie d un formulaire retrouve'],
        'formulaires.reopen' => ['formulaires', 'Rouvrir un formulaire resolu apres correction'],
        'formulaires.attach_any' => ['formulaires', 'Ajouter une piece sur tout dossier'],
        'formulaires.attach_own' => ['formulaires', 'Ajouter une piece sur un dossier affecte'],
        'formulaires.delete_attachment_any' => ['formulaires', 'Supprimer toute piece jointe'],
        'formulaires.delete_attachment_own' => ['formulaires', 'Supprimer sa propre piece sur un dossier affecte'],
        'formulaires.archive' => ['formulaires', 'Archiver et restaurer un formulaire'],
        'formulaires.export' => ['formulaires', 'Exporter la liste des formulaires'],
        'dashboard.view' => ['dashboard', 'Acceder au tableau de bord'],
        'statistiques.view' => ['statistiques', 'Acceder aux statistiques detaillees'],
        'recherche.view' => ['recherche', 'Utiliser la recherche avancee'],
        'utilisateurs.manage' => ['utilisateurs', 'Gerer les utilisateurs'],
        'journal.view' => ['journal', 'Consulter le journal d\'audit'],
        'parametres.manage' => ['parametres', 'Gerer les parametres de l\'application'],
        'sauvegardes.manage' => ['sauvegardes', 'Creer/restaurer les sauvegardes'],
        'notifications.view' => ['notifications', 'Consulter ses notifications'],
        'profil.update' => ['profil', 'Modifier son propre profil'],
    ];

    /** Droits par role ; l'administrateur recoit toutes les permissions. */
    private const DROITS = [
        'responsable' => [
            'formulaires.view', 'formulaires.create', 'formulaires.update_metadata', 'formulaires.assign',
            'formulaires.record_result_own', 'formulaires.finalize', 'formulaires.attach_own',
            'formulaires.delete_attachment_own', 'formulaires.export',
            'dashboard.view', 'statistiques.view', 'recherche.view', 'journal.view',
            'notifications.view', 'profil.update',
        ],
        'agent' => [
            'formulaires.view', 'formulaires.create', 'formulaires.record_result_own',
            'formulaires.attach_own', 'formulaires.delete_attachment_own',
            'dashboard.view', 'recherche.view', 'notifications.view', 'profil.update',
        ],
        'consultation' => [
            'formulaires.view', 'dashboard.view', 'recherche.view', 'notifications.view', 'profil.update',
        ],
    ];

    public function run(): string
    {
        $roles = $this->insererSiAbsent('roles', self::ROLES);

        $permissions = [];
        foreach (self::PERMISSIONS as $code => [$module, $libelle]) {
            $permissions[] = ['code' => $code, 'module' => $module, 'libelle' => $libelle];
        }
        $ajouteesPermissions = $this->insererSiAbsent('permissions', $permissions);

        $droits = self::DROITS + ['administrateur' => array_keys(self::PERMISSIONS)];
        $lignes = [];
        foreach ($droits as $role => $codes) {
            $roleId = $this->idPar('roles', 'code', $role);
            foreach ($codes as $code) {
                $lignes[] = ['role_id' => $roleId, 'permission_id' => $this->idPar('permissions', 'code', $code)];
            }
        }
        $ajoutesDroits = $this->insererSiAbsent('role_permissions', $lignes);

        return sprintf(
            '%d roles, %d permissions, %d droits (%d + %d + %d ajoutes)',
            count(self::ROLES),
            count(self::PERMISSIONS),
            count($lignes),
            $roles,
            $ajouteesPermissions,
            $ajoutesDroits
        );
    }
}
