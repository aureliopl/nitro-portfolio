-- ============================================================
--  FASE 4.1 — SERIE DIARIA DO PATRIMONIO (por usuario E carteira).
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco.
--  Idempotente (CREATE TABLE IF NOT EXISTS).
--
--  Diferente de apl_cotacoes/apl_indices (globais), esta tabela e' de DADO
--  DO USUARIO: a chave e' (user_id, carteira_id, data) — cada carteira rica
--  e' uma conta isolada (Fase 3) e tem a sua propria serie.
--
--  E' uma tabela MATERIALIZADA (cache calculado), nao uma fonte de verdade:
--  pode ser apagada e reconstruida a qualquer momento a partir de
--  apl_carteira + apl_notas + apl_movimentacoes + apl_cotacoes
--  (series_rebuild() em series.php). Recalcular a cada page-load seria caro.
--
--  CONVENCOES DE SINAL (nao inventar outras):
--   - capital_aplicado / valor_mercado usam o NET da vendida (entra NEGATIVA),
--     mesma logica de invNet()/posNet() do index.php — e' o que bate com o
--     "valor aplicado" e o "total em bolsa" da corretora.
--   - caixa segue EXATAMENTE a formula do extrato.php ("Disponivel para uso").
--   - fluxo_externo e' SO aporte/saque do Extrato. Nota de corretagem NAO e'
--     fluxo externo (ela move caixa e posicao ao mesmo tempo — e' interna).
--     Errar isso contamina toda a rentabilidade (TWR).
-- ============================================================

CREATE TABLE IF NOT EXISTS apl_serie_diaria (
  user_id          INT  NOT NULL,
  carteira_id      INT  NOT NULL,
  data             DATE NOT NULL,                       -- pregao
  capital_aplicado DECIMAL(14,2) NOT NULL DEFAULT 0,    -- preco_medio x lote das abertas (net da vendida)
  valor_mercado    DECIMAL(14,2) NOT NULL DEFAULT 0,    -- cotacao x lote das abertas (net da vendida)
  caixa            DECIMAL(14,2) NOT NULL DEFAULT 0,    -- aporte inicial + movimentacoes + fluxo_caixa das notas
  patrimonio       DECIMAL(14,2) NOT NULL DEFAULT 0,    -- caixa + valor_mercado
  fluxo_externo    DECIMAL(14,2) NOT NULL DEFAULT 0,    -- SO aportes/saques do dia
  realizado_acum   DECIMAL(14,2) NOT NULL DEFAULT 0,    -- P&L das encerradas ate a data
  custos_acum      DECIMAL(14,2) NOT NULL DEFAULT 0,    -- total_taxas das notas ate a data
  PRIMARY KEY (user_id, carteira_id, data),
  INDEX idx_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
