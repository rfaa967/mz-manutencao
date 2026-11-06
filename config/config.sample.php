<?php
// Copie para config/config.php e ajuste.
return [
    'app_nome'   => 'MZ Manutenção',
    'empresa'    => 'Minha Empresa',
    'base_url'   => '',            // ex.: '/manutencao' se estiver em subpasta; vazio = raiz
    'timezone'   => 'America/Sao_Paulo',
    'debug'      => false,

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'nome'    => 'cmms',
        'usuario' => 'cmms',
        'senha'   => 'troque-esta-senha',
        'charset' => 'utf8mb4',
    ],

    // Pasta fora da raiz pública para anexos
    'upload_dir'      => __DIR__ . '/../storage/uploads',
    'upload_max_mb'   => 15,
    'upload_extensoes'=> ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','dwg','zip'],

    // Token para chamar o gerador de preventivas via URL (cron por wget/curl). Vazio = desabilitado.
    'cron_token' => '',
];
