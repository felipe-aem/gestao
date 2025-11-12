<?php
/**
 * Arquivo: modules/prospeccao/processar_tarefa_visita.php
 * 
 * Processa criação de tarefa quando prospecto é movido para:
 * - Visita Semanal
 * - Revisitar
 */

require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/prospeccao_agenda_helper.php';

Auth::protect();

header('Content-Type: application/json');

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'];

try {
    // Dados recebidos
    $prospeccao_id = $_POST['prospeccao_id'] ?? null;
    $fase = $_POST['fase'] ?? null;
    $data_revisita = $_POST['data_revisita'] ?? null;
    $hora_revisita = $_POST['hora_revisita'] ?? null;
    $observacoes = $_POST['observacoes_revisita'] ?? '';
    
    if (!$prospeccao_id || !$fase) {
        throw new Exception('Dados incompletos');
    }
    
    // Buscar informações da prospecção
    $sql = "SELECT * FROM prospeccoes WHERE id = ?";
    $stmt = executeQuery($sql, [$prospeccao_id]);
    $prospeccao = $stmt->fetch();
    
    if (!$prospeccao) {
        throw new Exception('Prospecção não encontrada');
    }
    
    // Determinar tipo de tarefa e data
    if ($fase === 'Visita Semanal') {
        $tipo_tarefa = 'Visita Semanal';
        $data_agendada = ProspeccaoAgendaHelper::calcularDataVisitaSemanal();
        $hora_agendada = '09:00:00';
        $responsavel_id = $prospeccao['responsavel_id'];
        
    } else if ($fase === 'Revisitar') {
        $tipo_tarefa = 'Revisita';
        $data_agendada = $data_revisita ?? ProspeccaoAgendaHelper::calcularDataRevisita();
        $hora_agendada = $hora_revisita ?? '09:00:00';
        $responsavel_id = $prospeccao['responsavel_id'];
        
    } else {
        throw new Exception('Fase inválida para criação de tarefa');
    }
    
    // Criar tarefa de visita
    $resultado = ProspeccaoAgendaHelper::criarTarefaVisita(
        $prospeccao_id,
        $tipo_tarefa,
        $data_agendada,
        $hora_agendada,
        $responsavel_id,
        $usuario_id
    );
    
    if (!$resultado['success']) {
        throw new Exception($resultado['message']);
    }
    
    // Atualizar fase da prospecção
    $sql_update = "UPDATE prospeccoes 
                   SET fase = ?,
                       data_ultima_atualizacao = NOW()
                   WHERE id = ?";
    executeQuery($sql_update, [$fase, $prospeccao_id]);
    
    // Registrar no histórico
    $sql_hist = "INSERT INTO prospeccoes_historico (
                    prospeccao_id,
                    fase_anterior,
                    fase_nova,
                    observacao,
                    usuario_id,
                    data_movimento
                 ) VALUES (?, ?, ?, ?, ?, NOW())";
    
    executeQuery($sql_hist, [
        $prospeccao_id,
        $prospeccao['fase'],
        $fase,
        $observacoes ?: "Movido para {$fase}",
        $usuario_id
    ]);
    
    // Registrar interação
    $sql_interacao = "INSERT INTO prospeccoes_interacoes (
                        prospeccao_id,
                        tarefa_visita_id,
                        tipo,
                        descricao,
                        usuario_id,
                        data_interacao
                      ) VALUES (?, ?, 'Observação', ?, ?, NOW())";
    
    executeQuery($sql_interacao, [
        $prospeccao_id,
        $resultado['tarefa_id'],
        "📅 Tarefa de {$tipo_tarefa} criada para " . date('d/m/Y', strtotime($data_agendada)),
        $usuario_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Tarefa criada com sucesso!',
        'tarefa_id' => $resultado['tarefa_id'],
        'agenda_id' => $resultado['agenda_id']
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao processar tarefa de visita: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>