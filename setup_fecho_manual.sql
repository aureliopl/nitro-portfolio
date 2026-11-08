-- ============================================================
--  Encerramento manual (provisório, aguardando a nota confirmar)
--  Rode UMA vez no phpMyAdmin (aba SQL), DEPOIS de setup_origem.sql.
--
--  fecho_manual=1 → posição encerrada na mão (preço de saída digitado).
--  Mostra a bolinha amarela. Ao importar a nota com a VENDA (ou a
--  COMPRA, no caso de recompra) deste ativo, o preço/data de saída são
--  atualizados pela nota e fecho_manual volta a 0 (a bolinha some).
-- ============================================================
ALTER TABLE apl_carteira ADD COLUMN fecho_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER origem;
