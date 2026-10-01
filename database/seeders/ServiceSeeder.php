<?php
declare(strict_types=1);

namespace Database\Seeders;

/**
 * Services de l'OIPI, repris de l'organigramme de l'ERP (directions,
 * sous-directions et services de la Direction generale). La liste reste
 * modifiable dans Administration > Configuration > Listes metier.
 */
final class ServiceSeeder extends Seeder
{

    /** code, abreviation, libelle, direction_code, ordre */
    private const SERVICES = [
        ['DG', 'DG', 'Bureau du directeur général', 'DG', 10],
        ['AC', 'AC', 'Agence comptable', 'DG', 11],
        ['CB', 'CB', 'Contrôle budgétaire', 'DG', 12],
        ['SECR_DG', 'SECR DG', 'Secrétariat DG et protocole', 'DG', 13],
        ['SCE_COM_VULG', 'SCE COM VULG', 'Service communication et vulgarisation de la propriété intellectuelle', 'DG', 14],
        ['SCE_CISE', 'SCE CISE', 'Service contrôle interne et suivi-évaluation', 'DG', 15],
        ['SCE_CMR', 'SCE CMR', 'Service coopération et mobilisation des ressources', 'DG', 16],
        ['SCE_PATRIMOINE', 'SCE PATRIMOINE', 'Service gestion du patrimoine', 'DG', 17],
        ['SCE_JURIDIQUE', 'SCE JURIDIQUE', 'Service juridique', 'DG', 18],

        ['DBIITT', 'DBIITT', 'Bureau du directeur', 'DBIITT', 20],
        ['SDSPIACT', 'SDSPIACT', 'Sous-direction du soutien à la protection des inventions et autres créations techniques', 'DBIITT', 21],
        ['SDVTSITT', 'SDVTSITT', 'Sous-direction de la veille technologique, du soutien à l\'innovation et au transfert de technologie', 'DBIITT', 22],

        ['DMDMIDAQE', 'DMDMIDAQE', 'Bureau du directeur', 'DMDMIDAQE', 30],
        ['SDSPM_DMI', 'SDSPM DMI', 'Sous-direction du soutien à la protection des marques et des DMI', 'DMDMIDAQE', 31],
        ['SDDAQE', 'SDDAQE', 'Sous-direction des droits d\'auteurs et des questions émergentes', 'DMDMIDAQE', 32],

        ['DIGMCDS', 'DIGMCDS', 'Bureau du directeur', 'DIGMCDS', 40],
        ['SDIGMC', 'SDIGMC', 'Sous-direction des indications géographiques et des marques collectives', 'DIGMCDS', 41],
        ['SDDS', 'SDDS', 'Sous-direction du développement des services', 'DIGMCDS', 42],

        ['DSIDS', 'DSIDS', 'Bureau du directeur', 'DSIDS', 50],
        ['SDSID', 'SDSID', 'Sous-direction du système d\'information et de la documentation', 'DSIDS', 51],
        ['SDES', 'SDES', 'Sous-direction des études et des statistiques', 'DSIDS', 52],

        ['DCHBC', 'DCHBC', 'Bureau du directeur', 'DCHBC', 60],
        ['SDCH', 'SDCH', 'Sous-direction du capital humain', 'DCHBC', 61],
        ['SDBC', 'SDBC', 'Sous-direction du budget et de la comptabilité', 'DCHBC', 62],
    ];

    public function run(): string
    {
        (new DirectionSeeder($this->db))->run();
        $rows = array_map(
            fn (array $s): array => [
                'code' => $s[0],
                'abreviation' => $s[1],
                'libelle' => $s[2],
                'direction_id' => $this->idPar('directions', 'code', $s[3]),
                'ordre' => $s[4],
            ],
            self::SERVICES
        );
        $ajoutes = $this->insererSiAbsent('services', $rows);
        return sprintf('%d services (%d ajoutes)', count($rows), $ajoutes);
    }
}
