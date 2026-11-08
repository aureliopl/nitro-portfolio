-- ============================================================
--  Tabela BETHA (Carteira de simulação — modelo simples pago/recebido)
--  Espelho exato de apl_posicoes (Nitro): sem carteira_id (única por
--  usuário), sem taxas, sem origem/fecho_manual, com direcao (C/V).
--  Pessoal do super_admin, igual à Nitro (gate pode_ver_betha).
--  Idempotente e puramente aditivo. Importe apenas se ainda não existir.
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_betha (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,            -- dono da posicao (multi-tenancy, Fase 1)
  codigo        VARCHAR(20)  NOT NULL,
  estrutura     ENUM('PUT','CALL') NOT NULL,
  direcao       ENUM('C','V') NOT NULL DEFAULT 'C', -- C = comprada (paga o premio) | V = vendida (recebe)
  data_inicio   DATE         NOT NULL,
  data_final    DATE         NULL,
  dias          INT          NULL,
  recebido      DECIMAL(10,3) NULL,
  pago          DECIMAL(10,3) NOT NULL,
  status        ENUM('Aberta','Encerrada') NOT NULL DEFAULT 'Aberta',
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_status (status),
  INDEX idx_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A lixeira guarda a carteira de ORIGEM no ENUM `origem`. A Betha é um novo
-- valor. (Em banco novo o setup_lixeira.sql já pode nascer com 'betha'; este
-- ALTER cobre o banco existente. Idempotente: se já for o ENUM final, é no-op.)
ALTER TABLE apl_lixeira
  MODIFY COLUMN origem ENUM('carteira','posicoes','betha') NOT NULL;
