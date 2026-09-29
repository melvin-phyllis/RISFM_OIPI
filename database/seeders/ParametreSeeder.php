<?php
declare(strict_types=1);

namespace Database\Seeders;

/**
 * Valeurs initiales des parametres de l'application. Une valeur deja
 * modifiee depuis l'administration n'est jamais ecrasee.
 */
final class ParametreSeeder extends Seeder
{
    private const PARAMETRES = [
        ['cle' => 'app_nom', 'valeur' => 'OIPI - RISFM', 'description' => 'Nom affiche de l\'application'],
        ['cle' => 'app_logo', 'valeur' => '', 'description' => 'Chemin du logo OIPI (public/uploads/logos)'],
        ['cle' => 'couleur_primaire', 'valeur' => '#F68B1F', 'description' => 'Orange principal OIPI'],
        ['cle' => 'couleur_secondaire', 'valeur' => '#00A651', 'description' => 'Vert principal OIPI'],
        ['cle' => 'couleur_accent', 'valeur' => '#17352B', 'description' => 'Vert profond utilise pour le texte et les contrastes'],
        ['cle' => 'session_lifetime_minutes', 'valeur' => '20', 'description' => 'Duree d\'inactivite avant deconnexion automatique (minutes)'],
    ];

    public function run(): string
    {
        $ajoutes = $this->insererSiAbsent('parametres', self::PARAMETRES);
        return sprintf('%d parametres (%d ajoutes)', count(self::PARAMETRES), $ajoutes);
    }
}
