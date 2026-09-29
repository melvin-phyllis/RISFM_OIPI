<?php
declare(strict_types=1);

namespace App\Http\Requests\Mission;

use App\Core\FormRequest;

/** Resultat d'une mission. La date et le resultat sont verifies par le service (regles partagees avec l'import). */
final class EnregistrerResultatMissionFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'recherche_resultat_code' => 'required|in:retrouve,non_retrouve,a_verifier',
            'recherche_date'          => 'string',
            'recherche_resultat'      => 'string',
            'recherche_observations'  => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'recherche_resultat_code' => 'Selectionnez un resultat valide pour cette mission.',
        ];
    }
}
