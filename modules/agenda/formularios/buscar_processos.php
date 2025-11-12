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
    
    // BUSCA SEM data_cadastro
    $sql = "SELECT 
                id, 
                numero_processo, 
                cliente_nome 
            FROM processos 
            WHERE ativo = 1 
            AND (
                numero_processo LIKE ? 
                OR cliente_nome LIKE ?
                OR REPLACE(REPLACE(numero_processo, '.', ''), '-', '') LIKE ?
            )
            ORDER BY id DESC
            LIMIT 50";
    
    $termo_busca = "%{$termo}%";
    $termo_limpo = str_replace(['.', '-', ' '], '', $termo);
    $params = [$termo_busca, $termo_busca, "%{$termo_limpo}%"];
    
    $stmt = executeQuery($sql, $params);
    $processos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($processos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}