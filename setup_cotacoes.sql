-- ============================================================
--  FASE 4.1 — MEMORIA DE PRECOS (store GLOBAL).  apl_cotacoes
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco.
--  Idempotente (CREATE TABLE IF NOT EXISTS); pode rodar em qualquer cenario.
--
--  IMPORTANTE: este store e' GLOBAL — preco de fechamento e' DADO PUBLICO,
--  igual para todo mundo, entao NAO tem user_id (mesma natureza do
--  apl_config_geral e do apl_vencimentos). Guardar por usuario duplicaria
--  a mesma linha N vezes sem nenhum ganho.
--
--  O QUE ENTRA AQUI: so os codigos RELEVANTES — os que aparecem em
--  apl_carteira/apl_posicoes + os ETFs-proxy dos indices (BOVA11, IVVB11,
--  XFIX11). O COTAHIST inteiro seriam ~10 mil linhas POR DIA; filtrando,
--  sao ~60 codigos x ~43 pregoes = ~2.600 linhas no historico todo.
--
--  DIA SEM NEGOCIO: nao existe linha. A leitura aplica carry-forward do
--  ultimo fechamento conhecido (marcando o ponto como estimado) — ver
--  quotes_matrix() em series.php.
--
--  OPCAO VENCIDA: depois do vencimento o valor e' R$ 0,00 (nao o ultimo
--  preco negociado). Isso NAO e' gravado aqui: e' aplicado na leitura, via
--  vencimento_get() do vencimentos.php (1o consumo real do calendario da Fase 2).
-- ============================================================

CREATE TABLE IF NOT EXISTS apl_cotacoes (
  codigo     VARCHAR(20)   NOT NULL,            -- CODNEG da B3 (opcao, acao ou ETF)
  data       DATE          NOT NULL,            -- data do pregao
  fechamento DECIMAL(12,4) NOT NULL,            -- PREULT do COTAHIST (ultimo preco do dia)
  origem     ENUM('b3','manual','estimado') NOT NULL DEFAULT 'b3',
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (codigo, data),
  INDEX idx_data (data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
