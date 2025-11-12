<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    $revisao_id = $_GET['revisao_id'] ?? 0;
    
    if (!$revisao_id) {
        throw new Exception('ID da revisão não informado');
    }
    
    // Buscar dados completos da revisão
    $sql = "SELECT 
                tr.*,
                u_solicitante.nome as solicitante_nome,
                u_revisor.nome as revisor_nome,
                CASE 
                    WHEN tr.tipo_origem = 'tarefa' THEN t.titulo 
                    ELSE p.titulo 
                END as titulo_origem,
                CASE 
                    WHEN tr.tipo_origem = 'tarefa' THEN t.descricao 
                    ELSE p.descricao 
                END as descricao_origem,
                CASE 
                    WHEN tr.tipo_origem = 'tarefa' THEN proc.numero_processo 
                    ELSE proc2.numero_processo 
                END as numero_processo
            FROM tarefa_revisoes tr
            INNER JOIN usuarios u_solicitante ON tr.usuario_solicitante_id = u_solicitante.id
            INNER JOIN usuarios u_revisor ON tr.usuario_revisor_id = u_revisor.id
            LEFT JOIN tarefas t ON tr.tarefa_origem_id = t.id AND tr.tipo_origem = 'tarefa'
            LEFT JOIN prazos p ON tr.tarefa_origem_id = p.id AND tr.tipo_origem = 'prazo'
            LEFT JOIN processos proc ON t.processo_id = proc.id
            LEFT JOIN processos proc2 ON p.processo_id = proc2.id
            WHERE tr.id = ?";
    
    $stmt = executeQuery($sql, [$revisao_id]);
    $revisao = $stmt->fetch();
    
    if (!$revisao) {
        throw new Exception('Revisão não encontrada');
    }
    
    // Verificar permissão
    if ($revisao['usuario_revisor_id'] != $usuario_logado['usuario_id']) {
        throw new Exception('Você não tem permissão para visualizar esta revisão');
    }
    
    // Decodificar arquivos JSON
    $revisao['arquivos_solicitante_array'] = [];
    if ($revisao['arquivos_solicitante']) {
        $revisao['arquivos_solicitante_array'] = json_decode($revisao['arquivos_solicitante'], true) ?: [];
    }
    
    $revisao['arquivos_revisor_array'] = [];
    if ($revisao['arquivos_revisor']) {
        $revisao['arquivos_revisor_array'] = json_decode($revisao['arquivos_revisor'], true) ?: [];
    }
    
    echo json_encode([
        'success' => true,
        'revisao' => $revisao
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}