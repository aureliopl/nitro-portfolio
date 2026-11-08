<?php
// ============================================================
//  Conexao com o banco (PDO singleton).
//  As CREDENCIAIS ficam em config.local.php — arquivo FORA do git
//  (.gitignore). Modelo: config.local.example.php.
//  Em producao, crie config.local.php no servidor com os valores reais.
// ============================================================
require_once __DIR__ . '/config.local.php';

function getDB() {
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES   => false]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Banco indisponivel']));
        }
    }
    return $pdo;
}
