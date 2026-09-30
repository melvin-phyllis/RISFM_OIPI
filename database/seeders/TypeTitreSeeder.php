<?php
declare(strict_types=1);

namespace Database\Seeders;

/** Types de titres de propriete intellectuelle geres par l'OIPI (nomenclature officielle). */
final class TypeTitreSeeder extends Seeder
{
    private const TYPES = [
        ['code' => 'BRV', 'libelle' => 'Brevet', 'ordre' => 1],
        ['code' => 'DMI', 'libelle' => 'Dessin & Modèle Industriel', 'ordre' => 2],
        ['code' => 'IG', 'libelle' => 'Indication Géographique', 'ordre' => 3],
        ['code' => 'MAQ', 'libelle' => 'Marque', 'ordre' => 4],
        ['code' => 'MC', 'libelle' => 'Marque Collective', 'ordre' => 5],
        ['code' => 'MU', 'libelle' => 'Modèle d\'Utilité', 'ordre' => 6],
        ['code' => 'NC', 'libelle' => 'Nom Commercial', 'ordre' => 7],
        ['code' => 'OV', 'libelle' => 'Obtention Végétale', 'ordre' => 8],
    ];

    public function run(): string
    {
        $ajoutes = $this->insererSiAbsent('types_titres', self::TYPES);
        return sprintf('%d types de titres (%d ajoutes)', count(self::TYPES), $ajoutes);
    }
}
