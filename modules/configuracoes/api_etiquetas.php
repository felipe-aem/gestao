<?php
// modules/configuracoes/api_etiquetas.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não mostrar erros na tela
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once '../../includes/auth.php';
    Auth::protect();
    require_once '../../config/database.php';
    
    $usuario_logado = Auth::user();
    $usuario_id = $usuario_logado['usuario_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido: ' . json_last_error_msg());
    }
    
    $action = $input['action'] ?? '';
    
    if ($action === 'criar') {
        $nome = trim($input['nome'] ?? '');
        $cor = $input['cor'] ?? '#667eea';
        $tipo = $input['tipo'] ?? 'geral';
        $descricao = trim($input['descricao'] ?? '');
        
        if (empty($nome)) {
            throw new Exception('Nome da etiqueta é obrigatório');
        }
        
        // Verificar se já existe
        $sql_check = "SELECT id FROM etiquetas WHERE nome = ? AND ativo = 1";
        $stmt_check = executeQuery($sql_check, [$nome]);
        if ($stmt_check->fetch()) {
            throw new Exception('Já existe uma etiqueta com este nome');
        }
        
        // Inserir
        $sql = "INSERT INTO etiquetas (nome, cor, tipo, descricao, criado_por, ativo, data_criacao) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())";
        executeQuery($sql, [$nome, $cor, $tipo, $descricao, $usuario_id]);
        
        $pdo = getConnection();
        $id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'etiqueta' => [
                'id' => $id,
                'nome' => $nome,
                'cor' => $cor,
                'tipo' => $tipo,
                'descricao' => $descricao
            ]
        ]);
        exit;
        
    } else {
        throw new Exception('Ação não reconhecida: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}
?>