<?php
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se o usuário é administrador
require_once '../../includes/admin_constants.php';
$usuario_logado = requireAdminAccess();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Processar formulário de configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $acao = $_POST['acao'] ?? '';
        
        switch ($acao) {
            case 'salvar_configuracoes':
                // Salvar configurações gerais
                $configuracoes = [
                    'nome_sistema' => trim($_POST['nome_sistema']),
                    'email_sistema' => trim($_POST['email_sistema']),
                    'timezone' => $_POST['timezone'],
                    'backup_automatico' => isset($_POST['backup_automatico']) ? 1 : 0,
                    'logs_retention_days' => intval($_POST['logs_retention_days']),
                    'max_login_attempts' => intval($_POST['max_login_attempts']),
                    'session_timeout' => intval($_POST['session_timeout']),
                    'manutencao_modo' => isset($_POST['manutencao_modo']) ? 1 : 0,
                    'manutencao_mensagem' => trim($_POST['manutencao_mensagem'])
                ];
                
                foreach ($configuracoes as $chave => $valor) {
                    $sql = "INSERT INTO configuracoes (chave, valor) VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
                    executeQuery($sql, [$chave, $valor]);
                }
                
                // Registrar no log
                $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address) 
                            VALUES (?, 'Configurações Alteradas', 'Configurações do sistema atualizadas', ?)";
                executeQuery($sql_log, [$usuario_logado['usuario_id'], $_SERVER['REMOTE_ADDR'] ?? 'N/A']);
                
                $sucesso = "Configurações salvas com sucesso!";
                break;
                
            case 'backup_manual':
                // Executar backup manual
                $backup_result = executarBackup();
                if ($backup_result['success']) {
                    $sucesso = "Backup realizado com sucesso! Arquivo: " . $backup_result['filename'];
                } else {
                    throw new Exception($backup_result['error']);
                }
                break;
                
            case 'limpar_logs':
                // Limpar logs antigos
                $dias = intval($_POST['dias_logs']);
                $sql = "DELETE FROM logs_sistema WHERE data_acao < DATE_SUB(NOW(), INTERVAL ? DAY)";
                $stmt = executeQuery($sql, [$dias]);
                $logs_removidos = $stmt->rowCount();
                
                $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address) 
                            VALUES (?, 'Logs Limpos', ?, ?)";
                executeQuery($sql_log, [
                    $usuario_logado['usuario_id'],
                    "Logs anteriores a $dias dias removidos ($logs_removidos registros)",
                    $_SERVER['REMOTE_ADDR'] ?? 'N/A'
                ]);
                
                $sucesso = "$logs_removidos logs antigos foram removidos.";
                break;
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar configurações atuais
try {
    $sql = "SELECT chave, valor FROM configuracoes";
    $stmt = executeQuery($sql);
    $config_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Valores padrão
    $config = array_merge([
        'nome_sistema' => 'Sistema Jurídico',
        'email_sistema' => 'admin@sistema.com',
        'timezone' => 'America/Sao_Paulo',
        'backup_automatico' => 1,
        'logs_retention_days' => 90,
        'max_login_attempts' => 5,
        'session_timeout' => 30,
        'manutencao_modo' => 0,
        'manutencao_mensagem' => 'Sistema em manutenção. Tente novamente em alguns minutos.'
    ], $config_data);
    
} catch (Exception $e) {
    $config = [];
}

// Informações do sistema
try {
    // Espaço em disco
    $disk_total = disk_total_space('.');
    $disk_free = disk_free_space('.');
    $disk_used = $disk_total - $disk_free;
    
    // Informações do banco
    $sql = "SELECT 
        (SELECT COUNT(*) FROM usuarios) as total_usuarios,
        (SELECT COUNT(*) FROM clientes) as total_clientes,
        (SELECT COUNT(*) FROM agenda) as total_eventos,
        (SELECT COUNT(*) FROM logs_sistema) as total_logs";
    $stmt = executeQuery($sql);
    $db_stats = $stmt->fetch();
    
    // Últimos backups
    $backups_dir = '../../backups/';
    $backups = [];
    if (is_dir($backups_dir)) {
        $files = glob($backups_dir . '*.sql');
        foreach ($files as $file) {
            $backups[] = [
                'nome' => basename($file),
                'tamanho' => filesize($file),
                'data' => filemtime($file)
            ];
        }
        usort($backups, function($a, $b) {
            return $b['data'] - $a['data'];
        });
        $backups = array_slice($backups, 0, 5);
    }
    
} catch (Exception $e) {
    $disk_total = $disk_free = $disk_used = 0;
    $db_stats = ['total_usuarios' => 0, 'total_clientes' => 0, 'total_eventos' => 0, 'total_logs' => 0];
    $backups = [];
}

// Função para executar backup
function executarBackup() {
    try {
        $backup_dir = '../../backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backup_dir . $filename;
        
        // Comando mysqldump (ajustar conforme configuração)
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASS;
        $db = DB_NAME;
        
        $command = "mysqldump --host=$host --user=$user --password=$pass $db > $filepath 2>&1";
        
        exec($command, $output, $return_code);
        
        if ($return_code === 0 && file_exists($filepath)) {
            return ['success' => true, 'filename' => $filename];
        } else {
            return ['success' => false, 'error' => 'Erro ao executar backup: ' . implode("\n", $output)];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
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
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .btn-voltar {
        padding: 10px 20px;
        background: #6c757d;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .config-tabs {
        display: flex;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        overflow: hidden;
    }
    
    .tab-button {
        flex: 1;
        padding: 15px 20px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s;
        color: #666;
    }
    
    .tab-button.active {
        background: #007bff;
        color: white;
    }
    
    .tab-button:hover {
        background: rgba(0, 123, 255, 0.1);
    }
    
    .tab-button.active:hover {
        background: #0056b3;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .config-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        margin-bottom: 30px;
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
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-group label {
        margin-bottom: 5px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .checkbox-group input[type="checkbox"] {
        width: auto;
        margin: 0;
    }
    
    .checkbox-group label {
        margin: 0;
        cursor: pointer;
    }
    
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .btn-success {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
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
    
    .stat-card h3 {
        color: #1a1a1a;
        font-size: 24px;
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
    .stat-card.danger { border-left-color: #dc3545; }
    
    .stat-card.primary h3 { color: #007bff; }
    .stat-card.success h3 { color: #28a745; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.danger h3 { color: #dc3545; }
    
    .progress-bar {
        background: #e9ecef;
        border-radius: 10px;
        height: 10px;
        overflow: hidden;
        margin: 10px 0;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        transition: width 0.3s ease;
    }
    
    .backup-list {
        display: grid;
        gap: 10px;
    }
    
    .backup-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        background: rgba(0,0,0,0.03);
        border-radius: 8px;
        font-size: 14px;
    }
    
    .backup-info {
        flex: 1;
    }
    
    .backup-nome {
        font-weight: 600;
        color: #1a1a1a;
    }
    
    .backup-meta {
        font-size: 12px;
        color: #666;
    }
    
    .backup-actions {
        display: flex;
        gap: 8px;
    }
    
    .btn-small {
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 4px;
    }
    
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
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }
    
    .alert-warning {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid rgba(255, 193, 7, 0.3);
        color: #856404;
    }
    
    .maintenance-warning {
        background: rgba(255, 193, 7, 0.1);
        border: 2px solid #ffc107;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .maintenance-warning h4 {
        color: #856404;
        margin-bottom: 10px;
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .config-tabs {
            flex-direction: column;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }
        
        .backup-item {
            flex-direction: column;
            gap: 10px;
            text-align: center;
        }
    }
</style>

<div class="page-header">
    <h2>⚙️ Configurações do Sistema</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($sucesso)): ?>
<div class="alert alert-success">
    ✅ <?= htmlspecialchars($sucesso) ?>
</div>
<?php endif; ?>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<?php if ($config['manutencao_modo']): ?>
<div class="maintenance-warning">
    <h4>⚠️ Modo de Manutenção Ativo</h4>
    <p>O sistema está em modo de manutenção. Apenas administradores podem acessar.</p>
</div>
<?php endif; ?>

<div class="config-tabs">
    <button class="tab-button active" onclick="showTab('geral')">⚙️ Geral</button>
    <button class="tab-button" onclick="showTab('seguranca')">🔒 Segurança</button>
    <button class="tab-button" onclick="showTab('backup')">💾 Backup</button>
    <button class="tab-button" onclick="showTab('sistema')">🖥️ Sistema</button>
</div>

<!-- Tab Geral -->
<div id="tab-geral" class="tab-content active">
    <form method="POST" class="config-section">
        <input type="hidden" name="acao" value="salvar_configuracoes">
        
        <h3 class="section-title">⚙️ Configurações Gerais</h3>
        
        <div class="form-grid">
            <div class="form-group">
                <label for="nome_sistema">Nome do Sistema</label>
                <input type="text" id="nome_sistema" name="nome_sistema" 
                       value="<?= htmlspecialchars($config['nome_sistema']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email_sistema">E-mail do Sistema</label>
                <input type="email" id="email_sistema" name="email_sistema" 
                       value="<?= htmlspecialchars($config['email_sistema']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="timezone">Fuso Horário</label>
                <select id="timezone" name="timezone">
                    <option value="America/Sao_Paulo" <?= $config['timezone'] === 'America/Sao_Paulo' ? 'selected' : '' ?>>São Paulo (UTC-3)</option>
                    <option value="America/Rio_Branco" <?= $config['timezone'] === 'America/Rio_Branco' ? 'selected' : '' ?>>Rio Branco (UTC-5)</option>
                    <option value="America/Manaus" <?= $config['timezone'] === 'America/Manaus' ? 'selected' : '' ?>>Manaus (UTC-4)</option>
                    <option value="America/Recife" <?= $config['timezone'] === 'America/Recife' ? 'selected' : '' ?>>Recife (UTC-3)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="logs_retention_days">Retenção de Logs (dias)</label>
                <input type="number" id="logs_retention_days" name="logs_retention_days" 
                       value="<?= $config['logs_retention_days'] ?>" min="1" max="365">
            </div>
        </div>
        
        <div class="checkbox-group">
            <input type="checkbox" id="backup_automatico" name="backup_automatico" 
                   <?= $config['backup_automatico'] ? 'checked' : '' ?>>
            <label for="backup_automatico">Backup Automático Diário</label>
        </div>
        
        <h3 class="section-title">🔧 Modo de Manutenção</h3>
        
        <div class="checkbox-group">
            <input type="checkbox" id="manutencao_modo" name="manutencao_modo" 
                   <?= $config['manutencao_modo'] ? 'checked' : '' ?>>
            <label for="manutencao_modo">Ativar Modo de Manutenção</label>
        </div>
        
        <div class="form-group full-width">
            <label for="manutencao_mensagem">Mensagem de Manutenção</label>
            <textarea id="manutencao_mensagem" name="manutencao_mensagem"><?= htmlspecialchars($config['manutencao_mensagem']) ?></textarea>
        </div>
        
        <button type="submit" class="btn btn-primary">💾 Salvar Configurações</button>
    </form>
</div>

<!-- Tab Segurança -->
<div id="tab-seguranca" class="tab-content">
    <form method="POST" class="config-section">
        <input type="hidden" name="acao" value="salvar_configuracoes">
        
        <h3 class="section-title">🔒 Configurações de Segurança</h3>
        
        <div class="form-grid">
            <div class="form-group">
                <label for="max_login_attempts">Máximo de Tentativas de Login</label>
                <input type="number" id="max_login_attempts" name="max_login_attempts" 
                       value="<?= $config['max_login_attempts'] ?>" min="3" max="10">
            </div>
            
            <div class="form-group">
                <label for="session_timeout">Timeout de Sessão (minutos)</label>
                <input type="number" id="session_timeout" name="session_timeout" 
                       value="<?= $config['session_timeout'] ?>" min="5" max="480">
            </div>
        </div>
        
        <div class="alert alert-warning">
            ⚠️ <strong>Atenção:</strong> Alterar essas configurações pode afetar a segurança do sistema.
        </div>
        
        <button type="submit" class="btn btn-primary">🔒 Salvar Configurações de Segurança</button>
    </form>
    
    <!-- Limpeza de Logs -->
    <form method="POST" class="config-section">
        <input type="hidden" name="acao" value="limpar_logs">
        
        <h3 class="section-title">🧹 Limpeza de Logs</h3>
        
        <div class="form-grid">
            <div class="form-group">
                <label for="dias_logs">Remover logs anteriores a (dias)</label>
                <input type="number" id="dias_logs" name="dias_logs" value="90" min="1" max="365">
            </div>
        </div>
        
        <div class="alert alert-warning">
            ⚠️ <strong>Atenção:</strong> Esta ação não pode ser desfeita. Os logs removidos serão perdidos permanentemente.
        </div>
        
        <button type="submit" class="btn btn-danger" 
                onclick="return confirm('Tem certeza que deseja remover os logs antigos? Esta ação não pode ser desfeita.')">
            🗑️ Limpar Logs Antigos
        </button>
    </form>
</div>

<!-- Tab Backup -->
<div id="tab-backup" class="tab-content">
    <div class="config-section">
        <h3 class="section-title">💾 Backup do Sistema</h3>
        
        <form method="POST" style="margin-bottom: 30px;">
            <input type="hidden" name="acao" value="backup_manual">
            
            <div class="alert alert-warning">
                ℹ️ <strong>Backup Manual:</strong> Gera um backup completo do banco de dados.
            </div>
            
            <button type="submit" class="btn btn-success">💾 Executar Backup Agora</button>
        </form>
        
        <h4>📋 Últimos Backups</h4>
        
        <?php if (empty($backups)): ?>
        <div class="alert alert-warning">
            📁 Nenhum backup encontrado no diretório.
        </div>
        <?php else: ?>
        <div class="backup-list">
            <?php foreach ($backups as $backup): ?>
            <div class="backup-item">
                <div class="backup-info">
                    <div class="backup-nome"><?= htmlspecialchars($backup['nome']) ?></div>
                    <div class="backup-meta">
                        <?= date('d/m/Y H:i', $backup['data']) ?> • 
                        <?= number_format($backup['tamanho'] / 1024 / 1024, 2) ?> MB
                    </div>
                </div>
                <div class="backup-actions">
                    <a href="download_backup.php?file=<?= urlencode($backup['nome']) ?>" 
                       class="btn btn-primary btn-small">📥 Download</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tab Sistema -->
<div id="tab-sistema" class="tab-content">
    <div class="config-section">
        <h3 class="section-title">🖥️ Informações do Sistema</h3>
        
        <div class="stats-grid">
            <div class="stat-card primary">
                <h3><?= number_format($db_stats['total_usuarios']) ?></h3>
                <p>Usuários</p>
            </div>
            <div class="stat-card success">
                <h3><?= number_format($db_stats['total_clientes']) ?></h3>
                <p>Clientes</p>
            </div>
            <div class="stat-card warning">
                <h3><?= number_format($db_stats['total_eventos']) ?></h3>
                <p>Eventos</p>
            </div>
            <div class="stat-card danger">
                <h3><?= number_format($db_stats['total_logs']) ?></h3>
                <p>Logs</p>
            </div>
        </div>
        
        <h4>💾 Uso de Espaço em Disco</h4>
        <?php 
        $disk_percent = $disk_total > 0 ? ($disk_used / $disk_total) * 100 : 0;
        ?>
        <p>
            <?= number_format($disk_used / 1024 / 1024 / 1024, 2) ?> GB de 
            <?= number_format($disk_total / 1024 / 1024 / 1024, 2) ?> GB utilizados 
            (<?= number_format($disk_percent, 1) ?>%)
        </p>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $disk_percent ?>%"></div>
        </div>
        
        <h4>🔧 Informações Técnicas</h4>
        <div class="form-grid">
            <div class="form-group">
                <label>Versão do PHP</label>
                <input type="text" value="<?= PHP_VERSION ?>" readonly>
            </div>
            
            <div class="form-group">
                <label>Servidor Web</label>
                <input type="text" value="<?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?>" readonly>
            </div>
            
            <div class="form-group">
                <label>Sistema Operacional</label>
                <input type="text" value="<?= PHP_OS ?>" readonly>
            </div>
            
            <div class="form-group">
                <label>Limite de Memória</label>
                <input type="text" value="<?= ini_get('memory_limit') ?>" readonly>
            </div>
            
            <div class="form-group">
                <label>Tempo Máximo de Execução</label>
                <input type="text" value="<?= ini_get('max_execution_time') ?>s" readonly>
            </div>
            
            <div class="form-group">
                <label>Upload Máximo</label>
                <input type="text" value="<?= ini_get('upload_max_filesize') ?>" readonly>
            </div>
        </div>
    </div>
</div>

<script>
    function showTab(tabName) {
        // Esconder todas as abas
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remover classe active de todos os botões
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Mostrar aba selecionada
        document.getElementById('tab-' + tabName).classList.add('active');
        
        // Ativar botão correspondente
        event.target.classList.add('active');
    }
    
    // Confirmação para modo de manutenção
    document.getElementById('manutencao_modo').addEventListener('change', function() {
        if (this.checked) {
            if (!confirm('Ativar modo de manutenção? Isso impedirá o acesso de usuários normais ao sistema.')) {
                this.checked = false;
            }
        }
    });
    
    // Auto-salvar configurações (opcional)
    let autoSaveTimeout;
    document.querySelectorAll('input, select, textarea').forEach(element => {
        element.addEventListener('change', function() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Mostrar indicador de salvamento automático
                console.log('Auto-salvando configurações...');
            }, 2000);
        });
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Configurações do Sistema', $conteudo, 'admin');
?>