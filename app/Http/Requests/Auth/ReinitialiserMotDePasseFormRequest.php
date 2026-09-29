<?php
declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\FormRequest;

/** Nouveau mot de passe choisi via un lien de reinitialisation (formulaire public, controles faits par le service). */
final class ReinitialiserMotDePasseFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'mot_de_passe'              => 'raw',
            'mot_de_passe_confirmation' => 'raw',
        ];
    }

    /** Formulaire public : l'utilisateur n'est pas connecte. */
    public function authorize(): bool
    {
        return true;
    }
}
