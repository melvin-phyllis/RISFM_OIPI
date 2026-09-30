<?php
declare(strict_types=1);

use App\Core\Database;

/**
 * Database::transaction() et Database::ouvrirTransaction() : validation,
 * annulation, points de reprise dans une transaction deja ouverte, niveau
 * d'isolation. Travaille sur une table temporaire de la base de recette.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$db = Database::getConnection();
$db->exec('CREATE TEMPORARY TABLE risfm_test_transactions (valeur VARCHAR(20) NOT NULL) ENGINE=InnoDB');
$valeurs = static fn (): array => $db->query('SELECT valeur FROM risfm_test_transactions ORDER BY valeur')->fetchAll(PDO::FETCH_COLUMN);
$inserer = static fn (string $v) => $db->prepare('INSERT INTO risfm_test_transactions (valeur) VALUES (:v)')->execute(['v' => $v]);
$vider = static fn () => $db->exec('DELETE FROM risfm_test_transactions');

// 1. Transaction simple : validee, puis annulee sur exception.
Database::transaction(static fn () => $inserer('a'));
$assert($valeurs() === ['a'], 'une transaction reussie est validee');
try {
    Database::transaction(static function () use ($inserer): void {
        $inserer('b');
        throw new RuntimeException('echec voulu');
    });
} catch (RuntimeException) {
}
$assert($valeurs() === ['a'] && !$db->inTransaction(), 'une transaction en echec est annulee et fermee');

// 2. Dans une transaction ouverte : un echec imbrique n'annule que son propre travail.
$vider();
Database::transaction(static function () use ($inserer, $assert): void {
    $inserer('externe');
    try {
        Database::transaction(static function () use ($inserer): void {
            $inserer('imbrique_echec');
            throw new RuntimeException('echec imbrique');
        });
    } catch (RuntimeException) {
    }
    Database::transaction(static fn () => $inserer('imbrique_ok'));
});
$assert($valeurs() === ['externe', 'imbrique_ok'], 'point de reprise : seul le travail imbrique en echec est annule');

// 3. L'echec de la transaction englobante annule aussi le travail imbrique reussi.
$vider();
try {
    Database::transaction(static function () use ($inserer): void {
        Database::transaction(static fn () => $inserer('imbrique'));
        throw new RuntimeException('echec externe');
    });
} catch (RuntimeException) {
}
$assert($valeurs() === [], 'l echec englobant annule le travail imbrique');

// 4. Transaction ouverte sur plusieurs appels, et transaction rejointe.
$transaction = Database::ouvrirTransaction('REPEATABLE READ');
$inserer('longue');
$transaction->valider();
$assert($valeurs() === ['longue'] && !$db->inTransaction(), 'une transaction longue est validee par valider()');

$vider();
$db->beginTransaction();
$rejointe = Database::ouvrirTransaction('REPEATABLE READ');
$inserer('rejointe');
$rejointe->valider();
$assert($db->inTransaction(), 'une transaction rejointe ne valide pas celle qui l englobe');
$rejointe->annuler();
$assert($db->inTransaction(), 'une transaction rejointe n annule pas celle qui l englobe');
$db->rollBack();
$assert($valeurs() === [], 'la transaction englobante garde la decision');

// 5. Niveau d'isolation : liste fermee, aucune transaction laissee ouverte en cas de refus.
try {
    Database::transaction(static fn () => null, 'READ UNCOMMITTED; DROP TABLE x');
    $assert(false, 'un niveau d isolation inconnu doit etre refuse');
} catch (LogicException) {
    $assert(!$db->inTransaction(), 'un niveau refuse ne laisse aucune transaction ouverte');
}
$assert(Database::transaction(static fn () => 'ok', 'SERIALIZABLE') === 'ok', 'un niveau connu est accepte et la valeur renvoyee');

$db->exec('DROP TEMPORARY TABLE risfm_test_transactions');

if ($failures !== []) {
    fwrite(STDERR, 'ECHEC: ' . implode("\nECHEC: ", $failures) . "\n");
    exit(1);
}
echo "TRANSACTIONS OK: validation, annulation, points de reprise, transaction longue ou rejointe et isolation verifies.\n";
