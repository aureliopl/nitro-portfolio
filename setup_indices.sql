-- ============================================================
--  FASE 4.1 — INDICES COMPARADORES (store GLOBAL).  apl_indices
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco.
--  Idempotente (CREATE TABLE IF NOT EXISTS).
--
--  GLOBAL, sem user_id: CDI, IPCA e os indices de bolsa sao dado publico.
--
--  O QUE CADA `indice` GUARDA EM `valor` (unidades DIFERENTES de proposito —
--  a normalizacao para "fator acumulado base 100" acontece na LEITURA,
--  em indices_series() no series.php):
--    cdi   -> % AO DIA          (Banco Central, serie SGS 12)   ex.: 0.052531
--    ipca  -> % NO MES          (Banco Central, serie SGS 433)  ex.: 0.16
--    ibov  -> PRECO de fechamento do ETF BOVA11  (proxy do Ibovespa)
--    sp500 -> PRECO de fechamento do ETF IVVB11  (proxy do S&P 500, JA EM REAIS)
--    ifix  -> PRECO de fechamento do ETF XFIX11  (proxy do IFIX)
--
--  DECISAO (ADR 015): indices de bolsa por ETF-PROXY via o mesmo COTAHIST que
--  ja usamos — sem token, sem custo, sem dependencia nova. Em troca existe
--  descolamento em relacao ao indice puro (taxa de administracao, tracking
--  difference), por isso a UI SEMPRE rotula como proxy ("Ibovespa - via BOVA11").
-- ============================================================

CREATE TABLE IF NOT EXISTS apl_indices (
  indice VARCHAR(12)   NOT NULL,        -- cdi | ipca | ibov | sp500 | ifix
  data   DATE          NOT NULL,        -- dia (cdi/bolsa) ou 1o dia do mes (ipca)
  valor  DECIMAL(16,8) NOT NULL,        -- ver tabela de unidades acima
  PRIMARY KEY (indice, data),
  INDEX idx_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
