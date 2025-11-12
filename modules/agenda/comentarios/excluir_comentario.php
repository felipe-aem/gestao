<?php
/**
 * Excluir comentário + Histórico
 * VERSÃO SIMPLIFICADA - Registra diretamente na tabela correta
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Não autenticado');
    }
    
    require_once '../../../config/database.php';
    
    $usuario_id = $_SESSION['usuario_id'];
    $usuario_nome = $_SESSION['nome'] ?? 'Usuário';
    $comentario_id = $_POST['comentario_id'] ?? 0;
    
    if (empty($comentario_id)) {
        throw new Exception('ID não informado');
    }
    
    // Buscar dados do comentário
    $sql_check = "SELECT 
                    c.id, c.usuario_id, c.tipo_item, c.item_id,
                    c.comentario, c.mencoes, c.data_criacao,
                    u.nome as usuario_nome
                  FROM agenda_comentarios c
                  JOIN usuarios u ON c.usuario_id = u.id
                  WHERE c.id = ? AND c.deleted_at IS NULL";
    
    $stmt = executeQuery($sql_check, [$comentario_id]);
    $comentario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$comentario) {
        throw new Exception('Comentário não encontrado');
    }
    
    if ($comentario['usuario_id'] != $usuario_id) {
        throw new Exception('Sem permissão');
    }
    
    // Buscar anexos
    $sql_anexos = "SELECT nome_original FROM agenda_comentarios_anexos WHERE comentario_id = ?";
    $stmt_anexos = executeQuery($sql_anexos, [$comentario_id]);
    $anexos = $stmt_anexos->fetchAll(PDO::FETCH_COLUMN);
    
    // Buscar nomes dos mencionados
    $nomes_mencionados = [];
    if (!empty($comentario['mencoes'])) {
        $mencoes = json_decode($comentario['mencoes'], true);
        if (!empty($mencoes) && is_array($mencoes)) {
            $placeholders = implode(',', array_fill(0, count($mencoes), '?'));
            $sql_mencoes = "SELECT nome FROM usuarios WHERE id IN ($placeholders)";
            $stmt_mencoes = executeQuery($sql_mencoes, $mencoes);
            $nomes_mencionados = $stmt_mencoes->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    
    // Soft delete
    $sql_delete = "UPDATE agenda_comentarios SET deleted_at = NOW() WHERE id = ?";
    executeQuery($sql_delete, [$comentario_id]);
    
    // ===== REGISTRAR NO HISTÓRICO NA TABELA CORRETA =====
    $historico_registrado = false;
    
    try {
        // Preview do comentário
        $preview = mb_substr(strip_tags($comentario['comentario']), 0, 100);
        if (mb_strlen(strip_tags($comentario['comentario'])) > 100) {
            $preview .= '...';
        }
        
        // Montar observação
        $observacao = "Comentário excluído";
        $detalhes = [];
        
        if ($comentario['usuario_id'] != $usuario_id) {
            $detalhes[] = "Autor: {$comentario['usuario_nome']}";
        }
        
        $detalhes[] = "Conteúdo: \"{$preview}\"";
        
        if (!empty($anexos)) {
            $detalhes[] = count($anexos) . " anexo(s): " . implode(', ', $anexos);
        }
        
        if (!empty($nomes_mencionados)) {
            $detalhes[] = "Mencionava: " . implode(', ', $nomes_mencionados);
        }
        
        $data_comentario = date('d/m/Y H:i', strtotime($comentario['data_criacao']));
        $detalhes[] = "Postado em {$data_comentario}";
        
        if (!empty($detalhes)) {
            $observacao .= " | " . implode(' | ', $detalhes);
        }
        
        // Dados completos para o JSON
        $dados_json = [
            'comentario_id' => $comentario_id,
            'usuario_id' => $comentario['usuario_id'],
            'usuario_nome' => $comentario['usuario_nome'],
            'texto_completo' => strip_tags($comentario['comentario']),
            'data_criacao' => $comentario['data_criacao'],
            'mencoes' => $nomes_mencionados,
            'anexos' => $anexos
        ];
        
        // Determinar a tabela correta baseada no tipo_item
        $tipo_item = $comentario['tipo_item'];
        $item_id = $comentario['item_id'];
        
        switch ($tipo_item) {
            case 'tarefa':
                $tabela_historico = 'tarefas_historico';
                $campo_id = 'tarefa_id';
                break;
            case 'prazo':
                $tabela_historico = 'prazos_historico';
                $campo_id = 'prazo_id';
                break;
            case 'audiencia':
                $tabela_historico = 'audiencias_historico';
                $campo_id = 'audiencia_id';
                break;
            default:
                throw new Exception("Tipo de item inválido: {$tipo_item}");
        }
        
        // Inserir no histórico
        $sql_historico = "INSERT INTO {$tabela_historico} 
                          ({$campo_id}, usuario_id, tipo_acao, campo_alterado, 
                           valor_anterior, valor_novo, observacao, ip_address, data_hora) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        
        executeQuery($sql_historico, [
            $item_id,
            $usuario_id,
            'comentario_excluido',
            'comentario',
            json_encode($dados_json, JSON_UNESCAPED_UNICODE),
            null,
            $observacao,
            $ip_address
        ]);
        
        $historico_registrado = true;
        
    } catch (Exception $e) {
        error_log("Erro ao registrar no histórico: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Comentário excluído!',
        'historico_registrado' => $historico_registrado,
        'debug' => [
            'tipo_item' => $comentario['tipo_item'],
            'item_id' => $comentario['item_id']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao excluir comentário: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}