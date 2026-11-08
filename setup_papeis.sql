-- ============================================================
--  FASE 2 — PAPEIS DE USUARIO + campos de perfil administrativo.
--  Rode UMA vez no phpMyAdmin (aba SQL) sobre o banco — junto da Fase 1
--  (setup_multitenancy.sql), quando o deploy acontecer.
--  No dev (dev_nitro) rode agora para testar.
--
--  O que faz:
--   1) adiciona apl_usuarios.papel (super_admin | administrador | editor | colaborador);
--   2) adiciona sobrenome / bio / ativo (campos do CRUD administrativo);
--   3) promove o USUARIO FUNDADOR (menor id) a super_admin.
--
--  Papeis sao definidos em CODIGO (apl-admin/_admin.php), nao em tabela —
--  esta coluna so guarda QUAL papel cada usuario tem.
-- ============================================================

ALTER TABLE apl_usuarios
  ADD COLUMN sobrenome VARCHAR(120) NULL AFTER nome,
  ADD COLUMN papel     VARCHAR(20)  NOT NULL DEFAULT 'colaborador' AFTER senha_hash,
  ADD COLUMN bio       TEXT NULL AFTER papel,
  ADD COLUMN ativo     TINYINT(1)   NOT NULL DEFAULT 1 AFTER bio;

-- Fundador (menor id) vira super_admin (dono original do sistema).
UPDATE apl_usuarios SET papel = 'super_admin' ORDER BY id ASC LIMIT 1;
