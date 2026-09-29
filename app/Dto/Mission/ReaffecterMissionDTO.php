<?php
declare(strict_types=1);

namespace App\Dto\Mission;

/** Transfert d'une mission active a un autre responsable. */
final class ReaffecterMissionDTO
{
    public function __construct(
        public readonly int $responsable_id,
        public readonly ?string $date_echeance,
        public readonly string $priorite,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            responsable_id: (int) ($data['reaffectation_responsable_id'] ?? ''),
            date_echeance: $data['reaffectation_date_echeance'] ?? null,
            priorite: (string) ($data['reaffectation_priorite'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'responsable_id' => $this->responsable_id,
            'date_echeance'  => $this->date_echeance,
            'priorite'       => $this->priorite,
        ];
    }
}
