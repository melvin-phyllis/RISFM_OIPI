<?php
declare(strict_types=1);

namespace App\Http\Requests\Mission;

use App\Core\FormRequest;

/** Annulation d'une mission active, avec un motif obligatoire. */
final class AnnulerMissionFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'annulation_motif' => 'required|between:10,500',
        ];
    }

    public function messages(): array
    {
        return [
            'annulation_motif' => 'Le motif d’annulation est obligatoire et doit contenir entre 10 et 500 caracteres.',
        ];
    }
}
