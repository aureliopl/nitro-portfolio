-- ============================================================
--  FASE 2 — CALENDARIO DE VENCIMENTO DE OPCOES (store GLOBAL).
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco — junto da Fase 1/2,
--  quando o deploy acontecer. No dev (dev_nitro) rode agora para testar.
--  Idempotente (CREATE TABLE IF NOT EXISTS); pode rodar em qualquer cenario.
--
--  IMPORTANTE: este store e' GLOBAL (o calendario da B3 vale para a plataforma
--  inteira) — NAO tem user_id, igual ao apl_config_geral.
--
--  A tabela guarda APENAS os AJUSTES manuais do vencimento (ex.: feriado que
--  antecipa a data). Todo mes SEM linha aqui usa o padrao calculado em codigo
--  (3a sexta-feira do mes — ver vencimento_padrao() em vencimentos.php).
-- ============================================================

CREATE TABLE IF NOT EXISTS apl_vencimentos (
  ano           SMALLINT  NOT NULL,
  mes           TINYINT   NOT NULL,            -- 1..12
  data_venc     DATE      NOT NULL,            -- vencimento ajustado para (ano, mes)
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ano, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
