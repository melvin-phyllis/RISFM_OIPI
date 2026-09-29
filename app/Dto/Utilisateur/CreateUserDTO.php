<?php
declare(strict_types=1);

namespace App\Dto\Utilisateur;

/** Nouveau compte utilisateur. */
final class CreateUserDTO
{
    public function __construct(
        public readonly string $nom,
        public readonly string $prenoms,
        public readonly string $email,
        public readonly string $telephone,
        public readonly string $service,
        public readonly string $role,
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
            service: (string) ($data['service'] ?? ''),
            role: (string) ($data['role'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'nom'       => $this->nom,
            'prenoms'   => $this->prenoms,
            'email'     => $this->email,
            'telephone' => $this->telephone,
            'service'   => $this->service,
            'role'      => $this->role,
        ];
    }
}
