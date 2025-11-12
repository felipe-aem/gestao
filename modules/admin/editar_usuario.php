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

// Verificar se foi fornecido um ID
$usuario_id = $_GET['id'] ?? null;
if (!$usuario_id || !is_numeric($usuario_id)) {
    header('Location: usuarios.php?erro=ID de usuário inválido');
    exit;
}

// Buscar dados do usuário
$sql = "SELECT * FROM usuarios WHERE id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$usuario = $stmt ? $stmt->fetch() : null;

if (!$usuario) {
    header('Location: usuarios.php?erro=Usuário não encontrado');
    exit;
}

// Buscar núcleos disponíveis
$nucleos_disponiveis = [];
try {
    $sql_nucleos = "SELECT id, nome, descricao, ativo FROM nucleos WHERE ativo = 1 ORDER BY nome";
    $stmt_nucleos = executeQuery($sql_nucleos);
    if ($stmt_nucleos) {
        $nucleos_disponiveis = $stmt_nucleos->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Erro ao carregar núcleos: " . $e->getMessage());
}

// Buscar núcleos do usuário
$usuario_nucleos = [];
try {
    $sql_check_table = "SHOW TABLES LIKE 'usuarios_nucleos'";
    $stmt_check_table = executeQuery($sql_check_table);
    
    if ($stmt_check_table && $stmt_check_table->fetch()) {
        $sql_user_nucleos = "SELECT nucleo_id FROM usuarios_nucleos WHERE usuario_id = ?";
        $stmt_user_nucleos = executeQuery($sql_user_nucleos, [$usuario_id]);
        if ($stmt_user_nucleos) {
            $usuario_nucleos = $stmt_user_nucleos->fetchAll(PDO::FETCH_COLUMN);
        }
    }
} catch (Exception $e) {
    error_log("Erro ao carregar núcleos do usuário: " . $e->getMessage());
}

// Incluir núcleo principal se definido
if (!empty($usuario['nucleo_id']) && !in_array($usuario['nucleo_id'], $usuario_nucleos)) {
    $usuario_nucleos[] = $usuario['nucleo_id'];
}

// DEBUG - adicionar temporariamente após a linha dos $nucleos_disponiveis
echo "<!-- DEBUG: Núcleos disponíveis encontrados: " . count($nucleos_disponiveis) . " -->";
if (!empty($nucleos_disponiveis)) {
    echo "<!-- DEBUG: Primeiro núcleo: " . $nucleos_disponiveis[0]['nome'] . " -->";
}
echo "<!-- DEBUG: Núcleos do usuário: " . implode(', ', $usuario_nucleos) . " -->";

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validações básicas
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $nivel_acesso = $_POST['nivel_acesso'] ?? '';
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        // Se nenhum checkbox foi marcado, $_POST['nucleos'] pode não existir.
        // Garante que $nucleos_selecionados seja um array vazio se não houver seleção.
        $nucleos_selecionados = $_POST['nucleos'] ?? []; 
        $visualiza_publicacoes_nao_vinculadas = isset($_POST['visualiza_publicacoes_nao_vinculadas']) ? 1 : 0;
        
        if (empty($nome) || empty($email)) {
            throw new Exception('Nome e email são obrigatórios');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido');
        }
        
        $niveis_validos = ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado', 'Assistente'];
        if (!in_array($nivel_acesso, $niveis_validos)) {
            throw new Exception('Nível de acesso inválido');
        }
        
        // Verificar se email já existe (exceto para o próprio usuário)
        $sql = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
        $stmt = executeQuery($sql, [$email, $usuario_id]);
        if ($stmt && $stmt->fetch()) {
            throw new Exception('E-mail já cadastrado para outro usuário');
        }
        
        // Verificar se pode alterar senha
        $nova_senha = trim($_POST['nova_senha'] ?? '');
        $confirmar_senha = trim($_POST['confirmar_senha'] ?? '');
        
        if (!empty($nova_senha)) {
            if (strlen($nova_senha) < 6) {
                throw new Exception('A nova senha deve ter pelo menos 6 caracteres');
            }
            
            if ($nova_senha !== $confirmar_senha) {
                throw new Exception('As senhas não coincidem');
            }
        }
        
        // Preparar dados para atualização
        $telefone = !empty($_POST['telefone']) ? $_POST['telefone'] : null;
        $cpf = !empty($_POST['cpf']) ? $_POST['cpf'] : null;
        
        // Núcleo principal (o primeiro selecionado na lista do formulário)
        $nucleo_principal = null;
        if (!empty($nucleos_selecionados)) {
            $nucleo_principal = intval($nucleos_selecionados[0]);
        }
        
        // Atualizar usuário na tabela 'usuarios'
        if (!empty($nova_senha)) {
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $sql_update = "UPDATE usuarios SET 
                nome = ?, 
                email = ?, 
                senha = ?, 
                nivel_acesso = ?, 
                ativo = ?, 
                telefone = ?, 
                cpf = ?, 
                nucleo_id = ?,
                visualiza_publicacoes_nao_vinculadas = ?
                WHERE id = ?";
            $params = [$nome, $email, $senha_hash, $nivel_acesso, $ativo, $telefone, $cpf, $nucleo_principal, $visualiza_publicacoes_nao_vinculadas, $usuario_id];
        } else {
            $sql_update = "UPDATE usuarios SET 
                nome = ?, 
                email = ?, 
                nivel_acesso = ?, 
                ativo = ?, 
                telefone = ?, 
                cpf = ?, 
                nucleo_id = ?,
                visualiza_publicacoes_nao_vinculadas = ?
                WHERE id = ?";
            $params = [$nome, $email, $nivel_acesso, $ativo, $telefone, $cpf, $nucleo_principal, $visualiza_publicacoes_nao_vinculadas, $usuario_id];
}
        
        $stmt_update = executeQuery($sql_update, $params);
        
        if (!$stmt_update) {
            throw new Exception('Erro ao atualizar usuário principal');
        }
        
		// --- INÍCIO DA ALTERAÇÃO PARA OS NÚCLEOS MÚLTIPLOS ---
		// Atualizar vínculos na tabela 'usuario_nucleos'
		try {
			$sql_check_table = "SHOW TABLES LIKE 'usuarios_nucleos'";
			$stmt_check_table = executeQuery($sql_check_table);

			if ($stmt_check_table && $stmt_check_table->fetch()) {
				// 1. Remover todos os vínculos existentes
				$sql_delete = "DELETE FROM usuarios_nucleos WHERE usuario_id = ?";
				executeQuery($sql_delete, [$usuario_id]);

				// 2. Inserir apenas os núcleos selecionados
				if (!empty($nucleos_selecionados)) {
					$sql_insert = "INSERT INTO usuarios_nucleos (usuario_id, nucleo_id) VALUES (?, ?)";

					foreach ($nucleos_selecionados as $nucleo_id) {
						$nucleo_id = intval($nucleo_id);
						executeQuery($sql_insert, [$usuario_id, $nucleo_id]);
					}
				}
			}
		} catch (Exception $e) {
			error_log("Erro ao atualizar núcleos: " . $e->getMessage());
		}
		// --- FIM DA ALTERAÇÃO ---
        
        // Registrar no log
        try {
            $sql_check_logs = "SHOW TABLES LIKE 'logs_sistema'";
            $stmt_check_logs = executeQuery($sql_check_logs);
            
            if ($stmt_check_logs && $stmt_check_logs->fetch()) {
                $detalhes = "Usuário editado: $nome ($email) - Nível: $nivel_acesso - Status: " . ($ativo ? 'Ativo' : 'Inativo');
                if (!empty($nova_senha)) {
                    $detalhes .= " - Senha alterada";
                }
                
                $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address, data_log) 
                            VALUES (?, 'Usuário Editado', ?, ?, NOW())";
                executeQuery($sql_log, [
                    $usuario_logado['id'],
                    $detalhes,
                    $_SERVER['REMOTE_ADDR'] ?? 'N/A'
                ]);
            }
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
        
        // Redirecionar com sucesso
        $mensagem_sucesso = urlencode("Usuário '$nome' atualizado com sucesso!");
        header('Location: usuarios.php?success=1&msg=' . $mensagem_sucesso);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
        error_log("Erro ao editar usuário: " . $erro);
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
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        margin-bottom: 30px;
    }
    
    .form-section {
        margin-bottom: 30px;
    }
    
    .form-section h3 {
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
    }
    
    .form-grid.two-cols {
        grid-template-columns: 1fr 1fr;
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
    
    .form-group label.required::after {
        content: ' *';
        color: #dc3545;
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
    
    .nivel-acesso-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .nivel-option {
        position: relative;
    }
    
    .nivel-option input[type="radio"] {
        display: none;
    }
    
    .nivel-option label {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 15px 10px;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s;
        text-align: center;
        background: #f8f9fa;
        margin-bottom: 0;
    }
    
    .nivel-option input[type="radio"]:checked + label {
        border-color: #007bff;
        background: rgba(0, 123, 255, 0.1);
        color: #007bff;
    }
    
    .nivel-option .nivel-icon {
        font-size: 24px;
        margin-bottom: 5px;
    }
    
    .nivel-option .nivel-nome {
        font-weight: 700;
        font-size: 14px;
    }
    
    .nivel-option .nivel-desc {
        font-size: 11px;
        color: #666;
        margin-top: 2px;
    }
    
    .nucleos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }
    
    .nucleo-option {
        display: flex;
        align-items: center;
        padding: 12px;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        background: #f8f9fa;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .nucleo-option:hover {
        background: #e9ecef;
    }
    
    .nucleo-option input[type="checkbox"] {
        margin-right: 10px;
        transform: scale(1.2);
    }
    
    .nucleo-info {
        flex: 1;
    }
    
    .nucleo-nome {
        font-weight: 600;
        color: #333;
    }
    
    .nucleo-desc {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
    }
    
    .checkbox-group input[type="checkbox"] {
        width: auto;
        margin: 0;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
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
    
    .alert-info {
        background: rgba(0, 123, 255, 0.1);
        border: 1px solid rgba(0, 123, 255, 0.3);
        color: #004085;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
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
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-1px);
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .form-grid,
        .form-grid.two-cols {
            grid-template-columns: 1fr;
        }
        
        .nivel-acesso-grid {
            grid-template-columns: 1fr 1fr;
        }
        
        .nucleos-grid {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
    }
</style>

<div class="page-header">
    <h2>✏️ Editar Usuário</h2>
    <a href="usuarios.php" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<div class="alert alert-info">
    📝 <strong>Editando:</strong> <?= htmlspecialchars($usuario['nome']) ?> (<?= htmlspecialchars($usuario['email']) ?>)
</div>

<form method="POST" class="form-container">
    <!-- Dados Básicos -->
    <div class="form-section">
        <h3>📋 Dados Básicos</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="nome" class="required">Nome Completo</label>
                <input type="text" id="nome" name="nome" required value="<?= htmlspecialchars($usuario['nome']) ?>">
            </div>
            
            <div class="form-group">
                <label for="email" class="required">E-mail</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($usuario['email']) ?>">
            </div>
            
            <div class="form-group">
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($usuario['telefone'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="cpf">CPF</label>
                <input type="text" id="cpf" name="cpf" value="<?= htmlspecialchars($usuario['cpf'] ?? '') ?>" placeholder="000.000.000-00">
            </div>
        </div>
        
        <div class="checkbox-group">
            <input type="checkbox" id="ativo" name="ativo" <?= $usuario['ativo'] ? 'checked' : '' ?>>
            <label for="ativo">Usuário ativo</label>
        </div>
    </div>

    <!-- Nível de Acesso -->
    <div class="form-section">
        <h3>🔐 Nível de Acesso</h3>
        <div class="nivel-acesso-grid">
            <div class="nivel-option">
                <input type="radio" id="admin" name="nivel_acesso" value="Admin" 
                       <?= $usuario['nivel_acesso'] === 'Admin' ? 'checked' : '' ?>>
                <label for="admin">
                    <div class="nivel-icon">👑</div>
                    <div class="nivel-nome">Admin</div>
                    <div class="nivel-desc">Acesso total</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="socio" name="nivel_acesso" value="Socio"
                       <?= $usuario['nivel_acesso'] === 'Socio' ? 'checked' : '' ?>>
                <label for="socio">
                    <div class="nivel-icon">🤝</div>
                    <div class="nivel-nome">Sócio</div>
                    <div class="nivel-desc">Gestão completa</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="diretor" name="nivel_acesso" value="Diretor"
                       <?= $usuario['nivel_acesso'] === 'Diretor' ? 'checked' : '' ?>>
                <label for="diretor">
                    <div class="nivel-icon">👔</div>
                    <div class="nivel-nome">Diretor</div>
                    <div class="nivel-desc">Alta gestão</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="gestor" name="nivel_acesso" value="Gestor"
                       <?= $usuario['nivel_acesso'] === 'Gestor' ? 'checked' : '' ?>>
                <label for="gestor">
                    <div class="nivel-icon">👨‍</div>
                    <div class="nivel-nome">Gestor</div>
                    <div class="nivel-desc">Gestão operacional</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="advogado" name="nivel_acesso" value="Advogado"
                       <?= $usuario['nivel_acesso'] === 'Advogado' ? 'checked' : '' ?>>
                <label for="advogado">
                    <div class="nivel-icon">⚖️</div>
                    <div class="nivel-nome">Advogado</div>
                    <div class="nivel-desc">Profissional</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="assistente" name="nivel_acesso" value="Assistente"
                       <?= $usuario['nivel_acesso'] === 'Assistente' ? 'checked' : '' ?>>
                <label for="assistente">
                    <div class="nivel-icon">📋</div>
                    <div class="nivel-nome">Assistente</div>
                    <div class="nivel-desc">Suporte</div>
                </label>
            </div>
        </div>
    </div>

    <!-- Permissões de Publicações -->
    <div class="form-section">
        <h3>📰 Permissões de Publicações</h3>
        <div class="alert alert-info">
            <strong>Importante:</strong> Esta configuração controla quais publicações judiciais o usuário poderá visualizar.
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 2px solid #e9ecef;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <input type="checkbox" 
                       id="visualiza_publicacoes_nao_vinculadas" 
                       name="visualiza_publicacoes_nao_vinculadas" 
                       value="1"
                       style="margin-top: 3px; transform: scale(1.3);"
                       <?= $usuario['visualiza_publicacoes_nao_vinculadas'] ? 'checked' : '' ?>>
                <label for="visualiza_publicacoes_nao_vinculadas" style="margin: 0; cursor: pointer;">
                    <div style="font-weight: 700; font-size: 15px; color: #333; margin-bottom: 8px;">
                        📋 Visualizar publicações sem processo vinculado
                    </div>
                    <div style="font-size: 13px; color: #666; line-height: 1.6;">
                        <strong>Se marcado:</strong> O usuário verá <u>todas</u> as publicações que não estão vinculadas a nenhum processo, além das publicações dos processos em que ele é responsável.<br>
                        <strong>Se desmarcado:</strong> O usuário verá <u>apenas</u> as publicações dos processos em que ele é responsável.
                    </div>
                </label>
            </div>
        </div>
    </div>
    
    <!-- Núcleos de Atuação -->
    <?php if (!empty($nucleos_disponiveis)): ?>
    <div class="form-section">
        <h3>🏛️ Núcleos de Atuação</h3>
        <div class="alert alert-info">
            <strong>Importante:</strong> O primeiro núcleo selecionado será definido como núcleo principal.
        </div>
        
        <div class="nucleos-grid">
            <?php foreach ($nucleos_disponiveis as $nucleo): ?>
            <div class="nucleo-option" onclick="toggleNucleo(<?= $nucleo['id'] ?>)">
                <input type="checkbox" id="nucleo_<?= $nucleo['id'] ?>" name="nucleos[]" value="<?= $nucleo['id'] ?>"
                       <?= in_array($nucleo['id'], $usuario_nucleos) ? 'checked' : '' ?>>
                <div class="nucleo-info">
                    <div class="nucleo-nome"><?= htmlspecialchars($nucleo['nome']) ?></div>
                    <?php if ($nucleo['descricao']): ?>
                    <div class="nucleo-desc"><?= htmlspecialchars($nucleo['descricao']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alterar Senha -->
    <div class="form-section">
        <h3>🔒 Alterar Senha</h3>
        <div class="alert alert-warning">
            ⚠️ <strong>Opcional:</strong> Deixe em branco para manter a senha atual.
        </div>
        <div class="form-grid two-cols">
            <div class="form-group">
                <label for="nova_senha">Nova Senha</label>
                <input type="password" id="nova_senha" name="nova_senha" minlength="6">
                <small style="color: #666; font-size: 12px;">Mínimo 6 caracteres</small>
            </div>
            
            <div class="form-group">
                <label for="confirmar_senha">Confirmar Nova Senha</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" minlength="6">
                <div id="password-match" style="font-size: 12px; margin-top: 5px;"></div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
    </div>
</form>

<script>
    // Função para alternar seleção de núcleo
    function toggleNucleo(nucleoId) {
        const checkbox = document.getElementById('nucleo_' + nucleoId);
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
        }
    }

    // Verificar se as senhas coincidem
    function checkPasswordMatch() {
        const senha = document.getElementById('nova_senha').value;
        const confirmarSenha = document.getElementById('confirmar_senha').value;
        const matchDiv = document.getElementById('password-match');
        
        if (confirmarSenha.length === 0) {
            matchDiv.textContent = '';
            return;
        }
        
        if (senha === confirmarSenha) {
            matchDiv.textContent = '✅ Senhas coincidem';
            matchDiv.style.color = '#28a745';
        } else {
            matchDiv.textContent = '❌ Senhas não coincidem';
            matchDiv.style.color = '#dc3545';
        }
    }

    document.getElementById('nova_senha').addEventListener('input', checkPasswordMatch);
    document.getElementById('confirmar_senha').addEventListener('input', checkPasswordMatch);

    // Máscara para telefone
    const telefoneInput = document.getElementById('telefone');
    if (telefoneInput) {
        telefoneInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length <= 10) {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
            }
            
            e.target.value = value;
        });
    }

    // Máscara para CPF
    const cpfInput = document.getElementById('cpf');
    if (cpfInput) {
        cpfInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})/, '$1-$2');
            e.target.value = value;
        });
    }

    // Validação do formulário
    document.querySelector('form').addEventListener('submit', function(e) {
        const nome = document.getElementById('nome').value.trim();
        const email = document.getElementById('email').value.trim();
        const novaSenha = document.getElementById('nova_senha').value;
        const confirmarSenha = document.getElementById('confirmar_senha').value;
        
        if (!nome || !email) {
            alert('Nome e email são obrigatórios.');
            e.preventDefault();
            return false;
        }
        
        if (novaSenha && novaSenha !== confirmarSenha) {
            alert('As senhas não coincidem.');
            e.preventDefault();
            return false;
        }
        
        if (novaSenha && novaSenha.length < 6) {
            alert('A nova senha deve ter pelo menos 6 caracteres.');
            e.preventDefault();
            return false;
        }
        
        // Desabilitar botão para evitar duplo clique
        const submitBtn = document.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Salvando...';
        
        return true;
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Usuário', $conteudo, 'usuarios');
?>