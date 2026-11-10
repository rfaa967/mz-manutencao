# MZ Manutenção — CMMS em PHP + MySQL

Sistema de gestão de manutenção (CMMS) inspirado no fluxo de trabalho do ManWinWin Express: cadastro de ativos em árvore, pedidos de trabalho, ordens de trabalho (OTs), manutenção preventiva por tempo e por contador, estoque de peças, calendário e indicadores (KPIs).

PHP 8.1+ puro (sem framework), PDO/MySQL, Bootstrap 5, Chart.js e FullCalendar via CDN.

## Módulos

| Módulo | O que faz |
|---|---|
| **Ativos** | Árvore Localização › Ativo › Componente. Ficha técnica, criticidade A/B/C, garantia, centro de custo, documentos anexos, histórico de OTs, KPIs do ativo (disponibilidade, MTBF, MTTR, custo em 12 meses). Visões em lista e em árvore, com exportação CSV. |
| **Pedidos de trabalho** | Qualquer usuário (inclusive o perfil *solicitante*) reporta uma falha. O gestor aprova o pedido, que vira uma OT, ou rejeita com uma resposta. Quando o pedido marca "equipamento parado", o status do ativo muda. |
| **Ordens de trabalho** | Tipos: corretiva, preventiva, preditiva, inspeção, lubrificação, calibração e melhoria. Fluxo aberta → planejada → em execução ⇄ aguardando peças → concluída/cancelada. Checklist, apontamento de mão de obra (custo/hora), requisição de materiais com baixa no estoque, custo externo, causa da falha, horas de parada (calculadas automaticamente a partir da abertura do pedido) e impressão da OT com campos de assinatura. |
| **Planos preventivos** | Gatilho por **tempo** (dias/semanas/meses/anos) ou por **contador** (horímetro, km, ciclos). A reprogramação pode ser *fixa* (segue o calendário) ou *flutuante* (conta a partir da execução). Cada plano tem antecedência configurável, checklist e materiais previstos, que são copiados para a OT. Nenhuma OT é duplicada enquanto houver outra pendente para o mesmo plano. |
| **Contadores** | Leituras com histórico e opção de troca/zeragem do contador. Cada leitura dispara o gerador, então planos por contador viram OT na hora. O calendário estima a data de vencimento pela média de uso. |
| **Estoque** | Artigos com família, mínimo e localização no almoxarifado. Entradas usam custo médio ponderado; há também saídas avulsas e ajuste de inventário. O kardex mostra cada movimento ligado à sua OT. Cancelar uma OT ou remover um material devolve o item ao estoque. |
| **Calendário** | Mostra as OTs programadas e concluídas e projeta as próximas execuções dos planos (◌). Pode ser filtrado por técnico. |
| **Relatórios & KPIs** | Disponibilidade, MTBF, MTTR, horas de parada, % de manutenção planejada, cumprimento das preventivas, custos (mão de obra, materiais e externo) por ativo e por mês, Pareto de causas de falha, horas por técnico, consumo de materiais, backlog e ativos A/B sem plano. Todos os relatórios exportam CSV. |
| **QR Code e etiquetas** | Cada ativo tem um QR Code com link curto (`/q.php?c=CODIGO`). A página **Ativos › Etiquetas QR** imprime etiquetas em 3 tamanhos, filtradas por localização, categoria ou criticidade. |
| **Interface mobile (PWA)** | Pode ser instalada no celular; tem leitor de QR pela câmera e busca por código. Pela ficha resumida do ativo dá para reportar falha, abrir OT e registrar leitura de contador. A execução da OT tem botões grandes: iniciar/pausar, checklist, apontar +0,5/+1/+2/+4 h, requisitar peça e concluir. O solicitante acompanha os próprios pedidos. Se o QR for lido sem sessão, o sistema pede login e volta para o equipamento. No celular, técnicos e solicitantes entram direto nessa interface. |
| **Notificações por e-mail** | Avisam: novo pedido (para os gestores), pedido aprovado ou rejeitado (para o solicitante), OT atribuída (para o técnico), serviço concluído (para o solicitante) e o resumo diário (atrasadas, preventivas geradas, ativos parados, estoque baixo). Os envios passam por uma fila, com SMTP próprio (STARTTLS/SSL) ou `mail()`. Cada usuário pode desligar os e-mails em *Minha conta*. |
| **Listagens** | Todas as listas (OTs, ativos, pedidos, estoque, planos, usuários, cadastros) são tabelas *server-side* no estilo DataTables: busca por vários termos em várias colunas, ordenação clicando no cabeçalho, paginação, itens por página (lembrados por usuário) e exportação CSV do resultado filtrado. A tabela é atualizada sem recarregar a página e a URL acompanha, então voltar e compartilhar o link funcionam; sem JavaScript, tudo funciona com recarga. |
| **Configurações** (admin) | Tudo pelo navegador, sem editar arquivos. **Geral:** nome do sistema, empresa, endereço oficial (`app_url`), tamanho e cota de anexos, indexação por buscadores (desligada por padrão: `noindex` e `robots.txt` bloqueando tudo). **Aparência:** logotipo, favicon e as cores do menu, do destaque e dos botões; a cor do texto é escolhida automaticamente pelo contraste. **E-mail:** SMTP, com botão de e-mail de teste. **Turnstile:** chaves e formulários. **Segurança:** proxy/CDN e limites de requisição. **Sistema:** versão, extensões, último cron, fila de e-mails e o botão **Atualizar banco de dados**, que aplica as migrações sem terminal. Os valores salvos aqui se sobrepõem aos do `config.php`; banco, `debug` e `cron_token` continuam só no arquivo. |
| **Cadastros** | Localizações (em árvore), categorias, fornecedores, técnicos (internos ou terceirizados, com custo/hora), especialidades e usuários. |

### Perfis de acesso

| Perfil | Acesso |
|---|---|
| `admin` | Tudo, incluindo usuários |
| `gestor` | Cadastros, ativos, planos, aprovação de pedidos, estoque e relatórios |
| `tecnico` | Executa as OTs atribuídas a ele ou sem responsável (status, checklist, as próprias horas, materiais), registra leituras de contador, consulta ativos e estoque |
| `solicitante` | Abre e acompanha os próprios pedidos |

## Requisitos

- PHP 8.1+ com as extensões `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` (SMTP com TLS e Turnstile), `session` e `json`.
- MySQL 8.0+ ou MariaDB 10.5+. Compatível com `ONLY_FULL_GROUP_BY` e `STRICT_*`.
- No php.ini: `upload_max_filesize` e `post_max_size` maiores que `upload_max_mb` (ex.: 16M e 20M).

## Instalação

```bash
# 1. Banco (no MariaDB do Ubuntu/Debian use "sudo mysql" no lugar de "mysql -u root -p")
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS cmms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'cmms'@'localhost' IDENTIFIED BY 'SENHA'; GRANT ALL ON cmms.* TO 'cmms'@'localhost';"

# 2. Configuração: edite db, empresa, base_url, app_url, cron_token (mín. 24 caracteres), proxy, e-mail e turnstile
cp config/config.sample.php config/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"     # gera o cron_token

# 3. Estrutura + administrador (a senha é pedida no terminal)
#    install.php APAGA as tabelas do banco configurado; num banco com dados ele exige digitar o nome do banco.
php install.php --email=admin@empresa.com.br
#    Somente demonstração/homologação (cria usuários *@demo.local com a MESMA senha do admin):
#    php install.php --email=admin@empresa.com.br --demo

# 4. Permissões (troque www-data pelo usuário do PHP-FPM/Apache)
sudo chown -R root:www-data /var/www/mzmanut
sudo chmod 640 /var/www/mzmanut/config/config.php
sudo mkdir -p /var/www/mzmanut/storage/uploads
sudo chown -R www-data:www-data /var/www/mzmanut/storage && sudo chmod -R 770 /var/www/mzmanut/storage
```

O **DocumentRoot** deve ser a pasta `public/` (veja exemplos de Nginx e Apache em *Produção*). O `.htaccess` da raiz é só um paliativo para hospedagem compartilhada.

Para testar localmente: `php -S localhost:8080 -t public`

### Atualização (ex.: v2 → v3)

1. Faça backup: `mysqldump --single-transaction cmms > cmms_$(date +%F).sql`, mais `config/config.php` e `storage/uploads/`.
2. Ative o modo manutenção: `touch storage/MANUTENCAO`. Usuários veem "Sistema em atualização" enquanto o arquivo existir.
3. Extraia o pacote por cima da instalação. O zip não traz `config/config.php`, então ele é preservado.
4. Rode `php migrate.php`. Pode ser repetido com segurança; `--status` lista o que já foi aplicado. Se uma pré-checagem falhar, o próprio comando diz o que corrigir.
5. Copie para o seu `config/config.php` as chaves novas do `config.sample.php`: `app_url`, `forcar_https`, `email`, `proxy`, `rate_limit` e `turnstile`.
   - `app_url` é obrigatório para os links dos e-mails e para as etiquetas QR.
   - `cron_token` precisa ter 24 caracteres ou mais (tokens menores recebem 403 e geram aviso no log).
6. Recarregue o PHP-FPM/Apache para limpar o opcache (`sudo systemctl reload php8.3-fpm`) e apague `storage/MANUTENCAO`.
7. Os usuários precisarão entrar de novo.

### Atualização sem terminal (hospedagem com painel)

1. Faça backup do banco (painel → Backups ou phpMyAdmin → Exportar) e da pasta `storage/` (inclui `storage/app.key`, sem a qual as senhas salvas em Configurações não podem ser lidas).
2. Envie o zip pelo gerenciador de arquivos e extraia por cima da instalação. O zip não traz `config/` nem `storage/`.
3. Entre como administrador em **Configurações › Sistema** e clique em **Atualizar banco de dados**. A ação pede sua senha e roda com trava, então um duplo clique não aplica nada duas vezes.

**Arquivos de emergência.** Crie-os vazios em `storage/` pelo gerenciador de arquivos e apague-os depois de corrigir o problema:
- `DESATIVAR_TURNSTILE` desliga o Turnstile, se chaves erradas impedirem o login.
- `DESATIVAR_RATE_LIMIT` desliga os limites de requisição.
- `MANUTENCAO` mostra a página "Sistema em atualização".

### Tarefas agendadas (cron)

Rode o cron com o mesmo usuário do PHP web (`sudo crontab -u www-data -e`):

```cron
# gera as preventivas devidas e envia o resumo diário aos gestores
15 5 * * * /usr/bin/php /var/www/mzmanut/cron/gerar_preventivas.php >> /var/www/mzmanut/storage/cron.log 2>&1
# envia a fila de e-mails
*/5 * * * * /usr/bin/php /var/www/mzmanut/cron/enviar_emails.php >> /var/www/mzmanut/storage/cron.log 2>&1
```

Se não houver cron por CLI, use a URL e mande o token no cabeçalho (assim ele não vai para o log de acesso):
`curl -fsS -H "X-Cron-Token: SEU_TOKEN" https://seu-dominio/index.php?r=cron` (o token não é mais aceito na URL). O botão **Gerar OTs vencidas**, na tela de planos, faz o mesmo sob demanda.

Para o e-mail funcionar, preencha o bloco `email` e o `app_url`; sem `app_url` o envio fica desligado (os links sairiam do cabeçalho da requisição). A câmera do leitor de QR só funciona em **HTTPS**.

### Rate limit, Cloudflare e Turnstile

- **Rate limit** (bloco `rate_limit`): limites por minuto ou por hora.
  - Por IP: qualquer página, tentativas de login, aberturas do link do QR e cron por URL.
  - Por usuário: envios de formulário, pedidos de trabalho e anexos.
  - Ao estourar um limite, o sistema responde HTTP 429 com `Retry-After`. Os contadores ficam na tabela `rate_limites`.
- **Atrás da Cloudflare ou de um proxy reverso**, defina `'proxy' => 'cloudflare'` (ou `'confiavel'` com `proxies_confiaveis`).
  - Assim o sistema usa o IP real do visitante no rate limit, no bloqueio de login e na auditoria.
  - O cabeçalho `CF-Connecting-IP` só é aceito quando a conexão vem das faixas oficiais da Cloudflare, então ninguém consegue forjá-lo.
- **Cloudflare Turnstile** (bloco `turnstile`): crie as chaves no painel da Cloudflare (Turnstile → Add site).
  - Formulários: `login` e, opcionalmente, `pedido`.
  - No login, o widget pode aparecer sempre ou só depois de 3 falhas (`modo_login`).
  - A validação é feita no servidor, conferindo também a ação do formulário.
  - Se a Cloudflare não responder, o padrão é bloquear (`falhar_aberto => false`).
  - Para testar, use as chaves da Cloudflare `1x00000000000000000000AA` / `1x0000000000000000000000000000000AA`.

## Segurança

- Senhas com `password_hash` e rehash automático; bloqueio de login após 8 falhas em 15 min, por e-mail ou IP, gravado no banco.
- Sessão revalidada no banco a cada requisição:
  - usuário desativado ou rebaixado perde o acesso na hora;
  - trocar a senha encerra as outras sessões;
  - a sessão expira após 4 h sem uso, ou 16 h no máximo.
- Cookies `HttpOnly`/`SameSite=Lax`, `Secure` em HTTPS (`forcar_https` atrás de proxy) e `session.use_strict_mode`. O token CSRF é renovado no login.
- Token CSRF em todo POST; o logout também é por POST.
- **XSS:**
  - toda saída HTML é escapada;
  - a CSP usa **nonce por requisição + `strict-dynamic`**, sem `unsafe-inline` para scripts e com `script-src-attr 'none'`. Nenhum script inline e nenhum `onclick`: todo o JavaScript fica em `app.js`, ligado por atributos `data-*`. Mesmo um HTML injetado não executaria;
  - dados para gráficos e calendário vão em atributos escapados, não em `<script>`;
  - o JSON é emitido com `JSON_HEX_*`;
  - as bibliotecas da CDN levam **SRI** (hash de integridade).
- PDO com *prepared statements*; `X-Frame-Options`, `nosniff`, `Cross-Origin-Opener-Policy`, `Permissions-Policy` e HSTS em HTTPS.
- **Configurações pelo painel:**
  - só para o perfil admin;
  - as abas Geral, E-mail, Turnstile e Segurança e a atualização do banco pedem a senha de novo (vale por 15 minutos);
  - a senha SMTP e a chave secreta do Turnstile são gravadas cifradas (AES-256-GCM, chave em `storage/app.key`, fora do git) e nunca voltam para a tela, para o log ou para as mensagens de erro do SMTP;
  - o Turnstile só pode ser ativado depois que o próprio admin passa no desafio com as chaves salvas, o que evita se trancar para fora;
  - os limites de requisição têm piso mínimo;
  - proxies confiáveis precisam ser faixas de /8 ou menores;
  - logo e favicon são validados pelo conteúdo e regravados como PNG (sem SVG nem metadados).
- **Rate limit e Turnstile:** ver a seção acima.
  - IPv6 é contado por prefixo /64.
  - Rotas inexistentes e clientes acima do limite não criam sessão.
  - Cada estouro de limite é auditado uma vez por janela.
  - O login leva o mesmo tempo para e-mails existentes e inexistentes.
  - O bloqueio por e-mail não tranca o dono da conta quando ele entra de um IP já usado com sucesso.
- **Sessões e cache:**
  - as sessões ficam em `storage/sessions` (pasta própria, fora do diretório compartilhado do PHP);
  - as páginas logadas saem com `Cache-Control: no-store`;
  - acessos por HTTP são redirecionados para o `app_url` em HTTPS.
- **Anexos:**
  - fora imagens, são baixados como `application/octet-stream`, com o nome do arquivo sanitizado;
  - há uma cota total de espaço (`upload_cota_mb`).
- **Cron por URL:** o token só é aceito no cabeçalho `X-Cron-Token`. Força bruta no login é bloqueada em três níveis:
  - 8 falhas no mesmo e-mail e IP;
  - 50 falhas no mesmo IP;
  - 40 falhas no mesmo e-mail, de vários IPs.

  Assim um atacante não consegue travar a conta de outra pessoa nem derrubar uma empresa inteira atrás de NAT.
- **SMTP:** recusa autenticação sem TLS/SSL e detecta injeção de respostas no STARTTLS.
- Erros técnicos só aparecem para administradores, e somente com `debug` ligado.
- Controle por perfil em cada ação. O técnico executa apenas as OTs atribuídas a ele (ou sem responsável) e aponta só as próprias horas.
- Concorrência:
  - numeração atômica (`sequencias`);
  - pedido, OT, plano e item de material são travados com `SELECT … FOR UPDATE`;
  - nenhuma corrida duplica OT, número ou movimento de estoque (testado com 8 workers simultâneos).
- Validação de entradas:
  - números no formato brasileiro (`1.234,56`), sem negativos nem notação científica;
  - datas válidas, nunca no futuro na conclusão;
  - limites dos campos respeitados.
- Exportações CSV protegidas contra injeção de fórmulas.
- Upload:
  - lista de extensões permitidas e limite de tamanho;
  - arquivos salvos fora da raiz pública com nome aleatório, e o ativo precisa existir;
  - download apenas autenticado, com PDF sempre baixado em vez de aberto no navegador e cabeçalho CSP `sandbox`.
- Auditoria de ações (tabela `auditoria`), incluindo as falhas de login.

## Revisão independente

Esta versão passou por quatro revisões paralelas (segurança, lógica de negócio, banco/desempenho e QA funcional com navegador). Todos os achados confirmados foram corrigidos e testados de novo.

Destaques da revisão de lógica de negócio:
- OT preventiva cancelada pula a ocorrência do plano;
- reabrir e concluir de novo não reprograma o plano duas vezes;
- troca de horímetro preserva o saldo até a próxima revisão;
- não há mais deriva de datas (31/01 → 28/02 → 31/03);
- a parada é rateada por período e a de pai e componente não é contada duas vezes;
- material reservado não entra no custo realizado.

Desempenho, medido com 200 mil OTs:
- painel cerca de 10× mais rápido;
- geração de preventivas 8× mais rápida;
- calendário passou de 2.188 para 2 consultas.

## Estrutura

```
app/
  bootstrap.php   config, PDO, includes
  helpers.php     HTML, banco, formatação, CSV, árvores, numeração, regras de OT
  auth.php        sessão, login, matriz de permissões
  seguranca.php   IP real (proxy/Cloudflare), rate limit, Turnstile, CSP/nonce/SRI
  tabela.php      tabelas server-side (busca, ordenação, paginação, CSV, AJAX)
  configuracoes.php configurações pelo painel (validação, segredos cifrados, marca, tema)
  preventiva.php  motor de preventivas (geração, reprogramação, projeção)
  estoque.php     movimentação com custo médio
  kpi.php         cálculo de indicadores
  email.php       fila, SMTP e notificações
  migracoes.php   aplicação das migrações
  modulos/        um arquivo por módulo (front controller ?r=modulo/acao; m = interface mobile)
  views/          layouts desktop e mobile
config/           config.sample.php
cron/             gerar_preventivas.php, enviar_emails.php
database/         schema.sql, migracoes/*.sql, demo.php
public/           index.php, q.php (link do QR), marca.php (logo/favicon/tema), robots.php, manifest.php, sw.js, assets/
storage/uploads/  anexos (fora da raiz pública)
install.php, migrate.php
```

## Definições dos KPIs

- **Disponibilidade** = (horas de operação − horas de parada) ÷ horas de operação. As horas de operação vêm de `horas_operacao_dia` × dias do período.
- **MTBF** = (horas de operação − parada corretiva) ÷ nº de falhas (OTs corretivas concluídas).
- **MTTR** = média de (término − início) das corretivas concluídas.
- **% planejada** = OTs preventivas, preditivas, de inspeção, lubrificação e calibração ÷ total de OTs concluídas.
- **Cumprimento das preventivas** = OTs planejadas concluídas até a data prevista ÷ OTs planejadas previstas no período.

## Produção — recomendações

- DocumentRoot apontando para `public/`, para que as demais pastas fiquem fora do acesso web. Com Nginx, ou com Apache e `AllowOverride None`, os `.htaccess` não valem; se a raiz for o DocumentRoot, ficam expostos os anexos, os logs, os `.sql` e o `.git/`.

**Nginx**:
```nginx
server {
    server_name manutencao.empresa.com.br;
    root /var/www/mzmanut/public;
    index index.php;
    client_max_body_size 20m;
    location ~ /\. { deny all; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```
**Apache 2.4**:
```apache
<VirtualHost *:443>
    ServerName manutencao.empresa.com.br
    DocumentRoot /var/www/mzmanut/public
    UseCanonicalName On
    <Directory /var/www/mzmanut/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```
- HTTPS (exigido pela câmera do leitor de QR) e `debug => false`.
- MySQL 8+ ou MariaDB 10.5+; `innodb_buffer_pool_size` de 1 a 2 GB para bases grandes.
- Atrás de Cloudflare ou de proxy reverso, configure `proxy` no `config.php`. Sem isso, todos os acessos parecem vir do IP do proxy.
