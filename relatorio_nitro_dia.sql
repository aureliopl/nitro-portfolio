-- ============================================================
--  NITRO — movimentações dia a dia (apl_posicoes)
--  Cole na aba SQL do phpMyAdmin e rode. Só leitura (SELECT).
--  Cada linha = uma movimentação: Abertura (data_inicio) ou
--  Encerramento (data_final). Ordenado por dia.
-- ============================================================
SELECT
  ev.dia,
  ELT(DAYOFWEEK(ev.dia),'Dom','Seg','Ter','Qua','Qui','Sex','Sáb') AS dia_semana,
  ev.tipo,
  ev.codigo,
  ev.estrutura,
  ev.pago,
  ev.recebido,
  ev.resultado_pct,
  ev.status
FROM (
  SELECT data_inicio AS dia, 'Abertura' AS tipo, codigo, estrutura,
         pago, NULL AS recebido, NULL AS resultado_pct, status
    FROM apl_posicoes
  UNION ALL
  SELECT data_final AS dia, 'Encerramento' AS tipo, codigo, estrutura,
         pago, recebido,
         ROUND((recebido - pago) / NULLIF(pago,0) * 100, 2) AS resultado_pct, status
    FROM apl_posicoes
   WHERE data_final IS NOT NULL
) ev
ORDER BY ev.dia, FIELD(ev.tipo,'Abertura','Encerramento'), ev.codigo;
