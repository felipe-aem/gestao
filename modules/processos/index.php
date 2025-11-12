<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

require_once __DIR__ . '/ProcessoRelacionamento.php';

$usuario_logado = Auth::user();

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'processos';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

// Buscar núcleos que o usuário tem acesso
$sql = "SELECT n.* FROM nucleos n 
        WHERE ativo = 1 
        ORDER BY nome";
$stmt = executeQuery($sql, []);
$usuario_nucleos = $stmt->fetchAll();

if (empty($usuario_nucleos)) {
    die('
        <div style="text-align: center; margin-top: 50px;">
            <h2>Sem Acesso a Núcleos</h2>
            <p>Você não tem acesso a nenhum núcleo.</p>
            <a href="../dashboard/">Voltar ao Dashboard</a>
        </div>
    ');
}

// Buscar tipos de processo por núcleo
$tipos_por_nucleo = [];
foreach ($usuario_nucleos as $nucleo) {
    switch ($nucleo['nome']) {
        case 'Família':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Alimentos',
                'Cumprimento de Sentença – Prisão',
                'Cumprimento de Sentença – Penhora',
				'Execução de Alimentos - Prisão',
				'Execução de Alimentos - Penhora',
                'Inventário',
				'Medida Protetiva',
                'Pedidos diversos – completo',
                'Divórcio',
                'Dissolução',
                'Alimentos e Guarda',
				'Negatória de Paternidade'
            ];
            break;
        case 'Criminal':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Habeas Corpus',
                'Ação Penal',
                'Recurso Criminal',
                'Execução Penal'
            ];
            break;
        case 'Trabalhista':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Reclamação Trabalhista',
                'Recurso Ordinário',
                'Execução Trabalhista',
                'Mandado de Segurança'
            ];
            break;
        case 'Bancário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação Revisional',
                'Busca e Apreensão',
                'Execução',
                'Consignação em Pagamento'
            ];
            break;
        case 'Previdenciário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Aposentadoria',
                'Auxílio-doença',
                'Pensão por morte',
                'Revisão de benefício'
            ];
            break;
        case 'Cobrança':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação de Despejo',
                'Ação de Cobrança de Aluguel',
                'Ação Revisional de Aluguel',
                'Usucapião',
                'Ação de Reintegração de Posse',
                'Ação Demarcatória',
                'Ação de Adjudicação Compulsória',
                'Rescisão de Contrato de Locação',
                'Renovatória de Locação'
            ];
            break;
            
        case 'Criminal Econômico':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Dissolução de Sociedade',
                'Recuperação Judicial',
                'Falência',
                'Ação de Cobrança Empresarial',
                'Arbitragem',
                'Contrato Social',
                'Medida Cautelar',
                'Ação Declaratória'
            ];
            break;
            
        case 'Sucessões':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Mandado de Segurança Tributário',
                'Ação Anulatória de Débito Fiscal',
                'Embargos à Execução Fiscal',
                'Ação Declaratória de Inexistência de Relação Jurídico-Tributária',
                'Impugnação de Lançamento',
                'Compensação Tributária',
                'Recurso Administrativo'
            ];
            break;
            
        case 'Empresarial':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Indenização por Danos Morais',
                'Indenização por Danos Materiais',
                'Revisão de Contrato',
                'Cancelamento de Contrato',
                'Devolução de Valores',
                'Defeito de Produto',
                'Vício de Serviço',
                'Cobrança Indevida'
            ];
            break;
        default:
            $tipos_por_nucleo[$nucleo['id']] = ['Outros'];
            break;
    }
}

// Filtros - garantir que sempre sejam arrays
$nucleos_filtro = isset($_GET['nucleos']) && !empty($_GET['nucleos']) ? (array)$_GET['nucleos'] : [];
$situacoes_filtro = isset($_GET['situacoes']) && !empty($_GET['situacoes']) ? (array)$_GET['situacoes'] : [];
$tipos_filtro = isset($_GET['tipos']) && !empty($_GET['tipos']) ? (array)$_GET['tipos'] : [];
$responsaveis_filtro = isset($_GET['responsaveis']) && !empty($_GET['responsaveis']) ? (array)$_GET['responsaveis'] : [];
$busca = $_GET['busca'] ?? '';

if (empty($situacoes_filtro)) {
    $situacoes_filtro = ['Em Andamento', 'Transitado', 'Em Cumprimento de Sentença', 'Em Processo de Renúncia', 'Baixado', 'Renunciado', 'Em Grau Recursal'];
}

// Construir query com filtros
$where_conditions = [];
$params = [];

// Filtros específicos por arrays
if (!empty($nucleos_filtro)) {
    $nucleos_placeholders = str_repeat('?,', count($nucleos_filtro) - 1) . '?';
    $where_conditions[] = "p.nucleo_id IN ($nucleos_placeholders)";
    $params = array_merge($params, $nucleos_filtro);
}

if (!empty($situacoes_filtro) && count($situacoes_filtro) < 7) {
    $situacoes_placeholders = str_repeat('?,', count($situacoes_filtro) - 1) . '?';
    $where_conditions[] = "p.situacao_processual IN ($situacoes_placeholders)";
    $params = array_merge($params, $situacoes_filtro);
}

if (!empty($tipos_filtro)) {
    $tipos_placeholders = str_repeat('?,', count($tipos_filtro) - 1) . '?';
    $where_conditions[] = "p.tipo_processo IN ($tipos_placeholders)";
    $params = array_merge($params, $tipos_filtro);
}

if (!empty($busca)) {
    require_once __DIR__ . '/../../includes/search_helpers.php';
    $busca_normalizada = normalizarParaBusca($busca);
    
    $where_conditions[] = "(
        p.numero_processo LIKE ? 
        OR p.cliente_nome LIKE ? 
        OR p.parte_contraria LIKE ?
        OR REPLACE(REPLACE(REPLACE(REPLACE(p.numero_processo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
    )";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca_normalizada%";
}

// Buscar usuários para filtro
$sql = "SELECT DISTINCT u.id, u.nome 
        FROM usuarios u 
        WHERE u.ativo = 1 
        ORDER BY UPPER(u.nome) ASC";
$stmt = executeQuery($sql, []);
$usuarios_nucleos = $stmt->fetchAll();

// Aplicar filtro de responsáveis apenas se foi explicitamente enviado
if (isset($_GET['responsaveis']) && !empty($_GET['responsaveis'])) {
    $responsaveis_enviados = (array)$_GET['responsaveis'];
    
    if (count($responsaveis_enviados) < count($usuarios_nucleos)) {
        $responsaveis_placeholders = str_repeat('?,', count($responsaveis_enviados) - 1) . '?';
        $where_conditions[] = "p.responsavel_id IN ($responsaveis_placeholders)";
        $params = array_merge($params, $responsaveis_enviados);
        $responsaveis_filtro = $responsaveis_enviados;
    } else {
        $responsaveis_filtro = array_column($usuarios_nucleos, 'id');
    }
} else {
    $responsaveis_filtro = array_column($usuarios_nucleos, 'id');
}

// CRIAR O WHERE CLAUSE AQUI, ANTES DE USAR
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar processos
$sql = "SELECT p.*, 
        c.nome as cliente_nome_cadastrado,
        u.nome as responsavel_nome,
        cr.nome as criado_por_nome,
        n.nome as nucleo_nome,
        (SELECT COUNT(*) FROM processo_resultados WHERE processo_id = p.id) as total_resultados,
        (SELECT COUNT(*) 
         FROM processo_relacionamentos 
         WHERE (processo_origem_id = p.id OR processo_destino_id = p.id) 
         AND deleted_at IS NULL) as total_relacionamentos
        FROM processos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN usuarios u ON p.responsavel_id = u.id
        LEFT JOIN usuarios cr ON p.criado_por = cr.id
        LEFT JOIN nucleos n ON p.nucleo_id = n.id
        $where_clause
        ORDER BY p.data_criacao DESC";

$stmt = executeQuery($sql, $params);
$processos = $stmt->fetchAll();

// Estatísticas por núcleo
$stats_sql = "SELECT 
    n.nome as nucleo_nome,
    n.id as nucleo_id,
    COUNT(*) as total,
    SUM(CASE WHEN p.situacao_processual = 'Em Andamento' THEN 1 ELSE 0 END) as em_andamento,
    SUM(CASE WHEN p.situacao_processual = 'Transitado' THEN 1 ELSE 0 END) as transitado,
    SUM(CASE WHEN p.situacao_processual = 'Em Cumprimento de Sentença' THEN 1 ELSE 0 END) as cumprimento_sentenca,
    SUM(CASE WHEN p.situacao_processual = 'Em Processo de Renúncia' THEN 1 ELSE 0 END) as em_renuncia,
    SUM(CASE WHEN p.situacao_processual = 'Baixado' THEN 1 ELSE 0 END) as baixado,
    SUM(CASE WHEN p.situacao_processual = 'Renunciado' THEN 1 ELSE 0 END) as renunciado,
    SUM(CASE WHEN p.situacao_processual = 'Em Grau Recursal' THEN 1 ELSE 0 END) as em_recurso,
    SUM(CASE WHEN p.data_protocolo IS NULL THEN 1 ELSE 0 END) as sem_protocolo
    FROM processos p
    INNER JOIN nucleos n ON p.nucleo_id = n.id
    $where_clause
    GROUP BY n.id, n.nome
    ORDER BY n.nome";

$stmt = executeQuery($stats_sql, $params);
$stats_por_nucleo = $stmt->fetchAll();

// Estatísticas gerais
$stats_gerais = [
    'total' => array_sum(array_column($stats_por_nucleo, 'total')),
    'em_andamento' => array_sum(array_column($stats_por_nucleo, 'em_andamento')),
    'transitado' => array_sum(array_column($stats_por_nucleo, 'transitado')),
    'cumprimento_sentenca' => array_sum(array_column($stats_por_nucleo, 'cumprimento_sentenca')),
    'em_renuncia' => array_sum(array_column($stats_por_nucleo, 'em_renuncia')),
	'baixado' => array_sum(array_column($stats_por_nucleo, 'baixado')),
	'renunciado' => array_sum(array_column($stats_por_nucleo, 'renunciado')),
	'em_recurso' => array_sum(array_column($stats_por_nucleo, 'em_recurso')),
    'sem_protocolo' => array_sum(array_column($stats_por_nucleo, 'sem_protocolo'))
];

// Consolidar tipos de processo
$todos_tipos_processo = [];
foreach ($tipos_por_nucleo as $tipos) {
    $todos_tipos_processo = array_merge($todos_tipos_processo, $tipos);
}
$todos_tipos_processo = array_unique($todos_tipos_processo);
sort($todos_tipos_processo);

// Conteúdo da página
ob_start();
?>
<style>
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
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .btn-novo {
        padding: 12px 24px;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .btn-novo:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .btn-estatisticas {
        padding: 12px 24px;
        background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(111, 66, 193, 0.3);
    }
    
    .btn-estatisticas:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(111, 66, 193, 0.4);
    }
    
    .nucleos-overview {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .nucleos-overview h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .nucleos-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .nucleo-stat-card {
        background: rgba(255, 255, 255, 0.9);
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .nucleo-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #007bff, #28a745, #ffc107);
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .nucleo-stat-card:hover {
        border-color: #007bff;
        background: rgba(255, 255, 255, 1);
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0,123,255,0.2);
    }
    
    .nucleo-stat-card:hover::before {
        opacity: 1;
    }
    
    .nucleo-stat-card h4 {
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 12px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .nucleo-icon {
        width: 24px;
        height: 24px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: white;
        font-weight: bold;
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        color: #007bff;
        margin-bottom: 12px;
        line-height: 1;
    }
    
    .stat-breakdown {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .stat-item {
        font-size: 12px;
        color: #666;
        padding: 4px 8px;
        background: rgba(0,0,0,0.03);
        border-radius: 4px;
    }
    
    .stat-item.warning {
        color: #856404;
        background: rgba(255, 193, 7, 0.1);
        font-weight: 600;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
        position: relative;
        z-index: 1;
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
        color: #1a1a1a;
        font-size: 28px;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card.primary { border-left-color: #007bff; }
    .stat-card.success { border-left-color: #28a745; }
    .stat-card.danger { border-left-color: #dc3545; }
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.info { border-left-color: #17a2b8; }
    .stat-card.secondary { border-left-color: #6c757d; }
    
    .stat-card.success h3 { color: #28a745; }
    .stat-card.danger h3 { color: #dc3545; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.info h3 { color: #17a2b8; }
    .stat-card.secondary h3 { color: #6c757d; }
    
    .filters-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
        position:relative;
        z-index: 10;
        overflow: visible;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
        overflow: visible;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        position: relative;
        overflow: visible;
    }
    
    .filter-group label {
        margin-bottom: 5px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .filter-group input[type="text"] {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .filter-group input[type="text"]:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    /* Estilo do dropdown customizado */
    .custom-dropdown {
        position: relative;
        width: 100%;
    }
    
    .dropdown-button {
        width: 100%;
        padding: 10px 35px 10px 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        background: white;
        cursor: pointer;
        text-align: left;
        font-size: 14px;
        color: #333;
        transition: border-color 0.3s;
        position: relative;
    }
    
    .dropdown-button:hover,
    .dropdown-button.active {
        border-color: #007bff;
    }
    
    .dropdown-button::after {
        content: '▼';
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 12px;
        color: #666;
        transition: transform 0.3s;
    }
    
    .dropdown-button.active::after {
        transform: translateY(-50%) rotate(180deg);
    }
    
    .dropdown-content {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 5px 5px;
        max-height: 250px;
        overflow-y: auto;
        z-index: 99999;
        display: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        margin-top: 0;
    }
    
    .custom-dropdown {
        position: relative;
        width: 100%;
        z-index: 100; /* ← ADICIONAR */
    }
    
    /* Quando o dropdown estiver ativo, aumentar o z-index */
    .custom-dropdown.active {
        z-index: 100000;
    }
    
    .dropdown-content.show {
        display: block;
    }
    
    .dropdown-item {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        cursor: pointer;
        transition: background-color 0.3s;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .dropdown-item:hover {
        background: rgba(0, 123, 255, 0.1);
    }
    
    .dropdown-item:last-child {
        border-bottom: none;
    }
    
    .dropdown-item input[type="checkbox"] {
        margin-right: 8px;
        width: auto !important;
        padding: 0 !important;
    }
    
    .dropdown-item label {
        cursor: pointer;
        margin: 0 !important;
        font-weight: 500 !important;
        flex: 1;
        font-size: 14px !important;
    }
    
    .dropdown-item.select-all {
        border-bottom: 2px solid #007bff;
        background: rgba(0, 123, 255, 0.05);
        font-weight: 600;
    }
    
    .selection-count {
        background: #007bff;
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 5px;
        font-weight: 600;
    }
    
    .btn-filter {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }
    
    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .table-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
        position: relative;
        z-index: 1;
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
        letter-spacing: 0.5px;
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
        letter-spacing: 0.5px;
    }
    
    .badge-em-andamento { background: #007bff; color: white; }
    .badge-transitado { background: #28a745; color: white; }
    .badge-emcumprimentodesentenca { background: #ffc107; color: #000; }
    .badge-em-processo-de-renuncia { background: #dc3545; color: white; }
    
    .badge-protocolo-ok { background: #28a745; color: white; }
    .badge-sem-protocolo { background: #dc3545; color: white; }
    
    .badge-nucleo {
        background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
        color: white;
        padding: 4px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .btn-action {
        padding: 6px 12px;
        margin: 0 2px;
        border-radius: 5px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-block;
    }
    
    .btn-view {
        background: #17a2b8;
        color: white;
    }
    
    .btn-view:hover {
        background: #138496;
        transform: translateY(-1px);
    }
    
    .btn-edit {
        background: #007bff;
        color: white;
    }
    
    .btn-edit:hover {
        background: #0056b3;
        transform: translateY(-1px);
    }
    
    .alert-info {
        background: rgba(23, 162, 184, 0.1);
        border: 1px solid rgba(23, 162, 184, 0.3);
        color: #0c5460;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
        font-size: 16px;
    }
    
    .filters-info {
        background: rgba(0, 123, 255, 0.1);
        border: 1px solid rgba(0, 123, 255, 0.2);
        color: #004085;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
    }
    
    /* Ícones específicos por núcleo */
    .nucleo-stat-card[data-nucleo="Família"] .nucleo-icon { background: #e91e63; }
    .nucleo-stat-card[data-nucleo="Criminal"] .nucleo-icon { background: #f44336; }
    .nucleo-stat-card[data-nucleo="Bancário"] .nucleo-icon { background: #2196f3; }
    .nucleo-stat-card[data-nucleo="Trabalhista"] .nucleo-icon { background: #ff9800; }
    .nucleo-stat-card[data-nucleo="Previdenciário"] .nucleo-icon { background: #9c27b0; }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .nucleos-stats-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
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
</style>

<div class="page-header">
    <h2>Processos - Todos os Núcleos</h2>
    <div style="display: flex; gap: 10px;">
        <a href="estatisticas.php" class="btn-estatisticas" title="Ver Estatísticas">
            📊 Estatísticas
        </a>
        <a href="novo.php" class="btn-novo">+ Novo Processo</a>
    </div>
</div>

<?php 
// Verificar se há filtros ativos
$todos_nucleos_ids = array_column($usuario_nucleos, 'id');
$todos_usuarios_ids = array_column($usuarios_nucleos, 'id');

$tem_filtros_ativos = false;
$filtros_ativos_texto = [];

// Núcleos
if (!empty($nucleos_filtro) && count($nucleos_filtro) < count($todos_nucleos_ids)) {
    $tem_filtros_ativos = true;
    $filtros_ativos_texto[] = '<strong>Núcleos:</strong> ' . count($nucleos_filtro) . ' selecionado(s)';
}

// Busca
if (!empty($busca)) {
    $tem_filtros_ativos = true;
    $filtros_ativos_texto[] = '<strong>Busca:</strong> "' . htmlspecialchars($busca) . '"';
}

// Situações
if (count($situacoes_filtro) < 7) {
    $tem_filtros_ativos = true;
    $filtros_ativos_texto[] = '<strong>Situações:</strong> ' . count($situacoes_filtro) . ' selecionada(s)';
}

// Tipos
if (!empty($tipos_filtro)) {
    $tem_filtros_ativos = true;
    $filtros_ativos_texto[] = '<strong>Tipos:</strong> ' . count($tipos_filtro) . ' selecionado(s)';
}

// Responsáveis
if (isset($_GET['responsaveis']) && count($responsaveis_filtro) < count($todos_usuarios_ids)) {
    $tem_filtros_ativos = true;
    $filtros_ativos_texto[] = '<strong>Responsáveis:</strong> ' . count($responsaveis_filtro) . ' selecionado(s)';
}

if ($tem_filtros_ativos): 
?>
<div class="filters-info">
    🔍 Filtros ativos - <?= implode(' • ', $filtros_ativos_texto) ?>
</div>
<?php endif; ?>

<div class="stats-grid">
    <!-- Card Agrupado: Em Tramitação -->
    <div class="stat-card success">
        <h3><?= $stats_gerais['em_andamento'] + $stats_gerais['em_recurso'] ?></h3>
        <p>Em Tramitação</p>
        <small style="font-size: 11px; color: #999; display: block; margin-top: 5px;">
            <?= $stats_gerais['em_andamento'] ?> em andamento • <?= $stats_gerais['em_recurso'] ?> em recurso
        </small>
    </div>
    
    <!-- Card Agrupado: Finalizados -->
    <div class="stat-card info">
        <h3><?= $stats_gerais['transitado'] + $stats_gerais['baixado'] ?></h3>
        <p>Finalizados</p>
        <small style="font-size: 11px; color: #999; display: block; margin-top: 5px;">
            <?= $stats_gerais['transitado'] ?> transitados • <?= $stats_gerais['baixado'] ?> baixados
        </small>
    </div>
    
    <!-- Card Agrupado: Em Execução -->
    <div class="stat-card warning">
        <h3><?= $stats_gerais['cumprimento_sentenca'] ?></h3>
        <p>Em Execução</p>
    </div>
    
    <!-- Card Agrupado: Encerrados -->
    <div class="stat-card danger">
        <h3><?= $stats_gerais['em_renuncia'] + $stats_gerais['renunciado'] ?></h3>
        <p>Renúncia</p>
        <small style="font-size: 11px; color: #999; display: block; margin-top: 5px;">
            <?= $stats_gerais['em_renuncia'] ?> em processo • <?= $stats_gerais['renunciado'] ?> renunciados
        </small>
    </div>
    
    <!-- Total -->
    <div class="stat-card primary">
        <h3><?= $stats_gerais['total'] ?></h3>
        <p>Total de Processos</p>
    </div>
</div>

<div class="filters-container">
    <form method="GET" id="filterForm">
        <div class="filters-grid">
            <!-- Campo de Busca - Primeiro -->
            <div class="filter-group">
                <label for="busca">Buscar:</label>
                <input type="text" id="busca" name="busca" value="<?= htmlspecialchars($busca) ?>" 
                       placeholder="Número, cliente ou parte contrária">
            </div>
            
            <!-- Dropdown Núcleos -->
            <div class="filter-group">
                <label>Núcleo:</label>
                <div class="custom-dropdown">
                    <div class="dropdown-button" onclick="toggleDropdown('nucleos')">
                        <span id="nucleos-text">Todos os Núcleos</span>
                    </div>
                    <div class="dropdown-content" id="nucleos-dropdown">
                        <div class="dropdown-item select-all">
                            <input type="checkbox" id="nucleos-todos" onchange="selectAllNucleos()" checked>
                            <label for="nucleos-todos">Todos os Núcleos</label>
                        </div>
                        <?php foreach ($usuario_nucleos as $nucleo): ?>
                        <div class="dropdown-item">
                            <input type="checkbox" name="nucleos[]" value="<?= $nucleo['id'] ?>" 
                                   id="nucleo-<?= $nucleo['id'] ?>" onchange="updateNucleos()"
                                   <?= in_array($nucleo['id'], $nucleos_filtro) ? 'checked' : '' ?>>
                            <label for="nucleo-<?= $nucleo['id'] ?>"><?= htmlspecialchars($nucleo['nome']) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Dropdown Situações -->
            <div class="filter-group">
                <label>Situação:</label>
                <div class="custom-dropdown">
                    <div class="dropdown-button" onclick="toggleDropdown('situacoes')">
                        <span id="situacoes-text">Todas as Situações</span>
                    </div>
                    <div class="dropdown-content" id="situacoes-dropdown">
                        <div class="dropdown-item select-all">
                            <input type="checkbox" id="situacoes-todos" onchange="selectAllSituacoes()" checked>
                            <label for="situacoes-todos">Todas as Situações</label>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Em Andamento" id="situacao-1" onchange="updateSituacoes()"
                                   <?= in_array('Em Andamento', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-1">Em Andamento</label>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Transitado" id="situacao-2" onchange="updateSituacoes()"
                                   <?= in_array('Transitado', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-2">Transitado</label>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Em Cumprimento de Sentença" id="situacao-3" onchange="updateSituacoes()"
                                   <?= in_array('Em Cumprimento de Sentença', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-3">Em Cumprimento de Sentença</label>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Em Processo de Renúncia" id="situacao-4" onchange="updateSituacoes()"
                                   <?= in_array('Em Processo de Renúncia', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-4">Em processo de Renúncia</label>
                        </div>
						<div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Baixado" id="situacao-5" onchange="updateSituacoes()"
                                   <?= in_array('Baixado', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-5">Baixado</label>
                        </div>
						<div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Renunciado" id="situacao-6" onchange="updateSituacoes()"
                                   <?= in_array('Renunciado', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-6">Renunciado</label>
                        </div>
						<div class="dropdown-item">
                            <input type="checkbox" name="situacoes[]" value="Em Grau Recursal" id="situacao-7" onchange="updateSituacoes()"
                                   <?= in_array('Em Grau Recursal', $situacoes_filtro) ? 'checked' : '' ?>>
                            <label for="situacao-7">Em Grau Recursal</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dropdown Responsáveis -->
            <div class="filter-group">
                <label>Responsável:</label>
                <div class="custom-dropdown">
                    <div class="dropdown-button" onclick="toggleDropdown('responsaveis')">
                        <span id="responsaveis-text">Todos os Responsáveis</span>
                    </div>
                    <div class="dropdown-content" id="responsaveis-dropdown">
                        <div class="dropdown-item select-all">
                            <input type="checkbox" id="responsaveis-todos" onchange="selectAllResponsaveis()" checked>
                            <label for="responsaveis-todos">Todos os Responsáveis</label>
                        </div>
                        <?php foreach ($usuarios_nucleos as $user): ?>
                        <div class="dropdown-item">
                            <input type="checkbox" name="responsaveis[]" value="<?= $user['id'] ?>" 
                                   id="responsavel-<?= $user['id'] ?>" onchange="updateResponsaveis()"
                                   <?= in_array($user['id'], $responsaveis_filtro) ? 'checked' : '' ?>>
                            <label for="responsavel-<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn-filter">🔍 Filtrar Processos</button>
    </form>
</div>

<?php if (empty($processos)): ?>
<div class="alert-info">
    📋 Nenhum processo encontrado com os filtros aplicados.
    <br><small>Tente ajustar os filtros ou criar um novo processo.</small>
</div>
<?php else: ?>
<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Número</th>
                <th>Cliente</th>
                
                <th>Situação</th>
                <th>Responsável</th>
                
                <th>Núcleo</th>
                
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($processos as $processo): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($processo['numero_processo']) ?></strong>
                    <?php if ($processo['comarca']): ?>
                        <br><small style="color: #666;"><?= htmlspecialchars($processo['comarca']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
				<?php
				// Buscar nossos clientes deste processo
				$sql_partes = "SELECT nome FROM processo_partes WHERE processo_id = ? AND e_nosso_cliente = 1 ORDER BY ordem LIMIT 3";
				$stmt_partes = executeQuery($sql_partes, [$processo['id']]);
				$clientes_processo = $stmt_partes->fetchAll(PDO::FETCH_COLUMN);

				if (!empty($clientes_processo)) {
					echo htmlspecialchars(implode(', ', $clientes_processo));
					if (count($clientes_processo) > 2) {
						echo ' <small style="color: #666;">(+outros)</small>';
					}
				} else {
					// Fallback para processos antigos
					echo htmlspecialchars($processo['cliente_nome_cadastrado'] ?: $processo['cliente_nome']);
				}
				?>

				<?php if ($processo['usa_partes_multiplas']): ?>
					<br><small style="color: #007bff;">📋 Múltiplas partes cadastradas</small>
				<?php elseif ($processo['cliente_id']): ?>
					<br><small style="color: #28a745;">✓ Cliente Cadastrado</small>
				<?php else: ?>
					<br><small style="color: #ffc107;">⚠ Informado Manualmente</small>
				<?php endif; ?>

				<?php
				// Mostrar outras partes do processo (não necessariamente contrárias)
				$sql_outras_partes = "SELECT nome FROM processo_partes WHERE processo_id = ? AND e_nosso_cliente = 0 ORDER BY ordem LIMIT 2";
				$stmt_outras_partes = executeQuery($sql_outras_partes, [$processo['id']]);
				$outras_partes = $stmt_outras_partes->fetchAll(PDO::FETCH_COLUMN);

				if (!empty($outras_partes)) {
					echo '<br><small style="color: #666;">Outras partes: ' . htmlspecialchars(implode(', ', $outras_partes));

					// Contar total de outras partes
					$sql_count = "SELECT COUNT(*) FROM processo_partes WHERE processo_id = ? AND e_nosso_cliente = 0";
					$stmt_count = executeQuery($sql_count, [$processo['id']]);
					$total_outras = $stmt_count->fetchColumn();

					if ($total_outras > 2) {
						echo ' (+' . ($total_outras - 2) . ' outras)';
					}
					echo '</small>';
				} elseif ($processo['parte_contraria']) {
					// Fallback para processos antigos
					echo '<br><small style="color: #666;">Parte contrária: ' . htmlspecialchars($processo['parte_contraria']) . '</small>';
				}
				?>
			</td>
                
                <td>
                    <span class="badge badge-<?= strtolower(str_replace([' ', 'ã', 'ç'], ['', 'a', 'c'], $processo['situacao_processual'])) ?>">
                        <?= $processo['situacao_processual'] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($processo['responsavel_nome']) ?></td>
                
                <td>
                    <span class="badge-nucleo">
                        <?= htmlspecialchars($processo['nucleo_nome']) ?>
                    </span>
                </td>
                
                <td>
                    <a href="visualizar.php?id=<?= $processo['id'] ?>" class="btn-action btn-view" title="Visualizar">👁️ Ver</a>
                    <a href="editar.php?id=<?= $processo['id'] ?>" class="btn-action btn-edit" title="Editar">✏️ Editar</a>
                    <!-- ADICIONE ESTE CÓDIGO: -->
                    <?php if ($processo['total_relacionamentos'] > 0): ?>
                        <button class="btn btn-xs btn-outline-info ml-1" 
                                onclick="abrirModalRelacionamento(<?= $processo['id'] ?>)"
                                title="<?= $processo['total_relacionamentos'] ?> processo(s) relacionado(s)">
                            <i class="fas fa-link"></i> <?= $processo['total_relacionamentos'] ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// Dados dos tipos por núcleo
const tiposPorNucleo = <?= json_encode($tipos_por_nucleo) ?>;
const usuariosNucleos = <?= json_encode($usuarios_nucleos) ?>;
const nucleosUsuario = <?= json_encode($usuario_nucleos) ?>;

// Controlar abertura/fechamento dos dropdowns
function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId + '-dropdown');
    const button = dropdown.previousElementSibling;
    const customDropdown = button.closest('.custom-dropdown');
    
    // Fechar todos os outros dropdowns
    document.querySelectorAll('.dropdown-content').forEach(d => {
        if (d !== dropdown) {
            d.classList.remove('show');
            d.previousElementSibling.classList.remove('active');
            const otherCustomDropdown = d.closest('.custom-dropdown');
            if (otherCustomDropdown) {
                otherCustomDropdown.classList.remove('active');
            }
        }
    });
    
    // Toggle do dropdown atual
    dropdown.classList.toggle('show');
    button.classList.toggle('active');
    
    if (customDropdown) {
        if (dropdown.classList.contains('show')) {
            customDropdown.classList.add('active');
        } else {
            customDropdown.classList.remove('active');
        }
    }
}

// Fechar dropdowns ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-dropdown')) {
        document.querySelectorAll('.dropdown-content').forEach(d => {
            d.classList.remove('show');
            d.previousElementSibling.classList.remove('active');
        });
    }
});

// Funções para núcleos
function selectAllNucleos() {
    const allCheckbox = document.getElementById('nucleos-todos');
    const checkboxes = document.querySelectorAll('input[name="nucleos[]"]');
    
    checkboxes.forEach(cb => cb.checked = allCheckbox.checked);
    updateNucleos();
}

function updateNucleos() {
    const allCheckbox = document.getElementById('nucleos-todos');
    const checkboxes = document.querySelectorAll('input[name="nucleos[]"]');
    const checkedBoxes = document.querySelectorAll('input[name="nucleos[]"]:checked');
    const textElement = document.getElementById('nucleos-text');
    
    if (checkedBoxes.length === 0) {
        allCheckbox.checked = false;
        textElement.innerHTML = 'Nenhum núcleo';
    } else if (checkedBoxes.length === checkboxes.length) {
        allCheckbox.checked = true;
        textElement.innerHTML = 'Todos os Núcleos';
    } else {
        allCheckbox.checked = false;
        textElement.innerHTML = `${checkedBoxes.length} núcleo(s) <span class="selection-count">${checkedBoxes.length}</span>`;
    }
}

// Funções para situações
function selectAllSituacoes() {
    const allCheckbox = document.getElementById('situacoes-todos');
    const checkboxes = document.querySelectorAll('input[name="situacoes[]"]');
    
    checkboxes.forEach(cb => cb.checked = allCheckbox.checked);
    updateSituacoes();
}

function updateSituacoes() {
    const allCheckbox = document.getElementById('situacoes-todos');
    const checkboxes = document.querySelectorAll('input[name="situacoes[]"]');
    const checkedBoxes = document.querySelectorAll('input[name="situacoes[]"]:checked');
    const textElement = document.getElementById('situacoes-text');
    
    if (checkedBoxes.length === 0) {
        allCheckbox.checked = false;
        textElement.innerHTML = 'Nenhuma situação';
    } else if (checkedBoxes.length === checkboxes.length) {
        allCheckbox.checked = true;
        textElement.innerHTML = 'Todas as Situações';
    } else {
        allCheckbox.checked = false;
        textElement.innerHTML = `${checkedBoxes.length} situação(ões) <span class="selection-count">${checkedBoxes.length}</span>`;
    }
}

// Funções para responsáveis
function selectAllResponsaveis() {
    const allCheckbox = document.getElementById('responsaveis-todos');
    const checkboxes = document.querySelectorAll('input[name="responsaveis[]"]');
    
    checkboxes.forEach(cb => cb.checked = allCheckbox.checked);
    updateResponsaveis();
}

function updateResponsaveis() {
    const allCheckbox = document.getElementById('responsaveis-todos');
    const checkboxes = document.querySelectorAll('input[name="responsaveis[]"]');
    const checkedBoxes = document.querySelectorAll('input[name="responsaveis[]"]:checked');
    const textElement = document.getElementById('responsaveis-text');
    
    if (checkedBoxes.length === 0) {
        allCheckbox.checked = false;
        textElement.innerHTML = 'Nenhum responsável';
    } else if (checkedBoxes.length === checkboxes.length) {
        allCheckbox.checked = true;
        textElement.innerHTML = 'Todos os Responsáveis';
    } else {
        allCheckbox.checked = false;
        textElement.innerHTML = `${checkedBoxes.length} responsável(is) <span class="selection-count">${checkedBoxes.length}</span>`;
    }
}

// Inicializar na primeira carga
document.addEventListener('DOMContentLoaded', function() {
    updateNucleos();
    updateSituacoes();
    updateResponsaveis();
});

// Modal de relacionamentos
function abrirModalRelacionamento(processoId) {
    console.log('Abrindo modal para processo:', processoId);
    
    const modal = document.getElementById('modalRelacionamentos');
    
    if (!modal) {
        alert('Modal não encontrado!');
        return;
    }
    
    const processoIdInput = modal.querySelector('#processo_id_modal');
    if (processoIdInput) {
        processoIdInput.value = processoId;
    }
    
    // Carregar relacionamentos
    if (typeof carregarRelacionamentosExistentes === 'function') {
        carregarRelacionamentosExistentes(processoId);
    }
    
    // Abrir modal
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        new bootstrap.Modal(modal).show();
    } else if (typeof $ !== 'undefined' && $.fn.modal) {
        $(modal).modal('show');
    } else {
        modal.style.display = 'block';
        modal.classList.add('show');
    }
}
</script>

<?php include __DIR__ . '/modal_relacionamentos.html'; ?>

<?php
$conteudo = ob_get_clean();

// Renderizar layout
echo renderLayout('Processos', $conteudo, 'processos');
?>