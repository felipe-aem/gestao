<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$nivel_acesso = $usuario_logado['nivel_acesso'];
$eh_admin = in_array($nivel_acesso, ['Admin', 'Administrador', 'Socio', 'Diretor']);

// Buscar estatísticas
$stats = [
    'atendimentos_hoje' => 0,
    'processos_ativos' => 0,
    'compromissos_agenda' => 0,
    'clientes_cadastrados' => 0,
    'publicacoes_pendentes' => 0,
    'prazos_urgentes' => 0,
    'tarefas_pendentes' => 0
];

try {
    // Atendimentos hoje
    $sql = "SELECT COUNT(*) as count FROM atendimentos WHERE DATE(data_atendimento) = CURDATE()";
    $stmt = executeQuery($sql);
    $stats['atendimentos_hoje'] = $stmt->fetch()['count'];

    // Processos ativos
    $sql = "SELECT COUNT(*) as count FROM processos WHERE ativo = 1";
    $stmt = executeQuery($sql);
    $stats['processos_ativos'] = $stmt->fetch()['count'];

    // Clientes cadastrados
    $sql = "SELECT COUNT(*) as count FROM clientes WHERE ativo = 1";
    $stmt = executeQuery($sql);
    $stats['clientes_cadastrados'] = $stmt->fetch()['count'];

    // Publicações pendentes
    $sql = "SELECT COUNT(*) as count FROM publicacoes WHERE status_tratamento = 'nao_tratado' AND deleted_at IS NULL";
    $stmt = executeQuery($sql);
    $stats['publicacoes_pendentes'] = $stmt->fetch()['count'];

    // Prazos urgentes (48h)
    $sql = "SELECT COUNT(*) as count FROM prazos 
            WHERE status IN ('pendente', 'em_andamento') 
            AND data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)";
    $stmt = executeQuery($sql);
    $stats['prazos_urgentes'] = $stmt->fetch()['count'];

    // Tarefas pendentes
    $sql = "SELECT COUNT(*) as count FROM tarefas WHERE status IN ('pendente', 'em_andamento')";
    $stmt = executeQuery($sql);
    $stats['tarefas_pendentes'] = $stmt->fetch()['count'];

    // Eventos do dia (agenda)
    $sql = "SELECT a.*, c.nome as cliente_nome 
            FROM agenda a 
            LEFT JOIN clientes c ON a.cliente_id = c.id 
            WHERE DATE(a.data_inicio) = CURDATE() 
            AND a.status IN ('Agendado', 'Reagendado')
            ORDER BY TIME(a.data_inicio) ASC";
    $stmt = executeQuery($sql);
    $eventos_hoje = $stmt->fetchAll();
    $stats['compromissos_agenda'] = count($eventos_hoje);

    // Prazos próximos (7 dias)
    $sql = "SELECT p.*, pr.numero_processo, pr.cliente_nome
            FROM prazos p
            INNER JOIN processos pr ON p.processo_id = pr.id
            WHERE p.status IN ('pendente', 'em_andamento')
            AND p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY p.data_vencimento ASC
            LIMIT 5";
    $stmt = executeQuery($sql);
    $prazos_proximos = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Erro no dashboard: " . $e->getMessage());
    $eventos_hoje = [];
    $prazos_proximos = [];
}

ob_start();
?>
<style>
    .dashboard-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
    }
    
    .dashboard-header h1 {
        color: #1a1a1a;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    
    .dashboard-header p {
        color: #666;
        font-size: 16px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 25px;
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
        position: relative;
        overflow: hidden;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    }
    
    .stat-card.primary { border-left-color: #007bff; }
    .stat-card.success { border-left-color: #28a745; }
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.info { border-left-color: #17a2b8; }
    .stat-card.danger { border-left-color: #dc3545; }
    .stat-card.purple { border-left-color: #667eea; }
    
    .stat-icon {
        font-size: 40px;
        margin-bottom: 15px;
        display: block;
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #1a1a1a;
    }
    
    .stat-label {
        color: #666;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-link {
        display: block;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(0,0,0,0.1);
        color: #667eea;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .stat-link:hover {
        color: #764ba2;
    }
    
    .quick-actions {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
    }
    
    .quick-actions h2 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 20px;
        font-weight: 700;
    }
    
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }
    
    .action-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px 20px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border: 1px solid #dee2e6;
        border-radius: 10px;
        text-decoration: none;
        color: #495057;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .action-btn:hover {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
    }
    
    .action-icon {
        font-size: 20px;
    }
    
    .welcome-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 25px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    }
    
    .welcome-card h2 {
        color: #ffffff;
        margin-bottom: 10px;
        font-size: 22px;
    }
    
    .welcome-card p {
        color: #ffffff;
        line-height: 1.6;
    }
    
    .eventos-hoje, .prazos-proximos {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
    }

    .eventos-hoje h2, .prazos-proximos h2 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 20px;
        font-weight: 700;
    }

    .evento-item, .prazo-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 10px;
        margin-bottom: 10px;
        border-left: 4px solid #007bff;
        transition: all 0.3s ease;
    }

    .prazo-item {
        border-left-color: #ffc107;
    }

    .prazo-item.urgente {
        border-left-color: #dc3545;
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
    }

    .evento-item:hover, .prazo-item:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .evento-hora, .prazo-data {
        background: #007bff;
        color: white;
        padding: 8px 12px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        min-width: 70px;
        text-align: center;
    }

    .prazo-data {
        background: #ffc107;
        color: #000;
        min-width: 90px;
    }

    .prazo-item.urgente .prazo-data {
        background: #dc3545;
        color: white;
    }

    .evento-info, .prazo-info {
        flex: 1;
    }

    .evento-titulo, .prazo-titulo {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 4px;
    }

    .evento-cliente, .prazo-processo {
        color: #666;
        font-size: 14px;
    }

    .sem-eventos, .sem-prazos {
        text-align: center;
        padding: 30px;
        color: #666;
    }

    .sem-eventos-icon, .sem-prazos-icon {
        font-size: 48px;
        margin-bottom: 15px;
        display: block;
    }

    .alert-urgent {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
        border: 2px solid rgba(220, 53, 69, 0.3);
        border-radius: 15px;
        padding: 20px 25px;
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .alert-icon {
        font-size: 32px;
    }

    .alert-content h3 {
        color: #721c24;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .alert-content p {
        color: #721c24;
        margin: 0;
    }

    .two-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
    }

    @media (max-width: 1024px) {
        .two-columns {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="welcome-card">
    <h2>👋 Olá, <?= htmlspecialchars($usuario_logado['nome']) ?>!</h2>
    <p>Bem-vindo ao Sistema Integrado de Gestão do Escritório Alencar &amp; Martinazzo Advogados. Aqui você tem acesso a todas as funcionalidades para gerenciar processos, clientes, publicações e muito mais.</p>
</div>

<?php if ($stats['prazos_urgentes'] > 0): ?>
<div class="alert-urgent">
    <div class="alert-icon">🚨</div>
    <div class="alert-content">
        <h3>Atenção! Prazos Urgentes</h3>
        <p>Você tem <?= $stats['prazos_urgentes'] ?> prazo(s) vencendo nas próximas 48 horas!</p>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card primary">
        <span class="stat-icon">👥</span>
        <div class="stat-number"><?= $stats['atendimentos_hoje'] ?></div>
        <div class="stat-label">Atendimentos Hoje</div>
        <a href="../atendimentos/" class="stat-link">Ver todos →</a>
    </div>
    
    <div class="stat-card success">
        <span class="stat-icon">⚖️</span>
        <div class="stat-number"><?= $stats['processos_ativos'] ?></div>
        <div class="stat-label">Processos Ativos</div>
        <a href="../processos/" class="stat-link">Ver todos →</a>
    </div>
    
    <div class="stat-card danger">
        <span class="stat-icon">📄</span>
        <div class="stat-number"><?= $stats['publicacoes_pendentes'] ?></div>
        <div class="stat-label">Publicações Pendentes</div>
        <a href="../publicacoes/" class="stat-link">Ver todas →</a>
    </div>
    
    <div class="stat-card warning">
        <span class="stat-icon">⏰</span>
        <div class="stat-number"><?= $stats['prazos_urgentes'] ?></div>
        <div class="stat-label">Prazos Urgentes</div>
        <a href="../prazos/" class="stat-link">Ver todos →</a>
    </div>

    <div class="stat-card purple">
        <span class="stat-icon">✓</span>
        <div class="stat-number"><?= $stats['tarefas_pendentes'] ?></div>
        <div class="stat-label">Tarefas Pendentes</div>
        <a href="../tarefas/" class="stat-link">Ver todas →</a>
    </div>
    
    <div class="stat-card info">
        <span class="stat-icon">👤</span>
        <div class="stat-number"><?= $stats['clientes_cadastrados'] ?></div>
        <div class="stat-label">Clientes Cadastrados</div>
        <a href="../clientes/" class="stat-link">Ver todos →</a>
    </div>
</div>

<div class="two-columns">
    <div class="eventos-hoje">
        <h2>📅 Eventos de Hoje</h2>
        <?php if (empty($eventos_hoje)): ?>
            <div class="sem-eventos">
                <span class="sem-eventos-icon">🗓️</span>
                <p>Nenhum compromisso agendado para hoje.</p>
            </div>
        <?php else: ?>
            <?php foreach ($eventos_hoje as $evento): ?>
                <div class="evento-item">
                    <div class="evento-hora">
                        <?= date('H:i', strtotime($evento['data_inicio'])) ?>
                    </div>
                    <div class="evento-info">
                        <div class="evento-titulo">
                            <?= htmlspecialchars($evento['titulo']) ?>
                            <?php if ($evento['tipo']): ?>
                                <span style="background: #007bff; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;">
                                    <?= htmlspecialchars($evento['tipo']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($evento['cliente_nome']): ?>
                            <div class="evento-cliente">
                                👤 <?= htmlspecialchars($evento['cliente_nome']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="prazos-proximos">
        <h2>⏰ Prazos Próximos (7 dias)</h2>
        <?php if (empty($prazos_proximos)): ?>
            <div class="sem-prazos">
                <span class="sem-prazos-icon">✓</span>
                <p>Nenhum prazo nos próximos 7 dias.</p>
            </div>
        <?php else: ?>
            <?php foreach ($prazos_proximos as $prazo): 
                $data_venc = new DateTime($prazo['data_vencimento']);
                $hoje = new DateTime();
                $diff = $hoje->diff($data_venc);
                $dias = $diff->days;
                $urgente = $dias <= 2;
            ?>
                <div class="prazo-item <?= $urgente ? 'urgente' : '' ?>">
                    <div class="prazo-data">
                        <?= date('d/m', strtotime($prazo['data_vencimento'])) ?><br>
                        <small><?= $dias ?>d</small>
                    </div>
                    <div class="prazo-info">
                        <div class="prazo-titulo">
                            <?= htmlspecialchars($prazo['titulo']) ?>
                        </div>
                        <div class="prazo-processo">
                            ⚖️ <?= htmlspecialchars($prazo['numero_processo']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="quick-actions">
    <h2>🚀 Ações Rápidas</h2>
    <div class="actions-grid">
        <a href="../atendimentos/novo.php" class="action-btn">
            <span class="action-icon">➕</span>
            Novo Atendimento
        </a>
        
        <a href="../processos/novo.php" class="action-btn">
            <span class="action-icon">📋</span>
            Novo Processo
        </a>
        
        <a href="../clientes/novo.php" class="action-btn">
            <span class="action-icon">👤</span>
            Novo Cliente
        </a>
        
        <a href="../agenda/novo.php" class="action-btn">
            <span class="action-icon">📅</span>
            Agendar Compromisso
        </a>
        
        <a href="../publicacoes/" class="action-btn">
            <span class="action-icon">📄</span>
            Ver Publicações
        </a>
        
        <a href="../processos/" class="action-btn">
            <span class="action-icon">⚖️</span>
            Ver Processos
        </a>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Dashboard', $conteudo, 'dashboard');
?>