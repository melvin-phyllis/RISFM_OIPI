<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Validation d'une etape de finalisation (Numerise ou Saisi). */
final class AvancerFinalisationDTO
{
    public function __construct(
        public readonly string $etape,
        public readonly string $date,
        public readonly string $commentaire,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            etape: (string) ($data['finalisation_etape'] ?? ''),
            date: (string) ($data['finalisation_date'] ?? ''),
            commentaire: (string) ($data['finalisation_commentaire'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'etape'       => $this->etape,
            'date'        => $this->date,
            'commentaire' => $this->commentaire,
        ];
    }
}
