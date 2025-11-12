<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'] ?? $_SESSION['usuario_id'] ?? null;

// ===== NOVO: Obter módulo da URL =====
$modulo_codigo = $_GET['modulo'] ?? 'ADVOCACIA';

// Validar módulo
try {
    $sql_modulo = "SELECT * FROM prospeccao_modulos WHERE codigo = ? AND ativo = 1";
    $stmt_modulo = executeQuery($sql_modulo, [$modulo_codigo]);
    $modulo_atual = $stmt_modulo->fetch();
    
    if (!$modulo_atual) {
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Erro ao buscar módulo: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

// Buscar fases do módulo
try {
    $sql_fases = "SELECT * FROM prospeccao_fases_modulos 
                  WHERE modulo_codigo = ? AND ativo = 1 
                  ORDER BY ordem ASC";
    $stmt_fases = executeQuery($sql_fases, [$modulo_codigo]);
    $fases_modulo = $stmt_fases->fetchAll();
    
    // Criar array de fases para agrupamento e mapeamento de cores
    $fases_disponiveis = [];
    $cores_fases = [];
    $icones_fases = [];
    foreach ($fases_modulo as $fase_cfg) {
        $fases_disponiveis[] = $fase_cfg['fase'];
        $cores_fases[$fase_cfg['fase']] = $fase_cfg['cor'];
        $icones_fases[$fase_cfg['fase']] = $fase_cfg['icone'];
    }
} catch (Exception $e) {
    error_log("Erro ao buscar fases: " . $e->getMessage());
    $fases_disponiveis = ['Prospecção', 'Negociação', 'Fechados', 'Perdidos', 'Inviáveis'];
    $cores_fases = [
        'Prospecção' => '#3498db',
        'Negociação' => '#f39c12',
        'Fechados' => '#27ae60',
        'Perdidos' => '#e74c3c',
        'Inviáveis' => '#95a5a6'
    ];
    $icones_fases = [
        'Prospecção' => 'fas fa-search',
        'Negociação' => 'fas fa-handshake',
        'Fechados' => 'fas fa-check-circle',
        'Perdidos' => 'fas fa-times-circle',
        'Inviáveis' => 'fas fa-ban'
    ];
}
// ===== FIM NOVO =====


// --- PERMISSÕES ---
$pode_criar_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']);

// --- OBTER FILTROS DA URL ---
$busca = $_GET['busca'] ?? '';

// Filtros multi-seleção
$nucleos_filtro = $_GET['nucleos'] ?? [];
if (!is_array($nucleos_filtro)) {
    $nucleos_filtro = $nucleos_filtro === '' ? [] : [$nucleos_filtro];
}

$responsaveis_filtro = $_GET['responsaveis'] ?? [];
if (!is_array($responsaveis_filtro)) {
    $responsaveis_filtro = $responsaveis_filtro === '' ? [] : [$responsaveis_filtro];
}

$fases_filtro = $_GET['fases'] ?? [];
if (!is_array($fases_filtro)) {
    $fases_filtro = $fases_filtro === '' ? [] : [$fases_filtro];
}

$meio = $_GET['meio'] ?? '';

// Filtros de período
$periodo = $_GET['periodo'] ?? '';
$comparar = $_GET['comparar'] ?? '0';

// ===== FILTRO DE DATA PADRÃO: ÚLTIMA SEMANA =====
// Se não houver filtro de data na URL, definir padrão de 7 dias
$definir_data_padrao = !isset($_GET['data_inicio']) && !isset($_GET['data_fim']) && empty($periodo);

// Calcular datas baseado no período
if ($periodo === 'custom') {
    $data_inicio = $_GET['data_inicio'] ?? '';
    $data_fim = $_GET['data_fim'] ?? '';
} else if (!empty($periodo)) {
    $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
    $data_fim = date('Y-m-d');
} else {
    // Se não há filtro, usar últimos 7 dias como padrão
    if ($definir_data_padrao) {
        $data_inicio = date('Y-m-d', strtotime('-7 days'));
        $data_fim = date('Y-m-d');
    } else {
        $data_inicio = $_GET['data_inicio'] ?? '';
        $data_fim = $_GET['data_fim'] ?? '';
    }
}

// Filtros de valor
$valor_min = $_GET['valor_min'] ?? '';
$valor_max = $_GET['valor_max'] ?? '';

// Filtro de dias na fase
$dias_fase_min = $_GET['dias_fase_min'] ?? '';
$dias_fase_max = $_GET['dias_fase_max'] ?? '';

// --- BUSCAR NÚCLEOS DISPONÍVEIS ---
try {
    $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_nucleos = executeQuery($sql_nucleos);
    $nucleos = $stmt_nucleos->fetchAll();
} catch (Exception $e) {
    $nucleos = [];
}

// --- BUSCAR USUÁRIOS DISPONÍVEIS ---
try {
    $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_usuarios = executeQuery($sql_usuarios);
    $usuarios = $stmt_usuarios->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// --- BUSCAR ESTATÍSTICAS GERAIS (SEM FILTROS) ---
try {
    $stats_fields = "COUNT(*) as total";
    // Adicionar contagem dinâmica para cada fase do módulo
    foreach ($fases_disponiveis as $fase) {
        $fase_slug = strtolower(str_replace(' ', '_', $fase));
        $stats_fields .= ", SUM(CASE WHEN fase = '{$fase}' THEN 1 ELSE 0 END) as fase_{$fase_slug}";
    }
    $stats_fields .= ", SUM(CASE WHEN meio = 'Online' THEN 1 ELSE 0 END) as meio_online";
    $stats_fields .= ", SUM(CASE WHEN meio = 'Presencial' THEN 1 ELSE 0 END) as meio_presencial";
    $stats_fields .= ", SUM(COALESCE(valor_proposta, 0)) as valor_proposta_total";
    $stats_fields .= ", SUM(COALESCE(valor_fechado, 0)) as valor_fechado_total";
    
    $stats_sql = "SELECT $stats_fields FROM prospeccoes WHERE ativo = 1 AND modulo_codigo = ? AND fase NOT IN ('Inviáveis', 'Inviável')";
    $params_stats = [$modulo_codigo];
    $stmt_stats = executeQuery($stats_sql, $params_stats);
    $stats = $stmt_stats->fetch();
    
    $total_finalizados = $stats['fase_fechados'] + $stats['fase_perdidos'];
    $stats['taxa_conversao'] = $total_finalizados > 0 ? round(($stats['fase_fechados'] / $total_finalizados) * 100, 1) : 0;
    $stats['taxa_perda'] = $total_finalizados > 0 ? round(($stats['fase_perdidos'] / $total_finalizados) * 100, 1) : 0;
    
} catch (Exception $e) {
    $stats = [
        'total' => 0, 'fase_prospeccao' => 0, 'fase_negociacao' => 0,
        'fase_fechados' => 0, 'fase_perdidos' => 0, 'meio_online' => 0,
        'meio_presencial' => 0, 'valor_proposta_total' => 0, 'valor_fechado_total' => 0,
        'taxa_conversao' => 0, 'taxa_perda' => 0
    ];
}

// --- CONSTRUIR QUERY COM FILTROS ---
$where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
$params = [$modulo_codigo];  // Inicializar com o módulo

if (!empty($busca)) {
    $where_conditions[] = "(p.nome LIKE ? OR p.telefone LIKE ? OR p.cidade LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if (!empty($nucleos_filtro)) {
    $placeholders = implode(',', array_fill(0, count($nucleos_filtro), '?'));
    $where_conditions[] = "p.nucleo_id IN ({$placeholders})";
    $params = array_merge($params, $nucleos_filtro);
}

if (!empty($responsaveis_filtro)) {
    $placeholders = implode(',', array_fill(0, count($responsaveis_filtro), '?'));
    $where_conditions[] = "p.responsavel_id IN ({$placeholders})";
    $params = array_merge($params, $responsaveis_filtro);
}

if (!empty($fases_filtro)) {
    $placeholders = implode(',', array_fill(0, count($fases_filtro), '?'));
    $where_conditions[] = "p.fase IN ({$placeholders})";
    $params = array_merge($params, $fases_filtro);
}

if (!empty($meio)) {
    $where_conditions[] = "p.meio = ?";
    $params[] = $meio;
}

if (!empty($data_inicio)) {
    $where_conditions[] = "DATE(p.data_cadastro) >= ?";
    $params[] = $data_inicio;
}

if (!empty($data_fim)) {
    $where_conditions[] = "DATE(p.data_cadastro) <= ?";
    $params[] = $data_fim;
}

if (!empty($valor_min)) {
    $valor_min_num = str_replace(['.', ','], ['', '.'], $valor_min);
    $where_conditions[] = "(COALESCE(p.valor_proposta, 0) >= ? OR COALESCE(p.valor_fechado, 0) >= ?)";
    $params[] = $valor_min_num;
    $params[] = $valor_min_num;
}

if (!empty($valor_max)) {
    $valor_max_num = str_replace(['.', ','], ['', '.'], $valor_max);
    $where_conditions[] = "(COALESCE(p.valor_proposta, 0) <= ? OR COALESCE(p.valor_fechado, 0) <= ?)";
    $params[] = $valor_max_num;
    $params[] = $valor_max_num;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// ===== AUTO-ARQUIVAMENTO: Fases finais aparecem apenas por 1 semana =====
// Para Fechados, Perdidos e Inviáveis, mostrar apenas se foram movidos há menos de 7 dias
// A menos que o usuário tenha aplicado filtro de data personalizado
if (empty($data_inicio) || $definir_data_padrao) {
    $where_clause .= " AND (
        p.fase NOT IN ('Fechados', 'Perdidos', 'Inviáveis', 'Inviável')
        OR p.data_fase_final IS NULL
        OR p.data_fase_final >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
    )";
}

try {
    $sql = "SELECT p.*, 
                   n.nome as nucleo_nome,
                   u.nome as responsavel_nome,
                   uc.nome as criado_por_nome,
                   DATEDIFF(CURRENT_DATE, p.data_ultima_atualizacao) as dias_na_fase,
                   DATEDIFF(CURRENT_DATE, p.data_cadastro) as dias_total
            FROM prospeccoes p
            LEFT JOIN nucleos n ON p.nucleo_id = n.id
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            LEFT JOIN usuarios uc ON p.criado_por = uc.id
            $where_clause
            ORDER BY 
                CASE p.fase
                    WHEN 'Prospecção' THEN 1
                    WHEN 'Negociação' THEN 2
                    WHEN 'Fechados' THEN 3
                    WHEN 'Perdidos' THEN 4
                    WHEN 'Inviáveis' THEN 5
                    WHEN 'Inviável' THEN 5
                    ELSE 6
                END,
                p.data_ultima_atualizacao DESC";
    
    $stmt = executeQuery($sql, $params);
    $prospectos = $stmt->fetchAll();
    
    // Filtrar por dias na fase (pós-query porque usa campo calculado)
    if (!empty($dias_fase_min) || !empty($dias_fase_max)) {
        $prospectos = array_filter($prospectos, function($p) use ($dias_fase_min, $dias_fase_max) {
            $dias = $p['dias_na_fase'];
            $min_ok = empty($dias_fase_min) || $dias >= $dias_fase_min;
            $max_ok = empty($dias_fase_max) || $dias <= $dias_fase_max;
            return $min_ok && $max_ok;
        });
    }
    
    // Agrupar por fase (dinâmico baseado no módulo)
    $prospectos_por_fase = [];
    foreach ($fases_disponiveis as $fase) {
        $prospectos_por_fase[$fase] = [];
    }
    
    foreach ($prospectos as $prospecto) {
        if (isset($prospectos_por_fase[$prospecto['fase']])) {
            $prospectos_por_fase[$prospecto['fase']][] = $prospecto;
        }
    }
    
    // Contar resultados filtrados
    $total_filtrado = count($prospectos);
    
} catch (Exception $e) {
    error_log("Erro na consulta: " . $e->getMessage());
    
    // Mostrar erro detalhado para debug
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Erro no Kanban</title>";
    echo "<style>body{font-family:monospace;padding:40px;background:#1e1e1e;color:#fff;}";
    echo ".error-box{background:#d32f2f;padding:30px;border-radius:10px;margin:20px 0;}";
    echo "h1{color:#ff5252;}h2{color:#ffab91;margin-top:30px;}";
    echo "pre{background:#2d2d2d;padding:20px;border-radius:5px;overflow-x:auto;border-left:4px solid #ff5252;}";
    echo "</style></head><body>";
    echo "<h1>🔴 ERRO DETALHADO NO KANBAN</h1>";
    echo "<div class='error-box'>";
    echo "<h2>Mensagem do Erro:</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<h2>Arquivo:</h2>";
    echo "<pre>" . htmlspecialchars($e->getFile()) . "</pre>";
    echo "<h2>Linha:</h2>";
    echo "<pre>" . $e->getLine() . "</pre>";
    echo "<h2>Stack Trace:</h2>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    echo "<h2>🔍 Informações de Debug:</h2>";
    echo "<pre>Módulo: " . htmlspecialchars($modulo_codigo ?? 'NÃO DEFINIDO') . "</pre>";
    echo "<pre>Usuário: " . htmlspecialchars($usuario_id ?? 'NÃO DEFINIDO') . "</pre>";
    echo "</body></html>";
    die();
}

ob_start();
?>

<style>
    /* Header Sticky */
    .page-header {
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        top: 0;
        z-index: 100;
    }

    .header-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 25px;
    }

    .header-title h1 {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
        transition: all 0.3s ease;
    }

    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }

    .btn-secondary {
        background: #e0e0e0;
        color: #2c3e50;
        padding: 12px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .btn-secondary:hover {
        background: #d0d0d0;
    }

    /* Stats Container */
    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
        transition: all 0.3s ease;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        border-left: 4px solid;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }

    .stat-card.prospeccao { border-left-color: #3498db; }
    .stat-card.negociacao { border-left-color: #f39c12; }
    .stat-card.fechados { border-left-color: #27ae60; }
    .stat-card.perdidos { border-left-color: #e74c3c; }

    .stat-card .stat-label {
        font-size: 13px;
        color: #7f8c8d;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .stat-card .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #2c3e50;
    }

    .stat-card .stat-detail {
        font-size: 12px;
        color: #95a5a6;
        margin-top: 8px;
    }

    /* Filtros Compactos */
    .filters-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .filters-title {
        font-size: 14px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filters-compact-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px;
        margin-bottom: 12px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .filter-group label {
        font-size: 11px;
        font-weight: 600;
        color: #2c3e50;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 8px 10px;
        border: 2px solid #e0e0e0;
        border-radius: 6px;
        font-size: 13px;
        transition: all 0.3s ease;
        background: white;
    }

    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    /* Multi-select CORRIGIDO */
    .custom-multiselect {
        position: relative;
        width: 100%;
    }
    
    .multiselect-button {
        width: 100%;
        padding: 8px 10px;
        border: 2px solid #e0e0e0;
        border-radius: 6px;
        background: white;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
        transition: all 0.3s ease;
        text-align: left;
        min-height: 38px; /* IMPORTANTE */
    }
    
    .multiselect-button:hover {
        border-color: #667eea;
    }
    
    .multiselect-button.active {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .multiselect-button span:first-child {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .multiselect-button span:last-child {
        margin-left: 8px;
        flex-shrink: 0;
    }
    
    .multiselect-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: white;
        border: 2px solid #667eea;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        max-height: 250px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }
    
    .multiselect-dropdown.active {
        display: block;
    }
    
    .multiselect-option {
        padding: 10px 12px;
        cursor: pointer;
        transition: background 0.2s;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .multiselect-option:last-child {
        border-bottom: none;
    }
    
    .multiselect-option:hover {
        background: #f8f9fa;
    }
    
    .multiselect-option input[type="checkbox"] {
        margin: 0;
        cursor: pointer;
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }
    
    .multiselect-option label {
        cursor: pointer;
        margin: 0;
        padding: 0;
        text-transform: none;
        font-size: 13px;
        font-weight: 400;
        flex: 1;
        color: #2c3e50;
        line-height: 1.4;
    }
    
    /* Scrollbar customizada */
    .multiselect-dropdown::-webkit-scrollbar {
        width: 6px;
    }
    
    .multiselect-dropdown::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 6px;
    }
    
    .multiselect-dropdown::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 6px;
    }
    
    .multiselect-dropdown::-webkit-scrollbar-thumb:hover {
        background: #5568d3;
    }

    /* Botões de Filtro */
    .filter-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    .btn-filter,
    .btn-clear {
        padding: 8px 16px;
        font-size: 13px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .btn-filter {
        background: #667eea;
        color: white;
    }

    .btn-filter:hover {
        background: #5568d3;
    }

    .btn-clear {
        background: #e0e0e0;
        color: #2c3e50;
    }

    .btn-clear:hover {
        background: #d0d0d0;
    }

    /* Kanban */
    .kanban-container {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }

    @media (max-width: 1400px) {
        .kanban-container {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .kanban-container {
            grid-template-columns: 1fr;
        }
    }

    .kanban-column {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 15px;
        min-height: 400px;
        transition: all 0.2s ease;
    }

    .kanban-column.drag-over {
        background-color: rgba(102, 126, 234, 0.1) !important;
        border: 2px dashed #667eea !important;
        border-radius: 12px;
    }

    .kanban-header {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        color: white;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .kanban-column.prospeccao .kanban-header {
        background: linear-gradient(135deg, #3498db, #5dade2);
    }

    .kanban-column.negociacao .kanban-header {
        background: linear-gradient(135deg, #f39c12, #f8b739);
    }

    .kanban-column.fechados .kanban-header {
        background: linear-gradient(135deg, #27ae60, #52be80);
    }

    .kanban-column.perdidos .kanban-header {
        background: linear-gradient(135deg, #e74c3c, #ec7063);
    }

    .kanban-count {
        background: rgba(255, 255, 255, 0.3);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 14px;
    }

    .kanban-card {
        background: white;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        cursor: grab;
        transition: all 0.3s ease;
        border-left: 4px solid;
    }

    .kanban-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.2);
    }

    .kanban-card:active {
        cursor: grabbing;
    }

    .kanban-column.prospeccao .kanban-card {
        border-left-color: #3498db;
    }

    .kanban-column.negociacao .kanban-card {
        border-left-color: #f39c12;
    }

    .kanban-column.fechados .kanban-card {
        border-left-color: #27ae60;
    }

    .kanban-column.perdidos .kanban-card {
        border-left-color: #e74c3c;
    }

    .card-title {
        font-weight: 700;
        font-size: 16px;
        color: #2c3e50;
        margin-bottom: 8px;
    }

    .card-info {
        font-size: 13px;
        color: #7f8c8d;
        margin-bottom: 5px;
    }

    .card-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: 600;
        margin-top: 8px;
    }

    .badge-online {
        background: #e3f2fd;
        color: #1976d2;
    }

    .badge-presencial {
        background: #f3e5f5;
        color: #7b1fa2;
    }

    .card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #ecf0f1;
        font-size: 12px;
        color: #95a5a6;
    }

    .card-valor {
        font-weight: 700;
        color: #27ae60;
        font-size: 14px;
    }

    .card-tempo {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .card-tempo.alerta {
        color: #e74c3c;
        font-weight: 600;
    }

    .card-actions {
        display: flex;
        gap: 5px;
        margin-top: 10px;
    }

    .btn-card {
        padding: 5px 10px;
        border: none;
        border-radius: 5px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        color: white;
    }

    .btn-view {
        background: #3498db;
    }

    .btn-edit {
        background: #f39c12;
    }

    .btn-view:hover,
    .btn-edit:hover {
        transform: scale(1.05);
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #95a5a6;
    }
</style>

<div class="page-header" id="pageHeader">
    <div class="header-title">
        <div>
            <h1>
                <i class="<?= htmlspecialchars($modulo_atual['icone']) ?>" 
                   style="color: <?= htmlspecialchars($modulo_atual['cor']) ?>"></i>
                <?= htmlspecialchars($modulo_atual['nome']) ?> - Prospecção
            </h1>
            <?php if ($total_filtrado != $stats['total']): ?>
                <span class="filter-badge"><?= $total_filtrado ?> de <?= $stats['total'] ?> prospectos</span>
            <?php endif; ?>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar aos Módulos
            </a>
            <?php if ($pode_criar_editar): ?>
                <a href="novo.php?modulo=<?= urlencode($modulo_codigo) ?>" class="btn-primary">
                    ➕ Novo Prospecto
                </a>
            <?php endif; ?>
            <a href="relatorios.php?modulo=<?= urlencode($modulo_codigo) ?>" class="btn-secondary">
                📈 Relatórios
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-container">
        <div class="stat-card prospeccao">
            <div class="stat-label">🔍 Prospecção</div>
            <div class="stat-value"><?= $stats['fase_prospeccao'] ?></div>
            <div class="stat-detail">
                Online: <?= $stats['meio_online'] ?> | Presencial: <?= $stats['meio_presencial'] ?>
            </div>
        </div>

        <div class="stat-card negociacao">
            <div class="stat-label">🤝 Negociação</div>
            <div class="stat-value"><?= $stats['fase_negociacao'] ?></div>
            <div class="stat-detail">
                Valor em negoc.: R$ <?= number_format($stats['valor_proposta_total'], 2, ',', '.') ?>
            </div>
        </div>

        <div class="stat-card fechados">
            <div class="stat-label">✅ Fechados</div>
            <div class="stat-value"><?= $stats['fase_fechados'] ?></div>
            <div class="stat-detail">
                Total: R$ <?= number_format($stats['valor_fechado_total'], 2, ',', '.') ?> | Taxa: <?= $stats['taxa_conversao'] ?>%
            </div>
        </div>

        <div class="stat-card perdidos">
            <div class="stat-label">❌ Perdidos</div>
            <div class="stat-value"><?= $stats['fase_perdidos'] ?></div>
            <div class="stat-detail">
                Taxa de perda: <?= $stats['taxa_perda'] ?>%
            </div>
        </div>
    </div>

    <!-- Filtros Compactos -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-title">🔍 Filtros</div>
            
            <div class="filters-compact-grid">
                <!-- Busca -->
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Nome, telefone...">
                </div>
    
                <!-- Núcleos -->
                <div class="filter-group">
                    <label>Núcleos</label>
                    <div class="custom-multiselect">
                        <div class="multiselect-button" onclick="toggleMultiselect('nucleos')">
                            <span id="nucleos-text">
                                <?php 
                                if (empty($nucleos_filtro)) {
                                    echo "Todos";
                                } elseif (count($nucleos_filtro) == 1) {
                                    $nucleo_sel = array_filter($nucleos, fn($n) => $n['id'] == $nucleos_filtro[0]);
                                    echo reset($nucleo_sel)['nome'] ?? 'Todos';
                                } else {
                                    echo count($nucleos_filtro) . " selecionados";
                                }
                                ?>
                            </span>
                            <span>▼</span>
                        </div>
                        <div class="multiselect-dropdown" id="nucleos-dropdown">
                            <div class="multiselect-option">
                                <input type="checkbox" id="nucleos-todos" onchange="selectAllMulti('nucleos')">
                                <label for="nucleos-todos">Todos</label>
                            </div>
                            <?php foreach ($nucleos as $nucleo): ?>
                                <div class="multiselect-option">
                                    <input type="checkbox" name="nucleos[]" value="<?= $nucleo['id'] ?>" 
                                           id="nucleo-<?= $nucleo['id'] ?>"
                                           <?= in_array($nucleo['id'], $nucleos_filtro) ? 'checked' : '' ?>
                                           onchange="updateMultiText('nucleos')">
                                    <label for="nucleo-<?= $nucleo['id'] ?>"><?= htmlspecialchars($nucleo['nome']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
    
                <!-- Responsáveis -->
                <div class="filter-group">
                    <label>Responsáveis</label>
                    <div class="custom-multiselect">
                        <div class="multiselect-button" onclick="toggleMultiselect('responsaveis')">
                            <span id="responsaveis-text">
                                <?php 
                                if (empty($responsaveis_filtro)) {
                                    echo "Todos";
                                } elseif (count($responsaveis_filtro) == 1) {
                                    $resp_sel = array_filter($usuarios, fn($u) => $u['id'] == $responsaveis_filtro[0]);
                                    echo reset($resp_sel)['nome'] ?? 'Todos';
                                } else {
                                    echo count($responsaveis_filtro) . " selecionados";
                                }
                                ?>
                            </span>
                            <span>▼</span>
                        </div>
                        <div class="multiselect-dropdown" id="responsaveis-dropdown">
                            <div class="multiselect-option">
                                <input type="checkbox" id="responsaveis-todos" onchange="selectAllMulti('responsaveis')">
                                <label for="responsaveis-todos">Todos</label>
                            </div>
                            <?php foreach ($usuarios as $usuario): ?>
                                <div class="multiselect-option">
                                    <input type="checkbox" name="responsaveis[]" value="<?= $usuario['id'] ?>" 
                                           id="responsavel-<?= $usuario['id'] ?>"
                                           <?= in_array($usuario['id'], $responsaveis_filtro) ? 'checked' : '' ?>
                                           onchange="updateMultiText('responsaveis')">
                                    <label for="responsavel-<?= $usuario['id'] ?>"><?= htmlspecialchars($usuario['nome']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
    
                <!-- Fases -->
                <div class="filter-group">
                    <label>Fases</label>
                    <div class="custom-multiselect">
                        <div class="multiselect-button" onclick="toggleMultiselect('fases')">
                            <span id="fases-text">
                                <?php 
                                if (empty($fases_filtro)) {
                                    echo "Todas";
                                } elseif (count($fases_filtro) == 1) {
                                    echo $fases_filtro[0];
                                } else {
                                    echo count($fases_filtro) . " selecionadas";
                                }
                                ?>
                            </span>
                            <span>▼</span>
                        </div>
                        <div class="multiselect-dropdown" id="fases-dropdown">
                            <div class="multiselect-option">
                                <input type="checkbox" id="fases-todos" onchange="selectAllMulti('fases')">
                                <label for="fases-todos">Todas</label>
                            </div>
                            <?php 
                            $fases_opcoes = ['Prospecção', 'Negociação', 'Fechados', 'Perdidos'];
                            foreach ($fases_opcoes as $fase_opt): 
                            ?>
                                <div class="multiselect-option">
                                    <input type="checkbox" name="fases[]" value="<?= $fase_opt ?>" 
                                           id="fase-<?= $fase_opt ?>"
                                           <?= in_array($fase_opt, $fases_filtro) ? 'checked' : '' ?>
                                           onchange="updateMultiText('fases')">
                                    <label for="fase-<?= $fase_opt ?>"><?= $fase_opt ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
    
                <!-- Meio -->
                <div class="filter-group">
                    <label>Meio</label>
                    <select name="meio">
                        <option value="">Todos</option>
                        <option value="Online" <?= $meio === 'Online' ? 'selected' : '' ?>>Online</option>
                        <option value="Presencial" <?= $meio === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
                    </select>
                </div>
    
                <!-- Período -->
                <div class="filter-group">
                    <label>Período</label>
                    <select name="periodo" id="periodo" onchange="toggleCustomDates()">
                        <option value="">Todos</option>
                        <option value="7" <?= $periodo == 7 ? 'selected' : '' ?>>7 dias</option>
                        <option value="15" <?= $periodo == 15 ? 'selected' : '' ?>>15 dias</option>
                        <option value="30" <?= $periodo == 30 ? 'selected' : '' ?>>30 dias</option>
                        <option value="60" <?= $periodo == 60 ? 'selected' : '' ?>>60 dias</option>
                        <option value="90" <?= $periodo == 90 ? 'selected' : '' ?>>90 dias</option>
                        <option value="custom" <?= $periodo == 'custom' ? 'selected' : '' ?>>Personalizado</option>
                    </select>
                </div>
    
                <!-- Data Início -->
                <div class="filter-group" id="customDatesStart" style="display: <?= $periodo == 'custom' ? 'block' : 'none' ?>;">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" value="<?= $data_inicio ?>">
                </div>
    
                <!-- Data Fim -->
                <div class="filter-group" id="customDatesEnd" style="display: <?= $periodo == 'custom' ? 'block' : 'none' ?>;">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" value="<?= $data_fim ?>">
                </div>
    
                <!-- Valor Mínimo -->
                <div class="filter-group">
                    <label>Valor Mín (R$)</label>
                    <input type="text" name="valor_min" value="<?= $valor_min ?>" placeholder="0,00" class="money-input">
                </div>
    
                <!-- Valor Máximo -->
                <div class="filter-group">
                    <label>Valor Máx (R$)</label>
                    <input type="text" name="valor_max" value="<?= $valor_max ?>" placeholder="0,00" class="money-input">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter">🔍 Filtrar</button>
                <a href="index.php" class="btn-clear">✖️ Limpar</a>
            </div>
        </form>
    </div>
</div>

<!-- Kanban Board -->
<div class="kanban-container">
    <?php 
    // ===== USAR FASES DINÂMICAS DO BANCO =====
    foreach ($fases_modulo as $fase_cfg): 
        $fase_nome = $fase_cfg['fase'];
        $prospectos_fase = $prospectos_por_fase[$fase_nome] ?? [];
        $count = count($prospectos_fase);
        
        // Determinar classe CSS baseada na fase
        $fase_class = strtolower(str_replace([' ', 'ã', 'á', 'ç'], ['_', 'a', 'a', 'c'], $fase_nome));
        
        // Determinar ícone (usar do banco ou fallback)
        $icone_fase = $fase_cfg['icone'] ?? 'fas fa-circle';
    ?>
        <div class="kanban-column <?= $fase_class ?>" data-fase="<?= $fase_nome ?>" style="border-top: 4px solid <?= $fase_cfg['cor'] ?>">
            <div class="kanban-header" style="background: <?= $fase_cfg['cor'] ?>">
                <span><i class="<?= $icone_fase ?>"></i> <?= $fase_nome ?></span>
                <span class="kanban-count"><?= $count ?></span>
            </div>

            <?php if (empty($prospectos_fase)): ?>
                <div class="empty-state">
                    <i class="<?= $icone_fase ?>" style="font-size: 48px; color: <?= $fase_cfg['cor'] ?>"></i>
                    <p>Nenhum prospecto</p>
                </div>
            <?php else: ?>
                <?php foreach ($prospectos_fase as $prospecto): ?>
                    <div class="kanban-card" data-id="<?= $prospecto['id'] ?>">
                        <div class="card-title"><?= htmlspecialchars($prospecto['nome']) ?></div>
                        
                        <div class="card-info">
                            📍 <?= htmlspecialchars($prospecto['cidade']) ?>
                        </div>
                        
                        <div class="card-info">
                            📞 <?= htmlspecialchars($prospecto['telefone']) ?>
                        </div>
                        
                        <div class="card-info">
                            👤 <?= htmlspecialchars($prospecto['responsavel_nome']) ?>
                        </div>
                        
                        <div class="card-info">
                            🏢 <?= htmlspecialchars($prospecto['nucleo_nome']) ?>
                        </div>

                        <span class="card-badge badge-<?= strtolower($prospecto['meio']) ?>">
                        
                        <?php if ($prospecto['tipo_cliente'] === 'PJ' && !empty($prospecto['responsavel_contato'])): ?>
                        <div class="card-info" style="font-size: 11px; color: #7f8c8d;">
                            👔 <?= htmlspecialchars($prospecto['responsavel_contato']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="card-info">
                            👤 <?= htmlspecialchars($prospecto['responsavel_nome']) ?>
                        </div>
                        
                        <div class="card-info">
                            🏢 <?= htmlspecialchars($prospecto['nucleo_nome']) ?>
                        </div>

                        <span class="card-badge badge-<?= strtolower($prospecto['meio']) ?>">
                            <?= $prospecto['meio'] ?>
                        </span>

                        <div class="card-footer">
                            <?php if ($prospecto['valor_fechado']): ?>
                                <div class="card-valor">
                                    R$ <?= number_format($prospecto['valor_fechado'], 2, ',', '.') ?>
                                    <?php if (!empty($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                                        <span style="font-size: 11px; color: #27ae60; font-weight: bold;"> • <?= number_format($prospecto['percentual_exito'], 0) ?>%</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($prospecto['valor_proposta']): ?>
                                <div class="card-valor">
                                    R$ <?= number_format($prospecto['valor_proposta'], 2, ',', '.') ?>
                                    <?php if (!empty($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                                        <span style="font-size: 11px; color: #f39c12; font-weight: bold;"> • <?= number_format($prospecto['percentual_exito'], 0) ?>%</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                                <div class="card-valor" style="color: #3498db;">
                                    <?= number_format($prospecto['percentual_exito'], 0) ?>% de êxito
                                </div>
                            <?php else: ?>
                                <div style="color: #95a5a6;">Sem valor</div>
                            <?php endif; ?>

                        <div class="card-actions">
                            <a href="visualizar.php?id=<?= $prospecto['id'] ?>" class="btn-card btn-view">
                                👁️ Ver
                            </a>
                            <?php if ($pode_criar_editar): ?>
                                <a href="editar.php?id=<?= $prospecto['id'] ?>" class="btn-card btn-edit">
                                    ✏️ Editar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
// Toggle custom dates
function toggleCustomDates() {
    const periodo = document.getElementById('periodo').value;
    const showCustom = periodo === 'custom';
    document.getElementById('customDatesStart').style.display = showCustom ? 'block' : 'none';
    document.getElementById('customDatesEnd').style.display = showCustom ? 'block' : 'none';
}

// Toggle Filtros
let filtersOpen = true;
function toggleFilters() {
    filtersOpen = !filtersOpen;
    const content = document.getElementById('filtersContent');
    const icon = document.getElementById('filterIcon');
    
    if (filtersOpen) {
        content.style.display = 'grid';
        icon.textContent = '▼';
    } else {
        content.style.display = 'none';
        icon.textContent = '▶';
    }
}

// Multi-select
function toggleMultiselect(id) {
    const dropdown = document.getElementById(id + '-dropdown');
    const button = dropdown.previousElementSibling;
    
    // Fechar outros dropdowns
    document.querySelectorAll('.multiselect-dropdown').forEach(d => {
        if (d.id !== dropdown.id) {
            d.classList.remove('active');
        }
    });
    
    dropdown.classList.toggle('active');
    button.classList.toggle('active');
}

function selectAllMulti(id) {
    const checkbox = document.getElementById(id + '-todos');
    const checkboxes = document.querySelectorAll(`input[name="${id}[]"]`);
    
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    updateMultiText(id);
}

function updateMultiText(id) {
    const checkboxes = Array.from(document.querySelectorAll(`input[name="${id}[]"]:checked`));
    const text = document.getElementById(id + '-text');
    const allCheckbox = document.getElementById(id + '-todos');
    const totalCheckboxes = document.querySelectorAll(`input[name="${id}[]"]`).length;
    
    if (checkboxes.length === 0) {
        text.textContent = `Todos os ${id}`;
        allCheckbox.checked = false;
    } else if (checkboxes.length === totalCheckboxes) {
        text.textContent = `Todos os ${id}`;
        allCheckbox.checked = true;
    } else if (checkboxes.length === 1) {
        text.textContent = checkboxes[0].nextElementSibling.textContent;
        allCheckbox.checked = false;
    } else {
        text.textContent = `${checkboxes.length} selecionados`;
        allCheckbox.checked = false;
    }
}

// Fechar dropdowns ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-multiselect')) {
        document.querySelectorAll('.multiselect-dropdown').forEach(d => {
            d.classList.remove('active');
        });
        document.querySelectorAll('.multiselect-button').forEach(b => {
            b.classList.remove('active');
        });
    }
});

// Máscara de moeda
document.querySelectorAll('.money-input').forEach(input => {
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value) {
            value = (parseInt(value) / 100).toFixed(2);
            value = value.replace('.', ',');
            value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        e.target.value = value;
    });
});

// Drag and Drop
document.addEventListener('DOMContentLoaded', function() {
    const podeEditar = <?= $pode_criar_editar ? 'true' : 'false' ?>;
    
    if (!podeEditar) return;
    
    const cards = document.querySelectorAll('.kanban-card');
    cards.forEach(card => {
        card.draggable = true;
        
        card.addEventListener('dragstart', function(e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('prospecto_id', this.dataset.id);
            this.style.opacity = '0.5';
        });
        
        card.addEventListener('dragend', function() {
            this.style.opacity = '1';
        });
    });
    
    const colunas = document.querySelectorAll('.kanban-column');
    colunas.forEach(coluna => {
        coluna.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        });
        
        coluna.addEventListener('dragleave', function(e) {
            if (e.target === this) {
                this.classList.remove('drag-over');
            }
        });
        
        coluna.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            
            const prospecto_id = e.dataTransfer.getData('prospecto_id');
            const nova_fase = this.dataset.fase;
            
            if (!prospecto_id || !nova_fase) return;
            
            let valor_informado = null;
            let observacao = '';
            
            if (nova_fase === 'Fechados') {
                const valorStr = prompt('💰 Informe o valor fechado (R$):');
                if (valorStr === null) return;
                
                const valorLimpo = valorStr.replace(/\./g, '').replace(',', '.');
                valor_informado = parseFloat(valorLimpo);
                
                if (isNaN(valor_informado) || valor_informado <= 0) {
                    alert('❌ Valor inválido!');
                    return;
                }
                
                observacao = prompt('📝 Observação (opcional):') || '';
            }
            
            if (nova_fase === 'Perdidos') {
                observacao = prompt('❌ Motivo da perda (opcional):') || '';
            }
            
            const loadingMsg = document.createElement('div');
            loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 10px; z-index: 9999; font-size: 16px;';
            loadingMsg.textContent = '⏳ Movendo prospecto...';
            document.body.appendChild(loadingMsg);
            
            fetch('mover_fase.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    prospecto_id: prospecto_id,
                    nova_fase: nova_fase,
                    valor_informado: valor_informado,
                    observacao: observacao
                })
            })
            .then(response => response.json())
            .then(data => {
                document.body.removeChild(loadingMsg);
                
                if (data.success) {
                    const successMsg = document.createElement('div');
                    successMsg.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #27ae60; color: white; padding: 15px 25px; border-radius: 8px; z-index: 9999; font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
                    successMsg.textContent = '✅ ' + data.message;
                    document.body.appendChild(successMsg);
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    alert('❌ Erro: ' + data.message);
                }
            })
            .catch(error => {
                document.body.removeChild(loadingMsg);
                console.error('Erro:', error);
                alert('❌ Erro ao mover prospecto.');
            });
        });
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Prospecção', $conteudo, 'prospeccao');
?>
<!-- Modal de Revisita -->
<div id="modalRevisita" class="modal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Agendar Revisita</h3>
                <button type="button" class="close-modal" onclick="fecharModalRevisita()">&times;</button>
            </div>
            
            <form id="formRevisita" method="POST" action="agendar_revisita.php">
                <input type="hidden" name="prospeccao_id" id="revisita_prospeccao_id">
                <input type="hidden" name="modulo_codigo" value="<?= htmlspecialchars($modulo_codigo) ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="data_revisita">Data da Revisita *</label>
                        <input type="date" id="data_revisita" name="data_revisita" 
                               class="form-control" required 
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="hora_revisita">Hora</label>
                        <input type="time" id="hora_revisita" name="hora_revisita" 
                               class="form-control" value="09:00">
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_revisita">Observações</label>
                        <textarea id="observacoes_revisita" name="observacoes_revisita" 
                                  class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="fecharModalRevisita()">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-check"></i> Agendar Revisita
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-dialog {
    max-width: 500px;
    width: 90%;
}

.modal-content {
    background: white;
    border-radius: 15px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px 25px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #2c3e50;
    font-size: 20px;
}

.close-modal {
    background: none;
    border: none;
    font-size: 28px;
    color: #7f8c8d;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    line-height: 1;
    transition: all 0.3s;
}

.close-modal:hover {
    color: #e74c3c;
    transform: scale(1.1);
}

.modal-body {
    padding: 25px;
}

.modal-footer {
    padding: 20px 25px;
    border-top: 1px solid #e9ecef;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
}

.form-control {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}
</style>

<script>
// Função para abrir modal de revisita
function abrirModalRevisita(prospeccaoId) {
    document.getElementById('revisita_prospeccao_id').value = prospeccaoId;
    document.getElementById('modalRevisita').style.display = 'flex';
    
    // Definir data padrão (30 dias a partir de hoje)
    const hoje = new Date();
    hoje.setDate(hoje.getDate() + 30);
    document.getElementById('data_revisita').value = hoje.toISOString().split('T')[0];
}

// Função para fechar modal
function fecharModalRevisita() {
    document.getElementById('modalRevisita').style.display = 'none';
    document.getElementById('formRevisita').reset();
}

// Fechar modal ao clicar fora
document.getElementById('modalRevisita')?.addEventListener('click', function(e) {
    if (e.target === this) {
        fecharModalRevisita();
    }
});

// Verificar se deve abrir modal automaticamente
<?php if (isset($_SESSION['abrir_modal_revisita']) && $_SESSION['abrir_modal_revisita']): ?>
    abrirModalRevisita(<?= $_SESSION['prospeccao_id_revisita'] ?? 0 ?>);
    <?php 
    unset($_SESSION['abrir_modal_revisita']);
    unset($_SESSION['prospeccao_id_revisita']);
    ?>
<?php endif; ?>

// Modificar função de mover para incluir modal de revisita
const moveProspectoOriginal = window.moveProspecto;
window.moveProspecto = function(prospeccaoId, novaFase) {
    // Se a fase for "Revisitar", abrir modal
    if (novaFase === 'Revisitar') {
        abrirModalRevisita(prospeccaoId);
        return;
    }
    
    // Caso contrário, chamar função original
    if (typeof moveProspectoOriginal === 'function') {
        moveProspectoOriginal(prospeccaoId, novaFase);
    }
};

// =====================================================
// CÓDIGO JAVASCRIPT PARA ADICIONAR NO KANBAN.PHP
// Adicione este código na seção <script> do kanban.php
// =====================================================

// Interceptar movimento de cards para Visita Semanal e Revisitar
const moveProspectoOriginal = window.moveProspecto;

window.moveProspecto = function(prospeccaoId, novaFase) {
    // Se mover para Visita Semanal, criar tarefa automaticamente
    if (novaFase === 'Visita Semanal') {
        criarTarefaVisitaSemanal(prospeccaoId, novaFase);
        return;
    }
    
    // Se mover para Revisitar, abrir modal
    if (novaFase === 'Revisitar') {
        abrirModalRevisita(prospeccaoId);
        return;
    }
    
    // Caso contrário, chamar função original
    if (typeof moveProspectoOriginal === 'function') {
        moveProspectoOriginal(prospeccaoId, novaFase);
    }
};

/**
 * Criar tarefa de Visita Semanal automaticamente
 */
function criarTarefaVisitaSemanal(prospeccaoId, fase) {
    Swal.fire({
        title: 'Processando...',
        text: 'Criando tarefa de Visita Semanal',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData();
    formData.append('prospeccao_id', prospeccaoId);
    formData.append('fase', fase);
    
    fetch('processar_tarefa_visita.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                html: `
                    <p>Prospecto movido para <strong>Visita Semanal</strong></p>
                    <p>Tarefa criada automaticamente na agenda do responsável</p>
                `,
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            throw new Error(data.message || 'Erro ao criar tarefa');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: error.message || 'Erro ao processar movimento'
        });
    });
}

/**
 * Processar formulário de revisita
 */
document.getElementById('formRevisita')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('fase', 'Revisitar');
    
    // Verificar se data foi preenchida
    const dataRevisita = document.getElementById('data_revisita').value;
    if (!dataRevisita) {
        Swal.fire('Atenção', 'Por favor, informe a data da revisita', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Processando...',
        text: 'Agendando revisita',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('processar_tarefa_visita.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                html: `
                    <p>Revisita agendada com sucesso!</p>
                    <p>Uma tarefa foi criada na agenda do responsável</p>
                `,
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                fecharModalRevisita();
                location.reload();
            });
        } else {
            throw new Error(data.message || 'Erro ao agendar revisita');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: error.message || 'Erro ao processar revisita'
        });
    });
});

/**
 * Sugerir data ao abrir modal de revisita
 */
const originalAbrirModalRevisita = window.abrirModalRevisita;

window.abrirModalRevisita = function(prospeccaoId) {
    // Chamar função original
    if (typeof originalAbrirModalRevisita === 'function') {
        originalAbrirModalRevisita(prospeccaoId);
    }
    
    // Calcular data sugerida (30 dias a partir de hoje)
    const hoje = new Date();
    const dataSugerida = new Date(hoje.setDate(hoje.getDate() + 30));
    const dataFormatada = dataSugerida.toISOString().split('T')[0];
    
    // Preencher campo de data
    const campoData = document.getElementById('data_revisita');
    if (campoData && !campoData.value) {
        campoData.value = dataFormatada;
    }
};

console.log('✅ Integração de tarefas de visita carregada');
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Kanban de Prospecção - ' . htmlspecialchars($modulo_atual['nome']), $conteudo, 'prospeccao');
?>