<?php
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se o usuário é administrador
$usuario_logado = Auth::user();
$niveis_admin = ['Admin', 'Socio'];
if (!in_array($usuario_logado['nivel_acesso'], $niveis_admin)) {
    header('Location: ' . SITE_URL . '/modules/dashboard/?erro=Acesso negado');
    exit;
}

require_once '../../config/database.php';

header('Content-Type: application/json');

try {
    // Estatísticas em tempo real
    $stats = [];
    
    // Total de usuários
    $sql = "SELECT COUNT(*) as total FROM usuarios";
    $stmt = executeQuery($sql);
    $stats['usuarios'] = $stmt->fetch()['total'];
    
    // Usuários ativos
    $sql = "SELECT COUNT(*) as total FROM usuarios WHERE ativo = 1";
    $stmt = executeQuery($sql);
    $stats['usuarios_ativos'] = $stmt->fetch()['total'];
    
    // Total de clientes
    $sql = "SELECT COUNT(*) as total FROM clientes";
    $stmt = executeQuery($sql);
    $stats['clientes'] = $stmt->fetch()['total'];
    
    // Eventos futuros
    $sql = "SELECT COUNT(*) as total FROM agenda WHERE data_inicio >= NOW()";
    $stmt = executeQuery($sql);
    $stats['eventos_futuros'] = $stmt->fetch()['total'];
    
    // Logs hoje
    $sql = "SELECT COUNT(*) as total FROM logs_sistema WHERE DATE(data_acao) = CURDATE()";
    $stmt = executeQuery($sql);
    $stats['logs_hoje'] = $stmt->fetch()['total'];
    
    // Usuários online (últimos 15 minutos)
    $sql = "SELECT COUNT(DISTINCT usuario_id) as total FROM logs_sistema 
            WHERE data_acao >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
    $stmt = executeQuery($sql);
    $stats['usuarios_online'] = $stmt->fetch()['total'];
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>