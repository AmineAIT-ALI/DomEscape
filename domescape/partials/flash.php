<?php
// ============================================================
// DomEscape — Messages flash (succès / erreur / info)
// ============================================================

function flashSet(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['_flash'][$type][] = $message;
}

function flashGet(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function flashRender(): void
{
    $all = flashGet();
    if (empty($all)) {
        return;
    }

    $map = [
        'success' => ['bg' => 'rgba(0,255,136,0.08)', 'border' => '#00ff88', 'color' => '#00ff88'],
        'error'   => ['bg' => 'rgba(255,68,68,0.08)',  'border' => '#ff4444', 'color' => '#ff4444'],
        'info'    => ['bg' => 'rgba(59,130,246,0.08)', 'border' => '#60a5fa', 'color' => '#60a5fa'],
        'warning' => ['bg' => 'rgba(251,191,36,0.08)', 'border' => '#fbbf24', 'color' => '#fbbf24'],
    ];

    foreach ($all as $type => $messages) {
        $style = $map[$type] ?? $map['info'];
        foreach ($messages as $msg) {
            $escaped = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            echo <<<HTML
<div style="background:{$style['bg']};border:1px solid {$style['border']};color:{$style['color']};
            padding:12px 16px;border-radius:4px;margin-bottom:12px;font-size:.875rem;">
  {$escaped}
</div>
HTML;
        }
    }
}
