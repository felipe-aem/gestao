<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Suas linhas originais de autenticação
require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

// ==================================================
// VERIFICAÇÃO DE MÓDULO EM DESENVOLVIMENTO
// ==================================================
require_once __DIR__ . '/../../config/modules_config.php';

$moduloAtual = 'atendimento';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}
// ==================================================

require_once '../../includes/layout.php';
$usuario_logado = Auth::user();

// Filtros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01'); // Primeiro dia do mês atual
$data_fim = $_GET['data_fim'] ?? date('Y-m-t'); // Último dia do mês atual
$status_filtro = $_GET['status'] ?? '';
$atendido_por = $_GET['atendido_por'] ?? '';

// Construir query com filtros
$where_conditions = ['DATE(a.data_atendimento) BETWEEN ? AND ?'];
$params = [$data_inicio, $data_fim];

if (!empty($status_filtro)) {
    $where_conditions[] = "a.status_contrato = ?";
    $params[] = $status_filtro;
}

if (!empty($atendido_por)) {
    $where_conditions[] = "a.atendido_por = ?";
    $params[] = $atendido_por;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Buscar atendimentos
$sql = "SELECT a.*, 
        c.nome as cliente_nome_cadastrado,
        u.nome as atendido_por_nome,
        ur.nome as responsavel_reuniao_nome,
        cr.nome as criado_por_nome
        FROM atendimentos a
        LEFT JOIN clientes c ON a.cliente_id = c.id
        LEFT JOIN usuarios u ON a.atendido_por = u.id
        LEFT JOIN usuarios ur ON a.responsavel_nova_reuniao = ur.id
        LEFT JOIN usuarios cr ON a.criado_por = cr.id
        $where_clause
        ORDER BY a.data_atendimento DESC";

$stmt = executeQuery($sql, $params);
$atendimentos = $stmt->fetchAll();

// Buscar usuários para filtro
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Estatísticas rápidas - CORRIGIDO
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status_contrato = 'Fechado' THEN 1 ELSE 0 END) as fechados,
    SUM(CASE WHEN status_contrato = 'Não Fechou' THEN 1 ELSE 0 END) as nao_fechou,
    SUM(CASE WHEN status_contrato = 'Em Análise' THEN 1 ELSE 0 END) as em_analise,
    SUM(CASE WHEN precisa_nova_reuniao = 1 AND data_nova_reuniao IS NOT NULL AND data_nova_reuniao >= NOW() THEN 1 ELSE 0 END) as reunioes_pendentes
    FROM atendimentos a
    $where_clause";

$stmt = executeQuery($stats_sql, $params);
$stats = $stmt->fetch();


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
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        color: #1a1a1a;
        font-size: 32px;
        margin-bottom: 10px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card.primary { border-left-color: #007bff; }
    .stat-card.success { border-left-color: #28a745; }
    .stat-card.danger { border-left-color: #dc3545; }
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.info { border-left-color: #17a2b8; }
    
    .stat-card.success h3 { color: #28a745; }
    .stat-card.danger h3 { color: #dc3545; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.info h3 { color: #17a2b8; }
    
    .filters-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-group label {
        margin-bottom: 5px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .filter-group input,
    .filter-group select {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
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
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .badge-fechado { background: #28a745; color: white; }
    .badge-nao-fechou { background: #dc3545; color: white; }
    .badge-em-analise { background: #ffc107; color: #000; }
    
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
        color: #fff4ff;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
        font-size: 16px;
    }
    
    .period-info {
        background: rgba(0, 123, 255, 0.1);
        border: 1px solid rgba(0, 123, 255, 0.2);
        color: #ffffff;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
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
            min-width: 800px;
        }
    }
</style>

<div class="page-header">
    <h2>Atendimentos</h2>
    <a href="novo.php" class="btn-novo">+ Novo Atendimento</a>
</div>

<div class="period-info">
    📅 Exibindo atendimentos do período: <strong><?= date('d/m/Y', strtotime($data_inicio)) ?></strong> até <strong><?= date('d/m/Y', strtotime($data_fim)) ?></strong>
</div>

<!-- HTML DOS CARDS DE ESTATÍSTICAS - CORRIGIDO -->
<div class="stats-grid">
    <div class="stat-card primary">
        <h3><?= intval($stats['total']) ?></h3>
        <p>Total de Atendimentos</p>
    </div>
    <div class="stat-card success">
        <h3><?= intval($stats['fechados']) ?></h3>
        <p>Contratos Fechados</p>
    </div>
    <div class="stat-card danger">
        <h3><?= intval($stats['nao_fechou']) ?></h3>
        <p>Não Fecharam</p>
    </div>
    <div class="stat-card warning">
        <h3><?= intval($stats['em_analise']) ?></h3>
        <p>Em Análise</p>
    </div>
    <div class="stat-card info">
        <h3><?= intval($stats['reunioes_pendentes']) ?></h3>
        <p>Reuniões Pendentes</p>
    </div>
</div>

<div class="filters-container">
    <form method="GET">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="data_inicio">Data Início:</label>
                <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>">
            </div>
            
            <div class="filter-group">
                <label for="data_fim">Data Fim:</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
            </div>
            
            <div class="filter-group">
                <label for="status">Status:</label>
                <select id="status" name="status">
                    <option value="">Todos</option>
                    <option value="Fechado" <?= $status_filtro === 'Fechado' ? 'selected' : '' ?>>Fechado</option>
                    <option value="Não Fechou" <?= $status_filtro === 'Não Fechou' ? 'selected' : '' ?>>Não Fechou</option>
                    <option value="Em Análise" <?= $status_filtro === 'Em Análise' ? 'selected' : '' ?>>Em Análise</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="atendido_por">Atendido Por:</label>
                <select id="atendido_por" name="atendido_por">
                    <option value="">Todos</option>
                    <?php foreach ($usuarios as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= $atendido_por == $user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <button type="submit" class="btn-filter">🔍 Filtrar Atendimentos</button>
    </form>
</div>

<?php if (empty($atendimentos)): ?>
<div class="alert-info">
    📋 Nenhum atendimento encontrado no período selecionado.
    <br><small>Tente ajustar os filtros ou o período de busca.</small>
</div>
<?php else: ?>
<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Data/Hora</th>
                <th>Cliente</th>
                <th>CPF/CNPJ</th>
                <th>Atendido Por</th>
                <th>Status</th>
                <th>Nova Reunião</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($atendimentos as $atendimento): ?>
            <tr>
                <td>
                    <strong><?= date('d/m/Y', strtotime($atendimento['data_atendimento'])) ?></strong>
                    <br><small style="color: #666;"><?= date('H:i', strtotime($atendimento['data_atendimento'])) ?></small>
                </td>
                <td>
                    <?= htmlspecialchars($atendimento['cliente_nome_cadastrado'] ?: $atendimento['cliente_nome']) ?>
                    <?php if ($atendimento['cliente_id']): ?>
                        <br><small style="color: #28a745;">✓ Cliente Cadastrado</small>
                    <?php else: ?>
                        <br><small style="color: #ffc107;">⚠ Informado Manualmente</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($atendimento['cliente_cpf_cnpj']): ?>
                        <?php
                        $cpf_cnpj = $atendimento['cliente_cpf_cnpj'];
                        if (strlen($cpf_cnpj) == 11) {
                            echo preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf_cnpj);
                        } elseif (strlen($cpf_cnpj) == 14) {
                            echo preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cpf_cnpj);
                        } else {
                            echo htmlspecialchars($cpf_cnpj);
                        }
                        ?>
                    <?php else: ?>
                        <span style="color: #999;">Não informado</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($atendimento['atendido_por_nome']) ?></td>
                <td>
                    <span class="badge badge-<?= strtolower(str_replace([' ', 'ã'], ['', 'a'], $atendimento['status_contrato'])) ?>">
                        <?= $atendimento['status_contrato'] ?>
                    </span>
                </td>
                <td>
                    <?php if ($atendimento['precisa_nova_reuniao']): ?>
                        <?php if ($atendimento['data_nova_reuniao']): ?>
                            <strong><?= date('d/m/Y H:i', strtotime($atendimento['data_nova_reuniao'])) ?></strong>
                            <br><small style="color: #666;"><?= htmlspecialchars($atendimento['responsavel_reuniao_nome'] ?? 'Não definido') ?></small>
                        <?php else: ?>
                            <span style="color: #ffc107; font-weight: 600;">⏰ A agendar</span>
                            <br><small style="color: #666;"><?= htmlspecialchars($atendimento['responsavel_reuniao_nome'] ?? 'Não definido') ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color: #999;">➖ Não necessária</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="visualizar.php?id=<?= $atendimento['id'] ?>" class="btn-action btn-view" title="Visualizar">👁️ Ver</a>
                    <a href="editar.php?id=<?= $atendimento['id'] ?>" class="btn-action btn-edit" title="Editar">✏️ Editar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
$conteudo = ob_get_clean();

// Renderizar layout
echo renderLayout('Atendimentos', $conteudo, 'atendimentos');
?>
</body>

<script>
// ==========================================
// MELHORIAS DE UX PARA INDEX.PHP
// ==========================================

document.addEventListener('DOMContentLoaded', function() {
    
    // ==========================================
    // 1. ANIMAÇÃO DE ENTRADA DOS ELEMENTOS
    // ==========================================
    const statsCards = document.querySelectorAll('.stat-card');
    statsCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'scale(0.9)';
        setTimeout(() => {
            card.style.transition = 'all 0.4s ease-out';
            card.style.opacity = '1';
            card.style.transform = 'scale(1)';
        }, 100 * index);
    });
    
    // ==========================================
    // 2. CONTADOR ANIMADO NOS CARDS DE ESTATÍSTICAS
    // ==========================================
    function animateValue(element, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            element.textContent = Math.floor(progress * (end - start) + start);
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }
    
    document.querySelectorAll('.stat-card h3').forEach(h3 => {
        const finalValue = parseInt(h3.textContent);
        if (!isNaN(finalValue)) {
            animateValue(h3, 0, finalValue, 1000);
        }
    });
    
    // ==========================================
    // 3. FILTRO RÁPIDO POR PALAVRA-CHAVE NA TABELA
    // ==========================================
    const filtersContainer = document.querySelector('.filters-container');
    
    if (filtersContainer && document.querySelector('table')) {
        const quickSearchDiv = document.createElement('div');
        quickSearchDiv.style.cssText = `
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(0,0,0,0.1);
        `;
        
        quickSearchDiv.innerHTML = `
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="font-weight: 600; color: #333;">🔍 Busca Rápida:</label>
                <input type="text" id="quickSearch" 
                       placeholder="Digite para filtrar na tabela atual..." 
                       style="flex: 1; padding: 10px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px; transition: all 0.3s;">
                <span id="searchResults" style="color: #666; font-size: 14px; font-weight: 600;"></span>
            </div>
        `;
        
        filtersContainer.appendChild(quickSearchDiv);
        
        const quickSearchInput = document.getElementById('quickSearch');
        const searchResults = document.getElementById('searchResults');
        const tableRows = document.querySelectorAll('table tbody tr');
        
        quickSearchInput.addEventListener('focus', function() {
            this.style.borderColor = '#1a1a1a';
            this.style.boxShadow = '0 0 0 4px rgba(26, 26, 26, 0.1)';
        });
        
        quickSearchInput.addEventListener('blur', function() {
            this.style.borderColor = '#ddd';
            this.style.boxShadow = 'none';
        });
        
        quickSearchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            let visibleCount = 0;
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                    row.style.animation = 'fadeIn 0.3s ease-out';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (searchTerm.length > 0) {
                searchResults.textContent = `${visibleCount} resultado(s) encontrado(s)`;
                searchResults.style.color = visibleCount > 0 ? '#28a745' : '#dc3545';
            } else {
                searchResults.textContent = '';
            }
        });
    }
    
    // ==========================================
    // 4. HIGHLIGHT DE REUNIÕES PRÓXIMAS (< 24h)
    // ==========================================
    const hoje = new Date();
    const limite24h = new Date(hoje.getTime() + (24 * 60 * 60 * 1000));
    
    document.querySelectorAll('table tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        // A coluna de "Nova Reunião" geralmente é a 6ª (índice 5)
        if (cells[5]) {
            const dateText = cells[5].textContent;
            const match = dateText.match(/(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})/);
            
            if (match) {
                const [_, dia, mes, ano, hora, minuto] = match;
                const dataReuniao = new Date(ano, mes - 1, dia, hora, minuto);
                
                if (dataReuniao < limite24h && dataReuniao > hoje) {
                    // Destacar linha inteira
                    row.style.background = 'linear-gradient(135deg, rgba(255, 193, 7, 0.15) 0%, rgba(255, 193, 7, 0.05) 100%)';
                    row.style.borderLeft = '4px solid #ffc107';
                    
                    // Adicionar badge de alerta
                    const badge = document.createElement('div');
                    badge.innerHTML = '🔔 Urgente!';
                    badge.style.cssText = `
                        display: inline-block;
                        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                        color: white;
                        padding: 4px 10px;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 700;
                        margin-left: 8px;
                        animation: pulse 2s infinite;
                    `;
                    cells[5].appendChild(badge);
                }
            }
        }
    });
    
    // ==========================================
    // 5. TOOLTIP NOS BOTÕES DE AÇÃO
    // ==========================================
    document.querySelectorAll('.btn-action').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.05)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    
    // ==========================================
    // 6. CONFIRMAÇÃO VISUAL AO APLICAR FILTROS
    // ==========================================
    const filterForm = document.querySelector('.filters-container form');
    if (filterForm) {
        filterForm.addEventListener('submit', function() {
            const btnFilter = this.querySelector('.btn-filter');
            btnFilter.innerHTML = '🔄 Aplicando filtros...';
            btnFilter.style.pointerEvents = 'none';
            btnFilter.style.opacity = '0.7';
        });
    }
    
    // ==========================================
    // 7. EXPORTAR DADOS VISÍVEIS PARA CSV
    // ==========================================
    if (document.querySelector('table')) {
        const headerActions = document.querySelector('.page-header');
        
        const btnExport = document.createElement('a');
        btnExport.href = '#';
        btnExport.className = 'btn-novo';
        btnExport.style.background = 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)';
        btnExport.style.marginLeft = '15px';
        btnExport.innerHTML = '📥 Exportar CSV';
        
        btnExport.addEventListener('click', function(e) {
            e.preventDefault();
            exportTableToCSV('atendimentos.csv');
        });
        
        headerActions.appendChild(btnExport);
    }
    
    function exportTableToCSV(filename) {
        const table = document.querySelector('table');
        const rows = table.querySelectorAll('tr');
        let csv = [];
        
        rows.forEach(row => {
            // Pular linhas ocultas (filtradas)
            if (row.style.display === 'none') return;
            
            let rowData = [];
            const cells = row.querySelectorAll('td, th');
            
            cells.forEach(cell => {
                // Remover ações da exportação
                if (!cell.textContent.includes('Ver') && !cell.textContent.includes('Editar')) {
                    let data = cell.textContent.trim().replace(/\n/g, ' ').replace(/"/g, '""');
                    rowData.push('"' + data + '"');
                }
            });
            
            if (rowData.length > 0) {
                csv.push(rowData.join(','));
            }
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (navigator.msSaveBlob) {
            navigator.msSaveBlob(blob, filename);
        } else {
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
        }
        
        // Feedback visual
        const originalText = btnExport.innerHTML;
        btnExport.innerHTML = '✓ Exportado!';
        btnExport.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
        
        setTimeout(() => {
            btnExport.innerHTML = originalText;
            btnExport.style.background = 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)';
        }, 2000);
    }
    
    // ==========================================
    // 8. COPIAR INFORMAÇÕES AO CLICAR
    // ==========================================
    document.querySelectorAll('table tbody td').forEach(cell => {
        // Detectar células com CPF/CNPJ ou telefone
        const text = cell.textContent.trim();
        if (/\d{3}\.\d{3}\.\d{3}-\d{2}/.test(text) || 
            /\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/.test(text)) {
            
            cell.style.cursor = 'pointer';
            cell.title = 'Clique para copiar';
            
            cell.addEventListener('click', function(e) {
                // Evitar copiar se clicou num link
                if (e.target.tagName === 'A') return;
                
                const textToCopy = this.textContent.trim().split('\n')[0];
                
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalBg = this.style.backgroundColor;
                    this.style.backgroundColor = '#d4edda';
                    this.style.transition = 'background-color 0.3s';
                    
                    setTimeout(() => {
                        this.style.backgroundColor = originalBg;
                    }, 1000);
                });
            });
        }
    });
    
    // ==========================================
    // 9. INDICADOR DE LOADING PARA LINKS
    // ==========================================
    document.querySelectorAll('a[href*="visualizar.php"], a[href*="editar.php"]').forEach(link => {
        link.addEventListener('click', function() {
            this.style.opacity = '0.6';
            this.style.pointerEvents = 'none';
            
            const spinner = document.createElement('span');
            spinner.innerHTML = ' 🔄';
            spinner.style.animation = 'spin 1s linear infinite';
            this.appendChild(spinner);
        });
    });
    
    // ==========================================
    // 10. TOOLTIP CUSTOMIZADO PARA STATUS
    // ==========================================
    document.querySelectorAll('.badge').forEach(badge => {
        badge.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
            this.style.transition = 'transform 0.2s';
        });
        
        badge.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
    
    // ==========================================
    // 11. SCROLL SUAVE AO CLICAR NOS STATS
    // ==========================================
    document.querySelectorAll('.stat-card').forEach(card => {
        card.style.cursor = 'pointer';
        
        card.addEventListener('click', function() {
            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                tableContainer.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
                
                // Highlight temporário da tabela
                tableContainer.style.boxShadow = '0 0 0 4px rgba(26, 26, 26, 0.2)';
                setTimeout(() => {
                    tableContainer.style.boxShadow = '0 8px 32px rgba(0,0,0,0.15)';
                }, 1000);
            }
        });
    });
    
    // ==========================================
    // 12. ATUALIZAÇÃO AUTOMÁTICA DO PERÍODO
    // ==========================================
    const periodInfo = document.querySelector('.period-info');
    if (periodInfo) {
        periodInfo.style.cursor = 'pointer';
        periodInfo.title = 'Clique para atualizar';
        
        periodInfo.addEventListener('click', function() {
            location.reload();
        });
    }
    
});

// Adicionar keyframe para animação de spin (se não existir)
if (!document.querySelector('#spin-keyframe')) {
    const style = document.createElement('style');
    style.id = 'spin-keyframe';
    style.innerHTML = `
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    `;
    document.head.appendChild(style);
}
</script>

<style>
/* Estilos adicionais para melhorias de UX */
.stat-card {
    cursor: pointer;
    user-select: none;
}

.stat-card:active {
    transform: scale(0.98) !important;
}

table tbody tr {
    transition: all 0.3s ease;
}

table tbody tr:hover {
    transform: translateX(4px);
    box-shadow: -4px 0 0 0 rgba(26, 26, 26, 0.1);
}

.btn-action {
    transition: all 0.2s ease !important;
}

/* Melhor responsividade para a busca rápida */
@media (max-width: 768px) {
    #quickSearch {
        font-size: 16px !important; /* Previne zoom no iOS */
    }
}

/* Loading state visual */
@keyframes shimmer {
    0% {
        background-position: -1000px 0;
    }
    100% {
        background-position: 1000px 0;
    }
}

.loading-shimmer {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 1000px 100%;
    animation: shimmer 2s infinite;
}
</style>