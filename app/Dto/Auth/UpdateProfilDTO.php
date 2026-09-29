<?php
declare(strict_types=1);

namespace App\Dto\Auth;

/** Profil modifie par son titulaire. */
final class UpdateProfilDTO
{
    public function __construct(
        public readonly string $nom,
        public readonly string $prenoms,
        public readonly string $email,
        public readonly string $telephone,
        public readonly ?array $photo,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            nom: (string) ($data['nom'] ?? ''),
            prenoms: (string) ($data['prenoms'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            telephone: (string) ($data['telephone'] ?? ''),
            photo: $data['photo'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'nom'       => $this->nom,
            'prenoms'   => $this->prenoms,
            'email'     => $this->email,
            'telephone' => $this->telephone,
            'photo'     => $this->photo,
        ];
    }
}
