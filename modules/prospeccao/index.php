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
//$filtrar_por_cidade = false;
//$cidade_filtro = null;
//if ($usuario_id == 15) {
//    $filtrar_por_cidade = true;
//    $cidade_filtro = 'Chapecó';
//    $filtrar_por_nucleo = false; // Desabilita filtro por núcleo para este usuário
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

// Buscar módulos ativos
try {
    $sql = "SELECT * FROM prospeccao_modulos WHERE ativo = 1 ORDER BY ordem ASC";
    $stmt = executeQuery($sql);
    $modulos = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Erro ao buscar módulos: " . $e->getMessage());
    $modulos = [];
}

// Buscar estatísticas por módulo
$stats_modulos = [];
foreach ($modulos as $modulo) {
    try {
        // ============================================================
        // QUERY AJUSTADA COM AS NOVAS REGRAS
        // ============================================================
        
        // 1. TOTAL ATIVOS - Apenas Prospecção + Negociação
        $sql_total_ativos = "SELECT COUNT(*) as total_ativos
                             FROM prospeccoes 
                             WHERE modulo_codigo = ? 
                             AND ativo = 1
                             AND fase IN ('Prospecção', 'Negociação')";
        
        // 2. VALOR PROPOSTO - Apenas Negociação
        $sql_valor_proposto = "SELECT SUM(COALESCE(valor_proposta, 0)) as valor_proposto
                               FROM prospeccoes 
                               WHERE modulo_codigo = ? 
                               AND ativo = 1
                               AND fase = 'Negociação'";
        
        // 3. VALOR FECHADO - Apenas Fechados do mês atual
        $sql_valor_fechado = "SELECT SUM(COALESCE(valor_proposta, 0)) as valor_fechado
                              FROM prospeccoes 
                              WHERE modulo_codigo = ? 
                              AND ativo = 1
                              AND fase = 'Fechados'
                              AND MONTH(data_fase_final) = MONTH(CURRENT_DATE)
                              AND YEAR(data_fase_final) = YEAR(CURRENT_DATE)";
        
        // 4. TAXA DE CONVERSÃO - (Fechados do mês / Total finalizados do mês) × 100
        $sql_taxa_conversao = "SELECT 
                                   SUM(CASE WHEN fase = 'Fechados' THEN 1 ELSE 0 END) as fechados_mes,
                                   SUM(CASE WHEN fase IN ('Fechados', 'Perdidos') THEN 1 ELSE 0 END) as total_finalizados_mes
                               FROM prospeccoes 
                               WHERE modulo_codigo = ? 
                               AND ativo = 1
                               AND fase IN ('Fechados', 'Perdidos')
                               AND MONTH(data_entrada_fase) = MONTH(CURRENT_DATE)
                               AND YEAR(data_entrada_fase) = YEAR(CURRENT_DATE)";
        
        $stmt_taxa = executeQuery($sql_taxa_conversao, [$modulo['codigo']]);
        $taxa_conversao_data = $stmt_taxa->fetch();
        
        $fechados_mes = $taxa_conversao_data['fechados_mes'] ?? 0;
        $total_finalizados_mes = $taxa_conversao_data['total_finalizados_mes'] ?? 0;
        $taxa_conversao = $total_finalizados_mes > 0 
            ? round(($fechados_mes / $total_finalizados_mes) * 100, 1) 
            : 0;
        
        $fechados_mes = $taxa_conversao_data['fechados_mes'] ?? 0;
        $total_finalizados_mes = $taxa_conversao_data['total_finalizados_mes'] ?? 0;
        $taxa_conversao = $total_finalizados_mes > 0 
            ? round(($fechados_mes / $total_finalizados_mes) * 100, 1) 
            : 0;
        
        $params_base = [$modulo['codigo']];
        
        // ============================================================
        // APLICAR FILTROS DE NÚCLEO/CIDADE EM TODAS AS QUERIES
        // ============================================================
        
        $filtro_adicional = "";
        $params_filtro = [];
        
        // FILTRO POR NÚCLEO
        if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
            $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
            $filtro_adicional .= " AND (
                EXISTS (
                    SELECT 1 FROM prospeccoes_nucleos pn 
                    WHERE pn.prospeccao_id = prospeccoes.id 
                    AND pn.nucleo_id IN ({$placeholders})
                )
                OR
                (
                    NOT EXISTS (
                        SELECT 1 FROM prospeccoes_nucleos pn_check
                        WHERE pn_check.prospeccao_id = prospeccoes.id
                    )
                    AND prospeccoes.nucleo_id IN ({$placeholders})
                )
            )";
            $params_filtro = array_merge($nucleos_usuario, $nucleos_usuario);
        }
        
        // FILTRO POR CIDADE (Usuário ID 15)
        if ($filtrar_por_cidade && $cidade_filtro) {
            $filtro_adicional .= " AND cidade LIKE ?";
            $params_filtro[] = "%{$cidade_filtro}%";
        }
        
        // ============================================================
        // EXECUTAR AS 4 QUERIES
        // ============================================================
        
        // 1. Total Ativos
        $stmt_ativos = executeQuery($sql_total_ativos . $filtro_adicional, array_merge($params_base, $params_filtro));
        $result_ativos = $stmt_ativos->fetch();
        $total_ativos = $result_ativos['total_ativos'] ?? 0;
        
        // 2. Valor Proposto
        $stmt_proposto = executeQuery($sql_valor_proposto . $filtro_adicional, array_merge($params_base, $params_filtro));
        $result_proposto = $stmt_proposto->fetch();
        $valor_proposto = $result_proposto['valor_proposto'] ?? 0;
        
        // 3. Valor Fechado
        $stmt_fechado = executeQuery($sql_valor_fechado . $filtro_adicional, array_merge($params_base, $params_filtro));
        $result_fechado = $stmt_fechado->fetch();
        $valor_fechado = $result_fechado['valor_fechado'] ?? 0;
    
        
        // ============================================================
        // MONTAR ARRAY DE ESTATÍSTICAS
        // ============================================================
        $stats_modulos[$modulo['codigo']] = [
            'total' => $total_ativos,
            'valor_total' => $valor_proposto,
            'valor_fechado_total' => $valor_fechado,
            'taxa_conversao' => $taxa_conversao,
            // Valores auxiliares para debug (opcional)
            'fechados_mes' => $fechados_mes,
            'total_finalizados_mes' => $total_finalizados_mes
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar stats do módulo {$modulo['codigo']}: " . $e->getMessage());
        $stats_modulos[$modulo['codigo']] = [
            'total' => 0,
            'valor_total' => 0,
            'valor_fechado_total' => 0,
            'taxa_conversao' => 0
        ];
    }
}

// Mapear arquivos de kanban por módulo
$kanban_files = [
    'ADVOCACIA' => 'advocacia.php',
    'COMEX' => 'comex.php',
    'TAX' => 'tax.php'
];

// Mapear arquivos de relatórios por módulo
$relatorio_files = [
    'ADVOCACIA' => 'relatorio_advocacia.php',
    'COMEX' => 'relatorio_comex.php',
    'TAX' => 'relatorio_tax.php'
];

ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    .prospeccao-selector {
        padding: 20px;
    }

    .selector-header {
        text-align: center;
        margin-bottom: 40px;
    }

    .selector-header h1 {
        font-size: 32px;
        font-weight: 700;
        color: #fff;
        margin-bottom: 10px;
    }

    .selector-header p {
        font-size: 16px;
        color: rgba(255, 255, 255, 0.8);
    }

    .modules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .module-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 15px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        position: relative;
        overflow: hidden;
        border-top: 4px solid var(--module-color);
    }

    .module-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    }

    .module-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }

    .module-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        color: white;
        background: var(--module-color);
        flex-shrink: 0;
    }

    .module-info h2 {
        font-size: 22px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 3px;
    }

    .module-info p {
        font-size: 13px;
        color: #7f8c8d;
        line-height: 1.4;
    }

    .module-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-top: 20px;
    }

    .stat-item {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 8px;
        transition: all 0.2s ease;
    }

    .stat-item:hover {
        background: #e9ecef;
    }

    .stat-label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 20px;
        font-weight: 700;
        color: #2c3e50;
    }

    .stat-value.money {
        font-size: 16px;
    }

    .module-actions {
        margin-top: 20px;
        display: flex;
        gap: 8px;
    }

    .btn-module {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.3s ease;
    }

    .btn-module-primary {
        background: var(--module-color);
        color: white;
        flex: 1;
    }

    .btn-module-primary:hover {
        filter: brightness(1.1);
        transform: translateY(-2px);
    }

    .btn-module-secondary {
        background: #e9ecef;
        color: #495057;
        padding: 10px 15px;
    }

    .btn-module-secondary:hover {
        background: #dee2e6;
    }

    /* Cores específicas por módulo */
    .module-card[data-module="ADVOCACIA"] {
        --module-color: #667eea;
    }

    .module-card[data-module="COMEX"] {
        --module-color: #f39c12;
    }

    .module-card[data-module="TAX"] {
        --module-color: #27ae60;
    }

    .info-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .info-section h3 {
        font-size: 20px;
        color: #2c3e50;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .info-section ul {
        list-style: none;
        padding-left: 0;
    }

    .info-section li {
        padding: 10px 0;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #495057;
        font-size: 14px;
    }

    .info-section li:last-child {
        border-bottom: none;
    }

    .info-section li i {
        color: #667eea;
        width: 18px;
    }

    @media (max-width: 768px) {
        .modules-grid {
            grid-template-columns: 1fr;
        }

        .module-stats {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="prospeccao-selector">
    <div class="selector-header">
        <h1><i class="fas fa-rocket"></i> Sistema de Prospecção</h1>
        <p>Selecione o módulo para gerenciar suas prospecções</p>
    </div>

    <div class="modules-grid">
        <?php foreach ($modulos as $modulo): 
            // Controle de acesso por módulo
            $modulos_restritos = ['TAX', 'COMEX'];
            
            // Normalizar nível de acesso (trim e lowercase para evitar problemas)
            $nivel_normalizado = strtolower(trim($nivel_acesso_logado));
            
            // Níveis que podem acessar módulos restritos
            $niveis_permitidos = ['admin', 'diretor', 'socio'];
            
            // Verificar se o módulo é restrito e se o usuário tem permissão
            if (in_array($modulo['codigo'], $modulos_restritos) && !in_array($nivel_normalizado, $niveis_permitidos)) {
                continue; // Pula este módulo se o usuário não tem permissão
            }
            
            $stats = $stats_modulos[$modulo['codigo']];
            $taxa_conversao = $stats['taxa_conversao'];

            
            // Obter arquivos corretos para este módulo
            $kanban_file = $kanban_files[$modulo['codigo']] ?? 'kanban.php?modulo=' . urlencode($modulo['codigo']);
            $relatorio_file = $relatorio_files[$modulo['codigo']] ?? 'relatorios.php?modulo=' . urlencode($modulo['codigo']);
        ?>
        <div class="module-card" data-module="<?= htmlspecialchars($modulo['codigo']) ?>" 
             onclick="window.location.href='<?= htmlspecialchars($kanban_file) ?>'">
            <div class="module-header">
                <div class="module-icon">
                    <i class="<?= htmlspecialchars($modulo['icone']) ?>"></i>
                </div>
                <div class="module-info">
                    <h2><?= htmlspecialchars($modulo['nome']) ?></h2>
                    <p><?= htmlspecialchars($modulo['descricao']) ?></p>
                </div>

            </div>

            <div class="module-stats">
                <div class="stat-item">
                    <div class="stat-label">Total Ativos</div>
                    <div class="stat-value"><?= number_format($stats['total'], 0, ',', '.') ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Taxa Conversão</div>
                    <div class="stat-value"><?= $taxa_conversao ?>%</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Valor Proposto</div>
                    <div class="stat-value money">R$ <?= number_format($stats['valor_total'], 2, ',', '.') ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Valor Fechado</div>
                    <div class="stat-value money">R$ <?= number_format($stats['valor_fechado_total'], 2, ',', '.') ?></div>
                </div>
            </div>

            <div class="module-actions">
                <a href="<?= htmlspecialchars($kanban_file) ?>" class="btn-module btn-module-primary">
                    <i class="fas fa-columns"></i>
                    Acessar Kanban
                </a>
                <a href="<?= htmlspecialchars($relatorio_file) ?>" 
                   class="btn-module btn-module-secondary" 
                   onclick="event.stopPropagation()"
                   title="Relatórios">
                    <i class="fas fa-chart-bar"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Animação suave ao carregar
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.module-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.4s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Prospecção - Módulos', $conteudo, 'prospeccao');
?>