-- =============================================================
-- DomEscape — Script dzVents
-- Fichier à placer dans Domoticz : Setup > More Options > Events
--
-- Écoute les capteurs DomEscape et envoie un webhook POST
-- à handle_event.php à chaque changement d'état.
--
-- Mapping idx validé (Setup > Devices) :
--   idx 7  → 'Alarm Type: Home Security 7 (0x07)'  — Multisensor Node 2
--   idx 9  → 'Level'                                — Button Node 3
--   idx 25 → 'Alarm Type: Access Control 6 (0x06)' — Porte Node 5
-- =============================================================

-- =============================================================
-- CONFIGURATION — noms exacts validés dans Domoticz
-- =============================================================

-- Noms exacts des devices tels qu'affichés dans Setup > Devices
local SENSOR_NAMES = {
    'Alarm Type: Home Security 7 (0x07)',  -- Node 2 — Multisensor (idx 7)
    'Level',                               -- Node 3 — Bouton simple (idx 9)
    'Alarm Type: Access Control 6 (0x06)', -- Node 5 — Porte (idx 25)
    'double_press',                        -- Node 3 — Bouton double appui (idx 30)
}

-- Idx surveillés (sécurité supplémentaire — filtre dans execute())
local WATCHED_IDX = {
    [7]  = true,   -- Multisensor — Home Security / Motion (Node 2)
    [9]  = true,   -- Button simple (Node 3)
    [25] = true,   -- Door sensor — Alarm Type: Access Control 6 (Node 5)
    [30] = true,   -- Button double appui (Node 3 — device séparé Domoticz)
}

-- URL du webhook backend
local WEBHOOK_URL = 'http://localhost/domescape/api/handle_event.php'

-- Token de sécurité (doit correspondre à WEBHOOK_TOKEN dans config/secrets.php)
local WEBHOOK_TOKEN = 'domescape_secret_2025'

-- =============================================================
return {
    on = {
        devices = SENSOR_NAMES,
    },

    execute = function(domoticz, device)

        -- Filtre de sécurité par idx — évite les faux positifs de noms
        if not WATCHED_IDX[device.id] then
            domoticz.log(
                string.format(
                    '[DomEscape] idx=%d (%s) ignoré — non dans WATCHED_IDX nvalue=%d svalue=%s',
                    device.id, device.name, device.nValue or 0, device.sValue or ''
                ),
                domoticz.LOG_DEBUG
            )
            return
        end

        local nvalue = device.nValue or 0
        local svalue = device.sValue or ''
        local idx    = device.id

        domoticz.log(
            string.format(
                '[DomEscape] Événement : device=%s idx=%d nvalue=%d svalue=%s',
                device.name, idx, nvalue, svalue
            ),
            domoticz.LOG_INFO
        )

        domoticz.openURL({
            url    = WEBHOOK_URL,
            method = 'POST',
            postData = {
                token  = WEBHOOK_TOKEN,
                idx    = tostring(idx),
                nvalue = tostring(nvalue),
                svalue = svalue,
            },
            callback = 'domescape_callback',
        })
    end,
}

-- =============================================================
-- Callback pour logger la réponse du backend (optionnel)
-- Activer dans un fichier dzVents séparé nommé 'domescape_callback'
-- =============================================================
-- return {
--     on = { httpResponses = { 'domescape_callback' } },
--     execute = function(domoticz, response)
--         if response.ok then
--             domoticz.log('[DomEscape] Webhook OK : ' .. response.data, domoticz.LOG_INFO)
--         else
--             domoticz.log('[DomEscape] Webhook ERREUR : ' .. tostring(response.statusCode), domoticz.LOG_ERROR)
--         end
--     end,
-- }
