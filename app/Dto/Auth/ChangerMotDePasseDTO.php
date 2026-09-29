<?php
declare(strict_types=1);

namespace App\Dto\Auth;

/** Changement de son propre mot de passe. */
final class ChangerMotDePasseDTO
{
    public function __construct(
        public readonly string $actuel,
        public readonly string $nouveau,
        public readonly string $confirmation,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            actuel: (string) ($data['mot_de_passe_actuel'] ?? ''),
            nouveau: (string) ($data['mot_de_passe'] ?? ''),
            confirmation: (string) ($data['mot_de_passe_confirmation'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'actuel'       => $this->actuel,
            'nouveau'      => $this->nouveau,
            'confirmation' => $this->confirmation,
        ];
    }
}
