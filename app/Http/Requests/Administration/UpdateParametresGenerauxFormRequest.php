<?php
declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Core\Auth;
use App\Core\FormRequest;
use App\Core\Logger;
use App\Exceptions\ValidationException;

/** Nom, couleurs, duree de session et logo de l'application. */
final class UpdateParametresGenerauxFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'app_nom'                  => 'default:OIPI - RISFM|required|max:100',
            'couleur_primaire'         => 'default:#F68B1F|required|regex:/^#[0-9a-fA-F]{6}$/',
            'couleur_secondaire'       => 'default:#00A651|required|regex:/^#[0-9a-fA-F]{6}$/',
            'couleur_accent'           => 'default:#17352B|required|regex:/^#[0-9a-fA-F]{6}$/',
            'session_lifetime_minutes' => 'default:20|string',
            'logo'                     => 'file:png,jpg,jpeg',
        ];
    }

    public function messages(): array
    {
        return [
            'app_nom' => 'Le nom ou les couleurs fournis sont invalides.',
            'couleur_primaire' => 'Le nom ou les couleurs fournis sont invalides.',
            'couleur_secondaire' => 'Le nom ou les couleurs fournis sont invalides.',
            'couleur_accent' => 'Le nom ou les couleurs fournis sont invalides.',
            'logo' => 'Logo invalide (formats acceptes : PNG, JPG, JPEG).',
        ];
    }

    /** Un logo refuse pour son type est trace comme evenement de securite. */
    public function failed(ValidationException $exception): void
    {
        if ($exception->field === 'logo') {
            Logger::log(Auth::id(), 'securite', 'Logo refuse : type de fichier invalide');
        }
    }
}
