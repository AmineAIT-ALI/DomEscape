-- =============================================================
-- Migration : messages LCD narratifs (Phase 2)
-- Mise à jour des valeur_action dans etape_declenche
-- =============================================================

USE domescape;

-- Étape 1 — Boot Sequence
UPDATE etape_declenche SET valeur_action = 'Systeme en veille...'
    WHERE id_etape = 1 AND moment_declenchement = 'on_enter'  AND valeur_action = 'Appuyez sur le bouton';

UPDATE etape_declenche SET valeur_action = 'Systeme active. Niveau 1.'
    WHERE id_etape = 1 AND moment_declenchement = 'on_success' AND valeur_action = 'Système en ligne.';

UPDATE etape_declenche SET valeur_action = 'Sequence invalide.'
    WHERE id_etape = 1 AND moment_declenchement = 'on_failure';

-- Étape 2 — Secret Door
UPDATE etape_declenche SET valeur_action = 'Acces restreint. Entrez.'
    WHERE id_etape = 2 AND moment_declenchement = 'on_enter';

UPDATE etape_declenche SET valeur_action = 'Acces autorise. Niveau 2.'
    WHERE id_etape = 2 AND moment_declenchement = 'on_success';

UPDATE etape_declenche SET valeur_action = 'Intrusion refusee.'
    WHERE id_etape = 2 AND moment_declenchement = 'on_failure';

-- Étape 3 — Motion Scan
UPDATE etape_declenche SET valeur_action = 'Scanner en attente...'
    WHERE id_etape = 3 AND moment_declenchement = 'on_enter';

UPDATE etape_declenche SET valeur_action = 'Scan valide. Niveau 3.'
    WHERE id_etape = 3 AND moment_declenchement = 'on_success' AND id_type_action = 3;

UPDATE etape_declenche SET valeur_action = 'Hors perimetre. Stop.'
    WHERE id_etape = 3 AND moment_declenchement = 'on_failure';

-- Étape 4 — Final Code (correction + message narratif)
UPDATE etape_declenche SET valeur_action = 'Code final: double appui.'
    WHERE id_etape = 4 AND moment_declenchement = 'on_enter';

-- on_success étape 4 : déjà bon (ESCAPE SUCCESSFUL !)

UPDATE etape_declenche SET valeur_action = 'Code invalide. Reessayez.'
    WHERE id_etape = 4 AND moment_declenchement = 'on_failure';

-- Vérification
SELECT id_etape, moment_declenchement, valeur_action
FROM etape_declenche
WHERE id_type_action = 3
ORDER BY id_etape, moment_declenchement;
