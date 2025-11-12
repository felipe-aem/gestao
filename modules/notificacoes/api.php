<?php
// modules/notificacoes/api.php
header('Content-Type: application/json');

require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/notificacoes_helper.php';

$usuario_id = $_SESSION['usuario_id'] ?? 0;
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

try {
    switch ($acao) {
        case 'buscar':
            $notificacoes = Notificacoes::buscar($usuario_id, false, 20);
            $nao_lidas = Notificacoes::contarNaoLidas($usuario_id);
            
            echo json_encode([
                'success' => true,
                'notificacoes' => $notificacoes,
                'total_nao_lidas' => $nao_lidas
            ]);
            break;
            
        case 'contar':
            $total = Notificacoes::contarNaoLidas($usuario_id);
            
            echo json_encode([
                'success' => true,
                'total' => $total
            ]);
            break;
            
        case 'marcar_lida':
            $notificacao_id = $_POST['id'] ?? 0;
            
            if (!$notificacao_id) {
                throw new Exception('ID inválido');
            }
            
            Notificacoes::marcarComoLida($notificacao_id, $usuario_id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Notificação marcada como lida'
            ]);
            break;
            
        case 'marcar_todas_lidas':
            Notificacoes::marcarTodasComoLidas($usuario_id);
            
            echo json_encode([
                'success' => true,
                'message' => 'Todas as notificações foram marcadas como lidas'
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