<?php
declare(strict_types=1);

namespace App\Http\Requests\Mission;

use App\Core\FormRequest;

/** Affectation d'une mission de recherche. */
final class AffecterMissionFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'affectation_localisation_id'                        => 'required|id',
            'affectation_responsable_id'                         => 'required|id',
            'affectation_date_echeance'                          => 'nullable|date|not_past',
            'affectation_priorite'                               => 'default:Normale|in:Basse,Normale,Haute,Urgente',
            'confirmer_affectation_localisation_deja_recherchee' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'affectation_localisation_id' => 'La localisation a inspecter est obligatoire.',
            'affectation_responsable_id' => 'Le responsable de la recherche est obligatoire.',
            'affectation_date_echeance.date' => 'La date limite de recherche doit etre une date valide.',
            'affectation_date_echeance.not_past' => 'La date limite de recherche ne peut pas etre situee dans le passe.',
            'affectation_priorite' => 'La priorite selectionnee est invalide.',
        ];
    }
}
