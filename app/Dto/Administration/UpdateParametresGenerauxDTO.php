<?php
declare(strict_types=1);

namespace App\Dto\Administration;

/** Parametres generaux de l'application. */
final class UpdateParametresGenerauxDTO
{
    public function __construct(
        public readonly string $app_nom,
        public readonly string $couleur_primaire,
        public readonly string $couleur_secondaire,
        public readonly string $couleur_accent,
        public readonly string $session_lifetime_minutes,
        public readonly ?array $logo,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            app_nom: (string) ($data['app_nom'] ?? ''),
            couleur_primaire: (string) ($data['couleur_primaire'] ?? ''),
            couleur_secondaire: (string) ($data['couleur_secondaire'] ?? ''),
            couleur_accent: (string) ($data['couleur_accent'] ?? ''),
            session_lifetime_minutes: (string) ($data['session_lifetime_minutes'] ?? ''),
            logo: $data['logo'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'app_nom'                  => $this->app_nom,
            'couleur_primaire'         => $this->couleur_primaire,
            'couleur_secondaire'       => $this->couleur_secondaire,
            'couleur_accent'           => $this->couleur_accent,
            'session_lifetime_minutes' => $this->session_lifetime_minutes,
            'logo'                     => $this->logo,
        ];
    }
}
