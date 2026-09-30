-- Declaration directe d'un formulaire retrouve, sans mission prealable.
-- Catalogue des permissions (les droits effectifs sont dans config/roles.php).
-- Sur une base neuve (table vide), RolePermissionSeeder cree tout le catalogue.
INSERT INTO permissions (code, module, libelle)
SELECT 'formulaires.declare_found', 'formulaires', 'Signaler un formulaire retrouve sans mission (a verifier)' FROM DUAL
WHERE EXISTS (SELECT 1 FROM (SELECT id FROM permissions LIMIT 1) AS existantes)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM permissions WHERE code = 'formulaires.declare_found') AS deja);

INSERT INTO permissions (code, module, libelle)
SELECT 'formulaires.declare_found_validated', 'formulaires', 'Declarer ou confirmer directement un formulaire retrouve' FROM DUAL
WHERE EXISTS (SELECT 1 FROM (SELECT id FROM permissions LIMIT 1) AS existantes)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM permissions WHERE code = 'formulaires.declare_found_validated') AS deja);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('formulaires.declare_found', 'formulaires.declare_found_validated')
WHERE r.code IN ('administrateur', 'responsable')
   OR (r.code = 'agent' AND p.code = 'formulaires.declare_found');
