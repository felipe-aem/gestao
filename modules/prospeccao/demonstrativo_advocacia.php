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
$niveis_acesso_total = ['Admin', 'Socio', 'Diretor'];
$filtrar_por_nucleo = !in_array($nivel_acesso_logado, $niveis_acesso_total);

// EXCEÇÃO: Usuário ID 15
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
    } catch (Exception $e) {
        error_log("ERRO ao buscar núcleos: " . $e->getMessage());
    }
}

$modulo_codigo = 'ADVOCACIA';

// ============================================================
// DEFINIÇÃO DE CAMPOS DISPONÍVEIS
// ============================================================
$campos_disponiveis = [
    // Identificação
    'id' => ['label' => 'ID', 'grupo' => 'Identificação', 'tipo' => 'numero'],
    'nome' => ['label' => 'Nome', 'grupo' => 'Identificação', 'tipo' => 'texto'],
    'tipo_cliente' => ['label' => 'Tipo Cliente (PF/PJ)', 'grupo' => 'Identificação', 'tipo' => 'texto'],
    'cpf_cnpj' => ['label' => 'CPF/CNPJ', 'grupo' => 'Identificação', 'tipo' => 'texto'],
    
    // Contato
    'telefone' => ['label' => 'Telefone', 'grupo' => 'Contato', 'tipo' => 'texto'],
    'email' => ['label' => 'E-mail', 'grupo' => 'Contato', 'tipo' => 'texto'],
    'cidade' => ['label' => 'Cidade', 'grupo' => 'Contato', 'tipo' => 'texto'],
    
    // Status
    'fase' => ['label' => 'Fase', 'grupo' => 'Status', 'tipo' => 'texto'],
    'meio' => ['label' => 'Meio (Online/Presencial)', 'grupo' => 'Status', 'tipo' => 'texto'],

    // Valores
    'valor_proposta' => ['label' => 'Valor Proposta', 'grupo' => 'Valores', 'tipo' => 'moeda'],
    'percentual_exito' => ['label' => '% Êxito', 'grupo' => 'Valores', 'tipo' => 'percentual'],
    'estimativa_ganho' => ['label' => 'Previsão Ganho (Êxito)', 'grupo' => 'Valores', 'tipo' => 'moeda'],
    
    // Responsáveis
    'responsavel_nome' => ['label' => 'Responsável', 'grupo' => 'Responsáveis', 'tipo' => 'texto'],
    'nucleos_str' => ['label' => 'Núcleos (com %)', 'grupo' => 'Responsáveis', 'tipo' => 'texto'],
    
    // Datas
    'data_cadastro' => ['label' => 'Data Cadastro', 'grupo' => 'Datas', 'tipo' => 'data'],
    'data_entrada_fase' => ['label' => 'Data Entrada Fase', 'grupo' => 'Datas', 'tipo' => 'data'],
    'data_ultima_atualizacao' => ['label' => 'Data Última Atualização', 'grupo' => 'Datas', 'tipo' => 'data'],
    'dias_na_fase' => ['label' => 'Dias na Fase', 'grupo' => 'Datas', 'tipo' => 'numero'],
    
    // Observações
    'observacoes' => ['label' => 'Observações', 'grupo' => 'Outros', 'tipo' => 'texto'],
    'eh_recontratacao' => ['label' => 'Recontratação', 'grupo' => 'Outros', 'tipo' => 'boolean']
];

// ============================================================
// CAMPOS SELECIONADOS PELO USUÁRIO
// ============================================================
$campos_selecionados = $_POST['campos'] ?? [];

// Se for a primeira vez (nenhum campo selecionado), mostrar campos padrão
if (empty($campos_selecionados) && !isset($_POST['gerar'])) {
    $campos_selecionados = ['nome', 'telefone', 'cidade', 'fase', 'responsavel_nome', 'valor_proposta', 'data_cadastro'];
}

// ============================================================
// FILTROS
// ============================================================
$busca = $_GET['busca'] ?? $_POST['busca'] ?? '';
$nucleos_filtro = $_GET['nucleos'] ?? $_POST['nucleos'] ?? [];
$responsaveis_filtro = $_GET['responsaveis'] ?? $_POST['responsaveis'] ?? [];
$fases_filtro = $_GET['fases'] ?? $_POST['fases'] ?? [];
$meio = $_GET['meio'] ?? $_POST['meio'] ?? '';
$periodo = $_GET['periodo'] ?? $_POST['periodo'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? $_POST['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? $_POST['data_fim'] ?? '';
$eh_recontratacao = $_GET['eh_recontratacao'] ?? $_POST['eh_recontratacao'] ?? '';

// Converter arrays
if (!is_array($nucleos_filtro)) {
    $nucleos_filtro = $nucleos_filtro === '' ? [] : [$nucleos_filtro];
}
if (!is_array($responsaveis_filtro)) {
    $responsaveis_filtro = $responsaveis_filtro === '' ? [] : [$responsaveis_filtro];
}
if (!is_array($fases_filtro)) {
    $fases_filtro = $fases_filtro === '' ? [] : [$fases_filtro];
}

// Remover valor vazio (opção "Todos") dos arrays
$nucleos_filtro = array_filter($nucleos_filtro, function($v) { return $v !== ''; });
$responsaveis_filtro = array_filter($responsaveis_filtro, function($v) { return $v !== ''; });
$fases_filtro = array_filter($fases_filtro, function($v) { return $v !== ''; });

// Calcular período
if (!empty($periodo) && $periodo !== 'custom') {
    $data_fim = date('Y-m-d');
    switch ($periodo) {
        case '7': $data_inicio = date('Y-m-d', strtotime('-7 days')); break;
        case '30': $data_inicio = date('Y-m-d', strtotime('-30 days')); break;
        case '90': $data_inicio = date('Y-m-d', strtotime('-90 days')); break;
        case '180': $data_inicio = date('Y-m-d', strtotime('-180 days')); break;
        case '365': $data_inicio = date('Y-m-d', strtotime('-365 days')); break;
        case 'ano_atual': $data_inicio = date('Y') . '-01-01'; break;
        case 'mes_atual': $data_inicio = date('Y-m-01'); break;
    }
}

// --- BUSCAR NÚCLEOS ---
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

// --- BUSCAR USUÁRIOS ---
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

// ============================================================
// BUSCAR DADOS (SOMENTE SE GERAR FOI CLICADO)
// ============================================================
$prospectos = [];
if (isset($_POST['gerar']) && !empty($campos_selecionados)) {
    
    // Construir SELECT dinâmico
    $select_fields = ['p.id'];
    foreach ($campos_selecionados as $campo) {
        switch ($campo) {
            case 'responsavel_nome':
                if (!in_array('u.nome as responsavel_nome', $select_fields)) {
                    $select_fields[] = 'u.nome as responsavel_nome';
                }
                break;
            case 'nucleos_str':
                // Será adicionado via GROUP_CONCAT
                break;
            case 'dias_na_fase':
                $select_fields[] = 'DATEDIFF(CURRENT_DATE, p.data_entrada_fase) as dias_na_fase';
                break;
            default:
                if (!in_array("p.{$campo}", $select_fields)) {
                    $select_fields[] = "p.{$campo}";
                }
        }
    }
    
    // Adicionar GROUP_CONCAT se nucleos_str foi selecionado
    if (in_array('nucleos_str', $campos_selecionados)) {
        $select_fields[] = "GROUP_CONCAT(DISTINCT CONCAT(n.nome, ' (', pn.percentual, '%)') SEPARATOR ', ') as nucleos_str";
    }
    
    // WHERE clause
    $where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
    $params = [$modulo_codigo];
    
    // Filtro por núcleo
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM prospeccoes_nucleos pn_filter 
            WHERE pn_filter.prospeccao_id = p.id 
            AND pn_filter.nucleo_id IN ({$placeholders})
        )";
        $params = array_merge($params, $nucleos_usuario);
    }
    
    // Filtro por cidade
    if ($filtrar_por_cidade && $cidade_filtro) {
        $where_conditions[] = "p.cidade LIKE ?";
        $params[] = "%{$cidade_filtro}%";
    }
    
    // Busca
    if (!empty($busca)) {
        $where_conditions[] = "(p.nome LIKE ? OR p.telefone LIKE ? OR p.cpf_cnpj LIKE ?)";
        $params[] = "%$busca%";
        $params[] = "%$busca%";
        $params[] = "%$busca%";
    }
    
    // Núcleos
    if (!empty($nucleos_filtro)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_filtro), '?'));
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM prospeccoes_nucleos pn2 
            WHERE pn2.prospeccao_id = p.id 
            AND pn2.nucleo_id IN ({$placeholders})
        )";
        $params = array_merge($params, $nucleos_filtro);
    }
    
    // Responsáveis
    if (!empty($responsaveis_filtro)) {
        $placeholders = implode(',', array_fill(0, count($responsaveis_filtro), '?'));
        $where_conditions[] = "p.responsavel_id IN ({$placeholders})";
        $params = array_merge($params, $responsaveis_filtro);
    }
    
    // Fases
    if (!empty($fases_filtro)) {
        $placeholders = implode(',', array_fill(0, count($fases_filtro), '?'));
        $where_conditions[] = "p.fase IN ({$placeholders})";
        $params = array_merge($params, $fases_filtro);
    }
    
    // Meio
    if (!empty($meio)) {
        $where_conditions[] = "p.meio = ?";
        $params[] = $meio;
    }
    
    // Recontratação
    if ($eh_recontratacao !== '') {
        $where_conditions[] = "p.eh_recontratacao = ?";
        $params[] = $eh_recontratacao;
    }
    
    // Período
    if (!empty($data_inicio)) {
        $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) >= ?";
        $params[] = $data_inicio;
    }
    if (!empty($data_fim)) {
        $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) <= ?";
        $params[] = $data_fim;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Query final
    try {
        $sql = "SELECT " . implode(', ', $select_fields) . "
                FROM prospeccoes p
                LEFT JOIN usuarios u ON p.responsavel_id = u.id
                LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
                LEFT JOIN nucleos n ON pn.nucleo_id = n.id
                {$where_clause}
                GROUP BY p.id
                ORDER BY p.id DESC";
        
        $stmt = executeQuery($sql, $params);
        $prospectos = $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Erro na query demonstrativo: " . $e->getMessage());
        $erro_query = $e->getMessage();
    }
}

// ============================================================
// EXPORTAR PARA EXCEL
// ============================================================
if (isset($_POST['exportar_excel'])) {
    
    // Recalcular campos selecionados
    $campos_selecionados = $_POST['campos'] ?? [];
    
    if (empty($campos_selecionados)) {
        die("Erro: Nenhum campo selecionado para exportação.");
    }
    
    // Recalcular prospectos (mesma lógica da geração)
    $select_fields = ['p.id'];
    foreach ($campos_selecionados as $campo) {
        switch ($campo) {
            case 'responsavel_nome':
                if (!in_array('u.nome as responsavel_nome', $select_fields)) {
                    $select_fields[] = 'u.nome as responsavel_nome';
                }
                break;
            case 'nucleos_str':
                break;
            case 'dias_na_fase':
                $select_fields[] = 'DATEDIFF(CURRENT_DATE, p.data_entrada_fase) as dias_na_fase';
                break;
            default:
                if (!in_array("p.{$campo}", $select_fields)) {
                    $select_fields[] = "p.{$campo}";
                }
        }
    }
    
    if (in_array('nucleos_str', $campos_selecionados)) {
        $select_fields[] = "GROUP_CONCAT(DISTINCT CONCAT(n.nome, ' (', pn.percentual, '%)') SEPARATOR ', ') as nucleos_str";
    }
    
    // WHERE clause (mesma lógica)
    $where_conditions = ["p.ativo = 1", "p.modulo_codigo = ?"];
    $params = [$modulo_codigo];
    
    if ($filtrar_por_nucleo && !empty($nucleos_usuario)) {
        $placeholders = implode(',', array_fill(0, count($nucleos_usuario), '?'));
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM prospeccoes_nucleos pn_filter 
            WHERE pn_filter.prospeccao_id = p.id 
            AND pn_filter.nucleo_id IN ({$placeholders})
        )";
        $params = array_merge($params, $nucleos_usuario);
    }
    
    if ($filtrar_por_cidade && $cidade_filtro) {
        $where_conditions[] = "p.cidade LIKE ?";
        $params[] = "%{$cidade_filtro}%";
    }
    
    if (!empty($busca)) {
        $where_conditions[] = "(p.nome LIKE ? OR p.telefone LIKE ? OR p.cpf_cnpj LIKE ?)";
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
    
    if ($eh_recontratacao !== '') {
        $where_conditions[] = "p.eh_recontratacao = ?";
        $params[] = $eh_recontratacao;
    }
    
    if (!empty($data_inicio)) {
        $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) >= ?";
        $params[] = $data_inicio;
    }
    if (!empty($data_fim)) {
        $where_conditions[] = "DATE(COALESCE(p.data_entrada_fase, p.data_cadastro)) <= ?";
        $params[] = $data_fim;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    try {
        $sql = "SELECT " . implode(', ', $select_fields) . "
                FROM prospeccoes p
                LEFT JOIN usuarios u ON p.responsavel_id = u.id
                LEFT JOIN prospeccoes_nucleos pn ON p.id = pn.prospeccao_id
                LEFT JOIN nucleos n ON pn.nucleo_id = n.id
                {$where_clause}
                GROUP BY p.id
                ORDER BY p.id DESC";
        
        $stmt = executeQuery($sql, $params);
        $prospectos_excel = $stmt->fetchAll();
        
        if (empty($prospectos_excel)) {
            die("Erro: Nenhum registro encontrado para exportar.");
        }
        
    } catch (Exception $e) {
        die("Erro na exportação: " . $e->getMessage());
    }
    
    // Gerar Excel
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="demonstrativo_advocacia_' . date('Y-m-d_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "\xEF\xBB\xBF"; // UTF-8 BOM para acentuação
    
    // Cabeçalho da tabela
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<thead>";
    echo "<tr style='background: #636e72; color: white; font-weight: bold;'>";
    
    foreach ($campos_selecionados as $campo) {
        echo "<th style='padding: 10px; text-align: left;'>" . htmlspecialchars($campos_disponiveis[$campo]['label']) . "</th>";
    }
    
    echo "</tr></thead><tbody>";
    
    // Dados
    foreach ($prospectos_excel as $p) {
        echo "<tr>";
        foreach ($campos_selecionados as $campo) {
            $valor = $p[$campo] ?? '';
            
            // Formatação por tipo
            switch ($campos_disponiveis[$campo]['tipo']) {
                case 'moeda':
                    $valor_formatado = $valor > 0 ? 'R$ ' . number_format($valor, 2, ',', '.') : '-';
                    echo "<td style='padding: 8px; text-align: right;'>{$valor_formatado}</td>";
                    break;
                    
                case 'percentual':
                    $valor_formatado = $valor ? $valor . '%' : '-';
                    echo "<td style='padding: 8px; text-align: center;'>{$valor_formatado}</td>";
                    break;
                    
                case 'data':
                    $valor_formatado = $valor ? date('d/m/Y H:i', strtotime($valor)) : '-';
                    echo "<td style='padding: 8px; text-align: center;'>{$valor_formatado}</td>";
                    break;
                    
                case 'boolean':
                    $valor_formatado = $valor == 1 ? 'Sim' : 'Não';
                    echo "<td style='padding: 8px; text-align: center;'>{$valor_formatado}</td>";
                    break;
                    
                case 'numero':
                    $valor_formatado = $valor !== '' ? number_format($valor, 0, ',', '.') : '-';
                    echo "<td style='padding: 8px; text-align: right;'>{$valor_formatado}</td>";
                    break;
                    
                default:
                    $valor_formatado = htmlspecialchars($valor ?: '-');
                    echo "<td style='padding: 8px;'>{$valor_formatado}</td>";
            }
        }
        echo "</tr>";
    }
    
    // Rodapé com totais (se tiver valores numéricos)
    echo "<tr style='background: #f8f9fa; font-weight: bold;'>";
    foreach ($campos_selecionados as $campo) {
        $tipo = $campos_disponiveis[$campo]['tipo'];
        
        if ($tipo === 'moeda' || $tipo === 'numero') {
            // Calcular total
            $total = 0;
            foreach ($prospectos_excel as $p) {
                $total += floatval($p[$campo] ?? 0);
            }
            
            if ($tipo === 'moeda') {
                echo "<td style='padding: 8px; text-align: right;'>TOTAL: R$ " . number_format($total, 2, ',', '.') . "</td>";
            } else {
                echo "<td style='padding: 8px; text-align: right;'>TOTAL: " . number_format($total, 0, ',', '.') . "</td>";
            }
        } else {
            echo "<td style='padding: 8px;'></td>";
        }
    }
    echo "</tr>";
    
    echo "</tbody></table>";
    
    // Informações adicionais
    echo "<br><br>";
    echo "<table border='1' style='border-collapse: collapse; margin-top: 20px;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<td style='padding: 10px;'><strong>Relatório gerado em:</strong></td>";
    echo "<td style='padding: 10px;'>" . date('d/m/Y H:i:s') . "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td style='padding: 10px;'><strong>Total de registros:</strong></td>";
    echo "<td style='padding: 10px;'>" . count($prospectos_excel) . "</td>";
    echo "</tr>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<td style='padding: 10px;'><strong>Gerado por:</strong></td>";
    echo "<td style='padding: 10px;'>" . htmlspecialchars($usuario_logado['nome'] ?? 'Sistema') . "</td>";
    echo "</tr>";
    echo "</table>";
    
    exit;
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
        background: linear-gradient(135deg, #636e72 0%, #2d3436 100%);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        min-height: 100vh;
    }

    .container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 30px;
    }

    /* Header */
    .page-header {
        background: white;
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }

    .header-top h1 {
        font-size: 28px;
        font-weight: 700;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .header-subtitle {
        color: #7f8c8d;
        font-size: 14px;
        margin-top: 5px;
    }

    .header-actions {
        display: flex;
        gap: 10px;
    }

    .btn {
        padding: 10px 20px;
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
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-success {
        background: #27ae60;
        color: white;
    }

    .btn-success:hover {
        background: #229954;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    /* Seção de Seleção de Campos */
    .campos-section {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }

    .section-title {
        font-size: 18px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #ecf0f1;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .campos-grupos {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .campo-grupo {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .campo-grupo:hover {
        border-color: #667eea;
    }

    .grupo-title {
        font-size: 13px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .grupo-title::before {
        content: '▶';
        font-size: 10px;
    }

    .campo-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        border-radius: 6px;
        transition: background 0.2s;
        cursor: pointer;
    }

    .campo-item:hover {
        background: white;
    }

    .campo-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #667eea;
    }

    .campo-item label {
        cursor: pointer;
        font-size: 14px;
        color: #2c3e50;
        flex: 1;
    }

    /* Ações Rápidas */
    .acoes-rapidas {
        display: flex;
        gap: 10px;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #dee2e6;
        flex-wrap: wrap;
    }

    .btn-acao {
        padding: 8px 16px;
        font-size: 13px;
        border-radius: 6px;
        border: 2px solid #dee2e6;
        background: white;
        color: #495057;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-acao:hover {
        border-color: #667eea;
        color: #667eea;
        transform: translateY(-1px);
    }

    /* Filtros */
    .filtros-section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        margin-bottom: 20px;
    }

    .filtros-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .filter-group label {
        font-size: 11px;
        font-weight: 600;
        color: #495057;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .filter-group select,
    .filter-group input {
        padding: 8px 10px;
        border: 2px solid #e0e0e0;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
    }

    .filter-group select:focus,
    .filter-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    /* Estilos para seletor de usuários - LISTBOX */
    .usuarios-selector {
        border: 1px solid #ddd;
        border-radius: 8px;
        background: white;
        width: 100%;
    }
    .usuarios-selector select {
        width: 100%;
        min-height: 120px;
        padding: 8px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
    }
    .usuarios-selector select option {
        padding: 8px 12px;
        cursor: pointer;
    }
    .usuarios-selector select option:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    /* Estilos para seletor de núcleos - LISTBOX */
    .nucleos-selector {
        border: 1px solid #ddd;
        border-radius: 8px;
        background: white;
        width: 100%;
    }
    .nucleos-selector select {
        width: 100%;
        min-height: 120px;
        padding: 8px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
    }
    .nucleos-selector select option {
        padding: 8px 12px;
        cursor: pointer;
    }
    .nucleos-selector select option:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    /* Estilos para seletor de fases - LISTBOX */
    .fases-selector {
        border: 1px solid #ddd;
        border-radius: 8px;
        background: white;
        width: 100%;
    }
    .fases-selector select {
        width: 100%;
        min-height: 100px;
        padding: 8px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
    }
    .fases-selector select option {
        padding: 8px 12px;
        cursor: pointer;
    }
    .fases-selector select option:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    /* Período customizado */
    #custom-dates {
        display: none;
        grid-column: 1 / -1;
        gap: 12px;
        margin-top: 10px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 2px dashed #dee2e6;
    }

    #custom-dates.show {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }

    /* Tabela de Resultados */
    .resultados-section {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        margin-bottom: 20px;
        overflow-x: auto;
    }

    .tabela-demonstrativo {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        min-width: 800px;
    }

    .tabela-demonstrativo thead {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .tabela-demonstrativo th {
        padding: 12px;
        text-align: left;
        font-weight: 600;
        white-space: nowrap;
        border-right: 1px solid rgba(255,255,255,0.1);
    }

    .tabela-demonstrativo th:last-child {
        border-right: none;
    }

    .tabela-demonstrativo td {
        padding: 10px 12px;
        border-bottom: 1px solid #ecf0f1;
        vertical-align: top;
    }

    .tabela-demonstrativo tbody tr {
        transition: background 0.2s;
    }

    .tabela-demonstrativo tbody tr:hover {
        background: #f8f9fa;
    }

    .tabela-demonstrativo tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    .tabela-demonstrativo tbody tr:nth-child(even):hover {
        background: #f0f2f5;
    }

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

    .empty-state p {
        font-size: 16px;
        margin-top: 10px;
    }

    /* Contador de Resultados */
    .contador-resultados {
        background: linear-gradient(135deg, #e3f2fd 0%, #e1f5fe 100%);
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-left: 4px solid #2196f3;
    }

    .contador-resultados strong {
        font-size: 18px;
        color: #1976d2;
    }

    .contador-info {
        font-size: 13px;
        color: #666;
    }

    /* Botões de Ação do Form */
    .form-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 30px;
    }

    .form-actions .btn {
        flex: 1;
    }

    .form-actions .btn-success {
        flex: 0 0 auto;
        min-width: 200px;
    }

    /* Alert de Erro */
    .alert-error {
        background: #fee;
        border: 2px solid #f88;
        color: #c33;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    /* Responsivo */
    @media (max-width: 768px) {
        .container {
            padding: 15px;
        }

        .header-top {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .header-actions {
            width: 100%;
        }

        .header-actions .btn {
            flex: 1;
        }

        .campos-grupos {
            grid-template-columns: 1fr;
        }

        .filtros-grid {
            grid-template-columns: 1fr;
        }

        .form-actions {
            flex-direction: column;
        }

        .form-actions .btn {
            width: 100%;
        }

        .acoes-rapidas {
            flex-direction: column;
        }

        .btn-acao {
            width: 100%;
            justify-content: center;
        }
    }

    /* Loading State */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Badges */
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .badge-info {
        background: #e3f2fd;
        color: #1976d2;
    }
</style>

<div class="container">
    <!-- Header -->
    <div class="page-header">
        <div class="header-top">
            <div>
                <h1>📋 Demonstrativo - Advocacia</h1>
                <p class="header-subtitle">Relatório personalizado para conferência de informações</p>
            </div>
            <div class="header-actions">
                <a href="relatorio_advocacia.php?<?= http_build_query(array_filter($_GET)) ?>" class="btn btn-secondary">
                    ← Voltar aos Relatórios
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($erro_query)): ?>
        <div class="alert-error">
            <strong>❌ Erro na consulta:</strong> <?= htmlspecialchars($erro_query) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="formDemonstrativo">
        
        <!-- Seleção de Campos -->
        <div class="campos-section">
            <div class="section-title">
                <i class="fas fa-columns"></i>
                Selecione os Campos do Relatório
            </div>
            
            <div class="campos-grupos">
                <?php 
                // Agrupar campos
                $campos_por_grupo = [];
                foreach ($campos_disponiveis as $campo => $info) {
                    $campos_por_grupo[$info['grupo']][$campo] = $info;
                }
                
                // Exibir por grupo
                foreach ($campos_por_grupo as $grupo => $campos):
                ?>
                    <div class="campo-grupo">
                        <div class="grupo-title"><?= $grupo ?></div>
                        <?php foreach ($campos as $campo => $info): ?>
                            <div class="campo-item">
                                <input 
                                    type="checkbox" 
                                    name="campos[]" 
                                    value="<?= $campo ?>" 
                                    id="campo_<?= $campo ?>"
                                    <?= in_array($campo, $campos_selecionados) ? 'checked' : '' ?>
                                >
                                <label for="campo_<?= $campo ?>">
                                    <?= htmlspecialchars($info['label']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Ações Rápidas -->
            <div class="acoes-rapidas">
                <button type="button" onclick="selecionarTodos()" class="btn-acao">
                    ✓ Selecionar Todos
                </button>
                <button type="button" onclick="desmarcarTodos()" class="btn-acao">
                    ✗ Desmarcar Todos
                </button>
                <button type="button" onclick="selecionarPadrao()" class="btn-acao">
                    ⭐ Seleção Padrão
                </button>
                <button type="button" onclick="selecionarMinimo()" class="btn-acao">
                    📌 Mínimo Essencial
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filtros-section">
            <div class="section-title">
                <i class="fas fa-filter"></i>
                Filtros
            </div>
            
            <div class="filtros-grid">
                <!-- Busca -->
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Nome, telefone, CPF...">
                </div>
                
                <!-- Núcleos -->
                <div class="filter-group">
                    <label>Núcleos</label>
                    <div class="nucleos-selector">
                        <select name="nucleos[]" multiple>
                            <option value="" style="font-weight: bold; background: #f0f0f0;">✓ Todos os Núcleos</option>
                            <?php foreach ($nucleos as $n): ?>
                                <option value="<?= $n['id'] ?>" <?= in_array($n['id'], $nucleos_filtro) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($n['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Responsáveis -->
                <div class="filter-group">
                    <label>Responsáveis</label>
                    <div class="usuarios-selector">
                        <select name="responsaveis[]" multiple>
                            <option value="" style="font-weight: bold; background: #f0f0f0;">✓ Todos os Responsáveis</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= in_array($u['id'], $responsaveis_filtro) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Fase -->
                <div class="filter-group">
                    <label>Fases</label>
                    <div class="fases-selector">
                        <select name="fases[]" multiple>
                            <option value="" style="font-weight: bold; background: #f0f0f0;">✓ Todas as Fases</option>
                            <option value="Prospecção" <?= in_array('Prospecção', $fases_filtro) ? 'selected' : '' ?>>Prospecção</option>
                            <option value="Negociação" <?= in_array('Negociação', $fases_filtro) ? 'selected' : '' ?>>Negociação</option>
                            <option value="Fechados" <?= in_array('Fechados', $fases_filtro) ? 'selected' : '' ?>>Fechados</option>
                            <option value="Perdidos" <?= in_array('Perdidos', $fases_filtro) ? 'selected' : '' ?>>Perdidos</option>
                        </select>
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
                        <option value="">📅 Período Total</option>
                        <option value="7" <?= $periodo == '7' ? 'selected' : '' ?>>📆 Últimos 7 dias</option>
                        <option value="30" <?= $periodo == '30' ? 'selected' : '' ?>>📆 Últimos 30 dias</option>
                        <option value="90" <?= $periodo == '90' ? 'selected' : '' ?>>📆 Último Trimestre (90 dias)</option>
                        <option value="180" <?= $periodo == '180' ? 'selected' : '' ?>>📆 Último Semestre (180 dias)</option>
                        <option value="365" <?= $periodo == '365' ? 'selected' : '' ?>>📆 Último Ano (365 dias)</option>
                        <option value="mes_atual" <?= $periodo == 'mes_atual' ? 'selected' : '' ?>>📅 Mês Atual</option>
                        <option value="ano_atual" <?= $periodo == 'ano_atual' ? 'selected' : '' ?>>📅 Ano Atual</option>
                        <option value="custom" <?= $periodo == 'custom' ? 'selected' : '' ?>>🗓️ Período Personalizado</option>
                    </select>
                </div>
                
                <!-- Recontratação -->
                <div class="filter-group">
                    <label>Recontratação</label>
                    <select name="eh_recontratacao">
                        <option value="">Todos</option>
                        <option value="1" <?= $eh_recontratacao === '1' ? 'selected' : '' ?>>✓ Sim</option>
                        <option value="0" <?= $eh_recontratacao === '0' ? 'selected' : '' ?>>✗ Não</option>
                    </select>
                </div>
                
                <!-- Datas Personalizadas (aparece quando seleciona "Personalizado") -->
                <div id="custom-dates" class="<?= $periodo == 'custom' ? 'show' : '' ?>">
                    <div class="filter-group">
                        <label>📅 Data Início</label>
                        <input type="date" name="data_inicio" value="<?= $data_inicio ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>📅 Data Fim</label>
                        <input type="date" name="data_fim" value="<?= $data_fim ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Botões de Ação -->
        <div class="form-actions">
            <button type="submit" name="gerar" class="btn btn-primary">
                <i class="fas fa-table"></i>
                Gerar Demonstrativo
            </button>
            
            <button type="submit" name="exportar_excel" class="btn btn-success">
                <i class="fas fa-file-excel"></i>
                <?php if (!empty($prospectos)): ?>
                    Exportar para Excel (<?= count($prospectos) ?> registros)
                <?php else: ?>
                    Exportar para Excel
                <?php endif; ?>
            </button>
        </div>
    </form>

    <!-- Resultados -->
    <?php if (isset($_POST['gerar'])): ?>
        <?php if (!empty($prospectos)): ?>
            
            <!-- Contador -->
            <div class="contador-resultados">
                <div>
                    <strong><?= count($prospectos) ?></strong> registros encontrados
                </div>
                <div class="contador-info">
                    <span class="badge badge-info"><?= count($campos_selecionados) ?> campos selecionados</span>
                </div>
            </div>
            
            <!-- Tabela -->
            <div class="resultados-section">
                <table class="tabela-demonstrativo">
                    <thead>
                        <tr>
                            <?php foreach ($campos_selecionados as $campo): ?>
                                <th><?= htmlspecialchars($campos_disponiveis[$campo]['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prospectos as $p): ?>
                            <tr>
                                <?php foreach ($campos_selecionados as $campo): ?>
                                    <td>
                                        <?php
                                        $valor = $p[$campo] ?? '';
                                        
                                        // Formatação por tipo
                                        switch ($campos_disponiveis[$campo]['tipo']) {
                                            case 'moeda':
                                                echo $valor > 0 ? 'R$ ' . number_format($valor, 2, ',', '.') : '-';
                                                break;
                                            case 'percentual':
                                                echo $valor ? $valor . '%' : '-';
                                                break;
                                            case 'data':
                                                echo $valor ? date('d/m/Y H:i', strtotime($valor)) : '-';
                                                break;
                                            case 'boolean':
                                                echo $valor == 1 ? 'Sim' : 'Não';
                                                break;
                                            case 'numero':
                                                echo $valor !== '' ? number_format($valor, 0, ',', '.') : '-';
                                                break;
                                            default:
                                                echo htmlspecialchars($valor ?: '-');
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php else: ?>
            <div class="resultados-section">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p><strong>Nenhum registro encontrado</strong></p>
                    <p>Tente ajustar os filtros ou selecionar outros campos</p>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function toggleCustomDates() {
    const periodo = document.getElementById('periodo').value;
    const customDates = document.getElementById('custom-dates');
    
    if (periodo === 'custom') {
        customDates.classList.add('show');
    } else {
        customDates.classList.remove('show');
    }
}

function selecionarTodos() {
    document.querySelectorAll('input[name="campos[]"]').forEach(cb => cb.checked = true);
}

function desmarcarTodos() {
    document.querySelectorAll('input[name="campos[]"]').forEach(cb => cb.checked = false);
}

function selecionarPadrao() {
    const padrao = ['nome', 'telefone', 'cidade', 'fase', 'responsavel_nome', 'valor_proposta', 'data_cadastro'];
    document.querySelectorAll('input[name="campos[]"]').forEach(cb => {
        cb.checked = padrao.includes(cb.value);
    });
}

function selecionarMinimo() {
    const minimo = ['id', 'nome', 'telefone', 'fase'];
    document.querySelectorAll('input[name="campos[]"]').forEach(cb => {
        cb.checked = minimo.includes(cb.value);
    });
}

// Validação do formulário
document.getElementById('formDemonstrativo').addEventListener('submit', function(e) {
    const gerar = e.submitter && e.submitter.name === 'gerar';
    
    if (gerar) {
        const campos = document.querySelectorAll('input[name="campos[]"]:checked');
        if (campos.length === 0) {
            e.preventDefault();
            alert('⚠️ Por favor, selecione pelo menos um campo para o relatório!');
            return false;
        }
    }
});

// Animação ao carregar
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.campos-section, .filtros-section, .resultados-section');
    sections.forEach((section, index) => {
        section.style.opacity = '0';
        section.style.transform = 'translateY(20px)';
        setTimeout(() => {
            section.style.transition = 'all 0.4s ease';
            section.style.opacity = '1';
            section.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Highlight nas opções selecionadas dos listbox
    const listboxes = document.querySelectorAll('select[multiple]');
    listboxes.forEach(listbox => {
        listbox.querySelectorAll('option').forEach(option => {
            if (option.selected) {
                option.style.background = 'rgba(102, 126, 234, 0.2)';
                option.style.fontWeight = '600';
            }
        });
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Demonstrativo - Advocacia', $conteudo, 'prospeccao');
?>