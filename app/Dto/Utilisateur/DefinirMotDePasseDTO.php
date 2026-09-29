<?php
declare(strict_types=1);

namespace App\Dto\Utilisateur;

/** Mot de passe defini par un administrateur. */
final class DefinirMotDePasseDTO
{
    public function __construct(
        public readonly string $mot_de_passe,
        public readonly bool $forcer_changement,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            mot_de_passe: (string) ($data['nouveau_mot_de_passe'] ?? ''),
            forcer_changement: (bool) ($data['force_change'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'mot_de_passe'      => $this->mot_de_passe,
            'forcer_changement' => $this->forcer_changement,
        ];
    }
}
