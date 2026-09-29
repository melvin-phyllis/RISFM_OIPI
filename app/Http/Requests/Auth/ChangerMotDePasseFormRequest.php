<?php
declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\FormRequest;

/** Changement de son propre mot de passe (controles faits par le service, dans l'ordre historique). */
final class ChangerMotDePasseFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'mot_de_passe_actuel'       => 'raw',
            'mot_de_passe'              => 'raw',
            'mot_de_passe_confirmation' => 'raw',
        ];
    }
}
