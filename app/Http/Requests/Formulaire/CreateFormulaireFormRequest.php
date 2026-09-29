<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\FormRequest;
use App\Services\Formulaire\FormulaireMetierValidator;

/** Declaration d'un formulaire manquant (le reste du dossier est rempli ensuite depuis sa fiche). */
final class CreateFormulaireFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_titre_id'     => 'required|id',
            'annee'             => 'required|year',
            'numero_formulaire' => 'required|max:60',
        ];
    }

    public function messages(): array
    {
        return [
            'type_titre_id' => 'Le type de titre selectionne est invalide.',
            'annee' => sprintf(
                'L’annee doit etre un nombre a quatre chiffres compris entre %d et %d.',
                FormulaireMetierValidator::MIN_YEAR,
                (int) date('Y')
            ),
            'numero_formulaire.required' => 'Le numero du formulaire est obligatoire.',
            'numero_formulaire.max' => 'Le numero du formulaire ne doit pas depasser 60 caracteres.',
        ];
    }
}
