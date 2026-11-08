-- ============================================================
--  Extrato da conta (espelho da corretora) — SIMULADO
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco u152994482_carteira.
--
--  O sistema NÃO tem ligação com a corretora. Estas tabelas servem
--  para você espelhar manualmente o caixa: aporte inicial, depósitos,
--  saques e o efeito de caixa das apl_notas de corretagem importadas.
--
--  Saldo disponível = aporte_inicial (config)
--                   + Σ aportes − Σ saques            (tabela apl_movimentacoes)
--                   + Σ fluxo_caixa das apl_notas         (tabela apl_notas)
--
--  fluxo_caixa: efeito de caixa COM SINAL de cada nota.
--    + (crédito)  = a nota foi liquidada a seu favor (vendas > compras)
--    − (débito)   = a nota custou caixa (compras > vendas + taxas)
-- ============================================================

-- Depósitos (aportes) e saques manuais — o "Realizar Transferência" simulado.
CREATE TABLE IF NOT EXISTS apl_movimentacoes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT           NOT NULL,            -- dono da movimentacao (multi-tenancy, Fase 1)
  carteira_id INT          NOT NULL,            -- carteira a que a movimentacao pertence (Fase 3)
  tipo       ENUM('aporte','saque') NOT NULL,
  data       DATE          NOT NULL,
  descricao  VARCHAR(255)  NULL,
  valor      DECIMAL(12,2) NOT NULL,            -- sempre positivo; o sinal vem do tipo
  criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_user_cart (user_id, carteira_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Efeito de caixa (com sinal) de cada nota de corretagem.
-- NULL nas apl_notas antigas → o Extrato assume débito (−liquido) e deixa você ajustar inline.
ALTER TABLE apl_notas ADD COLUMN fluxo_caixa DECIMAL(12,2) NULL AFTER liquido;

-- Aporte inicial (Fase 3): mora na linha da carteira (apl_carteiras.aporte_inicial
--   / aporte_inicial_data), nao mais no apl_config_app. Cada carteira tem o seu.
--   Preencha pela tela de Configuração → Aporte inicial (grava na carteira ativa).
