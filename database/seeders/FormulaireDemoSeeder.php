<?php
declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * Deux dossiers de demonstration : une marque encore introuvable et un brevet
 * retrouve, chacun avec son historique de recherche. Necessite les comptes de
 * UtilisateurDemoSeeder.
 */
final class FormulaireDemoSeeder extends Seeder
{
    private const FORMULAIRES = [
        [
            'numero_auto' => 'FM-2014-000001', 'type' => 'MAQ', 'annee' => 2014,
            'numero_formulaire' => 'M-2014-005421', 'statut' => 'introuvable',
            'localisation' => 'Archives centrales', 'responsable' => 'demo.documentation@oipi.test',
            'date_recherche' => '2026-07-15', 'date_resolution' => null, 'resultat' => 'En cours',
        ],
        [
            'numero_auto' => 'FM-2017-000001', 'type' => 'BRV', 'annee' => 2017,
            'numero_formulaire' => 'B-2017-000154', 'statut' => 'retrouve',
            'localisation' => 'Direction Technique', 'responsable' => 'demo.chef.projet@oipi.test',
            'date_recherche' => '2026-07-18', 'date_resolution' => '2026-07-18 00:00:00', 'resultat' => 'Saisi',
        ],
    ];

    public function run(): string
    {
        $admin = $this->db->query(
            "SELECT id, CONCAT_WS(' ', nom, prenoms) AS nom FROM utilisateurs
             WHERE role = 'administrateur' ORDER BY id LIMIT 1"
        )->fetch();
        if (!$admin) {
            throw new RuntimeException('Aucun administrateur : lancez AdminSeeder avant les donnees de demonstration.');
        }
        $existe = $this->db->prepare('SELECT COUNT(*) FROM formulaires_manquants WHERE numero_auto = :numero');

        $ajoutes = 0;
        foreach (self::FORMULAIRES as $f) {
            $existe->execute(['numero' => $f['numero_auto']]);
            if ((int) $existe->fetchColumn() > 0) {
                continue;
            }
            $responsable = $this->utilisateur($f['responsable']);
            $statutId = $this->idPar('statuts', 'code', $f['statut']);
            $localisationId = $this->idPar('localisations', 'libelle', $f['localisation']);

            $this->db->prepare(
                'INSERT INTO formulaires_manquants
                    (numero_auto, type_titre_id, annee, numero_formulaire, statut_id, localisation_id,
                     responsable_id, date_recherche, date_resolution, resultat, cree_par)
                 VALUES
                    (:numero_auto, :type_titre_id, :annee, :numero_formulaire, :statut_id, :localisation_id,
                     :responsable_id, :date_recherche, :date_resolution, :resultat, :cree_par)'
            )->execute([
                'numero_auto' => $f['numero_auto'],
                'type_titre_id' => $this->idPar('types_titres', 'code', $f['type']),
                'annee' => $f['annee'],
                'numero_formulaire' => $f['numero_formulaire'],
                'statut_id' => $statutId,
                'localisation_id' => $localisationId,
                'responsable_id' => $responsable['id'],
                'date_recherche' => $f['date_recherche'],
                'date_resolution' => $f['date_resolution'],
                'resultat' => $f['resultat'],
                'cree_par' => $admin['id'],
            ]);
            $formulaireId = (int) $this->db->lastInsertId();

            $this->db->prepare(
                "INSERT INTO recherches_formulaire
                    (formulaire_id, localisation_id, localisation_libelle, responsable_id, responsable_nom,
                     statut_id, statut_libelle, date_recherche, resultat, saisi_par, saisi_par_nom, source)
                 SELECT :formulaire_id, :localisation_id, :localisation_libelle, :responsable_id, :responsable_nom,
                        s.id, s.libelle, :date_recherche, :resultat, :saisi_par, :saisi_par_nom, 'reprise'
                 FROM statuts s WHERE s.id = :statut_id"
            )->execute([
                'formulaire_id' => $formulaireId,
                'localisation_id' => $localisationId,
                'localisation_libelle' => $f['localisation'],
                'responsable_id' => $responsable['id'],
                'responsable_nom' => $responsable['nom'],
                'date_recherche' => $f['date_recherche'],
                'resultat' => $f['resultat'],
                'saisi_par' => $admin['id'],
                'saisi_par_nom' => $admin['nom'],
                'statut_id' => $statutId,
            ]);

            if ($f['date_resolution'] !== null) {
                $this->insererSiAbsent('finalisations_formulaire', [[
                    'formulaire_id' => $formulaireId,
                    'etape' => 'retrouve',
                    'statut_id' => $this->idPar('statuts', 'code', 'retrouve'),
                    'effectue_par' => $responsable['id'],
                    'effectue_par_nom' => $responsable['nom'],
                    'commentaire' => 'Reprise de la situation de demonstration',
                    'effectue_le' => $f['date_resolution'],
                ]]);
            }
            $ajoutes++;
        }

        return sprintf('%d formulaires de demonstration (%d ajoutes)', count(self::FORMULAIRES), $ajoutes);
    }

    /** @return array{id:int, nom:string} */
    private function utilisateur(string $email): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, CONCAT_WS(' ', nom, prenoms) AS nom FROM utilisateurs WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Compte {$email} introuvable : lancez UtilisateurDemoSeeder avant FormulaireDemoSeeder.");
        }
        return ['id' => (int) $row['id'], 'nom' => (string) $row['nom']];
    }
}
