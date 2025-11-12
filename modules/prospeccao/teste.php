<?php
// Script de teste para verificar histórico
require_once '../../config/database.php';

echo "<h2>Teste de Histórico</h2>";

// Testar conexão
try {
    $pdo = getConnection();
    echo "✅ Conexão OK<br>";
} catch (Exception $e) {
    echo "❌ Erro conexão: " . $e->getMessage() . "<br>";
    exit;
}

// Verificar se tabela existe
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'prospeccoes_historico'")->fetchAll();
    if (empty($tables)) {
        echo "❌ Tabela 'prospeccoes_historico' NÃO EXISTE<br>";
        echo "<br><strong>A tabela precisa ser criada!</strong><br>";
        echo "<pre>";
        echo "CREATE TABLE IF NOT EXISTS prospeccoes_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospeccao_id INT NOT NULL,
    fase_anterior VARCHAR(50),
    fase_nova VARCHAR(50),
    valor_informado DECIMAL(10,2),
    observacao TEXT,
    usuario_id INT,
    data_movimento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prospeccao_id) REFERENCES prospeccoes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);";
        echo "</pre>";
        exit;
    } else {
        echo "✅ Tabela 'prospeccoes_historico' existe<br>";
    }
} catch (Exception $e) {
    echo "❌ Erro ao verificar tabela: " . $e->getMessage() . "<br>";
}

// Verificar estrutura
try {
    $columns = $pdo->query("DESCRIBE prospeccoes_historico")->fetchAll();
    echo "<br><strong>Estrutura da tabela:</strong><br>";
    echo "<pre>";
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "\n";
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "❌ Erro ao descrever tabela: " . $e->getMessage() . "<br>";
}

// Contar registros
try {
    $count = $pdo->query("SELECT COUNT(*) as total FROM prospeccoes_historico")->fetch();
    echo "<br>📊 Total de registros: <strong>" . $count['total'] . "</strong><br>";
    
    if ($count['total'] > 0) {
        echo "<br><strong>Últimos 5 registros:</strong><br>";
        $historico = $pdo->query("SELECT * FROM prospeccoes_historico ORDER BY id DESC LIMIT 5")->fetchAll();
        echo "<pre>";
        print_r($historico);
        echo "</pre>";
    } else {
        echo "<br>⚠️ A tabela está VAZIA. Histórico não está sendo gravado.<br>";
    }
} catch (Exception $e) {
    echo "❌ Erro ao contar: " . $e->getMessage() . "<br>";
}
?>
