<?php
declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\FormRequest;

/** Demande de lien de reinitialisation (formulaire public). */
final class MotDePasseOublieFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }

    public function messages(): array
    {
        return [
            'email' => 'Adresse e-mail invalide.',
        ];
    }

    /** Formulaire public : l'utilisateur n'est pas connecte. */
    public function authorize(): bool
    {
        return true;
    }
}
