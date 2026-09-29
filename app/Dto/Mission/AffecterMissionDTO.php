<?php
declare(strict_types=1);

namespace App\Dto\Mission;

/** Affectation d'une mission de recherche. */
final class AffecterMissionDTO
{
    public function __construct(
        public readonly int $localisation_id,
        public readonly int $responsable_id,
        public readonly ?string $date_echeance,
        public readonly string $priorite,
        public readonly bool $confirmer_localisation_deja_recherchee,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            localisation_id: (int) ($data['affectation_localisation_id'] ?? ''),
            responsable_id: (int) ($data['affectation_responsable_id'] ?? ''),
            date_echeance: $data['affectation_date_echeance'] ?? null,
            priorite: (string) ($data['affectation_priorite'] ?? ''),
            confirmer_localisation_deja_recherchee: (bool) ($data['confirmer_affectation_localisation_deja_recherchee'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'localisation_id'                        => $this->localisation_id,
            'responsable_id'                         => $this->responsable_id,
            'date_echeance'                          => $this->date_echeance,
            'priorite'                               => $this->priorite,
            'confirmer_localisation_deja_recherchee' => $this->confirmer_localisation_deja_recherchee,
        ];
    }
}
