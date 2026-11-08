-- ============================================================
--  FASE 3 — Multiplas carteiras por usuario (modelo RICO)
--  Tabela apl_carteiras: cada linha e' uma carteira do usuario.
--  Cada carteira e' uma CONTA isolada (posicoes, notas, extrato/caixa,
--  aporte e checkpoints proprios). So a Carteira rica e' multi-carteira;
--  a Nitro (apl_posicoes) continua unica por usuario.
--
--  Importe este arquivo apenas se a tabela ainda nao existir
--  (fresh-install). Para migrar um banco com dados, use setup_multicarteira.sql.
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_carteiras (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT          NOT NULL,            -- dono da carteira
  nome                VARCHAR(60)  NOT NULL,            -- rotulo escolhido pelo usuario
  cor                 VARCHAR(7)   NULL,                -- cor opcional p/ o seletor (futuro)
  ordem               INT          NOT NULL DEFAULT 0,  -- ordem de exibicao no seletor
  is_default          TINYINT(1)   NOT NULL DEFAULT 0,  -- carteira padrao (ativa no 1o acesso)
  aporte_inicial      DECIMAL(12,2) NULL,               -- ponto de partida do caixa (saiu do apl_config_app)
  aporte_inicial_data DATE          NULL,               -- data do aporte inicial
  criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
