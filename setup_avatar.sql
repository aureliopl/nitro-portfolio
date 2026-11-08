-- ============================================================
--  Avatar personalizado (Fase 2). Adiciona a coluna `avatar` em
--  apl_usuarios — caminho relativo do arquivo enviado (ex.:
--  uploads/avatars/u5_a1b2c3d4.jpg) ou NULL (usa as iniciais).
--
--  MIGRACAO de banco existente: rodar UMA vez no phpMyAdmin.
--  Em fresh install NAO precisa (setup_usuarios.sql ja nasce com a coluna).
--  IF NOT EXISTS = idempotente (MariaDB 10.2+).
-- ============================================================
ALTER TABLE apl_usuarios
  ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) NULL AFTER bio;
