<?php
declare(strict_types=1);

class StatutModel extends Model
{
    protected string $table = 'statuts';

    /** Les codes pilotent des transitions de code et ne sont pas extensibles depuis l'interface. */
    public const WORKFLOW = [
        'introuvable' => ['resolu' => 0, 'ordre' => 1],
        'en_recherche' => ['resolu' => 0, 'ordre' => 2],
        'a_verifier' => ['resolu' => 0, 'ordre' => 3],
        'retrouve' => ['resolu' => 1, 'ordre' => 4],
        'numerise' => ['resolu' => 1, 'ordre' => 5],
        'saisi' => ['resolu' => 1, 'ordre' => 6],
        'archive' => ['resolu' => 1, 'ordre' => 7],
    ];

    public function tous(): array
    {
        return $this->db->query(
            'SELECT * FROM statuts ORDER BY systeme DESC, ordre ASC, id ASC'
        )->fetchAll();
    }

    public function actifs(): array
    {
        return $this->db->query(
            'SELECT * FROM statuts
             WHERE actif = 1 AND systeme = 1
             ORDER BY ordre ASC'
        )->fetchAll();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM statuts WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function isWorkflowCode(string $code): bool
    {
        return array_key_exists($code, self::WORKFLOW);
    }

    public static function expectedResolved(string $code): ?int
    {
        return isset(self::WORKFLOW[$code]) ? (int) self::WORKFLOW[$code]['resolu'] : null;
    }

    /** @return array{ok:bool,issues:array<int,string>,legacy_count:int} */
    public function workflowHealth(): array
    {
        $rows = $this->all('id', 'ASC');
        $byCode = [];
        $legacyCount = 0;
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $byCode[$code] = $row;
            if (!self::isWorkflowCode($code)) {
                $legacyCount++;
            }
        }

        $issues = [];
        foreach (self::WORKFLOW as $code => $definition) {
            $row = $byCode[$code] ?? null;
            if ($row === null) {
                $issues[] = "Statut système manquant : {$code}";
                continue;
            }
            if ((int) ($row['systeme'] ?? 0) !== 1 || (int) ($row['actif'] ?? 0) !== 1) {
                $issues[] = "Statut système inactif ou mal identifié : {$code}";
            }
            if ((int) ($row['resolu'] ?? -1) !== (int) $definition['resolu']) {
                $issues[] = "Caractère résolu incohérent : {$code}";
            }
        }
        return ['ok' => $issues === [], 'issues' => $issues, 'legacy_count' => $legacyCount];
    }
}
