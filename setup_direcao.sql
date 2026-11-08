-- ============================================================
--  Migração: SENTIDO da operação (compra x venda descoberta)
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco u152994482_carteira.
--
--  direcao = 'C'  -> COMPRADA  (titular: paga o prêmio, opera "a débito")  [padrão]
--  direcao = 'V'  -> VENDIDA   (lançador/descoberta: recebe o prêmio, "a crédito")
--
--  Para a posição VENDIDA o resultado é invertido (lucra quando o prêmio cai).
--  Linhas já existentes ficam como 'C' (comprada), que é o comportamento atual.
-- ============================================================
ALTER TABLE apl_carteira ADD COLUMN direcao ENUM('C','V') NOT NULL DEFAULT 'C' AFTER estrutura;
ALTER TABLE apl_lixeira  ADD COLUMN direcao ENUM('C','V') NULL            AFTER estrutura;
