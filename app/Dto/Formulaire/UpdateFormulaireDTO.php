<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Modification des informations generales d'un formulaire (l'annee et la recherche ne changent pas ici). */
final class UpdateFormulaireDTO
{
    public function __construct(
        public readonly int $type_titre_id,
        public readonly string $numero_formulaire,
        public readonly string $priorite,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            type_titre_id: (int) ($data['type_titre_id'] ?? ''),
            numero_formulaire: (string) ($data['numero_formulaire'] ?? ''),
            priorite: (string) ($data['priorite'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'type_titre_id'     => $this->type_titre_id,
            'numero_formulaire' => $this->numero_formulaire,
            'priorite'          => $this->priorite,
        ];
    }
}
