<?php
/**
 * notificacoes_stream.php
 * Stream de notificações em tempo real usando Server-Sent Events
 */

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

// Configurar headers para SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Para Nginx

// Desabilitar buffer
@ob_end_clean();
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);

$usuario_logado = Auth::user();
$ultimo_id_notificacao = 0;

// Função para enviar evento SSE
function enviarEvento($evento, $dados) {
    echo "event: {$evento}\n";
    echo "data: " . json_encode($dados) . "\n\n";
    @ob_flush();
    @flush();
}

// Enviar heartbeat inicial
enviarEvento('connected', ['message' => 'Conectado ao stream de notificações']);

// Loop principal
while (true) {
    try {
        $pdo = getConnection();
        
        // Buscar novas notificações
        $sql = "SELECT n.*, 
                       u_origem.nome as origem_nome,
                       u_origem.avatar_url as origem_avatar
                FROM notificacoes_revisao n
                JOIN usuarios u_origem ON n.usuario_origem_id = u_origem.id
                WHERE n.usuario_destinatario_id = ?
                AND n.id > ?
                AND n.lida = 0
                ORDER BY n.created_at DESC
                LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_logado['usuario_id'], $ultimo_id_notificacao]);
        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notificacoes as $notif) {
            // Formatar dados da notificação
            $dados_notificacao = [
                'id' => $notif['id'],
                'tipo' => $notif['tipo_notificacao'],
                'titulo' => $notif['titulo'],
                'mensagem' => $notif['mensagem'],
                'link' => $notif['link'],
                'origem_nome' => $notif['origem_nome'],
                'origem_avatar' => $notif['origem_avatar'],
                'tempo' => self::tempoRelativo($notif['created_at']),
                'timestamp' => $notif['created_at']
            ];
            
            // Enviar evento específico por tipo
            switch ($notif['tipo_notificacao']) {
                case 'nova_revisao':
                    enviarEvento('nova_revisao', $dados_notificacao);
                    break;
                    
                case 'revisao_aceita':
                    enviarEvento('revisao_aceita', $dados_notificacao);
                    break;
                    
                case 'revisao_recusada':
                    enviarEvento('revisao_recusada', $dados_notificacao);
                    break;
                    
                case 'correcao_enviada':
                    enviarEvento('correcao_enviada', $dados_notificacao);
                    break;
                    
                case 'protocolo_realizado':
                    enviarEvento('protocolo_realizado', $dados_notificacao);
                    break;
                    
                default:
                    enviarEvento('notificacao', $dados_notificacao);
            }
            
            // Atualizar último ID processado
            if ($notif['id'] > $ultimo_id_notificacao) {
                $ultimo_id_notificacao = $notif['id'];
            }
        }
        
        // Enviar contagem de notificações não lidas
        $sql_count = "SELECT COUNT(*) as total 
                     FROM notificacoes_revisao 
                     WHERE usuario_destinatario_id = ? AND lida = 0";
        $stmt = $pdo->prepare($sql_count);
        $stmt->execute([$usuario_logado['usuario_id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        enviarEvento('badge_update', ['count' => $count['total']]);
        
        // Heartbeat a cada 30 segundos
        if (time() % 30 == 0) {
            enviarEvento('heartbeat', ['time' => date('Y-m-d H:i:s')]);
        }
        
    } catch (Exception $e) {
        error_log("Erro no stream de notificações: " . $e->getMessage());
        enviarEvento('error', ['message' => 'Erro ao buscar notificações']);
    }
    
    // Aguardar 3 segundos antes da próxima verificação
    sleep(3);
    
    // Verificar se a conexão ainda está ativa
    if (connection_aborted()) {
        break;
    }
}

/**
 * Função auxiliar para calcular tempo relativo
 */
function tempoRelativo($timestamp) {
    $agora = time();
    $tempo = strtotime($timestamp);
    $diferenca = $agora - $tempo;
    
    if ($diferenca < 60) {
        return 'agora';
    } elseif ($diferenca < 3600) {
        $minutos = floor($diferenca / 60);
        return $minutos . ' minuto' . ($minutos != 1 ? 's' : '') . ' atrás';
    } elseif ($diferenca < 86400) {
        $horas = floor($diferenca / 3600);
        return $horas . ' hora' . ($horas != 1 ? 's' : '') . ' atrás';
    } else {
        $dias = floor($diferenca / 86400);
        return $dias . ' dia' . ($dias != 1 ? 's' : '') . ' atrás';
    }
}
?>