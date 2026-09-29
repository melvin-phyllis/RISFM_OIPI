<?php
declare(strict_types=1);

namespace App\Dto\Mission;

/** Resultat saisi a la cloture d'une mission. */
final class EnregistrerResultatMissionDTO
{
    public function __construct(
        public readonly string $resultat_code,
        public readonly string $date_recherche,
        public readonly string $resultat,
        public readonly string $observations,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            resultat_code: (string) ($data['recherche_resultat_code'] ?? ''),
            date_recherche: (string) ($data['recherche_date'] ?? ''),
            resultat: (string) ($data['recherche_resultat'] ?? ''),
            observations: (string) ($data['recherche_observations'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'resultat_code'  => $this->resultat_code,
            'date_recherche' => $this->date_recherche,
            'resultat'       => $this->resultat,
            'observations'   => $this->observations,
        ];
    }
}
