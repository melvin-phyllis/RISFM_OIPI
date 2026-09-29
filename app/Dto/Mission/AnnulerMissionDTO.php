<?php
declare(strict_types=1);

namespace App\Dto\Mission;

/** Annulation d'une mission active. */
final class AnnulerMissionDTO
{
    public function __construct(
        public readonly string $motif,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            motif: (string) ($data['annulation_motif'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'motif' => $this->motif,
        ];
    }
}
