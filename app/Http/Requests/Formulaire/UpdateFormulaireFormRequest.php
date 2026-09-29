<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\FormRequest;

/** Modification des informations generales d'un formulaire. */
final class UpdateFormulaireFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_titre_id'     => 'required|id',
            'numero_formulaire' => 'required|max:60',
            'priorite'          => 'default:Normale|in:Basse,Normale,Haute,Urgente',
        ];
    }

    public function messages(): array
    {
        return [
            'type_titre_id' => 'Le type de titre selectionne est invalide.',
            'numero_formulaire.required' => 'Le numero du formulaire est obligatoire.',
            'numero_formulaire.max' => 'Le numero du formulaire ne doit pas depasser 60 caracteres.',
            'priorite' => 'La priorite selectionnee est invalide.',
        ];
    }
}
