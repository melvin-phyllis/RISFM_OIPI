<?php
declare(strict_types=1);

namespace App\Dto\Utilisateur;

/** Informations modifiables d'un compte. */
final class UpdateUserDTO
{
    public function __construct(
        public readonly string $nom,
        public readonly string $prenoms,
        public readonly string $email,
        public readonly string $telephone,
        public readonly int $service_id,
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
            service_id: (int) ($data['service_id'] ?? 0),
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
            'service_id' => $this->service_id,
            'role'      => $this->role,
        ];
    }
}
