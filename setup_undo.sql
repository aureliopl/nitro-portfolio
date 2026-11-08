-- ============================================================
--  Desfazer importação + Pontos de restauração (14/06/2026)
--  Rode UMA vez no phpMyAdmin (aba SQL).
-- ============================================================

-- 1) Cada nota guarda o que a importação fez (p/ desfazer com precisão).
--    JSON: [{"t":"open","id":N}, {"t":"close","id":N,"prev":{...}}]
ALTER TABLE apl_notas ADD COLUMN acoes TEXT NULL AFTER qtd_itens;

-- 2) Pontos de restauração — snapshot completo do estado (p/ "reverter o dia").
CREATE TABLE IF NOT EXISTS apl_checkpoints (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  user_id   INT          NOT NULL,            -- dono do ponto de restauracao (multi-tenancy, Fase 1)
  carteira_id INT        NOT NULL,            -- carteira a que o checkpoint pertence (Fase 3)
  rotulo    VARCHAR(160) NOT NULL,
  tipo      ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  dados     LONGTEXT NOT NULL,                 -- JSON com as linhas de carteira/notas/movimentacoes/lixeira (da carteira)
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_user_cart (user_id, carteira_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
