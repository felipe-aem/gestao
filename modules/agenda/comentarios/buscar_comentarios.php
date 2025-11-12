<?php
/**
 * Buscar comentários com anexos
 * VERSÃO COM PROCESSAMENTO DE MENÇÕES NO PHP
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Verificar autenticação
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Não autenticado');
    }
    
    require_once '../../../config/database.php';
    
    $tipo_item = $_GET['tipo_item'] ?? '';
    $item_id = $_GET['item_id'] ?? 0;
    
    // Validações
    if (!in_array($tipo_item, ['tarefa', 'prazo', 'audiencia'])) {
        throw new Exception('Tipo de item inválido');
    }
    
    if (empty($item_id)) {
        throw new Exception('ID do item não informado');
    }
    
    // Buscar comentários (apenas não deletados)
    $sql = "SELECT 
                c.id,
                c.comentario,
                c.data_criacao,
                c.usuario_id,
                c.mencoes,
                u.nome as usuario_nome,
                u.email as usuario_email
            FROM agenda_comentarios c
            JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.tipo_item = ? 
            AND c.item_id = ?
            AND c.deleted_at IS NULL
            ORDER BY c.data_criacao DESC";
    
    $stmt = executeQuery($sql, [$tipo_item, (int)$item_id]);
    $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Processar cada comentário
    foreach ($comentarios as &$comentario) {
        // Processar menções no PHP (converte @[id:nome] para HTML)
        $comentario['comentario_processado'] = processarMencoesHTML($comentario['comentario']);
        
        // Buscar anexos
        $sql_anexos = "SELECT 
                        id, 
                        nome_original, 
                        nome_arquivo,
                        tamanho_arquivo, 
                        tipo_arquivo, 
                        caminho_arquivo,
                        data_upload
                       FROM agenda_comentarios_anexos
                       WHERE comentario_id = ?
                       ORDER BY data_upload ASC";
        
        $stmt_anexos = executeQuery($sql_anexos, [$comentario['id']]);
        $comentario['anexos'] = $stmt_anexos->fetchAll(PDO::FETCH_ASSOC);
        
        // Decodificar menções (se existir)
        if (!empty($comentario['mencoes'])) {
            $comentario['mencoes_array'] = json_decode($comentario['mencoes'], true);
        } else {
            $comentario['mencoes_array'] = [];
        }
    }
    
    echo json_encode([
        'success' => true,
        'comentarios' => $comentarios,
        'total' => count($comentarios)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao buscar comentários: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Processar menções convertendo @[id:nome] para HTML com badge
 */
function processarMencoesHTML($texto) {
    // Primeiro, escapar HTML normal
    $texto = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    
    // Depois, converter menções para HTML
    $texto = preg_replace(
        '/@\[(\d+):([^\]]+)\]/',
        '<span class="mencao" data-usuario-id="$1" title="Ver perfil de $2">@$2</span>',
        $texto
    );
    
    return $texto;
}