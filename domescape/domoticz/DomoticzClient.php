<?php

require_once __DIR__ . '/../config/app.php';

// =============================================================
// DomoticzClient
// Encapsule tous les appels à l'API JSON de Domoticz.
// =============================================================

class DomoticzClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = DOMOTICZ_URL . '/json.htm';
    }

    // ----------------------------------------------------------
    // Lecture
    // ----------------------------------------------------------

    /**
     * Récupère l'état actuel d'un device par son idx.
     */
    public function getDevice(int $idx): ?array
    {
        $data = $this->get([
            'type'  => 'devices',
            'rid'   => $idx,
        ]);

        return $data['result'][0] ?? null;
    }

    /**
     * Récupère tous les devices actifs.
     */
    public function getAllDevices(): array
    {
        $data = $this->get(['type' => 'devices', 'used' => 'true']);
        return $data['result'] ?? [];
    }

    // ----------------------------------------------------------
    // Commandes actionneurs
    // ----------------------------------------------------------

    /**
     * Allume ou éteint un switch (lampe, prise).
     * $cmd : 'On' | 'Off' | 'Toggle'
     */
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

    /**
     * Change la couleur d'une lampe RGB.
     * $hex : '#FF0000'
     */
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

    private function get(array $params): array
    {
        $url = $this->baseUrl . '?' . http_build_query($params);

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
                'method'  => 'GET',
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
