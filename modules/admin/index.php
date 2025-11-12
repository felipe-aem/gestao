<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']); // Usar os mesmos níveis da base funcional

require_once '../../config/database.php';
require_once '../../includes/layout.php'; // Incluir o layout padronizado

// Buscar estatísticas do sistema
try {
    $stats = [];
    
    // Total de usuários
    $sql = "SELECT COUNT(*) as total FROM usuarios";
    $stmt = executeQuery($sql);
    $stats['usuarios'] = $stmt->fetch()['total'];
    
    // Usuários ativos
    $sql = "SELECT COUNT(*) as total FROM usuarios WHERE ativo = 1";
    $stmt = executeQuery($sql);
    $stats['usuarios_ativos'] = $stmt->fetch()['total'];
    
    // Total de clientes
    $sql = "SELECT COUNT(*) as total FROM clientes";
    $stmt = executeQuery($sql);
    $stats['clientes'] = $stmt->fetch()['total'];
    
    // Eventos futuros
    $sql = "SELECT COUNT(*) as total FROM agenda WHERE data_inicio >= NOW()";
    $stmt = executeQuery($sql);
    $stats['eventos_futuros'] = $stmt->fetch()['total'];
    
    // Logs hoje
    $sql = "SELECT COUNT(*) as total FROM logs_sistema WHERE DATE(data_acao) = CURDATE()";
    $stmt = executeQuery($sql);
    $stats['logs_hoje'] = $stmt->fetch()['total'];
    
    // Total de clientes
    $sql = "SELECT COUNT(*) as total FROM clientes";
    $stmt = executeQuery($sql);
    $stats['clientes'] = $stmt->fetch()['total'];
    
    // Eventos futuros
    $sql = "SELECT COUNT(*) as total FROM agenda WHERE data_inicio >= NOW()";
    $stmt = executeQuery($sql);
    $stats['eventos_futuros'] = $stmt->fetch()['total'];
    
    // Logs hoje
    $sql = "SELECT COUNT(*) as total FROM logs_sistema WHERE DATE(data_acao) = CURDATE()";
    $stmt = executeQuery($sql);
    $stats['logs_hoje'] = $stmt->fetch()['total'];
    
    // Últimos logins
    $sql = "SELECT u.nome, u.ultimo_login, u.ip_ultimo_login 
            FROM usuarios u 
            WHERE u.ultimo_login IS NOT NULL 
            ORDER BY u.ultimo_login DESC 
            LIMIT 5";
    $stmt = executeQuery($sql);
    $ultimos_logins = $stmt->fetchAll();
    
    // Logs recentes
    $sql = "SELECT ls.*, u.nome as usuario_nome 
            FROM logs_sistema ls 
            LEFT JOIN usuarios u ON ls.usuario_id = u.id 
            ORDER BY ls.data_acao DESC 
            LIMIT 10";
    $stmt = executeQuery($sql);
    $logs_recentes = $stmt->fetchAll();
    
} catch (Exception $e) {
    // Debug: mostrar o erro se houver
    error_log("Erro nas estatísticas: " . $e->getMessage());
    $stats = ['usuarios' => 0, 'usuarios_ativos' => 0, 'clientes' => 0, 'eventos_futuros' => 0, 'logs_hoje' => 0];
    $ultimos_logins = [];
    $logs_recentes = [];
}

// Conteúdo da página
ob_start();
?>
<style>
    /* Estilos específicos para a página de administração */
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        text-align: center;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .page-subtitle {
        color: #666;
        font-size: 16px;
    }
    
    .admin-menu {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .admin-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        text-align: center;
        transition: all 0.3s ease;
        text-decoration: none;
        color: inherit;
        border-left: 4px solid transparent;
    }
    
    .admin-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        text-decoration: none;
        color: inherit;
    }
    
    .admin-card.usuarios { border-left-color: #007bff; }
    .admin-card.logs { border-left-color: #28a745; }
    .admin-card.config { border-left-color: #ffc107; }
    
    .admin-icon {
        font-size: 48px;
        margin-bottom: 15px;
        display: block;
    }
    
    .admin-card.usuarios .admin-icon { color: #007bff; }
    .admin-card.logs .admin-icon { color: #28a745; }
    .admin-card.config .admin-icon { color: #ffc107; }
    
    .admin-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 10px;
        color: #1a1a1a;
    }
    
    .admin-description {
        color: #666;
        font-size: 14px;
        line-height: 1.5;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 20px;
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
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.info { border-left-color: #17a2b8; }
    .stat-card.danger { border-left-color: #dc3545; }
    
    .stat-card.primary h3 { color: #007bff; }
    .stat-card.success h3 { color: #28a745; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.info h3 { color: #17a2b8; }
    .stat-card.danger h3 { color: #dc3545; }
    
    .content-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .info-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
    }
    
    .section-title {
        color: #1a1a1a;
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .login-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px;
        background: rgba(0,0,0,0.03);
        border-radius: 8px;
        margin-bottom: 10px;
    }
    
    .login-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #007bff;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 16px;
    }
    
    .login-info {
        flex: 1;
    }
    
    .login-nome {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 2px;
    }
    
    .login-data {
        font-size: 12px;
        color: #666;
    }
    
    .log-item {
        padding: 10px;
        background: rgba(0,0,0,0.03);
        border-radius: 8px;
        margin-bottom: 8px;
        border-left: 3px solid #28a745;
    }
    
    .log-acao {
        font-weight: 600;
        color: #1a1a1a;
        font-size: 14px;
        margin-bottom: 2px;
    }
    
    .log-meta {
        font-size: 12px;
        color: #666;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }
    
    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 15px;
        opacity: 0.5;
    }
    
    @media (max-width: 768px) {
        .admin-menu {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }
        
        .content-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h2>👨‍💼 Administração do Sistema</h2>
    <p class="page-subtitle">Painel de controle e gerenciamento</p>
</div>

<!-- Menu Principal -->
<div class="admin-menu">
    <a href="usuarios.php" class="admin-card usuarios">
        <span class="admin-icon">👥</span>
        <div class="admin-title">Gerenciar Usuários</div>
        <div class="admin-description">
            Criar, editar e gerenciar usuários do sistema. 
            Controlar permissões e níveis de acesso.
        </div>
    </a>
    
    <a href="logs.php" class="admin-card logs">
        <span class="admin-icon">📊</span>
        <div class="admin-title">Logs do Sistema</div>
        <div class="admin-description">
            Visualizar logs de atividades, erros e 
            auditoria do sistema.
        </div>
    </a>
</div>
<?php
$conteudo = ob_get_clean();

// Renderizar usando o layout padronizado
echo renderLayout('Administração', $conteudo, 'admin');
?>
