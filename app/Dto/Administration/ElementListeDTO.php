<?php
declare(strict_types=1);

namespace App\Dto\Administration;

/** Element d'une liste de reference ; couleur et ordre selon la liste. */
final class ElementListeDTO
{
    public function __construct(
        public readonly string $libelle,
        public readonly ?string $couleur,
        public readonly ?string $ordre,
        public readonly string $abreviation,
        public readonly int $direction_id,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            libelle: (string) ($data['libelle'] ?? ''),
            couleur: $data['couleur'] ?? null,
            ordre: $data['ordre'] ?? null,
            abreviation: (string) ($data['abreviation'] ?? ''),
            direction_id: (int) ($data['direction_id'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'libelle' => $this->libelle,
            'couleur' => $this->couleur,
            'ordre'   => $this->ordre,
            'abreviation' => $this->abreviation,
            'direction_id' => $this->direction_id,
        ];
    }
}
