<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'prospeccao';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];

// ===== MÓDULO FIXO: COMEX =====
$modulo_codigo = 'COMEX';

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
    
    $fases_disponiveis = [];
    foreach ($fases_modulo as $fase_cfg) {
        $fases_disponiveis[] = $fase_cfg['fase'];
    }
} catch (Exception $e) {
    error_log("Erro ao buscar fases: " . $e->getMessage());
    $fases_disponiveis = ['Prospecção', 'Visita Semanal', 'Negociação', 'Fechados', 'Perdidos', 'Revisitar'];
}
// ===== FIM MÓDULO =====

// --- FILTROS ---
$busca = $_GET['busca'] ?? '';
$periodo = $_GET['periodo'] ?? '30';
$meio = $_GET['meio'] ?? '';
$comparar = $_GET['comparar'] ?? '0';
$valor_min = $_GET['valor_min'] ?? '';
$valor_max = $_GET['valor_max'] ?? '';
$dias_fase_min = $_GET['dias_fase_min'] ?? '';
$dias_fase_max = $_GET['dias_fase_max'] ?? '';

// Filtros multi-seleção
$responsaveis_filtro = $_GET['responsaveis'] ?? [];
if (!is_array($responsaveis_filtro)) {
    $responsaveis_filtro = $responsaveis_filtro === '' ? [] : [$responsaveis_filtro];
}

$fases_filtro = $_GET['fases'] ?? [];
if (!is_array($fases_filtro)) {
    $fases_filtro = $fases_filtro === '' ? [] : [$fases_filtro];
}

// Calcular datas
if ($periodo === 'custom') {
    $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-m-d');
} else {
    $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
    $data_fim = date('Y-m-d');
}

// Se comparar, calcular período anterior
if ($comparar) {
    $dias_diferenca = (strtotime($data_fim) - strtotime($data_inicio)) / 86400;
    $data_inicio_anterior = date('Y-m-d', strtotime($data_inicio . " -{$dias_diferenca} days"));
    $data_fim_anterior = date('Y-m-d', strtotime($data_inicio . " -1 day"));
}

// --- BUSCAR USUÁRIOS ---
try {
    $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_usuarios = executeQuery($sql_usuarios);
    $usuarios = $stmt_usuarios->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// --- CONSTRUIR WHERE CLAUSE ---
$where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
$params = [$modulo_codigo];

if (!empty($busca)) {
    $where_conditions[] = "(p.nome LIKE ? OR p.telefone LIKE ? OR p.cidade LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
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

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// --- ESTATÍSTICAS GERAIS ---
try {
    $sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN fase = 'Prospecção' THEN 1 ELSE 0 END) as fase_prospeccao,
                    SUM(CASE WHEN fase = 'Negociação' THEN 1 ELSE 0 END) as fase_negociacao,
                    SUM(CASE WHEN fase = 'Visita Semanal' THEN 1 ELSE 0 END) as fase_visita_semanal,
                    SUM(CASE WHEN fase = 'Revisitar' THEN 1 ELSE 0 END) as fase_revisitar,
                    SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fase_fechados,
                    SUM(CASE WHEN fase = 'Perdidos' THEN 1 ELSE 0 END) as fase_perdidos,
                    SUM(COALESCE(valor_proposta, 0)) as valor_proposta_total,
                    SUM(COALESCE(valor_fechado, 0)) as valor_fechado_total,
                    AVG(DATEDIFF(CURRENT_DATE, data_cadastro)) as tempo_medio_dias
                  FROM prospeccoes p
                  {$where_clause}";
    
    $stmt_stats = executeQuery($sql_stats, $params);
    $stats = $stmt_stats->fetch();
    
    $total_finalizados = $stats['fase_fechados'] + $stats['fase_perdidos'];
    $stats['taxa_conversao'] = $total_finalizados > 0 ? round(($stats['fase_fechados'] / $total_finalizados) * 100, 1) : 0;
    $stats['taxa_perda'] = $total_finalizados > 0 ? round(($stats['fase_perdidos'] / $total_finalizados) * 100, 1) : 0;
    $stats['ticket_medio'] = $stats['fase_fechados'] > 0 ? $stats['valor_fechado_total'] / $stats['fase_fechados'] : 0;
    
} catch (Exception $e) {
    $stats = ['total' => 0, 'fase_prospeccao' => 0, 'fase_negociacao' => 0, 'fase_visita_semanal' => 0, 'fase_revisitar' => 0, 'fase_fechados' => 0, 'fase_perdidos' => 0, 'valor_proposta_total' => 0, 'valor_fechado_total' => 0, 'taxa_conversao' => 0, 'taxa_perda' => 0, 'ticket_medio' => 0, 'tempo_medio_dias' => 0];
}

// Se comparar, buscar stats do período anterior
if ($comparar) {
    $params_anterior = [];
    $where_anterior = ["p.ativo = 1", "p.data_cadastro BETWEEN ? AND ?"];
    $params_anterior = [$data_inicio_anterior, $data_fim_anterior];
    
    if (!empty($responsaveis_filtro)) {
        $placeholders = implode(',', array_fill(0, count($responsaveis_filtro), '?'));
        $where_anterior[] = "p.responsavel_id IN ({$placeholders})";
        $params_anterior = array_merge($params_anterior, $responsaveis_filtro);
    }
    
    $where_clause_anterior = 'WHERE ' . implode(' AND ', $where_anterior);
    
    try {
        $sql_stats_anterior = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fase_fechados,
                                SUM(COALESCE(valor_fechado, 0)) as valor_fechado_total
                              FROM prospeccoes p
                              {$where_clause_anterior}";
        
        $stmt_anterior = executeQuery($sql_stats_anterior, $params_anterior);
        $stats_anterior = $stmt_anterior->fetch();
        
        $stats['variacao_total'] = $stats_anterior['total'] > 0 ? round((($stats['total'] - $stats_anterior['total']) / $stats_anterior['total']) * 100, 1) : 0;
        $stats['variacao_fechados'] = $stats_anterior['fase_fechados'] > 0 ? round((($stats['fase_fechados'] - $stats_anterior['fase_fechados']) / $stats_anterior['fase_fechados']) * 100, 1) : 0;
        $stats['variacao_valor'] = $stats_anterior['valor_fechado_total'] > 0 ? round((($stats['valor_fechado_total'] - $stats_anterior['valor_fechado_total']) / $stats_anterior['valor_fechado_total']) * 100, 1) : 0;
        
    } catch (Exception $e) {
        $stats['variacao_total'] = 0;
        $stats['variacao_fechados'] = 0;
        $stats['variacao_valor'] = 0;
    }
}

// --- EVOLUÇÃO TEMPORAL ---
try {
    $sql_evolucao = "SELECT 
                        DATE(data_cadastro) as data,
                        COUNT(*) as total,
                        SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                        SUM(CASE WHEN fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos
                     FROM prospeccoes p
                     {$where_clause}
                     GROUP BY DATE(data_cadastro)
                     ORDER BY data ASC";
    
    $stmt_evolucao = executeQuery($sql_evolucao, $params);
    $evolucao = $stmt_evolucao->fetchAll();
} catch (Exception $e) {
    $evolucao = [];
}

// --- RANKING POR RESPONSÁVEL ---
try {
    $sql_ranking = "SELECT 
                        u.nome as responsavel,
                        COUNT(*) as total,
                        SUM(CASE WHEN p.fase = 'Prospecção' THEN 1 ELSE 0 END) as prospeccao,
                        SUM(CASE WHEN p.fase = 'Negociação' THEN 1 ELSE 0 END) as negociacao,
                        SUM(CASE WHEN p.fase = 'Visita Semanal' THEN 1 ELSE 0 END) as visita_semanal,
                        SUM(CASE WHEN p.fase = 'Revisitar' THEN 1 ELSE 0 END) as revisitar,
                        SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                        SUM(CASE WHEN p.fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos,
                        SUM(COALESCE(p.valor_fechado, 0)) as valor_total,
                        ROUND((SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) / 
                              NULLIF(SUM(CASE WHEN p.fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END), 0)) * 100, 1) as taxa_conversao
                    FROM prospeccoes p
                    INNER JOIN usuarios u ON p.responsavel_id = u.id
                    {$where_clause}
                    GROUP BY u.id, u.nome
                    ORDER BY fechados DESC, valor_total DESC
                    LIMIT 10";
    
    $stmt_ranking = executeQuery($sql_ranking, $params);
    $ranking = $stmt_ranking->fetchAll();
} catch (Exception $e) {
    $ranking = [];
}

// --- ANÁLISE DE PERDAS ---
try {
    $sql_perdas = "SELECT 
                      p.nome,
                      p.cidade,
                      u.nome as responsavel_nome,
                      p.data_cadastro,
                      DATEDIFF(p.data_ultima_atualizacao, p.data_cadastro) as dias_duracao,
                      COALESCE(p.valor_proposta, 0) as valor_proposta,
                      (SELECT i.descricao 
                       FROM prospeccoes_interacoes i 
                       WHERE i.prospeccao_id = p.id 
                       AND i.descricao LIKE '%perda%'
                       ORDER BY i.data_interacao DESC 
                       LIMIT 1) as motivo_perda
                   FROM prospeccoes p
                   INNER JOIN usuarios u ON p.responsavel_id = u.id
                   {$where_clause}
                   AND p.fase = 'Perdidos'
                   ORDER BY p.data_ultima_atualizacao DESC
                   LIMIT 20";
    
    $stmt_perdas = executeQuery($sql_perdas, $params);
    $perdas = $stmt_perdas->fetchAll();
} catch (Exception $e) {
    $perdas = [];
}

// --- PROSPECTOS EM RISCO ---
try {
    $sql_risco = "SELECT 
                     p.nome,
                     p.fase,
                     p.cidade,
                     u.nome as responsavel_nome,
                     DATEDIFF(CURRENT_DATE, p.data_ultima_atualizacao) as dias_parado,
                     COALESCE(p.valor_proposta, 0) as valor_proposta
                  FROM prospeccoes p
                  INNER JOIN usuarios u ON p.responsavel_id = u.id
                  {$where_clause}
                  AND p.fase IN ('Prospecção', 'Negociação')
                  HAVING dias_parado >= 7
                  ORDER BY dias_parado DESC
                  LIMIT 20";
    
    $stmt_risco = executeQuery($sql_risco, $params);
    $prospectos_risco = $stmt_risco->fetchAll();
} catch (Exception $e) {
    $prospectos_risco = [];
}

// --- TEMPO MÉDIO POR FASE ---
try {
    $sql_tempo_fases = "SELECT 
                           h1.fase_nova as fase,
                           AVG(DATEDIFF(
                               COALESCE(h2.data_movimento, CURRENT_DATE),
                               h1.data_movimento
                           )) as dias_media
                        FROM prospeccoes_historico h1
                        LEFT JOIN prospeccoes_historico h2 ON h2.prospeccao_id = h1.prospeccao_id 
                                                            AND h2.id > h1.id
                        INNER JOIN prospeccoes p ON p.id = h1.prospeccao_id
                        {$where_clause}
                        GROUP BY h1.fase_nova";
    
    $stmt_tempo = executeQuery($sql_tempo_fases, $params);
    $tempo_por_fase = $stmt_tempo->fetchAll();
} catch (Exception $e) {
    $tempo_por_fase = [];
}

ob_start();
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #f5f7fa;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 30px;
    }

    /* Header */
    .page-header {
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 25px;
    }

    .header-top h1 {
        font-size: 32px;
        font-weight: 700;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }

    .btn-secondary {
        background: #e0e0e0;
        color: #2c3e50;
    }

    .btn-secondary:hover {
        background: #d0d0d0;
    }

    .btn-success {
        background: #27ae60;
        color: white;
    }

    .btn-success:hover {
        background: #229954;
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
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
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

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border-left: 4px solid;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }

    .stat-card.primary { border-left-color: #667eea; }
    .stat-card.success { border-left-color: #27ae60; }
    .stat-card.warning { border-left-color: #f39c12; }
    .stat-card.danger { border-left-color: #e74c3c; }
    .stat-card.info { border-left-color: #3498db; }

    .stat-icon {
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 40px;
        opacity: 0.1;
    }

    .stat-label {
        font-size: 13px;
        color: #7f8c8d;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .stat-value {
        font-size: 36px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 8px;
    }

    .stat-detail {
        font-size: 13px;
        color: #95a5a6;
    }

    .stat-variation {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 8px;
    }

    .stat-variation.positive {
        background: #e8f5e9;
        color: #27ae60;
    }

    .stat-variation.negative {
        background: #ffebee;
        color: #e74c3c;
    }

    /* Charts Section */
    .charts-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
        gap: 30px;
        margin-bottom: 30px;
    }

    @media (max-width: 1200px) {
        .charts-section {
            grid-template-columns: 1fr;
        }
    }

    .chart-card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .chart-header {
        font-size: 18px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #ecf0f1;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .chart-container {
        position: relative;
        height: 300px;
    }

    canvas {
        max-height: 300px !important;
    }

    /* Funil */
    .funnel-container {
        display: flex;
        flex-direction: column;
        gap: 15px;
        padding: 20px;
    }

    .funnel-stage {
        color: white;
        padding: 20px;
        border-radius: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
        transition: all 0.3s ease;
        margin: 0 auto;
    }
    
    .funnel-stage:hover {
        transform: scale(1.02);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .funnel-stage-title {
        font-weight: 600;
        font-size: 16px;
    }

    .funnel-stage-value {
        font-size: 24px;
        font-weight: 700;
    }

    .funnel-percentage {
        font-size: 13px;
        opacity: 0.9;
        margin-top: 5px;
    }

    /* Tables */
    .table-card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }

    .table-header {
        font-size: 18px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #ecf0f1;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    table th {
        text-align: left;
        padding: 12px;
        background: #f8f9fa;
        font-size: 13px;
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 2px solid #e0e0e0;
        white-space: nowrap;
    }

    table td {
        padding: 12px;
        border-bottom: 1px solid #ecf0f1;
        font-size: 14px;
    }

    table tr:hover {
        background: #f8f9fa;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #ecf0f1;
        border-radius: 4px;
        overflow: hidden;
        margin-top: 5px;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        border-radius: 4px;
        transition: width 0.5s ease;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        display: inline-block;
    }

    .badge-success { background: #e8f5e9; color: #27ae60; }
    .badge-warning { background: #fff3e0; color: #f39c12; }
    .badge-danger { background: #ffebee; color: #e74c3c; }
    .badge-info { background: #e3f2fd; color: #3498db; }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #95a5a6;
    }

    .empty-state i {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    @media print {
        .no-print {
            display: none !important;
        }
        
        .page-header {
            box-shadow: none;
        }
        
        .stat-card,
        .chart-card,
        .table-card {
            break-inside: avoid;
            box-shadow: none;
            border: 1px solid #e0e0e0;
        }
    }
</style>

<div class="container">
    <!-- Header -->
    <div class="page-header no-print">
        <div class="header-top">
            <h1>📊 Relatórios de Prospecção</h1>
            <div class="header-actions">
                <a href="relatorios_print_comex.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-primary">
                    🖨️ Versão para Impressão
                </a>
                <button onclick="exportarExcel()" class="btn btn-success">
                    📥 Exportar Excel
                </button>
                <a href="comex.php" class="btn btn-primary">
                    ← Voltar ao Kanban
                </a>
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
                                $fases_opcoes = ['Prospecção', 'Negociação', 'Visita Semanal', 'Revisitar', 'Fechados', 'Perdidos'];
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
        
                    <!-- Comparar -->
                    <div class="filter-group">
                        <label>Comparar Período</label>
                        <select name="comparar">
                            <option value="0" <?= !$comparar ? 'selected' : '' ?>>Não</option>
                            <option value="1" <?= $comparar ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">🔍 Filtrar</button>
                    <a href="relatorio_comex.php" class="btn-clear">✖️ Limpar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon">📊</div>
            <div class="stat-label">Total de Prospectos</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-detail">No período selecionado</div>
            <?php if ($comparar && isset($stats['variacao_total'])): ?>
                <div class="stat-variation <?= $stats['variacao_total'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= $stats['variacao_total'] >= 0 ? '↑' : '↓' ?> <?= abs($stats['variacao_total']) ?>% vs período anterior
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card success">
            <div class="stat-icon">✅</div>
            <div class="stat-label">Taxa de Conversão</div>
            <div class="stat-value"><?= $stats['taxa_conversao'] ?>%</div>
            <div class="stat-detail"><?= $stats['fase_fechados'] ?> de <?= $total_finalizados ?> finalizados</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon">💰</div>
            <div class="stat-label">Valor Total Fechado</div>
            <div class="stat-value">R$ <?= number_format($stats['valor_fechado_total'], 0, ',', '.') ?></div>
            <div class="stat-detail">Ticket médio: R$ <?= number_format($stats['ticket_medio'], 2, ',', '.') ?></div>
            <?php if ($comparar && isset($stats['variacao_valor'])): ?>
                <div class="stat-variation <?= $stats['variacao_valor'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= $stats['variacao_valor'] >= 0 ? '↑' : '↓' ?> <?= abs($stats['variacao_valor']) ?>% vs período anterior
                </div>
            <?php endif; ?>
        </div>

        <div class="stat-card info">
            <div class="stat-icon">🕐</div>
            <div class="stat-label">Tempo Médio</div>
            <div class="stat-value"><?= round($stats['tempo_medio_dias']) ?></div>
            <div class="stat-detail">dias no sistema</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon">📈</div>
            <div class="stat-label">Em Negociação</div>
            <div class="stat-value"><?= $stats['fase_negociacao'] ?></div>
            <div class="stat-detail">R$ <?= number_format($stats['valor_proposta_total'], 0, ',', '.') ?> em negociação</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-icon">❌</div>
            <div class="stat-label">Taxa de Perda</div>
            <div class="stat-value"><?= $stats['taxa_perda'] ?>%</div>
            <div class="stat-detail"><?= $stats['fase_perdidos'] ?> perdidos</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-section">
        <!-- Funil de Conversão -->
        <div class="chart-card">
            <div class="chart-header">
                <span>🎯 Funil de Conversão</span>
            </div>
            <div class="funnel-container">
                <?php
                $total_max = max($stats['fase_prospeccao'], $stats['fase_negociacao'], $stats['fase_visita_semanal'], $stats['fase_revisitar'], $stats['fase_fechados'], $stats['fase_perdidos'], 1);
                
                $fases_funil = [
                    ['nome' => 'Prospecção', 'icon' => '🔍', 'valor' => $stats['fase_prospeccao'], 'cor' => 'linear-gradient(90deg, #3498db, #5dade2)'],
                    ['nome' => 'Negociação', 'icon' => '🤝', 'valor' => $stats['fase_negociacao'], 'cor' => 'linear-gradient(90deg, #f39c12, #f8b739)'],
                    ['nome' => 'Visita Semanal', 'icon' => '📅', 'valor' => $stats['fase_visita_semanal'], 'cor' => 'linear-gradient(90deg, #9b59b6, #bb8fce)'],
                    ['nome' => 'Revisitar', 'icon' => '🔄', 'valor' => $stats['fase_revisitar'], 'cor' => 'linear-gradient(90deg, #e67e22, #f39c12)'],
                    ['nome' => 'Fechados', 'icon' => '✅', 'valor' => $stats['fase_fechados'], 'cor' => 'linear-gradient(90deg, #27ae60, #52be80)'],
                    ['nome' => 'Perdidos', 'icon' => '❌', 'valor' => $stats['fase_perdidos'], 'cor' => 'linear-gradient(90deg, #e74c3c, #ec7063)']
                ];
                
                foreach ($fases_funil as $fase):
                    $percentual_total = $stats['total'] > 0 ? round(($fase['valor'] / $stats['total']) * 100, 1) : 0;
                    $largura = $fase['valor'] > 0 ? max(30, ($fase['valor'] / $total_max) * 100) : 30;
                ?>
                    <div class="funnel-stage" style="width: <?= $largura ?>%; background: <?= $fase['cor'] ?>;">
                        <div>
                            <div class="funnel-stage-title"><?= $fase['icon'] ?> <?= $fase['nome'] ?></div>
                            <div class="funnel-percentage"><?= $percentual_total ?>% dos leads</div>
                        </div>
                        <div class="funnel-stage-value"><?= $fase['valor'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Evolução Temporal -->
        <div class="chart-card">
            <div class="chart-header">
                <span>📈 Evolução no Período</span>
            </div>
            <div class="chart-container">
                <canvas id="chartEvolucao"></canvas>
            </div>
        </div>

        <!-- Distribuição por Fase -->
        <div class="chart-card">
            <div class="chart-header">
                <span>🥧 Distribuição por Fase</span>
            </div>
            <div class="chart-container">
                <canvas id="chartDistribuicao"></canvas>
            </div>
        </div>

        <!-- Tempo Médio por Fase -->
        <div class="chart-card">
            <div class="chart-header">
                <span>⏱️ Tempo Médio por Fase</span>
            </div>
            <div class="chart-container">
                <canvas id="chartTempoFases"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabela: Ranking de Responsáveis -->
    <div class="table-card">
        <div class="table-header">🏆 Ranking de Responsáveis (Top 10)</div>
        <?php if (empty($ranking)): ?>
            <div class="empty-state">
                <p>Nenhum dado disponível</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Responsável</th>
                        <th>Total</th>
                        <th>Prospecção</th>
                        <th>Negociação</th>
                        <th>📅 Visita</th>
                        <th>🔄 Revisitar</th>
                        <th>Fechados</th>
                        <th>Perdidos</th>
                        <th>Taxa Conv.</th>
                        <th>Valor Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $index => $resp): ?>
                        <tr>
                            <td><strong><?= $index + 1 ?></strong></td>
                            <td><?= htmlspecialchars($resp['responsavel']) ?></td>
                            <td><?= $resp['total'] ?></td>
                            <td><?= $resp['prospeccao'] ?></td>
                            <td><?= $resp['negociacao'] ?></td>
                            <td><span class="badge" style="background: #9b59b6;"><?= $resp['visita_semanal'] ?></span></td>
                            <td><span class="badge" style="background: #e67e22;"><?= $resp['revisitar'] ?></span></td>
                            <td><span class="badge badge-success"><?= $resp['fechados'] ?></span></td>
                            <td><span class="badge badge-danger"><?= $resp['perdidos'] ?></span></td>
                            <td><?= $resp['taxa_conversao'] ?? 0 ?>%</td>
                            <td><strong>R$ <?= number_format($resp['valor_total'], 2, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Tabela: Análise de Perdas -->
    <?php if (!empty($perdas)): ?>
        <div class="table-card">
            <div class="table-header">❌ Análise de Perdas (Últimos 20)</div>
            <table>
                <thead>
                    <tr>
                        <th>Prospecto</th>
                        <th>Cidade</th>
                        <th>Responsável</th>
                        <th>Data Cadastro</th>
                        <th>Duração</th>
                        <th>Valor Proposta</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($perdas as $perda): ?>
                        <tr>
                            <td><?= htmlspecialchars($perda['nome']) ?></td>
                            <td><?= htmlspecialchars($perda['cidade']) ?></td>
                            <td><?= htmlspecialchars($perda['responsavel_nome']) ?></td>
                            <td><?= date('d/m/Y', strtotime($perda['data_cadastro'])) ?></td>
                            <td><?= $perda['dias_duracao'] ?> dias</td>
                            <td>R$ <?= number_format($perda['valor_proposta'], 2, ',', '.') ?></td>
                            <td><?= htmlspecialchars($perda['motivo_perda'] ?: 'Não informado') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Tabela: Prospectos em Risco -->
    <?php if (!empty($prospectos_risco)): ?>
        <div class="table-card">
            <div class="table-header">⚠️ Prospectos em Risco (7+ dias sem movimentação)</div>
            <table>
                <thead>
                    <tr>
                        <th>Prospecto</th>
                        <th>Fase</th>
                        <th>Cidade</th>
                        <th>Responsável</th>
                        <th>Dias Parado</th>
                        <th>Valor Proposta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prospectos_risco as $risco): ?>
                        <tr>
                            <td><?= htmlspecialchars($risco['nome']) ?></td>
                            <td><span class="badge badge-warning"><?= $risco['fase'] ?></span></td>
                            <td><?= htmlspecialchars($risco['cidade']) ?></td>
                            <td><?= htmlspecialchars($risco['responsavel_nome']) ?></td>
                            <td><span class="badge badge-danger"><?= $risco['dias_parado'] ?> dias</span></td>
                            <td>R$ <?= number_format($risco['valor_proposta'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Toggle custom dates
function toggleCustomDates() {
    const periodo = document.getElementById('periodo').value;
    const showCustom = periodo === 'custom';
    document.getElementById('customDatesStart').style.display = showCustom ? 'block' : 'none';
    document.getElementById('customDatesEnd').style.display = showCustom ? 'block' : 'none';
}

// Multi-select
function toggleMultiselect(id) {
    const dropdown = document.getElementById(id + '-dropdown');
    const button = dropdown.previousElementSibling;
    
    // Fechar outros dropdowns
    document.querySelectorAll('.multiselect-dropdown').forEach(d => {
        if (d.id !== dropdown.id) {
            d.classList.remove('active');
            d.previousElementSibling.classList.remove('active');
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
        text.textContent = 'Todos';
        allCheckbox.checked = false;
    } else if (checkboxes.length === totalCheckboxes) {
        text.textContent = 'Todos';
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

// Gráfico de Evolução
const ctxEvolucao = document.getElementById('chartEvolucao').getContext('2d');
new Chart(ctxEvolucao, {
    type: 'line',
    data: {
        labels: [<?php foreach ($evolucao as $e) echo "'" . date('d/m', strtotime($e['data'])) . "',"; ?>],
        datasets: [
            {
                label: 'Total',
                data: [<?php foreach ($evolucao as $e) echo $e['total'] . ','; ?>],
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Fechados',
                data: [<?php foreach ($evolucao as $e) echo $e['fechados'] . ','; ?>],
                borderColor: '#27ae60',
                backgroundColor: 'rgba(39, 174, 96, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Perdidos',
                data: [<?php foreach ($evolucao as $e) echo $e['perdidos'] . ','; ?>],
                borderColor: '#e74c3c',
                backgroundColor: 'rgba(231, 76, 60, 0.1)',
                tension: 0.4,
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Gráfico de Distribuição
const ctxDistribuicao = document.getElementById('chartDistribuicao').getContext('2d');
new Chart(ctxDistribuicao, {
    type: 'doughnut',
    data: {
        labels: ['Prospecção', 'Negociação', 'Visita Semanal', 'Revisitar', 'Fechados', 'Perdidos'],
        datasets: [{
            data: [
                <?= $stats['fase_prospeccao'] ?>,
                <?= $stats['fase_negociacao'] ?>,
                <?= $stats['fase_visita_semanal'] ?>,
                <?= $stats['fase_revisitar'] ?>,
                <?= $stats['fase_fechados'] ?>,
                <?= $stats['fase_perdidos'] ?>
            ],
            backgroundColor: ['#3498db', '#f39c12', '#9b59b6', '#e67e22', '#27ae60', '#e74c3c']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Gráfico de Tempo por Fase
const ctxTempoFases = document.getElementById('chartTempoFases').getContext('2d');
new Chart(ctxTempoFases, {
    type: 'bar',
    data: {
        labels: [<?php foreach ($tempo_por_fase as $t) echo "'" . $t['fase'] . "',"; ?>],
        datasets: [{
            label: 'Dias Médios',
            data: [<?php foreach ($tempo_por_fase as $t) echo round($t['dias_media'], 1) . ','; ?>],
            backgroundColor: ['#3498db', '#f39c12', '#27ae60', '#e74c3c']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Dias' } }
        }
    }
});

// Exportar Excel
function exportarExcel() {
    alert('Funcionalidade em desenvolvimento. Use "Imprimir / PDF" e salve como PDF por enquanto.');
}
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Relatórios - Prospecção', $conteudo, 'prospeccao');
?>