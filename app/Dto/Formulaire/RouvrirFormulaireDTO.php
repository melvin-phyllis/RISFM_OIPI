<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Reouverture d'un dossier resolu dans un nouveau cycle. */
final class RouvrirFormulaireDTO
{
    public function __construct(
        public readonly string $motif,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            motif: (string) ($data['reouverture_motif'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'motif' => $this->motif,
        ];
    }
}
