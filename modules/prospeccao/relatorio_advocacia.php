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
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'] ?? $_SESSION['usuario_id'] ?? null;

// ===== CONTROLE DE ACESSO POR NÚCLEO =====
// Níveis que podem ver TODOS os prospectos (independente do núcleo)
$niveis_acesso_total = ['Admin', 'Socio', 'Diretor'];

// Se o usuário NÃO tem acesso total, filtrar por núcleo
$filtrar_por_nucleo = !in_array($nivel_acesso_logado, $niveis_acesso_total);

// EXCEÇÃO: Usuário ID 15 (Gestor Criminal) vê todos os prospectos de Chapecó
$filtrar_por_cidade = false;
$cidade_filtro = null;
if ($usuario_id == 15) {
    $filtrar_por_cidade = true;
    $cidade_filtro = 'Chapecó';
    $filtrar_por_nucleo = false; // Desabilita filtro por núcleo para este usuário
}

// Buscar núcleos do usuário logado (pode ter acesso a vários)
$nucleos_usuario = [];
if ($filtrar_por_nucleo) {
    try {
        // Buscar todos os núcleos do usuário (tabela usuarios_nucleos - CORRIGIDO)
        $sql_nucleos_usuario = "SELECT nucleo_id FROM usuarios_nucleos WHERE usuario_id = ?";
        $stmt_nucleos = executeQuery($sql_nucleos_usuario, [$usuario_id]);
        $resultados = $stmt_nucleos->fetchAll();
        
        foreach ($resultados as $row) {
            $nucleos_usuario[] = $row['nucleo_id'];
        }
        
        error_log("DEBUG advocacia.php - Núcleos encontrados em usuarios_nucleos: " . print_r($nucleos_usuario, true));
        
        // Se não encontrou na tabela de relacionamento, buscar núcleo principal
        if (empty($nucleos_usuario)) {
            $sql_nucleo_principal = "SELECT nucleo_id FROM usuarios WHERE id = ?";
            $stmt_principal = executeQuery($sql_nucleo_principal, [$usuario_id]);
            $resultado = $stmt_principal->fetch();
            if ($resultado && $resultado['nucleo_id']) {
                $nucleos_usuario[] = $resultado['nucleo_id'];
                error_log("DEBUG advocacia.php - Núcleo principal encontrado: " . $resultado['nucleo_id']);
            }
        }
        
        error_log("DEBUG advocacia.php - Núcleos finais do usuário: " . print_r($nucleos_usuario, true));
        
        // Se não tem núcleo definido, não pode ver nada
        if (empty($nucleos_usuario)) {
            error_log("ERRO advocacia.php - Usuário sem núcleos!");
            echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
            echo "<div style='padding: 40px; text-align: center;'>";
            echo "<h2>⚠️ Acesso Restrito</h2>";
            echo "<p>Você não possui núcleos associados. Entre em contato com o administrador.</p>";
            echo "<p style='font-size:12px; color:#666;'>Debug: Usuário ID {$usuario_id} | Nível: {$nivel_acesso_logado}</p>";
            echo "<a href='index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px;'>Voltar</a>";
            echo "</div></body></html>";
            exit;
        }
    } catch (Exception $e) {
        error_log("ERRO ao buscar núcleos do usuário: " . $e->getMessage());
        
        // Em caso de erro, tentar buscar da tabela usuarios diretamente
        try {
            $sql_nucleo_principal = "SELECT nucleo_id FROM usuarios WHERE id = ?";
            $stmt_principal = executeQuery($sql_nucleo_principal, [$usuario_id]);
            $resultado = $stmt_principal->fetch();
            if ($resultado && $resultado['nucleo_id']) {
                $nucleos_usuario[] = $resultado['nucleo_id'];
                error_log("DEBUG advocacia.php - Núcleo principal (fallback): " . $resultado['nucleo_id']);
            }
        } catch (Exception $e2) {
            error_log("ERRO crítico ao buscar núcleo: " . $e2->getMessage());
            $nucleos_usuario = [];
        }
    }
} else {
    error_log("DEBUG advocacia.php - Usuário tem ACESSO TOTAL (não filtrar por núcleo)");
}

// ===== MÓDULO FIXO: ADVOCACIA =====
$modulo_codigo = 'ADVOCACIA';

// --- FILTROS ---
$busca = $_GET['busca'] ?? '';
$meio = $_GET['meio'] ?? '';
$comparar = $_GET['comparar'] ?? '0';
$valor_min = $_GET['valor_min'] ?? '';
$valor_max = $_GET['valor_max'] ?? '';
$dias_fase_min = $_GET['dias_fase_min'] ?? '';
$dias_fase_max = $_GET['dias_fase_max'] ?? '';
$ranking_ordem = $_GET['ranking_ordem'] ?? 'fechados'; // padrão: número de fechados

// Filtro de período
$periodo = $_GET['periodo'] ?? '';

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

// ============================================================
// CÁLCULO DE PERÍODO - GLOBAL PARA TODO O RELATÓRIO
// ============================================================

$hoje = date('Y-m-d');

// Calcular datas baseado no período selecionado
switch ($periodo) {
    case 'custom':
        // Período personalizado
        $data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
        $data_fim = $_GET['data_fim'] ?? $hoje;
        $label_periodo = date('d/m/Y', strtotime($data_inicio)) . ' até ' . date('d/m/Y', strtotime($data_fim));
        break;
        
    case 'ano_atual':
        // Ano atual (01/01/2025 até hoje)
        $data_inicio = date('Y') . '-01-01';
        $data_fim = $hoje;
        $label_periodo = 'Ano Atual (' . date('Y') . ')';
        break;
        
    case 'mes_atual':
        // Mês atual (01/11/2025 até hoje)
        $data_inicio = date('Y-m-01');
        $data_fim = $hoje;
        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 
                  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $label_periodo = 'Mês Atual (' . $meses[intval(date('m'))] . '/' . date('Y') . ')';
        break;
        
    case '7':
        // Últimos 7 dias
        $data_inicio = date('Y-m-d', strtotime('-7 days'));
        $data_fim = $hoje;
        $label_periodo = 'Últimos 7 dias';
        break;
        
    case '30':
        // Últimos 30 dias
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = $hoje;
        $label_periodo = 'Últimos 30 dias';
        break;
        
    case '90':
        // Último trimestre
        $data_inicio = date('Y-m-d', strtotime('-90 days'));
        $data_fim = $hoje;
        $label_periodo = 'Último Trimestre (90 dias)';
        break;
        
    case '180':
        // Último semestre
        $data_inicio = date('Y-m-d', strtotime('-180 days'));
        $data_fim = $hoje;
        $label_periodo = 'Último Semestre (180 dias)';
        break;
        
    case '365':
        // Último ano
        $data_inicio = date('Y-m-d', strtotime('-365 days'));
        $data_fim = $hoje;
        $label_periodo = 'Último Ano (365 dias)';
        break;
        
    case '':
    default:
        // TOTAL - SEM FILTRO DE DATA
        $data_inicio = null;
        $data_fim = null;
        $label_periodo = 'Período Total';
        break;
}

// ============================================================
// FIM DO CÁLCULO DE PERÍODO
// ============================================================

// Se comparar, calcular período anterior
if ($comparar && !empty($data_inicio) && !empty($data_fim)) {
    $dias_diferenca = (strtotime($data_fim) - strtotime($data_inicio)) / 86400;
    $data_inicio_anterior = date('Y-m-d', strtotime($data_inicio . " -{$dias_diferenca} days"));
    $data_fim_anterior = date('Y-m-d', strtotime($data_inicio . " -1 day"));
}

// --- BUSCAR NÚCLEOS ---
try {
    // Se usuário tem acesso limitado, mostrar apenas seu núcleo
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

// --- BUSCAR USUÁRIOS ---
try {
    // Se usuário tem acesso limitado, mostrar apenas usuários do mesmo núcleo
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

// --- CONSTRUIR WHERE CLAUSE ---
$where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
$params = [$modulo_codigo];

// ===== APLICAR FILTRO POR NÚCLEO =====
if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
    $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
    $where_conditions[] = "p.nucleo_id IN ({$placeholders})";
    $params = array_merge($params, $nucleos_usuario);
}

// ===== APLICAR FILTRO POR CIDADE PARA USUÁRIO ID 15 =====
if ($filtrar_por_cidade && $cidade_filtro) {
    $where_conditions[] = "p.cidade LIKE ?";
    $params[] = "%{$cidade_filtro}%";
}

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

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// ============================================================
// FUNÇÃO HELPER: APLICAR FILTRO DE DATA
// ============================================================
function aplicarFiltroPeriodo($sql, $params, $data_inicio, $data_fim, $alias = 'p') {
    if (!empty($data_inicio)) {
        $sql .= " AND DATE(COALESCE({$alias}.data_entrada_fase, {$alias}.data_cadastro)) >= ?";
        $params[] = $data_inicio;
    }
    if (!empty($data_fim)) {
        $sql .= " AND DATE(COALESCE({$alias}.data_entrada_fase, {$alias}.data_cadastro)) <= ?";
        $params[] = $data_fim;
    }
    return ['sql' => $sql, 'params' => $params];
}
// ============================================================

// --- ESTATÍSTICAS GERAIS ---
try {
    $sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN fase = 'Prospecção' THEN 1 ELSE 0 END) as fase_prospeccao,
                    SUM(CASE WHEN fase = 'Negociação' THEN 1 ELSE 0 END) as fase_negociacao,
                    SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fase_fechados,
                    SUM(CASE WHEN fase = 'Perdidos' THEN 1 ELSE 0 END) as fase_perdidos,
                    SUM(CASE WHEN fase = 'Negociação' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_proposta_total,
                    SUM(CASE WHEN fase = 'Fechados' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_fechado_total,
                    AVG(DATEDIFF(CURRENT_DATE, data_cadastro)) as tempo_medio_dias,
                    -- NOVOS CAMPOS
                    SUM(CASE WHEN eh_recontratacao = 1 THEN 1 ELSE 0 END) as total_recontratacoes,
                    SUM(CASE WHEN fase IN ('Prospecção', 'Negociação') THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_pipeline,
                    SUM(CASE WHEN fase = 'Fechados' AND percentual_exito > 0 THEN COALESCE(estimativa_ganho, 0) ELSE 0 END) as estimativa_ganho_total,
                    SUM(CASE WHEN fase = 'Fechados' AND percentual_exito > 0 THEN 1 ELSE 0 END) as fechados_com_exito,
                    SUM(CASE WHEN tipo_cliente = 'PF' THEN 1 ELSE 0 END) as total_pf,
                    SUM(CASE WHEN tipo_cliente = 'PJ' THEN 1 ELSE 0 END) as total_pj
                  FROM prospeccoes p
                  {$where_clause}";
    
    $params_stats = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_stats, $params_stats, $data_inicio, $data_fim);
    $sql_stats = $resultado['sql'];
    $params_stats = $resultado['params'];
    
    $stmt_stats = executeQuery($sql_stats, $params_stats);
    $stats = $stmt_stats->fetch();
    
    $total_finalizados = $stats['fase_fechados'] + $stats['fase_perdidos'];
    $stats['taxa_conversao'] = $total_finalizados > 0 ? round(($stats['fase_fechados'] / $total_finalizados) * 100, 1) : 0;
    $stats['taxa_perda'] = $total_finalizados > 0 ? round(($stats['fase_perdidos'] / $total_finalizados) * 100, 1) : 0;
    $stats['ticket_medio'] = $stats['fase_fechados'] > 0 ? $stats['valor_fechado_total'] / $stats['fase_fechados'] : 0;
    $stats['perc_recontratacao'] = $stats['total'] > 0 ? round(($stats['total_recontratacoes'] / $stats['total']) * 100, 1) : 0;
    $stats['perc_pf'] = $stats['total'] > 0 ? round(($stats['total_pf'] / $stats['total']) * 100, 1) : 0;
    $stats['perc_pj'] = $stats['total'] > 0 ? round(($stats['total_pj'] / $stats['total']) * 100, 1) : 0;
    
} catch (Exception $e) {
    $stats = [
        'total' => 0, 'fase_prospeccao' => 0, 'fase_negociacao' => 0, 
        'fase_fechados' => 0, 'fase_perdidos' => 0, 'valor_proposta_total' => 0, 
        'valor_fechado_total' => 0, 'taxa_conversao' => 0, 'taxa_perda' => 0, 
        'ticket_medio' => 0, 'tempo_medio_dias' => 0,
        'total_recontratacoes' => 0, 'valor_pipeline' => 0, 
        'estimativa_ganho_total' => 0, 'fechados_com_exito' => 0,
        'total_pf' => 0, 'total_pj' => 0,
        'perc_recontratacao' => 0, 'perc_pf' => 0, 'perc_pj' => 0
    ];
}

// Se comparar, buscar stats do período anterior
if ($comparar && !empty($data_inicio_anterior) && !empty($data_fim_anterior)) {
    $params_anterior = [];
    $where_anterior = ["p.ativo = 1", "p.modulo_codigo = ?"];
    $params_anterior = [$modulo_codigo];
    
    // Aplicar mesmo filtro de núcleo/cidade
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $where_anterior[] = "p.nucleo_id IN ({$placeholders})";
        $params_anterior = array_merge($params_anterior, $nucleos_usuario);
    }
    
    if ($filtrar_por_cidade && $cidade_filtro) {
        $where_anterior[] = "p.cidade LIKE ?";
        $params_anterior[] = "%{$cidade_filtro}%";
    }
    
    if (!empty($nucleos_filtro)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_filtro), '?'));
        $where_anterior[] = "p.nucleo_id IN ({$placeholders})";
        $params_anterior = array_merge($params_anterior, $nucleos_filtro);
    }
    
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
                                SUM(CASE WHEN fase = 'Fechados' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_fechado_total
                              FROM prospeccoes p
                              {$where_clause_anterior}";
        
        // ✅ Aplicar filtro do período anterior
        $resultado_anterior = aplicarFiltroPeriodo($sql_stats_anterior, $params_anterior, $data_inicio_anterior, $data_fim_anterior);
        $sql_stats_anterior = $resultado_anterior['sql'];
        $params_anterior = $resultado_anterior['params'];
        
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
                        DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) as data,
                        COUNT(*) as total,
                        SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                        SUM(CASE WHEN fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos
                     FROM prospeccoes p
                     {$where_clause}";
    
    $params_evolucao = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_evolucao, $params_evolucao, $data_inicio, $data_fim);
    $sql_evolucao = $resultado['sql'];
    $params_evolucao = $resultado['params'];
    
    $sql_evolucao .= " GROUP BY DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) ORDER BY data ASC";
    
    $stmt_evolucao = executeQuery($sql_evolucao, $params_evolucao);
    $evolucao = $stmt_evolucao->fetchAll();
} catch (Exception $e) {
    $evolucao = [];
}

// --- RANKING POR RESPONSÁVEL (DINÂMICO) ---
try {
    // Definir ordem baseado no filtro
    switch ($ranking_ordem) {
        case 'valor':
            $order_by = "valor_total DESC, fechados DESC";
            $titulo_ranking = "💰 Ranking por Valor Total";
            break;
        case 'taxa':
            $order_by = "taxa_conversao DESC, fechados DESC";
            $titulo_ranking = "📊 Ranking por Taxa de Conversão";
            break;
        case 'fechados':
        default:
            $order_by = "fechados DESC, valor_total DESC";
            $titulo_ranking = "🏆 Ranking por Número de Fechados";
            break;
    }
    
    $sql_ranking = "SELECT 
                        u.nome as responsavel,
                        COUNT(*) as total,
                        SUM(CASE WHEN p.fase = 'Prospecção' THEN 1 ELSE 0 END) as prospeccao,
                        SUM(CASE WHEN p.fase = 'Negociação' THEN 1 ELSE 0 END) as negociacao,
                        SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                        SUM(CASE WHEN p.fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos,
                        SUM(CASE WHEN p.fase = 'Fechados' THEN COALESCE(p.valor_proposta, 0) ELSE 0 END) as valor_total,
                        ROUND((SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) / 
                              NULLIF(SUM(CASE WHEN p.fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END), 0)) * 100, 1) as taxa_conversao,
                        -- Ticket médio
                        CASE 
                            WHEN SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) > 0 
                            THEN SUM(CASE WHEN p.fase = 'Fechados' THEN COALESCE(p.valor_proposta, 0) ELSE 0 END) / 
                                 SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END)
                            ELSE 0 
                        END as ticket_medio
                    FROM prospeccoes p
                    INNER JOIN usuarios u ON p.responsavel_id = u.id
                    {$where_clause}";
    
    $params_ranking = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_ranking, $params_ranking, $data_inicio, $data_fim);
    $sql_ranking = $resultado['sql'];
    $params_ranking = $resultado['params'];
    
    $sql_ranking .= " GROUP BY u.id, u.nome
                     HAVING fechados > 0 OR valor_total > 0 OR taxa_conversao > 0
                     ORDER BY {$order_by}
                     LIMIT 10";
    
    $stmt_ranking = executeQuery($sql_ranking, $params_ranking);
    $ranking = $stmt_ranking->fetchAll();
} catch (Exception $e) {
    $ranking = [];
    $titulo_ranking = "🏆 Ranking de Responsáveis";
}

// --- PERFORMANCE POR NÚCLEO ---
try {
    $sql_nucleos_perf = "SELECT 
                            n.nome as nucleo,
                            COUNT(DISTINCT p.id) as total,
                            SUM(CASE WHEN p.fase = 'Prospecção' THEN 1 ELSE 0 END) as prospeccao,
                            SUM(CASE WHEN p.fase = 'Negociação' THEN 1 ELSE 0 END) as negociacao,
                            SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) as fechados,
                            SUM(CASE WHEN p.fase = 'Perdidos' THEN 1 ELSE 0 END) as perdidos,
                            -- Valor proporcional ao percentual do núcleo
                            SUM(CASE WHEN p.fase = 'Fechados' 
                                THEN COALESCE(p.valor_proposta, 0) * (pn.percentual / 100.0)
                                ELSE 0 
                            END) as valor_total,
                            ROUND(AVG(pn.percentual), 1) as percentual_medio,
                            ROUND((SUM(CASE WHEN p.fase = 'Fechados' THEN 1 ELSE 0 END) / 
                                  NULLIF(SUM(CASE WHEN p.fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END), 0)) * 100, 1) as taxa_conversao
                         FROM prospeccoes p
                         LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
                         LEFT JOIN nucleos n ON pn.nucleo_id = n.id
                         {$where_clause}
                         AND pn.nucleo_id IS NOT NULL";
    
    $params_nucleos = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_nucleos_perf, $params_nucleos, $data_inicio, $data_fim);
    $sql_nucleos_perf = $resultado['sql'];
    $params_nucleos = $resultado['params'];
    
    $sql_nucleos_perf .= " GROUP BY n.id, n.nome ORDER BY valor_total DESC";
    
    $stmt_nucleos_perf = executeQuery($sql_nucleos_perf, $params_nucleos);
    $nucleos_perf = $stmt_nucleos_perf->fetchAll();
} catch (Exception $e) {
    $nucleos_perf = [];
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
                      -- Buscar motivo do histórico
                      (
                          SELECT h.observacao
                          FROM prospeccoes_historico h
                          WHERE h.prospeccao_id = p.id
                          AND h.fase_nova = 'Perdidos'
                          ORDER BY h.data_movimento DESC
                          LIMIT 1
                      ) as motivo_perda,
                      GROUP_CONCAT(DISTINCT CONCAT(n.nome, ' (', pn.percentual, '%)') SEPARATOR ', ') as nucleos_str
                   FROM prospeccoes p
                   INNER JOIN usuarios u ON p.responsavel_id = u.id
                   LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
                   LEFT JOIN nucleos n ON pn.nucleo_id = n.id
                   {$where_clause}
                   AND p.fase = 'Perdidos'";
    
    $params_perdas = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_perdas, $params_perdas, $data_inicio, $data_fim);
    $sql_perdas = $resultado['sql'];
    $params_perdas = $resultado['params'];
    
    $sql_perdas .= " GROUP BY p.id ORDER BY p.data_ultima_atualizacao DESC LIMIT 10";
    
    $stmt_perdas = executeQuery($sql_perdas, $params_perdas);
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
                  AND p.fase IN ('Prospecção', 'Negociação')";
    
    $params_risco = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_risco, $params_risco, $data_inicio, $data_fim);
    $sql_risco = $resultado['sql'];
    $params_risco = $resultado['params'];
    
    $sql_risco .= " HAVING dias_parado >= 7 ORDER BY dias_parado DESC LIMIT 20";
    
    $stmt_risco = executeQuery($sql_risco, $params_risco);
    $prospectos_risco = $stmt_risco->fetchAll();
} catch (Exception $e) {
    $prospectos_risco = [];
}

// --- TOP 10 MAIORES VALORES ---
try {
    $sql_maiores_valores = "SELECT 
                               p.nome,
                               p.fase,
                               p.cidade,
                               u.nome as responsavel_nome,
                               COALESCE(p.valor_proposta, 0) as valor_proposta,
                               p.percentual_exito,
                               COALESCE(p.estimativa_ganho, 0) as estimativa_ganho,
                               p.eh_recontratacao,
                               GROUP_CONCAT(DISTINCT CONCAT(n.nome, ' (', pn.percentual, '%)') SEPARATOR ', ') as nucleos_str
                            FROM prospeccoes p
                            INNER JOIN usuarios u ON p.responsavel_id = u.id
                            LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
                            LEFT JOIN nucleos n ON pn.nucleo_id = n.id
                            {$where_clause}
                            AND p.fase IN ('Prospecção', 'Negociação', 'Fechados')
                            AND p.valor_proposta > 0";
    
    $params_maiores = $params;
    
    // ✅ Aplicar filtro de período
    $resultado = aplicarFiltroPeriodo($sql_maiores_valores, $params_maiores, $data_inicio, $data_fim);
    $sql_maiores_valores = $resultado['sql'];
    $params_maiores = $resultado['params'];
    
    $sql_maiores_valores .= " GROUP BY p.id ORDER BY p.valor_proposta DESC LIMIT 5";
    
    $stmt_maiores = executeQuery($sql_maiores_valores, $params_maiores);
    $maiores_valores = $stmt_maiores->fetchAll();
} catch (Exception $e) {
    $maiores_valores = [];
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
                        {$where_clause}";
    
    $params_tempo = $params;
    
    // ✅ Aplicar filtro de período (alias diferente: h1)
    $resultado = aplicarFiltroPeriodo($sql_tempo_fases, $params_tempo, $data_inicio, $data_fim, 'h1');
    $sql_tempo_fases = $resultado['sql'];
    $params_tempo = $resultado['params'];
    
    $sql_tempo_fases .= " GROUP BY h1.fase_nova";
    
    $stmt_tempo = executeQuery($sql_tempo_fases, $params_tempo);
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

    <?php if ($filtrar_por_nucleo): ?>
    /* Badge de filtro por núcleo */
    .nucleo-badge {
        display: inline-block;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        margin-left: 10px;
    }
    <?php endif; ?>

    <?php if ($filtrar_por_cidade): ?>
    .cidade-badge {
        display: inline-block;
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        margin-left: 10px;
    }
    <?php endif; ?>

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
    
    /* Botão Demonstrativo - Discreto */
    .btn-demonstrativo {
        background: #6c757d;
        color: white;
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
        border: 2px solid transparent;
    }
    
    .btn-demonstrativo:hover {
        background: #5a6268;
        border-color: #495057;
        transform: translateY(-1px);
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
            <h1>
                📊 Relatórios de Prospecção
                <?php if ($filtrar_por_nucleo && !empty($nucleos)): ?>
                    <span class="nucleo-badge">
                        🏢 <?= htmlspecialchars($nucleos[0]['nome']) ?>
                    </span>
                <?php endif; ?>
                <?php if ($filtrar_por_cidade && $cidade_filtro): ?>
                    <span class="cidade-badge">
                        📍 <?= htmlspecialchars($cidade_filtro) ?>
                    </span>
                <?php endif; ?>
            </h1>
            <div class="header-actions">
                <a href="demonstrativo_advocacia.php?<?= http_build_query($_GET) ?>" class="btn btn-demonstrativo">
                    📋 Demonstrativo
                </a>
                <a href="relatorios_print_advocacia.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-primary">
                    🖨️ Versão para Impressão
                </a>
                <button onclick="exportarExcel()" class="btn btn-success">
                    📥 Exportar Excel
                </button>
                <a href="advocacia.php" class="btn btn-primary">
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
        
                    <!-- Núcleos -->
                    <?php if (!$filtrar_por_nucleo || count($nucleos) > 1): ?>
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
                    <?php endif; ?>
        
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
                    
                    <!-- Ordenar Ranking Por -->
                    <div class="filter-group">
                        <label>Ordenar Ranking Por</label>
                        <select name="ranking_ordem">
                            <option value="fechados" <?= ($ranking_ordem ?? 'fechados') === 'fechados' ? 'selected' : '' ?>>
                                🏆 Nº de Fechados
                            </option>
                            <option value="valor" <?= ($ranking_ordem ?? 'fechados') === 'valor' ? 'selected' : '' ?>>
                                💰 Valor Total
                            </option>
                            <option value="taxa" <?= ($ranking_ordem ?? 'fechados') === 'taxa' ? 'selected' : '' ?>>
                                📊 Taxa de Conversão
                            </option>
                        </select>
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
                            <option value="" <?= empty($periodo) ? 'selected' : '' ?>>📊 Total (Tudo)</option>
                            <option value="7" <?= $periodo == '7' ? 'selected' : '' ?>>📅 Última Semana (7 dias)</option>
                            <option value="30" <?= $periodo == '30' ? 'selected' : '' ?>>📅 Último Mês (30 dias)</option>
                            <option value="90" <?= $periodo == '90' ? 'selected' : '' ?>>📅 Último Trimestre (90 dias)</option>
                            <option value="180" <?= $periodo == '180' ? 'selected' : '' ?>>📅 Último Semestre (180 dias)</option>
                            <option value="365" <?= $periodo == '365' ? 'selected' : '' ?>>📅 Último Ano (365 dias)</option>
                            <option value="ano_atual" <?= $periodo == 'ano_atual' ? 'selected' : '' ?>>📆 Ano Atual (2025)</option>
                            <option value="mes_atual" <?= $periodo == 'mes_atual' ? 'selected' : '' ?>>📆 Mês Atual</option>
                            <option value="custom" <?= $periodo == 'custom' ? 'selected' : '' ?>>🎯 Personalizado</option>
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
                    <a href="relatorio_advocacia.php" class="btn-clear">✖️ Limpar</a>
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
     
         <div class="stat-card info">
            <div class="stat-icon">🔄</div>
            <div class="stat-label">Recontratações</div>
            <div class="stat-value"><?= $stats['total_recontratacoes'] ?></div>
            <div class="stat-detail">
                <?= $stats['perc_recontratacao'] ?>% do total
            </div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon">🎯</div>
            <div class="stat-label">Previsão de Ganho (Êxito)</div>
            <div class="stat-value">R$ <?= number_format($stats['estimativa_ganho_total'], 0, ',', '.') ?></div>
            <div class="stat-detail">
                <?= $stats['fechados_com_exito'] ?> fechados com percentual
            </div>
        </div>
    
        <div class="stat-card info">
            <div class="stat-icon">💼</div>
            <div class="stat-label">Valor em Pipeline</div>
            <div class="stat-value">R$ <?= number_format($stats['valor_pipeline'], 0, ',', '.') ?></div>
            <div class="stat-detail">
                Prospecção + Negociação
            </div>
        </div>
    
        <div class="stat-card primary">
            <div class="stat-icon">👥</div>
            <div class="stat-label">PF vs PJ</div>
            <div class="stat-value"><?= $stats['total_pf'] ?> / <?= $stats['total_pj'] ?></div>
            <div class="stat-detail">
                PF: <?= $stats['perc_pf'] ?>% | PJ: <?= $stats['perc_pj'] ?>%
            </div>
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
                $total_max = max($stats['fase_prospeccao'], $stats['fase_negociacao'], $stats['fase_fechados'], $stats['fase_perdidos'], 1);
                
                $fases_funil = [
                    ['nome' => 'Prospecção', 'icon' => '🔍', 'valor' => $stats['fase_prospeccao'], 'cor' => 'linear-gradient(90deg, #3498db, #5dade2)'],
                    ['nome' => 'Negociação', 'icon' => '🤝', 'valor' => $stats['fase_negociacao'], 'cor' => 'linear-gradient(90deg, #f39c12, #f8b739)'],
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
        
        <!-- NOVOS GRÁFICOS -->
        
        <!-- Análise de Recontratação -->
        <div class="chart-card">
            <div class="chart-header">
                <span>🔄 Análise de Recontratação</span>
            </div>
            <div class="chart-container">
                <canvas id="chartRecontratacao"></canvas>
            </div>
        </div>

        <!-- Distribuição PF vs PJ -->
        <div class="chart-card">
            <div class="chart-header">
                <span>👥 Distribuição PF vs PJ</span>
            </div>
            <div class="chart-container">
                <canvas id="chartPfPj"></canvas>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TABELA: RANKING DE RESPONSÁVEIS COM SELETOR INTEGRADO -->
    <!-- ============================================================ -->
    
    <div class="table-card">
        <!-- HEADER COM SELETOR -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #ecf0f1;">
            <div class="table-header" style="margin: 0; padding: 0; border: none;">
                <?= $titulo_ranking ?> (Top 10)
            </div>
            
            <!-- SELETOR DE ORDENAÇÃO -->
            <div style="display: flex; align-items: center; gap: 10px;">
                <label style="font-size: 13px; font-weight: 600; color: #2c3e50;">Ordenar por:</label>
                <select 
                    id="rankingOrdem" 
                    onchange="alterarOrdemRanking()" 
                    style="
                        padding: 8px 15px;
                        border: 2px solid #667eea;
                        border-radius: 8px;
                        font-size: 14px;
                        font-weight: 600;
                        background: white;
                        cursor: pointer;
                        color: #2c3e50;
                        transition: all 0.3s ease;
                    "
                >
                    <option value="fechados" <?= $ranking_ordem === 'fechados' ? 'selected' : '' ?>>
                        🏆 Nº de Fechados
                    </option>
                    <option value="valor" <?= $ranking_ordem === 'valor' ? 'selected' : '' ?>>
                        💰 Valor Total
                    </option>
                    <option value="taxa" <?= $ranking_ordem === 'taxa' ? 'selected' : '' ?>>
                        📊 Taxa de Conversão
                    </option>
                </select>
            </div>
        </div>
    
        <!-- MINI PÓDIO VISUAL (TOP 3) -->
        <?php if (count($ranking) >= 3): ?>
        <div style="display: flex; justify-content: center; align-items: flex-end; gap: 20px; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; margin-bottom: 20px;">
            <!-- 2º Lugar -->
            <div style="text-align: center; padding: 20px; background: white; border-radius: 10px; width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.3s;">
                <div style="font-size: 48px; margin-bottom: 10px;">🥈</div>
                <div style="font-weight: 700; font-size: 14px; color: #2c3e50; margin-bottom: 8px;">
                    <?= htmlspecialchars(explode(' ', $ranking[1]['responsavel'])[0]) ?>
                </div>
                <?php if ($ranking_ordem === 'valor'): ?>
                    <div style="font-size: 18px; font-weight: 900; color: #f39c12;">
                        R$ <?= number_format($ranking[1]['valor_total'], 0, ',', '.') ?>
                    </div>
                <?php elseif ($ranking_ordem === 'taxa'): ?>
                    <div style="font-size: 18px; font-weight: 900; color: #3498db;">
                        <?= $ranking[1]['taxa_conversao'] ?>%
                    </div>
                <?php else: ?>
                    <div style="font-size: 18px; font-weight: 900; color: #27ae60;">
                        <?= $ranking[1]['fechados'] ?> fechados
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- 1º Lugar -->
            <div style="text-align: center; padding: 30px 20px; background: white; border-radius: 10px; width: 180px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); transform: translateY(-20px); transition: transform 0.3s;">
                <div style="font-size: 64px; margin-bottom: 10px;">🥇</div>
                <div style="font-weight: 900; font-size: 16px; color: #2c3e50; margin-bottom: 10px;">
                    <?= htmlspecialchars(explode(' ', $ranking[0]['responsavel'])[0]) ?>
                </div>
                <div style="background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); color: white; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-bottom: 10px;">
                    👑 LÍDER
                </div>
                <?php if ($ranking_ordem === 'valor'): ?>
                    <div style="font-size: 22px; font-weight: 900; color: #f39c12;">
                        R$ <?= number_format($ranking[0]['valor_total'], 0, ',', '.') ?>
                    </div>
                <?php elseif ($ranking_ordem === 'taxa'): ?>
                    <div style="font-size: 22px; font-weight: 900; color: #3498db;">
                        <?= $ranking[0]['taxa_conversao'] ?>%
                    </div>
                <?php else: ?>
                    <div style="font-size: 22px; font-weight: 900; color: #27ae60;">
                        <?= $ranking[0]['fechados'] ?> fechados
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- 3º Lugar -->
            <div style="text-align: center; padding: 20px; background: white; border-radius: 10px; width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.3s;">
                <div style="font-size: 48px; margin-bottom: 10px;">🥉</div>
                <div style="font-weight: 700; font-size: 14px; color: #2c3e50; margin-bottom: 8px;">
                    <?= htmlspecialchars(explode(' ', $ranking[2]['responsavel'])[0]) ?>
                </div>
                <?php if ($ranking_ordem === 'valor'): ?>
                    <div style="font-size: 18px; font-weight: 900; color: #f39c12;">
                        R$ <?= number_format($ranking[2]['valor_total'], 0, ',', '.') ?>
                    </div>
                <?php elseif ($ranking_ordem === 'taxa'): ?>
                    <div style="font-size: 18px; font-weight: 900; color: #3498db;">
                        <?= $ranking[2]['taxa_conversao'] ?>%
                    </div>
                <?php else: ?>
                    <div style="font-size: 18px; font-weight: 900; color: #27ae60;">
                        <?= $ranking[2]['fechados'] ?> fechados
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    
        <!-- TABELA COMPLETA -->
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
                        <th <?= $ranking_ordem === 'fechados' ? 'style="background: #e8f5e9; font-weight: 900;"' : '' ?>>
                            <?= $ranking_ordem === 'fechados' ? '🏆 ' : '' ?>Fechados
                        </th>
                        <th>Perdidos</th>
                        <th <?= $ranking_ordem === 'taxa' ? 'style="background: #e3f2fd; font-weight: 900;"' : '' ?>>
                            <?= $ranking_ordem === 'taxa' ? '📊 ' : '' ?>Taxa Conv.
                        </th>
                        <th <?= $ranking_ordem === 'valor' ? 'style="background: #fff3e0; font-weight: 900;"' : '' ?>>
                            <?= $ranking_ordem === 'valor' ? '💰 ' : '' ?>Valor Total
                        </th>
                        <th>Ticket Médio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $index => $resp): ?>
                        <tr>
                            <td>
                                <?php if ($index === 0): ?>
                                    <span style="font-size: 24px;">🥇</span>
                                <?php elseif ($index === 1): ?>
                                    <span style="font-size: 22px;">🥈</span>
                                <?php elseif ($index === 2): ?>
                                    <span style="font-size: 20px;">🥉</span>
                                <?php else: ?>
                                    <strong><?= $index + 1 ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($resp['responsavel']) ?></strong>
                                <?php if ($index === 0): ?>
                                    <span class="badge badge-warning" style="margin-left: 8px;">👑 Líder</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $resp['total'] ?></td>
                            <td><?= $resp['prospeccao'] ?></td>
                            <td><?= $resp['negociacao'] ?></td>
                            <td <?= $ranking_ordem === 'fechados' ? 'style="background: #e8f5e9; font-weight: 900; font-size: 16px;"' : '' ?>>
                                <span class="badge badge-success"><?= $resp['fechados'] ?></span>
                            </td>
                            <td><span class="badge badge-danger"><?= $resp['perdidos'] ?></span></td>
                            <td <?= $ranking_ordem === 'taxa' ? 'style="background: #e3f2fd; font-weight: 900; font-size: 16px;"' : '' ?>>
                                <?= $resp['taxa_conversao'] ?? 0 ?>%
                            </td>
                            <td <?= $ranking_ordem === 'valor' ? 'style="background: #fff3e0; font-weight: 900; font-size: 16px;"' : '' ?>>
                                <strong>R$ <?= number_format($resp['valor_total'], 2, ',', '.') ?></strong>
                            </td>
                            <td>R$ <?= number_format($resp['ticket_medio'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- ============================================================ -->
    <!-- JAVASCRIPT: ALTERAR ORDEM DO RANKING -->
    <!-- ============================================================ -->
    <script>
    function alterarOrdemRanking() {
        const select = document.getElementById('rankingOrdem');
        const novaOrdem = select.value;
        
        // Pegar URL atual
        const url = new URL(window.location.href);
        
        // Atualizar parâmetro ranking_ordem
        url.searchParams.set('ranking_ordem', novaOrdem);
        
        // Recarregar página com nova ordem
        window.location.href = url.toString();
    }
    
    // Hover effect no select
    document.getElementById('rankingOrdem').addEventListener('mouseenter', function() {
        this.style.transform = 'scale(1.05)';
        this.style.boxShadow = '0 4px 12px rgba(102, 126, 234, 0.3)';
    });
    
    document.getElementById('rankingOrdem').addEventListener('mouseleave', function() {
        this.style.transform = 'scale(1)';
        this.style.boxShadow = 'none';
    });
    </script>
    
    <!-- ============================================================ -->
    <!-- FIM DO RANKING -->
    <!-- ============================================================ -->

    <!-- Tabela: Performance por Núcleo -->
    <div class="table-card">
        <div class="table-header">🏢 Performance por Núcleo</div>
        <?php if (empty($nucleos_perf)): ?>
            <div class="empty-state">
                <p>Nenhum dado disponível</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Núcleo</th>
                        <th>% Médio</th>
                        <th>Total</th>
                        <th>Prospecção</th>
                        <th>Negociação</th>
                        <th>Fechados</th>
                        <th>Perdidos</th>
                        <th>Taxa Conv.</th>
                        <th>Valor Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nucleos_perf as $nuc): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($nuc['nucleo']) ?>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $nuc['total'] > 0 ? ($nuc['fechados'] / $nuc['total']) * 100 : 0 ?>%"></div>
                                </div>
                            </td>
                            <td><span class="badge badge-info"><?= $nuc['percentual_medio'] ?>%</span></td>
                            <td><?= $nuc['total'] ?></td>
                            <td><?= $nuc['prospeccao'] ?></td>
                            <td><?= $nuc['negociacao'] ?></td>
                            <td><span class="badge badge-success"><?= $nuc['fechados'] ?></span></td>
                            <td><span class="badge badge-danger"><?= $nuc['perdidos'] ?></span></td>
                            <td><?= $nuc['taxa_conversao'] ?? 0 ?>%</td>
                            <td><strong>R$ <?= number_format($nuc['valor_total'], 2, ',', '.') ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Tabela: Top 10 Maiores Valores -->
    <div class="table-card">
        <div class="table-header">💰 Top 5 Maiores Valores</div>
        <?php if (empty($maiores_valores)): ?>
            <div class="empty-state">
                <p>Nenhum dado disponível</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Prospecto</th>
                        <th>Fase</th>
                        <th>Responsável</th>
                        <th>Núcleos</th>
                        <th>Valor</th>
                        <th>% Êxito</th>
                        <th>Prev. Ganho</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($maiores_valores as $index => $mv): ?>
                        <tr>
                            <td><strong><?= $index + 1 ?></strong></td>
                            <td>
                                <?= htmlspecialchars($mv['nome']) ?>
                                <?php if ($mv['eh_recontratacao']): ?>
                                    <span class="badge badge-success" style="margin-left: 5px;">🔄</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info"><?= $mv['fase'] ?></span></td>
                            <td><?= htmlspecialchars($mv['responsavel_nome']) ?></td>
                            <td style="font-size: 11px;"><?= htmlspecialchars($mv['nucleos_str'] ?: 'N/A') ?></td>
                            <td><strong>R$ <?= number_format($mv['valor_proposta'], 2, ',', '.') ?></strong></td>
                            <td><?= $mv['percentual_exito'] ? $mv['percentual_exito'] . '%' : '-' ?></td>
                            <td><?= $mv['estimativa_ganho'] > 0 ? 'R$ ' . number_format($mv['estimativa_ganho'], 2, ',', '.') : '-' ?></td>
                            <td>
                                <?php if ($mv['fase'] == 'Fechados'): ?>
                                    <span class="badge badge-success">✅ Fechado</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⏳ Em andamento</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Tabela: Análise de Perdas -->
    <?php if (!empty($perdas)): ?>
        <div class="table-card">
            <div class="table-header">❌ Análise de Perdas (Últimos 10)</div>
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
        labels: ['Prospecção', 'Negociação', 'Fechados', 'Perdidos'],
        datasets: [{
            data: [
                <?= $stats['fase_prospeccao'] ?>,
                <?= $stats['fase_negociacao'] ?>,
                <?= $stats['fase_fechados'] ?>,
                <?= $stats['fase_perdidos'] ?>
            ],
            backgroundColor: ['#3498db', '#f39c12', '#27ae60', '#e74c3c']
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

// Gráfico de Recontratação
const ctxRecontratacao = document.getElementById('chartRecontratacao').getContext('2d');
new Chart(ctxRecontratacao, {
    type: 'doughnut',
    data: {
        labels: ['Primeiro Contato', 'Recontratação'],
        datasets: [{
            data: [
                <?= $stats['total'] - $stats['total_recontratacoes'] ?>,
                <?= $stats['total_recontratacoes'] ?>
            ],
            backgroundColor: ['#3498db', '#27ae60']
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

// Gráfico PF vs PJ
const ctxPfPj = document.getElementById('chartPfPj').getContext('2d');
new Chart(ctxPfPj, {
    type: 'bar',
    data: {
        labels: ['Pessoa Física', 'Pessoa Jurídica'],
        datasets: [{
            label: 'Quantidade',
            data: [<?= $stats['total_pf'] ?>, <?= $stats['total_pj'] ?>],
            backgroundColor: ['#3498db', '#f39c12']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true }
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