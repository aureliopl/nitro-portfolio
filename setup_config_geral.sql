-- ============================================================
--  FASE 2 — CONFIGURACOES GERAIS DO SISTEMA (store GLOBAL).
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco — junto da Fase 1/2,
--  quando o deploy acontecer. No dev (dev_nitro) rode agora para testar.
--
--  IMPORTANTE: este store e' GLOBAL (vale para a plataforma inteira) — NAO
--  tem user_id, ao contrario de apl_config_app, que e' config POR usuario
--  (PK composta (user_id, chave)). Sao tabelas distintas de proposito.
--
--  Chaves (defaults definidos em CODIGO, em config_geral.php):
--    site_titulo    — titulo geral da plataforma (alimenta o %title% das paginas)
--    site_descricao — descricao geral do site
--    site_icone     — URL do icone/favicon (upload e' sessao propria; aqui e' URL)
--    fuso_horario   — ex.: America/Sao_Paulo (horario da B3)
--    formato_data   — ex.: d/m/Y
--    formato_hora   — ex.: H:i (24h)
-- ============================================================

CREATE TABLE IF NOT EXISTS apl_config_geral (
  chave         VARCHAR(50) PRIMARY KEY,
  valor         TEXT NULL,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semeia os defaults (nao sobrescreve se ja existir).
INSERT IGNORE INTO apl_config_geral (chave, valor) VALUES
  ('site_titulo',    'Nitro'),
  ('site_descricao', 'Acompanhamento de carteiras de opções da B3.'),
  ('site_icone',     ''),
  ('fuso_horario',   'America/Sao_Paulo'),
  ('formato_data',   'd/m/Y'),
  ('formato_hora',   'H:i');
