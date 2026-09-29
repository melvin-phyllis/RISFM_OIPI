<?php
declare(strict_types=1);

namespace Database\Seeders;

/** Types de titres de propriete intellectuelle geres par l'OIPI. */
final class TypeTitreSeeder extends Seeder
{
    private const TYPES = [
        ['code' => 'marque', 'libelle' => 'Marque', 'ordre' => 1],
        ['code' => 'nom_commercial', 'libelle' => 'Noms commerciaux', 'ordre' => 2],
        ['code' => 'dmi', 'libelle' => 'Dessins et Modeles Industriels', 'ordre' => 3],
        ['code' => 'brevet', 'libelle' => 'Brevet', 'ordre' => 4],
        ['code' => 'modele_utilite', 'libelle' => 'Modeles d\'utilite', 'ordre' => 5],
        ['code' => 'obtention_vegetale', 'libelle' => 'Obtentions vegetales', 'ordre' => 6],
        ['code' => 'autre', 'libelle' => 'Autres', 'ordre' => 7],
    ];

    public function run(): string
    {
        $ajoutes = $this->insererSiAbsent('types_titres', self::TYPES);
        return sprintf('%d types de titres (%d ajoutes)', count(self::TYPES), $ajoutes);
    }
}
