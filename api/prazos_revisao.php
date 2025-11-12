<?php
/**
 * API de Revisão de Prazos
 * Gerenciamento completo do fluxo de revisão
 * 
 * Endpoints idênticos à API de tarefas:
 * POST /concluir - Concluir prazo (com ou sem revisão)
 * POST /enviar_revisao - Enviar prazo para revisão
 * POST /aceitar_revisao - Revisor aceita a revisão
 * POST /recusar_revisao - Revisor recusa a revisão
 * GET /pendentes_revisao - Listar prazos pendentes de revisão para o usuário logado
 * GET /historico - Obter histórico de um prazo
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
            // CONCLUIR PRAZO
            // ========================================
            case 'concluir':
                $prazo_id = $data['prazo_id'] ?? null;
                $precisa_revisao = $data['precisa_revisao'] ?? false;
                $comentario = $data['comentario'] ?? null;
                
                if (!$prazo_id) {
                    throw new Exception('ID do prazo não informado');
                }
                
                $pdo = getConnection();
                $pdo->beginTransaction();
                
                // Buscar prazo
                $sql = "SELECT * FROM prazos WHERE id = ? AND deleted_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$prazo_id]);
                $prazo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$prazo) {
                    throw new Exception('Prazo não encontrado');
                }
                
                // Verificar permissão
                $pode_concluir = false;
                
                // É responsável?
                if ($prazo['responsavel_id'] == $usuario_id) {
                    $pode_concluir = true;
                }
                
                // É envolvido com permissão?
                if (!$pode_concluir) {
                    $sql = "SELECT COUNT(*) as total FROM prazo_envolvidos WHERE prazo_id = ? AND usuario_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$prazo_id, $usuario_id]);
                    $envolvido = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($envolvido['total'] > 0) {
                        if (RevisaoHelper::podeConcluir($usuario_logado)) {
                            $pode_concluir = true;
                        }
                    }
                }
                
                if (!$pode_concluir) {
                    throw new Exception('Você não tem permissão para concluir este prazo');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'prazo',
                        $prazo_id,
                        'conclusao',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                // Se NÃO precisa revisão, apenas concluir
                if (!$precisa_revisao) {
                    $sql = "UPDATE prazos SET 
                            status = 'concluido',
                            data_conclusao = NOW(),
                            concluido_por = ?
                            WHERE id = ?";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$usuario_id, $prazo_id]);
                    
                    // Registrar no histórico
                    $sql = "INSERT INTO prazo_historico (prazo_id, usuario_id, acao, detalhes, data_acao)
                            VALUES (?, ?, 'concluido', ?, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$prazo_id, $usuario_id, json_encode(['arquivos' => $arquivos_ids])]);
                    
                    // Remover da agenda
                    $sql = "UPDATE agenda SET texto_concluido = 1 WHERE prazo_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$prazo_id]);
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Prazo concluído com sucesso'
                    ]);
                } else {
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true,
                        'precisa_revisao' => true,
                        'message' => 'Prazo pronto para enviar para revisão'
                    ]);
                }
                break;
            
            // ========================================
            // ENVIAR PARA REVISÃO
            // ========================================
            case 'enviar_revisao':
                $prazo_id = $data['prazo_id'] ?? null;
                $revisor_id = $data['revisor_id'] ?? null;
                $comentario = $data['comentario'] ?? null;
                
                if (!$prazo_id || !$revisor_id) {
                    throw new Exception('Parâmetros obrigatórios ausentes');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'prazo',
                        $prazo_id,
                        'revisao',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::enviarParaRevisao(
                    'prazo',
                    $prazo_id,
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
                $prazo_id = $data['prazo_id'] ?? null;
                
                if (!$prazo_id) {
                    throw new Exception('ID do prazo não informado');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'prazo',
                        $prazo_id,
                        'revisao_aceita',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::aceitarRevisao(
                    'prazo',
                    $prazo_id,
                    $usuario_id,
                    $arquivos_ids
                );
                
                echo json_encode($resultado);
                break;
            
            // ========================================
            // RECUSAR REVISÃO
            // ========================================
            case 'recusar_revisao':
                $prazo_id = $data['prazo_id'] ?? null;
                $observacao = $data['observacao'] ?? null;
                
                if (!$prazo_id || !$observacao) {
                    throw new Exception('Prazo ID e observação são obrigatórios');
                }
                
                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'prazo',
                        $prazo_id,
                        'revisao_recusada',
                        $usuario_id
                    );
                    
                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }
                
                $resultado = RevisaoHelper::recusarRevisao(
                    'prazo',
                    $prazo_id,
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
                
                $sql = "SELECT p.*, 
                        u_resp.nome as responsavel_nome,
                        proc.numero_processo
                        FROM prazos p
                        LEFT JOIN usuarios u_resp ON p.responsavel_id = u_resp.id
                        LEFT JOIN processos proc ON p.processo_id = proc.id
                        WHERE p.revisor_id = ?
                        AND p.status = 'aguardando_revisao'
                        AND p.deleted_at IS NULL
                        ORDER BY p.data_envio_revisao ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$usuario_id]);
                $prazos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Adicionar documentos de cada prazo
                foreach ($prazos as &$prazo) {
                    $prazo['documentos'] = DocumentoHelper::listarDocumentos('prazo', $prazo['id'], 'revisao');
                    $prazo['prazo_fatal_formatado'] = date('d/m/Y H:i', strtotime($prazo['prazo_fatal_original']));
                }
                
                echo json_encode([
                    'success' => true,
                    'prazos' => $prazos,
                    'total' => count($prazos)
                ]);
                break;
            
            // ========================================
            // HISTÓRICO DO PRAZO
            // ========================================
            case 'historico':
                $prazo_id = $_GET['prazo_id'] ?? null;
                
                if (!$prazo_id) {
                    throw new Exception('ID do prazo não informado');
                }
                
                $pdo = getConnection();
                
                $sql = "SELECT h.*, u.nome as usuario_nome
                        FROM prazo_historico h
                        LEFT JOIN usuarios u ON h.usuario_id = u.id
                        WHERE h.prazo_id = ?
                        ORDER BY h.data_acao ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$prazo_id]);
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