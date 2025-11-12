<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    $tarefa_id = $_POST['tarefa_id'] ?? 0;
    $enviar_revisao = $_POST['enviar_revisao'] ?? 'nao';
    $revisor_id = $_POST['revisor_id'] ?? null;
    $comentario_revisao = $_POST['comentario_revisao'] ?? '';
    
    if (!$tarefa_id) {
        throw new Exception('ID da tarefa não informado');
    }
    
    // Buscar tarefa
    $sql = "SELECT t.*, tr.id as revisao_id, tr.status as revisao_status 
            FROM tarefas t
            LEFT JOIN tarefa_revisoes tr ON t.id = tr.tarefa_origem_id 
                AND tr.tipo_origem = 'tarefa' 
                AND tr.status = 'pendente'
            WHERE t.id = ? AND t.deleted_at IS NULL";
    $stmt = executeQuery($sql, [$tarefa_id]);
    $tarefa = $stmt->fetch();
    
    if (!$tarefa) {
        throw new Exception('Tarefa não encontrada');
    }
    
    // Verificar se já tem revisão pendente
    if (!empty($tarefa['revisao_id']) && $tarefa['revisao_status'] === 'pendente') {
        throw new Exception('Esta tarefa já possui uma revisão pendente');
    }
    
    // Verificar se já está concluída ou aguardando revisão
    if (in_array($tarefa['status'], ['concluida', 'revisao_aceita', 'aguardando_revisao'])) {
        throw new Exception('Esta tarefa já foi concluída ou está aguardando revisão');
    }
    
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // Verificar o tipo de tarefa
        $tipo_tarefa = $tarefa['tipo_tarefa'] ?? '';
        $tipos_sistema = ['revisao', 'protocolo', 'correcao'];
        
        // ============= DETERMINAR STATUS =============
        $novo_status = 'concluida';
        $acao_historico = 'conclusao';
        $observacao_historico = 'Marcou como concluída';
        
        // Se enviou para revisão E não é tarefa de sistema
        if ($enviar_revisao === 'sim' && $revisor_id && !in_array($tipo_tarefa, $tipos_sistema)) {
            $novo_status = 'aguardando_revisao';
            $acao_historico = 'aguardando_revisao';
            $observacao_historico = 'Enviou para revisão';
        }
        
        // Atualizar status da tarefa
        $sql_update = "UPDATE tarefas 
                       SET status = ?, 
                           data_conclusao = NOW(),
                           concluida_por = ?,
                           concluido_por = ?
                       WHERE id = ?";
        
        executeQuery($sql_update, [
            $novo_status,
            $usuario_logado['usuario_id'],
            $usuario_logado['usuario_id'],
            $tarefa_id
        ]);
        
        // Registrar no histórico
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        HistoricoHelper::registrar('tarefa', $tarefa_id, $acao_historico, [
            'observacao' => $observacao_historico
        ]);
        
        // Registrar no histórico do processo (se vinculado)
        if ($tarefa['processo_id']) {
            $acao_processo = ($novo_status === 'aguardando_revisao') ? 'Tarefa Enviada para Revisão' : 'Tarefa Concluída';
            $descricao_processo = ($novo_status === 'aguardando_revisao') 
                ? "Tarefa enviada para revisão: {$tarefa['titulo']}"
                : "Tarefa concluída: {$tarefa['titulo']}";
                
            $sql_hist = "INSERT INTO processo_historico (
                            processo_id, usuario_id, acao, descricao, data_acao
                         ) VALUES (?, ?, ?, ?, NOW())";
            executeQuery($sql_hist, [
                $tarefa['processo_id'],
                $usuario_logado['usuario_id'],
                $acao_processo,
                $descricao_processo
            ]);
        }
        
        $response = [
            'success' => true,
            'message' => 'Tarefa concluída com sucesso!'
        ];
        
        // ============= FLUXO DE REVISÃO =============
        
        // Se enviou para revisão
        if ($enviar_revisao === 'sim' && $revisor_id) {
            // Processar arquivos (se houver)
            $arquivos_json = null;
            if (!empty($_FILES['arquivos_revisao']['name'][0])) {
                $arquivos = [];
                $upload_dir = '../../../uploads/revisoes/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                foreach ($_FILES['arquivos_revisao']['name'] as $key => $name) {
                    if ($_FILES['arquivos_revisao']['error'][$key] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['arquivos_revisao']['tmp_name'][$key];
                        $filename = time() . '_' . $key . '_' . basename($name);
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($tmp_name, $filepath)) {
                            $arquivos[] = [
                                'nome' => $name,
                                'caminho' => 'uploads/revisoes/' . $filename
                            ];
                        }
                    }
                }
                
                if (!empty($arquivos)) {
                    $arquivos_json = json_encode($arquivos);
                }
            }
            
            // Criar registro de revisão
            $sql_revisao = "INSERT INTO tarefa_revisoes (
                                tarefa_origem_id, tipo_origem, usuario_solicitante_id,
                                usuario_revisor_id, comentario_solicitante, arquivos_solicitante,
                                data_solicitacao
                            ) VALUES (?, 'tarefa', ?, ?, ?, ?, NOW())";
            
            executeQuery($sql_revisao, [
                $tarefa_id,
                $usuario_logado['usuario_id'],
                $revisor_id,
                $comentario_revisao,
                $arquivos_json
            ]);
            
            $revisao_id = $pdo->lastInsertId();
            
            // Criar tarefa de REVISÃO para o revisor (mesmo dia)
            $sql_nova = "INSERT INTO tarefas (
                            titulo, descricao, responsavel_id, data_vencimento,
                            prioridade, status, processo_id, criado_por, tipo_tarefa,
                            tarefa_origem_revisao_id, criado_em
                         ) VALUES (?, ?, ?, CURDATE(), ?, 'pendente', ?, ?, 'revisao', ?, NOW())";
            
            $titulo_revisao = "REVISÃO: " . $tarefa['titulo'];
            $descricao_revisao = "Tarefa enviada para revisão.\n\n";
            if ($comentario_revisao) {
                $descricao_revisao .= "Comentário: " . $comentario_revisao;
            }
            
            executeQuery($sql_nova, [
                $titulo_revisao,
                $descricao_revisao,
                $revisor_id,
                $tarefa['prioridade'] ?? 'media',
                $tarefa['processo_id'],
                $usuario_logado['usuario_id'],
                $revisao_id
            ]);
            
            $nova_tarefa_id = $pdo->lastInsertId();
            
            // Atualizar revisão com ID da tarefa criada
            $sql_update_rev = "UPDATE tarefa_revisoes SET tarefa_revisao_id = ? WHERE id = ?";
            executeQuery($sql_update_rev, [$nova_tarefa_id, $revisao_id]);
            
            // Registrar no histórico
            HistoricoHelper::registrar('tarefa', $tarefa_id, 'revisao_enviada', [
                'revisor_id' => $revisor_id,
                'comentario' => $comentario_revisao,
                'tarefa_revisao_id' => $nova_tarefa_id
            ]);
            
            // Buscar nome do revisor
            $sql_revisor = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_revisor = executeQuery($sql_revisor, [$revisor_id]);
            $revisor = $stmt_revisor->fetch();
            
            $response['message'] = 'Tarefa enviada para revisão de ' . $revisor['nome'];
        }
        
        $pdo->commit();
        echo json_encode($response);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}