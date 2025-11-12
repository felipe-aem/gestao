<?php
require_once '../../includes/auth.php';
Auth::protect(); // Qualquer usuário logado pode acessar

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

ob_start();
?>

<style>
    .content {
        flex: 1;
        padding: 30px;
        max-width: 600px;
        margin: 0 auto;
    }
    
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
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .page-header p {
        color: #666;
        font-size: 14px;
    }
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
    }
    
    .form-container .user-info {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 25px;
    }
    
    .form-container .user-info p {
        margin: 5px 0;
        color: #333;
    }
    
    .form-container .user-info strong {
        color: #000;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: #1a1a1a;
        box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
    }
    
    .password-requirements {
        background: #e7f3ff;
        border: 1px solid #b3d9ff;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .password-requirements h4 {
        color: #004085;
        margin-bottom: 10px;
        font-size: 14px;
    }
    
    .password-requirements ul {
        list-style: none;
        padding-left: 0;
    }
    
    .password-requirements li {
        color: #004085;
        padding: 5px 0;
        font-size: 13px;
    }
    
    .password-requirements li:before {
        content: "✓ ";
        color: #28a745;
        font-weight: bold;
    }
    
    .btn-container {
        display: flex;
        gap: 15px;
        justify-content: space-between;
        margin-top: 25px;
    }
    
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        flex: 1;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .btn-secondary {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
    }
    
    .btn-secondary:hover {
        transform: translateY(-2px);
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .password-toggle {
        position: relative;
    }
    
    .password-toggle button {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        padding: 5px;
        font-size: 18px;
    }
</style>

<div class="content">
    <div class="page-header">
        <h2>🔒 Alterar Minha Senha</h2>
        <p>Mantenha sua conta segura alterando sua senha regularmente</p>
    </div>
    
    <div class="form-container">
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= $erro ?></div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?= $sucesso ?></div>
        <?php endif; ?>
        
        <div class="user-info">
            <p><strong>Usuário:</strong> <?= htmlspecialchars($usuario_logado['nome']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($usuario_logado['email']) ?></p>
        </div>
        
        <form method="POST" action="process_alterar_minha_senha.php" id="formSenha">
            <div class="form-group">
                <label>Senha Atual *</label>
                <div class="password-toggle">
                    <input type="password" name="senha_atual" id="senha_atual" required>
                    <button type="button" onclick="togglePassword('senha_atual')">👁️</button>
                </div>
            </div>
            
            <div class="password-requirements">
                <h4>📋 Requisitos da Nova Senha:</h4>
                <ul>
                    <li>Mínimo de 6 caracteres</li>
                    <li>Diferente da senha atual</li>
                    <li>Recomendado: use letras, números e caracteres especiais</li>
                </ul>
            </div>
            
            <div class="form-group">
                <label>Nova Senha *</label>
                <div class="password-toggle">
                    <input type="password" name="nova_senha" id="nova_senha" required minlength="6">
                    <button type="button" onclick="togglePassword('nova_senha')">👁️</button>
                </div>
            </div>
            
            <div class="form-group">
                <label>Confirmar Nova Senha *</label>
                <div class="password-toggle">
                    <input type="password" name="confirmar_senha" id="confirmar_senha" required minlength="6">
                    <button type="button" onclick="togglePassword('confirmar_senha')">👁️</button>
                </div>
            </div>
            
            <div class="btn-container">
                <a href="../dashboard/" class="btn btn-secondary">← Voltar</a>
                <button type="submit" class="btn btn-primary">💾 Alterar Senha</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === 'password' ? 'text' : 'password';
}

document.getElementById('formSenha').addEventListener('submit', function(e) {
    const novaSenha = document.getElementById('nova_senha').value;
    const confirmarSenha = document.getElementById('confirmar_senha').value;
    
    if (novaSenha !== confirmarSenha) {
        e.preventDefault();
        alert('❌ As senhas não coincidem!');
        return false;
    }
    
    if (novaSenha.length < 6) {
        e.preventDefault();
        alert('❌ A senha deve ter no mínimo 6 caracteres!');
        return false;
    }
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Alterar Minha Senha', $conteudo, 'Alterar Minha Senha');
?>