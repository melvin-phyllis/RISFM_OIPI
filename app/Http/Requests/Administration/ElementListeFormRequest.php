<?php
declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Core\FormRequest;
use App\Services\Administration\ParametreService;

/** Element d'une liste de reference (type de titre, statut ou localisation). */
final class ElementListeFormRequest extends FormRequest
{
    /** Les champs dependent de la liste : types de titres, statuts ou localisations. */
    public function rules(): array
    {
        $type = $this->route('type');
        $rules = ['libelle' => 'required|max:' . self::longueurMax($type)];
        if ($type === 'statuts') {
            $rules['couleur'] = 'default:secondary|in:' . implode(',', ParametreService::COULEURS_STATUT);
        }
        if ($type === 'types_titres') {
            $rules['ordre'] = 'string';
        }
        return $rules;
    }

    public function messages(): array
    {
        return [
            'libelle' => 'Le libelle est obligatoire et limite a ' . self::longueurMax($this->route('type')) . ' caracteres.',
            'couleur' => 'La couleur de statut sélectionnée est invalide.',
        ];
    }

    private static function longueurMax(?string $type): int
    {
        return $type === 'localisations' ? 150 : 100;
    }
}
