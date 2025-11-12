<?php
// modules/processos/api_busca.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

header('Content-Type: application/json');

$termo = $_GET['termo'] ?? '';

if (strlen($termo) < 3) {
    echo json_encode(['success' => false, 'message' => 'Digite ao menos 3 caracteres']);
    exit;
}

try {
    // Buscar por número do processo ou nome da parte
    $sql = "SELECT 
        p.id,
        p.numero_processo,
        c.nome as cliente_nome,
        p.parte_contraria,
        p.ativo
        FROM processos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        WHERE p.ativo = 1
        AND (
            p.numero_processo LIKE ? 
            OR c.nome LIKE ?
            OR p.parte_contraria LIKE ?
        )
        ORDER BY p.data_criacao DESC
        LIMIT 20";
    
    $search = "%{$termo}%";
    $stmt = executeQuery($sql, [$search, $search, $search]);
    $processos = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'processos' => $processos
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>