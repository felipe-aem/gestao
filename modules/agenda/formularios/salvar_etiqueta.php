<?php
require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

header('Content-Type: application/json');

$usuario_id = $_SESSION['usuario_id'];
$nome = $_POST['nome'];
$cor = $_POST['cor'] ?? '#667eea';
$tipo = $_POST['tipo'] ?? 'tarefa';

try {
    $sql = "INSERT INTO etiquetas (nome, cor, tipo, criado_por, ativo) VALUES (?, ?, ?, ?, 1)";
    $stmt = executeQuery($sql, [$nome, $cor, $tipo, $usuario_id]);
    
    // Buscar o ID que acabou de ser inserido
    $sql_id = "SELECT id FROM etiquetas WHERE nome = ? AND criado_por = ? ORDER BY id DESC LIMIT 1";
    $result = executeQuery($sql_id, [$nome, $usuario_id]);
    $etiqueta = $result->fetch(PDO::FETCH_ASSOC);
    $id = $etiqueta['id'];
    
    echo json_encode(['success' => true, 'etiqueta_id' => $id, 'nome' => $nome, 'cor' => $cor]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;