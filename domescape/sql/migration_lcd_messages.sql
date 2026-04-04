-- =============================================================
-- Migration : messages LCD ≤ 16 chars (écran 2×16)
-- =============================================================

USE domescape;

-- Étape 1
UPDATE etape_declenche SET valeur_action = 'En veille...'    WHERE id_etape = 1 AND moment_declenchement = 'on_enter';
UPDATE etape_declenche SET valeur_action = 'Niveau 1 OK !'   WHERE id_etape = 1 AND moment_declenchement = 'on_success' AND id_type_action = 3;
UPDATE etape_declenche SET valeur_action = 'Invalide !'      WHERE id_etape = 1 AND moment_declenchement = 'on_failure';

-- Étape 2
UPDATE etape_declenche SET valeur_action = 'Zone restreinte' WHERE id_etape = 2 AND moment_declenchement = 'on_enter';
UPDATE etape_declenche SET valeur_action = 'Acces autorise!' WHERE id_etape = 2 AND moment_declenchement = 'on_success' AND id_type_action = 3;
UPDATE etape_declenche SET valeur_action = 'Intrus detecte!' WHERE id_etape = 2 AND moment_declenchement = 'on_failure';

-- Étape 3
UPDATE etape_declenche SET valeur_action = 'Scan en attente' WHERE id_etape = 3 AND moment_declenchement = 'on_enter';
UPDATE etape_declenche SET valeur_action = 'Scan valide !'   WHERE id_etape = 3 AND moment_declenchement = 'on_success' AND id_type_action = 3;
UPDATE etape_declenche SET valeur_action = 'Hors perimetre!' WHERE id_etape = 3 AND moment_declenchement = 'on_failure';

-- Étape 4
UPDATE etape_declenche SET valeur_action = 'Double appui !'  WHERE id_etape = 4 AND moment_declenchement = 'on_enter';
UPDATE etape_declenche SET valeur_action = 'ESCAPE SUCCESS!' WHERE id_etape = 4 AND moment_declenchement = 'on_success' AND id_type_action = 3;
UPDATE etape_declenche SET valeur_action = 'Code invalide !' WHERE id_etape = 4 AND moment_declenchement = 'on_failure';

-- Vérification
SELECT id_etape, moment_declenchement, valeur_action, LENGTH(valeur_action) AS len
FROM etape_declenche WHERE id_type_action = 3
ORDER BY id_etape, moment_declenchement;
