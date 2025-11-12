<?php
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se o usuário é administrador
$usuario_logado = Auth::user();
$niveis_admin = ['Admin', 'Administrador', 'Socio', 'Diretor'];
if (!in_array($usuario_logado['nivel_acesso'], $niveis_admin)) {
    header('Location: ' . SITE_URL . '/modules/dashboard/?erro=Acesso negado');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_id = $_GET['id'] ?? 0;

if (!$usuario_id) {
    header('Location: usuarios.php');
    exit;
}

try {
    // Buscar dados do usuário
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $usuario = $stmt->fetch();
    
    if (!$usuario) {
        header('Location: usuarios.php?erro=Usuário não encontrado');
        exit;
    }
    
    // Buscar estatísticas do usuário
    $stats = [];
    
	// Buscar núcleos do usuário
	$nucleos_usuario_nomes = [];
	try {
		$sql_check_table = "SHOW TABLES LIKE 'usuarios_nucleos'";
		$stmt_check_table = executeQuery($sql_check_table);

		if ($stmt_check_table && $stmt_check_table->fetch()) {
			$sql_nucleos = "SELECT n.nome 
						   FROM usuarios_nucleos un 
						   JOIN nucleos n ON un.nucleo_id = n.id 
						   WHERE un.usuario_id = ? AND n.ativo = 1 
						   ORDER BY n.nome";
			$stmt_nucleos = executeQuery($sql_nucleos, [$usuario_id]);
			if ($stmt_nucleos) {
				$nucleos_usuario_nomes = $stmt_nucleos->fetchAll(PDO::FETCH_COLUMN);
			}
		}
	} catch (Exception $e) {
		error_log("Erro ao carregar núcleos do usuário: " . $e->getMessage());
	}
	
    // Logins recentes
    $sql = "SELECT COUNT(*) as total FROM logs_sistema 
            WHERE usuario_id = ? AND acao = 'Login' 
            AND data_acao >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = executeQuery($sql, [$usuario_id]);
    $stats['logins_30_dias'] = $stmt->fetch()['total'];
    
    // Ações totais
    $sql = "SELECT COUNT(*) as total FROM logs_sistema WHERE usuario_id = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $stats['total_acoes'] = $stmt->fetch()['total'];
    
    // Eventos criados
    $sql = "SELECT COUNT(*) as total FROM agenda WHERE criado_por = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $stats['eventos_criados'] = $stmt->fetch()['total'];
    
    // Clientes criados
    $sql = "SELECT COUNT(*) as total FROM clientes WHERE criado_por = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $stats['clientes_criados'] = $stmt->fetch()['total'];
    
    // Últimas ações
    $sql = "SELECT * FROM logs_sistema 
            WHERE usuario_id = ? 
            ORDER BY data_acao DESC 
            LIMIT 10";
    $stmt = executeQuery($sql, [$usuario_id]);
    $ultimas_acoes = $stmt->fetchAll();
    
} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}

// Conteúdo da página
ob_start();
?>
<style>
    /* Usar os mesmos estilos da visualização de clientes, adaptados para usuários */
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
        display: flex;
        align-items: center;
        gap: 10px;
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
    }
    
    .btn-voltar {
        background: #6c757d;
        color: white;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .btn-editar {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }
    
    .btn-editar:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
    }
    
    .badge-administrador { background: #dc3545; color: white; }
    .badge-usuario { background: #007bff; color: white; }
    .badge-ativo { background: #28a745; color: white; }
    .badge-inativo { background: #6c757d; color: white; }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        color: #155724;
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
    
    .stat-card.primary h3 { color: #007bff; }
    .stat-card.success h3 { color: #28a745; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.info h3 { color: #17a2b8; }
    
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .info-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 20px;
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
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .info-label {
        font-size: 12px;
        color: #666;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .info-value {
        font-size: 14px;
        color: #1a1a1a;
        font-weight: 500;
    }
    
    .info-value.empty {
        color: #999;
        font-style: italic;
    }
    
    .acoes-list {
        display: grid;
        gap: 10px;
    }
    
    .acao-item {
        padding: 15px;
        background: rgba(0,0,0,0.03);
        border-radius: 8px;
        border-left: 3px solid #28a745;
    }
    
    .acao-titulo {
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 5px;
    }
    
    .acao-meta {
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
        .page-header {
            flex-direction: column;
            text-align: center;
        }
        
        .content-grid {
            grid-template-columns: 1fr;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .header-actions {
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <h2>
        👤 <?= htmlspecialchars($usuario['nome']) ?>
        <span class="badge badge-<?= strtolower($usuario['nivel_acesso']) ?>"><?= $usuario['nivel_acesso'] ?></span>
        <span class="badge badge-<?= $usuario['ativo'] ? 'ativo' : 'inativo' ?>"><?= $usuario['ativo'] ? 'Ativo' : 'Inativo' ?></span>
    </h2>
    <div class="header-actions">
        <a href="usuarios.php" class="btn btn-voltar">← Voltar</a>
        <a href="editar_usuario.php?id=<?= $usuario['id'] ?>" class="btn btn-editar">✏️ Editar</a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success">
    ✅ Usuário salvo com sucesso!
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card primary">
        <h3><?= $stats['logins_30_dias'] ?></h3>
        <p>Logins (30 dias)</p>
    </div>
    <div class="stat-card success">
        <h3><?= $stats['total_acoes'] ?></h3>
        <p>Total de Ações</p>
    </div>
    <div class="stat-card warning">
        <h3><?= $stats['eventos_criados'] ?></h3>
        <p>Eventos Criados</p>
    </div>
    <div class="stat-card info">
        <h3><?= $stats['clientes_criados'] ?></h3>
        <p>Clientes Criados</p>
    </div>
</div>

<div class="content-grid">
    <div class="main-content">
        <!-- Dados Básicos -->
        <div class="info-section">
            <h3 class="section-title">📋 Dados Básicos</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Nome Completo</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['nome']) ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">E-mail</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['email']) ?></div>
                </div>
                
                <div class="info-item">
					<div class="info-label">Núcleos</div>
					<div class="info-value">
						<?php if (!empty($nucleos_usuario_nomes)): ?>
							<?= htmlspecialchars(implode(', ', $nucleos_usuario_nomes)) ?>
						<?php else: ?>
							<span class="empty">Nenhum núcleo específico</span>
						<?php endif; ?>
					</div>
				</div>
				
				<div class="info-item">
                    <div class="info-label">Nível de Acesso</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['nivel_acesso']) ?></div>
                </div>
				              
                <?php if (!empty($usuario['telefone'])): ?>
                <div class="info-item">
                    <div class="info-label">Telefone</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['telefone']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value"><?= $usuario['ativo'] ? 'Ativo' : 'Inativo' ?></div>
                </div>
            </div>
        </div>

        <!-- Informações de Acesso -->
        <div class="info-section">
            <h3 class="section-title">🔐 Informações de Acesso</h3>
            <div class="info-grid">
                <?php if (!empty($usuario['ultimo_login'])): ?>
                <div class="info-item">
                    <div class="info-label">Último Login</div>
                    <div class="info-value"><?= date('d/m/Y H:i:s', strtotime($usuario['ultimo_login'])) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($usuario['ip_ultimo_login'])): ?>
                <div class="info-item">
                    <div class="info-label">Último IP</div>
                    <div class="info-value"><?= htmlspecialchars($usuario['ip_ultimo_login']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <div class="info-label">Data de Criação</div>
                    <div class="info-value"><?= date('d/m/Y H:i:s', strtotime($usuario['data_criacao'])) ?></div>
                </div>
                
                <?php if ($usuario['data_atualizacao'] != $usuario['data_criacao']): ?>
                <div class="info-item">
                    <div class="info-label">Última Atualização</div>
                    <div class="info-value"><?= date('d/m/Y H:i:s', strtotime($usuario['data_atualizacao'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Observações -->
        <?php if (!empty($usuario['observacoes'])): ?>
        <div class="info-section">
            <h3 class="section-title">📝 Observações</h3>
            <div class="info-value"><?= nl2br(htmlspecialchars($usuario['observacoes'])) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-content">
        <!-- Últimas Ações -->
        <div class="info-section">
            <h3 class="section-title">📋 Últimas Ações</h3>
            
            <?php if (empty($ultimas_acoes)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <p>Nenhuma ação registrada</p>
            </div>
            <?php else: ?>
            <div class="acoes-list">
                <?php foreach ($ultimas_acoes as $acao): ?>
                <div class="acao-item">
                    <div class="acao-titulo"><?= htmlspecialchars($acao['acao']) ?></div>
                    <?php if (!empty($acao['detalhes'])): ?>
                    <div class="acao-meta"><?= htmlspecialchars($acao['detalhes']) ?></div>
                    <?php endif; ?>
                    <div class="acao-meta">
                        <?= date('d/m/Y H:i', strtotime($acao['data_acao'])) ?>
                        <?php if (!empty($acao['ip_address'])): ?>
                            • IP: <?= htmlspecialchars($acao['ip_address']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Gerenciar Usuários', $conteudo, 'usuarios');
?>