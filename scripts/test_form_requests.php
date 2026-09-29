<?php
declare(strict_types=1);

use App\Core\FormRequest;
use App\Exceptions\ValidationException;
use App\Http\Requests\Formulaire\ArchiverFormulaireFormRequest;
use App\Http\Requests\Formulaire\AvancerFinalisationFormRequest;
use App\Http\Requests\Formulaire\CreateFormulaireFormRequest;
use App\Http\Requests\Mission\AffecterMissionFormRequest;
use App\Http\Requests\Mission\EnregistrerResultatMissionFormRequest;
use App\Http\Requests\Administration\ElementListeFormRequest;
use App\Http\Requests\Administration\RestaurerSauvegardeFormRequest;
use App\Http\Requests\Administration\UpdateParametresGenerauxFormRequest;
use App\Http\Requests\Auth\ChangerMotDePasseFormRequest;
use App\Http\Requests\Formulaire\AjouterPieceJointeFormRequest;
use App\Http\Requests\Utilisateur\CreateUserFormRequest;
use App\Http\Requests\Utilisateur\DefinirMotDePasseFormRequest;

/**
 * FormRequest : regles de format, messages en francais, premiere erreur
 * seulement, valeurs nettoyees et typees. Aucun acces a la base.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};
/** @return array{0:?array,1:?ValidationException} */
$run = static function (string $class, array $input): array {
    try {
        return [(new $class($input))->validated(), null];
    } catch (ValidationException $e) {
        return [null, $e];
    }
};

// Identifiant strict : "1abc", "0", "-1", " 1" n'est pas un identifiant.
foreach (['1abc', '0', '-1', '1.5', '01'] as $invalide) {
    [, $e] = $run(CreateFormulaireFormRequest::class, ['type_titre_id' => $invalide, 'annee' => '2020', 'numero_formulaire' => 'A']);
    $assert($e?->field === 'type_titre_id' && $e->getMessage() === 'Le type de titre selectionne est invalide.', "id strict refuse {$invalide}");
}
[$ok] = $run(CreateFormulaireFormRequest::class, ['type_titre_id' => ' 12 ', 'annee' => '2020', 'numero_formulaire' => '  <b>M-1</b> ']);
$assert($ok === ['type_titre_id' => 12, 'annee' => 2020, 'numero_formulaire' => 'M-1'], 'valeurs nettoyees (trim, balises) et typees');

// Premiere erreur seulement, dans l'ordre des regles.
[, $e] = $run(CreateFormulaireFormRequest::class, ['type_titre_id' => '', 'annee' => 'x', 'numero_formulaire' => '']);
$assert($e?->field === 'type_titre_id', 'la premiere erreur suit l ordre des regles');
[, $e] = $run(CreateFormulaireFormRequest::class, ['type_titre_id' => '1', 'annee' => '2020', 'numero_formulaire' => str_repeat('9', 61)]);
$assert($e?->getMessage() === 'Le numero du formulaire ne doit pas depasser 60 caracteres.', 'message specifique champ.regle');
[, $e] = $run(CreateFormulaireFormRequest::class, ['type_titre_id' => '1', 'annee' => '20', 'numero_formulaire' => 'A']);
$assert(str_contains((string) $e?->getMessage(), 'quatre chiffres'), 'annee sur quatre chiffres');

// Dates : format strict, date existante, bornes par rapport a aujourd'hui.
$demain = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$hier = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
foreach (['2026-02-30', '30/01/2026', '2026-1-5', $demain] as $date) {
    [, $e] = $run(AvancerFinalisationFormRequest::class, ['finalisation_etape' => 'numerise', 'finalisation_date' => $date]);
    $assert($e?->getMessage() === 'La date de finalisation est invalide ou situee dans le futur.', "date de finalisation refusee {$date}");
}
[$ok] = $run(AvancerFinalisationFormRequest::class, ['finalisation_etape' => 'numerise', 'finalisation_date' => $hier]);
$assert($ok === ['finalisation_etape' => 'numerise', 'finalisation_date' => $hier, 'finalisation_commentaire' => ''], 'champ absent non obligatoire = chaine vide');
[, $e] = $run(AffecterMissionFormRequest::class, ['affectation_localisation_id' => '1', 'affectation_responsable_id' => '2', 'affectation_date_echeance' => $hier]);
$assert($e?->getMessage() === 'La date limite de recherche ne peut pas etre situee dans le passe.', 'echeance dans le passe refusee');

// Valeur par defaut, nullable, case a cocher, liste.
[$ok] = $run(AffecterMissionFormRequest::class, ['affectation_localisation_id' => '1', 'affectation_responsable_id' => '2']);
$assert($ok !== null && $ok['affectation_priorite'] === 'Normale' && $ok['affectation_date_echeance'] === null
    && $ok['confirmer_affectation_localisation_deja_recherchee'] === false, 'defaut, nullable et case non cochee');
[$ok] = $run(AffecterMissionFormRequest::class, ['affectation_localisation_id' => '1', 'affectation_responsable_id' => '2', 'confirmer_affectation_localisation_deja_recherchee' => '1']);
$assert(($ok['confirmer_affectation_localisation_deja_recherchee'] ?? null) === true, 'case cochee');
[, $e] = $run(AffecterMissionFormRequest::class, ['affectation_localisation_id' => '1', 'affectation_responsable_id' => '2', 'affectation_priorite' => 'Extreme']);
$assert($e?->getMessage() === 'La priorite selectionnee est invalide.', 'valeur hors liste refusee');
[, $e] = $run(EnregistrerResultatMissionFormRequest::class, ['recherche_resultat_code' => 'perdu']);
$assert($e?->getMessage() === 'Selectionnez un resultat valide pour cette mission.', 'code de resultat hors liste');

// Longueur entre deux bornes (motifs).
foreach (['court', str_repeat('m', 501)] as $motif) {
    [, $e] = $run(ArchiverFormulaireFormRequest::class, ['motif_archivage' => $motif]);
    $assert($e?->field === 'motif_archivage', 'motif hors bornes refuse (' . mb_strlen($motif) . ')');
}
[$ok] = $run(ArchiverFormulaireFormRequest::class, ['motif_archivage' => 'Doublon du dossier 2014']);
$assert($ok === ['motif_archivage' => 'Doublon du dossier 2014'], 'motif valide accepte');

// E-mail, mot de passe brut (jamais nettoye ni renvoye), case obligatoire, motif.
[, $e] = $run(CreateUserFormRequest::class, ['nom' => 'A', 'prenoms' => 'B', 'email' => 'pas-une-adresse', 'role' => 'agent']);
$assert($e?->getMessage() === 'Adresse e-mail invalide.', 'adresse e-mail invalide refusee');
[, $e] = $run(CreateUserFormRequest::class, ['nom' => 'A', 'prenoms' => 'B', 'email' => 'a@b.ci', 'role' => 'super_admin']);
$assert($e?->getMessage() === 'Le role selectionne est invalide.', 'role inconnu refuse');
$motDePasse = '  <Mot>de&passe 2026! ';
[$ok] = $run(DefinirMotDePasseFormRequest::class, ['nouveau_mot_de_passe' => $motDePasse, 'force_change' => '1']);
$assert(($ok['nouveau_mot_de_passe'] ?? null) === $motDePasse, 'mot de passe transmis tel quel (espaces et chevrons)');
$saisie = new ChangerMotDePasseFormRequest(['mot_de_passe' => 'Secret_2026!', 'mot_de_passe_actuel' => 'x']);
$assert(!array_key_exists('mot_de_passe', $saisie->old()) && !array_key_exists('mot_de_passe_actuel', $saisie->old()),
    'un mot de passe n est jamais conserve pour remplir le formulaire');
$restauration = ['confirmation_critique' => 'RESTAURER OIPI', 'mot_de_passe_actuel' => 'x'];
[, $e] = $run(RestaurerSauvegardeFormRequest::class, $restauration);
$assert($e?->field === 'confirmer_perte_donnees' && $e->rule === 'accepted', 'case d avertissement obligatoire');
[, $e] = $run(UpdateParametresGenerauxFormRequest::class, ['couleur_primaire' => 'red']);
$assert($e?->field === 'couleur_primaire' && $e->rule === 'regex', 'couleur hors format #RRGGBB refusee');
[$ok] = $run(UpdateParametresGenerauxFormRequest::class, []);
$assert(($ok['app_nom'] ?? null) === 'OIPI - RISFM' && ($ok['couleur_accent'] ?? null) === '#17352B' && $ok['logo'] === null,
    'valeurs par defaut et logo facultatif');

// Parametres de route : les regles dependent de la liste modifiee.
$liste = static fn (string $type, array $input) => (new ElementListeFormRequest($input, [], ['type' => $type]));
$assert($liste('localisations', ['libelle' => str_repeat('L', 150)])->validated() === ['libelle' => str_repeat('L', 150)], 'localisation : 150 caracteres');
try {
    $liste('types_titres', ['libelle' => str_repeat('L', 101)])->validated();
    $assert(false, 'type de titre : 100 caracteres maximum');
} catch (ValidationException $e) {
    $assert($e->getMessage() === 'Le libelle est obligatoire et limite a 100 caracteres.', 'message de longueur propre a la liste');
}
$assert(($liste('statuts', ['libelle' => 'X'])->validated()['couleur'] ?? null) === 'secondary', 'couleur de statut par defaut');

// Fichiers : type reel verifie, taille, fichier absent.
$png = tempnam(sys_get_temp_dir(), 'risfm');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$faux = tempnam(sys_get_temp_dir(), 'risfm');
file_put_contents($faux, '<?php echo "pas une image";');
$upload = static fn (string $name, string $tmp) => ['piece' => ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK]];
$piece = static fn (array $files) => new AjouterPieceJointeFormRequest([], $files, ['formulaire_id' => 1]);
$ok = $piece($upload('scan.png', $png))->validated();
$assert(($ok['piece']['mime'] ?? null) === 'image/png' && $ok['piece']['extension'] === 'png', 'PNG reel accepte avec son type');
foreach ([['scan.png', $faux, 'file'], ['scan.exe', $png, 'file']] as [$nom, $tmp, $regle]) {
    try {
        $piece($upload($nom, $tmp))->validated();
        $assert(false, "fichier refuse : {$nom}");
    } catch (ValidationException $e) {
        $assert($e->rule === $regle, "fichier refuse ({$nom}) par la regle {$regle}");
    }
}
try {
    $piece(['piece' => ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]])->validated();
    $assert(false, 'piece jointe obligatoire');
} catch (ValidationException $e) {
    $assert($e->getMessage() === 'Aucun fichier selectionne.', 'message fichier absent');
}
@unlink($png);
@unlink($faux);

// Une regle mal orthographiee est une erreur de programmation, pas un succes silencieux.
$typo = new class (['x' => 'a']) extends FormRequest {
    public function rules(): array
    {
        return ['x' => 'requird'];
    }
};
try {
    $typo->validated();
    $assert(false, 'une regle inconnue doit lever une LogicException');
} catch (LogicException) {
}

if ($failures !== []) {
    fwrite(STDERR, 'ECHEC: ' . implode("\nECHEC: ", $failures) . "\n");
    exit(1);
}
echo "FORM REQUESTS OK: identifiants, dates, e-mails, mots de passe bruts, fichiers, routes, defauts et premiere erreur verifies.\n";
