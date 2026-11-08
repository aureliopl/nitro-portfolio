<?php
// ============================================================
//  Papeis de usuario + helpers de sessao/capacidade. (Fase 2)
//  Arquivo SEM efeitos colaterais (so define funcoes/constantes) —
//  pode ser incluido por auth.php (login do app), apl-login.php (login
//  do admin) e apl-admin/_admin.php (painel) sem conflito.
// ============================================================

// Papeis e seus niveis (estilo WordPress: nivel maior = mais poder).
// Os niveis sao a fonte da hierarquia; a coluna apl_usuarios.papel guarda a chave.
const PAPEIS = [
    'super_admin'   => ['nivel' => 100, 'rotulo' => 'Super administrador'],
    'administrador' => ['nivel' => 80,  'rotulo' => 'Administrador'],
    'editor'        => ['nivel' => 40,  'rotulo' => 'Editor'],
    'colaborador'   => ['nivel' => 10,  'rotulo' => 'Assinante'],   // chave 'colaborador' (sem migracao); rotulo exibido = Assinante
];
const NIVEL_ADMIN = 80;   // nivel minimo para acessar o painel /apl-admin/

function papel_valido($papel) { return isset(PAPEIS[$papel]); }
function papel_nivel($papel)  { return PAPEIS[$papel]['nivel']  ?? 0; }
function papel_rotulo($papel) { return PAPEIS[$papel]['rotulo'] ?? $papel; }

// Tem acesso ao painel administrativo?
function pode_admin($papel) { return papel_nivel($papel) >= NIVEL_ADMIN; }

// Pode ver a Carteira Nitro? (Fase 3) — a Nitro virou pessoal do dono do sistema;
// so o super_admin a enxerga (nav, /nitro, secao do ranking e API ?tabela=posicoes).
function pode_ver_nitro($papel) { return $papel === 'super_admin'; }

// Pode ver a Carteira Betha? — carteira de simulacao pessoal do dono, mesma regra
// da Nitro: so o super_admin a enxerga (nav, /betha, secao do ranking e API ?tabela=betha).
function pode_ver_betha($papel) { return $papel === 'super_admin'; }

// O ator pode GERIR (editar/excluir) um alvo com papel $alvoPapel?
//  - precisa ser admin;
//  - nao mexe em quem tem papel de nivel MAIOR que o seu;
//  - so super_admin mexe em super_admin.
function pode_gerir($atorPapel, $alvoPapel) {
    if (!pode_admin($atorPapel)) return false;
    if ($alvoPapel === 'super_admin') return $atorPapel === 'super_admin';
    return papel_nivel($atorPapel) >= papel_nivel($alvoPapel);
}

// O ator pode ATRIBUIR o papel $novoPapel (ao criar/editar um usuario)?
//  - nao concede papel acima do seu nivel; so super_admin concede super_admin.
function pode_atribuir($atorPapel, $novoPapel) {
    if (!papel_valido($novoPapel)) return false;
    if (!pode_admin($atorPapel))   return false;
    if ($novoPapel === 'super_admin') return $atorPapel === 'super_admin';
    return papel_nivel($atorPapel) >= papel_nivel($novoPapel);
}

// Inicia a sessao a partir de uma linha de apl_usuarios (login do app E do admin
// usam isto — evita duplicar logica e garante que o PAPEL entra na sessao).
// Guarda tambem o avatar (caminho relativo ou null) p/ a nav renderizar sem
// re-consultar o banco; quem troca o avatar atualiza $_SESSION['user_avatar'].
function iniciar_sessao(array $u) {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['auth_version'] = hash('sha256', $u['senha_hash']);
    $_SESSION['user_id']      = (int)$u['id'];
    $_SESSION['user_nome']    = $u['nome'];
    $_SESSION['user_email']   = $u['email'];
    $_SESSION['user_usuario'] = $u['usuario'];
    $_SESSION['user_papel']   = $u['papel'] ?? 'colaborador';
    $_SESSION['user_avatar']  = $u['avatar'] ?? null;
}
