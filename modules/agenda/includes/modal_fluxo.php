<!-- Modal de Conclusão de Tarefa -->
<div class="modal fade" id="modalFluxo" tabindex="-1" aria-labelledby="modalFluxoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 15px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div class="modal-header" style="border-bottom: 2px solid rgba(0,0,0,0.05); padding: 20px 25px;">
                <h5 class="modal-title" id="modalFluxoLabel" style="font-weight: 700; color: #1a1a1a;">
                    <i class="fas fa-share-nodes" style="color: #667eea; margin-right: 10px;"></i>
                    Tarefa concluída com sucesso!
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            
            <div class="modal-body" style="padding: 25px;">
                <div id="successMessage" class="alert alert-success" style="display: none; border-left: 4px solid #28a745; background: rgba(40, 167, 69, 0.1);">
                    <i class="fas fa-check-circle" style="color: #28a745; margin-right: 8px;"></i>
                    <strong>Ótimo trabalho!</strong> A tarefa foi marcada como concluída.
                </div>
                
                <div id="fluxoInfo" style="display: none;">
                    <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); 
                                border-radius: 10px; padding: 20px; margin-top: 15px;">
                        <h6 style="font-weight: 700; color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-arrow-right-arrow-left"></i> Fluxo Automático Ativado
                        </h6>
                        <p style="margin-bottom: 10px; color: #666;">
                            Uma nova tarefa foi criada e enviada para:
                        </p>
                        <div style="background: white; border-radius: 8px; padding: 15px; margin-top: 10px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 40px; height: 40px; border-radius: 50%; 
                                           background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                           display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-user" style="color: white;"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #1a1a1a;" id="fluxoResponsavel"></div>
                                    <div style="font-size: 13px; color: #666;">Próximo responsável</div>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 15px; padding: 12px; background: rgba(255, 193, 7, 0.1); 
                                   border-left: 3px solid #ffc107; border-radius: 6px;">
                            <small style="color: #856404;">
                                <i class="fas fa-info-circle"></i>
                                A nova tarefa foi criada automaticamente conforme o fluxo configurado.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" style="border-top: 2px solid rgba(0,0,0,0.05); padding: 15px 25px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Fechar
                </button>
                <button type="button" class="btn btn-primary" onclick="window.location.reload()">
                    <i class="fas fa-sync"></i> Atualizar Lista
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    font-weight: 600;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    font-weight: 600;
}
</style>

<script>
// Limpar modal quando fechar
document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.getElementById('modalFluxo');
    if (modalElement) {
        modalElement.addEventListener('hidden.bs.modal', function () {
            const successMsg = document.getElementById('successMessage');
            const fluxoInfo = document.getElementById('fluxoInfo');
            if (successMsg) successMsg.style.display = 'none';
            if (fluxoInfo) fluxoInfo.style.display = 'none';
        });
    }
});
</script>