-- ============================================================
--  NITRO — coluna `direcao` (C = comprada | V = vendida/lancada)
--
--  Ate aqui `direcao` existia SO na Carteira rica. O Nitro passa a ter
--  o mesmo sentido de operacao no formulario de nova posicao, entao a
--  `apl_posicoes` ganha a coluna com o mesmo ENUM e o mesmo default.
--
--  Migracao IDEMPOTENTE de banco existente: rode UMA vez.
--  (Em banco zerado nao precisa — `setup_posicoes.sql` ja cria a coluna.)
--  Todas as posicoes que ja existem viram 'C' (comprada), que e o que
--  o modelo antigo assumia: `pago` = premio pago, resultado = rec - pago.
-- ============================================================

ALTER TABLE apl_posicoes
  ADD COLUMN IF NOT EXISTS direcao ENUM('C','V') NOT NULL DEFAULT 'C' AFTER estrutura;

-- Rede de seguranca: qualquer linha sem sentido definido volta p/ comprada.
UPDATE apl_posicoes SET direcao='C' WHERE direcao IS NULL OR direcao='';
