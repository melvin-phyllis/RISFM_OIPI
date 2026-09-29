<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use JsonException;

/**
 * Transforme les traces techniques du journal en phrases metier lisibles.
 * Aucun texte libre supplementaire n'est demande a l'utilisateur : les
 * messages sont deduits de l'action et de ses instantanes avant/apres.
 */
final class FormulaireHistoriqueBuilder
{
    /**
     * @param array<int,array<string,mixed>> $activities
     * @param array<int,array<string,mixed>> $researches
     * @return array<int,array<string,mixed>>
     */
    public function construire(array $activities, array $researches): array
    {
        $researchByMission = [];
        foreach ($researches as $research) {
            $missionId = (int) ($research['mission_id'] ?? 0);
            if ($missionId > 0) {
                $researchByMission[$missionId] = $research;
            }
        }

        $events = [];
        $usedResearchIds = [];
        foreach ($activities as $activity) {
            $research = null;
            if ((string) ($activity['type_action'] ?? '') === 'recherche') {
                $missionId = (int) ($activity['entite_id'] ?? 0);
                $research = $researchByMission[$missionId] ?? null;
                if ($research !== null) {
                    $usedResearchIds[(int) $research['id']] = true;
                }
            }

            $event = $this->depuisActivite($activity, $research);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        // Les anciennes recherches creees avant la journalisation P2 restent
        // visibles. Une recherche moderne deja reliee a son activite n'est pas
        // ajoutee une seconde fois.
        foreach ($researches as $research) {
            if (isset($usedResearchIds[(int) ($research['id'] ?? 0)])) {
                continue;
            }
            $events[] = $this->depuisRecherche($research);
        }

        usort($events, static function (array $left, array $right): int {
            $dateOrder = strcmp((string) $right['date'], (string) $left['date']);
            return $dateOrder !== 0
                ? $dateOrder
                : ((int) ($right['_sort_id'] ?? 0) <=> (int) ($left['_sort_id'] ?? 0));
        });

        $events = array_slice($events, 0, 150);
        foreach ($events as &$event) {
            unset($event['_sort_id']);
        }
        unset($event);

        return $events;
    }

    /** @param array<string,mixed> $activity @param array<string,mixed>|null $research */
    private function depuisActivite(array $activity, ?array $research): ?array
    {
        $type = (string) ($activity['type_action'] ?? '');
        $before = $this->decodeContext($activity['donnees_avant'] ?? null);
        $after = $this->decodeContext($activity['donnees_apres'] ?? null);
        $actor = $this->label($activity['acteur_nom_affiche'] ?? null, 'le système');
        $location = $this->label($activity['mission_localisation'] ?? null, 'la localisation prévue');
        $responsible = $this->label($activity['mission_responsable'] ?? null, 'le responsable désigné');
        $message = '';
        $detail = null;
        $tone = 'neutral';
        $icon = 'fas fa-history';

        switch ($type) {
            case 'ajout':
                if (str_contains(mb_strtolower((string) ($activity['description'] ?? '')), 'piece jointe')) {
                    $filename = $this->label($after['nom_original'] ?? null, 'un fichier');
                    $message = "Pièce jointe « {$filename} » ajoutée par {$actor}.";
                    $icon = 'fas fa-paperclip';
                } else {
                    $message = "Formulaire ajouté au registre par {$actor}.";
                    $icon = 'fas fa-folder-plus';
                }
                $tone = 'success';
                break;

            case 'modification':
                if ($this->changed($before, $after, 'priorite')) {
                    $old = $this->label($before['priorite'] ?? null, 'non définie');
                    $new = $this->label($after['priorite'] ?? null, 'non définie');
                    $message = "Priorité passée de {$old} à {$new} par {$actor}.";
                    $tone = in_array(mb_strtolower($new), ['urgente', 'haute'], true) ? 'warning' : 'info';
                    $icon = 'fas fa-flag';
                } else {
                    $message = "Informations du formulaire mises à jour par {$actor}.";
                    $tone = 'info';
                    $icon = 'fas fa-pen';
                }
                break;

            case 'changement_statut':
                $old = $this->label($before['statut'] ?? null, 'Non défini');
                $new = $this->label($after['statut'] ?? null, 'Non défini');
                $message = "Statut passé de « {$old} » à « {$new} » par {$actor}.";
                $tone = 'info';
                $icon = 'fas fa-exchange-alt';
                break;

            case 'affectation':
                $message = "Mission confiée à {$responsible} pour une recherche à {$location} par {$actor}.";
                $tone = 'info';
                $icon = 'fas fa-user-check';
                break;

            case 'reaffectation':
                $oldResponsible = $this->label(
                    $activity['mission_ancien_responsable'] ?? $before['responsable'] ?? null,
                    'le précédent responsable'
                );
                $newResponsible = $this->label(
                    $activity['mission_responsable'] ?? $after['responsable'] ?? null,
                    'le nouveau responsable'
                );
                $message = "Mission transférée de {$oldResponsible} vers {$newResponsible} par {$actor}.";
                $tone = 'warning';
                $icon = 'fas fa-people-arrows';
                break;

            case 'annulation_mission':
                $message = "Mission de {$responsible} annulée à {$location} par {$actor}.";
                $tone = 'danger';
                $icon = 'fas fa-ban';
                break;

            case 'recherche':
                return $research !== null
                    ? $this->depuisRecherche($research, (int) ($activity['id'] ?? 0))
                    : $this->rechercheSansInstantane($activity, $after, $location, $responsible);

            case 'finalisation':
                $step = mb_strtolower((string) ($after['etape'] ?? ''));
                if ($step === 'numerise') {
                    $message = "Document marqué comme numérisé par {$actor}.";
                    $icon = 'fas fa-file-pdf';
                } elseif ($step === 'saisi') {
                    $message = "Données du document marquées comme saisies par {$actor}.";
                    $icon = 'fas fa-keyboard';
                } else {
                    $message = "Étape de finalisation validée par {$actor}.";
                    $icon = 'fas fa-tasks';
                }
                $tone = 'success';
                break;

            case 'reouverture':
                $cycle = (int) ($after['cycle_suivi'] ?? 0);
                $message = $cycle > 0
                    ? "Dossier rouvert pour un nouveau cycle de recherche (cycle {$cycle}) par {$actor}."
                    : "Dossier rouvert pour une nouvelle recherche par {$actor}.";
                $tone = 'warning';
                $icon = 'fas fa-redo';
                break;

            case 'archivage':
                $message = "Formulaire archivé par {$actor}.";
                $tone = 'neutral';
                $icon = 'fas fa-archive';
                break;

            case 'restauration':
                $message = "Formulaire restauré dans le registre actif par {$actor}.";
                $tone = 'success';
                $icon = 'fas fa-undo';
                break;

            case 'suppression':
                if (!str_contains(mb_strtolower((string) ($activity['description'] ?? '')), 'piece jointe')) {
                    return null;
                }
                $filename = $this->label($before['nom_original'] ?? null, 'un fichier');
                $message = "Pièce jointe « {$filename} » supprimée par {$actor}.";
                $tone = 'danger';
                $icon = 'fas fa-paperclip';
                break;

            default:
                return null;
        }

        return $this->event(
            (int) ($activity['id'] ?? 0),
            $type,
            (string) ($activity['cree_le'] ?? ''),
            $message,
            $detail,
            $tone,
            $icon,
            $actor
        );
    }

    /** @param array<string,mixed> $research */
    private function depuisRecherche(array $research, ?int $activityId = null): array
    {
        $code = (string) ($research['resultat_code'] ?? '');
        if ($code === '') {
            $status = mb_strtolower((string) ($research['statut_libelle'] ?? ''));
            $code = str_contains($status, 'introuv') ? 'non_retrouve'
                : (str_contains($status, 'retrouv') ? 'retrouve'
                    : (str_contains($status, 'vérifier') || str_contains($status, 'verifier') ? 'a_verifier' : 'non_retrouve'));
        }

        $location = $this->label($research['localisation_libelle'] ?? null, 'la localisation prévue');
        $responsible = $this->label($research['responsable_nom'] ?? null, 'le responsable désigné');
        $actor = $this->label($research['saisi_par_nom'] ?? null, $responsible);
        if ($code === 'retrouve') {
            $message = "Document retrouvé à {$location} par {$responsible}.";
            $tone = 'success';
            $icon = 'fas fa-check';
        } elseif ($code === 'a_verifier') {
            $message = "Résultat à vérifier après la recherche à {$location} par {$responsible}.";
            $tone = 'warning';
            $icon = 'fas fa-question';
        } else {
            $message = "Recherche infructueuse à {$location} par {$responsible}.";
            $tone = 'danger';
            $icon = 'fas fa-search-minus';
        }

        return $this->event(
            $activityId ?? (int) ($research['id'] ?? 0),
            'recherche',
            (string) ($research['cree_le'] ?? $research['date_recherche'] ?? ''),
            $message,
            trim((string) ($research['resultat'] ?? '')) ?: null,
            $tone,
            $icon,
            $actor
        );
    }

    /** @param array<string,mixed> $activity @param array<string,mixed> $after */
    private function rechercheSansInstantane(
        array $activity,
        array $after,
        string $location,
        string $responsible
    ): array {
        $code = (string) ($after['resultat_code'] ?? '');
        $message = match ($code) {
            'retrouve' => "Document retrouvé à {$location} par {$responsible}.",
            'a_verifier' => "Résultat à vérifier après la recherche à {$location} par {$responsible}.",
            default => "Recherche infructueuse à {$location} par {$responsible}.",
        };
        $tone = match ($code) {
            'retrouve' => 'success',
            'a_verifier' => 'warning',
            default => 'danger',
        };
        $icon = match ($code) {
            'retrouve' => 'fas fa-check',
            'a_verifier' => 'fas fa-question',
            default => 'fas fa-search-minus',
        };

        return $this->event(
            (int) ($activity['id'] ?? 0),
            'recherche',
            (string) ($activity['cree_le'] ?? ''),
            $message,
            null,
            $tone,
            $icon,
            $this->label($activity['acteur_nom_affiche'] ?? null, $responsible)
        );
    }

    /** @return array<string,mixed> */
    private function event(
        int $id,
        string $type,
        string $date,
        string $message,
        ?string $detail,
        string $tone,
        string $icon,
        string $actor
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'date' => $date,
            'message' => $message,
            'detail' => $detail,
            'tone' => $tone,
            'icon' => $icon,
            'actor' => $actor,
            '_sort_id' => $id,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeContext(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function changed(array $before, array $after, string $key): bool
    {
        return array_key_exists($key, $before)
            && array_key_exists($key, $after)
            && (string) $before[$key] !== (string) $after[$key];
    }

    private function label(mixed $value, string $fallback): string
    {
        $label = trim((string) ($value ?? ''));
        return $label !== '' ? $label : $fallback;
    }
}
