-- MZ Manutenção — CMMS (PHP + MySQL)
-- Schema MySQL 8 / MariaDB 10.5+
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS auditoria, movimentos_estoque, ot_materiais, ot_mao_obra, ot_tarefas, ordens_trabalho,
  plano_materiais, plano_tarefas, planos_manutencao, pedidos, leituras_contador, contadores,
  ativo_documentos, ativos, artigos, usuarios, tecnicos, especialidades, fornecedores,
  categorias_ativo, localizacoes, configuracoes;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE configuracoes (
  chave VARCHAR(64) NOT NULL PRIMARY KEY,
  valor TEXT,
  usuario_id INT NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE localizacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  codigo VARCHAR(30) NULL,
  nome VARCHAR(120) NOT NULL,
  descricao TEXT NULL,
  CONSTRAINT fk_loc_parent FOREIGN KEY (parent_id) REFERENCES localizacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_ativo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fornecedores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  cnpj VARCHAR(20) NULL,
  contato VARCHAR(100) NULL,
  telefone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  endereco VARCHAR(255) NULL,
  observacoes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE especialidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tecnicos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  especialidade_id INT NULL,
  fornecedor_id INT NULL COMMENT 'preenchido quando é técnico externo/terceirizado',
  custo_hora DECIMAL(10,2) NOT NULL DEFAULT 0,
  telefone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_tec_esp FOREIGN KEY (especialidade_id) REFERENCES especialidades(id) ON DELETE SET NULL,
  CONSTRAINT fk_tec_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  perfil ENUM('admin','gestor','tecnico','solicitante') NOT NULL DEFAULT 'solicitante',
  tecnico_id INT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_usr_tec FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ativos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(150) NOT NULL,
  parent_id INT NULL COMMENT 'ativo pai (árvore de equipamentos/componentes)',
  localizacao_id INT NULL,
  categoria_id INT NULL,
  fornecedor_id INT NULL,
  fabricante VARCHAR(100) NULL,
  modelo VARCHAR(100) NULL,
  numero_serie VARCHAR(100) NULL,
  data_aquisicao DATE NULL,
  valor_aquisicao DECIMAL(12,2) NULL,
  garantia_ate DATE NULL,
  criticidade ENUM('A','B','C') NOT NULL DEFAULT 'B',
  status ENUM('operacional','parado','em_manutencao','desativado') NOT NULL DEFAULT 'operacional',
  centro_custo VARCHAR(60) NULL,
  horas_operacao_dia DECIMAL(5,2) NOT NULL DEFAULT 24 COMMENT 'usado no cálculo de disponibilidade/MTBF',
  descricao TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_at_parent FOREIGN KEY (parent_id) REFERENCES ativos(id) ON DELETE SET NULL,
  CONSTRAINT fk_at_loc FOREIGN KEY (localizacao_id) REFERENCES localizacoes(id) ON DELETE SET NULL,
  CONSTRAINT fk_at_cat FOREIGN KEY (categoria_id) REFERENCES categorias_ativo(id) ON DELETE SET NULL,
  CONSTRAINT fk_at_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL,
  INDEX idx_at_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ativo_documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ativo_id INT NOT NULL,
  nome_original VARCHAR(255) NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  tamanho INT NOT NULL DEFAULT 0,
  enviado_por INT NULL,
  enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_at FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ativo_id INT NOT NULL,
  nome VARCHAR(80) NOT NULL,
  unidade VARCHAR(20) NOT NULL DEFAULT 'h',
  valor_atual DECIMAL(14,2) NOT NULL DEFAULT 0,
  atualizado_em DATETIME NULL,
  CONSTRAINT fk_cont_at FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leituras_contador (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contador_id INT NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  data_leitura DATETIME NOT NULL,
  usuario_id INT NULL,
  observacao VARCHAR(255) NULL,
  CONSTRAINT fk_leit_cont FOREIGN KEY (contador_id) REFERENCES contadores(id) ON DELETE CASCADE,
  INDEX idx_leit_data (contador_id, data_leitura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE artigos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  descricao VARCHAR(200) NOT NULL,
  unidade VARCHAR(10) NOT NULL DEFAULT 'un',
  familia VARCHAR(80) NULL,
  estoque_atual DECIMAL(12,3) NOT NULL DEFAULT 0,
  estoque_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
  custo_medio DECIMAL(12,4) NOT NULL DEFAULT 0,
  fornecedor_id INT NULL,
  localizacao VARCHAR(80) NULL COMMENT 'prateleira/posição no almoxarifado',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_art_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(20) NOT NULL UNIQUE,
  ativo_id INT NULL,
  localizacao_id INT NULL,
  solicitante_id INT NOT NULL,
  titulo VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
  ativo_parado TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('aberto','aprovado','rejeitado','convertido') NOT NULL DEFAULT 'aberto',
  resposta TEXT NULL,
  avaliado_por INT NULL,
  avaliado_em DATETIME NULL,
  ot_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ped_at FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_loc FOREIGN KEY (localizacao_id) REFERENCES localizacoes(id) ON DELETE SET NULL,
  CONSTRAINT fk_ped_usr FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  INDEX idx_ped_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE planos_manutencao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ativo_id INT NOT NULL,
  titulo VARCHAR(150) NOT NULL,
  tipo ENUM('preventiva','preditiva','inspecao','lubrificacao','calibracao') NOT NULL DEFAULT 'preventiva',
  descricao TEXT NULL,
  gatilho ENUM('tempo','contador') NOT NULL DEFAULT 'tempo',
  periodicidade INT NULL COMMENT 'intervalo (gatilho tempo)',
  unidade_periodo ENUM('dia','semana','mes','ano') NULL DEFAULT 'mes',
  base_calculo ENUM('fixa','conclusao') NOT NULL DEFAULT 'fixa' COMMENT 'fixa = calendário, conclusao = a partir da última execução',
  contador_id INT NULL,
  intervalo_contador DECIMAL(14,2) NULL,
  proxima_data DATE NULL,
  proximo_valor DECIMAL(14,2) NULL,
  ultima_execucao DATE NULL,
  ultimo_valor DECIMAL(14,2) NULL,
  antecedencia_dias INT NOT NULL DEFAULT 7 COMMENT 'gera a OT X dias antes / X unidades antes do contador',
  responsavel_id INT NULL,
  prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
  duracao_estimada DECIMAL(6,2) NULL COMMENT 'horas',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pl_at FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_cont FOREIGN KEY (contador_id) REFERENCES contadores(id) ON DELETE SET NULL,
  CONSTRAINT fk_pl_resp FOREIGN KEY (responsavel_id) REFERENCES tecnicos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plano_tarefas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plano_id INT NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  descricao VARCHAR(255) NOT NULL,
  CONSTRAINT fk_pt_pl FOREIGN KEY (plano_id) REFERENCES planos_manutencao(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plano_materiais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plano_id INT NOT NULL,
  artigo_id INT NOT NULL,
  quantidade DECIMAL(12,3) NOT NULL,
  CONSTRAINT fk_pm_pl FOREIGN KEY (plano_id) REFERENCES planos_manutencao(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_art FOREIGN KEY (artigo_id) REFERENCES artigos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ordens_trabalho (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(20) NOT NULL UNIQUE,
  ativo_id INT NULL,
  pedido_id INT NULL,
  plano_id INT NULL,
  tipo ENUM('corretiva','preventiva','preditiva','inspecao','lubrificacao','calibracao','melhoria') NOT NULL DEFAULT 'corretiva',
  prioridade ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
  status ENUM('aberta','planejada','em_execucao','aguardando_pecas','concluida','cancelada') NOT NULL DEFAULT 'aberta',
  titulo VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  responsavel_id INT NULL,
  data_abertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data_prevista DATE NULL,
  data_inicio DATETIME NULL,
  data_fim DATETIME NULL,
  ativo_parado TINYINT(1) NOT NULL DEFAULT 0,
  horas_parada DECIMAL(8,2) NOT NULL DEFAULT 0,
  causa_falha VARCHAR(150) NULL,
  solucao TEXT NULL,
  custo_externo DECIMAL(12,2) NOT NULL DEFAULT 0,
  leitura_contador DECIMAL(14,2) NULL,
  criado_por INT NULL,
  CONSTRAINT fk_ot_at FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ot_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ot_pl FOREIGN KEY (plano_id) REFERENCES planos_manutencao(id) ON DELETE SET NULL,
  CONSTRAINT fk_ot_resp FOREIGN KEY (responsavel_id) REFERENCES tecnicos(id) ON DELETE SET NULL,
  INDEX idx_ot_status (status),
  INDEX idx_ot_prevista (data_prevista),
  INDEX idx_ot_fim (data_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pedidos ADD CONSTRAINT fk_ped_ot FOREIGN KEY (ot_id) REFERENCES ordens_trabalho(id) ON DELETE SET NULL;

CREATE TABLE ot_tarefas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ot_id INT NOT NULL,
  ordem INT NOT NULL DEFAULT 0,
  descricao VARCHAR(255) NOT NULL,
  concluida TINYINT(1) NOT NULL DEFAULT 0,
  observacao VARCHAR(255) NULL,
  CONSTRAINT fk_ott_ot FOREIGN KEY (ot_id) REFERENCES ordens_trabalho(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ot_mao_obra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ot_id INT NOT NULL,
  tecnico_id INT NOT NULL,
  data DATE NOT NULL,
  horas DECIMAL(6,2) NOT NULL,
  custo_hora DECIMAL(10,2) NOT NULL DEFAULT 0,
  observacao VARCHAR(255) NULL,
  CONSTRAINT fk_mo_ot FOREIGN KEY (ot_id) REFERENCES ordens_trabalho(id) ON DELETE CASCADE,
  CONSTRAINT fk_mo_tec FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ot_materiais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ot_id INT NOT NULL,
  artigo_id INT NOT NULL,
  quantidade DECIMAL(12,3) NOT NULL,
  custo_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
  baixado TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = já deu saída no estoque',
  CONSTRAINT fk_om_ot FOREIGN KEY (ot_id) REFERENCES ordens_trabalho(id) ON DELETE CASCADE,
  CONSTRAINT fk_om_art FOREIGN KEY (artigo_id) REFERENCES artigos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movimentos_estoque (
  id INT AUTO_INCREMENT PRIMARY KEY,
  artigo_id INT NOT NULL,
  tipo ENUM('entrada','saida','ajuste') NOT NULL,
  quantidade DECIMAL(12,3) NOT NULL,
  custo_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
  saldo_apos DECIMAL(12,3) NOT NULL,
  ot_id INT NULL,
  documento VARCHAR(60) NULL COMMENT 'NF, requisição etc.',
  usuario_id INT NULL,
  observacao VARCHAR(255) NULL,
  data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mov_art FOREIGN KEY (artigo_id) REFERENCES artigos(id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_ot FOREIGN KEY (ot_id) REFERENCES ordens_trabalho(id) ON DELETE SET NULL,
  INDEX idx_mov_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auditoria (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  acao VARCHAR(30) NOT NULL,
  entidade VARCHAR(40) NOT NULL,
  entidade_id INT NULL,
  detalhe VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aud_ent (entidade, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
