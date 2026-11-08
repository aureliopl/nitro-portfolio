-- ============================================================
--  CORREÇÃO ÚNICA — fluxo de caixa das 6 apl_notas já importadas
--  Rode UMA vez no phpMyAdmin (aba SQL), DEPOIS de setup_extrato.sql.
--
--  Por quê: essas apl_notas foram importadas antes da coluna fluxo_caixa
--  existir (ficaram NULL → o Extrato assumia "débito presumido"), e duas
--  tiveram o separador de milhar comido no parse do PDF (1.350,00 -> 1,35
--  e 2.209,60 -> 2,21). Aqui gravamos o caixa COM SINAL (= extrato da RICO)
--  e consertamos os valores furados. Não mexe na apl_carteira/posições.
--
--  Conferência: −2212,62 −967,84 −90,11 −74,52 +99,89 −28,37 = −3.273,57
--               saldo = 3.500,00 (aporte) − 3.273,57 = R$ 226,43  (igual à RICO)
--
--  Importações FUTURAS já calculam fluxo_caixa sozinhas; este script é só
--  para as apl_notas antigas.
-- ============================================================

UPDATE apl_notas SET fluxo_caixa = -2212.62, valor_operacoes = 2209.60, liquido = 2212.62 WHERE nr_nota = '137675049';
UPDATE apl_notas SET fluxo_caixa =  -967.84, valor_operacoes = 1350.00                     WHERE nr_nota = '137776800';
UPDATE apl_notas SET fluxo_caixa =   -90.11                                                WHERE nr_nota = '137871467';
UPDATE apl_notas SET fluxo_caixa =   -74.52                                                WHERE nr_nota = '137962781';
UPDATE apl_notas SET fluxo_caixa =    99.89                                                WHERE nr_nota = '138060288';
UPDATE apl_notas SET fluxo_caixa =   -28.37                                                WHERE nr_nota = '138149085';

-- Confira o resultado:
-- SELECT nr_nota, data_pregao, valor_operacoes, liquido, fluxo_caixa FROM apl_notas ORDER BY data_pregao;
