<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    
    // Validar campos obrigatórios
    if (empty($_POST['nome'])) {
        throw new Exception('O nome da etiqueta é obrigatório');
    }
    
    // Sanitizar dados
    $nome = trim($_POST['nome']);
    $cor = $_POST['cor'] ?? '#667eea';
    $tipo = $_POST['tipo'] ?? 'tarefa';
    
    // Validar cor (hexadecimal)
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
        $cor = '#667eea';
    }
    
    // Validar tipo
    $tipos_validos = ['geral', 'processo', 'tarefa', 'prazo', 'audiencia'];
    if (!in_array($tipo, $tipos_validos)) {
        $tipo = 'tarefa';
    }
    
    // Inserir etiqueta
    $sql = "INSERT INTO etiquetas (nome, cor, tipo, ativo, criado_por) 
            VALUES (?, ?, ?, 1, ?)";
    
    $stmt = executeQuery($sql, [$nome, $cor, $tipo, $usuario_logado['usuario_id']]);
    $etiqueta_id = getConnection()->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Etiqueta criada com sucesso!',
        'etiqueta_id' => $etiqueta_id,
        'nome' => $nome,
        'cor' => $cor
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao criar etiqueta: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}