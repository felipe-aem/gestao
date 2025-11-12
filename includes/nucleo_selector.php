<?php
require_once dirname(__DIR__) . '/includes/nucleo_manager.php';

function renderNucleoSelector($usuario_logado) {
    $nucleo_ativo = NucleoManager::getDadosNucleoAtivo();
    $nucleos_usuario = NucleoManager::getNucleosUsuario($usuario_logado['usuario_id']);
    
    if (count($nucleos_usuario) <= 1) {
        return ''; // Não mostrar se tem apenas um núcleo
    }
    
    ob_start();
    ?>
    <div class="nucleo-selector" id="nucleoSelector">
        <div class="nucleo-atual" onclick="toggleNucleoDropdown()">
            <?php if ($nucleo_ativo): ?>
                <span class="nucleo-icon" data-nucleo="<?= htmlspecialchars($nucleo_ativo['nome']) ?>"></span>
                <span class="nucleo-nome"><?= htmlspecialchars($nucleo_ativo['nome']) ?></span>
            <?php else: ?>
                <span class="nucleo-icon">🏢</span>
                <span class="nucleo-nome">Selecionar Núcleo</span>
            <?php endif; ?>
            <span class="dropdown-arrow">▼</span>
        </div>
        
        <div class="nucleo-dropdown" id="nucleoDropdown">
            <?php foreach ($nucleos_usuario as $nucleo): ?>
            <a href="<?= SITE_URL ?>/modules/dashboard/process_selecionar_nucleo.php?nucleo=<?= $nucleo['id'] ?>" 
               class="nucleo-option <?= $nucleo_ativo && $nucleo['id'] == $nucleo_ativo['id'] ? 'active' : '' ?>"
               data-nucleo="<?= htmlspecialchars($nucleo['nome']) ?>">
                <span class="nucleo-icon"></span>
                <div class="nucleo-info">
                    <div class="nucleo-nome"><?= htmlspecialchars($nucleo['nome']) ?></div>
                    <div class="nucleo-desc"><?= htmlspecialchars($nucleo['descricao']) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            
            <div class="nucleo-divider"></div>
            <a href="<?= SITE_URL ?>/modules/dashboard/selecionar_nucleo.php" class="nucleo-option">
                <span class="nucleo-icon">🔄</span>
                <div class="nucleo-info">
                    <div class="nucleo-nome">Trocar Núcleo</div>
                    <div class="nucleo-desc">Ver todos os núcleos disponíveis</div>
                </div>
            </a>
        </div>
    </div>
    
    <style>
        .nucleo-selector {
            position: relative;
            display: inline-block;
            margin-right: 20px;
        }
        
        .nucleo-atual {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .nucleo-atual:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .nucleo-icon {
            font-size: 16px;
        }
        
        .dropdown-arrow {
            font-size: 10px;
            transition: transform 0.3s;
        }
        
        .nucleo-selector.open .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .nucleo-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            min-width: 300px;
            margin-top: 5px;
        }
        
        .nucleo-selector.open .nucleo-dropdown {
            display: block;
        }
        
        .nucleo-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            text-decoration: none;
            color: #333;
            transition: background 0.3s;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .nucleo-option:hover {
            background: rgba(26,26,26,0.05);
        }
        
        .nucleo-option.active {
            background: rgba(26,26,26,0.1);
            font-weight: 600;
        }
        
        .nucleo-option:last-child {
            border-bottom: none;
        }
        
        .nucleo-info {
            flex: 1;
        }
        
        .nucleo-option .nucleo-nome {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .nucleo-desc {
            font-size: 12px;
            color: #666;
        }
        
        .nucleo-divider {
            height: 1px;
            background: #ddd;
            margin: 5px 0;
        }
        
        /* Ícones específicos por núcleo */
        .nucleo-icon[data-nucleo="Bancário"]::before { content: "🏦"; }
        .nucleo-icon[data-nucleo="Criminal"]::before { content: "⚖️"; }
        .nucleo-icon[data-nucleo="Família"]::before { content: "👨‍👩‍👧‍👦"; }
        .nucleo-icon[data-nucleo="Previdenciário"]::before { content: "🏛️"; }
        .nucleo-icon[data-nucleo="Propriedade"]::before { content: "��"; }
        .nucleo-icon[data-nucleo="Público"]::before { content: "🏛️"; }
        .nucleo-icon[data-nucleo="Responsabilidade"]::before { content: "🛡️"; }
        .nucleo-icon[data-nucleo="Trabalhista"]::before { content: "👷"; }
        
        .nucleo-option[data-nucleo="Bancário"] .nucleo-icon::before { content: "🏦"; }
        .nucleo-option[data-nucleo="Criminal"] .nucleo-icon::before { content: "⚖️"; }
        .nucleo-option[data-nucleo="Família"] .nucleo-icon::before { content: "👨‍👩‍👧‍👦"; }
        .nucleo-option[data-nucleo="Previdenciário"] .nucleo-icon::before { content: "🏛️"; }
        .nucleo-option[data-nucleo="Propriedade"] .nucleo-icon::before { content: "🏠"; }
        .nucleo-option[data-nucleo="Público"] .nucleo-icon::before { content: "🏛️"; }
        .nucleo-option[data-nucleo="Responsabilidade"] .nucleo-icon::before { content: "🛡️"; }
        .nucleo-option[data-nucleo="Trabalhista"] .nucleo-icon::before { content: "��"; }
    </style>
    
    <script>
        function toggleNucleoDropdown() {
            const selector = document.getElementById('nucleoSelector');
            selector.classList.toggle('open');
        }
        
        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(e) {
            const selector = document.getElementById('nucleoSelector');
            if (!selector.contains(e.target)) {
                selector.classList.remove('open');
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
?>