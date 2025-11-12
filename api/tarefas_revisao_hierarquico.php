<?php
/**
 * API de Revisão de Tarefas - MODELO HIERÁRQUICO
 * Gerenciamento completo do fluxo de revisão usando tarefas FILHAS
 *
 * Endpoints:
 * POST /enviar_revisao - Enviar tarefa para revisão (cria tarefa filha)
 * POST /aceitar_revisao - Revisor aceita a revisão (cria tarefa de protocolo)
 * POST /recusar_revisao - Revisor recusa a revisão (cria tarefa de correção)
 * GET /pendentes_revisao - Listar tarefas de revisão pendentes para o usuário logado
 * GET /historico - Obter histórico completo de uma tarefa (pai + filhos)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/RevisaoHelperHierarquico.php';
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
            // ENVIAR PARA REVISÃO
            // ========================================
            case 'enviar_revisao':
                $tarefa_id = $data['tarefa_id'] ?? $_POST['tarefa_id'] ?? null;
                $revisor_id = $data['revisor_id'] ?? $_POST['revisor_id'] ?? null;
                $comentario = $data['comentario'] ?? $_POST['comentario'] ?? null;

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

                $resultado = RevisaoHelperHierarquico::enviarParaRevisao(
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
                $tarefa_revisao_id = $data['tarefa_id'] ?? $_POST['tarefa_id'] ?? null;
                $comentario = $data['comentario'] ?? $_POST['comentario'] ?? null;

                if (!$tarefa_revisao_id) {
                    throw new Exception('ID da tarefa de revisão não informado');
                }

                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_revisao_id,
                        'revisao_aceita',
                        $usuario_id
                    );

                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }

                $resultado = RevisaoHelperHierarquico::aceitarRevisao(
                    'tarefa',
                    $tarefa_revisao_id,
                    $usuario_id,
                    $comentario,
                    $arquivos_ids
                );

                echo json_encode($resultado);
                break;

            // ========================================
            // RECUSAR REVISÃO
            // ========================================
            case 'recusar_revisao':
                $tarefa_revisao_id = $data['tarefa_id'] ?? $_POST['tarefa_id'] ?? null;
                $observacao = $data['observacao'] ?? $_POST['observacao'] ?? null;

                if (!$tarefa_revisao_id || !$observacao) {
                    throw new Exception('Tarefa ID e observação são obrigatórios');
                }

                // Upload de arquivos (se houver)
                $arquivos_ids = [];
                if (!empty($_FILES['arquivos'])) {
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $_FILES['arquivos'],
                        'tarefa',
                        $tarefa_revisao_id,
                        'revisao_recusada',
                        $usuario_id
                    );

                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                }

                $resultado = RevisaoHelperHierarquico::recusarRevisao(
                    'tarefa',
                    $tarefa_revisao_id,
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
                $tarefas = RevisaoHelperHierarquico::listarRevisoesPendentes('tarefa', $usuario_id);

                // Adicionar documentos de cada tarefa
                foreach ($tarefas as &$tarefa) {
                    $tarefa['documentos'] = DocumentoHelper::listarDocumentos('tarefa', $tarefa['id'], 'revisao');
                }

                echo json_encode([
                    'success' => true,
                    'tarefas' => $tarefas,
                    'total' => count($tarefas)
                ]);
                break;

            // ========================================
            // HISTÓRICO COMPLETO DA TAREFA
            // ========================================
            case 'historico':
                $tarefa_id = $_GET['tarefa_id'] ?? null;

                if (!$tarefa_id) {
                    throw new Exception('ID da tarefa não informado');
                }

                $historico = RevisaoHelperHierarquico::listarHistoricoCompleto('tarefa', $tarefa_id);

                echo json_encode([
                    'success' => true,
                    'historico' => $historico,
                    'total' => count($historico)
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
