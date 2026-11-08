-- ============================================================
--  Detalhe das negociações por nota (p/ o modal "ver nota")
--  Rode UMA vez no phpMyAdmin (aba SQL).
--  Guarda os itens negociados de cada importação (JSON):
--    [{"codigo":"PETRS419","estrutura":"PUT","acao":"abrir","lote":100,"preco":1.22}, ...]
--  Notas importadas ANTES desta coluna ficam NULL → o modal reconstrói
--  as negociações a partir do log de ações (coluna acoes), quando houver.
-- ============================================================
ALTER TABLE apl_notas ADD COLUMN itens TEXT NULL AFTER acoes;
