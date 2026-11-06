<?php
// Copie para config/config.php e ajuste.
return [
    'app_nome'   => 'MZ Manutenção',
    'empresa'    => 'Minha Empresa',
    'base_url'   => '',            // ex.: '/manutencao' se estiver em subpasta; vazio = raiz
    'timezone'   => 'America/Sao_Paulo',
    'debug'      => false,          // true mostra detalhes de erro apenas para administradores
    'forcar_https' => false,        // true atrás de proxy/CDN que termina o HTTPS (cookie Secure + HSTS)

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
    // Mínimo de 24 caracteres; gere com: php -r "echo bin2hex(random_bytes(32));"
    // Envie no cabeçalho X-Cron-Token (preferível) ou em ?token=.
    'cron_token' => '',

    // Origem pública (protocolo + domínio, sem caminho e sem barra final), usada no QR Code e nos e-mails.
    // Se o sistema estiver em subpasta, informe a subpasta em base_url, não aqui.
    // Ex.: 'https://manutencao.suaempresa.com.br'. Vazio = detecta pelo host da requisição.
    'app_url' => '',

    // Notificações por e-mail. Os e-mails entram numa fila (tabela fila_emails) e são enviados
    // por cron/enviar_emails.php; com 'envio_imediato' => true o envio também é tentado no fim da requisição.
    'email' => [
        'ativo'           => false,
        'envio_imediato'  => true,
        'remetente'       => 'manutencao@suaempresa.com.br',
        'remetente_nome'  => 'MZ Manutenção',
        'transporte'      => 'smtp',          // 'smtp' ou 'mail' (função mail() do PHP)
        'smtp_host'       => 'smtp.suaempresa.com.br',
        'smtp_porta'      => 587,
        'smtp_seguranca'  => 'tls',           // 'tls' (STARTTLS, porta 587), 'ssl' (porta 465) ou ''
        'smtp_usuario'    => 'manutencao@suaempresa.com.br',
        'smtp_senha'      => '',
        'resumo_diario'   => true,            // resumo para gestores (atrasadas, preventivas geradas, estoque baixo)
    ],
];
