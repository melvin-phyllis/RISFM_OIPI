-- Catalogue informatif aligne sur la matrice RBAC appliquee dans config/roles.php.

INSERT INTO `permissions` (`code`, `module`, `libelle`) VALUES
('formulaires.update_metadata', 'formulaires', 'Modifier les informations generales'),
('formulaires.assign', 'formulaires', 'Affecter ou reaffecter une recherche'),
('formulaires.record_result_any', 'formulaires', 'Enregistrer tout resultat de recherche'),
('formulaires.record_result_own', 'formulaires', 'Enregistrer le resultat d une recherche affectee'),
('formulaires.attach_any', 'formulaires', 'Ajouter une piece sur tout dossier'),
('formulaires.attach_own', 'formulaires', 'Ajouter une piece sur un dossier affecte'),
('formulaires.delete_attachment_any', 'formulaires', 'Supprimer toute piece jointe'),
('formulaires.delete_attachment_own', 'formulaires', 'Supprimer sa propre piece sur un dossier affecte')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `libelle` = VALUES(`libelle`);

DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
JOIN roles r ON r.id = rp.role_id
WHERE r.code IN ('responsable', 'agent')
  AND p.code LIKE 'formulaires.%';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'formulaires.view', 'formulaires.create', 'formulaires.update_metadata',
    'formulaires.assign', 'formulaires.record_result_own',
    'formulaires.attach_own', 'formulaires.delete_attachment_own',
    'formulaires.export'
)
WHERE r.code = 'responsable';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'formulaires.view', 'formulaires.create', 'formulaires.record_result_own',
    'formulaires.attach_own', 'formulaires.delete_attachment_own'
)
WHERE r.code = 'agent';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'administrateur';

DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('formulaires.update', 'formulaires.update_own');

DELETE FROM permissions WHERE code IN ('formulaires.update', 'formulaires.update_own');
