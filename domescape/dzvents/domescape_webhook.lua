-- =============================================================
-- DomEscape — Script dzVents
-- Fichier à placer dans Domoticz : Setup > More Options > Events
--
-- Ce script écoute tous les capteurs définis dans SENSOR_NAMES
-- et envoie un webhook HTTP POST à handle_event.php
-- à chaque changement d'état.
--
-- Domoticz appelle ce script automatiquement.
-- =============================================================

-- Liste des noms de devices dans Domoticz à surveiller
-- (correspondent aux noms tels que définis dans Setup > Devices)
local SENSOR_NAMES = {
    'Fibaro Button',
    'Door Sensor',
    'Multisensor',
    'Keyfob Joueur',
}

-- URL du webhook backend
local WEBHOOK_URL = 'http://localhost/domescape/api/handle_event.php'

-- Token de sécurité (doit correspondre à WEBHOOK_TOKEN dans config/app.php)
local WEBHOOK_TOKEN = 'domescape_secret_2025'

-- =============================================================
return {
    on = {
        devices = SENSOR_NAMES,
    },

    execute = function(domoticz, device)

        -- nvalue : valeur numérique du nouvel état
        -- sValue : valeur textuelle du nouvel état
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

        -- Envoi du webhook (POST avec les paramètres)
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
-- Callback optionnel pour logger la réponse du backend
-- =============================================================
-- Pour activer le callback, ajouter ce bloc dans le même fichier
-- ou dans un fichier dzVents séparé nommé 'domescape_callback'.
--
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
