-- ============================================================
--  Tabela CARTEIRA (pessoal) — valores reais (preco_medio x lote)
--  Importe este arquivo apenas se a tabela ainda nao existir.
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_carteira (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,            -- dono da posicao (multi-tenancy, Fase 1)
  carteira_id  INT          NOT NULL,            -- carteira a que a posicao pertence (Fase 3)
  codigo       VARCHAR(20)  NOT NULL,
  estrutura    ENUM('PUT','CALL') NOT NULL,
  direcao      ENUM('C','V') NOT NULL DEFAULT 'C',   -- C = comprada (titular) | V = vendida (lançada/descoberta)
  data_inicio  DATE         NOT NULL,
  data_final   DATE         NULL,
  dias         INT          NULL,
  preco_medio  DECIMAL(10,3) NOT NULL,
  lote         INT          NOT NULL DEFAULT 100,
  recebido     DECIMAL(10,3) NULL,
  status       ENUM('Aberta','Encerrada') NOT NULL DEFAULT 'Aberta',
  criado_em    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_user_cart (user_id, carteira_id),
  INDEX idx_status (status),
  INDEX idx_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
