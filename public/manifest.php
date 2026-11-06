<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$base = rtrim((string)cfg('base_url', ''), '/');
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => cfg('app_nome') . ' — ' . cfg('empresa'),
    'short_name' => 'Manutenção',
    'start_url' => $base . '/index.php?r=m',
    'scope' => $base . '/',
    'display' => 'standalone',
    'background_color' => '#f1f3f5',
    'theme_color' => '#212529',
    'lang' => 'pt-BR',
    'icons' => [
        ['src' => $base . '/assets/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $base . '/assets/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => $base . '/assets/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
