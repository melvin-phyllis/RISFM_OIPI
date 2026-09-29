<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Donnee de formulaire refusee par un FormRequest : premiere erreur seulement. */
final class ValidationException extends RuntimeException
{
    /**
     * @param string $field champ refuse
     * @param string $rule  regle non respectee (required, file, max_mb...)
     */
    public function __construct(string $message, public readonly string $field, public readonly string $rule = '')
    {
        parent::__construct($message);
    }
}
