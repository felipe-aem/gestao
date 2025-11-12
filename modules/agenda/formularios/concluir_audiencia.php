<?php
/**
 * Concluir Audiência
 * 
 * Marca uma audiência como realizada e permite adicionar observações sobre o resultado
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../../includes/auth.php';
Auth::protect();

require_once '../../../config/database.php';
require_once '../includes/HistoricoHelper.php';

$usuario_logado = Auth::user();

try {
    $audiencia_id = $_POST['id'] ?? null;
    
    if (!$audiencia_id) {
        throw new Exception('ID da audiência não informado');
    }
    
    // Buscar audiência
    $sql = "SELECT a.*, p.numero_processo 
            FROM audiencias a
            INNER JOIN processos p ON a.processo_id = p.id
            WHERE a.id = ? AND a.deleted_at IS NULL";
    $stmt = executeQuery($sql, [$audiencia_id]);
    $audiencia = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$audiencia) {
        throw new Exception('Audiência não encontrada');
    }
    
    // Verificar se já está concluída
    if ($audiencia['status'] === 'realizada') {
        throw new Exception('Esta audiência já foi marcada como realizada');
    }
    
    // Preparar dados
    $observacoes_resultado = trim($_POST['observacoes_resultado'] ?? '');
    $data_realizacao = $_POST['data_realizacao'] ?? date('Y-m-d H:i:s');
    
    // Atualizar status
    $sql_update = "UPDATE audiencias SET 
                   status = 'realizada',
                   observacoes_resultado = ?,
                   data_realizacao = ?,
                   realizado_por = ?,
                   data_atualizacao = NOW()
                   WHERE id = ?";
    
    executeQuery($sql_update, [
        $observacoes_resultado,
        $data_realizacao,
        $usuario_logado['usuario_id'],
        $audiencia_id
    ]);
    
    // ====== REGISTRAR CONCLUSÃO NO HISTÓRICO DA AUDIÊNCIA ======
    $obs_conclusao = 'Marcou como realizada';
    if (!empty($observacoes_resultado)) {
        $obs_conclusao .= ' | Resultado: ' . $observacoes_resultado;
    }
    
    HistoricoHelper::registrar('audiencia', $audiencia_id, 'conclusao', [
        'observacao' => $obs_conclusao
    ]);
    
    // Registrar no histórico do processo
    $sql_historico = "INSERT INTO processo_historico (
                          processo_id, usuario_id, acao, descricao, data_acao
                      ) VALUES (?, ?, ?, ?, NOW())";
    
    executeQuery($sql_historico, [
        $audiencia['processo_id'],
        $usuario_logado['usuario_id'],
        'Audiência Realizada',
        "Audiência '{$audiencia['titulo']}' realizada em " . date('d/m/Y H:i', strtotime($data_realizacao))
    ]);
    
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Audiência marcada como realizada com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => $e->getMessage()
    ]);
}