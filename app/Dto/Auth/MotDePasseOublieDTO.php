<?php
declare(strict_types=1);

namespace App\Dto\Auth;

/** Demande de reinitialisation. */
final class MotDePasseOublieDTO
{
    public function __construct(
        public readonly string $email,
    ) {
    }

    /** A partir des donnees validees par le FormRequest correspondant. */
    public static function fromArray(array $data): self
    {
        return new self(
            email: (string) ($data['email'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
