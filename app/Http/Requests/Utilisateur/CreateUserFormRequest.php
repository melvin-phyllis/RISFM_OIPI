<?php
declare(strict_types=1);

namespace App\Http\Requests\Utilisateur;

use App\Core\FormRequest;

/** Creation d'un compte (le mot de passe est choisi par l'utilisateur via un lien). */
final class CreateUserFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nom'       => 'required|max:100',
            'prenoms'   => 'required|max:100',
            'email'     => 'required|email|max:150',
            'telephone' => 'max:30',
            'service'   => 'max:100',
            'role'      => 'required|in:administrateur,responsable,agent,consultation',
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.max' => 'Le nom ne doit pas depasser 100 caracteres.',
            'prenoms.required' => 'Les prenoms sont obligatoires.',
            'prenoms.max' => 'Les prenoms ne doivent pas depasser 100 caracteres.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.max' => 'L’adresse e-mail ne doit pas depasser 150 caracteres.',
            'telephone' => 'Le telephone ne doit pas depasser 30 caracteres.',
            'service' => 'Le service ne doit pas depasser 100 caracteres.',
            'role' => 'Le role selectionne est invalide.',
        ];
    }
}
