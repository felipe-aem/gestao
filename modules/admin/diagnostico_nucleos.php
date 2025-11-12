<?php
require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

// Verificar se o usuário é administrador
$usuario_logado = Auth::user();
$niveis_admin = ['Admin', 'Administrador', 'Socio', 'Diretor'];
if (!in_array($usuario_logado['nivel_acesso'], $niveis_admin)) {
    die('Acesso negado');
}

echo "<h2>Diagnóstico do Sistema de Núcleos</h2>";

try {
    // 1. Verificar se tabela usuario_nucleos existe
    echo "<h3>1. Verificando tabela usuario_nucleos:</h3>";
    $sql_check_table = "SHOW TABLES LIKE 'usuario_nucleos'";
    $stmt_check_table = executeQuery($sql_check_table);
    
    if ($stmt_check_table && $stmt_check_table->fetch()) {
        echo "✅ Tabela usuario_nucleos existe<br>";
        
        // Mostrar estrutura da tabela
        echo "<h4>Estrutura da tabela:</h4>";
        $sql_describe = "DESCRIBE usuario_nucleos";
        $stmt_describe = executeQuery($sql_describe);
        $colunas = $stmt_describe->fetchAll();
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th></tr>";
        foreach ($colunas as $coluna) {
            echo "<tr>";
            echo "<td>{$coluna['Field']}</td>";
            echo "<td>{$coluna['Type']}</td>";
            echo "<td>{$coluna['Null']}</td>";
            echo "<td>{$coluna['Key']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Contar registros
        $sql_count = "SELECT COUNT(*) as total FROM usuario_nucleos";
        $stmt_count = executeQuery($sql_count);
        $total = $stmt_count->fetch()['total'];
        echo "Total de vínculos: $total<br>";
        
    } else {
        echo "❌ Tabela usuario_nucleos NÃO existe<br>";
        echo "<strong>SOLUÇÃO:</strong> Execute o SQL para criar a tabela.<br>";
    }
    
    // 2. Verificar tabela nucleos
    echo "<h3>2. Verificando tabela nucleos:</h3>";
    $sql_check_nucleos = "SHOW TABLES LIKE 'nucleos'";
    $stmt_check_nucleos = executeQuery($sql_check_nucleos);
    
    if ($stmt_check_nucleos && $stmt_check_nucleos->fetch()) {
        echo "✅ Tabela nucleos existe<br>";
        
        $sql_nucleos = "SELECT * FROM nucleos WHERE ativo = 1";
        $stmt_nucleos = executeQuery($sql_nucleos);
        $nucleos = $stmt_nucleos->fetchAll();
        
        echo "Núcleos ativos: " . count($nucleos) . "<br>";
        foreach ($nucleos as $nucleo) {
            echo "- ID: {$nucleo['id']} | Nome: {$nucleo['nome']}<br>";
        }
    } else {
        echo "❌ Tabela nucleos NÃO existe<br>";
    }
    
    // 3. Verificar usuário específico (substitua o ID)
    $usuario_teste_id = 2; // ALTERE PARA O ID DO USUÁRIO DIRETOR
    echo "<h3>3. Verificando usuário ID $usuario_teste_id:</h3>";
    
    $sql_usuario = "SELECT * FROM usuarios WHERE id = ?";
    $stmt_usuario = executeQuery($sql_usuario, [$usuario_teste_id]);
    $usuario_teste = $stmt_usuario->fetch();
    
    if ($usuario_teste) {
        echo "✅ Usuário encontrado: {$usuario_teste['nome']} ({$usuario_teste['nivel_acesso']})<br>";
        echo "Núcleo principal: {$usuario_teste['nucleo_id']}<br>";
        
        // Verificar vínculos na tabela usuario_nucleos
        if ($stmt_check_table && $stmt_check_table->fetch()) {
            $sql_vinculos = "SELECT un.*, n.nome as nucleo_nome 
                           FROM usuario_nucleos un 
                           LEFT JOIN nucleos n ON un.nucleo_id = n.id 
                           WHERE un.usuario_id = ?";
            $stmt_vinculos = executeQuery($sql_vinculos, [$usuario_teste_id]);
            $vinculos = $stmt_vinculos->fetchAll();
            
            echo "Vínculos encontrados: " . count($vinculos) . "<br>";
            foreach ($vinculos as $vinculo) {
                echo "- Núcleo: {$vinculo['nucleo_nome']} | Ativo: " . ($vinculo['ativo'] ? 'Sim' : 'Não') . "<br>";
            }
        }
    } else {
        echo "❌ Usuário não encontrado<br>";
    }
    
    // 4. Testar inserção manual
    echo "<h3>4. Teste de inserção (se necessário):</h3>";
    echo "Para testar manualmente, execute:<br>";
    echo "<code>INSERT INTO usuario_nucleos (usuario_id, nucleo_id, ativo) VALUES ($usuario_teste_id, 1, 1);</code><br>";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}
?>