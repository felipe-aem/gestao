<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$cliente_id = $_GET['cliente_id'] ?? 0;

if (!$cliente_id) {
    header('Location: index.php');
    exit;
}

// Buscar cliente
try {
    $sql = "SELECT nome FROM clientes WHERE id = ?";
    $stmt = executeQuery($sql, [$cliente_id]);
    $cliente = $stmt->fetch();
    
    if (!$cliente) {
        header('Location: index.php?erro=Cliente não encontrado');
        exit;
    }
} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}

// Processar adição de contato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'adicionar') {
    try {
        $nome = trim($_POST['nome']);
        if (empty($nome)) {
            throw new Exception('Nome do contato é obrigatório');
        }
        
        $sql = "INSERT INTO clientes_contatos (cliente_id, nome, parentesco, telefone, celular, email, observacoes) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        executeQuery($sql, [
            $cliente_id,
            $nome,
            $_POST['parentesco'] ?: null,
            $_POST['telefone'] ?: null,
            $_POST['celular'] ?: null,
            $_POST['email'] ?: null,
            $_POST['observacoes'] ?: null
        ]);
        
        $sucesso = "Contato adicionado com sucesso!";
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar exclusão de contato
if (isset($_GET['excluir'])) {
    try {
        $contato_id = $_GET['excluir'];
        $sql = "DELETE FROM clientes_contatos WHERE id = ? AND cliente_id = ?";
        executeQuery($sql, [$contato_id, $cliente_id]);
        
        header("Location: contatos.php?cliente_id=$cliente_id&success=excluido");
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar contatos
try {
    $sql = "SELECT * FROM clientes_contatos WHERE cliente_id = ? ORDER BY nome";
    $stmt = executeQuery($sql, [$cliente_id]);
    $contatos = $stmt->fetchAll();
} catch (Exception $e) {
    $contatos = [];
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
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .contatos-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        margin-bottom: 5px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group textarea {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .contatos-list {
        display: grid;
        gap: 15px;
    }
    
    .contato-item {
        background: rgba(0,0,0,0.03);
        padding: 20px;
        border-radius: 10px;
        border-left: 4px solid #007bff;
        position: relative;
    }
    
    .contato-nome {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 10px;
    }
    
    .contato-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        font-size: 14px;
        color: #666;
    }
    
    .btn-excluir {
        position: absolute;
        top: 15px;
        right: 15px;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 5px;
        padding: 5px 10px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-excluir:hover {
        background: #c82333;
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
</style>

<div class="page-header">
    <h2>👥 Contatos de <?= htmlspecialchars($cliente['nome']) ?></h2>
    <a href="visualizar.php?id=<?= $cliente_id ?>" class="btn-voltar">← Voltar</a>
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

<?php if (isset($_GET['success']) && $_GET['success'] === 'excluido'): ?>
<div class="alert alert-success">
    ✅ Contato excluído com sucesso!
</div>
<?php endif; ?>

<!-- Formulário para adicionar contato -->
<div class="form-container">
    <h3>➕ Adicionar Novo Contato</h3>
    <form method="POST">
        <input type="hidden" name="acao" value="adicionar">
        
        <div class="form-grid">
            <div class="form-group">
                <label for="nome">Nome *</label>
                <input type="text" id="nome" name="nome" required>
            </div>
            
            <div class="form-group">
                <label for="parentesco">Parentesco/Relação</label>
                <input type="text" id="parentesco" name="parentesco" placeholder="Ex: Cônjuge, Filho(a), Sócio...">
            </div>
            
            <div class="form-group">
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone">
            </div>
            
            <div class="form-group">
                <label for="celular">Celular</label>
                <input type="text" id="celular" name="celular">
            </div>
            
            <div class="form-group">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email">
            </div>
            
            <div class="form-group">
                <label for="observacoes">Observações</label>
                <textarea id="observacoes" name="observacoes" rows="3"></textarea>
            </div>
        </div>
        
        <button type="submit" class="btn-primary">➕ Adicionar Contato</button>
    </form>
</div>

<!-- Lista de contatos -->
<div class="contatos-container">
    <h3>📋 Contatos Cadastrados</h3>
    
    <?php if (empty($contatos)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">👥</div>
        <p>Nenhum contato adicional cadastrado ainda.</p>
    </div>
    <?php else: ?>
    <div class="contatos-list">
        <?php foreach ($contatos as $contato): ?>
        <div class="contato-item">
            <button class="btn-excluir" onclick="excluirContato(<?= $contato['id'] ?>)">🗑️</button>
            
            <div class="contato-nome"><?= htmlspecialchars($contato['nome']) ?></div>
            
            <div class="contato-info">
                <?php if (!empty($contato['parentesco'])): ?>
                <div><strong>Parentesco:</strong> <?= htmlspecialchars($contato['parentesco']) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($contato['telefone'])): ?>
                <div><strong>Telefone:</strong> <?= htmlspecialchars($contato['telefone']) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($contato['celular'])): ?>
                <div><strong>Celular:</strong> <?= htmlspecialchars($contato['celular']) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($contato['email'])): ?>
                <div><strong>E-mail:</strong> <?= htmlspecialchars($contato['email']) ?></div>
                <?php endif; ?>
                
                <?php if (!empty($contato['observacoes'])): ?>
                <div class="full-width"><strong>Observações:</strong> <?= htmlspecialchars($contato['observacoes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    function excluirContato(id) {
        if (confirm('Tem certeza que deseja excluir este contato?')) {
            window.location.href = `contatos.php?cliente_id=<?= $cliente_id ?>&excluir=${id}`;
        }
    }

    // Máscara para telefones
    function mascaraTelefone(input) {
        input.addEventListener('input', function(e) {
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

    mascaraTelefone(document.getElementById('telefone'));
    mascaraTelefone(document.getElementById('celular'));
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Contatos do Cliente', $conteudo, 'clientes');
?>