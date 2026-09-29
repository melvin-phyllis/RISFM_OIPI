<?php
declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\Auth;
use App\Core\FormRequest;
use App\Core\Logger;
use App\Exceptions\ValidationException;

/** Mise a jour de son propre profil, photo comprise. */
final class UpdateProfilFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nom'       => 'required|max:100',
            'prenoms'   => 'required|max:100',
            'email'     => 'required|email|max:150',
            'telephone' => 'max:30',
            'photo'     => 'file:jpg,jpeg,png|max_mb:' . UPLOAD_MAX_MB,
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.max' => 'Le nom ne doit pas depasser 100 caracteres.',
            'prenoms.required' => 'Les prenoms sont obligatoires.',
            'prenoms.max' => 'Les prenoms ne doivent pas depasser 100 caracteres.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.max' => 'L’adresse e-mail ne doit pas depasser 150 caracteres.',
            'telephone' => 'Le telephone ne doit pas depasser 30 caracteres.',
            'photo.upload' => 'Le televersement de la photo a echoue. Verifiez sa taille puis reessayez.',
            'photo.file' => 'Format de photo non autorise (jpg, jpeg, png uniquement).',
            'photo.max_mb' => 'La photo depasse la taille maximale autorisee.',
        ];
    }

    /** Un fichier au contenu non conforme est une tentative a tracer. */
    public function failed(ValidationException $exception): void
    {
        if ($exception->field === 'photo' && $exception->rule === 'file') {
            Logger::log(Auth::id(), 'securite', 'Photo de profil refusee : type de fichier invalide');
        }
    }
}
