<?php
// api/notificacoes.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../includes/auth.php';

// Verificar autenticação
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$usuario_id = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Se for POST, pegar dados do JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if ($data) {
        $action = $data['action'] ?? $action;
    }
}

try {
    switch ($action) {
        case 'listar':
            $limit = $_GET['limit'] ?? 10;
            
            // Buscar notificações
            $sql = "SELECT * FROM notificacoes_sistema 
                    WHERE usuario_id = ? 
                    AND (expira_em IS NULL OR expira_em > NOW())
                    ORDER BY lida ASC, data_criacao DESC 
                    LIMIT ?";
            $stmt = executeQuery($sql, [$usuario_id, (int)$limit]);
            $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar não lidas
            $sql_count = "SELECT COUNT(*) as total FROM notificacoes_sistema 
                         WHERE usuario_id = ? 
                         AND lida = 0
                         AND (expira_em IS NULL OR expira_em > NOW())";
            $stmt_count = executeQuery($sql_count, [$usuario_id]);
            $nao_lidas = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            
            echo json_encode([
                'success' => true,
                'notificacoes' => $notificacoes,
                'nao_lidas' => (int)$nao_lidas
            ]);
            break;
            
        case 'marcar_lida':
            $notif_id = $data['id'] ?? $_POST['id'] ?? 0;
            
            if (!$notif_id) {
                throw new Exception('ID inválido');
            }
            
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW() 
                    WHERE id = ? AND usuario_id = ?";
            executeQuery($sql, [$notif_id, $usuario_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Notificação marcada como lida'
            ]);
            break;
            
        case 'marcar_todas_lidas':
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW() 
                    WHERE usuario_id = ? AND lida = 0";
            executeQuery($sql, [$usuario_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Todas as notificações marcadas como lidas'
            ]);
            break;
            
        case 'contar':
            $sql = "SELECT COUNT(*) as total FROM notificacoes_sistema 
                   WHERE usuario_id = ? 
                   AND lida = 0
                   AND (expira_em IS NULL OR expira_em > NOW())";
            $stmt = executeQuery($sql, [$usuario_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            echo json_encode([
                'success' => true,
                'total' => (int)$total
            ]);
            break;
            
        default:
            throw new Exception('Ação inválida');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>