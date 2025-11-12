<?php
/**
 * API de Eventos de um Dia Específico
 * Retorna todos os eventos de uma data, incluindo concluídos
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

$usuario_logado = Auth::user();

// Parâmetros
$data = $_GET['data'] ?? null;

if (!$data) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Data não informada'
    ]);
    exit;
}

try {
    $pdo = getConnection();
    
    // Cores por tipo
    $cores = [
        'tarefa' => '#ffc107',
        'prazo' => '#dc3545',
        'audiencia' => '#6f42c1',
        'atendimento' => '#fd7e14',
        'visita_comex' => '#17a2b8',
        'visita_tax' => '#e83e8c',
        'reuniao' => '#007bff'
    ];
    
    $eventos = [];
    
    // ========================================
    // TAREFAS
    // ========================================
    $sql = "SELECT 
            t.id,
            t.titulo,
            t.data_vencimento,
            t.status,
            t.descricao,
            t.responsavel_id,
            u.nome as responsavel_nome
            FROM tarefas t
            LEFT JOIN usuarios u ON t.responsavel_id = u.id
            WHERE t.deleted_at IS NULL
            AND DATE(t.data_vencimento) = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $concluido = $row['status'] === 'concluida';
        
        $eventos[] = [
            'id' => $row['id'],
            'tipo' => 'tarefa',
            'titulo' => $row['titulo'],
            'descricao' => $row['descricao'],
            'data' => $row['data_vencimento'],
            'status' => $row['status'],
            'responsavel' => $row['responsavel_nome'],
            'concluido' => $concluido,
            'cor' => $cores['tarefa']
        ];
    }
    
    // ========================================
    // PRAZOS
    // ========================================
    $sql = "SELECT 
            p.id,
            p.titulo,
            p.data_vencimento,
            p.status,
            p.descricao,
            p.responsavel_id,
            u.nome as responsavel_nome
            FROM prazos p
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            WHERE p.deleted_at IS NULL
            AND DATE(p.data_vencimento) = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $concluido = $row['status'] === 'concluido';
        
        $eventos[] = [
            'id' => $row['id'],
            'tipo' => 'prazo',
            'titulo' => $row['titulo'],
            'descricao' => $row['descricao'],
            'data' => $row['data_vencimento'],
            'status' => $row['status'],
            'responsavel' => $row['responsavel_nome'],
            'concluido' => $concluido,
            'cor' => $cores['prazo']
        ];
    }
    
    // ========================================
    // AUDIÊNCIAS
    // ========================================
    $sql = "SELECT 
            a.id,
            a.titulo,
            a.data_audiencia,
            a.status,
            a.local,
            a.responsavel_id,
            u.nome as responsavel_nome
            FROM audiencias a
            LEFT JOIN usuarios u ON a.responsavel_id = u.id
            WHERE a.deleted_at IS NULL
            AND DATE(a.data_audiencia) = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $concluido = $row['status'] === 'realizada';
        
        $eventos[] = [
            'id' => $row['id'],
            'tipo' => 'audiencia',
            'titulo' => $row['titulo'],
            'descricao' => $row['local'] ?? '',
            'data' => $row['data_audiencia'],
            'status' => $row['status'],
            'responsavel' => $row['responsavel_nome'],
            'concluido' => $concluido,
            'cor' => $cores['audiencia']
        ];
    }
    
    // ========================================
    // AGENDA (Atendimentos, Reuniões, Visitas)
    // ========================================
    $sql = "SELECT 
            a.id,
            a.titulo,
            a.data_inicio,
            a.data_fim,
            a.tipo,
            a.status,
            a.local,
            a.usuario_id,
            u.nome as usuario_nome
            FROM agenda a
            LEFT JOIN usuarios u ON a.usuario_id = u.id
            WHERE a.deleted_at IS NULL
            AND a.tarefa_id IS NULL
            AND a.prazo_id IS NULL
            AND a.audiencia_id IS NULL
            AND DATE(a.data_inicio) = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Determinar tipo
        $tipo = 'reuniao';
        if (stripos($row['tipo'], 'Atendimento') !== false) {
            $tipo = 'atendimento';
        } elseif (stripos($row['tipo'], 'COMEX') !== false) {
            $tipo = 'visita_comex';
        } elseif (stripos($row['tipo'], 'TAX') !== false) {
            $tipo = 'visita_tax';
        }
        
        $concluido = in_array(strtolower($row['status']), ['concluido', 'realizado']);
        
        $eventos[] = [
            'id' => $row['id'],
            'tipo' => $tipo,
            'titulo' => $row['titulo'],
            'descricao' => $row['local'] ?? '',
            'data' => $row['data_inicio'],
            'data_fim' => $row['data_fim'],
            'status' => $row['status'],
            'responsavel' => $row['usuario_nome'],
            'concluido' => $concluido,
            'cor' => $cores[$tipo]
        ];
    }
    
    // Ordenar eventos por horário
    usort($eventos, function($a, $b) {
        return strtotime($a['data']) - strtotime($b['data']);
    });
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'eventos' => $eventos,
        'total' => count($eventos)
    ]);
    
} catch (Exception $e) {
    error_log("Erro na API de eventos do dia: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao carregar eventos do dia'
    ]);
}
