<?php
// cron/verificar_notificacoes.php
/**
 * Script para verificar e criar notificações automáticas
 * Executar via CRON a cada 1 hora
 */

// Permitir execução via CLI ou web (com proteção)
if (php_sapi_name() !== 'cli') {
    // Se não for CLI, verificar token de segurança
    $token = $_GET['token'] ?? '';
    $token_esperado = 'seu_token_secreto_aqui_' . date('Ymd'); // Muda diariamente
    
    if ($token !== $token_esperado) {
        die('Acesso negado');
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notificacoes_helper.php';

echo "=== INICIANDO VERIFICAÇÃO DE NOTIFICAÇÕES ===" . PHP_EOL;
echo "Data/Hora: " . date('d/m/Y H:i:s') . PHP_EOL . PHP_EOL;

$total_criadas = 0;

try {
    // ========================================
    // 1. VERIFICAR PRAZOS VENCENDO
    // ========================================
    echo "1. Verificando prazos..." . PHP_EOL;
    
    // Prazos que vencem em 0, 1, 2, 3, 5, 7 dias
    $dias_alerta = [0, 1, 2, 3, 5, 7];
    
    foreach ($dias_alerta as $dias) {
        $data_alvo = date('Y-m-d', strtotime("+{$dias} days"));
        
        $sql = "SELECT p.*, pr.numero_processo 
                FROM prazos p
                INNER JOIN processos pr ON p.processo_id = pr.id
                WHERE DATE(p.data_vencimento) = ?
                AND p.status NOT IN ('concluido', 'cancelado')";
        
        $stmt = executeQuery($sql, [$data_alvo]);
        $prazos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($prazos as $prazo) {
            // Verificar se já existe notificação para este prazo nos últimos 12 horas
            $sql_check = "SELECT id FROM notificacoes_sistema 
                         WHERE usuario_id = ? 
                         AND tipo = 'prazo_vencendo'
                         AND mensagem LIKE ?
                         AND data_criacao > DATE_SUB(NOW(), INTERVAL 12 HOUR)";
            
            $stmt_check = executeQuery($sql_check, [
                $prazo['responsavel_id'], 
                '%' . $prazo['titulo'] . '%'
            ]);
            
            if ($stmt_check->rowCount() == 0) {
                // Criar notificação
                Notificacoes::prazoVencendo(
                    $prazo['responsavel_id'],
                    $prazo['id'],
                    $prazo['titulo'],
                    $dias
                );
                
                $total_criadas++;
                echo "   ✓ Prazo '{$prazo['titulo']}' - Vence em {$dias} dia(s)" . PHP_EOL;
            }
        }
    }
    
    // Prazos VENCIDOS (atrasados)
    $sql_vencidos = "SELECT p.*, pr.numero_processo 
                     FROM prazos p
                     INNER JOIN processos pr ON p.processo_id = pr.id
                     WHERE DATE(p.data_vencimento) < CURDATE()
                     AND p.status NOT IN ('concluido', 'cancelado')";
    
    $stmt_vencidos = executeQuery($sql_vencidos);
    $prazos_vencidos = $stmt_vencidos->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($prazos_vencidos as $prazo) {
        $data_venc = new DateTime($prazo['data_vencimento']);
        $hoje = new DateTime();
        $dias_atraso = $hoje->diff($data_venc)->days;
        
        // Notificar apenas se atrasou há 1, 3, 7, 15, 30 dias (para não spammar)
        if (in_array($dias_atraso, [1, 3, 7, 15, 30])) {
            $sql_check = "SELECT id FROM notificacoes_sistema 
                         WHERE usuario_id = ? 
                         AND tipo = 'prazo_vencido'
                         AND mensagem LIKE ?
                         AND data_criacao > DATE_SUB(NOW(), INTERVAL 12 HOUR)";
            
            $stmt_check = executeQuery($sql_check, [
                $prazo['responsavel_id'], 
                '%' . $prazo['titulo'] . '%'
            ]);
            
            if ($stmt_check->rowCount() == 0) {
                Notificacoes::prazoVencido(
                    $prazo['responsavel_id'],
                    $prazo['id'],
                    $prazo['titulo'],
                    $dias_atraso
                );
                
                $total_criadas++;
                echo "   🚨 Prazo VENCIDO '{$prazo['titulo']}' - {$dias_atraso} dia(s) de atraso" . PHP_EOL;
            }
        }
    }
    
    // ========================================
    // 2. VERIFICAR TAREFAS VENCENDO
    // ========================================
    echo PHP_EOL . "2. Verificando tarefas..." . PHP_EOL;
    
    foreach ([0, 1, 3] as $dias) {
        $data_alvo = date('Y-m-d', strtotime("+{$dias} days"));
        
        $sql = "SELECT * FROM tarefas 
                WHERE DATE(data_vencimento) = ?
                AND status NOT IN ('concluida', 'cancelada')";
        
        $stmt = executeQuery($sql, [$data_alvo]);
        $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tarefas as $tarefa) {
            $sql_check = "SELECT id FROM notificacoes_sistema 
                         WHERE usuario_id = ? 
                         AND tipo = 'tarefa_vencendo'
                         AND mensagem LIKE ?
                         AND data_criacao > DATE_SUB(NOW(), INTERVAL 12 HOUR)";
            
            $stmt_check = executeQuery($sql_check, [
                $tarefa['responsavel_id'], 
                '%' . $tarefa['titulo'] . '%'
            ]);
            
            if ($stmt_check->rowCount() == 0) {
                Notificacoes::tarefaVencendo(
                    $tarefa['responsavel_id'],
                    $tarefa['id'],
                    $tarefa['titulo'],
                    $dias
                );
                
                $total_criadas++;
                echo "   ✓ Tarefa '{$tarefa['titulo']}' - Vence em {$dias} dia(s)" . PHP_EOL;
            }
        }
    }
    
    // ========================================
    // 3. VERIFICAR AUDIÊNCIAS PRÓXIMAS
    // ========================================
    echo PHP_EOL . "3. Verificando audiências..." . PHP_EOL;
    
    // Verificar audiências nos próximos 1, 3, 7 dias
    foreach ([1, 3, 7] as $dias) {
        $data_inicio = date('Y-m-d 00:00:00', strtotime("+{$dias} days"));
        $data_fim = date('Y-m-d 23:59:59', strtotime("+{$dias} days"));
        
        $sql = "SELECT a.*, pr.numero_processo 
                FROM audiencias a
                INNER JOIN processos pr ON a.processo_id = pr.id
                WHERE a.data_inicio BETWEEN ? AND ?
                AND a.status NOT IN ('cancelada', 'concluida')";
        
        $stmt = executeQuery($sql, [$data_inicio, $data_fim]);
        $audiencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($audiencias as $audiencia) {
            // Notificar o responsável
            if ($audiencia['responsavel_id']) {
                $sql_check = "SELECT id FROM notificacoes_sistema 
                             WHERE usuario_id = ? 
                             AND tipo = 'audiencia_proxima'
                             AND mensagem LIKE ?
                             AND data_criacao > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                
                $stmt_check = executeQuery($sql_check, [
                    $audiencia['responsavel_id'], 
                    '%' . $audiencia['titulo'] . '%'
                ]);
                
                if ($stmt_check->rowCount() == 0) {
                    Notificacoes::audienciaProxima(
                        $audiencia['responsavel_id'],
                        $audiencia['id'],
                        $audiencia['titulo'],
                        $audiencia['data_inicio']
                    );
                    
                    $total_criadas++;
                    echo "   ✓ Audiência '{$audiencia['titulo']}' - Em {$dias} dia(s)" . PHP_EOL;
                }
            }
        }
    }
    
    // ========================================
    // 4. VERIFICAR EVENTOS DA AGENDA
    // ========================================
    echo PHP_EOL . "4. Verificando eventos da agenda..." . PHP_EOL;
    
    // Eventos de amanhã
    $amanha_inicio = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $amanha_fim = date('Y-m-d 23:59:59', strtotime('+1 day'));
    
    $sql = "SELECT a.*, ap.usuario_id
            FROM agenda a
            INNER JOIN agenda_participantes ap ON a.id = ap.agenda_id
            WHERE a.data_inicio BETWEEN ? AND ?
            AND a.status IN ('Agendado', 'Confirmado')
            AND ap.status_participacao IN ('Organizador', 'Confirmado')";
    
    $stmt = executeQuery($sql, [$amanha_inicio, $amanha_fim]);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($eventos as $evento) {
        $sql_check = "SELECT id FROM notificacoes_sistema 
                     WHERE usuario_id = ? 
                     AND tipo = 'audiencia_proxima'
                     AND mensagem LIKE ?
                     AND data_criacao > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        $stmt_check = executeQuery($sql_check, [
            $evento['usuario_id'], 
            '%' . $evento['titulo'] . '%'
        ]);
        
        if ($stmt_check->rowCount() == 0) {
            Notificacoes::criar(
                $evento['usuario_id'],
                'audiencia_proxima',
                'Evento Amanhã',
                "Você tem o evento '{$evento['titulo']}' amanhã às " . date('H:i', strtotime($evento['data_inicio'])),
                '/modules/agenda/visualizar.php?id=' . $evento['id'],
                'normal'
            );
            
            $total_criadas++;
            echo "   ✓ Evento '{$evento['titulo']}' - Amanhã" . PHP_EOL;
        }
    }
    
    // ========================================
    // 5. LIMPAR NOTIFICAÇÕES ANTIGAS
    // ========================================
    echo PHP_EOL . "5. Limpando notificações antigas..." . PHP_EOL;
    
    // Excluir notificações lidas com mais de 30 dias
    $sql_limpar = "DELETE FROM notificacoes_sistema 
                   WHERE lida = 1 
                   AND data_leitura < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    executeQuery($sql_limpar);
    $removidas = $GLOBALS['pdo']->rowCount();
    
    echo "   ✓ {$removidas} notificações antigas removidas" . PHP_EOL;
    
    // Excluir notificações expiradas
    $sql_expiradas = "DELETE FROM notificacoes_sistema 
                      WHERE expira_em IS NOT NULL 
                      AND expira_em < NOW()";
    executeQuery($sql_expiradas);
    $expiradas = $GLOBALS['pdo']->rowCount();
    
    echo "   ✓ {$expiradas} notificações expiradas removidas" . PHP_EOL;
    
    // ========================================
    // RESUMO
    // ========================================
    echo PHP_EOL . "=== RESUMO ===" . PHP_EOL;
    echo "Total de notificações criadas: {$total_criadas}" . PHP_EOL;
    echo "Notificações antigas removidas: {$removidas}" . PHP_EOL;
    echo "Notificações expiradas removidas: {$expiradas}" . PHP_EOL;
    echo PHP_EOL . "=== CONCLUÍDO ===" . PHP_EOL;
    
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . PHP_EOL;
    error_log("Erro no cron de notificações: " . $e->getMessage());
}
?>