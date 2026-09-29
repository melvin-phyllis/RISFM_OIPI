<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Archivage d'un dossier. */
final class ArchiverFormulaireDTO
{
    public function __construct(
        public readonly string $motif,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            motif: (string) ($data['motif_archivage'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'motif' => $this->motif,
        ];
    }
}
