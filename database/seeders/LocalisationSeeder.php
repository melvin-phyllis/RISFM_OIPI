<?php
declare(strict_types=1);

namespace Database\Seeders;

/** Lieux ou un formulaire peut etre recherche. */
final class LocalisationSeeder extends Seeder
{
    private const LOCALISATIONS = [
        'Archives centrales',
        'Direction Technique',
        'Direction Juridique',
        'Salle des archives',
        'OAPI',
        'Service Documentation',
        'Bureau regional',
        'Deposant',
        'Cabinet conseil',
        'Autre',
    ];

    public function run(): string
    {
        $ajoutees = $this->insererSiAbsent('localisations', array_map(
            static fn (string $libelle): array => ['libelle' => $libelle],
            self::LOCALISATIONS
        ));
        return sprintf('%d localisations (%d ajoutees)', count(self::LOCALISATIONS), $ajoutees);
    }
}
