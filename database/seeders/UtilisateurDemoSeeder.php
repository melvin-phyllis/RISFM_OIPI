<?php
declare(strict_types=1);

namespace Database\Seeders;

/**
 * Comptes de demonstration : un agent et un responsable. Reserve aux postes de
 * developpement et de recette, jamais a la production.
 */
final class UtilisateurDemoSeeder extends Seeder
{
    public const MOT_DE_PASSE = 'Demo@2026!';

    private const COMPTES = [
        [
            'nom' => 'Service', 'prenoms' => 'Documentation', 'email' => 'demo.documentation@oipi.test',
            'role' => 'agent', 'service' => 'SDSID',
        ],
        [
            'nom' => 'Chef', 'prenoms' => 'de projet', 'email' => 'demo.chef.projet@oipi.test',
            'role' => 'responsable', 'service' => 'DSIDS',
        ],
        [
            'nom' => 'Consultation', 'prenoms' => 'Direction generale', 'email' => 'demo.consultation@oipi.test',
            'role' => 'consultation', 'service' => 'DG',
        ],
    ];

    public function run(): string
    {
        $adminId = $this->db->query(
            "SELECT id FROM utilisateurs WHERE role = 'administrateur' ORDER BY id LIMIT 1"
        )->fetchColumn();
        $existe = $this->db->prepare('SELECT COUNT(*) FROM utilisateurs WHERE email = :email');

        $ajoutes = 0;
        foreach (self::COMPTES as $compte) {
            $existe->execute(['email' => $compte['email']]);
            if ((int) $existe->fetchColumn() > 0) {
                continue;
            }
            // 'service' designe le code du service (ServiceSeeder).
            $service = $compte['service'];
            unset($compte['service']);
            $this->creerUtilisateur($compte + [
                'service_id' => $this->idPar('services', 'code', $service),
                'mot_de_passe' => password_hash(self::MOT_DE_PASSE, PASSWORD_DEFAULT),
                'role_id' => $this->idPar('roles', 'code', $compte['role']),
                'actif' => 1,
                'doit_changer_mdp' => 1,
                'cree_par' => $adminId === false ? null : (int) $adminId,
            ]);
            $ajoutes++;
        }

        return sprintf(
            '%d comptes de demonstration (%d ajoutes, mot de passe %s)',
            count(self::COMPTES),
            $ajoutes,
            self::MOT_DE_PASSE
        );
    }
}
