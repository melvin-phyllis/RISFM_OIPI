<?php
declare(strict_types=1);

namespace Database\Seeders;

/**
 * Les sept statuts techniques du workflow. Leurs codes pilotent le code
 * (voir StatutRepository::WORKFLOW) : ils ne sont pas modifiables depuis l'interface.
 */
final class StatutSeeder extends Seeder
{
    private const STATUTS = [
        ['code' => 'introuvable', 'systeme' => 1, 'libelle' => 'Introuvable', 'couleur' => 'danger', 'resolu' => 0, 'ordre' => 1],
        ['code' => 'en_recherche', 'systeme' => 1, 'libelle' => 'En recherche', 'couleur' => 'warning', 'resolu' => 0, 'ordre' => 2],
        ['code' => 'a_verifier', 'systeme' => 1, 'libelle' => 'A verifier', 'couleur' => 'info', 'resolu' => 0, 'ordre' => 3],
        ['code' => 'retrouve', 'systeme' => 1, 'libelle' => 'Retrouve', 'couleur' => 'primary', 'resolu' => 1, 'ordre' => 4],
        ['code' => 'numerise', 'systeme' => 1, 'libelle' => 'Numerise', 'couleur' => 'info', 'resolu' => 1, 'ordre' => 5],
        ['code' => 'saisi', 'systeme' => 1, 'libelle' => 'Saisi', 'couleur' => 'success', 'resolu' => 1, 'ordre' => 6],
        ['code' => 'archive', 'systeme' => 1, 'libelle' => 'Archive', 'couleur' => 'secondary', 'resolu' => 1, 'ordre' => 7],
    ];

    public function run(): string
    {
        $ajoutes = $this->insererSiAbsent('statuts', self::STATUTS);
        return sprintf('%d statuts (%d ajoutes)', count(self::STATUTS), $ajoutes);
    }
}
