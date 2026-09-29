<?php
declare(strict_types=1);

namespace App\Services\Administration;

use App\Core\Logger;
use App\Core\Repository;
use App\Core\Security;
use App\Dto\Administration\ElementListeDTO;
use App\Dto\Administration\UpdateParametresGenerauxDTO;
use App\Repositories\Administration\ParametreRepository;
use App\Repositories\Referentiel\LocalisationRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Referentiel\TypeTitreRepository;
use DomainException;

/**
 * Parametres generaux et listes de reference (types de titres, statuts,
 * localisations). Les sept statuts du workflow sont fixes : seuls leur libelle
 * et leur couleur sont personnalisables. Un refus metier est signale par une
 * DomainException.
 */
final class ParametreService
{
    /** Listes modifiables depuis l'administration. */
    public const LISTES = ['types_titres', 'statuts', 'localisations'];

    /** Couleurs Bootstrap autorisees pour un statut. */
    public const COULEURS_STATUT = [
        'primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'light',
    ];

    /** @param array $dataValidated donnees de UpdateParametresGenerauxFormRequest */
    public function srv_modifierGeneraux(array $dataValidated, int $acteurId): void
    {
        $dto = UpdateParametresGenerauxDTO::fromArray($dataValidated);
        $repository = new ParametreRepository();
        $repository->repo_set('app_nom', $dto->app_nom);
        $repository->repo_set('couleur_primaire', strtoupper($dto->couleur_primaire));
        $repository->repo_set('couleur_secondaire', strtoupper($dto->couleur_secondaire));
        $repository->repo_set('couleur_accent', strtoupper($dto->couleur_accent));
        $repository->repo_set('session_lifetime_minutes', (string) max(5, min(120, (int) $dto->session_lifetime_minutes)));

        if ($dto->logo !== null) {
            $nom = Security::safeFilename($dto->logo['name']);
            $dossier = UPLOADS_PATH . '/logos';
            if (Security::ensureDirectory($dossier) && move_uploaded_file($dto->logo['tmp_name'], $dossier . '/' . $nom)) {
                $repository->repo_set('app_logo', $nom);
            }
        }
        Logger::log($acteurId, 'modification', 'Mise a jour des parametres generaux');
    }

    /** @param array $dataValidated donnees de ElementListeFormRequest */
    public function srv_ajouterElement(string $type, array $dataValidated, int $acteurId): void
    {
        if ($type === 'statuts') {
            throw new DomainException(
                'Le workflow utilise sept statuts système fixes. Seuls leurs libellés et couleurs peuvent être personnalisés.'
            );
        }
        $data = $this->donneesElement($type, ElementListeDTO::fromArray($dataValidated));
        $id = $this->repository($type)->repo_insert($data);
        Logger::log(
            $acteurId, 'ajout',
            "Ajout d'un element de parametrage [{$type}] : {$data['libelle']}",
            null, 'parametrage_' . $type, $id, null, $data
        );
    }

    /** @param array $dataValidated donnees de ElementListeFormRequest */
    public function srv_modifierElement(string $type, int $id, array $dataValidated, int $acteurId): void
    {
        $repository = $this->repository($type);
        $existant = $this->element($repository, $id);
        if ($type === 'statuts' && !StatutRepository::repo_isWorkflowCode((string) ($existant['code'] ?? ''))) {
            throw new DomainException('Cet ancien statut est conservé uniquement pour l’historique et ne peut plus être modifié.');
        }
        $data = $this->donneesElement($type, ElementListeDTO::fromArray($dataValidated), $existant);
        $repository->repo_update($id, $data);
        Logger::log(
            $acteurId, 'modification',
            "Modification d'un element de parametrage [{$type}] #{$id}",
            null, 'parametrage_' . $type, $id, $existant, array_merge($existant, $data)
        );
    }

    /** @return bool le nouvel etat : true si l'element est desormais actif */
    public function srv_basculerElement(string $type, int $id, int $acteurId): bool
    {
        $repository = $this->repository($type);
        $existant = $this->element($repository, $id);
        $actif = (int) ($existant['actif'] ?? 1) === 1 ? 0 : 1;
        if ($type === 'statuts') {
            if (StatutRepository::repo_isWorkflowCode((string) ($existant['code'] ?? ''))) {
                throw new DomainException('Ce statut système est indispensable au workflow et reste toujours actif.');
            }
            if ($actif === 1) {
                throw new DomainException('Un ancien statut hors workflow est conservé pour l’historique mais ne peut pas être réactivé.');
            }
        }

        $repository->repo_update($id, ['actif' => $actif]);
        Logger::log(
            $acteurId,
            $actif === 1 ? 'activation' : 'desactivation',
            ($actif === 1 ? 'Activation' : 'Desactivation') . " d'un element [{$type}] #{$id}",
            null,
            'parametrage_' . $type,
            $id,
            ['actif' => (int) ($existant['actif'] ?? 1)],
            ['actif' => $actif]
        );
        return $actif === 1;
    }

    private function repository(string $type): Repository
    {
        return match ($type) {
            'types_titres' => new TypeTitreRepository(),
            'statuts' => new StatutRepository(),
            'localisations' => new LocalisationRepository(),
            default => throw new DomainException('Liste inconnue.'),
        };
    }

    private function element(Repository $repository, int $id): array
    {
        $element = $repository->repo_find($id);
        if (!$element) {
            throw new DomainException('Element de parametrage introuvable.');
        }
        return $element;
    }

    /** Colonnes a enregistrer ; le code et les indicateurs du workflow ne viennent jamais du navigateur. */
    private function donneesElement(string $type, ElementListeDTO $dto, ?array $existant = null): array
    {
        $data = ['libelle' => $dto->libelle];
        if ($type === 'localisations') {
            return $data;
        }

        if ($type === 'statuts') {
            if ($existant === null) {
                throw new DomainException('Les étapes du workflow sont fixes. Aucun statut libre ne peut être ajouté.');
            }
            return [
                'libelle' => $dto->libelle,
                'couleur' => (string) $dto->couleur,
                // Ces valeurs pilotent le code : toute tentative de les
                // falsifier depuis le navigateur est explicitement ignorée.
                'resolu' => StatutRepository::repo_expectedResolved((string) ($existant['code'] ?? ''))
                    ?? (int) ($existant['resolu'] ?? 0),
                'ordre' => (int) ($existant['ordre'] ?? 0),
            ];
        }

        if ($existant === null) {
            // Le code est une cle interne : il n'est jamais accepte depuis le
            // navigateur et reste stable apres la creation de l'element.
            $data['code'] = $this->codeTypeUnique($dto->libelle);
        }
        $ordre = ($dto->ordre ?? '') !== '' ? (int) $dto->ordre : (int) ($existant['ordre'] ?? 0);
        $data['ordre'] = max(-32768, min(32767, $ordre));

        return $data;
    }

    private function codeTypeUnique(string $label): string
    {
        $ascii = $label;
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label);
            if (is_string($transliterated) && $transliterated !== '') {
                $ascii = $transliterated;
            }
        } elseif (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if (is_string($transliterated) && $transliterated !== '') {
                $ascii = strtolower($transliterated);
            }
        }

        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii), '_'));
        if ($base === '') {
            $base = 'type_titre';
        } elseif (!preg_match('/^[a-z]/', $base)) {
            $base = 'type_' . $base;
        }
        $base = substr($base, 0, 40);

        $repository = new TypeTitreRepository();
        $candidate = $base;
        $suffix = 2;
        while ($repository->repo_count('code = :code', ['code' => $candidate]) > 0) {
            $ending = '_' . $suffix;
            $candidate = substr($base, 0, 40 - strlen($ending)) . $ending;
            $suffix++;
        }

        return $candidate;
    }
}
