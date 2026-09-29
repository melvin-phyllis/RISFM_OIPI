<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ValidationException;
use DateTimeImmutable;
use finfo;
use LogicException;

/**
 * Base des FormRequest : forme et format des donnees envoyees par un
 * formulaire, avant tout traitement metier.
 *
 * Une sous-classe declare ses regles et ses messages en francais ;
 * validated() retourne uniquement les champs declares, nettoyes et types, ou
 * leve une ValidationException portant la premiere erreur rencontree (dans
 * l'ordre des regles). Les verifications en base (reference existante,
 * compte actif, mot de passe actuel...) restent dans les services.
 *
 * Regles disponibles :
 *   required          valeur non vide (ou fichier fourni)
 *   nullable          valeur vide autorisee (retournee a null)
 *   string            texte libre (nettoye, sans autre contrainte)
 *   raw               valeur brute, ni nettoyee ni raccourcie (mots de passe) ;
 *                     jamais renvoyee dans le formulaire apres une erreur
 *   id                entier strictement positif, sans signe ni espace ("1abc" refuse)
 *   year              annee sur quatre chiffres
 *   email             adresse e-mail valide
 *   date              date AAAA-MM-JJ existante
 *   not_future        date au plus egale a aujourd'hui
 *   not_past          date au moins egale a aujourd'hui
 *   in:a,b,c          valeur parmi une liste
 *   regex:/motif/     valeur conforme au motif (sans | dans le motif)
 *   max:N / between:N,M   longueur en caracteres
 *   default:X         valeur utilisee si le champ est absent (avant validation)
 *   boolean           case a cocher : "1" => true, sinon false
 *   accepted          case a cocher obligatoirement cochee
 *   file:ext,ext      fichier televerse (champ de $_FILES) dont l'extension est
 *                     dans la liste et le contenu reel correspond (PDF, JPEG, PNG
 *                     verifies par Security::validatedUploadMime, sinon voir mimes)
 *   mimes:a/b,c/d     types MIME reels acceptes pour les autres extensions
 *   max_mb:N          taille maximale du fichier en Mo
 */
abstract class FormRequest
{
    /**
     * @param array $input donnees POST
     * @param array $files fichiers televerses ($_FILES)
     * @param array $route parametres de la route (ex. ['type' => 'localisations'])
     */
    public function __construct(
        private readonly array $input,
        private readonly array $files = [],
        private readonly array $route = [],
    ) {
    }

    /** @return array<string, string> champ => regles separees par | */
    abstract public function rules(): array;

    /**
     * Messages d'erreur : "champ.regle" (prioritaire) ou "champ" (toute regle).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /** L'utilisateur peut-il soumettre ce formulaire ? */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Appele quand la validation echoue, avant la redirection : permet par
     * exemple de journaliser un fichier refuse pour motif de securite.
     */
    public function failed(ValidationException $exception): void
    {
    }

    /** Parametre de la route courante (equivalent de $request->route() de Laravel). */
    protected function route(string $key, ?string $default = null): ?string
    {
        return isset($this->route[$key]) ? (string) $this->route[$key] : $default;
    }

    /**
     * Valeurs saisies, nettoyees, pour remplir de nouveau le formulaire apres
     * une erreur. Les champs "raw" (mots de passe) et les fichiers sont exclus.
     *
     * @return array<string, string>
     */
    final public function old(): array
    {
        $old = [];
        foreach ($this->rules() as $field => $ruleString) {
            $rules = $this->parse($ruleString);
            if (!array_key_exists('raw', $rules) && !array_key_exists('file', $rules)) {
                $raw = $this->input[$field] ?? '';
                $old[$field] = Security::cleanString(is_scalar($raw) ? (string) $raw : '');
            }
        }
        return $old;
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    final public function validated(): array
    {
        $messages = $this->messages();
        $data = [];
        foreach ($this->rules() as $field => $ruleString) {
            $rules = $this->parse($ruleString);

            if (array_key_exists('file', $rules)) {
                $data[$field] = $this->file($field, $rules, $messages);
                continue;
            }
            $raw = $this->input[$field] ?? ($rules['default'] ?? null);
            if (array_key_exists('boolean', $rules) || array_key_exists('accepted', $rules)) {
                $data[$field] = (string) $raw === '1';
                if (array_key_exists('accepted', $rules) && !$data[$field]) {
                    throw $this->erreur($field, 'accepted', $messages, 'Cette case doit etre cochee.');
                }
                continue;
            }
            if (array_key_exists('raw', $rules)) {
                $value = is_scalar($raw) ? (string) $raw : '';
            } else {
                $value = Security::cleanString(is_scalar($raw) ? (string) $raw : '');
            }

            if ($value === '') {
                if (array_key_exists('required', $rules)) {
                    throw $this->erreur($field, 'required', $messages, 'Ce champ est obligatoire.');
                }
                $data[$field] = array_key_exists('nullable', $rules) ? null : '';
                continue;
            }

            foreach ($rules as $name => $param) {
                $ok = match ($name) {
                    'id' => preg_match('/^[1-9][0-9]*$/', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'year' => preg_match('/^[0-9]{4}$/', $value) === 1,
                    'email' => Security::isValidEmail($value),
                    'date' => self::date($value) !== null,
                    'not_future' => ($d = self::date($value)) !== null && $d <= new DateTimeImmutable('today'),
                    'not_past' => ($d = self::date($value)) !== null && $d >= new DateTimeImmutable('today'),
                    'in' => in_array($value, explode(',', (string) $param), true),
                    'regex' => preg_match((string) $param, $value) === 1,
                    'max' => mb_strlen($value) <= (int) $param,
                    'between' => (static function () use ($value, $param): bool {
                        [$min, $max] = array_map('intval', explode(',', (string) $param));
                        return mb_strlen($value) >= $min && mb_strlen($value) <= $max;
                    })(),
                    'required', 'nullable', 'default', 'string', 'raw' => true,
                    default => throw new LogicException("Regle de validation inconnue : {$name}"),
                };
                if (!$ok) {
                    throw $this->erreur($field, $name, $messages, 'Valeur invalide.');
                }
            }
            $data[$field] = array_key_exists('id', $rules) || array_key_exists('year', $rules) ? (int) $value : $value;
        }
        return $data;
    }

    /**
     * @return array{name:string, tmp_name:string, size:int, extension:string, mime:string}|null
     */
    private function file(string $field, array $rules, array $messages): ?array
    {
        $file = $this->files[$field] ?? null;
        if (!is_array($file) || (string) ($file['name'] ?? '') === '' || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            if (array_key_exists('required', $rules)) {
                throw $this->erreur($field, 'required', $messages, 'Aucun fichier selectionne.');
            }
            return null;
        }
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK) {
            throw $this->erreur($field, 'upload', $messages, 'Le televersement du fichier a echoue.');
        }

        $name = (string) $file['name'];
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = explode(',', (string) $rules['file']);
        if (!in_array($extension, $allowed, true) || $size <= 0 || !is_file($tmp)) {
            throw $this->erreur($field, 'file', $messages, 'Type de fichier non autorise.');
        }

        if (array_key_exists('mimes', $rules)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!is_string($mime) || !in_array($mime, explode(',', (string) $rules['mimes']), true)) {
                throw $this->erreur($field, 'mimes', $messages, 'Le contenu du fichier ne correspond pas a son type.');
            }
        } else {
            $mime = Security::validatedUploadMime($tmp, $extension, $allowed);
            if ($mime === null) {
                throw $this->erreur($field, 'file', $messages, 'Type de fichier non autorise.');
            }
        }

        if (array_key_exists('max_mb', $rules) && $size > (int) $rules['max_mb'] * 1024 * 1024) {
            throw $this->erreur($field, 'max_mb', $messages, 'Le fichier depasse la taille maximale autorisee.');
        }

        return ['name' => $name, 'tmp_name' => $tmp, 'size' => $size, 'extension' => $extension, 'mime' => $mime];
    }

    /** @return array<string, ?string> regle => parametre */
    private function parse(string $ruleString): array
    {
        $rules = [];
        foreach (explode('|', $ruleString) as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $rules[$name] = $param;
        }
        return $rules;
    }

    private function erreur(string $field, string $rule, array $messages, string $defaut): ValidationException
    {
        return new ValidationException($messages["{$field}.{$rule}"] ?? $messages[$field] ?? $defaut, $field, $rule);
    }

    /** Date AAAA-MM-JJ reellement existante (2026-02-30 refusee). */
    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
