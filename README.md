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
| **Cadastros** | Localizações (em árvore), categorias, fornecedores, técnicos (internos ou terceirizados, com custo/hora), especialidades e usuários. |

### Perfis de acesso

| Perfil | Acesso |
|---|---|
| `admin` | Tudo, incluindo usuários |
| `gestor` | Cadastros, ativos, planos, aprovação de pedidos, estoque e relatórios |
| `tecnico` | Executa OTs (status, checklist, mão de obra e materiais), registra leituras de contador, consulta ativos e estoque |
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

### Geração automática das preventivas (cron diário)

```cron
15 5 * * * /usr/bin/php /caminho/mzmanut/cron/gerar_preventivas.php >> /caminho/mzmanut/storage/cron.log 2>&1
```

Se não houver cron por CLI, use `https://seu-dominio/index.php?r=cron&token=SEU_CRON_TOKEN`. O botão **Gerar OTs vencidas**, na tela de planos, faz a mesma coisa sob demanda.

## Segurança

- Senhas com `password_hash` e rehash automático; limite de tentativas de login por sessão
- Token CSRF em todo POST; cookies de sessão `HttpOnly`/`SameSite=Lax` e `Secure` quando em HTTPS
- PDO com *prepared statements* em todas as consultas que recebem entrada do usuário
- Upload com lista de extensões permitidas e limite de tamanho, salvo fora da raiz pública com nome aleatório; download apenas autenticado
- Auditoria de ações na tabela `auditoria`

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
