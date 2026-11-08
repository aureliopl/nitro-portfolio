-- ============================================================
--  Tabela POSICOES (Nitro / Terceiros) — modelo simples pago/recebido
--  Sem origem nem fecho_manual (campos exclusivos da Carteira).
--  Tem `direcao` (C/V), igual a Carteira — ver setup_nitro_direcao.sql.
--  Importe este arquivo apenas se a tabela ainda nao existir.
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_posicoes (
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
