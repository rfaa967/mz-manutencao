<?php
// /robots.txt (reescrito para cá pelo .htaccess; no Nginx: location = /robots.txt { rewrite ^ /robots.php last; })
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
// Mesmo com indexação liberada, as áreas internas exigem login; só a tela de entrada fica visível aos buscadores.
echo cfg('seo.indexar')
    ? "User-agent: *\nAllow: /index.php?r=login\nDisallow: /q.php\nDisallow: /marca.php\n"
    : "User-agent: *\nDisallow: /\n";
