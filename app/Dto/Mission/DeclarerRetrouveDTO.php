<?php
declare(strict_types=1);

namespace App\Dto\Mission;

/** Declaration directe d'un formulaire retrouve. */
final class DeclarerRetrouveDTO
{
    public function __construct(
        public readonly int $localisation_id,
        public readonly string $date,
        public readonly string $precision,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            localisation_id: (int) ($data['retrouve_localisation_id'] ?? 0),
            date: (string) ($data['retrouve_date'] ?? ''),
            precision: (string) ($data['retrouve_precision'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'localisation_id' => $this->localisation_id,
            'date'            => $this->date,
            'precision'       => $this->precision,
        ];
    }
}
