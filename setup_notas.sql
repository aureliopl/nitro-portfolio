-- ============================================================
--  Notas de corretagem (taxas/custos) + configuração do app
--  Usado APENAS pela Carteira (Pessoal). Nitro nao usa.
-- ============================================================

-- Cada nota de corretagem importada (um PDF = uma ou mais apl_notas)
CREATE TABLE IF NOT EXISTS apl_notas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NOT NULL,           -- dono da nota (multi-tenancy, Fase 1)
  carteira_id     INT          NOT NULL,           -- carteira a que a nota pertence (Fase 3)
  nr_nota         VARCHAR(30)  NULL,
  corretora       VARCHAR(40)  NOT NULL DEFAULT 'RICO',
  data_pregao     DATE         NOT NULL,
  valor_operacoes DECIMAL(12,2) NOT NULL DEFAULT 0,   -- soma das compras/vendas de opcoes
  taxa_liquidacao DECIMAL(10,2) NOT NULL DEFAULT 0,
  taxa_registro   DECIMAL(10,2) NOT NULL DEFAULT 0,
  emolumentos     DECIMAL(10,2) NOT NULL DEFAULT 0,
  outros          DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_custos    DECIMAL(10,2) NOT NULL DEFAULT 0,    -- "Total Custos / Despesas" (bucket operacional)
  total_taxas     DECIMAL(10,2) NOT NULL DEFAULT 0,    -- liquidacao+registro+emolumentos+total_custos
  liquido         DECIMAL(12,2) NOT NULL DEFAULT 0,    -- "Liquido para ..."
  qtd_itens       INT          NOT NULL DEFAULT 0,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_user_cart (user_id, carteira_id),
  UNIQUE KEY uniq_nota (user_id, carteira_id, nr_nota, data_pregao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações simples (chave/valor) POR USUARIO: senha do PDF, corretora padrao, etc.
-- PK composta (user_id, chave) — cada usuario tem a sua propria config.
CREATE TABLE IF NOT EXISTS apl_config_app (
  chave         VARCHAR(50) NOT NULL,
  user_id       INT         NOT NULL,
  valor         TEXT NULL,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
