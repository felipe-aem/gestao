<?php
/**
 * API de Resposta a Revisões - MODELO HIERÁRQUICO
 *
 * Endpoints para revisor aceitar ou recusar revisões
 * Migrado para usar RevisaoHelperHierarquico
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';
require_once '../../includes/RevisaoHelperHierarquico.php';
require_once '../../includes/DocumentoHelper.php';

try {
    $usuario_logado = Auth::user();
    $usuario_id = $usuario_logado['usuario_id'];

    // Parâmetros da requisição
    $tarefa_revisao_id = $_POST['revisao_id'] ?? $_POST['tarefa_id'] ?? 0;
    $acao = $_POST['acao'] ?? ''; // 'aceitar' ou 'recusar'
    $comentario = $_POST['comentario_revisor'] ?? $_POST['comentario'] ?? '';
    $observacao = $_POST['observacao'] ?? $comentario; // Para recusa

    if (!$tarefa_revisao_id) {
        throw new Exception('ID da tarefa/prazo de revisão não informado');
    }

    if (!in_array($acao, ['aceitar', 'recusar'])) {
        throw new Exception('Ação inválida. Use "aceitar" ou "recusar"');
    }

    // Buscar a tarefa/prazo de revisão
    $pdo = getConnection();

    // Tentar buscar como tarefa primeiro
    $sql = "SELECT * FROM tarefas WHERE id = ? AND tipo_fluxo = 'revisao' AND status = 'pendente' AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tarefa_revisao_id]);
    $item_revisao = $stmt->fetch(PDO::FETCH_ASSOC);
    $tipo = 'tarefa';

    // Se não encontrou, buscar como prazo
    if (!$item_revisao) {
        $sql = "SELECT * FROM prazos WHERE id = ? AND tipo_fluxo = 'revisao' AND status = 'pendente' AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tarefa_revisao_id]);
        $item_revisao = $stmt->fetch(PDO::FETCH_ASSOC);
        $tipo = 'prazo';
    }

    if (!$item_revisao) {
        throw new Exception('Item de revisão não encontrado ou já foi respondido');
    }

    // Verificar se o usuário é o revisor (responsável pela tarefa/prazo de revisão)
    if ($item_revisao['responsavel_id'] != $usuario_id) {
        throw new Exception('Você não tem permissão para responder esta revisão');
    }

    // Upload de arquivos (se houver)
    $arquivos_ids = [];
    if (!empty($_FILES['arquivos_revisor'])) {
        // Adaptar formato de múltiplos arquivos
        $files_array = $_FILES['arquivos_revisor'];

        // Reorganizar array de arquivos para formato esperado pelo DocumentoHelper
        $reorganized_files = [];
        if (is_array($files_array['name'])) {
            for ($i = 0; $i < count($files_array['name']); $i++) {
                if ($files_array['error'][$i] === UPLOAD_ERR_OK) {
                    $reorganized_files['arquivos']['name'][] = $files_array['name'][$i];
                    $reorganized_files['arquivos']['type'][] = $files_array['type'][$i];
                    $reorganized_files['arquivos']['tmp_name'][] = $files_array['tmp_name'][$i];
                    $reorganized_files['arquivos']['error'][] = $files_array['error'][$i];
                    $reorganized_files['arquivos']['size'][] = $files_array['size'][$i];
                }
            }

            if (!empty($reorganized_files['arquivos']['name'])) {
                try {
                    $categoria = ($acao === 'aceitar') ? 'revisao_aceita' : 'revisao_recusada';
                    $resultado_upload = DocumentoHelper::uploadMultiplos(
                        $reorganized_files,
                        $tipo,
                        $tarefa_revisao_id,
                        $categoria,
                        $usuario_id
                    );

                    foreach ($resultado_upload['uploads'] as $upload) {
                        $arquivos_ids[] = $upload['documento_id'];
                    }
                } catch (Exception $e) {
                    // Log do erro mas continua o processo
                    error_log("Erro no upload de arquivos: " . $e->getMessage());
                }
            }
        }
    }

    // Processar ação
    if ($acao === 'aceitar') {
        // ACEITAR REVISÃO
        $resultado = RevisaoHelperHierarquico::aceitarRevisao(
            $tipo,
            $tarefa_revisao_id,
            $usuario_id,
            $comentario,
            $arquivos_ids
        );

        if ($resultado['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Revisão aceita com sucesso! Tarefa de protocolo criada para o solicitante.',
                'item_protocolo_id' => $resultado['item_protocolo_id']
            ]);
        } else {
            throw new Exception($resultado['message']);
        }

    } else {
        // RECUSAR REVISÃO
        if (empty($observacao)) {
            throw new Exception('A observação é obrigatória ao recusar uma revisão');
        }

        $resultado = RevisaoHelperHierarquico::recusarRevisao(
            $tipo,
            $tarefa_revisao_id,
            $usuario_id,
            $observacao,
            $arquivos_ids
        );

        if ($resultado['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Revisão recusada. Tarefa de correção enviada para o solicitante.',
                'item_correcao_id' => $resultado['item_correcao_id']
            ]);
        } else {
            throw new Exception($resultado['message']);
        }
    }

} catch (Exception $e) {
    error_log("Erro em api_eventos.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
