<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$tarefa_id = $_GET['id'] ?? 0;

if (!$tarefa_id) {
    header('Location: ../agenda/');
    exit;
}

try {
    // Buscar dados da tarefa
    $sql = "SELECT t.*, 
            pr.numero_processo, pr.cliente_nome,
            u.nome as responsavel_nome,
            uc.nome as criado_por_nome
            FROM tarefas t
            LEFT JOIN processos pr ON t.processo_id = pr.id
            LEFT JOIN usuarios u ON t.responsavel_id = u.id
            LEFT JOIN usuarios uc ON t.criado_por = uc.id
            WHERE t.id = ?";
    $stmt = executeQuery($sql, [$tarefa_id]);
    $tarefa = $stmt->fetch();
    
    if (!$tarefa) {
        header('Location: ../agenda/?erro=Tarefa não encontrada');
        exit;
    }
    
    // Buscar envolvidos
    $sql_envolvidos = "SELECT te.*, u.nome, u.email
                       FROM tarefa_envolvidos te
                       INNER JOIN usuarios u ON te.usuario_id = u.id
                       WHERE te.tarefa_id = ?
                       ORDER BY u.nome";
    $stmt_env = executeQuery($sql_envolvidos, [$tarefa_id]);
    $envolvidos = $stmt_env->fetchAll();
    
    // Buscar etiquetas
    $sql_etiquetas = "SELECT e.* 
                      FROM tarefa_etiquetas te
                      INNER JOIN etiquetas e ON te.etiqueta_id = e.id
                      WHERE te.tarefa_id = ?";
    $stmt_etiq = executeQuery($sql_etiquetas, [$tarefa_id]);
    $etiquetas = $stmt_etiq->fetchAll();
    
    // Calcular dias
    if ($tarefa['data_vencimento']) {
        $data_venc = new DateTime($tarefa['data_vencimento']);
        $hoje = new DateTime();
        $diff = $hoje->diff($data_venc);
        
        if ($data_venc < $hoje) {
            $dias_texto = "Atrasada há " . $diff->days . " dia(s)";
            $dias_classe = "atrasada";
        } else {
            $dias_texto = "Vence em " . $diff->days . " dia(s)";
            $dias_classe = "normal";
        }
    } else {
        $dias_texto = "Sem prazo definido";
        $dias_classe = "sem-prazo";
    }
    
} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        switch ($acao) {
            case 'concluir':
                $enviar_revisao = $_POST['enviar_revisao'] ?? 'nao';
                $revisor_id = $_POST['revisor_id'] ?? null;
                
                // Marcar tarefa como concluída
                $sql = "UPDATE tarefas 
                        SET status = 'concluida', 
                            data_conclusao = NOW(),
                            concluida_por = ?,
                            enviada_revisao = ?
                        WHERE id = ?";
                executeQuery($sql, [$usuario_id, ($enviar_revisao === 'sim' ? 1 : 0), $tarefa_id]);
                
                // Registrar no histórico do processo
                if ($tarefa['processo_id']) {
                    $sql_hist = "INSERT INTO processo_historico 
                                 (processo_id, tipo, descricao, usuario_id, data_registro)
                                 VALUES (?, 'tarefa_concluida', ?, ?, NOW())";
                    $desc_hist = "Tarefa concluída: " . $tarefa['titulo'];
                    executeQuery($sql_hist, [$tarefa['processo_id'], $desc_hist, $usuario_id]);
                }
                
                // Se enviou para revisão, criar tarefa de revisão
                if ($enviar_revisao === 'sim' && $revisor_id) {
                    $sql_revisao = "INSERT INTO tarefas 
                                    (titulo, descricao, processo_id, responsavel_id, status, 
                                     prioridade, data_vencimento, criado_por, tarefa_origem_id)
                                    VALUES (?, ?, ?, ?, 'pendente', 'alta', ?, ?, ?)";
                    
                    $titulo_revisao = "REVISÃO: " . $tarefa['titulo'];
                    $desc_revisao = "Tarefa enviada para revisão por " . $usuario_logado['nome'] . 
                                    "\n\nTarefa original: " . $tarefa['titulo'] . 
                                    "\n\nDescrição original: " . $tarefa['descricao'];
                    
                    // Data de vencimento: 3 dias úteis
                    $data_venc_revisao = date('Y-m-d', strtotime('+3 days'));
                    
                    executeQuery($sql_revisao, [
                        $titulo_revisao,
                        $desc_revisao,
                        $tarefa['processo_id'],
                        $revisor_id,
                        $data_venc_revisao,
                        $usuario_id,
                        $tarefa_id
                    ]);
                    
                    // Registrar no histórico
                    if ($tarefa['processo_id']) {
                        $sql_hist = "INSERT INTO processo_historico 
                                     (processo_id, tipo, descricao, usuario_id, data_registro)
                                     VALUES (?, 'tarefa_revisao', ?, ?, NOW())";
                        
                        // Buscar nome do revisor
                        $sql_revisor = "SELECT nome FROM usuarios WHERE id = ?";
                        $stmt_revisor = executeQuery($sql_revisor, [$revisor_id]);
                        $revisor = $stmt_revisor->fetch();
                        
                        $desc_hist = "Tarefa enviada para revisão de " . $revisor['nome'] . ": " . $tarefa['titulo'];
                        executeQuery($sql_hist, [$tarefa['processo_id'], $desc_hist, $usuario_id]);
                    }
                    
                    $_SESSION['success_message'] = 'Tarefa concluída e enviada para revisão!';
                } else {
                    $_SESSION['success_message'] = 'Tarefa marcada como concluída!';
                }
                
                header('Location: visualizar.php?id=' . $tarefa_id);
                exit;
                
            case 'reabrir':
                $sql = "UPDATE tarefas SET status = 'em_andamento', data_conclusao = NULL WHERE id = ?";
                executeQuery($sql, [$tarefa_id]);
                $_SESSION['success_message'] = 'Tarefa reaberta!';
                header('Location: visualizar.php?id=' . $tarefa_id);
                exit;
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

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
        display: flex;
        align-items: center;
        gap: 12px;
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
    
    .btn-editar {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }
    
    .btn-editar:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-success:hover {
        background: #218838;
        transform: translateY(-1px);
    }
    
    .btn-warning {
        background: #ffc107;
        color: #000;
    }
    
    .btn-warning:hover {
        background: #e0a800;
        transform: translateY(-1px);
    }
    
    .btn-excluir {
        background: #dc3545;
        color: white;
    }
    
    .btn-excluir:hover {
        background: #c82333;
        transform: translateY(-1px);
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
    
    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }
    
    .alert-banner {
        padding: 20px 25px;
        border-radius: 12px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-weight: 600;
    }

    .alert-banner.atrasada {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
        border: 2px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }

    .alert-banner.urgente {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
        border: 2px solid rgba(255, 193, 7, 0.3);
        color: #856404;
    }

    .alert-icon {
        font-size: 32px;
    }
    
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
        gap: 20px;
    }
    
    .info-item {
        display: grid;
        grid-template-columns: 140px 1fr;
        gap: 10px;
        padding: 10px 0;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .info-item:last-child {
        border-bottom: none;
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
    
    .badge {
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }
    
    .badge-status-pendente {
        background: rgba(255, 193, 7, 0.1);
        color: #856404;
    }

    .badge-status-em_andamento {
        background: rgba(23, 162, 184, 0.1);
        color: #0c5460;
    }

    .badge-status-concluida {
        background: rgba(40, 167, 69, 0.1);
        color: #155724;
    }

    .badge-status-cancelada {
        background: rgba(108, 117, 125, 0.1);
        color: #383d41;
    }
    
    .badge-prioridade-urgente {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    .badge-prioridade-alta {
        background: rgba(255, 193, 7, 0.1);
        color: #ffc107;
    }

    .badge-prioridade-normal {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .badge-prioridade-baixa {
        background: rgba(108, 117, 125, 0.1);
        color: #6c757d;
    }
    
    .etiqueta-tag {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 8px;
        margin-bottom: 8px;
    }
    
    .datetime-display {
        background: rgba(102, 126, 234, 0.1);
        padding: 15px;
        border-radius: 10px;
        border-left: 4px solid #667eea;
        margin-bottom: 20px;
    }
    
    .datetime-item {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    
    .datetime-item:last-child {
        margin-bottom: 0;
    }
    
    .datetime-icon {
        font-size: 18px;
        width: 25px;
        text-align: center;
    }
    
    .datetime-info {
        flex: 1;
    }
    
    .datetime-label {
        font-size: 12px;
        color: #666;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .datetime-value {
        font-size: 16px;
        font-weight: 700;
        color: #1a1a1a;
    }
    
    .checkbox-container {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        background: rgba(40, 167, 69, 0.1);
        border-radius: 10px;
        border-left: 4px solid #28a745;
        cursor: pointer;
        transition: all 0.3s;
    }

    .checkbox-container:hover {
        background: rgba(40, 167, 69, 0.2);
    }

    .checkbox-container input[type="checkbox"] {
        width: 24px;
        height: 24px;
        cursor: pointer;
    }

    .checkbox-label {
        font-size: 16px;
        font-weight: 600;
        color: #155724;
    }
    
    .envolvidos-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 5px;
    }
    
    .envolvido-tag {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid rgba(102, 126, 234, 0.3);
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            text-align: center;
        }
        
        .content-grid {
            grid-template-columns: 1fr;
        }
        
        .info-item {
            grid-template-columns: 1fr;
            gap: 5px;
        }
        
        .header-actions {
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <h2>
        <?php if ($tarefa['status'] === 'concluida'): ?>
            ✅
        <?php else: ?>
            ☐
        <?php endif; ?>
        <?= htmlspecialchars($tarefa['titulo']) ?>
    </h2>
    <div class="header-actions">
        <a href="../agenda/" class="btn btn-voltar">← Voltar</a>
        <?php if ($tarefa['status'] !== 'concluida'): ?>
        <a href="editar.php?id=<?= $tarefa['id'] ?>" class="btn btn-editar">✏️ Editar</a>
        <?php endif; ?>
        <button class="btn btn-excluir" onclick="excluirTarefa(<?= $tarefa['id'] ?>)">🗑️ Excluir</button>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success">
    ✅ <?= htmlspecialchars($_SESSION['success_message']) ?>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($erro)): ?>
<div class="alert alert-error">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<!-- Alerta de Status -->
<?php if ($tarefa['data_vencimento'] && $dias_classe === 'atrasada' && $tarefa['status'] !== 'concluida'): ?>
<div class="alert-banner atrasada">
    <div class="alert-icon">🚨</div>
    <div>
        <strong>Tarefa Atrasada!</strong><br>
        <span style="font-size: 14px; opacity: 0.9;">
            <?= $dias_texto ?> - Vencimento: <?= date('d/m/Y H:i', strtotime($tarefa['data_vencimento'])) ?>
        </span>
    </div>
</div>
<?php endif; ?>

<div class="content-grid">
    <div class="main-content">
        <!-- Checkbox de Conclusão -->
        <?php if ($tarefa['status'] !== 'concluida'): ?>
        <div class="info-section">
            <form method="POST" onsubmit="return confirm('Marcar esta tarefa como concluída?')">
                <input type="hidden" name="acao" value="concluir">
                <label class="checkbox-container">
                    <input type="checkbox" onchange="this.form.submit()">
                    <span class="checkbox-label">✓ Marcar como Concluída</span>
                </label>
            </form>
        </div>
        <?php else: ?>
        <div class="info-section">
            <div style="background: rgba(40, 167, 69, 0.1); padding: 20px; border-radius: 10px; text-align: center; border: 2px solid rgba(40, 167, 69, 0.3);">
                <div style="font-size: 48px; margin-bottom: 10px;">✅</div>
                <div style="font-size: 18px; font-weight: 700; color: #155724; margin-bottom: 5px;">Tarefa Concluída</div>
                <div style="font-size: 14px; color: #155724;">
                    <?php if ($tarefa['data_conclusao']): ?>
                        Finalizada em <?= date('d/m/Y H:i', strtotime($tarefa['data_conclusao'])) ?>
                    <?php endif; ?>
                </div>
                <form method="POST" style="margin-top: 15px;">
                    <input type="hidden" name="acao" value="reabrir">
                    <button type="submit" class="btn btn-warning">🔄 Reabrir Tarefa</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Data de Vencimento -->
        <?php if ($tarefa['data_vencimento']): ?>
        <div class="info-section">
            <div class="datetime-display">
                <div class="datetime-item">
                    <div class="datetime-icon">📅</div>
                    <div class="datetime-info">
                        <div class="datetime-label">Vencimento</div>
                        <div class="datetime-value"><?= date('d/m/Y H:i', strtotime($tarefa['data_vencimento'])) ?></div>
                        <div style="font-size: 13px; color: #666; margin-top: 3px;"><?= $dias_texto ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Informações Básicas -->
        <div class="info-section">
            <h3 class="section-title">📋 Informações da Tarefa</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Título</div>
                    <div class="info-value" style="font-size: 16px; font-weight: 700;">
                        <?= htmlspecialchars($tarefa['titulo']) ?>
                    </div>
                </div>

                <?php if ($tarefa['descricao']): ?>
                <div class="info-item">
                    <div class="info-label">Descrição</div>
                    <div class="info-value" style="line-height: 1.6;">
                        <?= nl2br(htmlspecialchars($tarefa['descricao'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="badge badge-status-<?= $tarefa['status'] ?>">
                            <?= strtoupper(str_replace('_', ' ', $tarefa['status'])) ?>
                        </span>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">Prioridade</div>
                    <div class="info-value">
                        <span class="badge badge-prioridade-<?= $tarefa['prioridade'] ?>">
                            <?= strtoupper($tarefa['prioridade']) ?>
                        </span>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">Responsável</div>
                    <div class="info-value">
                        👤 <?= htmlspecialchars($tarefa['responsavel_nome']) ?>
                    </div>
                </div>
                
                <?php if (!empty($envolvidos)): ?>
                <div class="info-item">
                    <div class="info-label">Envolvidos</div>
                    <div class="info-value">
                        <div class="envolvidos-list">
                            <?php foreach ($envolvidos as $env): ?>
                            <span class="envolvido-tag" title="<?= htmlspecialchars($env['email']) ?>">
                                👥 <?= htmlspecialchars($env['nome']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($etiquetas)): ?>
                <div class="info-item">
                    <div class="info-label">Etiquetas</div>
                    <div class="info-value">
                        <?php foreach ($etiquetas as $etiq): ?>
                        <span class="etiqueta-tag" style="background: <?= htmlspecialchars($etiq['cor']) ?>; color: white;">
                            🏷️ <?= htmlspecialchars($etiq['nome']) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-item">
                    <div class="info-label">Tipo</div>
                    <div class="info-value">
                        <?php if ($tarefa['processo_id']): ?>
                            ⚖️ Vinculada a Processo
                        <?php else: ?>
                            📌 Tarefa Avulsa
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Processo Vinculado -->
        <?php if ($tarefa['processo_id']): ?>
        <div class="info-section">
            <h3 class="section-title">⚖️ Processo Vinculado</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Número</div>
                    <div class="info-value" style="font-weight: 700;">
                        <?= htmlspecialchars($tarefa['numero_processo']) ?>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">Cliente</div>
                    <div class="info-value">👤 <?= htmlspecialchars($tarefa['cliente_nome']) ?></div>
                </div>
            </div>

            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(0,0,0,0.05);">
                <a href="../processos/visualizar.php?id=<?= $tarefa['processo_id'] ?>" class="btn btn-editar">
                    📁 Ver Processo Completo
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-content">
        <!-- Informações do Sistema -->
        <div class="info-section">
            <h3 class="section-title">ℹ️ Informações do Sistema</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Criado por</div>
                    <div class="info-value"><?= htmlspecialchars($tarefa['criado_por_nome'] ?? 'N/A') ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Data de Criação</div>
                    <div class="info-value"><?= date('d/m/Y H:i', strtotime($tarefa['data_criacao'])) ?></div>
                </div>
                
                <?php if ($tarefa['data_conclusao']): ?>
                <div class="info-item">
                    <div class="info-label">Concluída em</div>
                    <div class="info-value">
                        ✅ <?= date('d/m/Y H:i', strtotime($tarefa['data_conclusao'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Conclusão com Revisão -->
<div id="modalConclusao" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 15px; max-width: 500px; width: 90%;">
        <h3 style="margin-bottom: 20px; color: #1a1a1a;">✅ Concluir Tarefa</h3>
        
        <form method="POST" id="formConclusao">
            <input type="hidden" name="acao" value="concluir">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                    Deseja enviar esta tarefa para revisão?
                </label>
                
                <div style="display: flex; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                        <input type="radio" name="enviar_revisao" value="nao" checked onchange="toggleRevisor(false)">
                        ❌ Não, apenas concluir
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                        <input type="radio" name="enviar_revisao" value="sim" onchange="toggleRevisor(true)">
                        ✅ Sim, enviar para revisão
                    </label>
                </div>
            </div>
            
            <div id="selectRevisor" style="display: none; margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                    Selecione o revisor:
                </label>
                <select name="revisor_id" style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px;">
                    <option value="">Selecione...</option>
                    <?php
                    // Buscar usuários para revisão (exceto o atual)
                    $sql_usuarios = "SELECT id, nome FROM usuarios 
                                     WHERE ativo = 1 AND id != ? 
                                     ORDER BY nome";
                    $stmt_usuarios = executeQuery($sql_usuarios, [$usuario_id]);
                    $usuarios = $stmt_usuarios->fetchAll();
                    
                    foreach ($usuarios as $user):
                    ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="fecharModalConclusao()" 
                        style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
                    Cancelar
                </button>
                <button type="submit" 
                        style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
                    ✅ Confirmar Conclusão
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalConclusao() {
    document.getElementById('modalConclusao').style.display = 'flex';
}

function fecharModalConclusao() {
    document.getElementById('modalConclusao').style.display = 'none';
}

function toggleRevisor(mostrar) {
    document.getElementById('selectRevisor').style.display = mostrar ? 'block' : 'none';
    
    // Se não enviar para revisão, limpar seleção
    if (!mostrar) {
        document.querySelector('select[name="revisor_id"]').value = '';
    }
}
    
function excluirTarefa(id) {
    if (confirm('Tem certeza que deseja excluir esta tarefa?\n\nEsta ação não pode ser desfeita.')) {
        window.location.href = `excluir.php?id=${id}`;
    }
}
// Validação antes de enviar
document.getElementById('formConclusao').addEventListener('submit', function(e) {
    const enviarRevisao = document.querySelector('input[name="enviar_revisao"]:checked').value;
    const revisorId = document.querySelector('select[name="revisor_id"]').value;
    
    if (enviarRevisao === 'sim' && !revisorId) {
        e.preventDefault();
        alert('⚠️ Por favor, selecione um revisor!');
        return false;
    }
});

</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Visualizar Tarefa', $conteudo, 'tarefas');
?>