<?php
declare(strict_types=1);

namespace Database\Seeders;

/** Directions officielles de l'organigramme OIPI. */
final class DirectionSeeder extends Seeder
{
    /** code, libelle, ordre */
    private const DIRECTIONS = [
        ['DG', 'Direction générale (DG)', 10],
        ['DBIITT', 'Direction des brevets d\'invention, de l\'innovation et du transfert de technologie (DBIITT)', 20],
        ['DMDMIDAQE', 'Direction des marques, des DMI, des droits d\'auteurs et des questions émergentes (DMDMIDAQE)', 30],
        ['DIGMCDS', 'Direction des indications géographiques, des marques collectives et du développement des services (DIGMCDS)', 40],
        ['DSIDS', 'Direction du système d\'information, de la documentation et des statistiques (DSIDS)', 50],
        ['DCHBC', 'Direction du capital humain, du budget et de la comptabilité (DCHBC)', 60],
    ];

    public function run(): string
    {
        $rows = array_map(
            static fn (array $direction): array => [
                'code' => $direction[0],
                'libelle' => $direction[1],
                'ordre' => $direction[2],
            ],
            self::DIRECTIONS
        );
        $ajoutees = $this->insererSiAbsent('directions', $rows);
        return sprintf('%d directions (%d ajoutees)', count($rows), $ajoutees);
    }
}
