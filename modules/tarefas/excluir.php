<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$tarefa_id = $_GET['id'] ?? 0;

if (!$tarefa_id) {
    header('Location: ../agenda/?erro=ID inválido');
    exit;
}

try {
    // Verificar se existe
    $sql = "SELECT id FROM tarefas WHERE id = ?";
    $stmt = executeQuery($sql, [$tarefa_id]);
    
    if (!$stmt->fetch()) {
        header('Location: ../agenda/?erro=Tarefa não encontrada');
        exit;
    }
    
    // Excluir
    $sql = "DELETE FROM tarefas WHERE id = ?";
    executeQuery($sql, [$tarefa_id]);
    
    header('Location: ../agenda/?success=Tarefa excluída com sucesso');
    exit;
    
} catch (Exception $e) {
    header('Location: ../agenda/?erro=' . urlencode($e->getMessage()));
    exit;
}
?>