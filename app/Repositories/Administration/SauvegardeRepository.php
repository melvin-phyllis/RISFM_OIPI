<?php
declare(strict_types=1);

namespace App\Repositories\Administration;

use App\Core\Repository;

/** Index des fichiers de sauvegarde conserves dans storage/backups. */
class SauvegardeRepository extends Repository
{
    protected string $table = 'sauvegardes';

    /** Toutes les sauvegardes indexees, les plus recentes d'abord. */
    public function repo_toutesAvecCreateur(): array
    {
        return $this->db->query(
            "SELECT s.*,
                    NULLIF(TRIM(CONCAT_WS(' ', u.nom, u.prenoms)), '') AS createur_nom
               FROM sauvegardes s
          LEFT JOIN utilisateurs u ON u.id = s.cree_par
           ORDER BY s.cree_le DESC"
        )->fetchAll();
    }

    /** Indexe un fichier, ou met a jour sa taille s'il l'est deja. */
    public function repo_enregistrerFichier(string $nomFichier, int $tailleOctets, ?int $createurId): void
    {
        $existing = $this->db->prepare('SELECT id FROM sauvegardes WHERE nom_fichier = :nom LIMIT 1');
        $existing->execute(['nom' => $nomFichier]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $this->repo_update((int) $id, ['taille_octets' => $tailleOctets]);
            return;
        }
        $this->db->prepare(
            'INSERT INTO sauvegardes (nom_fichier, taille_octets, cree_par, cree_le)
             VALUES (:nom, :taille, :cree_par, NOW())'
        )->execute([
            'nom' => $nomFichier,
            'taille' => $tailleOctets,
            'cree_par' => $createurId,
        ]);
    }

    public function repo_supprimerFichier(string $nomFichier): void
    {
        $this->db->prepare('DELETE FROM sauvegardes WHERE nom_fichier = :f')->execute(['f' => $nomFichier]);
    }
}
