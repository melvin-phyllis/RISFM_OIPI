<?php
declare(strict_types=1);

namespace App\Dto\Auth;

/** Nouveau mot de passe et sa confirmation. */
final class ReinitialiserMotDePasseDTO
{
    public function __construct(
        public readonly string $mot_de_passe,
        public readonly string $confirmation,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            mot_de_passe: (string) ($data['mot_de_passe'] ?? ''),
            confirmation: (string) ($data['mot_de_passe_confirmation'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'mot_de_passe' => $this->mot_de_passe,
            'confirmation' => $this->confirmation,
        ];
    }
}
