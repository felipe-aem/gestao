<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();

// VERIFICAR ACESSO AO MÓDULO FINANCEIRO
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro === 'Nenhum') {
    die('
        <div style="text-align: center; margin-top: 50px;">
            <h2>Acesso Negado!</h2>
            <p>Você não tem permissão para acessar o módulo financeiro.</p>
            <p>Este módulo é restrito a Gestores, Diretores e Sócios.</p>
            <a href="../dashboard/">Voltar ao Dashboard</a>
        </div>
    ');
}

// Determinar núcleos que o usuário pode visualizar
$nucleos_usuario = $usuario_logado['nucleos'] ?? [];

if ($acesso_financeiro === 'Completo') {
    // Diretores e Sócios veem todos os núcleos
    $sql = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $nucleos_disponiveis = $stmt->fetchAll();
} else {
    // Gestores veem apenas seus núcleos
    $sql = "SELECT n.id, n.nome FROM nucleos n 
            INNER JOIN usuarios_nucleos un ON n.id = un.nucleo_id 
            WHERE un.usuario_id = ? AND n.ativo = 1 
            ORDER BY n.nome";
    $stmt = executeQuery($sql, [$usuario_logado['usuario_id']]);
    $nucleos_disponiveis = $stmt->fetchAll();
}

// Filtros
$nucleos_filtro = $_GET['nucleos'] ?? [];
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01'); // Primeiro dia do mês atual
$data_fim = $_GET['data_fim'] ?? date('Y-m-t'); // Último dia do mês atual
$situacao_filtro = $_GET['situacao'] ?? 'todos';

// Se nenhum núcleo selecionado, usar todos disponíveis
if (empty($nucleos_filtro)) {
    $nucleos_filtro = array_column($nucleos_disponiveis, 'id');
}

// Construir query base
$where_conditions = [];
$params = [];

// Filtro por núcleos
if (!empty($nucleos_filtro)) {
    $placeholders = str_repeat('?,', count($nucleos_filtro) - 1) . '?';
    $where_conditions[] = "p.nucleo_id IN ($placeholders)";
    $params = array_merge($params, $nucleos_filtro);
}

// Filtro por datas (processos criados no período)
if (!empty($data_inicio)) {
    $where_conditions[] = "p.data_criacao >= ?";
    $params[] = $data_inicio . ' 00:00:00';
}

if (!empty($data_fim)) {
    $where_conditions[] = "p.data_criacao <= ?";
    $params[] = $data_fim . ' 23:59:59';
}

// Filtro por situação de pagamento
if ($situacao_filtro !== 'todos') {
    if ($situacao_filtro === 'pagos') {
        $where_conditions[] = "pf.valor_honorarios IS NOT NULL AND 
            (SELECT COALESCE(SUM(pr.valor), 0) FROM processo_receitas pr WHERE pr.processo_id = p.id) >= pf.valor_honorarios";
    } elseif ($situacao_filtro === 'pendentes') {
        $where_conditions[] = "pf.valor_honorarios IS NOT NULL AND 
            (SELECT COALESCE(SUM(pr.valor), 0) FROM processo_receitas pr WHERE pr.processo_id = p.id) < pf.valor_honorarios";
    } elseif ($situacao_filtro === 'sem_info') {
        $where_conditions[] = "pf.id IS NULL";
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar estatísticas gerais
$sql_stats = "SELECT 
    COUNT(DISTINCT p.id) as total_processos,
    COUNT(DISTINCT CASE WHEN pf.id IS NOT NULL THEN p.id END) as processos_com_info,
    COALESCE(SUM(pf.valor_honorarios), 0) as total_contratado,
    COALESCE(SUM(pr.valor), 0) as total_recebido,
    COALESCE(SUM(pf.valor_honorarios) - SUM(pr.valor), 0) as total_pendente
    FROM processos p
    LEFT JOIN processo_financeiro pf ON p.id = pf.processo_id
    LEFT JOIN processo_receitas pr ON p.id = pr.processo_id
    $where_clause";

$stmt = executeQuery($sql_stats, $params);
$stats = $stmt->fetch();

// Buscar processos com informações financeiras
$sql_processos = "SELECT 
    p.id,
    p.numero_processo,
    p.cliente_nome,
    p.situacao_processual,
    n.nome as nucleo_nome,
    u.nome as responsavel_nome,
    pf.forma_pagamento,
    pf.valor_honorarios,
    pf.porcentagem_exito,
    pf.valor_entrada,
    pf.numero_parcelas,
    COALESCE(SUM(pr.valor), 0) as total_recebido,
    (pf.valor_honorarios - COALESCE(SUM(pr.valor), 0)) as saldo_pendente
    FROM processos p
    INNER JOIN nucleos n ON p.nucleo_id = n.id
    LEFT JOIN usuarios u ON p.responsavel_id = u.id
    LEFT JOIN processo_financeiro pf ON p.id = pf.processo_id
    LEFT JOIN processo_receitas pr ON p.id = pr.processo_id
    $where_clause
    GROUP BY p.id
    ORDER BY p.data_criacao DESC
    LIMIT 50";

$stmt = executeQuery($sql_processos, $params);
$processos = $stmt->fetchAll();

// Buscar receitas do período
$sql_receitas = "SELECT 
    pr.*,
    p.numero_processo,
    p.cliente_nome,
    n.nome as nucleo_nome
    FROM processo_receitas pr
    INNER JOIN processos p ON pr.processo_id = p.id
    INNER JOIN nucleos n ON p.nucleo_id = n.id
    WHERE pr.data_recebimento BETWEEN ? AND ?
    " . (!empty($nucleos_filtro) ? "AND p.nucleo_id IN (" . str_repeat('?,', count($nucleos_filtro) - 1) . "?)" : "") . "
    ORDER BY pr.data_recebimento DESC
    LIMIT 20";

$params_receitas = [$data_inicio, $data_fim];
if (!empty($nucleos_filtro)) {
    $params_receitas = array_merge($params_receitas, $nucleos_filtro);
}

$stmt = executeQuery($sql_receitas, $params_receitas);
$receitas_recentes = $stmt->fetchAll();

ob_start();
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
        background-attachment: fixed;
        min-height: 100vh;
    }
    
    .content {
        padding: 30px;
        max-width: 1600px;
        margin: 0 auto;
    }
    
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        text-align: center;
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    }
    
    .stat-card h3 {
        font-size: 32px;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card.success { border-left-color: #28a745; }
    .stat-card.success h3 { color: #28a745; }
    
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.warning h3 { color: #ffc107; }
    
    .stat-card.info { border-left-color: #17a2b8; }
    .stat-card.info h3 { color: #17a2b8; }
    
    .stat-card.primary { border-left-color: #007bff; }
    .stat-card.primary h3 { color: #007bff; }
    
    .filters-container {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 30px;
    }
    
    .filters-title {
        font-size: 14px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .filters-compact-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 12px;
        margin-bottom: 15px;
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
    
    .table-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
        margin-bottom: 30px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
        color: white;
        padding: 15px;
        text-align: left;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 13px;
    }
    
    td {
        padding: 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        color: #444;
        font-size: 14px;
    }
    
    tr:hover {
        background: rgba(26, 26, 26, 0.02);
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-success { background: #28a745; color: white; }
    .badge-warning { background: #ffc107; color: #000; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-info { background: #17a2b8; color: white; }
    
    .btn-action {
        padding: 6px 12px;
        margin: 0 2px;
        border-radius: 5px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-block;
        background: #007bff;
        color: white;
    }
    
    .btn-action:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #666;
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .filters-grid {
            grid-template-columns: 1fr;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            min-width: 1000px;
        }
    }
    
    /* Melhorias nos Filtros */
    .filters-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid rgba(0,0,0,0.1);
    }
    
    .btn-limpar-filtros {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-limpar-filtros:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .filter-input,
    .filter-select {
        width: 100%;
        padding: 12px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
    }
    
    .filter-input:focus,
    .filter-select:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    .filter-select {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M10.293 3.293L6 7.586 1.707 3.293A1 1 0 00.293 4.707l5 5a1 1 0 001.414 0l5-5a1 1 0 10-1.414-1.414z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 36px;
    }
    
    /* Multi-select Modernizado */
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
        min-height: 38px;
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
    
    /* Botões de Ação */
    .filters-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
    
    .btn-filter {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        display: flex;
        align-items: center;
    }
    
    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .btn-export {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        display: flex;
        align-items: center;
    }
    
    .btn-export:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    /* Scrollbar Customizada */
    .custom-select-dropdown::-webkit-scrollbar {
        width: 8px;
    }
    
    .custom-select-dropdown::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .custom-select-dropdown::-webkit-scrollbar-thumb {
        background: #007bff;
        border-radius: 4px;
    }
    
    .custom-select-dropdown::-webkit-scrollbar-thumb:hover {
        background: #0056b3;
    }
    
    /* Responsivo */
    @media (max-width: 768px) {
        .filters-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        
        .filters-grid {
            grid-template-columns: 1fr;
        }
        
        .filters-actions {
            width: 100%;
        }
        
        .btn-filter,
        .btn-export {
            flex: 1;
            justify-content: center;
        }
    }
</style>

<div class="content">
    <div class="page-header">
        <h2>💰 Dashboard Financeiro</h2>
        <div>
            <span style="color: #666; font-size: 14px;">
                Período: <?= date('d/m/Y', strtotime($data_inicio)) ?> a <?= date('d/m/Y', strtotime($data_fim)) ?>
            </span>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <h3><?= $stats['total_processos'] ?></h3>
            <p>Total de Processos</p>
        </div>
        
        <div class="stat-card success">
            <h3>R$ <?= number_format($stats['total_contratado'], 2, ',', '.') ?></h3>
            <p>Total Contratado</p>
        </div>
        
        <div class="stat-card info">
            <h3>R$ <?= number_format($stats['total_recebido'], 2, ',', '.') ?></h3>
            <p>Total Recebido</p>
        </div>
        
        <div class="stat-card warning">
            <h3>R$ <?= number_format($stats['total_pendente'], 2, ',', '.') ?></h3>
            <p>Saldo Pendente</p>
        </div>
        
        <div class="stat-card primary">
            <h3><?= $stats['processos_com_info'] ?></h3>
            <p>Com Info Financeira</p>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filters-container">
        <div class="filters-title">
            🔍 Filtros
        </div>
        
        <form method="GET" id="filterForm">
            <div class="filters-compact-grid">
                <!-- Núcleo Multi-Select -->
                <div class="filter-group">
                    <label>Núcleo:</label>
                    <div class="custom-multiselect">
                        <button type="button" class="multiselect-button" id="nucleoButton">
                            <span>Todos</span>
                            <span>▼</span>
                        </button>
                        <div class="multiselect-dropdown" id="nucleoDropdown">
                            <?php foreach ($nucleos_disponiveis as $nucleo): ?>
                            <div class="multiselect-option">
                                <input type="checkbox" 
                                       name="nucleos[]" 
                                       value="<?= $nucleo['id'] ?>" 
                                       id="nucleo_<?= $nucleo['id'] ?>"
                                       <?= in_array($nucleo['id'], $nucleos_filtro) ? 'checked' : '' ?>
                                       class="nucleo-checkbox">
                                <label for="nucleo_<?= $nucleo['id'] ?>">
                                    <?= htmlspecialchars($nucleo['nome']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Data Início -->
                <div class="filter-group">
                    <label>Data Início:</label>
                    <input type="date" name="data_inicio" value="<?= $data_inicio ?>" required>
                </div>
                
                <!-- Data Fim -->
                <div class="filter-group">
                    <label>Data Fim:</label>
                    <input type="date" name="data_fim" value="<?= $data_fim ?>" required>
                </div>
                
                <!-- Situação -->
                <div class="filter-group">
                    <label>Situação:</label>
                    <select name="situacao">
                        <option value="todos" <?= $situacao_filtro === 'todos' ? 'selected' : '' ?>>📊 Todos</option>
                        <option value="pagos" <?= $situacao_filtro === 'pagos' ? 'selected' : '' ?>>✅ Pagos</option>
                        <option value="pendentes" <?= $situacao_filtro === 'pendentes' ? 'selected' : '' ?>>⏳ Pendentes</option>
                        <option value="sem_info" <?= $situacao_filtro === 'sem_info' ? 'selected' : '' ?>>❌ Sem Info</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn-filter">🔍 Filtrar</button>
                <button type="button" class="btn-clear" onclick="limparFiltros()">✖ Limpar</button>
            </div>
        </form>
    </div>
    
    <!-- Tabela de Processos -->
    <div class="table-container">
        <h3 style="padding: 20px; color: #1a1a1a; border-bottom: 1px solid rgba(0,0,0,0.1);">
            📋 Processos com Informações Financeiras
        </h3>
        
        <?php if (empty($processos)): ?>
        <div class="empty-state">
            <p>Nenhum processo encontrado com os filtros aplicados.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Processo</th>
                    <th>Cliente</th>
                    <th>Núcleo</th>
                    <th>Responsável</th>
                    <th>Forma Pagamento</th>
                    <th>Valor Contratado</th>
                    <th>Total Recebido</th>
                    <th>Saldo</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($processos as $proc): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($proc['numero_processo']) ?></strong></td>
                    <td><?= htmlspecialchars($proc['cliente_nome']) ?></td>
                    <td><?= htmlspecialchars($proc['nucleo_nome']) ?></td>
                    <td><?= htmlspecialchars($proc['responsavel_nome']) ?></td>
                    <td>
                        <?php if ($proc['forma_pagamento']): ?>
                        <span class="badge badge-info"><?= htmlspecialchars($proc['forma_pagamento']) ?></span>
                        <?php else: ?>
                        <span class="badge badge-danger">Sem Info</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($proc['valor_honorarios']): ?>
                        R$ <?= number_format($proc['valor_honorarios'], 2, ',', '.') ?>
                        <?php elseif ($proc['porcentagem_exito']): ?>
                        <?= $proc['porcentagem_exito'] ?>% do êxito
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td style="color: #28a745; font-weight: 600;">
                        R$ <?= number_format($proc['total_recebido'], 2, ',', '.') ?>
                    </td>
                    <td style="color: #ffc107; font-weight: 600;">
                        <?php if ($proc['valor_honorarios']): ?>
                        R$ <?= number_format($proc['saldo_pendente'], 2, ',', '.') ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        if (!$proc['valor_honorarios']) {
                            echo '<span class="badge badge-danger">Sem Info</span>';
                        } elseif ($proc['saldo_pendente'] <= 0) {
                            echo '<span class="badge badge-success">Pago</span>';
                        } else {
                            echo '<span class="badge badge-warning">Pendente</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <a href="../processos/visualizar.php?id=<?= $proc['id'] ?>" class="btn-action">Ver Processo</a>
                        <a href="registrar_receita.php?processo_id=<?= $proc['id'] ?>" class="btn-action" style="background: #28a745;">+ Receita</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Receitas Recentes -->
    <div class="table-container">
        <h3 style="padding: 20px; color: #1a1a1a; border-bottom: 1px solid rgba(0,0,0,0.1);">
            💳 Receitas Recentes (<?= date('d/m/Y', strtotime($data_inicio)) ?> - <?= date('d/m/Y', strtotime($data_fim)) ?>)
        </h3>
        
        <?php if (empty($receitas_recentes)): ?>
        <div class="empty-state">
            <p>Nenhuma receita registrada no período.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Processo</th>
                    <th>Cliente</th>
                    <th>Núcleo</th>
                    <th>Tipo</th>
                    <th>Valor</th>
                    <th>Forma Recebimento</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receitas_recentes as $receita): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($receita['data_recebimento'])) ?></td>
                    <td><strong><?= htmlspecialchars($receita['numero_processo']) ?></strong></td>
                    <td><?= htmlspecialchars($receita['cliente_nome']) ?></td>
                    <td><?= htmlspecialchars($receita['nucleo_nome']) ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($receita['tipo_receita']) ?></span></td>
                    <td style="color: #28a745; font-weight: 700; font-size: 16px;">
                        R$ <?= number_format($receita['valor'], 2, ',', '.') ?>
                    </td>
                    <td><?= htmlspecialchars($receita['forma_recebimento']) ?></td>
                    <td>
                        <?php if ($acesso_financeiro === 'Completo'): ?>
                        <a href="editar_receita.php?id=<?= $receita['id'] ?>" class="btn-action">Editar</a>
                        <a href="deletar_receita.php?id=<?= $receita['id'] ?>" class="btn-action" style="background: #dc3545;" 
                           onclick="return confirm('Tem certeza que deseja deletar esta receita?')">Deletar</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
    // Multi-select de Núcleos
    document.addEventListener('DOMContentLoaded', function() {
        const button = document.getElementById('nucleoButton');
        const dropdown = document.getElementById('nucleoDropdown');
        const checkboxes = document.querySelectorAll('.nucleo-checkbox');
        
        if (!button || !dropdown) return;
        
        // Toggle dropdown
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropdown.classList.toggle('active');
            button.classList.toggle('active');
        });
        
        // Fechar ao clicar fora
        document.addEventListener('click', function(e) {
            if (!button.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('active');
                button.classList.remove('active');
            }
        });
        
        // Atualizar texto ao mudar checkboxes
        function atualizarTextoNucleos() {
            const total = checkboxes.length;
            const selecionados = Array.from(checkboxes).filter(cb => cb.checked).length;
            const textSpan = button.querySelector('span:first-child');
            
            if (selecionados === 0) {
                textSpan.textContent = 'Nenhum selecionado';
            } else if (selecionados === total) {
                textSpan.textContent = 'Todos';
            } else {
                textSpan.textContent = `${selecionados} selecionados`;
            }
        }
        
        checkboxes.forEach(cb => {
            cb.addEventListener('change', atualizarTextoNucleos);
        });
        
        // Inicializar texto
        atualizarTextoNucleos();
    });
    
    // Limpar filtros
    function limparFiltros() {
        const hoje = new Date();
        const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        const ultimoDia = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
        
        const dataInicio = primeiroDia.toISOString().split('T')[0];
        const dataFim = ultimoDia.toISOString().split('T')[0];
        
        document.querySelectorAll('.nucleo-checkbox').forEach(cb => cb.checked = true);
        document.querySelector('input[name="data_inicio"]').value = dataInicio;
        document.querySelector('input[name="data_fim"]').value = dataFim;
        document.querySelector('select[name="situacao"]').value = 'todos';
        
        document.getElementById('filterForm').submit();
    }
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Dashboard Financeiro', $conteudo, 'financeiro');
?>