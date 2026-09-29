<?php
declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Core\Auth;
use App\Core\FormRequest;
use App\Core\Logger;
use App\Exceptions\ValidationException;

/** Restauration complete de la base : triple confirmation et fichier SQL (le mot de passe est verifie par le service). */
final class RestaurerSauvegardeFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirmation_critique'   => 'required|in:RESTAURER OIPI',
            'confirmer_perte_donnees' => 'accepted',
            'mot_de_passe_actuel'     => 'raw',
            'fichier_sql'             => 'required|file:sql|mimes:text/plain,application/sql,application/x-sql,application/octet-stream|max_mb:' . BACKUP_MAX_MB,
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_critique' => 'Confirmation refusee. Cochez l\'avertissement, saisissez exactement RESTAURER OIPI et confirmez votre mot de passe actuel.',
            'confirmer_perte_donnees' => 'Confirmation refusee. Cochez l\'avertissement, saisissez exactement RESTAURER OIPI et confirmez votre mot de passe actuel.',
            'fichier_sql.required' => 'Aucun fichier selectionne.',
            'fichier_sql.mimes' => 'Le contenu du fichier ne correspond pas a un fichier SQL texte.',
            'fichier_sql' => 'Le fichier doit etre un export .sql valide.',
        ];
    }

    /** Une confirmation critique manquante est tracee comme evenement de securite. */
    public function failed(ValidationException $exception): void
    {
        if (in_array($exception->field, ['confirmation_critique', 'confirmer_perte_donnees'], true)) {
            Logger::log(Auth::id(), 'securite', 'Tentative de restauration refusee : confirmation critique invalide');
        }
    }
}
