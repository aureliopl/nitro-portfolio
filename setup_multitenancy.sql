-- ============================================================
--  FASE 1 — MULTI-TENANCY: adiciona user_id e escopa os dados por usuário.
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco de PRODUCAO.
--  (No dev/fresh-install os setup_*.sql ja nascem com user_id — este script
--   serve para MIGRAR um banco antigo que ja tem dados.)
--
--  O que faz, por tabela:
--   1) ADD COLUMN user_id (NULL temporariamente);
--   2) backfill: atribui TODAS as linhas existentes ao usuario de MENOR id
--      (o dono original da carteira mono-usuario);
--   3) torna user_id NOT NULL + cria indice.
--
--  Apos rodar, NENHUM dado fica sem dono. Novos usuarios comecam zerados.
--  Obs.: sem FOREIGN KEY por enquanto (o schema nao usa FK; o ON DELETE
--  CASCADE entra na Fase 2, junto com a exclusao administrativa de usuarios).
-- ============================================================

-- ── apl_carteira ─────────────────────────────────────────────────────────────
ALTER TABLE apl_carteira      ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_carteira      SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_carteira      MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);

-- ── apl_posicoes (Nitro) ─────────────────────────────────────────────────────
ALTER TABLE apl_posicoes      ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_posicoes      SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_posicoes      MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);

-- ── apl_notas (chave de dedup passa a incluir o usuario) ─────────────────────
ALTER TABLE apl_notas         ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_notas         SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_notas         MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);
ALTER TABLE apl_notas         DROP INDEX uniq_nota, ADD UNIQUE KEY uniq_nota (user_id, nr_nota, data_pregao);

-- ── apl_movimentacoes (Extrato) ──────────────────────────────────────────────
ALTER TABLE apl_movimentacoes ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_movimentacoes SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_movimentacoes MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);

-- ── apl_checkpoints (pontos de restauracao por usuario) ──────────────────────
ALTER TABLE apl_checkpoints   ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_checkpoints   SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_checkpoints   MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);

-- ── apl_lixeira (soft-delete por usuario) ────────────────────────────────────
ALTER TABLE apl_lixeira       ADD COLUMN user_id INT NULL AFTER id;
UPDATE apl_lixeira       SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_lixeira       MODIFY user_id INT NOT NULL, ADD INDEX idx_user (user_id);

-- ── apl_config_app (config por usuario: PK passa a ser composta) ─────────────
ALTER TABLE apl_config_app    ADD COLUMN user_id INT NULL AFTER chave;
UPDATE apl_config_app    SET user_id = (SELECT id FROM apl_usuarios ORDER BY id ASC LIMIT 1) WHERE user_id IS NULL;
ALTER TABLE apl_config_app    MODIFY user_id INT NOT NULL;
ALTER TABLE apl_config_app    DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, chave);
