<?php
// Debug para verificar conexão PDO
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug - Conexão PDO</h1>";

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

echo "<h2>1. Variáveis Globais</h2>";
echo "<pre>";
echo "GLOBALS['pdo']: ";
var_dump(isset($GLOBALS['pdo']) ? 'EXISTS' : 'NOT EXISTS');
if (isset($GLOBALS['pdo'])) {
    var_dump($GLOBALS['pdo']);
}

echo "\n\$pdo (variável local): ";
var_dump(isset($pdo) ? 'EXISTS' : 'NOT EXISTS');
if (isset($pdo)) {
    var_dump($pdo);
}

echo "\n\$db (variável local): ";
var_dump(isset($db) ? 'EXISTS' : 'NOT EXISTS');
if (isset($db)) {
    var_dump($db);
}

echo "\n\$conn (variável local): ";
var_dump(isset($conn) ? 'EXISTS' : 'NOT EXISTS');
if (isset($conn)) {
    var_dump($conn);
}
echo "</pre>";

echo "<h2>2. Testar executeQuery</h2>";
try {
    $sql = "SELECT 1 as teste";
    $result = executeQuery($sql);
    echo "✅ executeQuery funcionou<br>";
    
    // Tentar pegar o objeto PDO da própria função
    echo "<h3>Tentando obter PDO após executeQuery:</h3>";
    echo "<pre>";
    
    // Verificar o resultado
    var_dump($result);
    
    // Tentar obter connection do statement
    if (method_exists($result, 'getIterator')) {
        echo "\n\$result tem getIterator\n";
    }
    
    // Listar todas as variáveis definidas
    echo "\n\nTodas as variáveis definidas:\n";
    $all_vars = get_defined_vars();
    foreach ($all_vars as $name => $value) {
        if ($value instanceof PDO || $value instanceof PDOStatement) {
            echo "$name => " . get_class($value) . "\n";
        }
    }
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<h2>3. Testar INSERT e LastInsertId</h2>";
try {
    // Criar tabela temporária
    $sql = "CREATE TEMPORARY TABLE teste_insert (
        id INT PRIMARY KEY AUTO_INCREMENT,
        valor VARCHAR(50)
    )";
    executeQuery($sql);
    echo "✅ Tabela temporária criada<br>";
    
    // Inserir registro
    $sql = "INSERT INTO teste_insert (valor) VALUES ('teste')";
    $stmt = executeQuery($sql);
    echo "✅ Registro inserido<br>";
    
    // Tentar pegar lastInsertId de diferentes formas
    echo "<h3>Tentando obter lastInsertId:</h3>";
    echo "<pre>";
    
    // Método 1: Via GLOBALS
    if (isset($GLOBALS['pdo'])) {
        try {
            $id1 = $GLOBALS['pdo']->lastInsertId();
            echo "Método 1 (GLOBALS['pdo']): $id1\n";
        } catch (Exception $e) {
            echo "Método 1 falhou: " . $e->getMessage() . "\n";
        }
    }
    
    // Método 2: Via statement
    if ($stmt) {
        try {
            // Alguns drivers permitem pegar do statement
            echo "Statement class: " . get_class($stmt) . "\n";
        } catch (Exception $e) {
            echo "Método 2 falhou: " . $e->getMessage() . "\n";
        }
    }
    
    // Método 3: Query direta
    try {
        $result = executeQuery("SELECT LAST_INSERT_ID() as last_id");
        $row = $result->fetch();
        echo "Método 3 (SELECT LAST_INSERT_ID()): " . $row['last_id'] . "\n";
    } catch (Exception $e) {
        echo "Método 3 falhou: " . $e->getMessage() . "\n";
    }
    
    // Método 4: Via MAX(id)
    try {
        $result = executeQuery("SELECT MAX(id) as last_id FROM teste_insert");
        $row = $result->fetch();
        echo "Método 4 (SELECT MAX(id)): " . $row['last_id'] . "\n";
    } catch (Exception $e) {
        echo "Método 4 falhou: " . $e->getMessage() . "\n";
    }
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "❌ Erro no teste: " . $e->getMessage();
}

echo "<h2>4. Verificar Arquivo database.php</h2>";
$database_file = '../../../config/database.php';
if (file_exists($database_file)) {
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 400px; overflow-y: auto;'>";
    echo htmlspecialchars(file_get_contents($database_file));
    echo "</pre>";
} else {
    echo "❌ Arquivo não encontrado: $database_file";
}