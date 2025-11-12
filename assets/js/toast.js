/**
 * SISTEMA DE TOAST - Notificações elegantes
 * Uso: showToast('Mensagem', 'success' | 'error' | 'warning' | 'info')
 */

// Estilos CSS injetados automaticamente
(function injectToastStyles() {
    if (document.getElementById('toast-styles')) return;
    
    const styles = `
        <style id="toast-styles">
            .toast-container {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 99999;
                pointer-events: none;
            }
            
            .toast {
                background: white;
                color: #333;
                padding: 16px 24px;
                margin-bottom: 10px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 300px;
                max-width: 500px;
                pointer-events: auto;
                animation: slideInRight 0.3s ease-out;
                border-left: 4px solid #667eea;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 14px;
            }
            
            .toast.removing {
                animation: slideOutRight 0.3s ease-in forwards;
            }
            
            .toast-success {
                border-left-color: #28a745;
            }
            
            .toast-error {
                border-left-color: #dc3545;
            }
            
            .toast-warning {
                border-left-color: #ffc107;
            }
            
            .toast-info {
                border-left-color: #17a2b8;
            }
            
            .toast-icon {
                font-size: 24px;
                flex-shrink: 0;
            }
            
            .toast-content {
                flex: 1;
            }
            
            .toast-title {
                font-weight: 600;
                margin-bottom: 4px;
                color: #2c3e50;
            }
            
            .toast-message {
                color: #6c757d;
                line-height: 1.4;
            }
            
            .toast-close {
                background: none;
                border: none;
                font-size: 20px;
                color: #999;
                cursor: pointer;
                padding: 0;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                transition: all 0.2s;
                flex-shrink: 0;
            }
            
            .toast-close:hover {
                background: #f8f9fa;
                color: #333;
            }
            
            .toast-progress {
                position: absolute;
                bottom: 0;
                left: 0;
                height: 3px;
                background: currentColor;
                opacity: 0.3;
                animation: shrinkWidth 3s linear forwards;
            }
            
            @keyframes slideInRight {
                from {
                    transform: translateX(400px);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(400px);
                    opacity: 0;
                }
            }
            
            @keyframes shrinkWidth {
                from {
                    width: 100%;
                }
                to {
                    width: 0%;
                }
            }
            
            /* Responsivo */
            @media (max-width: 768px) {
                .toast-container {
                    left: 10px;
                    right: 10px;
                    top: 10px;
                }
                
                .toast {
                    min-width: auto;
                    max-width: none;
                }
            }
        </style>
    `;
    
    document.head.insertAdjacentHTML('beforeend', styles);
})();

// Container global de toasts
let toastContainer = null;

function getToastContainer() {
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    return toastContainer;
}

/**
 * Exibe um toast
 * @param {string} message - Mensagem a exibir
 * @param {string} type - Tipo: 'success', 'error', 'warning', 'info'
 * @param {object} options - Opções adicionais
 */
function showToast(message, type = 'info', options = {}) {
    const {
        duration = 3000,
        title = '',
        closable = true,
        showProgress = true
    } = options;
    
    const container = getToastContainer();
    
    // Ícones por tipo
    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    // Títulos padrão por tipo
    const defaultTitles = {
        success: 'Sucesso!',
        error: 'Erro!',
        warning: 'Atenção!',
        info: 'Informação'
    };
    
    const icon = icons[type] || icons.info;
    const toastTitle = title || defaultTitles[type] || '';
    
    // Criar elemento toast
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.position = 'relative';
    
    toast.innerHTML = `
        <div class="toast-icon">${icon}</div>
        <div class="toast-content">
            ${toastTitle ? `<div class="toast-title">${toastTitle}</div>` : ''}
            <div class="toast-message">${message}</div>
        </div>
        ${closable ? '<button class="toast-close" aria-label="Fechar">×</button>' : ''}
        ${showProgress && duration > 0 ? '<div class="toast-progress"></div>' : ''}
    `;
    
    container.appendChild(toast);
    
    // Botão de fechar
    if (closable) {
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => removeToast(toast));
    }
    
    // Auto-remover após duração
    if (duration > 0) {
        setTimeout(() => removeToast(toast), duration);
    }
    
    return toast;
}

/**
 * Remove um toast com animação
 */
function removeToast(toast) {
    if (!toast || !toast.parentElement) return;
    
    toast.classList.add('removing');
    
    setTimeout(() => {
        if (toast.parentElement) {
            toast.parentElement.removeChild(toast);
        }
    }, 300);
}

/**
 * Atalhos para tipos específicos
 */
window.showSuccessToast = function(message, options = {}) {
    return showToast(message, 'success', options);
};

window.showErrorToast = function(message, options = {}) {
    return showToast(message, 'error', options);
};

window.showWarningToast = function(message, options = {}) {
    return showToast(message, 'warning', options);
};

window.showInfoToast = function(message, options = {}) {
    return showToast(message, 'info', options);
};

// Exportar função principal
window.showToast = showToast;

/**
 * EXEMPLOS DE USO:
 * 
 * // Básico
 * showToast('Operação concluída!', 'success');
 * 
 * // Com título customizado
 * showToast('Dados salvos com sucesso', 'success', {
 *     title: 'Tudo certo!'
 * });
 * 
 * // Sem auto-fechar
 * showToast('Erro crítico', 'error', {
 *     duration: 0,
 *     title: 'Erro no sistema'
 * });
 * 
 * // Usando atalhos
 * showSuccessToast('Publicação tratada!');
 * showErrorToast('Falha ao salvar');
 * showWarningToast('Processo não vinculado');
 * showInfoToast('Nova atualização disponível');
 */