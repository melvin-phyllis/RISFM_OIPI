<?php
declare(strict_types=1);

/**
 * Historique metier immuable des recherches effectuees sur un formulaire.
 * Les libelles sont recopies au moment de la saisie afin que l'historique
 * reste lisible meme si un utilisateur ou un element de parametrage change.
 */
class RechercheFormulaireModel extends Model
{
    protected string $table = 'recherches_formulaire';

    public function pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, m.resultat_code
             FROM recherches_formulaire r
             LEFT JOIN missions_recherche m ON m.id = r.mission_id
             WHERE r.formulaire_id = :formulaire_id
             ORDER BY r.date_recherche DESC, r.cree_le DESC, r.id DESC'
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    /** @return int[] */
    public function localisationsDejaRecherchees(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT localisation_id
             FROM recherches_formulaire
             WHERE formulaire_id = :formulaire_id
               AND localisation_id IS NOT NULL'
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function localisationDejaRecherchee(int $formulaireId, int $localisationId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM recherches_formulaire
             WHERE formulaire_id = :formulaire_id
               AND localisation_id = :localisation_id
             LIMIT 1'
        );
        $stmt->execute([
            'formulaire_id' => $formulaireId,
            'localisation_id' => $localisationId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Cree un instantane historique a partir d'identifiants valides.
     *
     * @param array{mission_id?:?int,localisation_id:?int,responsable_id:?int,statut_id:int,date_recherche:string,resultat:?string,observations:?string} $data
     */
    public function enregistrer(int $formulaireId, array $data, ?int $saisiPar, string $source): int
    {
        $sources = ['creation', 'mise_a_jour', 'nouvelle_recherche', 'reprise'];
        if (!in_array($source, $sources, true)) {
            throw new InvalidArgumentException('Source d\'historique de recherche invalide.');
        }

        $stmt = $this->db->prepare(
            'SELECT
                s.libelle AS statut_libelle,
                l.libelle AS localisation_libelle,
                CONCAT_WS(" ", r.nom, r.prenoms) AS responsable_nom,
                CONCAT_WS(" ", a.nom, a.prenoms) AS saisi_par_nom
             FROM statuts s
             LEFT JOIN localisations l ON l.id = :localisation_id
             LEFT JOIN utilisateurs r ON r.id = :responsable_id
             LEFT JOIN utilisateurs a ON a.id = :saisi_par
             WHERE s.id = :statut_id
               AND EXISTS (SELECT 1 FROM formulaires_manquants f WHERE f.id = :formulaire_id)
             LIMIT 1'
        );
        $stmt->execute([
            'localisation_id' => $data['localisation_id'],
            'responsable_id' => $data['responsable_id'],
            'saisi_par' => $saisiPar,
            'statut_id' => $data['statut_id'],
            'formulaire_id' => $formulaireId,
        ]);
        $labels = $stmt->fetch();
        if (!$labels) {
            throw new RuntimeException('Impossible de construire l\'historique de la recherche.');
        }

        return $this->insert([
            'formulaire_id' => $formulaireId,
            'mission_id' => !empty($data['mission_id']) ? (int) $data['mission_id'] : null,
            'localisation_id' => $data['localisation_id'],
            'localisation_libelle' => $labels['localisation_libelle'] ?: null,
            'responsable_id' => $data['responsable_id'],
            'responsable_nom' => $labels['responsable_nom'] ?: null,
            'statut_id' => $data['statut_id'],
            'statut_libelle' => $labels['statut_libelle'],
            'date_recherche' => $data['date_recherche'],
            'resultat' => $data['resultat'] ?: null,
            'observations' => $data['observations'] ?: null,
            'saisi_par' => $saisiPar,
            'saisi_par_nom' => $labels['saisi_par_nom'] ?: null,
            'source' => $source,
        ]);
    }
}
