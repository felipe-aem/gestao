class AgendaNotifications {
    constructor() {
        this.checkInterval = 60000; // Verificar a cada 1 minuto
        this.notificationContainer = null;
        this.init();
    }
    
    init() {
        this.createNotificationContainer();
        this.requestNotificationPermission();
        this.startPeriodicCheck();
        this.bindEvents();
    }
    
    createNotificationContainer() {
        // Criar container para notificações na página
        const container = document.createElement('div');
        container.id = 'agenda-notifications';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 350px;
        `;
        document.body.appendChild(container);
        this.notificationContainer = container;
    }
    
    requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }
    
    startPeriodicCheck() {
        // Verificar imediatamente
        this.checkReminders();
        
        // Configurar verificação periódica
        setInterval(() => {
            this.checkReminders();
        }, this.checkInterval);
    }
    
    async checkReminders() {
        try {
            const response = await fetch('/modules/agenda/notificacoes.php?action=verificar_lembretes');
            const data = await response.json();
            
            if (data.success && data.lembretes > 0) {
                data.dados.forEach(lembrete => {
                    this.showReminder(lembrete);
                });
            }
        } catch (error) {
            console.error('Erro ao verificar lembretes:', error);
        }
    }
    
    showReminder(lembrete) {
        const evento = lembrete.evento;
        const minutos = lembrete.minutos_restantes;
        
        // Notificação do browser
        if (Notification.permission === 'granted') {
            const notification = new Notification(`🔔 Lembrete: ${evento.titulo}`, {
                body: `Seu evento começará em ${minutos} minuto(s)`,
                icon: '/favicon.ico',
                tag: `evento-${evento.id}`,
                requireInteraction: true
            });
            
            notification.onclick = () => {
                window.focus();
                window.location.href = `/modules/agenda/visualizar.php?id=${evento.id}`;
                notification.close();
            };
            
            // Auto-fechar após 10 segundos
            setTimeout(() => {
                notification.close();
            }, 10000);
        }
        
        // Notificação na página
        this.showPageNotification(evento, minutos);
    }
    
    showPageNotification(evento, minutos) {
        const notification = document.createElement('div');
        notification.style.cssText = `
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            animation: slideInRight 0.3s ease;
        `;
        
        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="font-size: 20px;">🔔</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; margin-bottom: 5px;">Lembrete de Evento</div>
                    <div style="font-size: 14px; opacity: 0.9;">${evento.titulo}</div>
                    <div style="font-size: 12px; opacity: 0.8;">Começará em ${minutos} minuto(s)</div>
                </div>
                <div style="font-size: 18px; opacity: 0.7; cursor: pointer;" onclick="this.parentElement.parentElement.remove()">×</div>
            </div>
        `;
        
        notification.onclick = (e) => {
            if (e.target.textContent !== '×') {
                window.location.href = `/modules/agenda/visualizar.php?id=${evento.id}`;
            }
        };
        
        this.notificationContainer.appendChild(notification);
        
        // Auto-remover após 15 segundos
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }
        }, 15000);
    }
    
    bindEvents() {
        // Verificar lembretes quando a página ganhar foco
        window.addEventListener('focus', () => {
            this.checkReminders();
        });
        
        // Verificar lembretes quando voltar de outra aba
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.checkReminders();
            }
        });
    }
}

// Adicionar animações CSS
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
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
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Inicializar quando a página carregar
document.addEventListener('DOMContentLoaded', () => {
    new AgendaNotifications();
});