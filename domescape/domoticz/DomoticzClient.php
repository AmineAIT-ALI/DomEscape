<?php

require_once __DIR__ . '/../config/app.php';

// =============================================================
// DomoticzClient
// Encapsule tous les appels à l'API JSON de Domoticz.
// =============================================================

class DomoticzClient
{
    private string $baseUrl;

    // Initialise l'URL de base de l'API JSON Domoticz depuis la configuration.
    public function __construct()
    {
        $this->baseUrl = DOMOTICZ_URL . '/json.htm';
    }

    // ----------------------------------------------------------
    // Lecture
    // ----------------------------------------------------------

    
    // Retourne les données d'un device Domoticz par son idx ou null si introuvable.
    public function getDevice(int $idx): ?array
    {
        $data = $this->get([
            'type'  => 'devices',
            'rid'   => $idx,
        ]);

        return $data['result'][0] ?? null;
    }

    
    // Retourne tous les devices actifs enregistrés dans Domoticz.
    public function getAllDevices(): array
    {
        $data = $this->get(['type' => 'devices', 'used' => 'true']);
        return $data['result'] ?? [];
    }

    // ----------------------------------------------------------
    // Commandes actionneurs
    // ----------------------------------------------------------

    
    // Envoie une commande switchlight à Domoticz. Retourne true si Domoticz répond OK.
    public function switchLight(int $idx, string $cmd = 'On'): bool
    {
        $data = $this->get([
            'type'      => 'command',
            'param'     => 'switchlight',
            'idx'       => $idx,
            'switchcmd' => $cmd,
        ]);

        return ($data['status'] ?? '') === 'OK';
    }

    public function turnOn(int $idx): bool  { return $this->switchLight($idx, 'On'); }
    public function turnOff(int $idx): bool { return $this->switchLight($idx, 'Off'); }

    
    // Définit la couleur d'un actionneur compatible RGB par son code hexadécimal.
    public function setColor(int $idx, string $hex): bool
    {
        $data = $this->get([
            'type'   => 'command',
            'param'  => 'setcolbrightnessvalue',
            'idx'    => $idx,
            'hex'    => ltrim($hex, '#'),
            'brightness' => 100,
        ]);

        return ($data['status'] ?? '') === 'OK';
    }

    // ----------------------------------------------------------
    // Interne
    // ----------------------------------------------------------

    // Exécute un appel GET authentifié vers l'API JSON Domoticz et retourne le tableau décodé.
    // Retourne un tableau vide si Domoticz est inaccessible.
    private function get(array $params): array
    {
        $url  = $this->baseUrl . '?' . http_build_query($params);
        $auth = base64_encode(DOMOTICZ_USER . ':' . DOMOTICZ_PASS);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
                'method'  => 'GET',
                'header'  => 'Authorization: Basic ' . $auth . "\r\n",
            ]
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            error_log("[DomoticzClient] Impossible de joindre Domoticz : $url");
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
