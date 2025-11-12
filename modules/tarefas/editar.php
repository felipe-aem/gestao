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

// Buscar dados da tarefa
try {
    $sql = "SELECT * FROM tarefas WHERE id = ?";
    $stmt = executeQuery($sql, [$tarefa_id]);
    $tarefa = $stmt->fetch();
    
    if (!$tarefa) {
        header('Location: ../agenda/?erro=Tarefa não encontrada');
        exit;
    }
    
    // Verificar se está concluída
    if ($tarefa['status'] === 'concluida') {
        header('Location: visualizar.php?id=' . $tarefa_id . '&erro=Tarefa concluída não pode ser editada');
        exit;
    }
    
} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}

// Buscar usuários para responsável
try {
    $sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// Buscar processos para vinculação
try {
    $sql = "SELECT id, numero_processo, cliente_nome FROM processos WHERE ativo = 1 ORDER BY data_criacao DESC LIMIT 100";
    $stmt = executeQuery($sql);
    $processos = $stmt->fetchAll();
} catch (Exception $e) {
    $processos = [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validações básicas
        $titulo = trim($_POST['titulo']);
        
        if (empty($titulo)) {
            throw new Exception('Título é obrigatório');
        }
        
        // Data de vencimento opcional
        $data_vencimento = !empty($_POST['data_vencimento']) ? $_POST['data_vencimento'] : null;
        
        // Atualizar tarefa
        $sql = "UPDATE tarefas SET 
                titulo = ?,
                descricao = ?,
                data_vencimento = ?,
                status = ?,
                prioridade = ?,
                responsavel_id = ?,
                processo_id = ?,
                data_atualizacao = NOW()
                WHERE id = ?";

        $params = [
            $titulo,
            $_POST['descricao'] ?: null,
            $data_vencimento,
            $_POST['status'],
            $_POST['prioridade'],
            $_POST['responsavel_id'],
            $_POST['processo_id'] ?: null,
            $tarefa_id
        ];

        executeQuery($sql, $params);
        
        header('Location: visualizar.php?id=' . $tarefa_id . '&success=atualizada');
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Usar dados do POST se houver erro, senão usar dados do banco
$dados = $_POST ?: $tarefa;

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
        margin-bottom: 8px;
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
        font-family: inherit;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .form-group small {
        margin-top: 5px;
        color: #666;
        font-size: 12px;
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
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .tipo-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        background: rgba(0,0,0,0.02);
        border-radius: 8px;
        border: 2px solid transparent;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .tipo-checkbox:hover {
        background: rgba(102, 126, 234, 0.05);
        border-color: #667eea;
    }
    
    .tipo-checkbox input[type="radio"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    
    .tipo-checkbox.selected {
        background: rgba(102, 126, 234, 0.1);
        border-color: #667eea;
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
        
        .form-actions {
            flex-direction: column;
        }
    }
</style>

<div class="page-header">
    <h2>✏️ Editar Tarefa</h2>
    <a href="visualizar.php?id=<?= $tarefa_id ?>" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['erro'])): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($_GET['erro']) ?>
</div>
<?php endif; ?>

<form method="POST" class="form-container">
    <!-- Informações Básicas -->
    <div class="form-section">
        <h3>📋 Informações da Tarefa</h3>
        
        <div class="form-group full-width">
            <label for="titulo" class="required">Título da Tarefa</label>
            <input type="text" id="titulo" name="titulo" required 
                   value="<?= htmlspecialchars($dados['titulo']) ?>"
                   placeholder="Ex: Elaborar petição inicial, Revisar documentos...">
        </div>
        
        <div class="form-group full-width">
            <label for="descricao">Descrição</label>
            <textarea id="descricao" name="descricao" 
                      placeholder="Descreva os detalhes da tarefa..."><?= htmlspecialchars($dados['descricao'] ?? '') ?></textarea>
        </div>
    </div>

    <!-- Data, Status e Prioridade -->
    <div class="form-section">
        <h3>📅 Prazo, Status e Prioridade</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="data_vencimento">Data de Vencimento</label>
                <input type="datetime-local" id="data_vencimento" name="data_vencimento" 
                       value="<?= $dados['data_vencimento'] ? date('Y-m-d\TH:i', strtotime($dados['data_vencimento'])) : '' ?>">
                <small>⚠️ Deixe em branco se não houver prazo específico</small>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="pendente" <?= $dados['status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                    <option value="em_andamento" <?= $dados['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                    <option value="concluida" <?= $dados['status'] === 'concluida' ? 'selected' : '' ?>>Concluída</option>
                    <option value="cancelada" <?= $dados['status'] === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="prioridade">Prioridade</label>
                <select id="prioridade" name="prioridade">
                    <option value="baixa" <?= $dados['prioridade'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="normal" <?= $dados['prioridade'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="alta" <?= $dados['prioridade'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgente" <?= $dados['prioridade'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Responsável -->
    <div class="form-section">
        <h3>👤 Responsável</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="responsavel_id">Atribuir para</label>
                <select id="responsavel_id" name="responsavel_id">
                    <?php foreach ($usuarios as $usuario): ?>
                    <option value="<?= $usuario['id'] ?>" 
                            <?= $dados['responsavel_id'] == $usuario['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($usuario['nome']) ?>
                        <?= $usuario['id'] == $usuario_logado['usuario_id'] ? ' (Você)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Tipo de Tarefa -->
    <div class="form-section">
        <h3>🔗 Vinculação</h3>
        
        <div style="margin-bottom: 20px;">
            <div class="tipo-checkbox <?= empty($dados['processo_id']) ? 'selected' : '' ?>" onclick="toggleTipoTarefa('avulsa')">
                <input type="radio" name="tipo_tarefa" value="avulsa" id="tipo_avulsa" 
                       <?= empty($dados['processo_id']) ? 'checked' : '' ?>>
                <label for="tipo_avulsa" style="cursor: pointer; margin: 0;">
                    <strong>📌 Tarefa Avulsa</strong><br>
                    <small style="color: #666;">Não vinculada a nenhum processo específico</small>
                </label>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <div class="tipo-checkbox <?= !empty($dados['processo_id']) ? 'selected' : '' ?>" onclick="toggleTipoTarefa('processo')">
                <input type="radio" name="tipo_tarefa" value="processo" id="tipo_processo"
                       <?= !empty($dados['processo_id']) ? 'checked' : '' ?>>
                <label for="tipo_processo" style="cursor: pointer; margin: 0;">
                    <strong>⚖️ Vinculada a Processo</strong><br>
                    <small style="color: #666;">Relacionada a um processo judicial</small>
                </label>
            </div>
        </div>
        
        <div id="processo-fields" style="<?= empty($dados['processo_id']) ? 'display: none;' : '' ?>">
            <div class="form-group">
                <label for="processo_id">Selecionar Processo</label>
                <select id="processo_id" name="processo_id">
                    <option value="">Selecione um processo</option>
                    <?php foreach ($processos as $processo): ?>
                    <option value="<?= $processo['id'] ?>" 
                            <?= $dados['processo_id'] == $processo['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($processo['numero_processo']) ?> - <?= htmlspecialchars($processo['cliente_nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="visualizar.php?id=<?= $tarefa_id ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">💾 Salvar Alterações</button>
    </div>
</form>

<script>
    function toggleTipoTarefa(tipo) {
        const radioAvulsa = document.getElementById('tipo_avulsa');
        const radioProcesso = document.getElementById('tipo_processo');
        const processoFields = document.getElementById('processo-fields');
        const processoSelect = document.getElementById('processo_id');
        
        if (tipo === 'avulsa') {
            radioAvulsa.checked = true;
            radioProcesso.checked = false;
            processoFields.style.display = 'none';
            processoSelect.value = '';
        } else {
            radioAvulsa.checked = false;
            radioProcesso.checked = true;
            processoFields.style.display = 'block';
        }
        
        // Atualizar visual dos checkboxes
        document.querySelectorAll('.tipo-checkbox').forEach(el => el.classList.remove('selected'));
        if (tipo === 'avulsa') {
            document.querySelector('.tipo-checkbox:has(#tipo_avulsa)').classList.add('selected');
        } else {
            document.querySelector('.tipo-checkbox:has(#tipo_processo)').classList.add('selected');
        }
    }
    
    // Limpar processo_id quando mudar para avulsa
    document.getElementById('tipo_avulsa').addEventListener('change', function() {
        if (this.checked) {
            document.getElementById('processo_id').value = '';
        }
    });
    
    // Alertar se mudar status para concluída
    document.getElementById('status').addEventListener('change', function() {
        if (this.value === 'concluida') {
            if (!confirm('Tem certeza que deseja marcar esta tarefa como concluída?')) {
                this.value = '<?= $dados['status'] ?>';
            }
        }
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Tarefa', $conteudo, 'tarefas');
?>