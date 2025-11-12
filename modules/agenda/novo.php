<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();

// Buscar usuários para participantes
try {
    $sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// Buscar etiquetas ativas para agenda
try {
    $sql = "SELECT id, nome, cor, icone 
            FROM etiquetas 
            WHERE ativo = 1 AND tipo IN ('agenda', 'geral')
            ORDER BY nome";
    $stmt = executeQuery($sql);
    $etiquetas = $stmt->fetchAll();
} catch (Exception $e) {
    $etiquetas = [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validações básicas
        $titulo = trim($_POST['titulo']);
        $data_inicio = $_POST['data_inicio'];
        $data_fim = $_POST['data_fim'];
        $tipo = $_POST['tipo'];
        
        if (empty($titulo)) {
            throw new Exception('Título é obrigatório');
        }
        
        if (empty($data_inicio) || empty($data_fim)) {
            throw new Exception('Data de início e fim são obrigatórias');
        }
        
        if (strtotime($data_fim) < strtotime($data_inicio)) {
            throw new Exception('Data de fim deve ser posterior à data de início');
        }
        
        // Inserir evento
        $sql = "INSERT INTO agenda (
            titulo, descricao, data_inicio, data_fim, tipo, status, prioridade,
            local_evento, observacoes, lembrete_minutos, cliente_id,
            processo_id, criado_por
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $titulo,
            $_POST['descricao'] ?: null,
            $data_inicio,
            $data_fim,
            $tipo,
            'Agendado',
            $_POST['prioridade'] ?: 'Normal',
            $_POST['local_evento'] ?: null,
            $_POST['observacoes'] ?: null,
            $_POST['lembrete_minutos'] ?: 15,
            $_POST['cliente_id'] ?: null,
            $_POST['processo_id'] ?: null,
            $usuario_logado['usuario_id']
        ];

        executeQuery($sql, $params);
        $evento_id = $GLOBALS['pdo']->lastInsertId();

        // Adicionar participantes (incluindo organizadores selecionados)
        if (!empty($_POST['participantes'])) {
            foreach ($_POST['participantes'] as $participante_id) {
                // Verificar se é organizador ou convidado
                $status = in_array($participante_id, $_POST['organizadores'] ?? []) ? 'Organizador' : 'Convidado';
                
                try {
                    if ($status === 'Organizador') {
                        $sql_part = "INSERT INTO agenda_participantes (agenda_id, usuario_id, status_participacao, data_resposta) 
                                    VALUES (?, ?, 'Organizador', NOW())";
                    } else {
                        $sql_part = "INSERT INTO agenda_participantes (agenda_id, usuario_id, status_participacao) 
                                    VALUES (?, ?, 'Convidado')";
                    }
                    executeQuery($sql_part, [$evento_id, $participante_id]);
                } catch (Exception $e) {
                    error_log("Erro ao adicionar participante: " . $e->getMessage());
                }
            }
        }
        
        // Adicionar etiquetas
        if (!empty($_POST['etiquetas'])) {
            foreach ($_POST['etiquetas'] as $etiqueta_id) {
                try {
                    $sql_et = "INSERT INTO agenda_etiquetas (agenda_id, etiqueta_id) VALUES (?, ?)";
                    executeQuery($sql_et, [$evento_id, $etiqueta_id]);
                } catch (Exception $e) {
                    error_log("Erro ao adicionar etiqueta: " . $e->getMessage());
                }
            }
        }
        
        // Registrar no histórico
        try {
            $sql_hist = "INSERT INTO agenda_historico (agenda_id, acao, descricao_alteracao, usuario_id) 
                         VALUES (?, 'Criado', 'Evento criado na agenda', ?)";
            executeQuery($sql_hist, [$evento_logado['usuario_id']]);
        } catch (Exception $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
        }
        
        header('Location: visualizar.php?id=' . $evento_id . '&tipo=evento&success=criado');
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Valores padrão para o formulário
$data_default = $_GET['date'] ?? date('Y-m-d');
$hora_default = $_GET['time'] ?? date('H:i');

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
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    /* Busca de Cliente/Processo */
    .busca-container {
        position: relative;
    }
    
    .busca-input {
        width: 100%;
        padding: 12px 40px 12px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
    }
    
    .busca-icon {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
    }
    
    .busca-resultados {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .busca-resultados.active {
        display: block;
    }
    
    .busca-item {
        padding: 10px 12px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 13px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .busca-item:hover {
        background: rgba(0, 123, 255, 0.1);
    }
    
    .busca-principal {
        font-weight: 600;
        color: #007bff;
    }
    
    .busca-secundaria {
        color: #666;
        font-size: 12px;
    }
    
    .item-selecionado {
        padding: 8px 12px;
        background: linear-gradient(135deg, rgba(0, 123, 255, 0.1) 0%, rgba(0, 86, 179, 0.1) 100%);
        border-radius: 8px;
        font-size: 13px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
    }
    
    .btn-remover {
        background: transparent;
        border: none;
        color: #dc3545;
        cursor: pointer;
        font-size: 16px;
        padding: 0;
        width: 24px;
        height: 24px;
    }
    
    /* Participantes */
    .participantes-container {
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 10px;
        background: #f8f9fa;
    }
    
    .participante-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 0;
    }
    
    .participante-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .participante-item label {
        cursor: pointer;
        margin: 0;
        flex: 1;
    }
    
    .organizador-checkbox {
        margin-left: auto;
        display: none;
    }
    
    .participante-item input:checked ~ .organizador-checkbox {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        color: #666;
    }
    
    .organizador-checkbox input {
        width: 16px;
        height: 16px;
    }
    
    /* Etiquetas */
    .etiquetas-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .btn-criar-etiqueta {
        padding: 5px 12px;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-criar-etiqueta:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }
    
    .etiquetas-container {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        min-height: 40px;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #f8f9fa;
    }
    
    .etiqueta-checkbox {
        display: none;
    }
    
    .etiqueta-label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 2px solid transparent;
        opacity: 0.6;
        transition: all 0.3s;
    }
    
    .etiqueta-checkbox:checked + .etiqueta-label {
        opacity: 1;
        border-color: rgba(0,0,0,0.2);
        transform: translateY(-2px);
    }
    
    /* Modal Etiqueta */
    .modal-etiqueta {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }
    
    .modal-etiqueta.active {
        display: flex;
    }
    
    .modal-content-etiqueta {
        background: white;
        border-radius: 15px;
        padding: 25px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }
    
    .modal-header-etiqueta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
    }
    
    .modal-header-etiqueta h4 {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .btn-close-form {
        background: transparent;
        border: none;
        color: #999;
        font-size: 24px;
        cursor: pointer;
        transition: all 0.3s;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
    }
    
    .btn-close-form:hover {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }
    
    .cores-etiqueta {
        display: grid;
        grid-template-columns: repeat(8, 1fr);
        gap: 10px;
        margin: 15px 0;
    }
    
    .cor-option {
        width: 100%;
        aspect-ratio: 1;
        border-radius: 8px;
        cursor: pointer;
        border: 3px solid transparent;
        transition: all 0.2s;
    }
    
    .cor-option.selected {
        border-color: #1a1a1a;
        transform: scale(1.1);
    }
    
    .cor-option:hover {
        transform: scale(1.05);
    }
    
    /* Tipo de evento */
    .tipo-badges {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .tipo-badge {
        padding: 12px 20px;
        border: 2px solid #ddd;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .tipo-badge:hover {
        border-color: #007bff;
        background: rgba(0, 123, 255, 0.05);
    }
    
    .tipo-badge.selected {
        border-color: #007bff;
        background: linear-gradient(135deg, rgba(0, 123, 255, 0.1) 0%, rgba(0, 86, 179, 0.1) 100%);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.2);
    }
    
    /* Botões */
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        padding-top: 20px;
        border-top: 2px solid #e9ecef;
        margin-top: 20px;
    }
    
    .btn {
        padding: 12px 30px;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        font-size: 15px;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
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
        transform: translateY(-2px);
    }
    
    .btn-success {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
    }
    
    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .alert {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: 600;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
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
        
        .cores-etiqueta {
            grid-template-columns: repeat(4, 1fr);
        }
    }
</style>

<div class="page-header">
    <h2>📅 Novo Evento</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<form method="POST" class="form-container" id="formEvento">
    <!-- Informações Básicas -->
    <div class="form-section">
        <h3>📋 Informações Básicas</h3>
        
        <div class="form-group full-width">
            <label for="titulo" class="required">Título do Evento</label>
            <input type="text" id="titulo" name="titulo" required value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>">
        </div>
        
        <div class="form-group full-width">
            <label for="descricao">Descrição</label>
            <textarea id="descricao" name="descricao" placeholder="Descreva os detalhes do evento..."><?= htmlspecialchars($_POST['descricao'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group full-width">
            <label>Tipo de Evento</label>
            <div class="tipo-badges">
                <div class="tipo-badge" data-tipo="Reunião">📝 Reunião</div>
                <div class="tipo-badge" data-tipo="Compromisso">📅 Compromisso</div>
                <div class="tipo-badge" data-tipo="Outro">📌 Outro</div>
            </div>
            <input type="hidden" id="tipo" name="tipo" value="<?= htmlspecialchars($_POST['tipo'] ?? 'Compromisso') ?>">
        </div>
    </div>

    <!-- Data e Hora -->
    <div class="form-section">
        <h3>🕐 Data e Hora</h3>
        <div class="form-grid two-cols">
            <div class="form-group">
                <label for="data_inicio" class="required">Data/Hora de Início</label>
                <input type="datetime-local" id="data_inicio" name="data_inicio" required 
                       value="<?= htmlspecialchars($_POST['data_inicio'] ?? $data_default . 'T' . $hora_default) ?>">
            </div>
            
            <div class="form-group">
                <label for="data_fim" class="required">Data/Hora de Fim</label>
                <input type="datetime-local" id="data_fim" name="data_fim" required 
                       value="<?= htmlspecialchars($_POST['data_fim'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Prioridade -->
    <div class="form-section">
        <h3>⚡ Prioridade e Configurações</h3>
        <div class="form-grid two-cols">
            <div class="form-group">
                <label for="prioridade">Prioridade</label>
                <select id="prioridade" name="prioridade">
                    <option value="Baixa" <?= ($_POST['prioridade'] ?? '') === 'Baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="Normal" <?= ($_POST['prioridade'] ?? 'Normal') === 'Normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="Alta" <?= ($_POST['prioridade'] ?? '') === 'Alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="Urgente" <?= ($_POST['prioridade'] ?? '') === 'Urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="lembrete_minutos">Lembrete (minutos antes)</label>
                <select id="lembrete_minutos" name="lembrete_minutos">
                    <option value="0" <?= ($_POST['lembrete_minutos'] ?? '') === '0' ? 'selected' : '' ?>>Sem lembrete</option>
                    <option value="5" <?= ($_POST['lembrete_minutos'] ?? '') === '5' ? 'selected' : '' ?>>5 minutos</option>
                    <option value="15" <?= ($_POST['lembrete_minutos'] ?? '15') === '15' ? 'selected' : '' ?>>15 minutos</option>
                    <option value="30" <?= ($_POST['lembrete_minutos'] ?? '') === '30' ? 'selected' : '' ?>>30 minutos</option>
                    <option value="60" <?= ($_POST['lembrete_minutos'] ?? '') === '60' ? 'selected' : '' ?>>1 hora</option>
                    <option value="120" <?= ($_POST['lembrete_minutos'] ?? '') === '120' ? 'selected' : '' ?>>2 horas</option>
                    <option value="1440" <?= ($_POST['lembrete_minutos'] ?? '') === '1440' ? 'selected' : '' ?>>1 dia</option>
                </select>
            </div>
        </div>
        
        <div class="form-group full-width">
            <label for="local_evento">Local do Evento</label>
            <input type="text" id="local_evento" name="local_evento" 
                   value="<?= htmlspecialchars($_POST['local_evento'] ?? '') ?>"
                   placeholder="Ex: Escritório, Fórum, Online...">
        </div>
    </div>

    <!-- Participantes -->
    <div class="form-section">
        <h3>👥 Participantes</h3>
        <div class="form-group">
            <label>Selecionar Participantes</label>
            <p style="font-size: 13px; color: #666; margin-bottom: 10px;">
                💡 Marque os participantes e defina quem será organizador
            </p>
            <div class="participantes-container">
                <?php foreach ($usuarios as $usuario): ?>
                <div class="participante-item">
                    <input type="checkbox" 
                           id="part_<?= $usuario['id'] ?>" 
                           name="participantes[]" 
                           value="<?= $usuario['id'] ?>"
                           <?= in_array($usuario['id'], $_POST['participantes'] ?? []) ? 'checked' : '' ?>>
                    <label for="part_<?= $usuario['id'] ?>"><?= htmlspecialchars($usuario['nome']) ?></label>
                    <div class="organizador-checkbox">
                        <input type="checkbox" 
                               id="org_<?= $usuario['id'] ?>" 
                               name="organizadores[]" 
                               value="<?= $usuario['id'] ?>"
                               <?= in_array($usuario['id'], $_POST['organizadores'] ?? []) ? 'checked' : '' ?>>
                        <label for="org_<?= $usuario['id'] ?>">Organizador</label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Etiquetas/Tags -->
    <div class="form-section">
        <h3>🏷️ Etiquetas</h3>
        <div class="form-group">
            <div class="etiquetas-header">
                <label>Categorizar com Etiquetas</label>
                <button type="button" class="btn-criar-etiqueta" id="btnNovaEtiqueta">
                    ➕ Nova Etiqueta
                </button>
            </div>
            <div class="etiquetas-container" id="etiquetasContainer">
                <?php if (empty($etiquetas)): ?>
                    <small style="color: #999; font-size: 12px;">Clique em "Nova Etiqueta" para criar</small>
                <?php else: ?>
                    <?php foreach ($etiquetas as $etiqueta): ?>
                        <input type="checkbox" 
                               class="etiqueta-checkbox" 
                               id="etiqueta_<?= $etiqueta['id'] ?>" 
                               name="etiquetas[]" 
                               value="<?= $etiqueta['id'] ?>"
                               <?= in_array($etiqueta['id'], $_POST['etiquetas'] ?? []) ? 'checked' : '' ?>>
                        <label for="etiqueta_<?= $etiqueta['id'] ?>" 
                               class="etiqueta-label" 
                               style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                            <?php if (!empty($etiqueta['icone'])): ?>
                                <?= htmlspecialchars($etiqueta['icone']) ?>
                            <?php endif; ?>
                            <?= htmlspecialchars($etiqueta['nome']) ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Vinculações -->
    <div class="form-section">
        <h3>🔗 Vinculações</h3>
        <div class="form-grid two-cols">
            <!-- Cliente -->
            <div class="form-group">
                <label for="cliente_busca">Cliente</label>
                <div class="busca-container">
                    <input type="text" 
                           class="busca-input" 
                           id="cliente_busca" 
                           placeholder="Digite para buscar cliente..."
                           autocomplete="off">
                    <span class="busca-icon">🔍</span>
                    <div class="busca-resultados" id="clienteResultados"></div>
                </div>
                <input type="hidden" name="cliente_id" id="cliente_id" value="<?= $_POST['cliente_id'] ?? '' ?>">
                <div id="clienteSelecionado"></div>
            </div>
            
            <!-- Processo -->
            <div class="form-group">
                <label for="processo_busca">Processo</label>
                <div class="busca-container">
                    <input type="text" 
                           class="busca-input" 
                           id="processo_busca" 
                           placeholder="Digite para buscar processo..."
                           autocomplete="off">
                    <span class="busca-icon">🔍</span>
                    <div class="busca-resultados" id="processoResultados"></div>
                </div>
                <input type="hidden" name="processo_id" id="processo_id" value="<?= $_POST['processo_id'] ?? '' ?>">
                <div id="processoSelecionado"></div>
            </div>
        </div>
    </div>

    <!-- Observações -->
    <div class="form-section">
        <h3>📝 Observações</h3>
        <div class="form-group full-width">
            <label for="observacoes">Observações Adicionais</label>
            <textarea id="observacoes" name="observacoes" 
                      placeholder="Informações adicionais sobre o evento..."><?= htmlspecialchars($_POST['observacoes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <a href="index.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">📅 Criar Evento</button>
    </div>
</form>

<!-- Modal Etiqueta -->
<div class="modal-etiqueta" id="modalEtiqueta">
    <div class="modal-content-etiqueta">
        <div class="modal-header-etiqueta">
            <h4>🏷️ Nova Etiqueta</h4>
            <button type="button" class="btn-close-form" id="btnFecharModalEtiqueta">✕</button>
        </div>
        
        <form id="formEtiqueta">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                    Nome <span style="color: #dc3545;">*</span>
                </label>
                <input type="text" class="form-control" id="nomeEtiqueta" 
                       name="nome" required placeholder="Ex: Reunião Importante">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cor</label>
                <div class="cores-etiqueta" id="coresEtiqueta">
                    <div class="cor-option selected" data-cor="#667eea" style="background: #667eea;"></div>
                    <div class="cor-option" data-cor="#dc3545" style="background: #dc3545;"></div>
                    <div class="cor-option" data-cor="#28a745" style="background: #28a745;"></div>
                    <div class="cor-option" data-cor="#ffc107" style="background: #ffc107;"></div>
                    <div class="cor-option" data-cor="#17a2b8" style="background: #17a2b8;"></div>
                    <div class="cor-option" data-cor="#6f42c1" style="background: #6f42c1;"></div>
                    <div class="cor-option" data-cor="#fd7e14" style="background: #fd7e14;"></div>
                    <div class="cor-option" data-cor="#e83e8c" style="background: #e83e8c;"></div>
                </div>
                <input type="hidden" name="cor" id="corEtiqueta" value="#667eea">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Tipo</label>
                <select class="form-control" name="tipo">
                    <option value="agenda">Agenda</option>
                    <option value="geral">Geral</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" id="btnCancelarEtiqueta">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-success" id="btnSalvarEtiqueta">
                    ✓ Criar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Seleção de tipo de evento
document.querySelectorAll('.tipo-badge').forEach(badge => {
    badge.addEventListener('click', function() {
        document.querySelectorAll('.tipo-badge').forEach(b => b.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('tipo').value = this.dataset.tipo;
    });
});

// Definir tipo inicial
const tipoInicial = document.getElementById('tipo').value;
document.querySelector(`[data-tipo="${tipoInicial}"]`)?.classList.add('selected');

// Auto-ajustar data fim quando data início mudar
document.getElementById('data_inicio').addEventListener('change', function() {
    const dataInicio = new Date(this.value);
    const dataFim = document.getElementById('data_fim');
    
    if (!dataFim.value || new Date(dataFim.value) <= dataInicio) {
        const novaDataFim = new Date(dataInicio.getTime() + 60 * 60 * 1000);
        dataFim.value = novaDataFim.toISOString().slice(0, 16);
    }
});

// Modal Etiqueta
const btnNovaEtiqueta = document.getElementById('btnNovaEtiqueta');
const btnFecharModal = document.getElementById('btnFecharModalEtiqueta');
const btnCancelarModal = document.getElementById('btnCancelarEtiqueta');
const modalEtiqueta = document.getElementById('modalEtiqueta');

if (btnNovaEtiqueta) {
    btnNovaEtiqueta.addEventListener('click', function(e) {
        e.preventDefault();
        modalEtiqueta.classList.add('active');
        setTimeout(() => document.getElementById('nomeEtiqueta')?.focus(), 100);
    });
}

function fecharModalEtiqueta() {
    modalEtiqueta.classList.remove('active');
    document.getElementById('formEtiqueta').reset();
    document.querySelectorAll('.cor-option').forEach(c => c.classList.remove('selected'));
    document.querySelector('.cor-option').classList.add('selected');
    document.getElementById('corEtiqueta').value = '#667eea';
}

if (btnFecharModal) btnFecharModal.addEventListener('click', fecharModalEtiqueta);
if (btnCancelarModal) btnCancelarModal.addEventListener('click', fecharModalEtiqueta);

if (modalEtiqueta) {
    modalEtiqueta.addEventListener('click', function(e) {
        if (e.target === this) fecharModalEtiqueta();
    });
}

// Seleção de cor
document.querySelectorAll('.cor-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.cor-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('corEtiqueta').value = this.dataset.cor;
    });
});

// Salvar etiqueta
const formEtiqueta = document.getElementById('formEtiqueta');
if (formEtiqueta) {
    formEtiqueta.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const btnSubmit = document.getElementById('btnSalvarEtiqueta');
        const textoOriginal = btnSubmit.textContent;
        
        try {
            btnSubmit.disabled = true;
            btnSubmit.textContent = '⏳ Salvando...';
            
            const formData = new FormData(this);
            
            const response = await fetch('formularios/salvar_etiqueta.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Fechar modal
                fecharModalEtiqueta();
                
                // Adicionar à lista
                const container = document.getElementById('etiquetasContainer');
                const small = container.querySelector('small');
                if (small) small.remove();
                
                container.insertAdjacentHTML('beforeend', `
                    <input type="checkbox" 
                           class="etiqueta-checkbox" 
                           id="etiqueta_${result.etiqueta_id}" 
                           name="etiquetas[]" 
                           value="${result.etiqueta_id}" 
                           checked>
                    <label for="etiqueta_${result.etiqueta_id}" 
                           class="etiqueta-label" 
                           style="background: ${result.cor}; color: white;">
                        ${result.nome}
                    </label>
                `);
            } else {
                alert('Erro ao criar etiqueta: ' + (result.message || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('Erro ao criar etiqueta. Tente novamente.');
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.textContent = textoOriginal;
        }
    });
}

// Busca de Clientes
let buscaClienteTimeout;
const clienteBuscaInput = document.getElementById('cliente_busca');
const clienteResultados = document.getElementById('clienteResultados');
const clienteIdInput = document.getElementById('cliente_id');
const clienteSelecionado = document.getElementById('clienteSelecionado');

clienteBuscaInput.addEventListener('input', function() {
    clearTimeout(buscaClienteTimeout);
    const termo = this.value.trim();
    
    if (termo.length < 2) {
        clienteResultados.classList.remove('active');
        return;
    }
    
    buscaClienteTimeout = setTimeout(() => {
        fetch(`formularios/buscar_clientes.php?termo=${encodeURIComponent(termo)}`)
            .then(response => response.json())
            .then(clientes => {
                clienteResultados.innerHTML = '';
                
                if (clientes.length === 0) {
                    clienteResultados.innerHTML = '<div class="busca-item">Nenhum cliente encontrado</div>';
                } else {
                    clientes.forEach(cliente => {
                        const div = document.createElement('div');
                        div.className = 'busca-item';
                        div.innerHTML = `
                            <div class="busca-principal">${cliente.nome}</div>
                            <div class="busca-secundaria">${cliente.cpf_cnpj || ''} ${cliente.email || ''}</div>
                        `;
                        div.onclick = () => selecionarCliente(cliente);
                        clienteResultados.appendChild(div);
                    });
                }
                
                clienteResultados.classList.add('active');
            })
            .catch(error => {
                console.error('Erro ao buscar clientes:', error);
                clienteResultados.innerHTML = '<div class="busca-item">Erro ao buscar</div>';
                clienteResultados.classList.add('active');
            });
    }, 300);
});

function selecionarCliente(cliente) {
    clienteIdInput.value = cliente.id;
    clienteBuscaInput.value = '';
    clienteResultados.classList.remove('active');
    
    clienteSelecionado.innerHTML = `
        <div class="item-selecionado">
            <div>
                <strong>${cliente.nome}</strong><br>
                <small>${cliente.cpf_cnpj || ''}</small>
            </div>
            <button type="button" class="btn-remover" onclick="removerCliente()" title="Remover">✕</button>
        </div>
    `;
}

function removerCliente() {
    clienteIdInput.value = '';
    clienteSelecionado.innerHTML = '';
}

// Busca de Processos
let buscaProcessoTimeout;
const processoBuscaInput = document.getElementById('processo_busca');
const processoResultados = document.getElementById('processoResultados');
const processoIdInput = document.getElementById('processo_id');
const processoSelecionado = document.getElementById('processoSelecionado');

processoBuscaInput.addEventListener('input', function() {
    clearTimeout(buscaProcessoTimeout);
    const termo = this.value.trim();
    
    if (termo.length < 2) {
        processoResultados.classList.remove('active');
        return;
    }
    
    buscaProcessoTimeout = setTimeout(() => {
        fetch(`formularios/buscar_processos.php?termo=${encodeURIComponent(termo)}`)
            .then(response => response.json())
            .then(processos => {
                processoResultados.innerHTML = '';
                
                if (processos.length === 0) {
                    processoResultados.innerHTML = '<div class="busca-item">Nenhum processo encontrado</div>';
                } else {
                    processos.forEach(processo => {
                        const div = document.createElement('div');
                        div.className = 'busca-item';
                        div.innerHTML = `
                            <div class="busca-principal">${processo.numero_processo}</div>
                            <div class="busca-secundaria">${processo.cliente_nome || ''}</div>
                        `;
                        div.onclick = () => selecionarProcesso(processo);
                        processoResultados.appendChild(div);
                    });
                }
                
                processoResultados.classList.add('active');
            })
            .catch(error => {
                console.error('Erro ao buscar processos:', error);
                processoResultados.innerHTML = '<div class="busca-item">Erro ao buscar</div>';
                processoResultados.classList.add('active');
            });
    }, 300);
});

function selecionarProcesso(processo) {
    processoIdInput.value = processo.id;
    processoBuscaInput.value = '';
    processoResultados.classList.remove('active');
    
    processoSelecionado.innerHTML = `
        <div class="item-selecionado">
            <div>
                <strong>${processo.numero_processo}</strong><br>
                <small>${processo.cliente_nome || ''}</small>
            </div>
            <button type="button" class="btn-remover" onclick="removerProcesso()" title="Remover">✕</button>
        </div>
    `;
}

function removerProcesso() {
    processoIdInput.value = '';
    processoSelecionado.innerHTML = '';
}

// Fechar resultados ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.busca-container')) {
        document.querySelectorAll('.busca-resultados').forEach(el => {
            el.classList.remove('active');
        });
    }
});

// Controle de organizadores - só aparecem quando participante está marcado
document.querySelectorAll('input[name="participantes[]"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const orgCheckbox = document.getElementById('org_' + this.value);
        if (!this.checked && orgCheckbox) {
            orgCheckbox.checked = false;
        }
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Evento', $conteudo, 'agenda');
?>
