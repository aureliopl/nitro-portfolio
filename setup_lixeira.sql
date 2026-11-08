-- ============================================================
--  Tabela LIXEIRA — guarda o que for excluido de apl_carteira E apl_posicoes
--  Cada linha eh um snapshot completo da posicao removida.
--  origem = de qual tabela veio ('carteira' = Pessoal | 'posicoes' = Nitro)
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_lixeira (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,            -- dono do item excluido (multi-tenancy, Fase 1)
  carteira_id  INT          NULL,                -- carteira de origem (Fase 3); NULL quando origem = Nitro
  origem       ENUM('carteira','posicoes') NOT NULL,
  ref_id       INT          NULL,            -- id original na tabela de origem
  codigo       VARCHAR(20)  NULL,
  estrutura    ENUM('PUT','CALL') NULL,
  direcao      ENUM('C','V') NULL,                  -- snapshot do sentido (so origem = apl_carteira)
  data_inicio  DATE         NULL,
  data_final   DATE         NULL,
  dias         INT          NULL,
  preco_medio  DECIMAL(10,3) NULL,           -- so existe quando origem = apl_carteira
  lote         INT          NULL,            -- so existe quando origem = apl_carteira
  pago         DECIMAL(10,3) NULL,           -- so existe quando origem = apl_posicoes
  recebido     DECIMAL(10,3) NULL,
  status       VARCHAR(20)  NULL,
  payload      TEXT         NULL,            -- snapshot JSON completo (para restaurar)
  excluido_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_user_cart (user_id, carteira_id),
  INDEX idx_origem (origem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
