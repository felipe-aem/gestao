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

// Buscar núcleos disponíveis
$nucleos_disponiveis = [];
try {
    $sql_nucleos = "SELECT id, nome, descricao, ativo FROM nucleos WHERE ativo = 1 ORDER BY nome";
    $stmt_nucleos = executeQuery($sql_nucleos);
    if ($stmt_nucleos) {
        $nucleos_disponiveis = $stmt_nucleos->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Núcleos não são obrigatórios, continuar sem eles
    error_log("Erro ao carregar núcleos: " . $e->getMessage());
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validações básicas
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        $nivel_acesso = $_POST['nivel_acesso'] ?? '';
        $nucleos_selecionados = $_POST['nucleos'] ?? [];
        $visualiza_publicacoes_nao_vinculadas = isset($_POST['visualiza_publicacoes_nao_vinculadas']) ? 1 : 0;
        
        if (empty($nome) || empty($email) || empty($senha)) {
            throw new Exception('Todos os campos obrigatórios devem ser preenchidos');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido');
        }
        
        if (strlen($senha) < 6) {
            throw new Exception('A senha deve ter pelo menos 6 caracteres');
        }
        
        if ($senha !== $confirmar_senha) {
            throw new Exception('As senhas não coincidem');
        }
        
        $niveis_validos = ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado', 'Assistente'];
        if (!in_array($nivel_acesso, $niveis_validos)) {
            throw new Exception('Nível de acesso inválido');
        }
        
        // Verificar se email já existe
        $sql = "SELECT id FROM usuarios WHERE email = ?";
        $stmt = executeQuery($sql, [$email]);
        if ($stmt && $stmt->fetch()) {
            throw new Exception('E-mail já cadastrado');
        }
        
        // Preparar dados para inserção
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $telefone = !empty($_POST['telefone']) ? $_POST['telefone'] : null;
        $cpf = !empty($_POST['cpf']) ? $_POST['cpf'] : null;
        $criado_por = $usuario_logado['id'] ?? null;
        
        // Inserir usuário (SEM nucleo_id - campo será NULL)
        $sql_insert = "INSERT INTO usuarios (
            nome, 
            email, 
            senha, 
            nivel_acesso, 
            ativo, 
            data_criacao,
            telefone,
            cpf,
            criado_por,
            visualiza_publicacoes_nao_vinculadas
        ) VALUES (?, ?, ?, ?, 1, NOW(), ?, ?, ?, ?)";
        
        $params = [
            $nome,
            $email,
            $senha_hash,
            $nivel_acesso,
            $telefone,
            $cpf,
            $criado_por,
            $visualiza_publicacoes_nao_vinculadas
        ];
        
        $stmt_insert = executeQuery($sql_insert, $params);
        
        if (!$stmt_insert) {
            throw new Exception('Erro ao inserir usuário no banco de dados');
        }
        
        // Obter ID do usuário criado
        $usuario_id_stmt = executeQuery("SELECT LAST_INSERT_ID() as ultimo_id");
        $usuario_id_result = $usuario_id_stmt ? $usuario_id_stmt->fetch() : null;
        $usuario_id = $usuario_id_result ? $usuario_id_result['ultimo_id'] : null;
        
        // Inserir núcleos selecionados na tabela usuarios_nucleos
        if (!empty($nucleos_selecionados) && $usuario_id) {
            try {
                // Verificar se tabela usuarios_nucleos existe
                $sql_check_table = "SHOW TABLES LIKE 'usuarios_nucleos'";
                $stmt_check_table = executeQuery($sql_check_table);
                
                if ($stmt_check_table && $stmt_check_table->fetch()) {
                    // Inserir os núcleos selecionados
                    $sql_insert = "INSERT INTO usuarios_nucleos (usuario_id, nucleo_id) VALUES (?, ?)";
                    
                    $nucleos_inseridos = 0;
                    foreach ($nucleos_selecionados as $nucleo_id) {
                        $nucleo_id = intval($nucleo_id);
                        try {
                            executeQuery($sql_insert, [$usuario_id, $nucleo_id]);
                            $nucleos_inseridos++;
                            error_log("✅ Núcleo $nucleo_id vinculado ao usuário $usuario_id");
                        } catch (Exception $e) {
                            error_log("❌ Erro ao inserir núcleo $nucleo_id para usuário $usuario_id: " . $e->getMessage());
                        }
                    }
                    
                    error_log("📊 Total de núcleos inseridos: $nucleos_inseridos de " . count($nucleos_selecionados));
                    
                    if ($nucleos_inseridos === 0 && !empty($nucleos_selecionados)) {
                        error_log("⚠️ AVISO: Nenhum núcleo foi vinculado ao usuário $usuario_id");
                    }
                } else {
                    error_log("⚠️ AVISO: Tabela 'usuarios_nucleos' não existe");
                }
            } catch (Exception $e) {
                error_log("❌ Erro ao processar núcleos: " . $e->getMessage());
            }
        }
		
        // Registrar no log (se a tabela existir)
        try {
            $sql_check_logs = "SHOW TABLES LIKE 'logs_sistema'";
            $stmt_check_logs = executeQuery($sql_check_logs);
            
            if ($stmt_check_logs && $stmt_check_logs->fetch()) {
                $detalhes = "Novo usuário criado: $nome ($email) - Nível: $nivel_acesso";
                if (!empty($nucleos_selecionados)) {
                    $nucleos_nomes = [];
                    foreach ($nucleos_disponiveis as $nucleo) {
                        if (in_array($nucleo['id'], $nucleos_selecionados)) {
                            $nucleos_nomes[] = $nucleo['nome'];
                        }
                    }
                    if (!empty($nucleos_nomes)) {
                        $detalhes .= " - Núcleos: " . implode(', ', $nucleos_nomes);
                    }
                }
                
                $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address, data_log) 
                            VALUES (?, 'Usuário Criado', ?, ?, NOW())";
                executeQuery($sql_log, [
                    $usuario_logado['id'],
                    $detalhes,
                    $_SERVER['REMOTE_ADDR'] ?? 'N/A'
                ]);
            }
        } catch (Exception $e) {
            // Erro no log não deve impedir a criação
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
        
        // Redirecionar para página de sucesso
        $mensagem_sucesso = urlencode("Usuário '$nome' criado com sucesso!");
        header('Location: usuarios.php?success=1&msg=' . $mensagem_sucesso);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
        error_log("Erro ao criar usuário: " . $erro);
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
    <h2>👤 Novo Usuário</h2>
    <a href="usuarios.php" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<div class="alert alert-warning">
    ⚠️ <strong>Atenção:</strong> Você está criando um novo usuário do sistema. O nome de usuário será gerado automaticamente baseado no e-mail.
</div>

<form method="POST" class="form-container">
    <!-- Dados Básicos -->
    <div class="form-section">
        <h3>📋 Dados Básicos</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="nome" class="required">Nome Completo</label>
                <input type="text" id="nome" name="nome" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="email" class="required">E-mail</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <small style="color: #666; font-size: 12px;">O nome de usuário será gerado automaticamente a partir do e-mail</small>
            </div>
            
            <div class="form-group">
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($_POST['telefone'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="cpf">CPF</label>
                <input type="text" id="cpf" name="cpf" value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>" placeholder="000.000.000-00">
            </div>
        </div>
    </div>

    <!-- Nível de Acesso -->
    <div class="form-section">
        <h3>🔐 Nível de Acesso</h3>
        <div class="nivel-acesso-grid">
            <div class="nivel-option">
                <input type="radio" id="admin" name="nivel_acesso" value="Admin" 
                       <?= ($_POST['nivel_acesso'] ?? '') === 'Admin' ? 'checked' : '' ?>>
                <label for="admin">
                    <div class="nivel-icon">👑</div>
                    <div class="nivel-nome">Admin</div>
                    <div class="nivel-desc">Acesso total</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="socio" name="nivel_acesso" value="Socio"
                       <?= ($_POST['nivel_acesso'] ?? '') === 'Socio' ? 'checked' : '' ?>>
                <label for="socio">
                    <div class="nivel-icon">🤝</div>
                    <div class="nivel-nome">Sócio</div>
                    <div class="nivel-desc">Gestão completa</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="diretor" name="nivel_acesso" value="Diretor"
                       <?= ($_POST['nivel_acesso'] ?? '') === 'Diretor' ? 'checked' : '' ?>>
                <label for="diretor">
                    <div class="nivel-icon">👔</div>
                    <div class="nivel-nome">Diretor</div>
                    <div class="nivel-desc">Alta gestão</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="gestor" name="nivel_acesso" value="Gestor"
                       <?= ($_POST['nivel_acesso'] ?? '') === 'Gestor' ? 'checked' : '' ?>>
                <label for="gestor">
                    <div class="nivel-icon">👨‍💼</div>
                    <div class="nivel-nome">Gestor</div>
                    <div class="nivel-desc">Gestão operacional</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="advogado" name="nivel_acesso" value="Advogado"
                       <?= ($_POST['nivel_acesso'] ?? 'Advogado') === 'Advogado' ? 'checked' : '' ?>>
                <label for="advogado">
                    <div class="nivel-icon">⚖️</div>
                    <div class="nivel-nome">Advogado</div>
                    <div class="nivel-desc">Profissional</div>
                </label>
            </div>
            
            <div class="nivel-option">
                <input type="radio" id="assistente" name="nivel_acesso" value="Assistente"
                       <?= ($_POST['nivel_acesso'] ?? '') === 'Assistente' ? 'checked' : '' ?>>
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
                       <?= (isset($_POST['visualiza_publicacoes_nao_vinculadas']) && $_POST['visualiza_publicacoes_nao_vinculadas']) ? 'checked' : '' ?>>
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
            <strong>Importante:</strong> Selecione os núcleos aos quais o usuário terá acesso. Todos os núcleos selecionados serão vinculados ao usuário.
        </div>
        
        <div class="nucleos-grid">
            <?php foreach ($nucleos_disponiveis as $nucleo): ?>
            <div class="nucleo-option" onclick="toggleNucleo(<?= $nucleo['id'] ?>)">
                <input type="checkbox" id="nucleo_<?= $nucleo['id'] ?>" name="nucleos[]" value="<?= $nucleo['id'] ?>"
                       <?= (in_array($nucleo['id'], $_POST['nucleos'] ?? [])) ? 'checked' : '' ?>>
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

    <!-- Senha -->
    <div class="form-section">
        <h3>🔒 Senha</h3>
        <div class="form-grid two-cols">
            <div class="form-group">
                <label for="senha" class="required">Senha</label>
                <input type="password" id="senha" name="senha" required minlength="6">
                <small style="color: #666; font-size: 12px;">Mínimo 6 caracteres</small>
            </div>
            
            <div class="form-group">
                <label for="confirmar_senha" class="required">Confirmar Senha</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="6">
                <div id="password-match" style="font-size: 12px; margin-top: 5px;"></div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">👤 Criar Usuário</button>
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
        const senha = document.getElementById('senha').value;
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

    document.getElementById('senha').addEventListener('input', checkPasswordMatch);
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
        const senha = document.getElementById('senha').value;
        const confirmarSenha = document.getElementById('confirmar_senha').value;
        const nivelSelecionado = document.querySelector('input[name="nivel_acesso"]:checked');
        
        if (!nome || !email || !senha || !confirmarSenha || !nivelSelecionado) {
            alert('Por favor, preencha todos os campos obrigatórios.');
            e.preventDefault();
            return false;
        }
        
        if (senha !== confirmarSenha) {
            alert('As senhas não coincidem.');
            e.preventDefault();
            return false;
        }
        
        if (senha.length < 6) {
            alert('A senha deve ter pelo menos 6 caracteres.');
            e.preventDefault();
            return false;
        }
        
        // Desabilitar botão para evitar duplo clique
        const submitBtn = document.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Criando usuário...';
        
        return true;
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Usuário', $conteudo, 'admin');
?>