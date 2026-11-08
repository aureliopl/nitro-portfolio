<?php
// ============================================================
//  Calendario de VENCIMENTO de opcoes da B3 (store global apl_vencimentos). (Fase 2)
//  Arquivo de LEITURA, sem efeitos colaterais (so define funcoes/constantes) —
//  pode ser incluido por qualquer pagina (app ou painel) sem conflito.
//
//  GLOBAL != por-usuario: o calendario da B3 vale para a plataforma inteira e
//  NAO tem user_id (mesma natureza do apl_config_geral). A tabela guarda APENAS
//  os AJUSTES manuais (ex.: feriado que antecipa o vencimento); todo mes sem
//  ajuste usa o padrao calculado (3a sexta-feira do mes).
//
//  REGRA B3: opcoes de acoes vencem na 3a SEXTA-FEIRA do mes de vencimento
//  (padrao desde 2018; se a sexta for feriado, antecipa para o pregao anterior —
//  por isso o ajuste manual existe). A LETRA da serie (5o caractere do codigo)
//  identifica o mes e o tipo, casando com detectarTipo() do app:
//    CALL: A=jan B=fev C=mar D=abr E=mai F=jun G=jul H=ago I=set J=out K=nov L=dez
//    PUT:  M=jan N=fev O=mar P=abr Q=mai R=jun S=jul T=ago U=set V=out W=nov X=dez
// ============================================================
require_once __DIR__ . '/config.php';

// Mes -> [nome, letra CALL, letra PUT]. Fonte da verdade do mapa de series.
const MESES_OPCOES = [
    1  => ['Janeiro',   'A', 'M'],
    2  => ['Fevereiro', 'B', 'N'],
    3  => ['Março',     'C', 'O'],
    4  => ['Abril',     'D', 'P'],
    5  => ['Maio',      'E', 'Q'],
    6  => ['Junho',     'F', 'R'],
    7  => ['Julho',     'G', 'S'],
    8  => ['Agosto',    'H', 'T'],
    9  => ['Setembro',  'I', 'U'],
    10 => ['Outubro',   'J', 'V'],
    11 => ['Novembro',  'K', 'W'],
    12 => ['Dezembro',  'L', 'X'],
];

// Padrao B3: 3a sexta-feira do mes. Retorna 'YYYY-MM-DD'.
function vencimento_padrao($ano, $mes) {
    $primeiro = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
    $dow      = (int)$primeiro->format('N');   // 1=seg .. 7=dom; sexta=5
    $ate1aSexta = (5 - $dow + 7) % 7;          // dias do dia 1 ate a 1a sexta
    $dia        = 1 + $ate1aSexta + 14;        // 3a sexta = 1a sexta + 2 semanas
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

// Le os AJUSTES manuais de um ano (mes => 'YYYY-MM-DD'). Cache estatico por ano.
function vencimentos_ajustes($ano) {
    static $cache = [];
    $ano = (int)$ano;
    if (isset($cache[$ano])) return $cache[$ano];
    $out = [];
    try {
        $st = getDB()->prepare("SELECT mes, data_venc FROM apl_vencimentos WHERE ano=?");
        $st->execute([$ano]);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['mes']] = $r['data_venc'];
        }
    } catch (Throwable $e) {
        // Tabela ainda nao criada / banco indisponivel: fica so nos padroes.
    }
    return $cache[$ano] = $out;
}

// Calendario completo de um ano: 12 linhas com padrao + ajuste + flags.
// Cada item: mes, nome, call, put, data_padrao, data (efetiva), ajustado (bool).
function vencimentos_ano($ano) {
    $ano     = (int)$ano;
    $ajustes = vencimentos_ajustes($ano);
    $linhas  = [];
    foreach (MESES_OPCOES as $mes => [$nome, $call, $put]) {
        $padrao   = vencimento_padrao($ano, $mes);
        $ajustado = isset($ajustes[$mes]) && $ajustes[$mes] !== $padrao;
        $linhas[] = [
            'mes'         => $mes,
            'nome'        => $nome,
            'call'        => $call,
            'put'         => $put,
            'data_padrao' => $padrao,
            'data'        => $ajustado ? $ajustes[$mes] : $padrao,
            'ajustado'    => $ajustado,
        ];
    }
    return $linhas;
}

// Vencimento efetivo de um mes (ajuste se houver, senao o padrao). 'YYYY-MM-DD'.
function vencimento_get($ano, $mes) {
    $ajustes = vencimentos_ajustes($ano);
    $padrao  = vencimento_padrao($ano, $mes);
    return $ajustes[(int)$mes] ?? $padrao;
}
