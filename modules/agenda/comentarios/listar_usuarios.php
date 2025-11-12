<?php
/**
 * Listar usuários ativos para sistema de menções
 * VERSÃO FINAL CORRIGIDA - SEM COLUNA CARGO
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Verificar autenticação
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Não autenticado');
    }
    
    require_once '../../../config/database.php';
    
    // Buscar apenas usuários ativos (SEM COLUNA CARGO)
    $sql = "SELECT 
                id, 
                nome, 
                email
            FROM usuarios 
            WHERE ativo = 1 
            ORDER BY nome ASC";
    
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Remover o próprio usuário da lista (opcional)
    $usuario_logado_id = $_SESSION['usuario_id'];
    $usuarios = array_filter($usuarios, function($u) use ($usuario_logado_id) {
        return $u['id'] != $usuario_logado_id;
    });
    
    // Reindexar array
    $usuarios = array_values($usuarios);
    
    echo json_encode([
        'success' => true,
        'usuarios' => $usuarios,
        'total' => count($usuarios)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao listar usuários: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}