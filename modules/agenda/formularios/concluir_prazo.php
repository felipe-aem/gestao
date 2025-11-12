<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    $prazo_id = $_POST['prazo_id'] ?? 0;
    $enviar_revisao = $_POST['enviar_revisao'] ?? 'nao'; // 'sim' ou 'nao'
    $revisor_id = $_POST['revisor_id'] ?? null;
    $comentario_revisao = $_POST['comentario_revisao'] ?? '';
    
    if (!$prazo_id) {
        throw new Exception('ID do prazo não informado');
    }
    
    // Buscar prazo
    $sql = "SELECT p.*, tr.id as revisao_id, tr.status as revisao_status 
            FROM prazos p
            LEFT JOIN tarefa_revisoes tr ON p.id = tr.tarefa_retorno_id AND tr.tipo_origem = 'prazo' AND tr.status = 'pendente'
            WHERE p.id = ? AND p.deleted_at IS NULL";
    $stmt = executeQuery($sql, [$prazo_id]);
    $prazo = $stmt->fetch();
    
    if (!$prazo) {
        throw new Exception('Prazo não encontrado');
    }
    
    // Verificar se já está concluído
    if (in_array($prazo['status'], ['concluido', 'revisao_aceita', 'aguardando_revisao'])) {
        throw new Exception('Este prazo já foi concluído ou está aguardando revisão');
    }
    
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // Verificar o tipo de prazo
        $tipo_prazo = $prazo['tipo_prazo'] ?? '';
        $tipos_sistema = ['revisao', 'protocolo', 'correcao'];
        
        // ============= DETERMINAR STATUS =============
        // Se não enviou para revisão OU é prazo de sistema (revisao, protocolo), marca como concluído
        $novo_status = 'concluido';
        $acao_historico = 'conclusao';
        $observacao_historico = 'Marcou como concluído';
        
        // Se enviou para revisão E não é prazo de sistema
        if ($enviar_revisao === 'sim' && $revisor_id && !in_array($tipo_prazo, $tipos_sistema)) {
            $novo_status = 'aguardando_revisao';
            $acao_historico = 'aguardando_revisao';
            $observacao_historico = 'Enviou para revisão';
        }
        
        // Atualizar status do prazo
        $sql_update = "UPDATE prazos 
                       SET status = ?, 
                           data_conclusao = NOW(),
                           concluido_por = ?
                       WHERE id = ?";
        
        executeQuery($sql_update, [
            $novo_status,
            $usuario_logado['usuario_id'],
            $prazo_id
        ]);
        
        // Registrar no histórico
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        HistoricoHelper::registrar('prazo', $prazo_id, $acao_historico, [
            'observacao' => $observacao_historico
        ]);
        
        // Registrar no histórico do processo (se vinculado)
        if ($prazo['processo_id']) {
            $acao_processo = ($novo_status === 'aguardando_revisao') ? 'Prazo Enviado para Revisão' : 'Prazo Concluído';
            $descricao_processo = ($novo_status === 'aguardando_revisao') 
                ? "Prazo enviado para revisão: {$prazo['titulo']}"
                : "Prazo concluído: {$prazo['titulo']}";
                
            $sql_hist = "INSERT INTO processo_historico (
                            processo_id, usuario_id, acao, descricao, data_acao
                         ) VALUES (?, ?, ?, ?, NOW())";
            executeQuery($sql_hist, [
                $prazo['processo_id'],
                $usuario_logado['usuario_id'],
                $acao_processo,
                $descricao_processo
            ]);
        }
        
        $response = [
            'success' => true,
            'message' => 'Prazo concluído com sucesso!'
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
                            ) VALUES (?, 'prazo', ?, ?, ?, ?, NOW())";
            
            executeQuery($sql_revisao, [
                $prazo_id,
                $usuario_logado['usuario_id'],
                $revisor_id,
                $comentario_revisao,
                $arquivos_json
            ]);
            
            $revisao_id = $pdo->lastInsertId();
            
            // Criar prazo de REVISÃO para o revisor (mesmo dia)
            $sql_novo = "INSERT INTO prazos (
                            titulo, descricao, responsavel_id, data_vencimento,
                            prioridade, status, processo_id, criado_por, tipo_prazo,
                            prazo_origem_revisao_id, data_criacao
                         ) VALUES (?, ?, ?, CURDATE(), ?, 'pendente', ?, ?, 'revisao', ?, NOW())";
            
            $titulo_revisao = "REVISÃO: " . $prazo['titulo'];
            $descricao_revisao = "Prazo enviado para revisão.\n\n";
            if ($comentario_revisao) {
                $descricao_revisao .= "Comentário: " . $comentario_revisao;
            }
            
            executeQuery($sql_novo, [
                $titulo_revisao,
                $descricao_revisao,
                $revisor_id,
                $prazo['prioridade'] ?? 'media',
                $prazo['processo_id'],
                $usuario_logado['usuario_id'],
                $revisao_id
            ]);
            
            $novo_prazo_id = $pdo->lastInsertId();
            
            // Atualizar revisão com ID do prazo criado
            $sql_update_rev = "UPDATE tarefa_revisoes SET tarefa_revisao_id = ? WHERE id = ?";
            executeQuery($sql_update_rev, [$novo_prazo_id, $revisao_id]);
            
            // Registrar no histórico
            HistoricoHelper::registrar('prazo', $prazo_id, 'revisao_enviada', [
                'revisor_id' => $revisor_id,
                'comentario' => $comentario_revisao,
                'prazo_revisao_id' => $novo_prazo_id
            ]);
            
            // Buscar nome do revisor
            $sql_revisor = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_revisor = executeQuery($sql_revisor, [$revisor_id]);
            $revisor = $stmt_revisor->fetch();
            
            $response['message'] = 'Prazo enviado para revisão de ' . $revisor['nome'];
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