<?php
declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Operation valide mais qui demande une confirmation explicite de
 * l'utilisateur (par exemple une localisation deja inspectee). Le message est
 * affiche comme un avertissement, pas comme une erreur.
 */
final class ConfirmationRequiseException extends DomainException
{
}
