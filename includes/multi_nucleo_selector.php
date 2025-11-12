<?php
function renderMultiNucleoSelector($usuario_logado) {
    // Buscar núcleos do usuário
    $sql = "SELECT n.* FROM nucleos n 
            INNER JOIN usuarios_nucleos un ON n.id = un.nucleo_id 
            WHERE un.usuario_id = ? AND n.ativo = 1 
            ORDER BY n.nome";
    $stmt = executeQuery($sql, [$usuario_logado['usuario_id']]);
    $nucleos_usuario = $stmt->fetchAll();
    
    if (empty($nucleos_usuario)) {
        return '';
    }
    
    // Obter núcleos selecionados da sessão (padrão: todos)
    $nucleos_selecionados = $_SESSION['nucleos_selecionados'] ?? array_column($nucleos_usuario, 'id');
    
    ob_start();
    ?>
    <div class="multi-nucleo-selector" id="multiNucleoSelector">
        <div class="selector-header" onclick="toggleMultiNucleoDropdown()">
            <span class="selector-icon">🏢</span>
            <span class="selector-text">
                <?php if (count($nucleos_selecionados) == count($nucleos_usuario)): ?>
                    Todos os Núcleos (<?= count($nucleos_usuario) ?>)
                <?php elseif (count($nucleos_selecionados) == 1): ?>
                    <?php
                    $nucleo_unico = array_filter($nucleos_usuario, function($n) use ($nucleos_selecionados) {
                        return in_array($n['id'], $nucleos_selecionados);
                    });
                    $nucleo_unico = reset($nucleo_unico);
                    echo htmlspecialchars($nucleo_unico['nome']);
                    ?>
                <?php else: ?>
                    <?= count($nucleos_selecionados) ?> Núcleos Selecionados
                <?php endif; ?>
            </span>
            <span class="dropdown-arrow">▼</span>
        </div>
        
        <div class="multi-nucleo-dropdown" id="multiNucleoDropdown">
            <div class="dropdown-header">
                <h4>Selecionar Núcleos</h4>
                <div class="header-actions">
                    <button type="button" onclick="selectAllNucleos()" class="btn-select-all">Todos</button>
                    <button type="button" onclick="clearAllNucleos()" class="btn-clear-all">Limpar</button>
                </div>
            </div>
            
            <form id="nucleosForm" action="<?= SITE_URL ?>/modules/dashboard/process_nucleos.php" method="POST">
                <div class="nucleos-list">
                    <?php foreach ($nucleos_usuario as $nucleo): ?>
                    <label class="nucleo-checkbox-item">
                        <input type="checkbox" 
                               name="nucleos_selecionados[]" 
                               value="<?= $nucleo['id'] ?>"
                               <?= in_array($nucleo['id'], $nucleos_selecionados) ? 'checked' : '' ?>
                               onchange="updateNucleoSelection()">
                        <span class="nucleo-icon" data-nucleo="<?= htmlspecialchars($nucleo['nome']) ?>"></span>
                        <div class="nucleo-info">
                            <div class="nucleo-nome"><?= htmlspecialchars($nucleo['nome']) ?></div>
                            <div class="nucleo-desc"><?= htmlspecialchars($nucleo['descricao']) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <div class="dropdown-footer">
                    <button type="submit" class="btn-apply">Aplicar Seleção</button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
        .multi-nucleo-selector {
            position: relative;
            display: inline-block;
            margin-right: 20px;
        }
        
        .selector-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
            font-weight: 600;
            font-size: 14px;
            min-width: 200px;
        }
        
        .selector-header:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .selector-icon {
            font-size: 16px;
        }
        
        .selector-text {
            flex: 1;
            text-align: left;
        }
        
        .dropdown-arrow {
            font-size: 10px;
            transition: transform 0.3s;
        }
        
        .multi-nucleo-selector.open .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .multi-nucleo-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 12px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            min-width: 350px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .multi-nucleo-selector.open .multi-nucleo-dropdown {
            display: block;
        }
        
        .dropdown-header {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dropdown-header h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-select-all,
        .btn-clear-all {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-select-all:hover,
        .btn-clear-all:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .nucleos-list {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px 0;
        }
        
        .nucleo-checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            cursor: pointer;
            transition: background 0.3s;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .nucleo-checkbox-item:hover {
            background: rgba(26,26,26,0.05);
        }
        
        .nucleo-checkbox-item:last-child {
            border-bottom: none;
        }
        
        .nucleo-checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
        }
        
        .nucleo-info {
            flex: 1;
        }
        
        .nucleo-nome {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
            color: #333;
        }
        
        .nucleo-desc {
            font-size: 12px;
            color: #666;
            line-height: 1.3;
        }
        
        .dropdown-footer {
            background: #f8f9fa;
            padding: 15px 20px;
            border-top: 1px solid #ddd;
        }
        
        .btn-apply {
            width: 100%;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-apply:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        /* Ícones específicos por núcleo */
        .nucleo-icon[data-nucleo="Bancário"]::before { content: "��"; }
        .nucleo-icon[data-nucleo="Criminal"]::before { content: "⚖️"; }
        .nucleo-icon[data-nucleo="Família"]::before { content: "👨‍👩‍👧‍👦"; }
        .nucleo-icon[data-nucleo="Previdenciário"]::before { content: "🏛️"; }
        .nucleo-icon[data-nucleo="Propriedade"]::before { content: "🏠"; }
        .nucleo-icon[data-nucleo="Público"]::before { content: "🏛️"; }
        .nucleo-icon[data-nucleo="Responsabilidade"]::before { content: "🛡️"; }
        .nucleo-icon[data-nucleo="Trabalhista"]::before { content: "👷"; }
    </style>
    
    <script>
        function toggleMultiNucleoDropdown() {
            const selector = document.getElementById('multiNucleoSelector');
            selector.classList.toggle('open');
        }
        
        function selectAllNucleos() {
            const checkboxes = document.querySelectorAll('#nucleosForm input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = true);
            updateNucleoSelection();
        }
        
        function clearAllNucleos() {
            const checkboxes = document.querySelectorAll('#nucleosForm input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateNucleoSelection();
        }
        
        function updateNucleoSelection() {
            const checkboxes = document.querySelectorAll('#nucleosForm input[type="checkbox"]');
            const totalCheckboxes = checkboxes.length;
            const checkedCheckboxes = document.querySelectorAll('#nucleosForm input[type="checkbox"]:checked');
            const totalChecked = checkedCheckboxes.length;
            
            const selectorText = document.querySelector('.selector-text');
            
            if (totalChecked === 0) {
                selectorText.textContent = 'Nenhum Núcleo Selecionado';
            } else if (totalChecked === totalCheckboxes) {
                selectorText.textContent = `Todos os Núcleos (${totalCheckboxes})`;
            } else if (totalChecked === 1) {
                const checkedLabel = checkedCheckboxes[0].closest('.nucleo-checkbox-item').querySelector('.nucleo-nome').textContent;
                selectorText.textContent = checkedLabel;
            } else {
                selectorText.textContent = `${totalChecked} Núcleos Selecionados`;
            }
        }
        
        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(e) {
            const selector = document.getElementById('multiNucleoSelector');
            if (!selector.contains(e.target)) {
                selector.classList.remove('open');
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
?>