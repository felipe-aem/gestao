<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

// Esta função pode ser chamada via CRON ou AJAX para verificar lembretes
function verificarLembretes() {
    try {
        $agora = date('Y-m-d H:i:s');
        
        // Buscar eventos que precisam de lembrete
        $sql = "SELECT a.*, u.nome as responsavel_nome, u.email as responsavel_email
                FROM agenda a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                WHERE a.status IN ('Agendado', 'Confirmado')
                AND a.lembrete_minutos > 0
                AND TIMESTAMPDIFF(MINUTE, ?, a.data_inicio) <= a.lembrete_minutos
                AND TIMESTAMPDIFF(MINUTE, ?, a.data_inicio) > 0
                AND a.lembrete_enviado = 0";
        
        $stmt = executeQuery($sql, [$agora, $agora]);
        $eventos_lembrete = $stmt->fetchAll();
        
        $lembretes_enviados = [];
        
        foreach ($eventos_lembrete as $evento) {
            $minutos_restantes = round((strtotime($evento['data_inicio']) - time()) / 60);
            
            // Marcar lembrete como enviado
            $sql_update = "UPDATE agenda SET lembrete_enviado = 1 WHERE id = ?";
            executeQuery($sql_update, [$evento['id']]);
            
            // Criar notificação no sistema
            $sql_notif = "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao) 
                         VALUES (?, 'lembrete_evento', ?, ?, ?, NOW())";
            
            $titulo = "🔔 Lembrete: {$evento['titulo']}";
            $mensagem = "Seu evento '{$evento['titulo']}' começará em {$minutos_restantes} minuto(s).";
            $link = "/modules/agenda/visualizar.php?id={$evento['id']}";
            
            executeQuery($sql_notif, [$evento['usuario_id'], $titulo, $mensagem, $link]);
            
            $lembretes_enviados[] = [
                'evento' => $evento,
                'minutos_restantes' => $minutos_restantes
            ];
        }
        
        return $lembretes_enviados;
        
    } catch (Exception $e) {
        error_log("Erro ao verificar lembretes: " . $e->getMessage());
        return [];
    }
}

// Se chamado via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'verificar_lembretes') {
    header('Content-Type: application/json');
    
    $lembretes = verificarLembretes();
    
    echo json_encode([
        'success' => true,
        'lembretes' => count($lembretes),
        'dados' => $lembretes
    ]);
    exit;
}

// Buscar notificações do usuário atual
if (isset($_GET['action']) && $_GET['action'] === 'buscar_notificacoes') {
    header('Content-Type: application/json');
    
    try {
        $usuario_id = Auth::user()['usuario_id'];
        
        $sql = "SELECT * FROM notificacoes 
                WHERE usuario_id = ? 
                AND lida = 0 
                ORDER BY data_criacao DESC 
                LIMIT 10";
        
        $stmt = executeQuery($sql, [$usuario_id]);
        $notificacoes = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'notificacoes' => $notificacoes
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Marcar notificação como lida
if (isset($_GET['action']) && $_GET['action'] === 'marcar_lida') {
    header('Content-Type: application/json');
    
    try {
        $notificacao_id = $_GET['id'] ?? 0;
        $usuario_id = Auth::user()['usuario_id'];
        
        $sql = "UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?";
        executeQuery($sql, [$notificacao_id, $usuario_id]);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
?>