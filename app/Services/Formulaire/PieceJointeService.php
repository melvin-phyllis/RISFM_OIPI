<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use App\Core\Logger;
use App\Core\Security;
use App\Dto\Formulaire\AjouterPieceJointeDTO;
use App\Repositories\Formulaire\PieceJointeRepository;
use DomainException;

/**
 * Pieces jointes des dossiers, stockees hors de la racine Web
 * (storage/uploads/formulaires). Les droits sont verifies par le controleur.
 */
final class PieceJointeService
{
    /** @param array $dataValidated donnees de AjouterPieceJointeFormRequest */
    public function srv_ajouter(int $formulaireId, array $dataValidated, int $acteurId): void
    {
        $fichier = AjouterPieceJointeDTO::fromArray($dataValidated)->fichier;
        $nomStocke = Security::safeFilename($fichier['name']);
        $dossier = PRIVATE_UPLOADS_PATH . '/formulaires';
        if (!Security::ensureDirectory($dossier)) {
            throw new DomainException('Le dossier securise des pieces jointes est indisponible.');
        }
        if (!move_uploaded_file($fichier['tmp_name'], $dossier . '/' . $nomStocke)) {
            throw new DomainException('Echec du televersement.');
        }

        $nomOriginal = Security::cleanString($fichier['name']);
        (new PieceJointeRepository())->repo_insert([
            'formulaire_id' => $formulaireId,
            'nom_original' => $nomOriginal,
            'nom_fichier' => $nomStocke,
            'type_mime' => $fichier['mime'],
            'taille_octets' => $fichier['size'],
            'televerse_par' => $acteurId,
        ]);
        Logger::log(
            $acteurId,
            'ajout',
            "Piece jointe televersee pour le formulaire #{$formulaireId}",
            null,
            'formulaire',
            $formulaireId,
            null,
            ['nom_original' => $nomOriginal]
        );
    }

    /** @param array $piece piece jointe telle qu'enregistree */
    public function srv_supprimer(array $piece, int $acteurId): void
    {
        foreach ([
            PRIVATE_UPLOADS_PATH . '/formulaires/' . basename((string) $piece['nom_fichier']),
            UPLOADS_PATH . '/formulaires/' . basename((string) $piece['nom_fichier']),
        ] as $chemin) {
            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }
        (new PieceJointeRepository())->repo_delete((int) $piece['id']);
        Logger::log(
            $acteurId,
            'suppression',
            "Suppression de la piece jointe #{$piece['id']}",
            null,
            'formulaire',
            (int) $piece['formulaire_id'],
            ['nom_original' => (string) $piece['nom_original']],
            null
        );
    }
}
