<?php
// modules/configuracoes/etiquetas.php
require_once '../../includes/auth.php';
Auth::protect();

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'etiquetas';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'criar') {
        $nome = trim($_POST['nome'] ?? '');
        $cor = $_POST['cor'] ?? '#667eea';
        $tipo = $_POST['tipo'] ?? 'geral';
        $descricao = trim($_POST['descricao'] ?? '');
        
        if ($nome) {
            $sql = "INSERT INTO etiquetas (nome, cor, tipo, descricao, criado_por) VALUES (?, ?, ?, ?, ?)";
            executeQuery($sql, [$nome, $cor, $tipo, $descricao, $usuario_id]);
            header('Location: etiquetas.php?msg=criada');
            exit;
        }
    }
    
    if ($acao === 'editar') {
        $id = $_POST['id'] ?? 0;
        $nome = trim($_POST['nome'] ?? '');
        $cor = $_POST['cor'] ?? '#667eea';
        $tipo = $_POST['tipo'] ?? 'geral';
        $descricao = trim($_POST['descricao'] ?? '');
        
        if ($id && $nome) {
            $sql = "UPDATE etiquetas SET nome = ?, cor = ?, tipo = ?, descricao = ? WHERE id = ?";
            executeQuery($sql, [$nome, $cor, $tipo, $descricao, $id]);
            header('Location: etiquetas.php?msg=editada');
            exit;
        }
    }
    
    if ($acao === 'excluir') {
        $id = $_POST['id'] ?? 0;
        if ($id) {
            $sql = "UPDATE etiquetas SET ativo = 0 WHERE id = ?";
            executeQuery($sql, [$id]);
            header('Location: etiquetas.php?msg=excluida');
            exit;
        }
    }
}

// Buscar etiquetas
$sql = "SELECT e.*, u.nome as criador_nome 
        FROM etiquetas e
        LEFT JOIN usuarios u ON e.criado_por = u.id
        WHERE e.ativo = 1
        ORDER BY e.tipo, e.nome";
$stmt = executeQuery($sql);
$etiquetas = $stmt->fetchAll();

// Agrupar por tipo
$etiquetas_por_tipo = [
    'geral' => [],
    'processo' => [],
    'tarefa' => [],
    'prazo' => [],
    'audiencia' => []
];

foreach ($etiquetas as $etiqueta) {
    $etiquetas_por_tipo[$etiqueta['tipo']][] = $etiqueta;
}

ob_start();
?>

<style>
    .page-header {
        background: white;
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
        margin: 0;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 24px;
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
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }
    
    .etiquetas-section {
        background: white;
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        margin-bottom: 25px;
    }
    
    .etiquetas-section h3 {
        color: #1a1a1a;
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .etiquetas-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 15px;
    }
    
    .etiqueta-card {
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        padding: 15px;
        transition: all 0.3s;
        position: relative;
    }
    
    .etiqueta-card:hover {
        border-color: #667eea;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .etiqueta-preview {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 10px;
    }
    
    .etiqueta-actions {
        display: flex;
        gap: 8px;
        margin-top: 10px;
    }
    
    .btn-small {
        padding: 6px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-edit {
        background: #ffc107;
        color: #000;
    }
    
    .btn-delete {
        background: #dc3545;
        color: white;
    }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.6);
        padding: 50px 20px;
    }
    
    .modal-content {
        background-color: white;
        margin: 0 auto;
        padding: 30px;
        width: 90%;
        max-width: 500px;
        border-radius: 15px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.3);
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
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
    }
    
    .form-group input[type="color"] {
        height: 50px;
        cursor: pointer;
    }
    
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
</style>

<div class="page-header">
    <h2>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
            <line x1="7" y1="7" x2="7.01" y2="7"></line>
        </svg>
        Gerenciar Etiquetas
    </h2>
    <button class="btn-primary" onclick="abrirModalCriar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        Nova Etiqueta
    </button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <?php
    $mensagens = [
        'criada' => '✅ Etiqueta criada com sucesso!',
        'editada' => '✅ Etiqueta atualizada com sucesso!',
        'excluida' => '✅ Etiqueta excluída com sucesso!'
    ];
    $msg = $mensagens[$_GET['msg']] ?? '';
    ?>
    <?php if ($msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php
$tipos_nome = [
    'geral' => '🏷️ Gerais',
    'processo' => '📁 Processos',
    'tarefa' => '✓ Tarefas',
    'prazo' => '⏰ Prazos',
    'audiencia' => '📅 Audiências'
];

foreach ($etiquetas_por_tipo as $tipo => $lista):
    if (empty($lista)) continue;
?>
<div class="etiquetas-section">
    <h3><?= $tipos_nome[$tipo] ?></h3>
    <div class="etiquetas-grid">
        <?php foreach ($lista as $etiqueta): ?>
        <div class="etiqueta-card">
            <div class="etiqueta-preview" style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                🏷️ <?= htmlspecialchars($etiqueta['nome']) ?>
            </div>
            <?php if ($etiqueta['descricao']): ?>
            <p style="font-size: 13px; color: #666; margin: 0 0 10px 0;">
                <?= htmlspecialchars($etiqueta['descricao']) ?>
            </p>
            <?php endif; ?>
            <div style="font-size: 11px; color: #999;">
                Criada por <?= htmlspecialchars($etiqueta['criador_nome']) ?>
            </div>
            <div class="etiqueta-actions">
                <button class="btn-small btn-edit" onclick='editarEtiqueta(<?= json_encode($etiqueta) ?>)'>
                    ✏️ Editar
                </button>
                <button class="btn-small btn-delete" onclick="excluirEtiqueta(<?= $etiqueta['id'] ?>, '<?= htmlspecialchars($etiqueta['nome']) ?>')">
                    🗑️ Excluir
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($etiquetas)): ?>
<div class="etiquetas-section" style="text-align: center; padding: 60px 20px;">
    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2" style="margin-bottom: 20px;">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
        <line x1="7" y1="7" x2="7.01" y2="7"></line>
    </svg>
    <h3 style="color: #666; margin-bottom: 10px;">Nenhuma etiqueta cadastrada</h3>
    <p style="color: #999;">Crie sua primeira etiqueta para organizar melhor suas tarefas e processos</p>
</div>
<?php endif; ?>

<!-- Modal Criar/Editar -->
<div id="modalEtiqueta" class="modal">
    <div class="modal-content">
        <h3 id="modalTitulo">Nova Etiqueta</h3>
        <form method="POST" id="formEtiqueta">
            <input type="hidden" name="acao" id="formAcao" value="criar">
            <input type="hidden" name="id" id="formId" value="">
            
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="nome" id="formNome" required maxlength="100">
            </div>
            
            <div class="form-group">
                <label>Cor</label>
                <input type="color" name="cor" id="formCor" value="#667eea">
            </div>
            
            <div class="form-group">
                <label>Tipo</label>
                <select name="tipo" id="formTipo">
                    <option value="geral">Geral (Todos os módulos)</option>
                    <option value="processo">Processos</option>
                    <option value="tarefa">Tarefas</option>
                    <option value="prazo">Prazos</option>
                    <option value="audiencia">Audiências</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="descricao" id="formDescricao" rows="3" maxlength="500"></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-small" onclick="fecharModal()" style="background: #6c757d; color: white;">
                    Cancelar
                </button>
                <button type="submit" class="btn-primary">
                    Salvar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalCriar() {
    document.getElementById('modalTitulo').textContent = 'Nova Etiqueta';
    document.getElementById('formAcao').value = 'criar';
    document.getElementById('formId').value = '';
    document.getElementById('formNome').value = '';
    document.getElementById('formCor').value = '#667eea';
    document.getElementById('formTipo').value = 'geral';
    document.getElementById('formDescricao').value = '';
    document.getElementById('modalEtiqueta').style.display = 'block';
}

function editarEtiqueta(etiqueta) {
    document.getElementById('modalTitulo').textContent = 'Editar Etiqueta';
    document.getElementById('formAcao').value = 'editar';
    document.getElementById('formId').value = etiqueta.id;
    document.getElementById('formNome').value = etiqueta.nome;
    document.getElementById('formCor').value = etiqueta.cor;
    document.getElementById('formTipo').value = etiqueta.tipo;
    document.getElementById('formDescricao').value = etiqueta.descricao || '';
    document.getElementById('modalEtiqueta').style.display = 'block';
}

function excluirEtiqueta(id, nome) {
    if (!confirm(`Tem certeza que deseja excluir a etiqueta "${nome}"?\n\nEla será removida de todas as tarefas, prazos e processos vinculados.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="acao" value="excluir">
        <input type="hidden" name="id" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function fecharModal() {
    document.getElementById('modalEtiqueta').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('modalEtiqueta');
    if (event.target === modal) {
        fecharModal();
    }
}
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Gerenciar Etiquetas', $conteudo, 'etiquetas');
?>