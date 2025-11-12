<?php
/**
 * AJAX - Salvar Cliente Rápido
 * Processa cadastro via modal (não popup)
 */

require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

header('Content-Type: application/json');

try {
    // Validar dados obrigatórios
    $tipo_pessoa = $_POST['tipo_pessoa'] ?? '';
    $nome = trim($_POST['nome'] ?? '');
    $cpf_cnpj = preg_replace('/[^0-9]/', '', $_POST['cpf_cnpj'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    
    // Validações
    if (empty($tipo_pessoa) || !in_array($tipo_pessoa, ['F', 'J'])) {
        throw new Exception('Tipo de pessoa inválido');
    }
    
    if (empty($nome)) {
        throw new Exception('Nome é obrigatório');
    }
    
    if (empty($cpf_cnpj)) {
        throw new Exception('CPF/CNPJ é obrigatório');
    }
    
    // Validar CPF/CNPJ
    if ($tipo_pessoa === 'F' && strlen($cpf_cnpj) != 11) {
        throw new Exception('CPF inválido (deve ter 11 dígitos)');
    }
    
    if ($tipo_pessoa === 'J' && strlen($cpf_cnpj) != 14) {
        throw new Exception('CNPJ inválido (deve ter 14 dígitos)');
    }
    
    // Verificar se CPF/CNPJ já existe
    $sql = "SELECT id, nome FROM clientes WHERE cpf_cnpj = ?";
    $stmt = executeQuery($sql, [$cpf_cnpj]);
    $existe = $stmt->fetch();
    
    if ($existe) {
        // Retornar cliente existente
        echo json_encode([
            'success' => true,
            'cliente_id' => $existe['id'],
            'cliente_nome' => $existe['nome'],
            'cliente_doc' => $cpf_cnpj,
            'message' => 'Cliente já existe e foi selecionado'
        ]);
        exit;
    }
    
    // Inserir novo cliente
    $sql = "INSERT INTO clientes (
                tipo_pessoa,
                nome,
                cpf_cnpj,
                email,
                telefone,
                criado_em
            ) VALUES (?, ?, ?, ?, ?, NOW())";
    
    $params = [
        $tipo_pessoa,
        $nome,
        $cpf_cnpj,
        $email ?: null,
        $telefone ?: null
    ];
    
    executeQuery($sql, $params);
    
    // Pegar ID do cliente criado
    $conn = getConnection();
    $cliente_id = $conn->lastInsertId();
    
    // Log da ação
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, descricao, ip, user_agent) 
                VALUES (?, 'cliente_criado', ?, ?, ?)";
    
    $descricao = "Cliente cadastrado: {$nome} (ID: {$cliente_id})";
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        executeQuery($sql_log, [$usuario_id, $descricao, $ip, $user_agent]);
    } catch (Exception $e) {
        // Ignorar erro de log
    }
    
    // Retornar sucesso
    echo json_encode([
        'success' => true,
        'cliente_id' => $cliente_id,
        'cliente_nome' => $nome,
        'cliente_doc' => $cpf_cnpj,
        'message' => 'Cliente cadastrado com sucesso!'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>