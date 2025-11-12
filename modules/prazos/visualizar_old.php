<?php
// modules/prazos/visualizar.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';
require_once '../../includes/EnvolvidosHelper.php';


$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$prazo_id = $_GET['id'] ?? null;

if (!$prazo_id) {
    header('Location: index.php');
    exit;
}

try {
    // Buscar prazo com todas as informações
    $sql = "SELECT p.*, 
            pr.numero_processo, pr.numero_cnj, pr.cliente_nome, pr.parte_contraria,
            pr.comarca, pr.tipo_processo, pr.situacao_processual,
            u.nome as responsavel_nome, u.email as responsavel_email,
            uc.nome as criado_por_nome,
            CASE 
                WHEN p.data_vencimento < NOW() AND p.status IN ('pendente', 'em_andamento') THEN 'vencido'
                WHEN p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) AND p.status IN ('pendente', 'em_andamento') THEN 'urgente'
                WHEN p.data_vencimento BETWEEN DATE_ADD(NOW(), INTERVAL 48 HOUR) AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND p.status IN ('pendente', 'em_andamento') THEN 'proximo'
                ELSE 'normal'
            END as alerta
            FROM prazos p
            INNER JOIN processos pr ON p.processo_id = pr.id
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            LEFT JOIN usuarios uc ON p.criado_por = uc.id
            WHERE p.id = ?";

    $stmt = executeQuery($sql, [$prazo_id]);
    $prazo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prazo) {
        header('Location: index.php');
        exit;
    }

    // Buscar envolvidos
    $envolvidos = [];
    try {
        $sql_env = "SELECT pe.*, u.nome, u.email
                    FROM prazo_envolvidos pe
                    INNER JOIN usuarios u ON pe.usuario_id = u.id
                    WHERE pe.prazo_id = ?";
        $stmt_env = executeQuery($sql_env, [$prazo_id]);
        $envolvidos = $stmt_env->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tabela pode não existir ainda
        error_log("Erro ao buscar envolvidos: " . $e->getMessage());
    }

    // Buscar etiquetas
    $etiquetas = [];
    try {
        $sql_etiq = "SELECT e.* 
                     FROM prazo_etiquetas pe
                     INNER JOIN etiquetas e ON pe.etiqueta_id = e.id
                     WHERE pe.prazo_id = ?";
        $stmt_etiq = executeQuery($sql_etiq, [$prazo_id]);
        $etiquetas = $stmt_etiq->fetchAll(PDO::FETCH_ASSOC);
   } catch (Exception $e) {
        // Tabela pode não existir ainda
        error_log("Erro ao buscar etiquetas: " . $e->getMessage());
    }
    
    // Buscar envolvidos
    $envolvidos = [];
    try {
        $sql_env = "SELECT pe.*, u.nome, u.email
                    FROM prazo_envolvidos pe
                    INNER JOIN usuarios u ON pe.usuario_id = u.id
                    WHERE pe.prazo_id = ?
                    ORDER BY u.nome";
        $stmt_env = executeQuery($sql_env, [$prazo_id]);
        $envolvidos = $stmt_env->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao buscar envolvidos: " . $e->getMessage());
    }
    
    // Processar ações
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';
        
        try {
            switch ($acao) {
                case 'mudar_status':
                    $novo_status = $_POST['novo_status'] ?? '';
                    $observacao = $_POST['observacao'] ?? '';
                    $enviar_revisao = $_POST['enviar_revisao'] ?? 'nao';
                    $revisor_id = $_POST['revisor_id'] ?? null;
                    
                    // Atualizar status do prazo
                    $sql = "UPDATE prazos 
                            SET status = ?, 
                                data_conclusao = NOW(),
                                concluido_por = ?,
                                enviado_revisao = ?
                            WHERE id = ?";
                    executeQuery($sql, [
                        $novo_status, 
                        $usuario_id,
                        ($enviar_revisao === 'sim' ? 1 : 0),
                        $prazo_id
                    ]);
                    
                    // Registrar no histórico do prazo
                    try {
                        $sql_hist = "INSERT INTO prazo_historico (prazo_id, usuario_id, acao, detalhes, data_acao)
                                    VALUES (?, ?, ?, ?, NOW())";
                        $detalhes = "Status alterado para: {$novo_status}";
                        if ($observacao) {
                            $detalhes .= ". Observação: {$observacao}";
                        }
                        executeQuery($sql_hist, [
                            $prazo_id,
                            $usuario_id,
                            'status_alterado',
                            $detalhes
                        ]);
                    } catch (Exception $e) {
                        error_log("Erro ao salvar histórico do prazo: " . $e->getMessage());
                    }
                    
                    // Registrar no histórico do PROCESSO
                    if ($prazo['processo_id']) {
                        try {
                            $sql_proc_hist = "INSERT INTO processo_historico 
                                             (processo_id, tipo, descricao, usuario_id, data_registro)
                                             VALUES (?, 'prazo_concluido', ?, ?, NOW())";
                            $desc_hist = "Prazo concluído: " . $prazo['tipo_prazo'] . " - " . $prazo['descricao'];
                            executeQuery($sql_proc_hist, [$prazo['processo_id'], $desc_hist, $usuario_id]);
                        } catch (Exception $e) {
                            error_log("Erro ao salvar histórico do processo: " . $e->getMessage());
                        }
                    }
                    
                    // Se enviou para revisão e status é 'concluido', criar prazo de revisão
                    if ($enviar_revisao === 'sim' && $revisor_id && $novo_status === 'concluido') {
                        try {
                            $sql_revisao = "INSERT INTO prazos 
                                            (tipo_prazo, descricao, processo_id, responsavel_id, status, 
                                             data_vencimento, prioridade, criado_por, prazo_origem_id)
                                            VALUES (?, ?, ?, ?, 'pendente', ?, 'alta', ?, ?)";
                            
                            $tipo_revisao = "REVISÃO: " . $prazo['tipo_prazo'];
                            $desc_revisao = "Prazo enviado para revisão por " . $usuario_logado['nome'] . 
                                            "\n\nPrazo original: " . $prazo['tipo_prazo'] . 
                                            "\n\nDescrição original: " . $prazo['descricao'];
                            
                            // Data de vencimento: 2 dias úteis
                            $data_venc_revisao = date('Y-m-d H:i:s', strtotime('+2 days'));
                            
                            executeQuery($sql_revisao, [
                                $tipo_revisao,
                                $desc_revisao,
                                $prazo['processo_id'],
                                $revisor_id,
                                $data_venc_revisao,
                                $usuario_id,
                                $prazo_id
                            ]);
                            
                            // Registrar criação da revisão no histórico do processo
                            if ($prazo['processo_id']) {
                                // Buscar nome do revisor
                                $sql_revisor = "SELECT nome FROM usuarios WHERE id = ?";
                                $stmt_revisor = executeQuery($sql_revisor, [$revisor_id]);
                                $revisor = $stmt_revisor->fetch(PDO::FETCH_ASSOC);
                                
                                $sql_proc_hist = "INSERT INTO processo_historico 
                                                 (processo_id, tipo, descricao, usuario_id, data_registro)
                                                 VALUES (?, 'prazo_revisao', ?, ?, NOW())";
                                $desc_hist = "Prazo enviado para revisão de " . $revisor['nome'] . ": " . $prazo['tipo_prazo'];
                                executeQuery($sql_proc_hist, [$prazo['processo_id'], $desc_hist, $usuario_id]);
                            }
                            
                            $_SESSION['success_message'] = 'Prazo concluído e enviado para revisão de ' . ($revisor['nome'] ?? 'revisor') . '!';
                        } catch (Exception $e) {
                            error_log("Erro ao criar prazo de revisão: " . $e->getMessage());
                            $_SESSION['success_message'] = 'Status atualizado com sucesso!';
                        }
                    } else {
                        $_SESSION['success_message'] = 'Status atualizado com sucesso!';
                    }
                    
                    break;
                    
                case 'adicionar_observacao':
                    $observacao = $_POST['observacao'] ?? '';
                    
                    if ($observacao) {
                        try {
                            $sql = "INSERT INTO prazo_historico (prazo_id, usuario_id, acao, detalhes, data_acao)
                                   VALUES (?, ?, ?, ?, NOW())";
                            executeQuery($sql, [$prazo_id, $usuario_id, 'observacao', $observacao]);
                            
                            $_SESSION['success_message'] = 'Observação adicionada!';
                        } catch (Exception $e) {
                            error_log("Erro ao adicionar observação: " . $e->getMessage());
                            $_SESSION['success_message'] = 'Observação não pode ser salva (tabela não existe ainda).';
                        }
                    }
                    break;
            }
            
            header('Location: visualizar.php?id=' . $prazo_id);
            exit;
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }

    // Buscar histórico
    $historico = [];
    try {
        $sql_hist = "SELECT ph.*, u.nome as usuario_nome
                     FROM prazo_historico ph
                     LEFT JOIN usuarios u ON ph.usuario_id = u.id
                     WHERE ph.prazo_id = ?
                     ORDER BY ph.data_acao DESC";
        $stmt_hist = executeQuery($sql_hist, [$prazo_id]);
        $historico = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tabela pode não existir
        error_log("Erro ao buscar histórico: " . $e->getMessage());
    }

    // Calcular dias
    $data_venc = new DateTime($prazo['data_vencimento']);
    $hoje = new DateTime();
    $diff = $hoje->diff($data_venc);

    if ($data_venc < $hoje) {
        $dias_texto = "Vencido há " . $diff->days . " dia(s)";
        $dias_classe = "vencido";
    } else {
        $dias_texto = "Vence em " . $diff->days . " dia(s)";
        $dias_classe = $prazo['alerta'];
    }

} catch (Exception $e) {
    echo "<h1>Erro ao carregar prazo</h1>";
    echo "<p>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='../../modules/agenda'>Voltar para a lista</a></p>";
    exit;
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

    .btn-back {
        padding: 10px 20px;
        background: #6c757d;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }

    .btn-back:hover {
        background: #5a6268;
        transform: translateY(-2px);
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

    .alert-banner.vencido {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
        border: 2px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }

    .alert-banner.urgente {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
        border: 2px solid rgba(255, 193, 7, 0.3);
        color: #856404;
    }

    .alert-banner.proximo {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
        border: 2px solid rgba(23, 162, 184, 0.3);
        color: #0c5460;
    }

    .alert-icon {
        font-size: 32px;
    }

    .info-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 25px;
    }

    .info-card h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
        padding-bottom: 15px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .info-grid {
        display: grid;
        gap: 15px;
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
        color: #666;
        font-weight: 600;
        font-size: 13px;
    }

    .info-value {
        color: #1a1a1a;
        font-weight: 500;
        font-size: 14px;
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

    .badge-status-cumprido {
        background: rgba(40, 167, 69, 0.1);
        color: #155724;
    }

    .badge-status-cancelado {
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
    
    .etiqueta-tag {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 8px;
        margin-bottom: 8px;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid rgba(0,0,0,0.05);
        flex-wrap: wrap;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 14px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-success {
        background: #28a745;
        color: white;
    }

    .btn-success:hover {
        background: #218838;
        transform: translateY(-2px);
    }

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    .alert {
        padding: 15px 20px;
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

    @media (max-width: 768px) {
        .info-item {
            grid-template-columns: 1fr;
            gap: 5px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <h2>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
        </svg>
        Detalhes do Prazo
    </h2>
    <a href="index.php" class="btn-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
        Voltar
    </a>
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
<?php if ($prazo['alerta'] !== 'normal' && $prazo['status'] !== 'cumprido'): ?>
<div class="alert-banner <?= $dias_classe ?>">
    <div class="alert-icon">
        <?php if ($prazo['alerta'] === 'vencido'): ?>
            🚨
        <?php elseif ($prazo['alerta'] === 'urgente'): ?>
            ⚠️
        <?php else: ?>
            📅
        <?php endif; ?>
    </div>
    <div>
        <strong><?= $dias_texto ?></strong><br>
        <span style="font-size: 14px; opacity: 0.9;">
            Vencimento: <?= date('d/m/Y H:i', strtotime($prazo['data_vencimento'])) ?>
        </span>
    </div>
</div>
<?php endif; ?>

<!-- Modal de Status Atualizado com Revisão -->
<div id="statusModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000;">
    <div class="modal-content" style="background: white; margin: 50px auto; padding: 30px; max-width: 600px; border-radius: 15px; box-shadow: 0 8px 32px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h3 style="margin: 0; color: #1a1a1a; font-size: 22px;">✅ Atualizar Status do Prazo</h3>
            <button onclick="fecharModalStatus()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">×</button>
        </div>
        
        <form method="POST" id="formStatus">
            <input type="hidden" name="acao" value="mudar_status">
            
            <!-- Seleção de Status -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                    Novo Status:
                </label>
                <select name="novo_status" required 
                        style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;"
                        onchange="toggleOpcaoRevisao(this.value)">
                    <option value="">Selecione...</option>
                    <option value="pendente">⏸️ Pendente</option>
                    <option value="em_andamento">🔄 Em Andamento</option>
                    <option value="concluido">✅ Concluído</option>
                    <option value="cancelado">❌ Cancelado</option>
                </select>
            </div>
            
            <!-- Observação -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                    Observação (opcional):
                </label>
                <textarea name="observacao" 
                          rows="3"
                          style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical;"
                          placeholder="Adicione detalhes sobre a mudança de status..."></textarea>
            </div>
            
            <!-- Opção de Revisão (só aparece se status = concluído) -->
            <div id="opcaoRevisao" style="display: none; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid #667eea;">
                <label style="display: block; margin-bottom: 15px; font-weight: 600; color: #333; font-size: 15px;">
                    🔍 Deseja enviar este prazo para revisão?
                </label>
                
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px; background: white; border-radius: 8px; flex: 1;">
                        <input type="radio" name="enviar_revisao" value="nao" checked onchange="toggleSelectRevisor(false)">
                        <span>❌ Não, apenas concluir</span>
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px; background: white; border-radius: 8px; flex: 1;">
                        <input type="radio" name="enviar_revisao" value="sim" onchange="toggleSelectRevisor(true)">
                        <span>✅ Sim, enviar para revisão</span>
                    </label>
                </div>
                
                <div id="selectRevisorContainer" style="display: none;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">
                        Selecione o revisor:
                    </label>
                    <select name="revisor_id" id="selectRevisor"
                            style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                        <option value="">Selecione um usuário...</option>
                        <?php
                        // Buscar usuários para revisão (exceto o atual)
                        $sql_usuarios = "SELECT id, nome FROM usuarios 
                                         WHERE ativo = 1 AND id != ? 
                                         ORDER BY nome";
                        $stmt_usuarios = executeQuery($sql_usuarios, [$usuario_id]);
                        $usuarios_revisao = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($usuarios_revisao as $user):
                        ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <p style="margin-top: 10px; font-size: 13px; color: #666;">
                        💡 Um novo prazo de revisão será criado com vencimento em 2 dias úteis
                    </p>
                </div>
            </div>
            
            <!-- Botões -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="fecharModalStatus()" 
                        style="background: #6c757d; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    Cancelar
                </button>
                <button type="submit" 
                        style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    ✅ Confirmar Alteração
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Informações do Prazo -->
<div class="info-card">
    <h3>📋 Informações do Prazo</h3>
    
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Título:</div>
            <div class="info-value" style="font-size: 16px; font-weight: 700;">
                <?= htmlspecialchars($prazo['titulo']) ?>
            </div>
        </div>

        <?php if ($prazo['descricao']): ?>
        <div class="info-item">
            <div class="info-label">Descrição:</div>
            <div class="info-value" style="line-height: 1.6;">
                <?= nl2br(htmlspecialchars($prazo['descricao'])) ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="info-item">
            <div class="info-label">Data Vencimento:</div>
            <div class="info-value">
                📅 <strong><?= date('d/m/Y H:i', strtotime($prazo['data_vencimento'])) ?></strong>
                <br><small style="color: #999;"><?= $dias_texto ?></small>
            </div>
        </div>

        <?php if ($prazo['dias_prazo']): ?>
        <div class="info-item">
            <div class="info-label">Dias de Prazo:</div>
            <div class="info-value"><?= $prazo['dias_prazo'] ?> dia(s)</div>
        </div>
        <?php endif; ?>

        <div class="info-item">
            <div class="info-label">Status:</div>
            <div class="info-value">
                <span class="badge badge-status-<?= $prazo['status'] ?>">
                    <?= strtoupper(str_replace('_', ' ', $prazo['status'])) ?>
                </span>
            </div>
        </div>

        <div class="info-item">
            <div class="info-label">Prioridade:</div>
            <div class="info-value">
                <span class="badge badge-prioridade-<?= $prazo['prioridade'] ?>">
                    <?= strtoupper($prazo['prioridade']) ?>
                </span>
            </div>
        </div>

        <div class="info-item">
            <div class="info-label">Responsável:</div>
            <div class="info-value">
                👤 <?= htmlspecialchars($prazo['responsavel_nome']) ?>
            </div>
        </div>
        
        <?php if (!empty($envolvidos)): ?>
        <div class="info-item">
            <div class="info-label">Envolvidos:</div>
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
            <div class="info-label">Etiquetas:</div>
            <div class="info-value">
                <?php foreach ($etiquetas as $etiq): ?>
                <span class="etiqueta-tag" style="background: <?= htmlspecialchars($etiq['cor']) ?>; color: white;">
                    🏷️ <?= htmlspecialchars($etiq['nome']) ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Ações -->
    <?php if ($prazo['status'] !== 'cumprido' && $prazo['status'] !== 'cancelado'): ?>
    <div class="action-buttons">
        <button type="button" class="btn btn-success" onclick="document.getElementById('modalCumprir').style.display='flex'">
            ✓ Marcar como Cumprido
        </button>
        <button type="button" class="btn btn-danger" onclick="document.getElementById('modalCancelar').style.display='flex'">
            ✕ Cancelar Prazo
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Informações do Processo -->
<div class="info-card">
    <h3>⚖️ Processo Vinculado</h3>
    
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Número:</div>
            <div class="info-value" style="font-weight: 700;">
                <?= htmlspecialchars($prazo['numero_processo']) ?>
            </div>
        </div>

        <div class="info-item">
            <div class="info-label">Cliente:</div>
            <div class="info-value">👤 <?= htmlspecialchars($prazo['cliente_nome']) ?></div>
        </div>

        <?php if ($prazo['parte_contraria']): ?>
        <div class="info-item">
            <div class="info-label">Parte Contrária:</div>
            <div class="info-value"><?= htmlspecialchars($prazo['parte_contraria']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($prazo['comarca']): ?>
        <div class="info-item">
            <div class="info-label">Comarca:</div>
            <div class="info-value"><?= htmlspecialchars($prazo['comarca']) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="action-buttons">
        <a href="../processos/visualizar.php?id=<?= $prazo['processo_id'] ?>" class="btn btn-secondary">
            📁 Ver Processo Completo
        </a>
    </div>
</div>

<!-- Modal Cumprir -->
<div id="modalCumprir" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 15px; max-width: 500px; width: 90%;" onclick="event.stopPropagation()">
        <h3 style="margin-bottom: 20px;">✓ Marcar como Cumprido</h3>
        <form method="POST">
            <input type="hidden" name="acao" value="mudar_status">
            <input type="hidden" name="novo_status" value="cumprido">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #333; font-weight: 600;">Observação (opcional):</label>
                <textarea name="observacao" placeholder="Adicione uma observação..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; min-height: 100px; font-family: inherit;"></textarea>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-success" style="flex: 1;">
                    ✓ Confirmar
                </button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalCumprir').style.display='none'">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Cancelar -->
<div id="modalCancelar" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 15px; max-width: 500px; width: 90%;" onclick="event.stopPropagation()">
        <h3 style="margin-bottom: 20px;">✕ Cancelar Prazo</h3>
        <form method="POST">
            <input type="hidden" name="acao" value="mudar_status">
            <input type="hidden" name="novo_status" value="cancelado">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: #333; font-weight: 600;">Motivo do cancelamento:</label>
                <textarea name="observacao" placeholder="Informe o motivo..." required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; min-height: 100px; font-family: inherit;"></textarea>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-danger" style="flex: 1;">
                    ✕ Confirmar
                </button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalCancelar').style.display='none'">
                    Voltar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.addEventListener('click', function(e) {
    if (e.target.id === 'modalCumprir') {
        document.getElementById('modalCumprir').style.display = 'none';
    }
    if (e.target.id === 'modalCancelar') {
        document.getElementById('modalCancelar').style.display = 'none';
    }
});

// Mostrar modal de status
function abrirModalStatus() {
    document.getElementById('statusModal').style.display = 'flex';
    document.getElementById('statusModal').style.alignItems = 'center';
    document.getElementById('statusModal').style.justifyContent = 'center';
}

// Fechar modal de status
function fecharModalStatus() {
    document.getElementById('statusModal').style.display = 'none';
    // Resetar form
    document.getElementById('formStatus').reset();
    document.getElementById('opcaoRevisao').style.display = 'none';
    document.getElementById('selectRevisorContainer').style.display = 'none';
}

// Mostrar opção de revisão apenas se status for "concluído"
function toggleOpcaoRevisao(status) {
    const opcaoRevisao = document.getElementById('opcaoRevisao');
    if (status === 'concluido') {
        opcaoRevisao.style.display = 'block';
    } else {
        opcaoRevisao.style.display = 'none';
        // Resetar seleção de revisão
        document.querySelector('input[name="enviar_revisao"][value="nao"]').checked = true;
        toggleSelectRevisor(false);
    }
}

// Mostrar/ocultar select de revisor
function toggleSelectRevisor(mostrar) {
    const container = document.getElementById('selectRevisorContainer');
    const select = document.getElementById('selectRevisor');
    
    if (mostrar) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
        select.value = ''; // Limpar seleção
    }
}

// Validação do formulário
document.getElementById('formStatus').addEventListener('submit', function(e) {
    const status = document.querySelector('select[name="novo_status"]').value;
    const enviarRevisao = document.querySelector('input[name="enviar_revisao"]:checked')?.value;
    const revisorId = document.getElementById('selectRevisor')?.value;
    
    // Se status é concluído E quer enviar para revisão, validar seleção do revisor
    if (status === 'concluido' && enviarRevisao === 'sim') {
        if (!revisorId) {
            e.preventDefault();
            alert('⚠️ Por favor, selecione um revisor para enviar o prazo para revisão!');
            return false;
        }
    }
});

// Fechar modal ao clicar fora
document.getElementById('statusModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        fecharModalStatus();
    }
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Detalhes do Prazo', $conteudo, 'prazos');
?>