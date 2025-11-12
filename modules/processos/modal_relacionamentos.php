<style>
/* ============================================================================
   CSS MODAL DE RELACIONAMENTOS - VERSÃO COMPLETA E BONITA
   ============================================================================ */

/* Modal Container */
#modalRelacionamentos {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    z-index: 99999 !important;
    overflow: auto !important;
    background: rgba(0, 0, 0, 0.6) !important;
}

#modalRelacionamentos.show {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Modal Dialog */
#modalRelacionamentos .modal-dialog {
    position: relative !important;
    z-index: 100000 !important;
    margin: 30px auto !important;
    max-width: 900px !important;
    width: 95% !important;
}

/* Modal Content */
#modalRelacionamentos .modal-content {
    background: white !important;
    border-radius: 15px !important;
    box-shadow: 0 10px 50px rgba(0,0,0,0.3) !important;
    position: relative !important;
    z-index: 100001 !important;
    border: none !important;
}

/* Modal Header */
.modal-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
    color: white !important;
    border-radius: 15px 15px 0 0 !important;
    border-bottom: none !important;
    padding: 25px 30px !important;
}

.modal-header h5 {
    font-size: 20px !important;
    font-weight: 700 !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
}

.modal-header .close {
    position: absolute !important;
    right: 25px !important;
    top: 25px !important;
    font-size: 32px !important;
    font-weight: 300 !important;
    line-height: 1 !important;
    color: white !important;
    opacity: 1 !important;
    cursor: pointer !important;
    background: none !important;
    border: none !important;
    padding: 0 !important;
    z-index: 10 !important;
    transition: all 0.3s !important;
}

.modal-header .close:hover {
    transform: rotate(90deg) !important;
    opacity: 0.8 !important;
}

/* Modal Body */
.modal-body {
    padding: 30px !important;
    max-height: 70vh !important;
    overflow-y: auto !important;
}

.modal-body h6 {
    color: #1a1a1a !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    margin-bottom: 20px !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

/* Form Groups */
.form-group {
    margin-bottom: 20px !important;
}

.form-group label {
    display: block !important;
    margin-bottom: 8px !important;
    color: #333 !important;
    font-weight: 600 !important;
    font-size: 14px !important;
}

.form-control {
    width: 100% !important;
    padding: 12px 16px !important;
    border: 2px solid #e9ecef !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    transition: all 0.3s !important;
    background: #f8f9fa !important;
}

.form-control:focus {
    outline: none !important;
    border-color: #007bff !important;
    background: white !important;
    box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.1) !important;
}

.form-control::placeholder {
    color: #999 !important;
}

.form-text {
    display: block !important;
    margin-top: 6px !important;
    font-size: 12px !important;
    color: #6c757d !important;
}

/* Select */
select.form-control {
    cursor: pointer !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 16px center !important;
    padding-right: 40px !important;
}

/* Textarea */
textarea.form-control {
    resize: vertical !important;
    min-height: 80px !important;
}

/* Botão Submit */
.btn-primary {
    width: 100% !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
    color: white !important;
    border: none !important;
    border-radius: 8px !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.3s !important;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

.btn-primary:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(40, 167, 69, 0.4) !important;
}

/* Divider */
hr {
    border: none !important;
    border-top: 2px solid #e9ecef !important;
    margin: 30px 0 !important;
}

/* Resultados da Busca */
#resultados_busca_modal {
    max-height: 250px !important;
    overflow-y: auto !important;
    border: 2px solid #e3f2fd !important;
    border-radius: 10px !important;
    background: white !important;
    margin-top: 10px !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
}

.resultado-busca-item {
    padding: 14px 16px !important;
    border-bottom: 1px solid #f0f0f0 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    position: relative !important;
}

.resultado-busca-item::before {
    content: '' !important;
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    bottom: 0 !important;
    width: 0 !important;
    background: linear-gradient(180deg, #007bff 0%, #0056b3 100%) !important;
    transition: width 0.3s !important;
}

.resultado-busca-item:hover {
    background: #f0f7ff !important;
    padding-left: 20px !important;
}

.resultado-busca-item:hover::before {
    width: 4px !important;
}

.resultado-busca-item:last-child {
    border-bottom: none !important;
}

.resultado-busca-item strong {
    display: block !important;
    color: #1a1a1a !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    margin-bottom: 4px !important;
}

.resultado-busca-item small {
    color: #666 !important;
    font-size: 13px !important;
}

/* Lista de Relacionamentos */
#lista_relacionamentos_modal {
    max-height: 300px !important;
    overflow-y: auto !important;
}

.relacionamento-item {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
    border: 2px solid #dee2e6 !important;
    border-radius: 10px !important;
    padding: 18px !important;
    margin-bottom: 12px !important;
    transition: all 0.3s !important;
}

.relacionamento-item:hover {
    transform: translateX(4px) !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
    border-color: #007bff !important;
}

.relacionamento-item:last-child {
    margin-bottom: 0 !important;
}

.relacionamento-item strong {
    display: block !important;
    color: #1a1a1a !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    margin-bottom: 8px !important;
}

.relacionamento-item .badge {
    display: inline-block !important;
    padding: 4px 10px !important;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
    color: white !important;
    border-radius: 12px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    margin-right: 6px !important;
}

/* Modal Footer */
.modal-footer {
    border-top: 2px solid #e9ecef !important;
    padding: 20px 30px !important;
    background: #f8f9fa !important;
    border-radius: 0 0 15px 15px !important;
}

.btn-secondary {
    padding: 10px 20px !important;
    background: #6c757d !important;
    color: white !important;
    border: none !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.3s !important;
}

.btn-secondary:hover {
    background: #5a6268 !important;
    transform: translateY(-1px) !important;
}

/* Backdrop */
.modal-backdrop {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0, 0, 0, 0.6) !important;
    z-index: 99998 !important;
}

/* Loading */
.text-center {
    text-align: center !important;
}

.text-muted {
    color: #6c757d !important;
}

.py-3 {
    padding-top: 20px !important;
    padding-bottom: 20px !important;
}

.fa-spinner {
    animation: spin 1s linear infinite !important;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Scrollbar customizada */
#resultados_busca_modal::-webkit-scrollbar,
#lista_relacionamentos_modal::-webkit-scrollbar,
.modal-body::-webkit-scrollbar {
    width: 8px !important;
}

#resultados_busca_modal::-webkit-scrollbar-track,
#lista_relacionamentos_modal::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track {
    background: #f1f1f1 !important;
    border-radius: 10px !important;
}

#resultados_busca_modal::-webkit-scrollbar-thumb,
#lista_relacionamentos_modal::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #007bff 0%, #0056b3 100%) !important;
    border-radius: 10px !important;
}

#resultados_busca_modal::-webkit-scrollbar-thumb:hover,
#lista_relacionamentos_modal::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover {
    background: #0056b3 !important;
}

/* Responsivo */
@media (max-width: 768px) {
    #modalRelacionamentos .modal-dialog {
        width: 98% !important;
        margin: 10px auto !important;
    }
    
    .modal-header {
        padding: 20px !important;
    }
    
    .modal-body {
        padding: 20px !important;
    }
    
    .modal-header h5 {
        font-size: 18px !important;
    }
}
</style>

<!-- Modal de Relacionamentos -->
<div class="modal fade" id="modalRelacionamentos" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-link"></i> Gerenciar Relacionamentos
                </h5>
                <button type="button" class="close" onclick="fecharModalRelacionamento()">
                    <span>&times;</span>
                </button>
            </div>
            
            <div class="modal-body">
                <input type="hidden" id="processo_id_modal" value="">
                
                <h6 class="mb-3">📋 Adicionar Novo Relacionamento</h6>
                
                <form id="formAdicionarRelacionamento" onsubmit="event.preventDefault(); adicionarRelacionamento();">
                    <div class="form-group">
                        <label for="tipo_relacionamento">Tipo de Relacionamento:</label>
                        <select class="form-control" id="tipo_relacionamento" required>
                            <option value="">Selecione...</option>
                            <option value="Agravo de Instrumento">Agravo de Instrumento</option>
                            <option value="Recurso de Apelação">Recurso de Apelação</option>
                            <option value="Embargos de Declaração">Embargos de Declaração</option>
                            <option value="Recurso Especial">Recurso Especial</option>
                            <option value="Recurso Extraordinário">Recurso Extraordinário</option>
                            <option value="Cumprimento de Sentença">Cumprimento de Sentença</option>
                            <option value="Execução">Execução</option>
                            <option value="Cautelar">Cautelar</option>
                            <option value="Conexo">Conexo</option>
                            <option value="Incidente">Incidente</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="busca_processo_destino">🔍 Buscar Processo:</label>
                        <input type="text" 
                               class="form-control" 
                               id="busca_processo_destino" 
                               placeholder="Digite o número do processo..."
                               autocomplete="off">
                        <small class="form-text text-muted">Digite pelo menos 3 caracteres</small>
                    </div>
                    
                    <div id="resultados_busca_modal" style="display: none;"></div>
                    
                    <input type="hidden" id="processo_destino_id">
                    
                    <div class="form-group">
                        <label for="descricao_relacionamento">📝 Descrição (opcional):</label>
                        <textarea class="form-control" 
                                  id="descricao_relacionamento" 
                                  rows="2"
                                  placeholder="Observações..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-plus"></i> Adicionar Relacionamento
                    </button>
                </form>
                
                <hr class="my-4">
                
                <h6 class="mb-3">🔗 Relacionamentos Existentes</h6>
                <div id="lista_relacionamentos_modal">
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-spinner fa-spin"></i> Carregando...
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModalRelacionamento()">
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Modal de relacionamentos carregado');
    
    // ✅ GARANTIR QUE O MODAL COMECE FECHADO
    const modal = document.getElementById('modalRelacionamentos');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        console.log('✅ Modal garantido como fechado');
    }
    
    const buscaInput = document.getElementById('busca_processo_destino');
    if (buscaInput) {
        buscaInput.addEventListener('input', function() {
            const termo = this.value.trim();
            if (termo.length >= 3) {
                buscarProcessosModal(termo);
            } else {
                document.getElementById('resultados_busca_modal').style.display = 'none';
            }
        });
    }
});

function buscarProcessosModal(termo) {
    fetch(`api_relacionamentos.php?action=buscar&termo=${encodeURIComponent(termo)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro na requisição: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            const resultadosDiv = document.getElementById('resultados_busca_modal');
            resultadosDiv.innerHTML = '';
            
            // Verificar se data é array
            if (!Array.isArray(data)) {
                console.error('Resposta não é um array:', data);
                resultadosDiv.innerHTML = '<div class="text-center text-danger p-3">❌ Erro ao buscar processos</div>';
                resultadosDiv.style.display = 'block';
                return;
            }
            
            if (data.length === 0) {
                resultadosDiv.innerHTML = '<div class="text-center text-muted p-3">📋 Nenhum processo encontrado</div>';
            } else {
                data.forEach(processo => {
                    const item = document.createElement('div');
                    item.className = 'resultado-busca-item';
                    item.innerHTML = `
                        <strong>${processo.numero_processo || 'Sem número'}</strong>
                        <small>${processo.cliente_nome || 'Sem cliente'}</small>
                    `;
                    item.onclick = function() {
                        selecionarProcessoModal(processo);
                    };
                    resultadosDiv.appendChild(item);
                });
            }
            
            resultadosDiv.style.display = 'block';
        })
        .catch(error => {
            console.error('Erro ao buscar processos:', error);
            const resultadosDiv = document.getElementById('resultados_busca_modal');
            resultadosDiv.innerHTML = '<div class="text-center text-danger p-3">❌ Erro: ' + error.message + '</div>';
            resultadosDiv.style.display = 'block';
        });
}

function selecionarProcessoModal(processo) {
    document.getElementById('busca_processo_destino').value = processo.numero_processo;
    document.getElementById('processo_destino_id').value = processo.id;
    document.getElementById('resultados_busca_modal').style.display = 'none';
}

function adicionarRelacionamento() {
    const processoOrigemId = document.getElementById('processo_id_modal').value;
    const processoDestinoId = document.getElementById('processo_destino_id').value;
    const tipoRelacionamento = document.getElementById('tipo_relacionamento').value;
    const descricao = document.getElementById('descricao_relacionamento').value;
    
    if (!processoDestinoId) {
        alert('Por favor, selecione um processo para vincular');
        return;
    }
    
    if (!tipoRelacionamento) {
        alert('Por favor, selecione o tipo de relacionamento');
        return;
    }
    
    fetch('api_relacionamentos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'adicionar',
            processo_origem_id: processoOrigemId,
            processo_destino_id: processoDestinoId,
            tipo_relacionamento: tipoRelacionamento,
            descricao: descricao
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Relacionamento adicionado!');
            location.reload();
        } else {
            alert('❌ Erro: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('❌ Erro ao adicionar relacionamento');
    });
}

// Carregar relacionamentos quando o modal abrir
function carregarRelacionamentosExistentes(processoId) {
    const listaDiv = document.getElementById('lista_relacionamentos_modal');
    listaDiv.innerHTML = '<div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    
    fetch(`api_relacionamentos.php?action=listar&processo_id=${processoId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro ao carregar: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Relacionamentos carregados:', data);
            
            if (!Array.isArray(data)) {
                console.error('Resposta não é um array:', data);
                listaDiv.innerHTML = '<div class="alert alert-danger">Erro ao carregar relacionamentos</div>';
                return;
            }
            
            if (data.length === 0) {
                listaDiv.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-link" style="font-size: 48px; color: #dee2e6; margin-bottom: 10px; display: block;"></i>
                        <p style="margin: 0; font-size: 14px;">Nenhum relacionamento cadastrado</p>
                    </div>
                `;
            } else {
                let html = '';
                
                data.forEach(rel => {
                    const isOrigem = rel.processo_origem_id == processoId;
                    const outroProcesso = isOrigem ? rel.numero_processo_destino : rel.numero_processo_origem;
                    const outroProcessoId = isOrigem ? rel.processo_destino_id : rel.processo_origem_id;
                    const direcao = isOrigem ? '→' : '←';
                    const corBadge = isOrigem ? '#28a745' : '#007bff';
                    
                    html += `
                        <div class="relacionamento-item">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                <div style="flex: 1;">
                                    <strong style="display: flex; align-items: center; gap: 8px;">
                                        <span style="color: ${corBadge}; font-size: 20px;">${direcao}</span>
                                        ${outroProcesso}
                                    </strong>
                                    <span class="badge" style="background: ${corBadge} !important; margin-top: 8px;">
                                        ${rel.tipo_relacionamento}
                                    </span>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <button onclick="window.open('visualizar.php?id=${outroProcessoId}', '_blank')" 
                                            class="btn btn-sm btn-info"
                                            style="padding: 6px 12px; font-size: 12px; border-radius: 6px; background: #17a2b8; border: none; color: white; cursor: pointer;"
                                            title="Ver processo">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button onclick="removerRelacionamento(${rel.id})" 
                                            class="btn btn-sm btn-danger"
                                            style="padding: 6px 12px; font-size: 12px; border-radius: 6px; background: #dc3545; border: none; color: white; cursor: pointer;"
                                            title="Remover">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            ${rel.descricao ? `<small style="color: #666; font-style: italic; display: block; margin-top: 8px;">💬 ${rel.descricao}</small>` : ''}
                        </div>
                    `;
                });
                
                listaDiv.innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Erro ao carregar relacionamentos:', error);
            listaDiv.innerHTML = `
                <div class="text-center text-danger py-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p style="margin: 10px 0 0 0; font-size: 14px;">Erro ao carregar relacionamentos</p>
                    <small>${error.message}</small>
                </div>
            `;
        });
}

// Função para remover relacionamento
function removerRelacionamento(relacionamentoId) {
    if (!confirm('Tem certeza que deseja remover este relacionamento?')) {
        return;
    }
    
    fetch('api_relacionamentos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'remover',
            relacionamento_id: relacionamentoId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Relacionamento removido!');
            const processoId = document.getElementById('processo_id_modal').value;
            carregarRelacionamentosExistentes(processoId);
        } else {
            alert('❌ Erro: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('❌ Erro ao remover relacionamento');
    });
}
</script>