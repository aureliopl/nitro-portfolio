-- ============================================================
--  FASE 3 — MIGRACAO p/ multiplas carteiras (banco com dados).
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco de PRODUCAO,
--  DEPOIS da Fase 1 (setup_multitenancy.sql — exige user_id em todas as tabelas).
--
--  (No fresh-install os setup_*.sql ja nascem com carteira_id e a
--   tabela apl_carteiras vem do setup_carteiras.sql — nao rode este script.)
--
--  O que faz:
--   1) cria apl_carteiras;
--   2) cria UMA carteira "Principal" (is_default=1) por usuario, ja
--      herdando o aporte_inicial/_data que hoje mora no apl_config_app;
--   3) adiciona carteira_id nas tabelas da conta rica + lixeira;
--   4) backfill: liga cada linha existente a' carteira Principal do seu dono;
--   5) torna carteira_id NOT NULL (menos a lixeira) + indices;
--   6) remove as chaves de aporte do apl_config_app (ja migradas).
--
--  Nitro (apl_posicoes) NAO entra: continua unica por usuario.
-- ============================================================

-- ── 1) tabela das carteiras ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS apl_carteiras (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT          NOT NULL,
  nome                VARCHAR(60)  NOT NULL,
  cor                 VARCHAR(7)   NULL,
  ordem               INT          NOT NULL DEFAULT 0,
  is_default          TINYINT(1)   NOT NULL DEFAULT 0,
  aporte_inicial      DECIMAL(12,2) NULL,
  aporte_inicial_data DATE          NULL,
  criado_em           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2) uma carteira "Principal" por usuario (herda o aporte do config_app) ───
INSERT INTO apl_carteiras (user_id, nome, ordem, is_default, aporte_inicial, aporte_inicial_data)
SELECT u.id, 'Principal', 0, 1,
       NULLIF(ci.valor, '')                AS aporte_inicial,
       NULLIF(cd.valor, '')                AS aporte_inicial_data
FROM apl_usuarios u
LEFT JOIN apl_config_app ci ON ci.user_id = u.id AND ci.chave = 'aporte_inicial'
LEFT JOIN apl_config_app cd ON cd.user_id = u.id AND cd.chave = 'aporte_inicial_data';

-- ── 3) coluna carteira_id (NULL temporariamente) ─────────────────────────────
ALTER TABLE apl_carteira      ADD COLUMN carteira_id INT NULL AFTER user_id;
ALTER TABLE apl_notas         ADD COLUMN carteira_id INT NULL AFTER user_id;
ALTER TABLE apl_movimentacoes ADD COLUMN carteira_id INT NULL AFTER user_id;
ALTER TABLE apl_checkpoints   ADD COLUMN carteira_id INT NULL AFTER user_id;
ALTER TABLE apl_lixeira       ADD COLUMN carteira_id INT NULL AFTER user_id;

-- ── 4) backfill: liga cada linha a' carteira Principal do seu dono ───────────
UPDATE apl_carteira      t JOIN apl_carteiras w ON w.user_id = t.user_id AND w.is_default = 1 SET t.carteira_id = w.id WHERE t.carteira_id IS NULL;
UPDATE apl_notas         t JOIN apl_carteiras w ON w.user_id = t.user_id AND w.is_default = 1 SET t.carteira_id = w.id WHERE t.carteira_id IS NULL;
UPDATE apl_movimentacoes t JOIN apl_carteiras w ON w.user_id = t.user_id AND w.is_default = 1 SET t.carteira_id = w.id WHERE t.carteira_id IS NULL;
UPDATE apl_checkpoints   t JOIN apl_carteiras w ON w.user_id = t.user_id AND w.is_default = 1 SET t.carteira_id = w.id WHERE t.carteira_id IS NULL;
-- lixeira: so as linhas de origem CARTEIRA recebem carteira_id (Nitro fica NULL).
-- (cobre os dois rotulos de origem por causa de inconsistencia historica do ENUM)
UPDATE apl_lixeira       t JOIN apl_carteiras w ON w.user_id = t.user_id AND w.is_default = 1
  SET t.carteira_id = w.id
  WHERE t.carteira_id IS NULL AND t.origem IN ('carteira', 'apl_carteira');

-- ── 5) carteira_id obrigatoria (menos a lixeira, que mistura Nitro) + indices ─
ALTER TABLE apl_carteira      MODIFY carteira_id INT NOT NULL, ADD INDEX idx_user_cart (user_id, carteira_id);
ALTER TABLE apl_notas         MODIFY carteira_id INT NOT NULL, ADD INDEX idx_user_cart (user_id, carteira_id);
ALTER TABLE apl_movimentacoes MODIFY carteira_id INT NOT NULL, ADD INDEX idx_user_cart (user_id, carteira_id);
ALTER TABLE apl_checkpoints   MODIFY carteira_id INT NOT NULL, ADD INDEX idx_user_cart (user_id, carteira_id);
ALTER TABLE apl_lixeira       ADD INDEX idx_user_cart (user_id, carteira_id);

-- dedup de nota passa a ser POR CARTEIRA (contas isoladas)
ALTER TABLE apl_notas DROP INDEX uniq_nota, ADD UNIQUE KEY uniq_nota (user_id, carteira_id, nr_nota, data_pregao);

-- conserto do ENUM origem da lixeira: o codigo (api.php + lixeira.php) sempre usou
-- 'carteira'/'posicoes', mas o ENUM antigo era 'apl_carteira'/'apl_posicoes' (sob
-- sql_mode STRICT o delete->lixeira nem grava). Normaliza valores legados e corrige.
ALTER TABLE apl_lixeira MODIFY origem VARCHAR(20) NOT NULL;
UPDATE apl_lixeira SET origem = 'carteira' WHERE origem IN ('apl_carteira');
UPDATE apl_lixeira SET origem = 'posicoes' WHERE origem IN ('apl_posicoes');
ALTER TABLE apl_lixeira MODIFY origem ENUM('carteira','posicoes') NOT NULL;

-- ── 6) aporte saiu do config_app (agora mora na carteira) ────────────────────
DELETE FROM apl_config_app WHERE chave IN ('aporte_inicial', 'aporte_inicial_data');
