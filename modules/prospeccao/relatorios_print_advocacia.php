<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

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
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'] ?? $_SESSION['usuario_id'] ?? null;

// ===== CONTROLE DE ACESSO POR NÚCLEO =====
$niveis_acesso_total = ['Admin', 'Socio', 'Diretor'];
$filtrar_por_nucleo = !in_array($nivel_acesso_logado, $niveis_acesso_total);

// EXCEÇÃO: Usuário ID 15 (Gestor Criminal)
$filtrar_por_cidade = false;
$cidade_filtro = null;
if ($usuario_id == 15) {
    $filtrar_por_cidade = true;
    $cidade_filtro = 'Chapecó';
    $filtrar_por_nucleo = false;
}

// Buscar núcleos do usuário
$nucleos_usuario = [];
if ($filtrar_por_nucleo) {
    try {
        $sql_nucleos_usuario = "SELECT nucleo_id FROM usuarios_nucleos WHERE usuario_id = ?";
        $stmt_nucleos = executeQuery($sql_nucleos_usuario, [$usuario_id]);
        $resultados = $stmt_nucleos->fetchAll();
        
        foreach ($resultados as $row) {
            $nucleos_usuario[] = $row['nucleo_id'];
        }
        
        if (empty($nucleos_usuario)) {
            $sql_nucleo_principal = "SELECT nucleo_id FROM usuarios WHERE id = ?";
            $stmt_principal = executeQuery($sql_nucleo_principal, [$usuario_id]);
            $resultado = $stmt_principal->fetch();
            if ($resultado && $resultado['nucleo_id']) {
                $nucleos_usuario[] = $resultado['nucleo_id'];
            }
        }
        
        if (empty($nucleos_usuario)) {
            echo "<!DOCTYPE html><html><body><div style='padding:40px;text-align:center;'>";
            echo "<h2>⚠️ Acesso Restrito</h2><p>Você não possui um núcleo associado.</p>";
            echo "<a href='advocacia.php' style='padding:10px 20px;background:#667eea;color:white;text-decoration:none;border-radius:8px;'>Voltar</a>";
            echo "</div></body></html>";
            exit;
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar núcleo: " . $e->getMessage());
        $nucleos_usuario = [];
    }
}

$modulo_codigo = 'ADVOCACIA';

// Filtros simples
$busca = $_GET['busca'] ?? '';
$periodo = $_GET['periodo'] ?? '30';
$meio = $_GET['meio'] ?? '';
$valor_min = $_GET['valor_min'] ?? '';
$valor_max = $_GET['valor_max'] ?? '';

// ⚠️ CORREÇÃO: Filtros multi-seleção - suporte para arrays vindos da URL
$nucleos_filtro = $_GET['nucleos'] ?? [];
if (!is_array($nucleos_filtro)) {
    // Se veio como string (do http_build_query), converter para array
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

// Calcular datas
if ($periodo === 'custom') {
    $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_GET['data_fim'] ?? date('Y-m-d');
} else {
    $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
    $data_fim = date('Y-m-d');
}

// Buscar núcleos
try {
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 AND id IN ({$placeholders}) ORDER BY nome ASC";
        $stmt_nucleos = executeQuery($sql_nucleos, $nucleos_usuario);
    } else {
        $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome ASC";
        $stmt_nucleos = executeQuery($sql_nucleos);
    }
    $nucleos = $stmt_nucleos->fetchAll();
} catch (Exception $e) {
    $nucleos = [];
}

// Buscar usuários
try {
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 AND nucleo_id IN ({$placeholders}) ORDER BY nome ASC";
        $stmt_usuarios = executeQuery($sql_usuarios, $nucleos_usuario);
    } else {
        $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
        $stmt_usuarios = executeQuery($sql_usuarios);
    }
    $usuarios = $stmt_usuarios->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// Construir WHERE
$where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
$params = [$modulo_codigo];

// Filtro por núcleo do usuário logado
if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
    $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
    $where_conditions[] = "p.nucleo_id IN ({$placeholders})";
    $params = array_merge($params, $nucleos_usuario);
}

// Filtro por cidade
if ($filtrar_por_cidade && $cidade_filtro) {
    $where_conditions[] = "p.cidade LIKE ?";
    $params[] = "%{$cidade_filtro}%";
}

// Busca
if (!empty($busca)) {
    $where_conditions[] = "(p.nome LIKE ? OR p.telefone LIKE ? OR p.cidade LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

// ⚠️ CORREÇÃO: Aplicar filtros multi-seleção
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

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// ⚠️ CORREÇÃO: Estatísticas com valor_proposta para fechados
try {
    $sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN fase = 'Prospecção' THEN 1 ELSE 0 END) as fase_prospeccao,
                    SUM(CASE WHEN fase = 'Negociação' THEN 1 ELSE 0 END) as fase_negociacao,
                    SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fase_fechados,
                    SUM(CASE WHEN fase = 'Perdidos' THEN 1 ELSE 0 END) as fase_perdidos,
                    SUM(COALESCE(valor_proposta, 0)) as valor_proposta_total,
                    SUM(CASE WHEN fase = 'Fechados' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_fechado_total,
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
    $stats = ['total' => 0, 'fase_prospeccao' => 0, 'fase_negociacao' => 0, 'fase_fechados' => 0, 'fase_perdidos' => 0, 'valor_proposta_total' => 0, 'valor_fechado_total' => 0, 'taxa_conversao' => 0, 'taxa_perda' => 0, 'ticket_medio' => 0, 'tempo_medio_dias' => 0];
}

// ⚠️ CORREÇÃO: Ranking com valor_proposta para fechados
try {
    $sql_ranking = "SELECT 
                        u.nome as responsavel,
                        COUNT(*) as total,
                        SUM(CASE WHEN p.fase = 'Prospecção' THEN 1 ELSE 0 END) as prospeccao,
                        SUM(CASE WHEN p.fase = 'Negociação' THEN 1 ELSE 0 END) as negociacao,
                        SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                        SUM(CASE WHEN p.fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos,
                        SUM(CASE WHEN p.fase = 'Fechados' THEN COALESCE(p.valor_proposta, 0) ELSE 0 END) as valor_total,
                        ROUND((SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) / 
                              NULLIF(SUM(CASE WHEN p.fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END), 0)) * 100, 1) as taxa_conversao
                    FROM prospeccoes p
                    LEFT JOIN usuarios u ON p.responsavel_id = u.id
                    {$where_clause}
                    GROUP BY u.nome
                    ORDER BY fechados DESC, valor_total DESC
                    LIMIT 10";
    
    $stmt_ranking = executeQuery($sql_ranking, $params);
    $ranking = $stmt_ranking->fetchAll();
} catch (Exception $e) {
    $ranking = [];
}

// ⚠️ CORREÇÃO: Performance por núcleo com valor_proposta para fechados
try {
    $sql_nucleos_perf = "SELECT 
                            n.nome as nucleo,
                            COUNT(*) as total,
                            SUM(CASE WHEN p.fase = 'Prospecção' THEN 1 ELSE 0 END) as prospeccao,
                            SUM(CASE WHEN p.fase = 'Negociação' THEN 1 ELSE 0 END) as negociacao,
                            SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                            SUM(CASE WHEN p.fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos,
                            SUM(CASE WHEN p.fase = 'Fechados' THEN COALESCE(p.valor_proposta, 0) ELSE 0 END) as valor_total,
                            ROUND((SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) / 
                                  NULLIF(SUM(CASE WHEN p.fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END), 0)) * 100, 1) as taxa_conversao
                        FROM prospeccoes p
                        LEFT JOIN nucleos n ON p.nucleo_id = n.id
                        {$where_clause}
                        GROUP BY n.nome
                        ORDER BY fechados DESC";
    
    $stmt_nucleos = executeQuery($sql_nucleos_perf, $params);
    $nucleos_performance = $stmt_nucleos->fetchAll();
} catch (Exception $e) {
    $nucleos_performance = [];
}

// Montar texto dos filtros aplicados
$filtros_aplicados = [];
if (!empty($nucleos_filtro)) {
    $nomes_nucleos = array_filter(array_map(function($n) use ($nucleos_filtro) {
        return in_array($n['id'], $nucleos_filtro) ? $n['nome'] : null;
    }, $nucleos));
    if (!empty($nomes_nucleos)) {
        $filtros_aplicados[] = "Núcleos: " . implode(', ', array_filter($nomes_nucleos));
    }
}
if (!empty($responsaveis_filtro)) {
    $nomes_resp = array_filter(array_map(function($u) use ($responsaveis_filtro) {
        return in_array($u['id'], $responsaveis_filtro) ? $u['nome'] : null;
    }, $usuarios));
    if (!empty($nomes_resp)) {
        $filtros_aplicados[] = "Responsáveis: " . implode(', ', array_filter($nomes_resp));
    }
}
if (!empty($fases_filtro)) {
    $filtros_aplicados[] = "Fases: " . implode(', ', $fases_filtro);
}
if (!empty($meio)) {
    $filtros_aplicados[] = "Meio: " . $meio;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Prospecção - SIGAM</title>
    <style>
* {margin: 0;padding: 0;box-sizing: border-box;}
body {font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;color: #2c3e50;background: #ffffff;line-height: 1.6;}
.print-container {max-width: 1200px;margin: 0 auto;padding: 40px 20px;}
.report-header {text-align: center;margin-bottom: 40px;padding-bottom: 20px;border-bottom: 3px solid #667eea;}
.report-title {font-size: 32px;font-weight: 700;color: #667eea;margin-bottom: 10px;}
.report-subtitle {font-size: 16px;color: #7f8c8d;margin-bottom: 5px;}
.report-date {font-size: 14px;color: #95a5a6;}
.filtros-aplicados {background: #f8f9fa;padding: 15px;border-radius: 8px;margin-bottom: 30px;border-left: 4px solid #667eea;}
.filtros-aplicados h3 {font-size: 14px;color: #2c3e50;margin-bottom: 10px;text-transform: uppercase;}
.filtros-aplicados ul {list-style: none;font-size: 13px;color: #555;}
.filtros-aplicados li {padding: 3px 0;}
.metrics-grid {display: grid;grid-template-columns: repeat(3, 1fr);gap: 20px;margin-bottom: 40px;}
.metric-card {background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);color: white;padding: 25px;border-radius: 12px;box-shadow: 0 4px 12px rgba(0,0,0,0.1);}
.metric-card.secondary {background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);}
.metric-card.success {background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);}
.metric-card.warning {background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);}
.metric-card.info {background: linear-gradient(135deg, #30cfd0 0%, #330867 100%);}
.metric-card.danger {background: linear-gradient(135deg, #f857a6 0%, #ff5858 100%);}
.metric-label {font-size: 13px;opacity: 0.9;margin-bottom: 8px;text-transform: uppercase;letter-spacing: 1px;}
.metric-value {font-size: 36px;font-weight: 700;margin-bottom: 8px;}
.metric-detail {font-size: 13px;opacity: 0.85;}
.section {margin-bottom: 40px;page-break-inside: avoid;}
.section-title {font-size: 22px;font-weight: 600;color: #2c3e50;margin-bottom: 20px;padding-bottom: 10px;border-bottom: 2px solid #ecf0f1;}
.funnel {display: grid;grid-template-columns: repeat(4, 1fr);gap: 15px;margin-bottom: 30px;}
.funnel-stage {background: #f8f9fa;border: 2px solid #e9ecef;border-radius: 10px;padding: 20px;text-align: center;transition: all 0.3s;}
.funnel-stage.prospeccao {border-color: #3498db;background: rgba(52, 152, 219, 0.05);}
.funnel-stage.negociacao {border-color: #f39c12;background: rgba(243, 156, 18, 0.05);}
.funnel-stage.fechados {border-color: #27ae60;background: rgba(39, 174, 96, 0.05);}
.funnel-stage.perdidos {border-color: #e74c3c;background: rgba(231, 76, 60, 0.05);}
.funnel-icon {font-size: 32px;margin-bottom: 10px;}
.funnel-label {font-size: 14px;color: #7f8c8d;margin-bottom: 8px;font-weight: 600;}
.funnel-count {font-size: 32px;font-weight: 700;color: #2c3e50;margin-bottom: 5px;}
.funnel-percentage {font-size: 13px;color: #95a5a6;}
.data-table {width: 100%;border-collapse: collapse;background: white;box-shadow: 0 2px 8px rgba(0,0,0,0.08);border-radius: 8px;overflow: hidden;}
.data-table thead {background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);color: white;}
.data-table th {padding: 15px 12px;text-align: left;font-weight: 600;font-size: 13px;text-transform: uppercase;letter-spacing: 0.5px;}
.data-table td {padding: 14px 12px;border-bottom: 1px solid #ecf0f1;font-size: 14px;}
.data-table tbody tr:hover {background-color: #f8f9fa;}
.data-table tbody tr:last-child td {border-bottom: none;}
.data-table .text-center {text-align: center;}
.data-table .text-right {text-align: right;}
.badge {display: inline-block;padding: 5px 12px;border-radius: 20px;font-size: 12px;font-weight: 600;text-align: center;}
.badge-primary {background: #3498db;color: white;}
.badge-success {background: #27ae60;color: white;}
.badge-warning {background: #f39c12;color: white;}
.badge-danger {background: #e74c3c;color: white;}
.report-footer {margin-top: 50px;padding-top: 20px;border-top: 2px solid #ecf0f1;text-align: center;color: #95a5a6;font-size: 13px;}
@media print {
    body {background: white;margin: 0;padding: 0;}
    .print-container {max-width: 100%;padding: 20px;}
    .section {page-break-inside: avoid;}
    .metric-card, .funnel-stage, .data-table {box-shadow: none !important;border: 1px solid #dee2e6 !important;}
    .metric-card, .data-table thead, .badge {-webkit-print-color-adjust: exact;print-color-adjust: exact;color-adjust: exact;}
    .metrics-grid, .funnel, .data-table, .section {page-break-inside: avoid;}
    @page {margin: 1.5cm;}
}
@media screen {
    .no-print-actions {position: fixed;top: 20px;right: 20px;z-index: 1000;}
    .btn-print {background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);color: white;border: none;padding: 12px 30px;border-radius: 8px;font-size: 14px;font-weight: 600;cursor: pointer;box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);transition: all 0.3s;}
    .btn-print:hover {transform: translateY(-2px);box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);}
}
@media print {.no-print-actions {display: none !important;}}
    </style>
</head>
<body>

<div class="no-print-actions">
    <button onclick="window.print()" class="btn-print">🖨️ Imprimir Relatório</button>
</div>

<div class="print-container">
    <div class="report-header">
        <h1 class="report-title">📊 Relatório de Prospecção - SIGAM</h1>
        <p class="report-subtitle">Análise Detalhada de Performance
        <?php if ($filtrar_por_nucleo && !empty($nucleos)): ?>
            - <?= htmlspecialchars($nucleos[0]['nome']) ?>
        <?php endif; ?>
        <?php if ($filtrar_por_cidade && $cidade_filtro): ?>
            - <?= htmlspecialchars($cidade_filtro) ?>
        <?php endif; ?>
        </p>
        <p class="report-date">Período: <?= date('d/m/Y', strtotime($data_inicio)) ?> a <?= date('d/m/Y', strtotime($data_fim)) ?></p>
        <p class="report-date">Gerado em: <?= date('d/m/Y H:i') ?></p>
    </div>

    <?php if (!empty($filtros_aplicados)): ?>
    <div class="filtros-aplicados">
        <h3>🔍 Filtros Aplicados:</h3>
        <ul>
            <?php foreach ($filtros_aplicados as $filtro): ?>
                <li>• <?= htmlspecialchars($filtro) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2 class="section-title">📈 Indicadores Principais</h2>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total de Prospectos</div>
                <div class="metric-value"><?= $stats['total'] ?></div>
                <div class="metric-detail">No período selecionado</div>
            </div>

            <div class="metric-card secondary">
                <div class="metric-label">Taxa de Conversão</div>
                <div class="metric-value"><?= $stats['taxa_conversao'] ?>%</div>
                <div class="metric-detail"><?= $stats['fase_fechados'] ?> de <?= $total_finalizados ?> finalizados</div>
            </div>

            <div class="metric-card success">
                <div class="metric-label">Valor Total Fechado</div>
                <div class="metric-value">R$ <?= number_format($stats['valor_fechado_total'], 0, ',', '.') ?></div>
                <div class="metric-detail">Ticket médio: R$ <?= number_format($stats['ticket_medio'], 2, ',', '.') ?></div>
            </div>

            <div class="metric-card warning">
                <div class="metric-label">Tempo Médio</div>
                <div class="metric-value"><?= round($stats['tempo_medio_dias']) ?></div>
                <div class="metric-detail">dias no sistema</div>
            </div>

            <div class="metric-card info">
                <div class="metric-label">Em Negociação</div>
                <div class="metric-value"><?= $stats['fase_negociacao'] ?></div>
                <div class="metric-detail">R$ <?= number_format($stats['valor_proposta_total'], 0, ',', '.') ?> em propostas</div>
            </div>

            <div class="metric-card danger">
                <div class="metric-label">Taxa de Perda</div>
                <div class="metric-value"><?= $stats['taxa_perda'] ?>%</div>
                <div class="metric-detail"><?= $stats['fase_perdidos'] ?> perdidos</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title">🎯 Funil de Conversão</h2>
        <div class="funnel">
            <div class="funnel-stage prospeccao">
                <div class="funnel-icon">🔍</div>
                <div class="funnel-label">Prospecção</div>
                <div class="funnel-count"><?= $stats['fase_prospeccao'] ?></div>
                <div class="funnel-percentage"><?= $stats['total'] > 0 ? round(($stats['fase_prospeccao'] / $stats['total']) * 100, 1) : 0 ?>% dos leads</div>
            </div>

            <div class="funnel-stage negociacao">
                <div class="funnel-icon">🤝</div>
                <div class="funnel-label">Negociação</div>
                <div class="funnel-count"><?= $stats['fase_negociacao'] ?></div>
                <div class="funnel-percentage"><?= $stats['total'] > 0 ? round(($stats['fase_negociacao'] / $stats['total']) * 100, 1) : 0 ?>% dos leads</div>
            </div>

            <div class="funnel-stage fechados">
                <div class="funnel-icon">✅</div>
                <div class="funnel-label">Fechados</div>
                <div class="funnel-count"><?= $stats['fase_fechados'] ?></div>
                <div class="funnel-percentage"><?= $stats['total'] > 0 ? round(($stats['fase_fechados'] / $stats['total']) * 100, 1) : 0 ?>% dos leads</div>
            </div>

            <div class="funnel-stage perdidos">
                <div class="funnel-icon">❌</div>
                <div class="funnel-label">Perdidos</div>
                <div class="funnel-count"><?= $stats['fase_perdidos'] ?></div>
                <div class="funnel-percentage"><?= $stats['total'] > 0 ? round(($stats['fase_perdidos'] / $stats['total']) * 100, 1) : 0 ?>% dos leads</div>
            </div>
        </div>
    </div>

    <?php if (!empty($ranking)): ?>
    <div class="section">
        <h2 class="section-title">🏆 Ranking de Responsáveis (Top 10)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Responsável</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">Prospecção</th>
                    <th class="text-center">Negociação</th>
                    <th class="text-center">Fechados</th>
                    <th class="text-center">Perdidos</th>
                    <th class="text-center">Taxa Conv.</th>
                    <th class="text-right">Valor Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $posicao = 1;
                foreach ($ranking as $r): 
                ?>
                <tr>
                    <td><strong><?= $posicao++ ?></strong></td>
                    <td><strong><?= htmlspecialchars($r['responsavel']) ?></strong></td>
                    <td class="text-center"><?= $r['total'] ?></td>
                    <td class="text-center"><?= $r['prospeccao'] ?></td>
                    <td class="text-center"><?= $r['negociacao'] ?></td>
                    <td class="text-center"><span class="badge badge-success"><?= $r['fechados'] ?></span></td>
                    <td class="text-center"><span class="badge badge-danger"><?= $r['perdidos'] ?></span></td>
                    <td class="text-center"><strong><?= $r['taxa_conversao'] ?? 0 ?>%</strong></td>
                    <td class="text-right"><strong>R$ <?= number_format($r['valor_total'], 2, ',', '.') ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($nucleos_performance)): ?>
    <div class="section">
        <h2 class="section-title">🏢 Performance por Núcleo</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Núcleo</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">Prospecção</th>
                    <th class="text-center">Negociação</th>
                    <th class="text-center">Fechados</th>
                    <th class="text-center">Perdidos</th>
                    <th class="text-center">Taxa Conv.</th>
                    <th class="text-right">Valor Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($nucleos_performance as $n): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($n['nucleo']) ?></strong></td>
                    <td class="text-center"><?= $n['total'] ?></td>
                    <td class="text-center"><?= $n['prospeccao'] ?></td>
                    <td class="text-center"><?= $n['negociacao'] ?></td>
                    <td class="text-center"><span class="badge badge-success"><?= $n['fechados'] ?></span></td>
                    <td class="text-center"><span class="badge badge-danger"><?= $n['perdidos'] ?></span></td>
                    <td class="text-center"><strong><?= $n['taxa_conversao'] ?? 0 ?>%</strong></td>
                    <td class="text-right"><strong>R$ <?= number_format($n['valor_total'], 2, ',', '.') ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="report-footer">
        <p><strong>SIGAM - Sistema Integrado de Gestão - Alencar e Martianzzo Advogados</strong></p>
        <p>Relatório gerado automaticamente • <?= date('d/m/Y H:i:s') ?></p>
    </div>
</div>

<script>
window.onload = function() { window.print(); };
</script>
</body>
</html>