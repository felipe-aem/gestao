<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once '../../../includes/auth.php';
    Auth::protect();
    require_once '../../../config/database.php';
    
    $termo = $_GET['termo'] ?? '';
    
    if (strlen($termo) < 2) {
        echo json_encode([]);
        exit;
    }
    
    $sql = "SELECT 
                id, 
                nome,
                cpf_cnpj,
                email
            FROM clientes 
            WHERE ativo = 1 
            AND (
                nome LIKE ? 
                OR cpf_cnpj LIKE ?
                OR email LIKE ?
            )
            ORDER BY nome
            LIMIT 50";
    
    $termo_busca = "%{$termo}%";
    $params = [$termo_busca, $termo_busca, $termo_busca];
    
    $stmt = executeQuery($sql, $params);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
