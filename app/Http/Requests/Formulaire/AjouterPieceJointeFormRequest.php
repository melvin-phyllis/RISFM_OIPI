<?php
declare(strict_types=1);

namespace App\Http\Requests\Formulaire;

use App\Core\Auth;
use App\Core\FormRequest;
use App\Core\Logger;
use App\Exceptions\ValidationException;

/** Piece jointe ajoutee a un dossier (PDF, JPG ou PNG). */
final class AjouterPieceJointeFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'piece' => 'required|file:pdf,jpg,jpeg,png|max_mb:' . UPLOAD_MAX_MB,
        ];
    }

    public function messages(): array
    {
        return [
            'piece.required' => 'Aucun fichier selectionne.',
            'piece.upload' => 'Fichier invalide (formats acceptes : PDF, JPG, PNG).',
            'piece.file' => 'Fichier invalide (formats acceptes : PDF, JPG, PNG).',
            'piece.max_mb' => 'Le fichier depasse la taille maximale autorisee (' . UPLOAD_MAX_MB . ' Mo).',
        ];
    }

    /** Un fichier refuse pour son type est trace comme evenement de securite. */
    public function failed(ValidationException $exception): void
    {
        if (in_array($exception->rule, ['file', 'upload'], true)) {
            Logger::log(
                Auth::id(),
                'securite',
                'Televersement refuse pour le formulaire #' . $this->route('formulaire_id') . ' : type invalide'
            );
        }
    }
}
