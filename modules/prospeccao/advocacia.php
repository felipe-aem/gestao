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

// ===== DEBUG: VERIFICAR NÍVEL DE ACESSO =====
// REMOVER DEPOIS DE TESTAR
error_log("DEBUG advocacia.php - Usuário ID: " . $usuario_id);
error_log("DEBUG advocacia.php - Nível de Acesso: " . $nivel_acesso_logado);
error_log("DEBUG advocacia.php - Array completo do usuário: " . print_r($usuario_logado, true));

// ===== CONTROLE DE ACESSO POR NÚCLEO =====
// APENAS estes níveis podem ver TODOS os prospectos (independente do núcleo)
$niveis_acesso_total = ['Admin', 'Socio', 'Diretor'];

// Se o usuário NÃO tem acesso total, filtrar por núcleo
$filtrar_por_nucleo = !in_array($nivel_acesso_logado, $niveis_acesso_total);

// EXCEÇÃO: Usuário ID 15 (Gestor Criminal) vê todos os prospectos de Chapecó
//$filtrar_por_cidade = false;
//$cidade_filtro = null;
//if ($usuario_id == 15) {
//    $filtrar_por_cidade = true;
//    $cidade_filtro = 'Chapecó';
//    $filtrar_por_nucleo = false; // Desabilita filtro por núcleo para este usuário
//    error_log("DEBUG advocacia.php - EXCEÇÃO USER 15: Filtrar por Chapecó");
//}

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

// --- PERMISSÕES ---
$usuarios_especiais = [28, 13, 15]; // IDs dos usuários com permissão especial
$pode_criar_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']) 
                     || in_array($usuario_id, $usuarios_especiais);

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
    // Se usuário tem acesso total (Admin/Socio/Diretor), mostrar TODOS os núcleos
    if (!$filtrar_por_nucleo) {
        $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome ASC";
        $stmt_nucleos = executeQuery($sql_nucleos);
        $nucleos = $stmt_nucleos->fetchAll();
    } 
    // Se usuário tem acesso limitado E tem núcleos definidos
    else if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 AND id IN ({$placeholders}) ORDER BY nome ASC";
        $stmt_nucleos = executeQuery($sql_nucleos, $nucleos_usuario);
        $nucleos = $stmt_nucleos->fetchAll();
    }
    // Usuário sem núcleos definidos
    else {
        $nucleos = [];
    }
    
    // DEBUG
    error_log("DEBUG - Núcleos disponíveis para exibição: " . count($nucleos));
    error_log("DEBUG - IDs dos núcleos: " . implode(', ', array_column($nucleos, 'id')));
    
} catch (Exception $e) {
    error_log("ERRO ao buscar núcleos disponíveis: " . $e->getMessage());
    $nucleos = [];
}

// --- BUSCAR USUÁRIOS DISPONÍVEIS ---
try {
    // Se usuário tem acesso total (Admin/Socio/Diretor), mostrar TODOS os usuários
    if (!$filtrar_por_nucleo) {
        $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
        $stmt_usuarios = executeQuery($sql_usuarios);
        $usuarios = $stmt_usuarios->fetchAll();
    }
    // Se usuário tem acesso limitado E tem núcleos definidos
    else if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 AND nucleo_id IN ({$placeholders}) ORDER BY nome ASC";
        $stmt_usuarios = executeQuery($sql_usuarios, $nucleos_usuario);
        $usuarios = $stmt_usuarios->fetchAll();
    }
    // Usuário sem núcleos definidos
    else {
        $usuarios = [];
    }
    
    // DEBUG
    error_log("DEBUG - Usuários disponíveis para exibição: " . count($usuarios));
    
} catch (Exception $e) {
    error_log("ERRO ao buscar usuários disponíveis: " . $e->getMessage());
    $usuarios = [];
}

// --- BUSCAR ESTATÍSTICAS GERAIS ---
try {
    // Função para criar slug sem acentos (já existe, manter)
    function criar_slug_fase($texto) {
        $mapa_acentos = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E',
            'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ç' => 'C'
        ];
        $texto = strtr($texto, $mapa_acentos);
        return strtolower(str_replace(' ', '_', $texto));
    }
    
    $stats_fields = "COUNT(*) as total";
    // Adicionar contagem dinâmica para cada fase do módulo
    foreach ($fases_disponiveis as $fase) {
        $fase_slug = criar_slug_fase($fase);
        $stats_fields .= ", SUM(CASE WHEN fase = '{$fase}' THEN 1 ELSE 0 END) as fase_{$fase_slug}";
    }
    $stats_fields .= ", SUM(CASE WHEN meio = 'Online' THEN 1 ELSE 0 END) as meio_online";
    $stats_fields .= ", SUM(CASE WHEN meio = 'Presencial' THEN 1 ELSE 0 END) as meio_presencial";
    // Somar valores por fase específica
    $stats_fields .= ", SUM(CASE WHEN fase = 'Prospecção' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_prospeccao_total";
    $stats_fields .= ", SUM(CASE WHEN fase = 'Negociação' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_negociacao_total";
    $stats_fields .= ", SUM(CASE WHEN fase = 'Fechados' THEN COALESCE(valor_proposta, 0) ELSE 0 END) as valor_fechado_total";
    
    $stats_sql = "SELECT $stats_fields FROM prospeccoes WHERE ativo = 1 AND modulo_codigo = ?";
    $params_stats = [$modulo_codigo];
    
    // APLICAR FILTRO POR NÚCLEO NAS ESTATÍSTICAS (COM FALLBACK)
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders_stats = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $stats_sql .= " AND (
            -- Prospectos com rateio
            EXISTS (
                SELECT 1 FROM prospeccoes_nucleos pn_stats 
                WHERE pn_stats.prospeccao_id = prospeccoes.id 
                AND pn_stats.nucleo_id IN ({$placeholders_stats})
            )
            OR
            -- FALLBACK: Prospectos sem rateio
            (
                NOT EXISTS (
                    SELECT 1 FROM prospeccoes_nucleos pn_check_stats
                    WHERE pn_check_stats.prospeccao_id = prospeccoes.id
                )
                AND prospeccoes.nucleo_id IN ({$placeholders_stats})
            )
        )";
        $params_stats = array_merge($params_stats, $nucleos_usuario, $nucleos_usuario);
    }
    
    // APLICAR FILTRO POR CIDADE PARA USUÁRIO ID 15 (usa LIKE para pegar variações)
    if ($filtrar_por_cidade && $cidade_filtro) {
        $stats_sql .= " AND cidade LIKE ?";
        $params_stats[] = "%{$cidade_filtro}%";
    }
    
    // ============================================================
    // NOVO: APLICAR FILTRO DE DATA NAS ESTATÍSTICAS
    // ============================================================
    if (!empty($data_inicio)) {
        $stats_sql .= " AND DATE(COALESCE(data_entrada_fase, data_cadastro)) >= ?";
        $params_stats[] = $data_inicio;
    }
    
    if (!empty($data_fim)) {
        $stats_sql .= " AND DATE(COALESCE(data_entrada_fase, data_cadastro)) <= ?";
        $params_stats[] = $data_fim;
    }
    // ============================================================
    // FIM DA ADIÇÃO
    // ============================================================
    
    $stmt_stats = executeQuery($stats_sql, $params_stats);
    $stats = $stmt_stats->fetch();
    
    $total_finalizados = $stats['fase_fechados'] + $stats['fase_perdidos'];
    $stats['taxa_conversao'] = $total_finalizados > 0 ? round(($stats['fase_fechados'] / $total_finalizados) * 100, 1) : 0;
    $stats['taxa_perda'] = $total_finalizados > 0 ? round(($stats['fase_perdidos'] / $total_finalizados) * 100, 1) : 0;
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas: " . $e->getMessage());
    $stats = [
        'total' => 0, 'fase_prospeccao' => 0, 'fase_negociacao' => 0,
        'fase_fechados' => 0, 'fase_perdidos' => 0, 'meio_online' => 0,
        'meio_presencial' => 0, 
        'valor_prospeccao_total' => 0, 
        'valor_negociacao_total' => 0, 
        'valor_fechado_total' => 0,
        'taxa_conversao' => 0, 'taxa_perda' => 0
    ];
}

// --- CONSTRUIR QUERY COM FILTROS ---
$where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
$params = [$modulo_codigo];

// ===== APLICAR FILTRO POR NÚCLEO =====
if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
    $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prospeccoes_nucleos pn_filter 
        WHERE pn_filter.prospeccao_id = p.id 
        AND pn_filter.nucleo_id IN ({$placeholders})
    )";
    $params = array_merge($params, $nucleos_usuario);
}

// ===== APLICAR FILTRO POR CIDADE PARA USUÁRIO ID 15 (usa LIKE para pegar variações) =====
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
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prospeccoes_nucleos pn2 
        WHERE pn2.prospeccao_id = p.id 
        AND pn2.nucleo_id IN ({$placeholders})
    )";
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
    $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) >= ?";
    $params[] = $data_inicio;
}

if (!empty($data_fim)) {
    $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) <= ?";
    $params[] = $data_fim;
}

if (!empty($valor_min)) {
    $valor_min_num = str_replace(['.', ','], ['', '.'], $valor_min);
    $where_conditions[] = "COALESCE(p.valor_proposta, 0) >= ?";
    $params[] = $valor_min_num;
}

if (!empty($valor_max)) {
    $valor_max_num = str_replace(['.', ','], ['', '.'], $valor_max);
    $where_conditions[] = "COALESCE(p.valor_proposta, 0) <= ?";
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
               u.nome as responsavel_nome,
               uc.nome as criado_por_nome,
               DATEDIFF(CURRENT_DATE, p.data_ultima_atualizacao) as dias_na_fase,
               DATEDIFF(CURRENT_DATE, p.data_cadastro) as dias_total,
               p.estimativa_ganho,
               GROUP_CONCAT(DISTINCT CONCAT(n.nome, ' (', pn.percentual, '%)') SEPARATOR ', ') as nucleos_str
            FROM prospeccoes p
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            LEFT JOIN usuarios uc ON p.criado_por = uc.id
            LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
            LEFT JOIN nucleos n ON pn.nucleo_id = n.id
            $where_clause
            GROUP BY p.id
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
    echo "<pre>Núcleo do usuário: " . htmlspecialchars($nucleo_usuario ?? 'NÃO DEFINIDO') . "</pre>";
    echo "<pre>Filtrar por núcleo: " . ($filtrar_por_nucleo ? 'SIM' : 'NÃO') . "</pre>";
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
        display: flex;
        gap: 20px;
        margin-bottom: 30px;
        overflow-x: auto;
        padding-bottom: 10px;
    }
    
    /* Scrollbar personalizada (opcional) */
    .kanban-container::-webkit-scrollbar {
        height: 10px;
    }
    
    .kanban-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    .kanban-container::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 10px;
    }
    
    .kanban-container::-webkit-scrollbar-thumb:hover {
        background: #5568d3;
    }

    /* Telas médias - 2 colunas */
    @media (max-width: 1200px) {
        .kanban-container {
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
    }

    /* Mobile - 1 coluna */
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
        width: 320px; 
        flex-shrink: 0;
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

    .kanban-column.inviaveis .kanban-header {
        background: linear-gradient(135deg, #95a5a6, #b2bec3);
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

    .kanban-column.inviaveis .kanban-card {
        border-left-color: #95a5a6;
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
    
    /* Badge Recontratação no Kanban */
    .badge-recontratacao {
        display: inline-block;
        background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
        color: white;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 700;
        margin-left: 8px;
        vertical-align: middle;
        box-shadow: 0 2px 6px rgba(39, 174, 96, 0.3);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .badge-em-analise {
        display: inline-block;
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        color: #000;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 700;
        margin-left: 8px;
        vertical-align: middle;
        box-shadow: 0 2px 6px rgba(255, 193, 7, 0.4);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
</style>

<div class="page-header" id="pageHeader">
    <div class="header-title">
        <div>
            <h1>
                <i class="<?= htmlspecialchars($modulo_atual['icone']) ?>" 
                   style="color: <?= htmlspecialchars($modulo_atual['cor']) ?>"></i>
                <?= htmlspecialchars($modulo_atual['nome']) ?> - Prospecção
                
                <?php if ($filtrar_por_nucleo && !empty($nucleos)): ?>
                    <?php if (count($nucleos) == 1): ?>
                        <span class="nucleo-badge">
                            🏢 <?= htmlspecialchars($nucleos[0]['nome']) ?>
                        </span>
                    <?php else: ?>
                        <span class="nucleo-badge">
                            🏢 <?= count($nucleos) ?> Núcleos
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($filtrar_por_cidade && $cidade_filtro): ?>
                    <span class="nucleo-badge" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);">
                        📍 <?= htmlspecialchars($cidade_filtro) ?>
                    </span>
                <?php endif; ?>
            </h1>
            <?php if ($total_filtrado != $stats['total']): ?>
                <span class="filter-badge"><?= $total_filtrado ?> de <?= $stats['total'] ?> prospectos</span>
            <?php endif; ?>
            <?php if (!empty($data_inicio) && !empty($data_fim)): ?>
                <span class="filter-badge" style="
                    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                    color: white;
                    padding: 8px 16px;
                    border-radius: 20px;
                    font-size: 13px;
                    font-weight: 600;
                    margin-left: 10px;
                    display: inline-block;
                ">
                    📅 <?= date('d/m/Y', strtotime($data_inicio)) ?> 
                    até 
                    <?= date('d/m/Y', strtotime($data_fim)) ?>
                    
                    <?php if (!empty($periodo) && $periodo != 'custom'): ?>
                        (Últimos <?= $periodo ?> dias)
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar aos Módulos
            </a>
            <?php if ($pode_criar_editar): ?>
                <a href="novo_advocacia.php" class="btn-primary">
                    ➕ Novo Prospecto
                </a>
            <?php endif; ?>
            <a href="relatorio_advocacia.php" class="btn-secondary">
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
                Valor em negoc.: R$ <?= number_format($stats['valor_negociacao_total'], 2, ',', '.') ?>
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
                            $fases_opcoes = ['Prospecção', 'Negociação', 'Fechados', 'Perdidos', 'Inviáveis'];
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
    
                <!-- Data Início -->
                <div class="filter-group" id="customDatesStart">
                    <label>Data Início</label>
                    <input 
                        type="date" 
                        name="data_inicio" 
                        value="<?= $data_inicio ?>"
                        <?= $periodo != 'custom' && !empty($periodo) ? 'readonly' : '' ?>
                        style="<?= $periodo != 'custom' && !empty($periodo) ? 'background: #f0f0f0;' : '' ?>"
                    >
                </div>
                
                <!-- Data Fim -->
                <div class="filter-group" id="customDatesEnd">
                    <label>Data Fim</label>
                    <input 
                        type="date" 
                        name="data_fim" 
                        value="<?= $data_fim ?>"
                        <?= $periodo != 'custom' && !empty($periodo) ? 'readonly' : '' ?>
                        style="<?= $periodo != 'custom' && !empty($periodo) ? 'background: #f0f0f0;' : '' ?>"
                    >
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
                <a href="advocacia.php" class="btn-clear">✖️ Limpar</a>
            </div>
        </form>
    </div>
</div>

<!-- Kanban Board -->
<div class="kanban-container">
    <?php 
    $fases = [
        'Prospecção' => ['icon' => '🔍', 'class' => 'prospeccao'],
        'Negociação' => ['icon' => '🤝', 'class' => 'negociacao'],
        'Fechados' => ['icon' => '✅', 'class' => 'fechados'],
        'Perdidos' => ['icon' => '❌', 'class' => 'perdidos'],
        'Inviáveis' => ['icon' => '🚫', 'class' => 'inviaveis']
    ];
    
    foreach ($fases as $fase_nome => $fase_info): 
        $prospectos_fase = $prospectos_por_fase[$fase_nome];
        $count = count($prospectos_fase);
    ?>
        <div class="kanban-column <?= $fase_info['class'] ?>" data-fase="<?= $fase_nome ?>">
            <div class="kanban-header">
                <span><?= $fase_info['icon'] ?> <?= $fase_nome ?></span>
                <span class="kanban-count"><?= $count ?></span>
            </div>

            <?php if (empty($prospectos_fase)): ?>
                <div class="empty-state">
                    <i><?= $fase_info['icon'] ?></i>
                    <p>Nenhum prospecto</p>
                </div>
            <?php else: ?>
                <?php foreach ($prospectos_fase as $prospecto): ?>
                    <div class="kanban-card" data-id="<?= $prospecto['id'] ?>">
                        <div class="card-title"><?= htmlspecialchars($prospecto['nome']) ?>
                            <!-- Badge de Recontratação -->
                            <?php if (!empty($prospecto['eh_recontratacao']) && $prospecto['eh_recontratacao'] == 1): ?>
                                <span class="badge-recontratacao" title="Cliente retornando">
                                    🔄 Recontratação
                                </span>
                            <?php endif; ?>
                            <?php if ($prospecto['em_analise']): ?>
                                <span class="badge badge-em-analise">⏳ Em Análise</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-info">
                            📍 <?= htmlspecialchars($prospecto['cidade']) ?>
                        </div>
                        
                        <div class="card-info">
                            📞 <?= htmlspecialchars($prospecto['telefone']) ?>
                        </div>
                        
                        <?php if ($prospecto['tipo_cliente'] === 'PJ' && !empty($prospecto['responsavel_contato'])): ?>
                        <div class="card-info" style="font-size: 11px; color: #7f8c8d;">
                            👔 <?= htmlspecialchars($prospecto['responsavel_contato']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="card-info">
                            👤 <?= htmlspecialchars($prospecto['responsavel_nome']) ?>
                        </div>
                        
                        <?php if (!empty($prospecto['nucleos_str'])): ?>
                        <div class="card-info" style="font-size: 11px;">
                            🏢 <?= htmlspecialchars($prospecto['nucleos_str']) ?>
                        </div>
                        <?php endif; ?>

                        <span class="card-badge badge-<?= strtolower($prospecto['meio']) ?>">
                            <?= $prospecto['meio'] ?>
                        </span>

                        <div class="card-footer">
                            <?php if ($prospecto['valor_proposta']): ?>
                                <div class="card-valor">
                                    R$ <?= number_format($prospecto['valor_proposta'], 2, ',', '.') ?>
                                    <?php if (!empty($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                                        <span style="font-size: 11px; color: #27ae60; font-weight: bold;"> • <?= number_format($prospecto['percentual_exito'], 0) ?>%</span>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!empty($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                                <div class="card-valor" style="color: #3498db;">
                                    <?= number_format($prospecto['percentual_exito'], 0) ?>% de êxito
                                </div>
                            <?php else: ?>
                                <div style="color: #95a5a6;">Sem valor</div>
                            <?php endif; ?>
                            <?php if (!empty($prospecto['estimativa_ganho']) && $prospecto['estimativa_ganho'] > 0): ?>
                                <div style="margin-top: 8px; font-size: 11px; color: #f39c12; padding: 5px 10px; background: rgba(243, 156, 18, 0.1); border-radius: 6px; display: inline-block;">
                                    🎯 Prev. Êxito: R$ <?= number_format($prospecto['estimativa_ganho'], 2, ',', '.') ?>
                                </div>
                            <?php endif; ?>
                            <div class="card-tempo <?= $prospecto['dias_na_fase'] > 7 ? 'alerta' : '' ?>">
                                🕐 <?= $prospecto['dias_na_fase'] ?> <?= $prospecto['dias_na_fase'] == 1 ? 'dia' : 'dias' ?>
                            </div>
                        </div>

                        <div class="card-actions">
                            <a href="visualizar_advocacia.php?id=<?= $prospecto['id'] ?>" class="btn-card btn-view">
                                👁️ Ver
                            </a>
                            <?php if ($pode_criar_editar): ?>
                                <a href="editar_advocacia.php?id=<?= $prospecto['id'] ?>" class="btn-card btn-edit">
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
    const dataInicio = document.querySelector('[name="data_inicio"]');
    const dataFim = document.querySelector('[name="data_fim"]');
    
    if (periodo === 'custom') {
        // Modo personalizado - pode editar
        dataInicio.removeAttribute('readonly');
        dataFim.removeAttribute('readonly');
        dataInicio.style.background = 'white';
        dataFim.style.background = 'white';
        dataInicio.focus();
    } else if (periodo !== '') {
        // Período predefinido - readonly
        dataInicio.setAttribute('readonly', 'readonly');
        dataFim.setAttribute('readonly', 'readonly');
        dataInicio.style.background = '#f0f0f0';
        dataFim.style.background = '#f0f0f0';
        
        // Calcular datas automaticamente
        const hoje = new Date();
        const dataFinal = hoje.toISOString().split('T')[0];
        const dataInicial = new Date(hoje);
        dataInicial.setDate(dataInicial.getDate() - parseInt(periodo));
        
        dataInicio.value = dataInicial.toISOString().split('T')[0];
        dataFim.value = dataFinal;
    } else {
        // Sem filtro - limpar
        dataInicio.value = '';
        dataFim.value = '';
        dataInicio.removeAttribute('readonly');
        dataFim.removeAttribute('readonly');
        dataInicio.style.background = 'white';
        dataFim.style.background = 'white';
    }
}

// Executar ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    toggleCustomDates();
});

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
        text.textContent = `Todos`;
        allCheckbox.checked = false;
    } else if (checkboxes.length === totalCheckboxes) {
        text.textContent = `Todos`;
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
        card.style.cursor = 'grab';
        
        card.addEventListener('dragstart', function(e) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('prospecto_id', this.dataset.id);
            this.style.opacity = '0.5';
            this.style.cursor = 'grabbing';
        });
        
        card.addEventListener('dragend', function() {
            this.style.opacity = '1';
            this.style.cursor = 'grab';
        });
        
        // Clicar no título abre visualização
        const title = card.querySelector('h3');
        if (title) {
            title.style.cursor = 'pointer';
            title.addEventListener('click', function(e) {
                e.stopPropagation();
                window.location.href = 'visualizar_advocacia.php?id=' + card.dataset.id;
            });
        }
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

            // Se for para "Fechados", mostrar popup de confirmação de valores
            if (nova_fase === 'Fechados') {
                mostrarPopupConfirmacao(prospecto_id, nova_fase);
                return;
            }
            
            // Se for para "Perdidos", mostrar popup de motivo da perda
            if (nova_fase === 'Perdidos') {
                mostrarPopupMotivoPerdido(prospecto_id, nova_fase);
                return;
            }
            
            // Para outras fases, mover diretamente
            const loadingMsg = document.createElement('div');
            loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 10px; z-index: 9999; font-size: 16px;';
            loadingMsg.textContent = '⏳ Movendo prospecto...';
            document.body.appendChild(loadingMsg);
            
            executarMovimento(prospecto_id, nova_fase, '', null, null, true, loadingMsg);
        });
    });
});

// Função para mostrar popup de motivo da perda
function mostrarPopupMotivoPerdido(prospecto_id, nova_fase) {
    // Criar overlay
    const overlay = document.createElement('div');
    overlay.id = 'popupMotivoPerdido';
    overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 99999; display: flex; align-items: center; justify-content: center;';
    
    // Criar popup
    const popup = document.createElement('div');
    popup.style.cssText = 'background: white; padding: 30px; border-radius: 12px; max-width: 600px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3);';
    popup.innerHTML = `
        <h3 style="margin: 0 0 20px 0; color: #e74c3c; font-size: 22px; display: flex; align-items: center; gap: 10px;">
            ❌ Motivo da Perda
        </h3>
        <p style="margin-bottom: 25px; color: #555; font-size: 15px;">
            Por favor, informe o motivo da perda deste prospecto para análise futura:
        </p>
        
        <!-- Campo de Motivo -->
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #2c3e50; font-size: 14px;">
                📝 Motivo da Perda
            </label>
            <textarea id="motivoPerdido" 
                      rows="5"
                      placeholder="Ex: Cliente escolheu outro escritório, valor muito alto, desistiu da ação, não retornou contato..."
                      style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
            <small style="color: #7f8c8d; font-size: 12px;">Este motivo será registrado no histórico para análise de perdas</small>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button id="btnConfirmarPerda" style="flex: 1; padding: 14px; background: linear-gradient(135deg, #e74c3c, #ec7063); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3); transition: all 0.3s;">
                ✅ Confirmar Perda
            </button>
            <button id="btnCancelarPerda" style="padding: 14px 20px; background: #95a5a6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; transition: all 0.3s;">
                ❌ Cancelar
            </button>
        </div>
    `;
    
    overlay.appendChild(popup);
    document.body.appendChild(overlay);
    
    // Hover effects
    const buttons = popup.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', () => {
            btn.style.transform = 'translateY(-2px)';
            btn.style.boxShadow = '0 6px 20px rgba(0,0,0,0.2)';
        });
        btn.addEventListener('mouseleave', () => {
            btn.style.transform = 'translateY(0)';
        });
    });
    
    // Foco automático no textarea
    setTimeout(() => {
        document.getElementById('motivoPerdido').focus();
    }, 100);
    
    // Evento confirmar
    document.getElementById('btnConfirmarPerda').addEventListener('click', () => {
        const motivo = document.getElementById('motivoPerdido').value.trim();
        
        if (!motivo) {
            alert('⚠️ Por favor, informe o motivo da perda.');
            document.getElementById('motivoPerdido').focus();
            return;
        }
        
        document.body.removeChild(overlay);
        
        const loadingMsg = document.createElement('div');
        loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.9); color: white; padding: 25px 50px; border-radius: 12px; z-index: 9999; font-size: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.3);';
        loadingMsg.innerHTML = `
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="width: 24px; height: 24px; border: 3px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <span>Registrando perda...</span>
            </div>
            <style>
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            </style>
        `;
        document.body.appendChild(loadingMsg);
        
        executarMovimento(prospecto_id, nova_fase, motivo, null, null, true, loadingMsg);
    });
    
    // Evento cancelar
    document.getElementById('btnCancelarPerda').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    // Fechar com ESC
    const fecharComEsc = function(e) {
        if (e.key === 'Escape' && document.getElementById('popupMotivoPerdido')) {
            document.body.removeChild(overlay);
            document.removeEventListener('keydown', fecharComEsc);
        }
    };
    document.addEventListener('keydown', fecharComEsc);
    
    // Enter para confirmar (quando não estiver no textarea)
    const confirmarComEnter = function(e) {
        if (e.key === 'Enter' && e.ctrlKey && document.getElementById('popupMotivoPerdido')) {
            document.getElementById('btnConfirmarPerda').click();
        }
    };
    document.addEventListener('keydown', confirmarComEnter);
}

// Função para mostrar popup de confirmação de valores (FECHADOS - AJUSTADA COM PREVISÃO DE GANHO)
function mostrarPopupConfirmacao(prospecto_id, nova_fase) {
    // Primeiro, buscar os valores atuais
    fetch('mover_fase.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            prospecto_id: prospecto_id,
            nova_fase: nova_fase
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.requires_confirmation) {
            const valores = data.valores_atuais;
            
            // Criar overlay
            const overlay = document.createElement('div');
            overlay.id = 'popupConfirmacao';
            overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 99999; display: flex; align-items: center; justify-content: center;';
            
            // Criar popup
            const popup = document.createElement('div');
            popup.style.cssText = 'background: white; padding: 30px; border-radius: 12px; max-width: 550px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;';
            popup.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #2c3e50; font-size: 20px;">
                    ✅ Confirmar Fechamento
                </h3>
                <p style="margin-bottom: 25px; color: #555; font-size: 15px;">
                    Os valores e/ou percentuais se mantêm os mesmos?
                </p>
                
                <!-- Valores Atuais -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <div style="margin-bottom: 10px;">
                        <strong>💰 Valor Atual:</strong> 
                        R$ ${valores.valor_proposta ? parseFloat(valores.valor_proposta).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '0,00'}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>📊 Percentual Atual:</strong> 
                        ${valores.percentual_exito ? parseFloat(valores.percentual_exito).toFixed(0) + '%' : 'Não informado'}
                    </div>
                    ${valores.estimativa_ganho > 0 ? `
                    <div>
                        <strong>🎯 Previsão de Ganho (Êxito):</strong> 
                        R$ ${parseFloat(valores.estimativa_ganho).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                    </div>
                    ` : ''}
                </div>
                
                <!-- Campos de Edição (ocultos inicialmente) -->
                <div id="camposEdicao" style="display: none; margin-bottom: 20px;">
                    
                    <!-- Valor Fechado -->
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #555;">
                            💰 Valor Fechado (Honorários)
                        </label>
                        <input type="text" id="novoValor" class="form-control money-input" 
                               value="${valores.valor_proposta || ''}" 
                               style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                    </div>
                    
                    <!-- Percentual Êxito -->
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #555;">
                            📊 Percentual de Êxito (%)
                        </label>
                        <input type="number" id="novoPercentual" 
                               value="${valores.percentual_exito || ''}" 
                               min="0" max="100" step="1"
                               placeholder="0-100"
                               style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                            💡 Percentual que será ganho no êxito no futuro
                        </small>
                    </div>
                    
                    <!-- Previsão de Ganho (NOVO) -->
                    <div style="margin-bottom: 15px; background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #f39c12;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #555;">
                            🎯 Previsão de Ganho (Êxito Estimado)
                        </label>
                        <input type="text" id="novaEstimativa" class="form-control money-input" 
                               value="${valores.estimativa_ganho > 0 ? parseFloat(valores.estimativa_ganho).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : ''}" 
                               placeholder="0,00"
                               style="width: 100%; padding: 10px; border: 2px solid #f39c12; border-radius: 6px; font-size: 14px; background: white;">
                        <small style="color: #856404; display: block; margin-top: 5px;">
                            💡 Quanto você estima ganhar com o percentual de êxito?
                        </small>
                    </div>
                    
                    <!-- Observações -->
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #555;">
                            📝 Observações sobre o Fechamento
                        </label>
                        <textarea id="observacaoFechamento" 
                                  placeholder="Ex: Cliente aceitou proposta com entrada + 10 parcelas"
                                  style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; min-height: 80px; resize: vertical;"></textarea>
                    </div>
                </div>
                
                <!-- Botões -->
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button id="btnSim" style="flex: 1; padding: 12px; background: #27ae60; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; transition: all 0.3s;">
                        ✅ Sim, manter valores
                    </button>
                    <button id="btnNao" style="flex: 1; padding: 12px; background: #f39c12; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; transition: all 0.3s;">
                        ✏️ Não, editar
                    </button>
                    <button id="btnCancelar" style="padding: 12px 20px; background: #95a5a6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; transition: all 0.3s;">
                        ❌ Cancelar
                    </button>
                </div>
                
                <button id="btnSalvar" style="display: none; width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; margin-top: 10px; transition: all 0.3s;">
                    💾 Salvar e Fechar
                </button>
            `;
            
            overlay.appendChild(popup);
            document.body.appendChild(overlay);
            
            // Aplicar máscara de dinheiro nos campos monetários
            function aplicarMascaraDinheiro(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;
                
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value) {
                        value = (parseInt(value) / 100).toFixed(2);
                        value = value.replace('.', ',');
                        value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    }
                    e.target.value = value;
                });
            }
            
            aplicarMascaraDinheiro('novoValor');
            aplicarMascaraDinheiro('novaEstimativa');
            
            // Hover effects nos botões
            document.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                });
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
            
            // Eventos dos botões
            document.getElementById('btnSim').addEventListener('click', () => {
                document.body.removeChild(overlay);
                const loadingMsg = document.createElement('div');
                loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 10px; z-index: 9999; font-size: 16px;';
                loadingMsg.textContent = '⏳ Movendo prospecto...';
                document.body.appendChild(loadingMsg);
                
                // Manter valores atuais
                executarMovimento(
                    prospecto_id, 
                    nova_fase, 
                    '', 
                    null, 
                    null, 
                    null, // estimativa_ganho mantém o atual (null = não altera)
                    true, 
                    loadingMsg
                );
            });
            
            document.getElementById('btnNao').addEventListener('click', () => {
                document.getElementById('camposEdicao').style.display = 'block';
                document.getElementById('btnSim').style.display = 'none';
                document.getElementById('btnNao').style.display = 'none';
                document.getElementById('btnSalvar').style.display = 'block';
                
                // Scroll suave para os campos
                setTimeout(() => {
                    document.getElementById('camposEdicao').scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'nearest' 
                    });
                }, 100);
            });
            
            document.getElementById('btnSalvar').addEventListener('click', () => {
                const novoValor = document.getElementById('novoValor').value.replace(/\./g, '').replace(',', '.');
                const novoPercentual = document.getElementById('novoPercentual').value;
                const novaEstimativa = document.getElementById('novaEstimativa').value.replace(/\./g, '').replace(',', '.');
                const observacao = document.getElementById('observacaoFechamento').value;
                
                // Validação
                if (!novoValor || parseFloat(novoValor) <= 0) {
                    alert('⚠️ Por favor, informe um valor fechado válido!');
                    return;
                }
                
                document.body.removeChild(overlay);
                
                const loadingMsg = document.createElement('div');
                loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px 40px; border-radius: 10px; z-index: 9999; font-size: 16px;';
                loadingMsg.textContent = '⏳ Salvando e movendo prospecto...';
                document.body.appendChild(loadingMsg);
                
                executarMovimento(
                    prospecto_id, 
                    nova_fase, 
                    observacao,
                    novoValor, 
                    novoPercentual,
                    novaEstimativa || null, // Se vazio, passa null
                    false, 
                    loadingMsg
                );
            });
            
            document.getElementById('btnCancelar').addEventListener('click', () => {
                document.body.removeChild(overlay);
            });
            
            // Fechar ao clicar no overlay (fora do popup)
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    document.body.removeChild(overlay);
                }
            });
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('❌ Erro ao verificar valores.');
    });
}

// Função executarMovimento ATUALIZADA (adicionar parâmetro estimativa_ganho)
function executarMovimento(prospecto_id, nova_fase, observacao, valor_proposta, percentual_exito, estimativa_ganho, manter_valores, loadingMsg) {
    console.log('Executando movimento:', {
        prospecto_id, 
        nova_fase, 
        observacao, 
        valor_proposta, 
        percentual_exito, 
        estimativa_ganho,  // NOVO
        manter_valores
    });
    
    fetch('mover_fase.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            prospecto_id: prospecto_id,
            nova_fase: nova_fase,
            observacao: observacao,
            valor_proposta: valor_proposta,
            percentual_exito: percentual_exito,
            estimativa_ganho: estimativa_ganho,  // NOVO
            manter_valores: manter_valores,
            executar: true
        })
    })
    .then(response => response.json())
    .then(data => {
        if (loadingMsg && loadingMsg.parentNode) {
            document.body.removeChild(loadingMsg);
        }
        
        if (data.success) {
            // Mostrar mensagem de sucesso
            const successMsg = document.createElement('div');
            successMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #27ae60; color: white; padding: 20px 40px; border-radius: 10px; z-index: 9999; font-size: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);';
            successMsg.innerHTML = '✅ Prospecto movido com sucesso!';
            document.body.appendChild(successMsg);
            
            setTimeout(() => {
                document.body.removeChild(successMsg);
                location.reload();
            }, 1500);
        } else {
            alert('❌ Erro: ' + (data.message || 'Erro ao mover prospecto'));
        }
    })
    .catch(error => {
        if (loadingMsg && loadingMsg.parentNode) {
            document.body.removeChild(loadingMsg);
        }
        console.error('Erro:', error);
        alert('❌ Erro ao processar movimento.');
    });
}

// Função para executar o movimento (mantém igual)
function executarMovimento(prospecto_id, nova_fase, observacao, valor_proposta, percentual_exito, manter_valores, loadingMsg) {
    console.log('Executando movimento:', {prospecto_id, nova_fase, observacao, valor_proposta, percentual_exito, manter_valores});
    
    fetch('mover_fase.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            prospecto_id: prospecto_id,
            nova_fase: nova_fase,
            valor_proposta: valor_proposta,
            percentual_exito: percentual_exito,
            observacao: observacao,
            manter_valores: manter_valores,
            confirmado: true
        })
    })
    .then(response => {
        console.log('Response recebido:', response);
        return response.json();
    })
    .then(data => {
        console.log('Data recebido:', data);
        
        if (loadingMsg && loadingMsg.parentNode) {
            document.body.removeChild(loadingMsg);
        }
        
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
        console.error('Erro no fetch:', error);
        if (loadingMsg && loadingMsg.parentNode) {
            document.body.removeChild(loadingMsg);
        }
        alert('❌ Erro ao mover prospecto: ' + error.message);
    });
}
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Prospecção', $conteudo, 'prospeccao');
?>