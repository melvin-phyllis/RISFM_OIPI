<?php
declare(strict_types=1);

namespace App\Http\Requests\Mission;

use App\Core\FormRequest;

/** Transfert d'une mission active a un autre responsable. */
final class ReaffecterMissionFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reaffectation_responsable_id' => 'required|id',
            'reaffectation_date_echeance'  => 'nullable|date|not_past',
            'reaffectation_priorite'       => 'required|in:Basse,Normale,Haute,Urgente',
        ];
    }

    public function messages(): array
    {
        return [
            'reaffectation_responsable_id' => 'Le responsable de la recherche est obligatoire.',
            'reaffectation_date_echeance.date' => 'La date limite de recherche doit etre une date valide.',
            'reaffectation_date_echeance.not_past' => 'La date limite de recherche ne peut pas etre situee dans le passe.',
            'reaffectation_priorite' => 'La priorite selectionnee est invalide.',
        ];
    }
}
