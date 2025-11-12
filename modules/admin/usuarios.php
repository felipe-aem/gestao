<?php
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se o usuário é administrador
require_once '../../includes/admin_constants.php';
$usuario_logado = requireAdminAccess();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Filtros
$busca = $_GET['busca'] ?? '';
$nivel_acesso = $_GET['nivel_acesso'] ?? '';
$ativo = $_GET['ativo'] ?? '';

try {
    // Construir query com filtros
    $where_conditions = [];
    $params = [];
    
    if (!empty($busca)) {
        $where_conditions[] = "(nome LIKE ? OR email LIKE ?)";
        $params[] = "%$busca%";
        $params[] = "%$busca%";
    }
    
    if (!empty($nivel_acesso)) {
        $where_conditions[] = "nivel_acesso = ?";
        $params[] = $nivel_acesso;
    }
    
    if ($ativo !== '') {
        $where_conditions[] = "ativo = ?";
        $params[] = $ativo;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Buscar usuários
    $sql = "SELECT * FROM usuarios $where_clause ORDER BY nome ASC";
    $stmt = executeQuery($sql, $params);
    $usuarios = $stmt->fetchAll();
    
    // Estatísticas atualizadas baseadas nos dados reais
    $sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) as inativos,
        SUM(CASE WHEN nivel_acesso = 'Admin' THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN nivel_acesso = 'Socio' THEN 1 ELSE 0 END) as socios,
        SUM(CASE WHEN nivel_acesso = 'Diretor' THEN 1 ELSE 0 END) as diretores,
        SUM(CASE WHEN nivel_acesso = 'Gestor' THEN 1 ELSE 0 END) as gestores,
        SUM(CASE WHEN nivel_acesso = 'Advogado' THEN 1 ELSE 0 END) as advogados,
        SUM(CASE WHEN nivel_acesso = 'Assistente' THEN 1 ELSE 0 END) as assistentes,
        SUM(CASE WHEN DATE(data_criacao) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as novos_30_dias
        FROM usuarios $where_clause";
    
    $stmt = executeQuery($sql, $params);
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    $usuarios = [];
    $stats = [
        'total' => 0, 'ativos' => 0, 'inativos' => 0, 'admins' => 0, 'socios' => 0, 
        'diretores' => 0, 'gestores' => 0, 'advogados' => 0, 'assistentes' => 0, 'novos_30_dias' => 0
    ];
}

// Função para obter classe da badge do nível
function getBadgeClass($nivel) {
    $classes = [
        'Admin' => 'badge-admin',
        'Socio' => 'badge-socio', 
        'Diretor' => 'badge-diretor',
        'Gestor' => 'badge-gestor',
        'Advogado' => 'badge-advogado',
        'Assistente' => 'badge-assistente'
    ];
    return $classes[$nivel] ?? 'badge-advogado';
}

// Função para obter classe do card
function getCardClass($usuario) {
    $nivel_class = strtolower($usuario['nivel_acesso']);
    $status_class = !$usuario['ativo'] ? ' inativo' : '';
    return $nivel_class . $status_class;
}

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
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-block;
        text-align: center;
        border: none;
        cursor: pointer;
    }
    
    .btn-voltar {
        background: #6c757d;
        color: white;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .btn-novo {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .btn-novo:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 15px;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        text-align: center;
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    }
    
    .stat-card h3 {
        color: #1a1a1a;
        font-size: 24px;
        margin-bottom: 5px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0;
    }
    
    .stat-card.total { border-left-color: #007bff; }
    .stat-card.ativos { border-left-color: #28a745; }
    .stat-card.inativos { border-left-color: #dc3545; }
    .stat-card.admins { border-left-color: #dc3545; }
    .stat-card.socios { border-left-color: #fd7e14; }
    .stat-card.diretores { border-left-color: #6f42c1; }
    .stat-card.gestores { border-left-color: #20c997; }
    .stat-card.advogados { border-left-color: #007bff; }
    .stat-card.assistentes { border-left-color: #6c757d; }
    .stat-card.novos { border-left-color: #17a2b8; }
    
    .stat-card.total h3 { color: #007bff; }
    .stat-card.ativos h3 { color: #28a745; }
    .stat-card.inativos h3 { color: #dc3545; }
    .stat-card.admins h3 { color: #dc3545; }
    .stat-card.socios h3 { color: #fd7e14; }
    .stat-card.diretores h3 { color: #6f42c1; }
    .stat-card.gestores h3 { color: #20c997; }
    .stat-card.advogados h3 { color: #007bff; }
    .stat-card.assistentes h3 { color: #6c757d; }
    .stat-card.novos h3 { color: #17a2b8; }
    
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
    }
    
    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .usuarios-grid {
        display: grid;
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .usuario-card {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid #e9ecef;
        border-radius: 15px;
        padding: 25px;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .usuario-card::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: #007bff;
    }
    
    .usuario-card.admin::before { background: #dc3545; }
    .usuario-card.socio::before { background: #fd7e14; }
    .usuario-card.diretor::before { background: #6f42c1; }
    .usuario-card.gestor::before { background: #20c997; }
    .usuario-card.advogado::before { background: #007bff; }
    .usuario-card.assistente::before { background: #6c757d; }
    .usuario-card.inativo::before { background: #adb5bd; }
    
    .usuario-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    
    .usuario-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .usuario-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: #007bff;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 24px;
        margin-right: 15px;
    }
    
    .usuario-info {
        flex: 1;
    }
    
    .usuario-nome {
        font-size: 20px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 5px;
    }
    
    .usuario-email {
        color: #666;
        font-size: 14px;
        margin-bottom: 5px;
    }
    
    .usuario-badges {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 15px;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Badges coloridas para níveis */
    .badge-admin { 
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
    }
    .badge-socio { 
        background: linear-gradient(135deg, #fd7e14 0%, #e55a00 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(253, 126, 20, 0.3);
    }
    .badge-diretor { 
        background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(111, 66, 193, 0.3);
    }
    .badge-gestor { 
        background: linear-gradient(135deg, #20c997 0%, #17a085 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(32, 201, 151, 0.3);
    }
    .badge-advogado { 
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
    }
    .badge-assistente { 
        background: linear-gradient(135deg, #6c757d 0%, #545b62 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(108, 117, 125, 0.3);
    }
    
    /* Badges para status */
    .badge-ativo { 
        background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }
    .badge-inativo { 
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%); 
        color: white; 
        box-shadow: 0 2px 8px rgba(108, 117, 125, 0.3);
    }
    
    .usuario-detalhes {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .detalhe-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: #666;
    }
    
    .detalhe-icon {
        font-size: 16px;
        width: 20px;
        text-align: center;
    }
    
    .usuario-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    
    .btn-action {
        padding: 6px 12px;
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
    
    .btn-toggle {
        background: #ffc107;
        color: #000;
    }
    
    .btn-toggle:hover {
        background: #e0a800;
        transform: translateY(-1px);
    }
    
    .btn-toggle.inativo {
        background: #28a745;
        color: white;
    }
    
    .btn-toggle.inativo:hover {
        background: #218838;
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
    
    .btn {
        padding: 6px 12px;
        border-radius: 5px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        display: inline-block;
        margin: 2px;
        transition: all 0.3s;
    }
    
    .btn-warning {
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        color: #000;
    }
    
    .btn-warning:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
    }
    
    .btn-info {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
    }
    
    .btn-info:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
    }
        
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            text-align: center;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        }
        
        .filters-grid {
            grid-template-columns: 1fr;
        }
        
        .usuario-header {
            flex-direction: column;
            gap: 10px;
        }
        
        .usuario-detalhes {
            grid-template-columns: 1fr;
        }
        
        .usuario-actions {
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <h2>👥 Gerenciar Usuários</h2>
    <div class="header-actions">
        <a href="index.php" class="btn btn-voltar">← Voltar</a>
        <a href="novo_usuario.php" class="btn btn-novo">+ Novo Usuário</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card total">
        <h3><?= $stats['total'] ?></h3>
        <p>Total</p>
    </div>
    <div class="stat-card ativos">
        <h3><?= $stats['ativos'] ?></h3>
        <p>Ativos</p>
    </div>
    <div class="stat-card inativos">
        <h3><?= $stats['inativos'] ?></h3>
        <p>Inativos</p>
    </div>
    <div class="stat-card admins">
        <h3><?= $stats['admins'] ?></h3>
        <p>Admins</p>
    </div>
    <div class="stat-card socios">
        <h3><?= $stats['socios'] ?></h3>
        <p>Sócios</p>
    </div>
    <div class="stat-card diretores">
        <h3><?= $stats['diretores'] ?></h3>
        <p>Diretores</p>
    </div>
    <div class="stat-card gestores">
        <h3><?= $stats['gestores'] ?></h3>
        <p>Gestores</p>
    </div>
    <div class="stat-card advogados">
        <h3><?= $stats['advogados'] ?></h3>
        <p>Advogados</p>
    </div>
    <div class="stat-card assistentes">
        <h3><?= $stats['assistentes'] ?></h3>
        <p>Assistentes</p>
    </div>
    <div class="stat-card novos">
        <h3><?= $stats['novos_30_dias'] ?></h3>
        <p>Novos 30d</p>
    </div>
</div>

<div class="filters-container">
    <form method="GET">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="busca">Buscar:</label>
                <input type="text" id="busca" name="busca" value="<?= htmlspecialchars($busca) ?>" 
                       placeholder="Nome ou email">
            </div>
            
            <div class="filter-group">
                <label for="nivel_acesso">Nível de Acesso:</label>
                <select id="nivel_acesso" name="nivel_acesso">
                    <option value="">Todos</option>
                    <option value="Admin" <?= $nivel_acesso === 'Admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="Socio" <?= $nivel_acesso === 'Socio' ? 'selected' : '' ?>>Sócio</option>
                    <option value="Diretor" <?= $nivel_acesso === 'Diretor' ? 'selected' : '' ?>>Diretor</option>
                    <option value="Gestor" <?= $nivel_acesso === 'Gestor' ? 'selected' : '' ?>>Gestor</option>
                    <option value="Advogado" <?= $nivel_acesso === 'Advogado' ? 'selected' : '' ?>>Advogado</option>
                    <option value="Assistente" <?= $nivel_acesso === 'Assistente' ? 'selected' : '' ?>>Assistente</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="ativo">Status:</label>
                <select id="ativo" name="ativo">
                    <option value="">Todos</option>
                    <option value="1" <?= $ativo === '1' ? 'selected' : '' ?>>Ativos</option>
                    <option value="0" <?= $ativo === '0' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
        </div>
        
        <button type="submit" class="btn-filter">🔍 Filtrar Usuários</button>
    </form>
</div>

<?php if (empty($usuarios)): ?>
<div class="alert-info">
    👥 Nenhum usuário encontrado com os filtros aplicados.
    <br><small>Tente ajustar os filtros ou cadastrar um novo usuário.</small>
</div>
<?php else: ?>
<div class="usuarios-grid">
    <?php foreach ($usuarios as $usuario): ?>
    <div class="usuario-card <?= getCardClass($usuario) ?>">
        <div class="usuario-header">
            <div style="display: flex; align-items: center;">
                <div class="usuario-avatar">
                    <?= strtoupper(substr($usuario['nome'], 0, 1)) ?>
                </div>
                <div class="usuario-info">
                    <div class="usuario-nome"><?= htmlspecialchars($usuario['nome']) ?></div>
                    <div class="usuario-email"><?= htmlspecialchars($usuario['email']) ?></div>
                </div>
            </div>
            <div class="usuario-badges">
                <span class="badge <?= getBadgeClass($usuario['nivel_acesso']) ?>">
                    <?= $usuario['nivel_acesso'] ?>
                </span>
                <span class="badge badge-<?= $usuario['ativo'] ? 'ativo' : 'inativo' ?>">
                    <?= $usuario['ativo'] ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
        </div>
        
        <div class="usuario-detalhes">
            <div class="detalhe-item">
                <span class="detalhe-icon">📧</span>
                <span><?= htmlspecialchars($usuario['email']) ?></span>
            </div>
            
            <?php if (!empty($usuario['telefone'])): ?>
            <div class="detalhe-item">
                <span class="detalhe-icon">📱</span>
                <span><?= htmlspecialchars($usuario['telefone']) ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($usuario['ultimo_acesso'])): ?>
            <div class="detalhe-item">
                <span class="detalhe-icon">🕐</span>
                <span>Último acesso: <?= date('d/m/Y H:i', strtotime($usuario['ultimo_acesso'])) ?></span>
            </div>
            <?php endif; ?>
            
            <div class="detalhe-item">
                <span class="detalhe-icon">📅</span>
                <span>Criado: <?= date('d/m/Y', strtotime($usuario['data_criacao'])) ?></span>
            </div>
        </div>
        
        <div class="usuario-actions">
            <!-- Botão Resetar Senha -->
            <a href="reset_senha.php?id=<?= $usuario['id'] ?>" 
               class="btn btn-warning btn-sm"
               onclick="return confirm('Resetar senha do usuário <?= htmlspecialchars($usuario['nome']) ?>?\n\nUma senha temporária será gerada.')">
                🔑 Resetar Senha
            </a>
            
            <!-- Botão Login Como (APENAS PARA ADMIN e se não for o próprio) -->
            <?php if ($usuario_logado['nivel_acesso'] === 'Admin' && $usuario['id'] != $usuario_logado['usuario_id']): ?>
                <a href="login_como_usuario.php?id=<?= $usuario['id'] ?>" 
                   class="btn btn-info btn-sm"
                   onclick="return confirm('⚠️ IMPERSONAÇÃO\n\nVocê será redirecionado para a conta de:\n<?= htmlspecialchars($usuario['nome']) ?>\n\nPara voltar, clique no banner vermelho no topo.\n\nTodas as ações serão registradas.\n\nContinuar?')">
                    🎭 Login Como
                </a>
            <?php endif; ?>
            <a href="visualizar_usuario.php?id=<?= $usuario['id'] ?>" class="btn-action btn-view" title="Visualizar">👁️ Ver</a>
            <a href="editar_usuario.php?id=<?= $usuario['id'] ?>" class="btn-action btn-edit" title="Editar">✏️ Editar</a>
            <?php if ($usuario['id'] != $usuario_logado['id']): ?>
            <a href="toggle_usuario.php?id=<?= $usuario['id'] ?>" 
               class="btn-action btn-toggle <?= !$usuario['ativo'] ? 'inativo' : '' ?>" 
               title="<?= $usuario['ativo'] ? 'Desativar' : 'Reativar' ?>"
               onclick="return confirm('Tem certeza que deseja <?= $usuario['ativo'] ? 'desativar' : 'reativar' ?> este usuário?')">
                <?= $usuario['ativo'] ? '🔒 Desativar' : '🔓 Reativar' ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Gerenciar Usuários', $conteudo, 'usuarios');
?>