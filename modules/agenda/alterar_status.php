<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$evento_id = $_GET['id'] ?? 0;
$novo_status = $_GET['status'] ?? '';

if (!$evento_id || !$novo_status) {
    header('Location: index.php?erro=Parâmetros inválidos');
    exit;
}

$status_validos = ['Agendado', 'Confirmado', 'Cancelado', 'Concluído', 'Reagendado'];
if (!in_array($novo_status, $status_validos)) {
    header('Location: visualizar.php?id=' . $evento_id . '&tipo=evento&erro=Status inválido');
    exit;
}

try {
    // Buscar evento atual e verificar permissões
    $sql = "SELECT a.*, 
            ap.usuario_id as organizador_id,
            u.nome as organizador_nome
            FROM agenda a
            LEFT JOIN agenda_participantes ap ON a.id = ap.agenda_id AND ap.status_participacao = 'Organizador'
            LEFT JOIN usuarios u ON ap.usuario_id = u.id
            WHERE a.id = ?";
    $stmt = executeQuery($sql, [$evento_id]);
    $evento = $stmt->fetch();
    
    if (!$evento) {
        header('Location: index.php?erro=Evento não encontrado');
        exit;
    }
    
    // Verificar permissões
    $usuario_logado_id = Auth::user()['usuario_id'];
    $pode_alterar = false;
    
    // Organizador sempre pode alterar
    if ($evento['organizador_id'] == $usuario_logado_id) {
        $pode_alterar = true;
    }
    
    // Admin pode alterar qualquer evento
    if (Auth::canManageUsers()) {
        $pode_alterar = true;
    }
    
    // Participantes confirmados podem marcar como concluído
    if ($novo_status === 'Concluído') {
        $sql_participante = "SELECT 1 FROM agenda_participantes 
                            WHERE agenda_id = ? AND usuario_id = ? 
                            AND status_participacao IN ('Organizador', 'Confirmado')";
        $stmt = executeQuery($sql_participante, [$evento_id, $usuario_logado_id]);
        if ($stmt->fetch()) {
            $pode_alterar = true;
        }
    }
    
    if (!$pode_alterar) {
        header('Location: visualizar.php?id=' . $evento_id . '&tipo=evento&erro=Sem permissão para alterar este evento');
        exit;
    }
    
    $status_anterior = $evento['status'];
    
    // Atualizar status
    $sql = "UPDATE agenda SET status = ?, updated_at = NOW() WHERE id = ?";
    executeQuery($sql, [$novo_status, $evento_id]);
    
    // Lógica automática baseada no novo status
    switch ($novo_status) {
        case 'Confirmado':
            // Organizador automaticamente confirma participação
            $sql_confirm_org = "UPDATE agenda_participantes 
                               SET status_participacao = 'Confirmado', data_resposta = NOW()
                               WHERE agenda_id = ? AND status_participacao = 'Organizador'";
            executeQuery($sql_confirm_org, [$evento_id]);
            break;
            
        case 'Reagendado':
            // Resetar confirmações dos participantes (exceto recusados)
            $sql_reset = "UPDATE agenda_participantes 
                         SET status_participacao = CASE 
                             WHEN status_participacao = 'Organizador' THEN 'Organizador'
                             WHEN status_participacao = 'Recusado' THEN 'Recusado'
                             ELSE 'Convidado'
                         END,
                         data_resposta = CASE 
                             WHEN status_participacao = 'Organizador' THEN data_resposta
                             WHEN status_participacao = 'Recusado' THEN data_resposta
                             ELSE NULL
                         END
                         WHERE agenda_id = ?";
            executeQuery($sql_reset, [$evento_id]);
            break;
    }
    
    // ====== REGISTRAR ALTERAÇÃO NO HISTÓRICO ======
    require_once __DIR__ . '/includes/HistoricoHelper.php';
    
    // Determinar tipo (evento ou compromisso)
    $tipo_evento = ($evento['tipo'] === 'Compromisso') ? 'compromisso' : 'evento';
    
    // Registrar a alteração de status
    HistoricoHelper::registrarAlteracao($tipo_evento, $evento_id, 'status', $status_anterior, $novo_status);
    
    // Adicionar observação adicional conforme o novo status
    $observacao_extra = null;
    switch ($novo_status) {
        case 'Confirmado':
            $observacao_extra = 'Evento confirmado e pronto para execução';
            break;
        case 'Cancelado':
            $observacao_extra = 'Evento cancelado';
            break;
        case 'Concluído':
            $observacao_extra = 'Evento marcado como concluído';
            break;
        case 'Reagendado':
            $observacao_extra = 'Evento reagendado - Confirmações dos participantes foram resetadas';
            break;
    }
    
    if ($observacao_extra) {
        HistoricoHelper::registrar($tipo_evento, $evento_id, 'comentario', [
            'observacao' => $observacao_extra
        ]);
    }
    
    header("Location: visualizar.php?id={$evento_id}&tipo=evento&success=status");
    exit;
    
} catch (Exception $e) {
    header("Location: visualizar.php?id={$evento_id}&tipo=evento&erro=" . urlencode($e->getMessage()));
    exit;
}
?>