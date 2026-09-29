<?php
declare(strict_types=1);

namespace App\Dto\Formulaire;

/** Fichier televerse et deja valide (nom, chemin temporaire, taille, type). */
final class AjouterPieceJointeDTO
{
    public function __construct(
        public readonly array $fichier,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            fichier: (array) ($data['piece'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'fichier' => $this->fichier,
        ];
    }
}
