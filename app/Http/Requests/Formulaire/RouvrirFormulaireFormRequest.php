<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\FormRequest;

/** Reouverture d'un dossier resolu. */
final class RouvrirFormulaireFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reouverture_motif' => 'required|between:10,500',
        ];
    }

    public function messages(): array
    {
        return [
            'reouverture_motif' => 'Le motif de réouverture est obligatoire et doit contenir entre 10 et 500 caracteres.',
        ];
    }
}
