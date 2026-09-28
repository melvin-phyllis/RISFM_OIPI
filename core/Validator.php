<?php
declare(strict_types=1);

/**
 * Validateur minimaliste de tableaux de donnees (equivalent $_POST).
 * Regles supportees: required, email, min:N, max:N, numeric, in:a,b,c, date
 */
class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($rulesList as $rule) {
                $param = null;
                if (str_contains($rule, ':')) {
                    [$rule, $param] = explode(':', $rule, 2);
                }

                switch ($rule) {
                    case 'required':
                        if ($value === null || trim((string) $value) === '') {
                            $this->addError($field, 'Ce champ est obligatoire.');
                        }
                        break;
                    case 'email':
                        if ($value !== null && $value !== '' && !Security::isValidEmail((string) $value)) {
                            $this->addError($field, 'Adresse e-mail invalide.');
                        }
                        break;
                    case 'min':
                        if ($value !== null && mb_strlen((string) $value) < (int) $param) {
                            $this->addError($field, "Ce champ doit contenir au moins {$param} caracteres.");
                        }
                        break;
                    case 'max':
                        if ($value !== null && mb_strlen((string) $value) > (int) $param) {
                            $this->addError($field, "Ce champ ne doit pas depasser {$param} caracteres.");
                        }
                        break;
                    case 'numeric':
                        if ($value !== null && $value !== '' && !is_numeric($value)) {
                            $this->addError($field, 'Ce champ doit etre numerique.');
                        }
                        break;
                    case 'in':
                        $allowed = explode(',', (string) $param);
                        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
                            $this->addError($field, 'Valeur non autorisee.');
                        }
                        break;
                    case 'date':
                        if ($value !== null && $value !== '' && !strtotime((string) $value)) {
                            $this->addError($field, 'Date invalide.');
                        }
                        break;
                }
            }
        }

        return empty($this->errors);
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0];
        }
        return null;
    }
}
