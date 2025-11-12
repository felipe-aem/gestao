<?php
date_default_timezone_set('America/Sao_Paulo');
// ========== CONFIGURAÇÃO DE SESSÕES ==========
// Criar diretório para sessões se não existir
$session_path = dirname(__DIR__) . '/logs/sessions';
if (!is_dir($session_path)) {
    mkdir($session_path, 0700, true);
}

// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'alenc1282555_gestao');
define('DB_USER', 'alenc1282555_gestao_alencarmartinazzo');
define('DB_PASS', '3iN35eh2#'); 
define('DB_CHARSET', 'utf8mb4');

// Configurações do sistema
define('SITE_URL', 'https://gestao.alencarmartinazzo.adv.br');
define('SITE_NAME', 'SIGAM');
define('SESSION_TIMEOUT', 3600); // 1 hora

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Função de conexão (MODIFICADA PARA RETORNAR UMA ÚNICA INSTÂNCIA)
function getConnection() {
    // A variável $pdo é estática, o que significa que seu valor é persistente
    // entre chamadas da função, mas visível apenas dentro dela.
    static $pdo = null; 
    
    // Se a conexão ainda não foi estabelecida, criamos uma nova
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Em caso de erro na conexão, loga o erro e interrompe o script
            error_log("Erro na conexão com o banco de dados: " . $e->getMessage());
            die("Erro na conexão com o banco de dados. Por favor, tente novamente mais tarde.");
        }
    }
    // Retorna a instância da conexão (seja a recém-criada ou a já existente)
    return $pdo; 
}

// Função para executar queries (AGORA USANDO A CONEXÃO PERSISTENTE OBTIDA VIA getConnection())
function executeQuery($sql, $params = []) {
    // Pega a conexão (que agora é sempre a mesma instância)
    $conn = getConnection(); 
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Função para debug (remover em produção)
function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die();
}

setlocale(LC_TIME, 'pt_BR.utf8', 'pt_BR', 'portuguese');
?>