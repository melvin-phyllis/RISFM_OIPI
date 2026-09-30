-- Aligne les types de titres sur la nomenclature officielle de l'OIPI
-- (codes BRV, DMI, IG, MAQ, MC, MU, NC, OV). Les formulaires existants gardent
-- leur type : seuls le code, le libelle et l'ordre changent.
UPDATE types_titres SET code = 'BRV', libelle = 'Brevet',                     ordre = 1 WHERE code = 'brevet';
UPDATE types_titres SET code = 'DMI', libelle = 'Dessin & Modèle Industriel', ordre = 2 WHERE code = 'dmi';
UPDATE types_titres SET code = 'MAQ', libelle = 'Marque',                     ordre = 4 WHERE code = 'marque';
UPDATE types_titres SET code = 'MU',  libelle = 'Modèle d''Utilité',          ordre = 6 WHERE code = 'modele_utilite';
UPDATE types_titres SET code = 'NC',  libelle = 'Nom Commercial',             ordre = 7 WHERE code = 'nom_commercial';
UPDATE types_titres SET code = 'OV',  libelle = 'Obtention Végétale',         ordre = 8 WHERE code = 'obtention_vegetale';

-- "Autres" ne fait pas partie de la nomenclature : desactive, jamais supprime,
-- car d'anciens formulaires peuvent y etre rattaches.
UPDATE types_titres SET actif = 0, ordre = 99 WHERE code = 'autre';

-- Types absents de l'ancienne liste. Sur une base neuve (table vide), le
-- seeder TypeTitreSeeder cree la liste complete apres les migrations.
INSERT INTO types_titres (code, libelle, actif, ordre)
SELECT 'IG', 'Indication Géographique', 1, 3 FROM DUAL
WHERE EXISTS (SELECT 1 FROM (SELECT id FROM types_titres LIMIT 1) AS existants)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM types_titres WHERE code = 'IG') AS deja);

INSERT INTO types_titres (code, libelle, actif, ordre)
SELECT 'MC', 'Marque Collective', 1, 5 FROM DUAL
WHERE EXISTS (SELECT 1 FROM (SELECT id FROM types_titres LIMIT 1) AS existants)
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM types_titres WHERE code = 'MC') AS deja);
