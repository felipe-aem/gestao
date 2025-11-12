<!-- ====================================== -->
<!-- SEÇÃO DE FLUXO DE TRABALHO -->
<!-- Adicione isso no form_tarefa.php, após os campos principais -->
<!-- ====================================== -->

<div class="form-group form-group-full" style="margin-top: 20px;">
    <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
                border-radius: 12px; padding: 20px; border: 2px dashed #667eea;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
            <h6 style="margin: 0; font-weight: 700; color: #667eea;">
                <i class="fas fa-share-nodes"></i> Fluxo de Trabalho (Opcional)
            </h6>
            <button type="button" class="btn-toggle-fluxo" onclick="toggleFluxo()" 
                    style="background: transparent; border: none; color: #667eea; cursor: pointer; font-size: 12px;">
                <i class="fas fa-chevron-down"></i> Expandir
            </button>
        </div>
        
        <div id="fluxoContainer" style="display: none;">
            <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                <i class="fas fa-info-circle" style="color: #667eea;"></i>
                Configure para onde a tarefa deve ir após ser concluída
            </p>
            
            <div class="row">
                <div class="col-md-6">
                    <label for="enviar_para_usuario_id" style="font-weight: 600; font-size: 13px; margin-bottom: 8px;">
                        Enviar para (próximo responsável)
                    </label>
                    <select class="form-control" id="enviar_para_usuario_id" name="enviar_para_usuario_id">
                        <option value="">Ninguém (não criar próxima tarefa)</option>
                        <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>">
                            <?= htmlspecialchars($usuario['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #666; font-size: 11px; display: block; margin-top: 5px;">
                        Ao concluir, cria tarefa para este usuário
                    </small>
                </div>
                
                <div class="col-md-6">
                    <label for="fluxo_tipo" style="font-weight: 600; font-size: 13px; margin-bottom: 8px;">
                        Tipo de Fluxo
                    </label>
                    <select class="form-control" id="fluxo_tipo" name="fluxo_tipo">
                        <option value="">Selecione o tipo</option>
                        <option value="revisao">📋 Revisão</option>
                        <option value="aprovacao">✅ Aprovação</option>
                        <option value="execucao">⚙️ Execução</option>
                        <option value="analise">🔍 Análise</option>
                        <option value="outro">➡️ Outro</option>
                    </select>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label for="fluxo_instrucao" style="font-weight: 600; font-size: 13px; margin-bottom: 8px;">
                    Instruções para o próximo responsável
                </label>
                <textarea class="form-control" id="fluxo_instrucao" name="fluxo_instrucao" 
                          rows="3" placeholder="Ex: Por favor, revise os dados e aprove..."></textarea>
                <small style="color: #666; font-size: 11px; display: block; margin-top: 5px;">
                    Esta mensagem será mostrada na próxima tarefa criada
                </small>
            </div>
            
            <div style="margin-top: 15px; padding: 12px; background: rgba(255, 193, 7, 0.1); 
                       border-left: 3px solid #ffc107; border-radius: 6px;">
                <small style="color: #856404;">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Como funciona:</strong> Quando você concluir esta tarefa, o sistema 
                    criará automaticamente uma nova tarefa para o usuário selecionado com as 
                    instruções que você definir.
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.btn-toggle-fluxo {
    transition: all 0.3s;
}

.btn-toggle-fluxo.active i {
    transform: rotate(180deg);
}

#fluxoContainer {
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 500px;
    }
}
</style>

<script>
function toggleFluxo() {
    const container = document.getElementById('fluxoContainer');
    const btn = document.querySelector('.btn-toggle-fluxo');
    
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.classList.add('active');
        btn.innerHTML = '<i class="fas fa-chevron-up"></i> Recolher';
    } else {
        container.style.display = 'none';
        btn.classList.remove('active');
        btn.innerHTML = '<i class="fas fa-chevron-down"></i> Expandir';
    }
}

// Validação: se selecionar usuário, tipo é obrigatório
document.getElementById('enviar_para_usuario_id')?.addEventListener('change', function() {
    const tipoSelect = document.getElementById('fluxo_tipo');
    if (this.value) {
        tipoSelect.required = true;
        tipoSelect.parentElement.querySelector('label').innerHTML = 
            'Tipo de Fluxo <span style="color: #dc3545;">*</span>';
    } else {
        tipoSelect.required = false;
        tipoSelect.parentElement.querySelector('label').innerHTML = 'Tipo de Fluxo';
    }
});
</script>