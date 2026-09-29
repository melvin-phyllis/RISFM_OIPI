<?php
declare(strict_types=1);

namespace App\Dto\Administration;

/** Demande de restauration confirmee. */
final class RestaurerSauvegardeDTO
{
    public function __construct(
        public readonly string $mot_de_passe_actuel,
        public readonly array $fichier,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            mot_de_passe_actuel: (string) ($data['mot_de_passe_actuel'] ?? ''),
            fichier: (array) ($data['fichier_sql'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'mot_de_passe_actuel' => $this->mot_de_passe_actuel,
            'fichier'             => $this->fichier,
        ];
    }
}
