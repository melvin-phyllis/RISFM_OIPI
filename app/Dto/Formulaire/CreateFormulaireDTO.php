<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Declaration d'un nouveau formulaire manquant. */
final class CreateFormulaireDTO
{
    public function __construct(
        public readonly int $type_titre_id,
        public readonly int $annee,
        public readonly string $numero_formulaire,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            type_titre_id: (int) ($data['type_titre_id'] ?? ''),
            annee: (int) ($data['annee'] ?? ''),
            numero_formulaire: (string) ($data['numero_formulaire'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'type_titre_id'     => $this->type_titre_id,
            'annee'             => $this->annee,
            'numero_formulaire' => $this->numero_formulaire,
        ];
    }
}
