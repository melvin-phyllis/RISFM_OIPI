-- Nouvelle palette par defaut RISFM/OIPI.
-- Ne remplace que les anciennes valeurs livrees par defaut afin de ne pas
-- ecraser une personnalisation deja effectuee depuis le back-office.

UPDATE parametres
SET valeur = '#F68B1F',
    description = 'Orange principal OIPI'
WHERE cle = 'couleur_primaire'
  AND UPPER(valeur) = '#1F3864';

UPDATE parametres
SET valeur = '#00A651',
    description = 'Vert principal OIPI'
WHERE cle = 'couleur_secondaire'
  AND UPPER(valeur) = '#F5811F';

UPDATE parametres
SET valeur = '#000000',
    description = 'Noir utilise pour le texte et les contrastes'
WHERE cle = 'couleur_accent'
  AND UPPER(valeur) = '#2E7D32';
