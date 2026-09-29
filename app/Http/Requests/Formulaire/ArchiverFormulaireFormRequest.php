<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\FormRequest;

/** Archivage d'un dossier, avec un motif obligatoire. */
final class ArchiverFormulaireFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'motif_archivage' => 'required|between:10,500',
        ];
    }

    public function messages(): array
    {
        return [
            'motif_archivage' => 'Le motif d’archivage doit contenir entre 10 et 500 caracteres.',
        ];
    }
}
