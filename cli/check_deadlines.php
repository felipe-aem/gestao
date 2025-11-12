<?php
// cli/check_deadlines.php
// Script para verificar prazos e audiências próximas

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando.\n");
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/NotificacaoHelper.php';

echo "[" . date('Y-m-d H:i:s') . "] Verificando prazos e audiências...\n";

try {
    $pdo = getConnection();
    
    // ========== PRAZOS VENCENDO EM 48H ==========
    $sql_prazos = "SELECT id, titulo, data_vencimento, responsavel_id
                   FROM prazos
                   WHERE status IN ('pendente', 'em_andamento')
                   AND data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
                   AND id NOT IN (
                       SELECT SUBSTRING_INDEX(link, '=', -1) 
                       FROM notificacoes_sistema 
                       WHERE tipo = 'prazo_vencendo' 
                       AND data_criacao >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   )";
    
    $stmt = $pdo->query($sql_prazos);
    $prazos_count = 0;
    
    while ($prazo = $stmt->fetch(PDO::FETCH_ASSOC)) {
        NotificacaoHelper::notificarPrazoVencendo($prazo['id']);
        $prazos_count++;
    }
    
    echo "✓ {$prazos_count} notificações de prazos criadas\n";
    
    // ========== AUDIÊNCIAS NAS PRÓXIMAS 24H ==========
    $sql_audiencias = "SELECT id, titulo, data_inicio, responsavel_id
                       FROM audiencias
                       WHERE status = 'agendada'
                       AND data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
                       AND id NOT IN (
                           SELECT SUBSTRING_INDEX(link, '=', -1) 
                           FROM notificacoes_sistema 
                           WHERE tipo = 'audiencia_proxima' 
                           AND data_criacao >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
                       )";
    
    $stmt_aud = $pdo->query($sql_audiencias);
    $audiencias_count = 0;
    
    while ($aud = $stmt_aud->fetch(PDO::FETCH_ASSOC)) {
        NotificacaoHelper::notificarAudienciaProxima($aud['id']);
        $audiencias_count++;
    }
    
    echo "✓ {$audiencias_count} notificações de audiências criadas\n";
    
    echo "[" . date('Y-m-d H:i:s') . "] Verificação concluída!\n";
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>