<?php
// ============================================================
//  Configuracoes GERAIS do sistema (store global apl_config_geral). (Fase 2)
//  Arquivo de LEITURA, sem efeitos colaterais (so define funcoes/constantes) —
//  pode ser incluido por qualquer pagina (app ou painel) sem conflito.
//
//  GLOBAL != por-usuario: este store vale para a plataforma inteira e NAO tem
//  user_id. O config POR usuario (senha do PDF, fonte de cotacao, aporte, etc.)
//  continua em apl_config_app, escopado por user_id no api.php do app.
//
//  Os DEFAULTS e as opcoes validas de formato moram aqui (codigo, nao banco) —
//  a UI (apl-admin/configuracoes.php) e a validacao (apl-admin/api.php) usam
//  as mesmas constantes, evitando divergencia.
//
//  Helpers de consumo (wiring): config_fuso() (fuso validado) e
//  fmt_data()/fmt_hora()/fmt_data_hora() (formatam respeitando formato_data/
//  formato_hora). Sao PUROS — aplicar o fuso (date_default_timezone_set) e'
//  responsabilidade do chamador (partials/head.php, api.php), nao deste arquivo.
// ============================================================
require_once __DIR__ . '/config.php';

// Chaves do store + seus defaults (fonte da verdade dos valores padrao).
const CONFIG_GERAL_DEFAULTS = [
    'site_titulo'    => 'Nitro',
    'site_descricao' => 'Acompanhamento de carteiras de opções da B3.',
    'site_icone'     => '',                    // URL do favicon/logo (upload e' sessao propria)
    'fuso_horario'   => 'America/Sao_Paulo',   // horario da B3
    'formato_data'   => 'd/m/Y',
    'formato_hora'   => 'H:i',                 // 24h
];

// Formatos oferecidos na UI (e aceitos na validacao). Numericos de proposito —
// formatos textuais (F = "June") dependeriam de locale/IntlDateFormatter.
const FORMATOS_DATA = ['d/m/Y', 'd/m/y', 'd-m-Y', 'Y-m-d', 'm/d/Y'];
const FORMATOS_HORA = ['H:i', 'H:i:s', 'h:i A'];

// Le TODAS as chaves (defaults sobrepostos pelo que houver no banco). Cache estatico.
function config_geral_all() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = CONFIG_GERAL_DEFAULTS;
    try {
        foreach (getDB()->query("SELECT chave, valor FROM apl_config_geral")->fetchAll() as $r) {
            if (array_key_exists($r['chave'], CONFIG_GERAL_DEFAULTS)) {
                $cache[$r['chave']] = $r['valor'];
            }
        }
    } catch (Throwable $e) {
        // Tabela ainda nao migrada / banco indisponivel: fica nos defaults.
    }
    return $cache;
}

// Le UMA chave. Cai no default da chave (ou em $fallback se a chave nao existir).
function config_geral_get($chave, $fallback = null) {
    $all = config_geral_all();
    if (array_key_exists($chave, $all)) return $all[$chave];
    return $fallback;
}

// ── Helpers de consumo (wiring no app) ───────────────────────────────────────
// Funcoes PURAS: leem a config e devolvem valor — NAO mexem em estado global.
// Quem aplica o fuso (date_default_timezone_set) e' o CHAMADOR (partials/head.php
// no app, api.php), nunca este arquivo (que segue "so leitura, sem efeitos").

// Fuso horario validado: o que estiver salvo, desde que seja um identificador
// real; senao cai no default (America/Sao_Paulo). Use como argumento de
// date_default_timezone_set() no inicio de cada entrada (paginas e API).
function config_fuso() {
    $tz = config_geral_get('fuso_horario', CONFIG_GERAL_DEFAULTS['fuso_horario']);
    return in_array($tz, timezone_identifiers_list(), true)
         ? $tz : CONFIG_GERAL_DEFAULTS['fuso_horario'];
}

// Normaliza "quando" -> timestamp. Aceita: null/'now'/'' (=agora), int (timestamp)
// ou string de data ('2026-06-20 15:18:00', 'Y-m-d', etc.). String invalida = agora.
function _config_ts($quando = 'now') {
    if ($quando === null || $quando === '' || $quando === 'now') return time();
    if (is_int($quando)) return $quando;
    $t = strtotime((string)$quando);
    return $t !== false ? $t : time();
}

// Formata DATA no formato configurado (formato_data), validado contra a whitelist.
function fmt_data($quando = 'now') {
    $f = config_geral_get('formato_data', CONFIG_GERAL_DEFAULTS['formato_data']);
    if (!in_array($f, FORMATOS_DATA, true)) $f = CONFIG_GERAL_DEFAULTS['formato_data'];
    return date($f, _config_ts($quando));
}

// Formata HORA no formato configurado (formato_hora), validado contra a whitelist.
function fmt_hora($quando = 'now') {
    $f = config_geral_get('formato_hora', CONFIG_GERAL_DEFAULTS['formato_hora']);
    if (!in_array($f, FORMATOS_HORA, true)) $f = CONFIG_GERAL_DEFAULTS['formato_hora'];
    return date($f, _config_ts($quando));
}

// Formata DATA + HORA ("formato_data formato_hora").
function fmt_data_hora($quando = 'now') {
    return fmt_data($quando) . ' ' . fmt_hora($quando);
}
