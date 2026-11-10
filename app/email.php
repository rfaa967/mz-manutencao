<?php
declare(strict_types=1);

/**
 * Notificações por e-mail.
 *  - Toda mensagem entra em fila (tabela fila_emails); o envio é feito por email_processar_fila(),
 *    chamado pelo cron/enviar_emails.php e, se 'envio_imediato' estiver ligado, no fim da requisição.
 *  - Transporte SMTP próprio (STARTTLS/SSL, AUTH LOGIN) ou mail() do PHP — sem dependências externas.
 */

function email_ativo(): bool
{
    if (!cfg('email.ativo', false)) return false;
    if (trim((string)cfg('app_url', '')) === '') {
        // sem app_url os links sairiam do cabeçalho da requisição (manipulável) — não envia
        error_log('[MZManut] e-mail desativado: defina app_url no config.php');
        return false;
    }
    return true;
}

function email_enfileirar(array|string $para, string $assunto, string $html, string $evento = ''): int
{
    if (!email_ativo() || !empty($GLOBALS['__sem_email'])) return 0;
    // quem executou a ação não recebe aviso dela (ex.: gestor que abriu e aprovou o próprio pedido)
    $eu = mb_strtolower((string)(usuario()['email'] ?? ''));
    $para = array_filter((array)$para, fn($x) => mb_strtolower(trim((string)$x)) !== $eu);
    $n = 0;
    foreach (array_unique(array_filter((array)$para)) as $dest) {
        $dest = trim((string)$dest);
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) continue;
        insert('fila_emails', ['para' => $dest, 'assunto' => mb_substr($assunto, 0, 200), 'corpo_html' => $html, 'evento' => $evento ?: null]);
        $n++;
    }
    if ($n && cfg('email.envio_imediato', false) && PHP_SAPI !== 'cli' && empty($GLOBALS['__email_shutdown'])) {
        $GLOBALS['__email_shutdown'] = true;
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();       // não prende a sessão durante o SMTP
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();       // responde ao usuário antes de enviar (PHP-FPM)
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); // idem no LiteSpeed (LSAPI)
            try { email_processar_fila(10); } catch (Throwable $ex) { error_log('[MZManut e-mail] ' . $ex->getMessage()); }
        });
    }
    return $n;
}

/** Envia até $limite e-mails pendentes. Retorna [enviados, erros]. */
function email_processar_fila(int $limite = 50): array
{
    $ok = 0; $err = 0;
    // "reserva" as linhas para evitar envio duplicado se dois processos rodarem juntos
    $token = bin2hex(random_bytes(6));
    qx("UPDATE fila_emails SET erro = ?, reservado_em = NOW() WHERE status = 'pendente' AND tentativas < 5 AND (erro IS NULL OR erro NOT LIKE 'lock:%') ORDER BY id LIMIT " . (int)$limite, ["lock:$token"]);
    foreach (q('SELECT * FROM fila_emails WHERE erro = ?', ["lock:$token"]) as $m) {
        try {
            email_enviar($m['para'], $m['assunto'], $m['corpo_html']);
            qx("UPDATE fila_emails SET status = 'enviado', enviado_em = NOW(), erro = NULL, tentativas = tentativas + 1 WHERE id = ?", [$m['id']]);
            $ok++;
        } catch (Throwable $ex) {
            qx("UPDATE fila_emails SET tentativas = tentativas + 1, erro = ?, status = IF(tentativas + 1 >= 5, 'erro', 'pendente') WHERE id = ?", [mb_substr($ex->getMessage(), 0, 480), $m['id']]);
            $err++;
        }
    }
    // libera reservas antigas (processo que morreu no meio)
    qx("UPDATE fila_emails SET erro = NULL, reservado_em = NULL WHERE status = 'pendente' AND erro LIKE 'lock:%' AND reservado_em < NOW() - INTERVAL 15 MINUTE");
    return [$ok, $err];
}

/** Versão texto do e-mail (clientes sem HTML): uma informação por linha e links por extenso. */
function email_texto(string $html): string
{
    $t = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2: $1', $html);
    $t = preg_replace('/<\/td>\s*<td[^>]*>/i', ': ', $t);                       // "Rótulo: valor"
    $t = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|tr|h\d|li|table)>/i', '/<li[^>]*>/i'], ["\n", "\n", '- '], $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    $t = preg_replace(["/[ \t]+\n/", "/\n{3,}/"], ["\n", "\n\n"], $t);
    return trim($t);
}

function email_enviar(string $para, string $assunto, string $html): void
{
    $de = (string)cfg('email.remetente');
    $nome = (string)cfg('email.remetente_nome', cfg('app_nome'));
    $assuntoEnc = mb_encode_mimeheader($assunto, 'UTF-8', 'B', "\r\n");   // RFC 2047, linhas de até 75 caracteres
    $texto = email_texto($html);
    $fronteira = 'b' . bin2hex(random_bytes(8));
    $corpo = "--$fronteira\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
           . "--$fronteira\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
           . "--$fronteira--\r\n";
    $dominio = substr(strrchr($de, '@') ?: '@localhost', 1);
    $cab = [
        'Date' => date('r'),
        'From' => '=?UTF-8?B?' . base64_encode($nome) . "?= <$de>",
        'To' => $para,
        'Subject' => $assuntoEnc,
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . "@$dominio>",
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$fronteira\"",
        'Auto-Submitted' => 'auto-generated',
    ];

    if (cfg('email.transporte', 'smtp') === 'mail') {
        $h = $cab; unset($h['To'], $h['Subject']);
        $linhas = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($h), $h));
        if (!mail($para, $assuntoEnc, $corpo, $linhas, '-f' . $de)) throw new RuntimeException('mail() retornou falso');
        return;
    }
    $msg = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($cab), $cab)) . "\r\n\r\n" . $corpo;
    smtp_enviar($de, $para, $msg);
}

function smtp_enviar(string $de, string $para, string $mensagem): void
{
    $host = (string)cfg('email.smtp_host'); $porta = (int)cfg('email.smtp_porta', 587);
    $seg = strtolower(trim((string)cfg('email.smtp_seguranca', 'tls')));
    if ($seg === 'starttls') $seg = 'tls';
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ((string)cfg('email.smtp_usuario') !== '' && !in_array($seg, ['tls', 'ssl'], true) && !$local)
        throw new RuntimeException("SMTP: autenticação sem criptografia recusada (smtp_seguranca='$seg'). Use 'tls' ou 'ssl'.");
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $s = @stream_socket_client(($seg === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$porta", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("SMTP: conexão falhou ($errstr)");
    stream_set_timeout($s, 20);

    $ler = function () use ($s): array {
        $txt = '';
        while (($l = fgets($s, 1024)) !== false) { $txt .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return [(int)substr($txt, 0, 3), $txt];
    };
    // $rotulo vai na mensagem de erro no lugar do comando: o usuário/senha (base64) nunca aparecem em log, tela ou fila
    $cmd = function (?string $c, array $esperado, ?string $rotulo = null) use ($s, $ler): string {
        if ($c !== null) fwrite($s, $c . "\r\n");
        [$cod, $txt] = $ler();
        if (!in_array($cod, $esperado, true)) {
            $txt = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $txt)), 0, 200);
            throw new RuntimeException('SMTP ' . ($rotulo ?? ($c !== null ? strtok($c, ' ') : 'saudação')) . ': ' . ($cod ? $txt : 'resposta inválida (o servidor não é SMTP?)'));
        }
        return $txt;
    };
    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($seg === 'tls') {
            $cmd('STARTTLS', [220]);
            // nada pode vir antes do handshake TLS (proteção contra injeção de respostas, CVE-2011-0411)
            if ((stream_get_meta_data($s)['unread_bytes'] ?? 0) > 0) throw new RuntimeException('SMTP: dados inesperados após STARTTLS');
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP: falha no STARTTLS');
            $cmd($ehlo, [250]);
        }
        if (($usr = (string)cfg('email.smtp_usuario')) !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($usr), [334], 'AUTH (usuário)');
            $cmd(base64_encode((string)cfg('email.smtp_senha')), [235], 'AUTH (usuário/senha recusados)');
        }
        $cmd("MAIL FROM:<$de>", [250]);
        $cmd("RCPT TO:<$para>", [250, 251]);
        $cmd('DATA', [354]);
        // dot-stuffing (RFC 5321 §4.5.2)
        $dados = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mensagem));
        fwrite($s, str_replace("\n", "\r\n", $dados) . "\r\n.\r\n");
        $cmd(null, [250]);
        $cmd('QUIT', [221]);
    } finally {
        fclose($s);
    }
}

/* ------------------------------------------------------------------ */
/* Modelo e destinatários                                               */
/* ------------------------------------------------------------------ */

function email_layout(string $titulo, string $conteudo, ?string $link = null, string $rotuloLink = 'Abrir no sistema'): string
{
    $btn = $link ? '<p style="margin:24px 0"><a href="' . e($link) . '" style="background:#ffc107;color:#212529;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">' . e($rotuloLink) . '</a></p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#212529">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
        . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:8px;overflow:hidden">'
        . '<tr><td style="background:' . e((string)cfg('tema.cor_menu', '#212529')) . ';color:' . e((string)cfg('tema.cor_destaque', '#ffc107')) . ';padding:16px 24px;font-weight:bold;font-size:18px">' . e(cfg('app_nome')) . ' <span style="color:#adb5bd;font-weight:normal;font-size:13px">· ' . e(cfg('empresa')) . '</span></td></tr>'
        . '<tr><td style="padding:24px"><h1 style="font-size:20px;margin:0 0 16px">' . e($titulo) . '</h1>' . $conteudo . $btn . '</td></tr>'
        . '<tr><td style="padding:12px 24px;background:#f8f9fa;color:#6c757d;font-size:12px">Mensagem automática. Para deixar de receber, desmarque "Receber notificações por e-mail" em Minha conta.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_tabela(array $linhas): string
{
    $h = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px;width:100%">';
    foreach ($linhas as $k => $v) $h .= '<tr><td style="color:#6c757d;border-bottom:1px solid #eee;width:35%">' . e($k) . '</td><td style="border-bottom:1px solid #eee">' . $v . '</td></tr>';
    return $h . '</table>';
}

/** E-mails de usuários ativos que aceitam notificação, por perfil. */
function emails_perfis(array $perfis): array
{
    $in = implode(',', array_fill(0, count($perfis), '?'));
    return array_column(q("SELECT email FROM usuarios WHERE ativo = 1 AND receber_emails = 1 AND perfil IN ($in)", $perfis), 'email');
}
function email_usuario(?int $id): ?string { return $id ? qv('SELECT email FROM usuarios WHERE id = ? AND ativo = 1 AND receber_emails = 1', [$id]) : null; }
function email_tecnico(?int $tecnicoId): array
{
    if (!$tecnicoId) return [];
    $l = array_column(q('SELECT email FROM usuarios WHERE tecnico_id = ? AND ativo = 1 AND receber_emails = 1', [$tecnicoId]), 'email');
    if (!$l && ($e = qv('SELECT email FROM tecnicos WHERE id = ? AND ativo = 1', [$tecnicoId]))) $l[] = $e;
    return $l;
}

/* ------------------------------------------------------------------ */
/* Eventos                                                              */
/* ------------------------------------------------------------------ */

function notificar_novo_pedido(int $pedidoId): void
{
    if (!email_ativo()) return;
    $p = q1('SELECT p.*, a.codigo, a.nome AS ativo, u.nome AS solicitante FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id JOIN usuarios u ON u.id=p.solicitante_id WHERE p.id = ?', [$pedidoId]);
    if (!$p) return;
    $urg = in_array($p['prioridade'], ['alta', 'urgente'], true) || $p['ativo_parado'];
    email_enfileirar(emails_perfis(['admin', 'gestor']),
        ($urg ? '[' . mb_strtoupper(rotulo('prioridade', $p['prioridade'])) . '] ' : '') . "Novo pedido {$p['numero']}: {$p['titulo']}",
        email_layout('Novo pedido de trabalho', email_tabela([
            'Pedido' => e($p['numero']), 'Equipamento' => e(trim($p['codigo'] . ' · ' . $p['ativo'], ' ·')) ?: '—',
            'Problema' => e($p['titulo']), 'Detalhes' => nl2br(e($p['descricao'] ?? '')),
            'Urgência' => e(rotulo('prioridade', $p['prioridade'])) . ($p['ativo_parado'] ? ' — <strong style="color:#dc3545">equipamento parado</strong>' : ''),
            'Solicitante' => e($p['solicitante']), 'Aberto em' => dt($p['criado_em'], true),
        ]), url_abs('pedidos/ver', ['id' => $pedidoId]), 'Avaliar pedido'), 'novo_pedido');
}

function notificar_pedido_avaliado(int $pedidoId): void
{
    if (!email_ativo()) return;
    $p = q1('SELECT p.*, o.numero AS ot_numero FROM pedidos p LEFT JOIN ordens_trabalho o ON o.id=p.ot_id WHERE p.id = ?', [$pedidoId]);
    if (!$p || !($para = email_usuario((int)$p['solicitante_id']))) return;
    $aprov = $p['status'] === 'convertido';
    email_enfileirar($para, "Pedido {$p['numero']} " . ($aprov ? 'aprovado' : 'rejeitado'),
        email_layout('Seu pedido foi ' . ($aprov ? 'aprovado' : 'rejeitado'), email_tabela(array_filter([
            'Pedido' => e($p['numero'] . ' — ' . $p['titulo']),
            'Situação' => $aprov ? 'Aprovado — ordem de trabalho ' . e($p['ot_numero']) . ' aberta' : '<strong style="color:#dc3545">Rejeitado</strong>',
            'Resposta' => $p['resposta'] ? nl2br(e($p['resposta'])) : null,
        ])), url_abs('m/pedidos', ['id' => $pedidoId]), 'Acompanhar'), 'pedido_avaliado');
}

function notificar_ot_atribuida(int $otId): void
{
    if (!email_ativo()) return;
    $o = q1('SELECT o.*, a.codigo, a.nome AS ativo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id WHERE o.id = ?', [$otId]);
    if (!$o || !$o['responsavel_id']) return;
    if (usuario() && (int)(usuario()['tecnico_id'] ?? 0) === (int)$o['responsavel_id']) return; // não avisa quem atribuiu a si mesmo
    email_enfileirar(email_tecnico((int)$o['responsavel_id']),
        "OT {$o['numero']} atribuída a você: {$o['titulo']}",
        email_layout('Nova ordem de trabalho para você', email_tabela([
            'OT' => e($o['numero']), 'Tipo' => e(rotulo('tipo_ot', $o['tipo'])), 'Prioridade' => e(rotulo('prioridade', $o['prioridade'])),
            'Equipamento' => e(trim($o['codigo'] . ' · ' . $o['ativo'], ' ·')) ?: '—', 'Trabalho' => e($o['titulo']),
            'Descrição' => nl2br(e($o['descricao'] ?? '')), 'Data prevista' => dt($o['data_prevista']) ?: '—',
        ]), url_abs('m/ot', ['id' => $otId]), 'Abrir OT'), 'ot_atribuida');
}

function notificar_ot_concluida(int $otId): void
{
    if (!email_ativo()) return;
    $o = q1('SELECT o.*, a.codigo, a.nome AS ativo, p.solicitante_id, p.numero AS pedido_numero FROM ordens_trabalho o
             LEFT JOIN ativos a ON a.id=o.ativo_id LEFT JOIN pedidos p ON p.id=o.pedido_id WHERE o.id = ?', [$otId]);
    if (!$o || !$o['solicitante_id'] || !($para = email_usuario((int)$o['solicitante_id']))) return;
    email_enfileirar($para, "Concluído: {$o['titulo']} (pedido {$o['pedido_numero']})",
        email_layout('O serviço que você pediu foi concluído', email_tabela([
            'Pedido' => e($o['pedido_numero']), 'Equipamento' => e(trim($o['codigo'] . ' · ' . $o['ativo'], ' ·')) ?: '—',
            'Concluído em' => dt($o['data_fim'], true), 'O que foi feito' => nl2br(e($o['solucao'] ?? '—')),
        ]), url_abs('m/pedidos', ['id' => $o['pedido_id']]), 'Ver pedido'), 'ot_concluida');
}

/** Resumo diário para gestores: preventivas geradas, OTs atrasadas, pedidos aguardando, estoque baixo. */
function enviar_resumo_diario(array $geradas = []): int
{
    if (!email_ativo() || !cfg('email.resumo_diario', true)) return 0;
    $atrasadas = q("SELECT o.id, o.numero, o.titulo, o.data_prevista, a.codigo, t.nome AS resp FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
                    LEFT JOIN tecnicos t ON t.id=o.responsavel_id WHERE o.status IN (" . STATUS_PENDENTES . ") AND o.data_prevista < CURDATE() ORDER BY o.data_prevista LIMIT 30");
    $pedidos = (int)qv("SELECT COUNT(*) FROM pedidos WHERE status = 'aberto'");
    $estoque = q('SELECT codigo, descricao, estoque_atual, estoque_minimo, unidade FROM artigos WHERE ativo = 1 AND estoque_atual < estoque_minimo ORDER BY codigo LIMIT 30');
    $parados = q("SELECT codigo, nome FROM ativos WHERE status IN ('parado','em_manutencao') ORDER BY criticidade, codigo");
    $geradasHoje = q("SELECT o.numero, o.titulo, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id = o.ativo_id
                      WHERE o.plano_id IS NOT NULL AND o.data_abertura >= CURDATE() ORDER BY o.id");
    if (!$geradasHoje && !$atrasadas && !$pedidos && !$estoque && !$parados) return 0;

    $li = fn(array $itens) => $itens ? '<ul style="padding-left:18px;margin:4px 0 16px">' . implode('', array_map(fn($x) => "<li>$x</li>", $itens)) . '</ul>' : '<p style="color:#6c757d;margin:4px 0 16px">Nenhum.</p>';
    $html = '<h3 style="font-size:15px;margin:0">Preventivas geradas hoje (' . count($geradasHoje) . ')</h3>'
        . $li(array_map(fn($g) => e("{$g['numero']} · {$g['codigo']} — {$g['titulo']}"), $geradasHoje))
        . '<h3 style="font-size:15px;margin:0">OTs atrasadas (' . count($atrasadas) . ')</h3>'
        . $li(array_map(fn($o) => e("{$o['numero']} · {$o['codigo']} — {$o['titulo']}") . ' <span style="color:#dc3545">(' . dt($o['data_prevista']) . ')</span>' . ($o['resp'] ? ' · ' . e($o['resp']) : ''), $atrasadas))
        . '<h3 style="font-size:15px;margin:0">Equipamentos parados / em manutenção (' . count($parados) . ')</h3>'
        . $li(array_map(fn($a) => e("{$a['codigo']} — {$a['nome']}"), $parados))
        . '<h3 style="font-size:15px;margin:0">Pedidos aguardando avaliação: ' . $pedidos . '</h3><br>'
        . '<h3 style="font-size:15px;margin:0">Peças abaixo do mínimo (' . count($estoque) . ')</h3>'
        . $li(array_map(fn($a) => e("{$a['codigo']} — {$a['descricao']}: ") . num($a['estoque_atual'], 2) . ' / mín. ' . num($a['estoque_minimo'], 2) . ' ' . e($a['unidade']), $estoque));
    return email_enfileirar(emails_perfis(['admin', 'gestor']), 'Resumo da manutenção — ' . date('d/m/Y'),
        email_layout('Resumo diário da manutenção', $html, url_abs('dashboard'), 'Abrir painel'), 'resumo_diario');
}
