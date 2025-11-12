<?php
/**
 * Visualização em Lista da Agenda
 * Exibe todos os eventos em formato de lista agrupados por data
 */

// Construir query baseada nos filtros
$where_conditions = [];
$params = [];

// Filtro de responsável
if (!empty($filtro_responsavel)) {
    $where_conditions[] = "(
        t.responsavel_id = ? OR 
        p.responsavel_id = ? OR 
        aud.responsavel_id = ? OR
        a.usuario_id = ?
    )";
    $params[] = $filtro_responsavel;
    $params[] = $filtro_responsavel;
    $params[] = $filtro_responsavel;
    $params[] = $filtro_responsavel;
}

// Filtro de status
if (!empty($filtro_status)) {
    $where_conditions[] = "(
        t.status = ? OR 
        p.status = ? OR 
        aud.status = ? OR
        a.status = ?
    )";
    $params[] = $filtro_status;
    $params[] = $filtro_status;
    $params[] = $filtro_status;
    $params[] = $filtro_status;
}

// Determinar período
switch ($filtro_periodo) {
    case 'hoje':
        $data_inicio = date('Y-m-d 00:00:00');
        $data_fim = date('Y-m-d 23:59:59');
        break;
    case 'amanha':
        $data_inicio = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $data_fim = date('Y-m-d 23:59:59', strtotime('+1 day'));
        break;
    case 'esta_semana':
        $data_inicio = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $data_fim = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        break;
    case 'proxima_semana':
        $data_inicio = date('Y-m-d 00:00:00', strtotime('monday next week'));
        $data_fim = date('Y-m-d 23:59:59', strtotime('sunday next week'));
        break;
    case 'proximos_30':
        $data_inicio = date('Y-m-d 00:00:00');
        $data_fim = date('Y-m-d 23:59:59', strtotime('+30 days'));
        break;
    case 'este_mes':
        $data_inicio = date('Y-m-01 00:00:00');
        $data_fim = date('Y-m-t 23:59:59');
        break;
    case 'proximo_mes':
        $data_inicio = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $data_fim = date('Y-m-t 23:59:59', strtotime('last day of next month'));
        break;
    default:
        $data_inicio = null;
        $data_fim = null;
}

if ($data_inicio && $data_fim) {
    $where_conditions[] = "(
        (t.data_vencimento BETWEEN ? AND ?) OR
        (p.data_vencimento BETWEEN ? AND ?) OR
        (aud.data_audiencia BETWEEN ? AND ?) OR
        (a.data_inicio BETWEEN ? AND ?)
    )";
    $params[] = $data_inicio;
    $params[] = $data_fim;
    $params[] = $data_inicio;
    $params[] = $data_fim;
    $params[] = $data_inicio;
    $params[] = $data_fim;
    $params[] = $data_inicio;
    $params[] = $data_fim;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Query principal - UNION de todas as fontes
$sql = "
SELECT 
    'tarefa' as tipo,
    t.id,
    t.titulo,
    t.descricao,
    t.data_vencimento as data_evento,
    t.status,
    t.prioridade,
    u_resp.nome as responsavel_nome,
    proc.numero_processo,
    NULL as cliente_nome,
    t.revisor_id,
    u_rev.nome as revisor_nome
FROM tarefas t
LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
LEFT JOIN usuarios u_rev ON t.revisor_id = u_rev.id
LEFT JOIN processos proc ON t.processo_id = proc.id
WHERE t.deleted_at IS NULL

UNION ALL

SELECT 
    'prazo' as tipo,
    p.id,
    p.titulo,
    p.descricao,
    p.data_vencimento as data_evento,
    p.status,
    p.prioridade,
    u_resp.nome as responsavel_nome,
    proc.numero_processo,
    NULL as cliente_nome,
    p.revisor_id,
    u_rev.nome as revisor_nome
FROM prazos p
LEFT JOIN usuarios u_resp ON p.responsavel_id = u_resp.id
LEFT JOIN usuarios u_rev ON p.revisor_id = u_rev.id
LEFT JOIN processos proc ON p.processo_id = proc.id
WHERE p.deleted_at IS NULL

UNION ALL

SELECT 
    'audiencia' as tipo,
    aud.id,
    aud.titulo,
    aud.observacoes as descricao,
    aud.data_audiencia as data_evento,
    aud.status,
    aud.tipo as prioridade,
    u_resp.nome as responsavel_nome,
    proc.numero_processo,
    NULL as cliente_nome,
    NULL as revisor_id,
    NULL as revisor_nome
FROM audiencias aud
LEFT JOIN usuarios u_resp ON aud.responsavel_id = u_resp.id
LEFT JOIN processos proc ON aud.processo_id = proc.id
WHERE aud.deleted_at IS NULL

UNION ALL

SELECT 
    CASE 
        WHEN a.tipo LIKE '%Visita%' AND a.tipo LIKE '%COMEX%' THEN 'visita_comex'
        WHEN a.tipo LIKE '%Visita%' AND a.tipo LIKE '%TAX%' THEN 'visita_tax'
        WHEN a.tipo LIKE '%Atendimento%' THEN 'atendimento'
        ELSE 'reuniao'
    END as tipo,
    a.id,
    a.titulo,
    a.descricao,
    a.data_inicio as data_evento,
    a.status,
    a.prioridade,
    u.nome as responsavel_nome,
    NULL as numero_processo,
    c.nome as cliente_nome,
    NULL as revisor_id,
    NULL as revisor_nome
FROM agenda a
LEFT JOIN usuarios u ON a.usuario_id = u.id
LEFT JOIN clientes c ON a.cliente_id = c.id
WHERE a.deleted_at IS NULL
AND a.tarefa_id IS NULL 
AND a.prazo_id IS NULL 
AND a.audiencia_id IS NULL

ORDER BY data_evento ASC, tipo ASC
";

// Adicionar filtro de tipo se especificado
if (!empty($filtro_tipo)) {
    // Modificar query para filtrar apenas o tipo especificado
    // (implementação simplificada - pode ser refinada)
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar eventos por data
    $eventos_por_data = [];
    foreach ($eventos as $evento) {
        $data = date('Y-m-d', strtotime($evento['data_evento']));
        if (!isset($eventos_por_data[$data])) {
            $eventos_por_data[$data] = [];
        }
        $eventos_por_data[$data][] = $evento;
    }
    
} catch (Exception $e) {
    error_log("Erro ao buscar eventos: " . $e->getMessage());
    $eventos_por_data = [];
}

// Função auxiliar para determinar classe do badge
function getBadgeClass($tipo) {
    $classes = [
        'tarefa' => 'badge-tarefa',
        'prazo' => 'badge-prazo',
        'audiencia' => 'badge-audiencia',
        'atendimento' => 'badge-atendimento',
        'visita_comex' => 'badge-visita-comex',
        'visita_tax' => 'badge-visita-tax',
        'reuniao' => 'badge-reuniao'
    ];
    return $classes[$tipo] ?? 'badge-secondary';
}

// Função auxiliar para ícone
function getIcone($tipo) {
    $icones = [
        'tarefa' => 'fa-tasks',
        'prazo' => 'fa-clock',
        'audiencia' => 'fa-gavel',
        'atendimento' => 'fa-user',
        'visita_comex' => 'fa-briefcase',
        'visita_tax' => 'fa-calculator',
        'reuniao' => 'fa-calendar'
    ];
    return $icones[$tipo] ?? 'fa-calendar';
}

// Função auxiliar para label
function getLabel($tipo) {
    $labels = [
        'tarefa' => 'Tarefa',
        'prazo' => 'Prazo',
        'audiencia' => 'Audiência',
        'atendimento' => 'Atendimento',
        'visita_comex' => 'Visita COMEX',
        'visita_tax' => 'Visita TAX',
        'reuniao' => 'Reunião'
    ];
    return $labels[$tipo] ?? 'Evento';
}
?>

<div class="card">
    <div class="card-body">
        <?php if (empty($eventos_por_data)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum evento encontrado para os filtros selecionados.
            </div>
        <?php else: ?>
            <?php foreach ($eventos_por_data as $data => $eventos_do_dia): ?>
                <?php
                $data_obj = new DateTime($data);
                $hoje = new DateTime();
                $amanha = new DateTime('+1 day');
                
                // Determinar label da data
                if ($data_obj->format('Y-m-d') === $hoje->format('Y-m-d')) {
                    $data_label = 'Hoje';
                } elseif ($data_obj->format('Y-m-d') === $amanha->format('Y-m-d')) {
                    $data_label = 'Amanhã';
                } else {
                    $data_label = strftime('%A, %d de %B', $data_obj->getTimestamp());
                }
                ?>
                
                <!-- Cabeçalho do Dia -->
                <div class="mb-3">
                    <h5 class="text-primary border-bottom pb-2">
                        <i class="fas fa-calendar-day"></i> 
                        <?= ucfirst($data_label) ?>
                        <small class="text-muted">(<?= count($eventos_do_dia) ?> evento<?= count($eventos_do_dia) > 1 ? 's' : '' ?>)</small>
                    </h5>
                    
                    <!-- Lista de Eventos do Dia -->
                    <div class="list-group">
                        <?php foreach ($eventos_do_dia as $evento): ?>
                            <?php
                            $concluido = in_array($evento['status'], ['concluida', 'concluido', 'realizada']);
                            $url_visualizar = "visualizar/" . $evento['tipo'] . ".php?id=" . $evento['id'];
                            ?>
                            
                            <div class="list-group-item list-group-item-action <?= $concluido ? 'texto-concluido' : '' ?>">
                                <div class="d-flex w-100 justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <!-- Tipo e Título -->
                                        <h6 class="mb-1">
                                            <span class="badge <?= getBadgeClass($evento['tipo']) ?>">
                                                <i class="fas <?= getIcone($evento['tipo']) ?>"></i>
                                                <?= getLabel($evento['tipo']) ?>
                                            </span>
                                            
                                            <a href="<?= $url_visualizar ?>" target="_blank" class="text-dark">
                                                <?= htmlspecialchars($evento['titulo']) ?>
                                            </a>
                                            
                                            <?php if ($concluido): ?>
                                                <i class="fas fa-check-circle text-success" title="Concluído"></i>
                                            <?php endif; ?>
                                        </h6>
                                        
                                        <!-- Informações Adicionais -->
                                        <p class="mb-1 text-muted small">
                                            <?php if (!empty($evento['numero_processo'])): ?>
                                                <i class="fas fa-folder"></i> <?= htmlspecialchars($evento['numero_processo']) ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($evento['cliente_nome'])): ?>
                                                <i class="fas fa-user"></i> <?= htmlspecialchars($evento['cliente_nome']) ?>
                                            <?php endif; ?>
                                            
                                            <i class="fas fa-user-tie"></i> <?= htmlspecialchars($evento['responsavel_nome']) ?>
                                            
                                            <?php if (!empty($evento['revisor_nome'])): ?>
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-eye"></i> Revisor: <?= htmlspecialchars($evento['revisor_nome']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                        
                                        <!-- Descrição -->
                                        <?php if (!empty($evento['descricao'])): ?>
                                            <p class="mb-0 small text-muted">
                                                <?= nl2br(htmlspecialchars(substr($evento['descricao'], 0, 150))) ?>
                                                <?= strlen($evento['descricao']) > 150 ? '...' : '' ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Hora e Ações -->
                                    <div class="text-right ml-3">
                                        <div class="mb-2">
                                            <strong><?= date('H:i', strtotime($evento['data_evento'])) ?></strong>
                                        </div>
                                        
                                        <!-- Status Badge -->
                                        <?php
                                        $status_classes = [
                                            'pendente' => 'badge-secondary',
                                            'em_andamento' => 'badge-info',
                                            'concluida' => 'badge-success',
                                            'concluido' => 'badge-success',
                                            'cancelada' => 'badge-dark',
                                            'aguardando_revisao' => 'badge-warning'
                                        ];
                                        $status_class = $status_classes[$evento['status']] ?? 'badge-secondary';
                                        ?>
                                        <span class="badge <?= $status_class ?> d-block mb-2">
                                            <?= ucfirst(str_replace('_', ' ', $evento['status'])) ?>
                                        </span>
                                        
                                        <!-- Botões de Ação -->
                                        <div class="btn-group-vertical btn-group-sm">
                                            <a href="<?= $url_visualizar ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-primary" title="Visualizar">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if (!$concluido && in_array($evento['tipo'], ['tarefa', 'prazo'])): ?>
                                                <button class="btn btn-sm btn-outline-success" 
                                                        onclick="abrirModalConcluir('<?= $evento['tipo'] ?>', <?= $evento['id'] ?>)"
                                                        title="Concluir">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>