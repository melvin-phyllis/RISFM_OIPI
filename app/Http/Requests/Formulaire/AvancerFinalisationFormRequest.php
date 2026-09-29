<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\FormRequest;

/** Validation de l'etape suivante : Numerise puis Saisi. */
final class AvancerFinalisationFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'finalisation_etape'       => 'string',
            'finalisation_date'        => 'required|date|not_future',
            'finalisation_commentaire' => 'max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'finalisation_date' => 'La date de finalisation est invalide ou situee dans le futur.',
            'finalisation_commentaire' => 'Le commentaire de finalisation ne doit pas depasser 500 caracteres.',
        ];
    }
}
