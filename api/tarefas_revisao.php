<?php
/**
 * API de Revisão de Tarefas
 * Gerenciamento completo do fluxo de revisão
 * 
 * Endpoints:
 * POST /concluir - Concluir tarefa (com ou sem revisão)
 * POST /enviar_revisao - Enviar tarefa para revisão
 * POST /aceitar_revisao - Revisor aceita a revisão
 * POST /recusar_revisao - Revisor recusa a revisão
 * GET /pendentes_revisao - Listar tarefas pendentes de revisão para o usuário logado
 * GET /historico - Obter histórico de uma tarefa
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/RevisaoHelper.php';
require_once __DIR__ . '/../includes/DocumentoHelper.php';

// Verificar autenticação
Auth::protect();
$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Determinar método HTTP
$method = $_SERVER['REQUEST_METHOD'];

try {
    
    // ========================================
    // POST - Ações de revisão
    // ========================================
    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        $action = $data['action'] ?? $_POST['action'] ?? null;
        
        if (!$action) {
            throw new Exception('Action não especificada');
        }
        
        switch ($action) {
            
            // ========================================
            // CONCLUIR TAREFA
            // ========================================
            case 'concluir':
                $tarefa_id = $data['tarefa_id'] ?? null;
                $precisa_revisao = $data['precisa_revisao'] ?? false;
                $comentario = $data['comentario'] ?? null;
                
                if (!$tarefa_id) {
                    throw new Exception('ID da tarefa não informado');
                }
                
                $pdo = getConnection();
                $pdo->beginTransaction();
                
                // Buscar tarefa
                $sql = "SELECT * FROM tarefas WHERE id = ? AND deleted_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tarefa_id]);
                $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$tarefa) {
                    throw new Exception('Tarefa não encontrada');
                }
                
                // Verificar permissão
                $pode_concluir = false;
                
                // É responsável?
                if ($tarefa['responsavel_id'] == $usuario_id) {
                    $pode_concluir = true;
                }
                
                // É envolvido com permissão?
                if (!$pode_concluir) {
                    $sql = "SELECT COUNT(*) as total FROM tarefa_envolvidos WHERE tarefa_id = ? AND usuario_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$tarefa_id, $usuario_id]);
                    $envolvido = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($envolvido['total'] > 0) {
                        // Verificar nível de acesso
                        if (RevisaoHelper::podeConcluir($usuario_logado)) {
                            $pode_concluir = true;
                        }
                    }
                }
                
                if (!$pode_concluir) {
                    throw new Exception('Você não tem permissão para concluir esta tarefa');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_id,
                        'conclusao',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                // Se NÃO precisa revisão, apenas concluir
                if (!$precisa_revisao) {
                    $sql = "UPDATE tarefas SET 
                            status = 'concluida',
                            data_conclusao = NOW(),
                            concluido_por = ?
                            WHERE id = ?";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$usuario_id, $tarefa_id]);
                    
                    // Registrar no histórico
                    $sql = "INSERT INTO tarefa_historico (tarefa_id, usuario_id, acao, detalhes, data_acao)
                            VALUES (?, ?, 'concluida', ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$tarefa_id, $usuario_id, json_encode(['arquivos' => $arquivos_ids])]);
                    
                    // Remover da agenda
                    $sql = "UPDATE agenda SET texto_concluido = 1 WHERE tarefa_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$tarefa_id]);
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Tarefa concluída com sucesso'
                    ]);
                } else {
                    // Precisa revisão - apenas atualizar para "em_andamento" e retornar sucesso
                    // O próximo passo será enviar para revisão via outro endpoint
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true,
                        'precisa_revisao' => true,
                        'message' => 'Tarefa pronta para enviar para revisão'
                    ]);
                }
                break;
            
            // ========================================
            // ENVIAR PARA REVISÃO
            // ========================================
            case 'enviar_revisao':
                $tarefa_id = $data['tarefa_id'] ?? null;
                $revisor_id = $data['revisor_id'] ?? null;
                $comentario = $data['comentario'] ?? null;
                
                if (!$tarefa_id || !$revisor_id) {
                    throw new Exception('Parâmetros obrigatórios ausentes');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_id,
                        'revisao',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::enviarParaRevisao(
                    'tarefa',
                    $tarefa_id,
                    $revisor_id,
                    $usuario_id,
                    $comentario,
                    $arquivos_ids
                );
                
                echo json_encode($resultado);
                break;
            
            // ========================================
            // ACEITAR REVISÃO
            // ========================================
            case 'aceitar_revisao':
                $tarefa_id = $data['tarefa_id'] ?? null;
                
                if (!$tarefa_id) {
                    throw new Exception('ID da tarefa não informado');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_id,
                        'revisao_aceita',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::aceitarRevisao(
                    'tarefa',
                    $tarefa_id,
                    $usuario_id,
                    $arquivos_ids
                );
                
                echo json_encode($resultado);
                break;
            
            // ========================================
            // RECUSAR REVISÃO
            // ========================================
            case 'recusar_revisao':
                $tarefa_id = $data['tarefa_id'] ?? null;
                $observacao = $data['observacao'] ?? null;
                
                if (!$tarefa_id || !$observacao) {
                    throw new Exception('Tarefa ID e observação são obrigatórios');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_id,
                        'revisao_recusada',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::recusarRevisao(
                    'tarefa',
                    $tarefa_id,
                    $usuario_id,
                    $observacao,
                    $arquivos_ids
                );
                
                echo json_encode($resultado);
                break;
            
            default:
                throw new Exception('Action inválida');
        }
    }
    
    // ========================================
    // GET - Consultas
    // ========================================
    else if ($method === 'GET') {
        
        $action = $_GET['action'] ?? null;
        
        if (!$action) {
            throw new Exception('Action não especificada');
        }
        
        switch ($action) {
            
            // ========================================
            // LISTAR PENDENTES DE REVISÃO
            // ========================================
            case 'pendentes_revisao':
                $pdo = getConnection();
                
                $sql = "SELECT t.*, 
                        u_resp.nome as responsavel_nome,
                        p.numero_processo
                        FROM tarefas t
                        LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
                        LEFT JOIN processos p ON t.processo_id = p.id
                        WHERE t.revisor_id = ?
                        AND t.status = 'aguardando_revisao'
                        AND t.deleted_at IS NULL
                        ORDER BY t.data_envio_revisao ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$usuario_id]);
                $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Adicionar documentos de cada tarefa
                foreach ($tarefas as &$tarefa) {
                    $tarefa['documentos'] = DocumentoHelper::listarDocumentos('tarefa', $tarefa['id'], 'revisao');
                    $tarefa['prazo_fatal_formatado'] = date('d/m/Y H:i', strtotime($tarefa['prazo_fatal_original']));
                }
                
                echo json_encode([
                    'success' => true,
                    'tarefas' => $tarefas,
                    'total' => count($tarefas)
                ]);
                break;
            
            // ========================================
            // HISTÓRICO DA TAREFA
            // ========================================
            case 'historico':
                $tarefa_id = $_GET['tarefa_id'] ?? null;
                
                if (!$tarefa_id) {
                    throw new Exception('ID da tarefa não informado');
                }
                
                $pdo = getConnection();
                
                $sql = "SELECT h.*, u.nome as usuario_nome
                        FROM tarefa_historico h
                        LEFT JOIN usuarios u ON h.usuario_id = u.id
                        WHERE h.tarefa_id = ?
                        ORDER BY h.data_acao ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$tarefa_id]);
                $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Formatar datas e detalhes
                foreach ($historico as &$item) {
                    $item['data_acao_formatada'] = date('d/m/Y H:i:s', strtotime($item['data_acao']));
                    if ($item['detalhes']) {
                        $item['detalhes_json'] = json_decode($item['detalhes'], true);
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'historico' => $historico
                ]);
                break;
            
            default:
                throw new Exception('Action inválida');
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}