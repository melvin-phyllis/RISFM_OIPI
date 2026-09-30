<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Transaction ouverte par Database::ouvrirTransaction(). Seule la transaction
 * qui l'a demarree la valide ou l'annule : une transaction rejointe laisse
 * cette decision a celle qui l'englobe.
 */
final class Transaction
{
    public function __construct(private readonly PDO $db, private readonly bool $proprietaire)
    {
    }

    public function valider(): void
    {
        if ($this->proprietaire && $this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    public function annuler(): void
    {
        if ($this->proprietaire && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
