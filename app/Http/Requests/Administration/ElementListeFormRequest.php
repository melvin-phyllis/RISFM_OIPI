<?php
declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Core\FormRequest;
use App\Services\Administration\ParametreService;

/** Element d'une liste de reference administrable. */
final class ElementListeFormRequest extends FormRequest
{
    /** Les champs dependent de la liste modifiee. */
    public function rules(): array
    {
        $type = $this->route('type');
        $rules = ['libelle' => 'required|max:' . self::longueurMax($type)];
        if ($type === 'statuts') {
            $rules['couleur'] = 'default:secondary|in:' . implode(',', ParametreService::COULEURS_STATUT);
        }
        if (in_array($type, ['types_titres', 'directions'], true)) {
            $rules['ordre'] = 'string';
        }
        if ($type === 'services') {
            $rules['abreviation'] = 'max:30';
            $rules['direction_id'] = 'required|id';
            $rules['ordre'] = 'string';
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'libelle' => 'Le libelle est obligatoire et limite a ' . self::longueurMax($this->route('type')) . ' caracteres.',
            'couleur' => 'La couleur de statut sélectionnée est invalide.',
            'abreviation' => 'L’abreviation ne doit pas depasser 30 caracteres.',
            'direction_id' => 'La direction de rattachement est obligatoire.',
        ];
    }

    private static function longueurMax(?string $type): int
    {
        return in_array($type, ['localisations', 'directions', 'services'], true) ? 150 : 100;
    }
}
