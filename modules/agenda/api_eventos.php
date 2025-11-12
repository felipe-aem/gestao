<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    $revisao_id = $_POST['revisao_id'] ?? 0;
    $acao = $_POST['acao'] ?? ''; // 'aceitar' ou 'recusar'
    $comentario = $_POST['comentario_revisor'] ?? '';
    
    if (!$revisao_id) {
        throw new Exception('ID da revisão não informado');
    }
    
    if (!in_array($acao, ['aceitar', 'recusar'])) {
        throw new Exception('Ação inválida');
    }
    
    // Buscar revisão
    $sql = "SELECT tr.*, 
            CASE WHEN tr.tipo_origem = 'tarefa' THEN t.titulo ELSE p.titulo END as titulo_origem,
            CASE WHEN tr.tipo_origem = 'tarefa' THEN t.processo_id ELSE p.processo_id END as processo_id,
            CASE WHEN tr.tipo_origem = 'tarefa' THEN t.prioridade ELSE p.prioridade END as prioridade
            FROM tarefa_revisoes tr
            LEFT JOIN tarefas t ON tr.tarefa_origem_id = t.id AND tr.tipo_origem = 'tarefa'
            LEFT JOIN prazos p ON tr.tarefa_origem_id = p.id AND tr.tipo_origem = 'prazo'
            WHERE tr.id = ? AND tr.status = 'pendente'";
    $stmt = executeQuery($sql, [$revisao_id]);
    $revisao = $stmt->fetch();
    
    if (!$revisao) {
        throw new Exception('Revisão não encontrada ou já respondida');
    }
    
    // Verificar se o usuário é o revisor
    if ($revisao['usuario_revisor_id'] != $usuario_logado['usuario_id']) {
        throw new Exception('Você não tem permissão para responder esta revisão');
    }
    
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // Processar arquivos (se houver)
        $arquivos_json = null;
        if (!empty($_FILES['arquivos_revisor']['name'][0])) {
            $arquivos = [];
            $upload_dir = '../../../uploads/revisoes/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            foreach ($_FILES['arquivos_revisor']['name'] as $key => $name) {
                if ($_FILES['arquivos_revisor']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['arquivos_revisor']['tmp_name'][$key];
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
        
        // Atualizar revisão
        $novo_status = $acao === 'aceitar' ? 'aceita' : 'recusada';
        $sql_update = "UPDATE tarefa_revisoes 
                       SET status = ?, 
                           comentario_revisor = ?,
                           arquivos_revisor = ?,
                           data_resposta = NOW()
                       WHERE id = ?";
        
        executeQuery($sql_update, [
            $novo_status,
            $comentario,
            $arquivos_json,
            $revisao_id
        ]);
        
        // Concluir a tarefa/prazo de REVISÃO
        if ($revisao['tipo_origem'] === 'tarefa') {
            $sql_concluir = "UPDATE tarefas SET status = 'concluida', data_conclusao = NOW() WHERE id = ?";
        } else {
            $sql_concluir = "UPDATE prazos SET status = 'concluido', data_conclusao = NOW() WHERE id = ?";
        }
        executeQuery($sql_concluir, [$revisao['tarefa_revisao_id']]);
        
        // ============= ATUALIZAR STATUS DA TAREFA ORIGINAL =============
        if ($acao === 'aceitar') {
            // ACEITA - Marcar tarefa original como CONCLUÍDA
            if ($revisao['tipo_origem'] === 'tarefa') {
                $sql_atualizar_origem = "UPDATE tarefas 
                                        SET status = 'concluida', 
                                            data_conclusao = NOW(),
                                            concluida_por = ?,
                                            concluido_por = ?
                                        WHERE id = ?";
                executeQuery($sql_atualizar_origem, [
                    $usuario_logado['usuario_id'],
                    $usuario_logado['usuario_id'],
                    $revisao['tarefa_origem_id']
                ]);
            } else {
                $sql_atualizar_origem = "UPDATE prazos 
                                        SET status = 'concluido', 
                                            data_conclusao = NOW(),
                                            concluido_por = ?
                                        WHERE id = ?";
                executeQuery($sql_atualizar_origem, [
                    $usuario_logado['usuario_id'],
                    $revisao['tarefa_origem_id']
                ]);
            }
        } else {
            // RECUSADA - Atualizar tarefa original para 'revisao_recusada'
            // Permanece pendente para que o solicitante possa corrigir
            if ($revisao['tipo_origem'] === 'tarefa') {
                $sql_atualizar_origem = "UPDATE tarefas SET status = 'revisao_recusada' WHERE id = ?";
            } else {
                $sql_atualizar_origem = "UPDATE prazos SET status = 'revisao_recusada' WHERE id = ?";
            }
            executeQuery($sql_atualizar_origem, [$revisao['tarefa_origem_id']]);
        }
        
        // Criar nova tarefa/prazo para o solicitante
        if ($acao === 'aceitar') {
            // ACEITA - Criar tarefa de PROTOCOLO
            $titulo_novo = "PROTOCOLO: " . $revisao['titulo_origem'];
            $descricao_novo = "Revisão aceita. Pronto para protocolo.\n\n";
            if ($comentario) {
                $descricao_novo .= "Comentário do revisor: " . $comentario;
            }
            $tipo_novo = 'protocolo';
            
        } else {
            // RECUSADA - Criar tarefa de CORREÇÃO
            $titulo_novo = "CORREÇÃO: " . $revisao['titulo_origem'];
            $descricao_novo = "Revisão recusada. Necessário corrigir.\n\n";
            if ($comentario) {
                $descricao_novo .= "Comentário do revisor: " . $comentario;
            }
            $tipo_novo = 'correcao';
        }
        
        if ($revisao['tipo_origem'] === 'tarefa') {
            $sql_nova = "INSERT INTO tarefas (
                            titulo, descricao, responsavel_id, data_vencimento,
                            prioridade, status, processo_id, criado_por, tipo_tarefa,
                            tarefa_origem_revisao_id, criado_em
                         ) VALUES (?, ?, ?, CURDATE(), ?, 'pendente', ?, ?, ?, ?, NOW())";
        } else {
            $sql_nova = "INSERT INTO prazos (
                            titulo, descricao, responsavel_id, data_vencimento,
                            prioridade, status, processo_id, criado_por, tipo_prazo,
                            prazo_origem_revisao_id, data_criacao
                         ) VALUES (?, ?, ?, CURDATE(), ?, 'pendente', ?, ?, ?, ?, NOW())";
        }
        
        executeQuery($sql_nova, [
            $titulo_novo,
            $descricao_novo,
            $revisao['usuario_solicitante_id'],
            $revisao['prioridade'] ?? 'media',
            $revisao['processo_id'],
            $usuario_logado['usuario_id'],
            $tipo_novo,
            $revisao_id
        ]);
        
        $nova_id = $pdo->lastInsertId();
        
        // Atualizar revisão com ID da tarefa de retorno
        $sql_update_retorno = "UPDATE tarefa_revisoes SET tarefa_retorno_id = ? WHERE id = ?";
        executeQuery($sql_update_retorno, [$nova_id, $revisao_id]);
        
        // Registrar no histórico
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        HistoricoHelper::registrar(
            $revisao['tipo_origem'], 
            $revisao['tarefa_origem_id'], 
            'revisao_' . $novo_status, 
            [
                'revisor_id' => $usuario_logado['usuario_id'],
                'comentario' => $comentario,
                'acao' => $acao,
                'nova_tarefa_id' => $nova_id
            ]
        );
        
        $pdo->commit();
        
        $mensagem = $acao === 'aceitar' 
            ? 'Revisão aceita! Tarefa de protocolo criada para o solicitante.'
            : 'Revisão recusada! Tarefa de correção enviada para o solicitante.';
        
        echo json_encode([
            'success' => true,
            'message' => $mensagem
        ]);
        
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