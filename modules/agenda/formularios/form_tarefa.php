<?php
require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

$usuario_logado = Auth::user();

// Buscar usuários ativos
$sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$usuarios = executeQuery($sql_usuarios)->fetchAll();

// Buscar etiquetas ativas do tipo tarefa ou geral
$sql_etiquetas = "SELECT id, nome, cor, icone 
                  FROM etiquetas 
                  WHERE ativo = 1 AND tipo IN ('tarefa', 'geral')
                  ORDER BY nome";
$etiquetas = executeQuery($sql_etiquetas)->fetchAll();
?>

<style>
.form-inline-container {
    background: white;
    border-radius: 15px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    padding: 25px;
    margin-bottom: 20px;
}

.form-inline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(0,0,0,0.05);
}

.form-inline-header h3 {
    color: #1a1a1a;
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
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

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px 20px;
    margin-bottom: 20px;
}

.form-group-full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
}

.form-group label .required {
    color: #dc3545;
    margin-left: 3px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

textarea.form-control {
    min-height: 80px;
    resize: vertical;
}

.processo-busca-container {
    position: relative;
}

.processo-busca-input {
    width: 100%;
    padding: 8px 35px 8px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
}

.processo-busca-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    pointer-events: none;
}

.processo-resultados {
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

.processo-resultados.active {
    display: block;
}

.processo-item {
    padding: 10px 12px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.processo-item:hover {
    background: rgba(102, 126, 234, 0.1);
}

.processo-numero {
    font-weight: 600;
    color: #667eea;
}

.processo-cliente {
    color: #666;
    font-size: 12px;
}

.processo-selecionado {
    padding: 8px 12px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
    border-radius: 8px;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
}

.btn-remover-processo {
    background: transparent;
    border: none;
    color: #dc3545;
    cursor: pointer;
    font-size: 16px;
    padding: 0;
    width: 24px;
    height: 24px;
}

.usuarios-selector select {
    width: 100%;
    min-height: 100px;
    padding: 6px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 13px;
}

.usuarios-selector select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.usuarios-selector select option:checked {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.etiquetas-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.btn-criar-etiqueta {
    padding: 4px 10px;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
}

.etiquetas-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    min-height: 32px;
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
}

.modal-header-etiqueta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid rgba(0,0,0,0.05);
}

.cores-etiqueta {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 10px;
    margin: 15px 0;
}

.cor-option {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    cursor: pointer;
    border: 3px solid transparent;
    transition: all 0.2s;
}

.cor-option.selected {
    border-color: #1a1a1a;
    transform: scale(1.1);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 15px;
    border-top: 2px solid rgba(0,0,0,0.05);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.loading-overlay.active {
    display: flex;
}

.loading-spinner {
    background: white;
    padding: 30px;
    border-radius: 15px;
    text-align: center;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 15px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<div class="form-inline-container" id="formTarefaContainer" style="position: relative;">
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <div class="form-inline-header">
        <h3><i class="fas fa-tasks" style="color: #ffc107;"></i> Nova Tarefa</h3>
        <button type="button" class="btn-close-form" onclick="fecharFormulario()" title="Fechar">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <form id="formTarefa">
        <div class="form-grid">
            <div class="form-group form-group-full">
                <label for="titulo">Título <span class="required">*</span></label>
                <input type="text" class="form-control" id="titulo" name="titulo" required 
                       placeholder="Ex: Revisar contrato">
            </div>
            
            <div class="form-group">
                <label for="processo_busca">Processo</label>
                <div class="processo-busca-container">
                    <input type="text" 
                           class="form-control processo-busca-input" 
                           id="processo_busca" 
                           placeholder="Digite para buscar..."
                           autocomplete="off">
                    <i class="fas fa-search processo-busca-icon"></i>
                    <div class="processo-resultados" id="processoResultados"></div>
                </div>
                <input type="hidden" name="processo_id" id="processo_id">
                <div id="processoSelecionado"></div>
            </div>
            
            <div class="form-group">
                <label for="responsavel_id">Responsável <span class="required">*</span></label>
                <select class="form-control" id="responsavel_id" name="responsavel_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($usuarios as $usuario): ?>
                    <option value="<?= $usuario['id'] ?>" 
                            <?= $usuario['id'] == $usuario_logado['usuario_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($usuario['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Data de vencimento -->
            <div class="form-group">
                <label for="data_vencimento">
                    Data de Vencimento
                    <span class="required">*</span>
                </label>
                <input type="date" 
                       class="form-control" 
                       id="data_vencimento" 
                       name="data_vencimento" 
                       required>
            </div>
            
            <!-- Hora de vencimento -->
            <div class="form-group">
                <label for="hora_vencimento">
                    Hora de Vencimento
                </label>
                <input type="time" 
                       class="form-control" 
                       id="hora_vencimento" 
                       name="hora_vencimento"
                       value="23:59">
            </div>
            
            <!-- Dias de antecedência para alerta -->
            <div class="form-group">
                <label for="dias_alerta">
                    Alertar com antecedência
                </label>
                <select class="form-control" id="dias_alerta" name="dias_alerta">
                    <option value="0">No dia do vencimento</option>
                    <option value="1">1 dia antes</option>
                    <option value="2">2 dias antes</option>
                    <option value="3" selected>3 dias antes</option>
                    <option value="5">5 dias antes</option>
                    <option value="7">7 dias antes</option>
                    <option value="10">10 dias antes</option>
                    <option value="15">15 dias antes</option>
                </select>
            </div>
            
            <!-- Prioridade -->
            <div class="form-group">
                <label for="prioridade">
                    Prioridade
                </label>
                <select class="form-control" id="prioridade" name="prioridade">
                    <option value="baixa">🟢 Baixa</option>
                    <option value="normal" selected>🟡 Normal</option>
                    <option value="alta">🔴 Alta</option>
                    <option value="urgente">⚠️ Urgente</option>
                </select>
            </div>
            
            
            <div class="form-group form-group-full">
                <label for="descricao">Descrição</label>
                <textarea class="form-control" id="descricao" name="descricao" 
                          placeholder="Descreva os detalhes da tarefa..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="pendente">Pendente</option>
                    <option value="em_andamento">Em Andamento</option>
                    <option value="concluida">Concluída</option>
                </select>
            </div>
            
            <div class="form-group">
                <div class="etiquetas-header">
                    <label>Etiquetas</label>
                    <button type="button" class="btn-criar-etiqueta" id="btnNovaEtiqueta">
                        <i class="fas fa-plus"></i> Nova
                    </button>
                </div>
                <div class="etiquetas-container" id="etiquetasContainer">
                    <?php if (empty($etiquetas)): ?>
                        <small style="color: #999; font-size: 11px;">Clique em "Nova"</small>
                    <?php else: ?>
                        <?php foreach ($etiquetas as $etiqueta): ?>
                            <input type="checkbox" class="etiqueta-checkbox" 
                                   id="etiqueta_<?= $etiqueta['id'] ?>" 
                                   name="etiquetas[]" value="<?= $etiqueta['id'] ?>">
                            <label for="etiqueta_<?= $etiqueta['id'] ?>" 
                                   class="etiqueta-label" 
                                   style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                                <?= htmlspecialchars($etiqueta['nome']) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group form-group-full">
                <label for="envolvidos">Envolvidos</label>
                <div class="usuarios-selector">
                    <select class="form-control" id="envolvidos" name="envolvidos[]" multiple size="4">
                        <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>">
                            <?= htmlspecialchars($usuario['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small style="color: #666; font-size: 11px; margin-top: 5px; display: block;">
                    💡 Ctrl+clique para múltiplos
                </small>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="fecharFormulario()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn btn-primary" id="btnSalvar">
                <i class="fas fa-save"></i> Salvar
            </button>
        </div>
    </form>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div>Salvando tarefa...</div>
    </div>
</div>

<!-- Modal Etiqueta -->
<div class="modal-etiqueta" id="modalEtiqueta">
    <div class="modal-content-etiqueta">
        <div class="modal-header-etiqueta">
            <h4><i class="fas fa-tag"></i> Nova Etiqueta</h4>
            <button type="button" class="btn-close-form" id="btnFecharModalEtiqueta">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formEtiqueta">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                    Nome <span style="color: #dc3545;">*</span>
                </label>
                <input type="text" class="form-control" id="nomeEtiqueta" 
                       name="nome" required placeholder="Ex: Urgente">
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
                    <option value="tarefa">Tarefa</option>
                    <option value="geral">Geral</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" id="btnCancelarEtiqueta">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-success" id="btnSalvarEtiqueta">
                    Criar
                </button>
            </div>
        </form>
    </div>
</div>

<script id="scriptFormTarefa">
console.log('🔧 [FORM_TAREFA] Inicializando...');

(function() {
    function init() {
        console.log('✅ [INIT] Formulário carregado');
        
        // Data padrão
        const dataInput = document.getElementById('data_vencimento');
        if (dataInput) {
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            dataInput.value = now.toISOString().slice(0, 16);
        }
        
        // Modal etiqueta
        const btnNova = document.getElementById('btnNovaEtiqueta');
        const btnFechar = document.getElementById('btnFecharModalEtiqueta');
        const btnCancelar = document.getElementById('btnCancelarEtiqueta');
        const modal = document.getElementById('modalEtiqueta');
        
        if (btnNova && modal) {
            btnNova.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('📂 [MODAL] Abrindo...');
                modal.classList.add('active');
                const input = document.getElementById('nomeEtiqueta');
                if (input) setTimeout(() => input.focus(), 100);
            });
        }
        
        function fecharModal() {
            console.log('📂 [MODAL] Fechando...');
            if (modal) modal.classList.remove('active');
            const form = document.getElementById('formEtiqueta');
            if (form) form.reset();
            document.querySelectorAll('.cor-option').forEach(c => c.classList.remove('selected'));
            const primeira = document.querySelector('.cor-option');
            if (primeira) primeira.classList.add('selected');
            const corInput = document.getElementById('corEtiqueta');
            if (corInput) corInput.value = '#667eea';
        }
        
        if (btnFechar) btnFechar.addEventListener('click', fecharModal);
        if (btnCancelar) btnCancelar.addEventListener('click', fecharModal);
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) fecharModal();
            });
        }
        
        // Cores
        document.querySelectorAll('.cor-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.cor-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                const corInput = document.getElementById('corEtiqueta');
                if (corInput) corInput.value = this.dataset.cor;
            });
        });
        
        // Busca de processos
        const processoBusca = document.getElementById('processo_busca');
        const processoResultados = document.getElementById('processoResultados');
        let timeout = null;
        
        if (processoBusca && processoResultados) {
            processoBusca.addEventListener('input', function() {
                const termo = this.value.trim();
                
                clearTimeout(timeout);
                
                if (termo.length < 2) {
                    processoResultados.classList.remove('active');
                    processoResultados.innerHTML = '';
                    return;
                }
                
                timeout = setTimeout(() => buscar(termo), 300);
            });
            
            document.addEventListener('click', function(e) {
                if (!processoBusca.contains(e.target) && !processoResultados.contains(e.target)) {
                    processoResultados.classList.remove('active');
                }
            });
        }
        
        async function buscar(termo) {
            console.log('🔍 [BUSCA] Buscando:', termo);
            try {
                const url = `/modules/agenda/formularios/buscar_processos.php?termo=${encodeURIComponent(termo)}`;
                const response = await fetch(url);
                
                const text = await response.text();
                const processos = JSON.parse(text);
                console.log('✅ [BUSCA] Encontrados:', processos.length);
                
                if (processos.length === 0) {
                    processoResultados.innerHTML = '<div class="processo-item" style="color:#999">Nenhum encontrado</div>';
                } else {
                    processoResultados.innerHTML = processos.map(p => `
                        <div class="processo-item" data-id="${p.id}" data-numero="${p.numero_processo}" data-cliente="${p.cliente_nome}">
                            <div class="processo-numero">${p.numero_processo}</div>
                            <div class="processo-cliente">${p.cliente_nome}</div>
                        </div>
                    `).join('');
                    
                    processoResultados.querySelectorAll('.processo-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const id = this.dataset.id;
                            const numero = this.dataset.numero;
                            const cliente = this.dataset.cliente;
                            
                            const processoId = document.getElementById('processo_id');
                            const processoBusca = document.getElementById('processo_busca');
                            const processoSelecionado = document.getElementById('processoSelecionado');
                            
                            if (processoId) processoId.value = id;
                            if (processoBusca) processoBusca.value = '';
                            processoResultados.classList.remove('active');
                            
                            if (processoSelecionado) {
                                processoSelecionado.innerHTML = `
                                    <div class="processo-selecionado">
                                        <div>
                                            <div class="processo-numero">${numero}</div>
                                            <div class="processo-cliente">${cliente}</div>
                                        </div>
                                        <button type="button" class="btn-remover-processo" onclick="removerProcessoTarefa()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                `;
                            }
                        });
                    });
                }
                
                processoResultados.classList.add('active');
            } catch (error) {
                console.error('❌ [BUSCA] Erro:', error);
            }
        }
        
        window.removerProcessoTarefa = function() {
            const processoId = document.getElementById('processo_id');
            const processoSelecionado = document.getElementById('processoSelecionado');
            if (processoId) processoId.value = '';
            if (processoSelecionado) processoSelecionado.innerHTML = '';
        };
        
        // Form etiqueta - CÓDIGO CORRIGIDO
        const formEtiqueta = document.getElementById('formEtiqueta');
        if (formEtiqueta) {
            formEtiqueta.addEventListener('submit', async function(e) {
                e.preventDefault();
                console.log('💾 [ETIQUETA] Salvando...');
                
                const btnSubmit = this.querySelector('button[type="submit"]');
                const textoOriginal = btnSubmit.textContent;
                
                try {
                    btnSubmit.disabled = true;
                    btnSubmit.textContent = '⏳ Salvando...';
                    
                    const formData = new FormData(this);
                    
                    const response = await fetch('/modules/agenda/formularios/salvar_etiqueta.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    
                    console.log('📊 Status:', response.status);
                    
                    const text = await response.text();
                    console.log('📄 Resposta:', text.substring(0, 200));
                    
                    let result;
                    try {
                        result = JSON.parse(text);
                        console.log('✅ JSON parseado:', result);
                    } catch (parseError) {
                        console.error('❌ Erro parse:', parseError);
                        console.error('📄 Texto completo:', text);
                        throw new Error('Resposta inválida do servidor');
                    }
                    
                    if (result.success) {
                        console.log('🎉 Etiqueta salva!');
                        
                        // USAR VARIÁVEL 'modal' DO ESCOPO
                        if (modal) {
                            modal.classList.remove('active');
                            console.log('✅ Modal fechado');
                        }
                        
                        this.reset();
                        
                        // USAR .cor-option (NÃO .cor-item)
                        document.querySelectorAll('.cor-option').forEach(item => {
                            item.classList.remove('selected');
                        });
                        const primeiraCor = document.querySelector('.cor-option');
                        if (primeiraCor) primeiraCor.classList.add('selected');
                        const corInput = document.getElementById('corEtiqueta');
                        if (corInput) corInput.value = '#667eea';
                        
                        // Adicionar à lista
                        const container = document.getElementById('etiquetasContainer');
                        if (container) {
                            const small = container.querySelector('small');
                            if (small) small.remove();
                            
                            container.insertAdjacentHTML('beforeend', `
                                <input type="checkbox" class="etiqueta-checkbox" 
                                       id="etiqueta_${result.etiqueta_id}" 
                                       name="etiquetas[]" value="${result.etiqueta_id}" checked>
                                <label for="etiqueta_${result.etiqueta_id}" 
                                       class="etiqueta-label" 
                                       style="background: ${result.cor}; color: white;">
                                    ${result.nome}
                                </label>
                            `);
                            
                            console.log('✅ Etiqueta adicionada');
                        }
                        
                    } else {
                        console.error('❌ Erro:', result.message);
                        alert('❌ Erro: ' + result.message);
                    }
                    
                } catch (error) {
                    console.error('❌ Erro ao salvar:', error);
                    alert('❌ Erro: ' + error.message);
                } finally {
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = textoOriginal;
                }
            });
        }
        
        // Form tarefa
        const formTarefa = document.getElementById('formTarefa');
        if (formTarefa) {
            formTarefa.addEventListener('submit', async function(e) {
                e.preventDefault();
                console.log('💾 [TAREFA] Salvando...');
                
                const btn = document.getElementById('btnSalvar');
                const loading = document.getElementById('loadingOverlay');
                
                if (btn) btn.disabled = true;
                if (loading) loading.classList.add('active');
                
                try {
                    const formData = new FormData(this);
                    const response = await fetch('/modules/agenda/formularios/salvar_tarefa.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    console.log('✅ Resultado:', result);
                    
                    if (result.success) {
                        alert('✅ Tarefa criada!');
                        window.location.reload();
                    } else {
                        alert('❌ Erro: ' + result.message);
                        if (btn) btn.disabled = false;
                    }
                } catch (error) {
                    console.error('❌ Erro:', error);
                    alert('❌ Erro: ' + error.message);
                    if (btn) btn.disabled = false;
                } finally {
                    if (loading) loading.classList.remove('active');
                }
            });
        }
        
        console.log('🎉 [INIT] Completo!');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>