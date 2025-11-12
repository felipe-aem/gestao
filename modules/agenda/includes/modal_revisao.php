<!-- Modal: Enviar para Revisão -->
<div id="modalRevisao" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>📋 Enviar para Revisão</h3>
            <button type="button" class="btn-close-modal" onclick="fecharModalRevisao()">&times;</button>
        </div>
        
        <form id="formRevisao" enctype="multipart/form-data">
            <input type="hidden" id="revisao_item_id" name="item_id">
            <input type="hidden" id="revisao_tipo" name="tipo">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="revisor_id">Revisor <span class="required">*</span></label>
                    <select id="revisor_id" name="revisor_id" class="form-control" required>
                        <option value="">Selecione um revisor...</option>
                        <?php
                        $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
                        $usuarios_rev = executeQuery($sql_usuarios)->fetchAll();
                        foreach ($usuarios_rev as $u) {
                            echo "<option value='{$u['id']}'>{$u['nome']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="comentario_revisao">Comentário</label>
                    <textarea id="comentario_revisao" name="comentario_revisao" 
                              class="form-control" rows="4" 
                              placeholder="Adicione observações para o revisor..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="arquivos_revisao">Anexar Arquivos</label>
                    <input type="file" id="arquivos_revisao" name="arquivos_revisao[]" 
                           class="form-control" multiple>
                    <small class="form-text">Você pode anexar múltiplos arquivos</small>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalRevisao()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="enviarParaRevisao()">
                    📤 Enviar para Revisão
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Responder Revisão -->
<div id="modalResponderRevisao" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3>✅ Responder Revisão</h3>
            <button type="button" class="btn-close-modal" onclick="fecharModalResponderRevisao()">&times;</button>
        </div>
        
        <form id="formResponderRevisao" enctype="multipart/form-data">
            <input type="hidden" id="resposta_revisao_id" name="revisao_id">
            <input type="hidden" id="resposta_acao" name="acao">
            
            <div class="modal-body">
                <div id="infoRevisao" class="info-box" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <!-- Informações da revisão serão carregadas aqui -->
                </div>
                
                <div class="form-group">
                    <label for="comentario_revisor">Comentário do Revisor</label>
                    <textarea id="comentario_revisor" name="comentario_revisor" 
                              class="form-control" rows="4" 
                              placeholder="Adicione suas observações..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="arquivos_revisor">Anexar Arquivos</label>
                    <input type="file" id="arquivos_revisor" name="arquivos_revisor[]" 
                           class="form-control" multiple>
                    <small class="form-text">Você pode anexar múltiplos arquivos</small>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalResponderRevisao()">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger" onclick="responderRevisao('recusar')">
                    ❌ Recusar
                </button>
                <button type="button" class="btn btn-success" onclick="responderRevisao('aceitar')">
                    ✅ Aceitar
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    overflow: auto;
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #888;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 20px;
}

.btn-close-modal {
    background: transparent;
    border: none;
    color: white;
    font-size: 28px;
    cursor: pointer;
    line-height: 1;
    padding: 0;
    width: 30px;
    height: 30px;
}

.btn-close-modal:hover {
    opacity: 0.7;
}

.modal-body {
    padding: 25px;
}

.modal-footer {
    padding: 15px 25px;
    background: #f8f9fa;
    border-radius: 0 0 12px 12px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.info-box {
    font-size: 14px;
    line-height: 1.6;
}

.info-box strong {
    color: #333;
}
</style>

<script>
// Variável global para controle
let itemAtualRevisao = null;

// Abrir modal de revisão
function abrirModalRevisao(itemId, tipo) {
    itemAtualRevisao = { id: itemId, tipo: tipo };
    document.getElementById('revisao_item_id').value = itemId;
    document.getElementById('revisao_tipo').value = tipo;
    document.getElementById('modalRevisao').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Fechar modal de revisão
function fecharModalRevisao() {
    document.getElementById('modalRevisao').style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('formRevisao').reset();
    itemAtualRevisao = null;
}

// Enviar para revisão
async function enviarParaRevisao() {
    const revisorId = document.getElementById('revisor_id').value;
    
    if (!revisorId) {
        alert('Por favor, selecione um revisor!');
        return;
    }
    
    if (!itemAtualRevisao) {
        alert('Erro: item não identificado!');
        return;
    }
    
    const formData = new FormData();
    formData.append(itemAtualRevisao.tipo + '_id', itemAtualRevisao.id);
    formData.append('enviar_revisao', 'sim');
    formData.append('revisor_id', revisorId);
    formData.append('comentario_revisao', document.getElementById('comentario_revisao').value);
    
    // Adicionar arquivos
    const arquivos = document.getElementById('arquivos_revisao').files;
    for (let i = 0; i < arquivos.length; i++) {
        formData.append('arquivos_revisao[]', arquivos[i]);
    }
    
    try {
        const url = itemAtualRevisao.tipo === 'tarefa' 
            ? 'formularios/concluir_tarefa.php'
            : 'formularios/concluir_prazo.php';
            
        const response = await fetch(url, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message || 'Enviado para revisão com sucesso!');
            fecharModalRevisao();
            location.reload();
        } else {
            alert('Erro: ' + (result.message || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao enviar para revisão!');
    }
}

// Abrir modal de responder revisão
async function abrirModalResponderRevisao(revisaoId) {
    document.getElementById('resposta_revisao_id').value = revisaoId;
    
    try {
        // Buscar dados da revisão
        const response = await fetch(`formularios/buscar_revisao.php?revisao_id=${revisaoId}`);
        const result = await response.json();
        
        if (result.success) {
            const rev = result.revisao;
            
            // Montar HTML com informações
            let html = `
                <div style="margin-bottom: 15px;">
                    <strong>📋 Tarefa/Prazo:</strong> ${escapeHtml(rev.titulo_origem)}
                </div>
            `;
            
            if (rev.numero_processo) {
                html += `
                    <div style="margin-bottom: 15px;">
                        <strong>⚖️ Processo:</strong> ${escapeHtml(rev.numero_processo)}
                    </div>
                `;
            }
            
            html += `
                <div style="margin-bottom: 15px;">
                    <strong>👤 Solicitante:</strong> ${escapeHtml(rev.solicitante_nome)}
                </div>
                <div style="margin-bottom: 15px;">
                    <strong>📅 Data da Solicitação:</strong> ${formatarDataHora(rev.data_solicitacao)}
                </div>
            `;
            
            if (rev.comentario_solicitante) {
                html += `
                    <div style="margin-bottom: 15px;">
                        <strong>💬 Comentário do Solicitante:</strong>
                        <div style="background: white; padding: 10px; border-radius: 6px; margin-top: 5px; border-left: 3px solid #667eea;">
                            ${escapeHtml(rev.comentario_solicitante).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            }
            
            if (rev.arquivos_solicitante_array && rev.arquivos_solicitante_array.length > 0) {
                html += `
                    <div style="margin-bottom: 15px;">
                        <strong>📎 Arquivos Anexados:</strong>
                        <ul style="margin: 5px 0 0 20px;">
                `;
                
                rev.arquivos_solicitante_array.forEach(arq => {
                    html += `
                        <li>
                            <a href="../../../${arq.caminho}" target="_blank" 
                               style="color: #667eea; text-decoration: none;">
                                📄 ${escapeHtml(arq.nome)}
                            </a>
                        </li>
                    `;
                });
                
                html += `
                        </ul>
                    </div>
                `;
            }
            
            if (rev.descricao_origem) {
                html += `
                    <div style="margin-bottom: 15px;">
                        <strong>📝 Descrição Original:</strong>
                        <div style="background: white; padding: 10px; border-radius: 6px; margin-top: 5px; max-height: 150px; overflow-y: auto;">
                            ${escapeHtml(rev.descricao_origem).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('infoRevisao').innerHTML = html;
            
            document.getElementById('modalResponderRevisao').style.display = 'block';
            document.body.style.overflow = 'hidden';
        } else {
            alert('Erro ao carregar revisão: ' + result.message);
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao buscar dados da revisão');
    }
}

// Função auxiliar para escapar HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Função auxiliar para formatar data/hora
function formatarDataHora(dataStr) {
    if (!dataStr) return '';
    const data = new Date(dataStr);
    return data.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Fechar modal de responder revisão
function fecharModalResponderRevisao() {
    document.getElementById('modalResponderRevisao').style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('formResponderRevisao').reset();
}

// Responder revisão
async function responderRevisao(acao) {
    const revisaoId = document.getElementById('resposta_revisao_id').value;
    
    if (!revisaoId) {
        alert('Erro: revisão não identificada!');
        return;
    }
    
    const confirmar = acao === 'aceitar'
        ? confirm('Tem certeza que deseja ACEITAR esta revisão?')
        : confirm('Tem certeza que deseja RECUSAR esta revisão?');
    
    if (!confirmar) return;
    
    const formData = new FormData();
    formData.append('revisao_id', revisaoId);
    formData.append('acao', acao);
    formData.append('comentario_revisor', document.getElementById('comentario_revisor').value);
    
    // Adicionar arquivos
    const arquivos = document.getElementById('arquivos_revisor').files;
    for (let i = 0; i < arquivos.length; i++) {
        formData.append('arquivos_revisor[]', arquivos[i]);
    }
    
    try {
        const response = await fetch('formularios/responder_revisao.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message || 'Revisão respondida com sucesso!');
            fecharModalResponderRevisao();
            location.reload();
        } else {
            alert('Erro: ' + (result.message || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao responder revisão!');
    }
}

// Fechar modais ao clicar fora
window.onclick = function(event) {
    const modalRevisao = document.getElementById('modalRevisao');
    const modalResponder = document.getElementById('modalResponderRevisao');
    
    if (event.target === modalRevisao) {
        fecharModalRevisao();
    }
    if (event.target === modalResponder) {
        fecharModalResponderRevisao();
    }
}
</script>
<!-- Modal: Pergunta sobre Revisão (Popup Interno) -->
<div id="modalPerguntaRevisao" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <h3>📋 Enviar para Revisão?</h3>
        </div>
        
        <div class="modal-body" style="padding: 30px; text-align: center;">
            <div style="font-size: 48px; margin-bottom: 20px;">
                🤔
            </div>
            <p style="font-size: 18px; color: #333; margin-bottom: 10px;">
                <strong>Deseja enviar esta tarefa para revisão?</strong>
            </p>
            <p style="font-size: 14px; color: #666;">
                Se sim, você poderá selecionar um revisor, adicionar comentários e anexar arquivos.
            </p>
        </div>
        
        <div class="modal-footer" style="padding: 20px; justify-content: center; gap: 15px;">
            <button type="button" class="btn btn-secondary" onclick="responderPerguntaRevisao(false)" style="min-width: 120px;">
                ❌ Não
            </button>
            <button type="button" class="btn btn-primary" onclick="responderPerguntaRevisao(true)" style="min-width: 120px;">
                ✅ Sim, Enviar
            </button>
        </div>
    </div>
</div>

<script>
// Variáveis globais para controlar o fluxo
let perguntaRevisaoCallback = null;
let perguntaRevisaoItem = null;

// Abrir modal de pergunta
function abrirModalPerguntaRevisao(itemId, tipo, callback) {
    perguntaRevisaoItem = { id: itemId, tipo: tipo };
    perguntaRevisaoCallback = callback;
    document.getElementById('modalPerguntaRevisao').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Responder pergunta
function responderPerguntaRevisao(enviar) {
    document.getElementById('modalPerguntaRevisao').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    if (perguntaRevisaoCallback) {
        perguntaRevisaoCallback(enviar, perguntaRevisaoItem);
    }
    
    perguntaRevisaoCallback = null;
    perguntaRevisaoItem = null;
}

// Fechar ao clicar fora
window.addEventListener('click', function(event) {
    const modal = document.getElementById('modalPerguntaRevisao');
    if (event.target === modal) {
        responderPerguntaRevisao(false);
    }
});
</script>

<style>
/* Melhorias no CSS dos modais */
.modal {
    animation: fadeIn 0.2s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.modal-content {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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

/* Ajustes no form-control */
.form-control {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.required {
    color: #dc3545;
}

.form-text {
    display: block;
    margin-top: 5px;
    font-size: 12px;
    color: #6c757d;
}
</style>
