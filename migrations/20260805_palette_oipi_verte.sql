-- Evolution de la palette OIPI.
-- Cette migration complete 20260719_05 sans modifier son historique. Elle ne
-- remplace que les couleurs deja livrees par l'application afin de conserver
-- les personnalisations realisees depuis le back-office.

UPDATE parametres
SET valeur = '#F68B1F',
    description = 'Orange principal OIPI'
WHERE cle = 'couleur_primaire'
  AND UPPER(valeur) IN ('#1F3864', '#AFAA0D');

UPDATE parametres
SET valeur = '#00A651',
    description = 'Vert principal OIPI'
WHERE cle = 'couleur_secondaire'
  AND UPPER(valeur) IN ('#F5811F', '#FE7606');

UPDATE parametres
SET valeur = '#17352B',
    description = 'Vert profond utilise pour le texte et les contrastes'
WHERE cle = 'couleur_accent'
  AND UPPER(valeur) IN ('#2E7D32', '#000000', '#C74343');
