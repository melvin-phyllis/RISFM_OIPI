<?php
declare(strict_types=1);

/**
 * Validation metier commune aux formulaires et a leur historique de recherche.
 *
 * Cette classe ne fait aucune confiance aux listes affichees par le navigateur :
 * toutes les references et toutes les valeurs enumerees sont reverifiees en base.
 */
class FormulaireMetierValidator
{
    public const MIN_YEAR = 2006;

    private const PRIORITES = ['Basse', 'Normale', 'Haute', 'Urgente'];
    private const ROLES_RESPONSABLES = ['administrateur', 'responsable', 'agent'];

    public function validateForm(
        array $data,
        ?array $existing = null,
        bool $validateResearchState = true
    ): ?string
    {
        $typeId = $this->positiveInteger($data['type_titre_id'] ?? null);
        if ($typeId === null) {
            return 'Le type de titre selectionne est invalide.';
        }
        $type = (new TypeTitreModel())->find($typeId);
        if (!$type || (int) ($type['actif'] ?? 0) !== 1) {
            return 'Le type de titre selectionne est inexistant ou inactif.';
        }

        $yearValue = $existing !== null ? ($existing['annee'] ?? null) : ($data['annee'] ?? null);
        $year = $this->year($yearValue);
        $currentYear = (int) date('Y');
        if ($year === null || $year < self::MIN_YEAR || $year > $currentYear) {
            return sprintf(
                'L’annee doit etre un nombre a quatre chiffres compris entre %d et %d.',
                self::MIN_YEAR,
                $currentYear
            );
        }

        $numero = trim((string) ($data['numero_formulaire'] ?? ''));
        if ($numero === '') {
            return 'Le numero du formulaire est obligatoire.';
        }
        if (mb_strlen($numero) > 60) {
            return 'Le numero du formulaire ne doit pas depasser 60 caracteres.';
        }

        $statusId = $this->positiveInteger($data['statut_id'] ?? null);
        if ($statusId === null) {
            return 'Le statut selectionne est invalide.';
        }
        $status = (new StatutModel())->find($statusId);
        if (!$this->isUsableStatus($status)) {
            return 'Le statut selectionne est inexistant ou inutilisable.';
        }

        $locationError = $this->validateOptionalLocation($data['localisation_id'] ?? null);
        if ($locationError !== null) {
            return $locationError;
        }

        $responsibleError = $this->validateOptionalResponsible($data['responsable_id'] ?? null);
        if ($responsibleError !== null) {
            return $responsibleError;
        }

        $depositDate = $this->optionalDate($data['date_depot'] ?? null);
        if ($depositDate === false) {
            return 'La date de depot doit etre une date valide au format jour/mois/annee.';
        }
        if ($depositDate instanceof DateTimeImmutable && $depositDate > $this->today()) {
            return 'La date de depot ne peut pas etre situee dans le futur.';
        }

        $researchDate = $this->optionalDate($data['date_recherche'] ?? null);
        if ($researchDate === false) {
            return 'La date de recherche doit etre une date valide au format jour/mois/annee.';
        }
        if ($researchDate instanceof DateTimeImmutable && $researchDate > $this->today()) {
            return 'La date de recherche ne peut pas etre situee dans le futur.';
        }
        if ($depositDate instanceof DateTimeImmutable
            && $researchDate instanceof DateTimeImmutable
            && $researchDate < $depositDate
        ) {
            return 'La date de recherche ne peut pas etre anterieure a la date de depot.';
        }

        if (!in_array((string) ($data['priorite'] ?? ''), self::PRIORITES, true)) {
            return 'La priorite selectionnee est invalide.';
        }

        if (mb_strlen((string) ($data['deposant'] ?? '')) > 200) {
            return 'Le nom du deposant ne doit pas depasser 200 caracteres.';
        }
        if (mb_strlen((string) ($data['mandataire'] ?? '')) > 200) {
            return 'Le nom du mandataire ne doit pas depasser 200 caracteres.';
        }
        if (mb_strlen((string) ($data['resultat'] ?? '')) > 255) {
            return 'Le resultat de la recherche ne doit pas depasser 255 caracteres.';
        }

        // La modification des informations generales ne doit pas reecrire ni
        // revalider une ancienne recherche incomplete. Les changements de
        // recherche utilisent validateResearch() dans leur route dediee.
        if (!$validateResearchState) {
            return null;
        }

        // Le statut est la source de verite : tout statut marque comme resolu
        // doit etre justifie par une recherche complete et un resultat explicite.
        $statusChanged = $existing !== null
            && (int) ($existing['statut_id'] ?? 0) !== $statusId;
        $researchRequired = (int) ($status['resolu'] ?? 0) === 1
            || $statusChanged
            || $this->hasResearchDetails($data);
        if ($researchRequired) {
            return $this->validateResearch($data, $depositDate, $status);
        }

        return null;
    }

    /**
     * Valide une entree complete de l'historique des recherches.
     *
     * @param DateTimeImmutable|string|null $depositDate
     */
    public function validateResearch(
        array $data,
        DateTimeImmutable|string|null $depositDate = null,
        ?array $knownStatus = null
    ): ?string {
        $locationId = $this->positiveInteger($data['localisation_id'] ?? null);
        if ($locationId === null) {
            return 'La localisation inspectee est obligatoire pour enregistrer une recherche.';
        }
        $location = (new LocalisationModel())->find($locationId);
        if (!$location || (int) ($location['actif'] ?? 0) !== 1) {
            return 'La localisation selectionnee est inexistante ou inactive.';
        }

        $responsibleId = $this->positiveInteger($data['responsable_id'] ?? null);
        if ($responsibleId === null) {
            return 'Le responsable de la recherche est obligatoire.';
        }
        $responsible = (new UserModel())->find($responsibleId);
        if (!$responsible || (int) ($responsible['actif'] ?? 0) !== 1) {
            return 'Le responsable selectionne est inexistant ou inactif.';
        }
        if (!in_array((string) ($responsible['role'] ?? ''), self::ROLES_RESPONSABLES, true)) {
            return 'Le responsable selectionne ne possede pas un role autorise pour conduire une recherche.';
        }

        $statusId = $this->positiveInteger($data['statut_id'] ?? null);
        $status = $knownStatus;
        if ($statusId === null || $status === null || (int) ($status['id'] ?? 0) !== $statusId) {
            $status = $statusId === null ? null : (new StatutModel())->find($statusId);
        }
        if (!$this->isUsableStatus($status)) {
            return 'Le statut de la recherche est inexistant ou inutilisable.';
        }

        $researchDate = $this->requiredDate($data['date_recherche'] ?? null);
        if ($researchDate === null) {
            return 'La date de recherche est obligatoire et doit etre valide.';
        }
        if ($researchDate > $this->today()) {
            return 'La date de recherche ne peut pas etre situee dans le futur.';
        }

        if (is_string($depositDate)) {
            $depositDate = $this->requiredDate($depositDate);
        }
        if ($depositDate instanceof DateTimeImmutable && $researchDate < $depositDate) {
            return 'La date de recherche ne peut pas etre anterieure a la date de depot.';
        }

        $result = trim((string) ($data['resultat'] ?? ''));
        if ($result === '') {
            return (int) ($status['resolu'] ?? 0) === 1
                ? 'Un statut resolu exige un resultat de recherche explicite.'
                : 'Le resultat de la recherche est obligatoire (par exemple : En cours, Non retrouve ou Retrouve).';
        }
        if (mb_strlen($result) > 255) {
            return 'Le resultat de la recherche ne doit pas depasser 255 caracteres.';
        }

        return null;
    }

    /** Valide une affectation avant toute execution de la recherche. */
    public function validateAssignment(array $data): ?string
    {
        $locationId = $this->positiveInteger($data['localisation_id'] ?? null);
        if ($locationId === null) {
            return 'La localisation a inspecter est obligatoire.';
        }
        if (($error = $this->validateOptionalLocation($locationId)) !== null) {
            return $error;
        }

        $responsibleId = $this->positiveInteger($data['responsable_id'] ?? null);
        if ($responsibleId === null) {
            return 'Le responsable de la recherche est obligatoire.';
        }
        if (($error = $this->validateOptionalResponsible($responsibleId)) !== null) {
            return $error;
        }

        $deadline = $this->optionalDate($data['date_echeance_recherche'] ?? null);
        if ($deadline === false) {
            return 'La date limite de recherche doit etre une date valide.';
        }
        if ($deadline instanceof DateTimeImmutable && $deadline < $this->today()) {
            return 'La date limite de recherche ne peut pas etre situee dans le passe.';
        }

        if (!in_array((string) ($data['priorite'] ?? ''), self::PRIORITES, true)) {
            return 'La priorite selectionnee est invalide.';
        }

        return null;
    }

    private function validateOptionalLocation(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = $this->positiveInteger($value);
        if ($id === null) {
            return 'La localisation selectionnee est invalide.';
        }
        $location = (new LocalisationModel())->find($id);
        return (!$location || (int) ($location['actif'] ?? 0) !== 1)
            ? 'La localisation selectionnee est inexistante ou inactive.'
            : null;
    }

    private function validateOptionalResponsible(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = $this->positiveInteger($value);
        if ($id === null) {
            return 'Le responsable selectionne est invalide.';
        }
        $responsible = (new UserModel())->find($id);
        if (!$responsible || (int) ($responsible['actif'] ?? 0) !== 1) {
            return 'Le responsable selectionne est inexistant ou inactif.';
        }
        return !in_array((string) ($responsible['role'] ?? ''), self::ROLES_RESPONSABLES, true)
            ? 'Le responsable selectionne ne possede pas un role autorise.'
            : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = is_int($value) ? (string) $value : trim((string) $value);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? null : $integer;
    }

    private function year(mixed $value): ?int
    {
        $value = is_int($value) ? (string) $value : trim((string) $value);
        return preg_match('/^[0-9]{4}$/', $value) ? (int) $value : null;
    }

    /** @return DateTimeImmutable|false|null */
    private function optionalDate(mixed $value): DateTimeImmutable|false|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return $this->requiredDate($value) ?? false;
    }

    private function requiredDate(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d') === $value ? $date : null;
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    private function isUsableStatus(?array $status): bool
    {
        $code = trim((string) ($status['code'] ?? ''));
        return $status !== null
            && (int) ($status['actif'] ?? 1) === 1
            && (!array_key_exists('systeme', $status) || (int) $status['systeme'] === 1)
            && StatutModel::isWorkflowCode($code)
            && $code !== ''
            && trim((string) ($status['libelle'] ?? '')) !== '';
    }

    private function hasResearchDetails(array $data): bool
    {
        return trim((string) ($data['date_recherche'] ?? '')) !== ''
            || trim((string) ($data['localisation_id'] ?? '')) !== ''
            || trim((string) ($data['resultat'] ?? '')) !== ''
            || trim((string) ($data['observations'] ?? '')) !== '';
    }
}
