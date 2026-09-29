<?php
declare(strict_types=1);

namespace App\Http\Requests\Utilisateur;

use App\Core\FormRequest;

/** Mot de passe defini par un administrateur (la politique est verifiee par le service). */
final class DefinirMotDePasseFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nouveau_mot_de_passe' => 'raw',
            'force_change'         => 'boolean',
        ];
    }
}
