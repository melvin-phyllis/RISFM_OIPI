<?php
declare(strict_types=1);

namespace App\Http\Requests\Mission;

use App\Core\FormRequest;

/** Declaration directe d'un formulaire retrouve, sans mission prealable. */
final class DeclarerRetrouveFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'retrouve_localisation_id' => 'required|id',
            'retrouve_date'            => 'required|date|not_future',
            'retrouve_precision'       => 'nullable|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'retrouve_localisation_id' => 'Indiquez la localisation ou le formulaire a ete retrouve.',
            'retrouve_date' => 'La date de decouverte est obligatoire et ne peut pas etre situee dans le futur.',
            'retrouve_precision' => 'La precision ne doit pas depasser 200 caracteres.',
        ];
    }
}
