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
| **Cadastros** | Localizações (em árvore), categorias, fornecedores, técnicos (internos ou terceirizados, com custo/hora), especialidades e usuários. |

### Perfis de acesso

| Perfil | Acesso |
|---|---|
| `admin` | Tudo, incluindo usuários |
| `gestor` | Cadastros, ativos, planos, aprovação de pedidos, estoque e relatórios |
| `tecnico` | Executa as OTs atribuídas a ele ou sem responsável (status, checklist, as próprias horas, materiais), registra leituras de contador, consulta ativos e estoque |
| `solicitante` | Abre e acompanha os próprios pedidos |

## Instalação

```bash
# 1. Banco
mysql -u root -p -e "CREATE DATABASE cmms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'cmms'@'localhost' IDENTIFIED BY 'SENHA'; GRANT ALL ON cmms.* TO 'cmms'@'localhost';"

# 2. Configuração
cp config/config.sample.php config/config.php   # edite db, empresa, base_url, cron_token

# 3. Estrutura + administrador (--demo popula uma fábrica fictícia com 6 meses de histórico)
php install.php --email=admin@empresa.com.br --senha=SenhaForte123 --demo
```

O **DocumentRoot** do Apache/Nginx deve apontar para `public/`. As pastas `app/`, `config/`, `storage/` e `database/` ficam fora da raiz pública. Em hospedagem compartilhada sem acesso ao DocumentRoot, use o `.htaccess` da raiz, que redireciona para `public/`. A pasta `storage/` também tem um `.htaccess` que bloqueia o acesso.

Para testar localmente: `php -S localhost:8080 -t public`

### Atualizações (migrações)

Em uma instalação existente, depois de atualizar os arquivos rode `php migrate.php` (`--status` lista o que já foi aplicado).

### Tarefas agendadas (cron)

```cron
# gera as preventivas devidas e envia o resumo diário aos gestores
15 5 * * * /usr/bin/php /caminho/mzmanut/cron/gerar_preventivas.php >> /caminho/mzmanut/storage/cron.log 2>&1
# envia a fila de e-mails
*/5 * * * * /usr/bin/php /caminho/mzmanut/cron/enviar_emails.php >> /caminho/mzmanut/storage/cron.log 2>&1
```

Para o e-mail funcionar, preencha o bloco `email` do `config.php` e defina `app_url` (o domínio público), que é usado nos links e nos QR Codes. A câmera do leitor de QR só funciona em **HTTPS**.

Se não houver cron por CLI, use `https://seu-dominio/index.php?r=cron&token=SEU_CRON_TOKEN`. O botão **Gerar OTs vencidas**, na tela de planos, faz a mesma coisa sob demanda.

## Segurança

- Senhas com `password_hash` e rehash automático; bloqueio de login após 8 falhas em 15 min, por e-mail ou IP, gravado no banco.
- Sessão revalidada no banco a cada requisição:
  - usuário desativado ou rebaixado perde o acesso na hora;
  - trocar a senha encerra as outras sessões;
  - a sessão expira após 4 h sem uso, ou 16 h no máximo.
- Cookies `HttpOnly`/`SameSite=Lax`, `Secure` em HTTPS (`forcar_https` atrás de proxy) e `session.use_strict_mode`. O token CSRF é renovado no login.
- Token CSRF em todo POST; o logout também é por POST.
- PDO com *prepared statements*; CSP, `X-Frame-Options`, `nosniff` e HSTS em HTTPS.
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
  helpers.php     HTML, banco, formatação, CSV, árvores
  auth.php        sessão, login, matriz de permissões
  preventiva.php  motor de preventivas (geração, reprogramação, projeção)
  estoque.php     movimentação com custo médio
  kpi.php         cálculo de indicadores
  modulos/        um arquivo por módulo (front controller ?r=modulo/acao)
  views/          header/footer
config/           config.sample.php
cron/             gerar_preventivas.php
database/         schema.sql, demo.php
public/           index.php, assets/
storage/uploads/  anexos (fora da raiz pública)
```

## Definições dos KPIs

- **Disponibilidade** = (horas de operação − horas de parada) ÷ horas de operação. As horas de operação vêm de `horas_operacao_dia` × dias do período.
- **MTBF** = (horas de operação − parada corretiva) ÷ nº de falhas (OTs corretivas concluídas).
- **MTTR** = média de (término − início) das corretivas concluídas.
- **% planejada** = OTs preventivas, preditivas, de inspeção, lubrificação e calibração ÷ total de OTs concluídas.
- **Cumprimento das preventivas** = OTs planejadas concluídas até a data prevista ÷ OTs planejadas previstas no período.

## Produção — recomendações

- DocumentRoot apontando para `public/` (as demais pastas ficam fora do acesso web). No Nginx, não há `.htaccess`, então isso é obrigatório.
- HTTPS (exigido pela câmera do leitor de QR) e `debug => false`.
- MySQL 8+ ou MariaDB 10.5+; `innodb_buffer_pool_size` de 1 a 2 GB para bases grandes.
- O bloqueio de login por IP considera `REMOTE_ADDR`. Atrás de proxy reverso, configure o servidor web para repassar o IP real do cliente; senão todos os acessos parecem vir do mesmo IP.
