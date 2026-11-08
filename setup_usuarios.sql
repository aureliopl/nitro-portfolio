-- ============================================================
--  Usuários do sistema (login por usuário OU e-mail + senha).
--  Para FRESH INSTALL. (Banco já existente usou setup_prefixo_apl.sql,
--  que já cria esta tabela junto com o RENAME.)
--  A tabela nasce VAZIA → no 1º acesso o sistema mostra "Criar acesso".
-- ============================================================
CREATE TABLE IF NOT EXISTS apl_usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  sobrenome     VARCHAR(120) NULL,                -- Fase 2 (CRUD administrativo)
  usuario       VARCHAR(40)  NOT NULL UNIQUE,
  email         VARCHAR(160) NOT NULL UNIQUE,
  senha_hash    VARCHAR(255) NOT NULL,            -- password_hash() (bcrypt)
  papel         VARCHAR(20)  NOT NULL DEFAULT 'colaborador', -- super_admin|administrador|editor|colaborador
  bio           TEXT NULL,
  avatar        VARCHAR(255) NULL,                -- Fase 2: caminho do avatar enviado (uploads/avatars/...) ou NULL = iniciais
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = login bloqueado
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Obs.: o 1o usuario criado pela tela "Criar acesso" nasce 'colaborador'; promova-o
-- a super_admin (UPDATE ... ORDER BY id LIMIT 1) ou via setup_papeis.sql num banco migrado.
