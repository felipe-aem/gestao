<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

header('Content-Type: application/json');

$view = $_GET['view'] ?? 'month';
$date = $_GET['date'] ?? date('Y-m-d');
$usuarios_filtro = $_GET['usuarios'] ?? '';

try {
    $data_atual = new DateTime($date);
    
    // Definir período baseado na view
    switch ($view) {
        case 'day':
            $data_inicio = $data_atual->format('Y-m-d 00:00:00');
            $data_fim = $data_atual->format('Y-m-d 23:59:59');
            break;
        case 'week':
            $inicio_semana = clone $data_atual;
            $inicio_semana->modify('monday this week');
            $fim_semana = clone $inicio_semana;
            $fim_semana->modify('+6 days');
            $data_inicio = $inicio_semana->format('Y-m-d 00:00:00');
            $data_fim = $fim_semana->format('Y-m-d 23:59:59');
            break;
        case 'month':
        default:
            $primeiro_dia = new DateTime($data_atual->format('Y-m-01'));
            $ultimo_dia = new DateTime($data_atual->format('Y-m-t'));
            $data_inicio = $primeiro_dia->format('Y-m-d 00:00:00');
            $data_fim = $ultimo_dia->format('Y-m-d 23:59:59');
            break;
    }
    
    // Construir query baseada em participação
    $where_conditions = ["a.data_inicio <= ? AND a.data_fim >= ?"];
    $params = [$data_fim, $data_inicio];
    
    if (!empty($usuarios_filtro)) {
        $usuarios_selecionados = explode(',', $usuarios_filtro);
        $placeholders = str_repeat('?,', count($usuarios_selecionados) - 1) . '?';
        
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM agenda_participantes ap 
            WHERE ap.agenda_id = a.id 
            AND ap.usuario_id IN ($placeholders)
            AND ap.status_participacao IN ('Organizador', 'Confirmado')
        )";
        
        $params = array_merge($params, $usuarios_selecionados);
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $sql = "SELECT a.*, 
            org.nome as organizador_nome,
            c.nome as cliente_nome
            FROM agenda a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            LEFT JOIN agenda_participantes ap_org ON a.id = ap_org.agenda_id AND ap_org.status_participacao = 'Organizador'
            LEFT JOIN usuarios org ON ap_org.usuario_id = org.id
            $where_clause
            ORDER BY a.data_inicio ASC";
    
    $stmt = executeQuery($sql, $params);
    $eventos = $stmt->fetchAll();
    
    // Formatar eventos para o frontend
    $eventos_formatados = [];
    foreach ($eventos as $evento) {
        $eventos_formatados[] = [
            'id' => $evento['id'],
            'title' => $evento['titulo'],
            'start' => $evento['data_inicio'],
            'end' => $evento['data_fim'],
            'tipo' => $evento['tipo'],
            'status' => $evento['status'],
            'prioridade' => $evento['prioridade'],
            'organizador' => $evento['organizador_nome'],
            'cliente' => $evento['cliente_nome'],
            'local' => $evento['local_evento'],
            'descricao' => $evento['descricao'],
            'className' => [
                'evento-' . strtolower(str_replace(' ', '-', $evento['tipo'])),
                'status-' . strtolower(str_replace(' ', '-', $evento['status'])),
                'prioridade-' . strtolower($evento['prioridade'])
            ],
            'extendedProps' => [
                'organizador_nome' => $evento['organizador_nome'],
                'cliente_nome' => $evento['cliente_nome'],
                'local_evento' => $evento['local_evento'],
                'observacoes' => $evento['observacoes'],
                'lembrete_minutos' => $evento['lembrete_minutos']
            ]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'eventos' => $eventos_formatados,
        'periodo' => [
            'inicio' => $data_inicio,
            'fim' => $data_fim
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Erro na API de eventos: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor'
    ]);
}
?>