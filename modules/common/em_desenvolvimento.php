<?php
require_once '../../includes/auth.php';
Auth::protect();

$usuario_logado = Auth::user();

// Capturar a página de origem se fornecida
$pagina_origem = $_GET['origem'] ?? '../dashboard/';
$modulo_nome = $_GET['modulo'] ?? 'Esta funcionalidade';

// Sanitizar a URL de origem para segurança
$paginas_permitidas = [
    '../dashboard/',
    '../atendimentos/',
    '../agenda/',
    '../processos/',
    '../clientes/',
    '../usuarios/',
    '../relatorios/',
    '../configuracoes/'
];

if (!in_array($pagina_origem, $paginas_permitidas)) {
    $pagina_origem = '../dashboard/';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Em Desenvolvimento - SIGAM</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 50px;
            text-align: center;
            max-width: 600px;
            width: 90%;
            position: relative;
            overflow: hidden;
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #007bff, #28a745, #ffc107, #dc3545, #6f42c1);
            animation: shimmer 3s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; }
        }
        
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 2s ease-in-out infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        .title {
            color: #1a1a1a;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            letter-spacing: -0.5px;
        }
        
        .subtitle {
            color: #666;
            font-size: 18px;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .message {
            background: linear-gradient(135deg, rgba(0, 123, 255, 0.1) 0%, rgba(40, 167, 69, 0.1) 100%);
            border: 1px solid rgba(0, 123, 255, 0.2);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            color: #155724;
        }
        
        .message h3 {
            color: #007bff;
            margin-bottom: 10px;
            font-size: 20px;
        }
        
        .message p {
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .features-list {
            text-align: left;
            margin: 20px 0;
        }
        
        .features-list li {
            margin: 8px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .features-list li::before {
            content: '⚡';
            position: absolute;
            left: 0;
            top: 0;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 30px;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .user-info {
            background: rgba(26, 26, 26, 0.05);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        
        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            margin: 20px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            background: linear-gradient(90deg, #007bff, #28a745);
            height: 100%;
            width: 65%;
            border-radius: 10px;
            animation: progress 3s ease-in-out infinite;
        }
        
        @keyframes progress {
            0%, 100% { width: 60%; }
            50% { width: 70%; }
        }
        
        .eta {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 30px 20px;
                margin: 20px;
            }
            
            .title {
                font-size: 24px;
            }
            
            .subtitle {
                font-size: 16px;
            }
            
            .actions {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚧</div>
        
        <h1 class="title">Em Desenvolvimento</h1>
        <p class="subtitle"><?= htmlspecialchars($modulo_nome) ?> está sendo desenvolvido(a) pela nossa equipe</p>
        
        <div class="user-info">
            <strong>Usuário:</strong> <?= htmlspecialchars($usuario_logado['nome']) ?> 
            (<?= htmlspecialchars($usuario_logado['nivel_acesso']) ?>)
        </div>
        
        <div class="message">
            <h3>🎯 O que está por vir?</h3>
            <p>Estamos trabalhando duro para trazer esta funcionalidade para você! Nossa equipe está desenvolvendo uma solução completa e intuitiva.</p>
            
            <div class="features-list">
                <ul>
                    <li>Interface moderna e responsiva</li>
                    <li>Funcionalidades avançadas de gestão</li>
                    <li>Integração completa com o sistema</li>
                    <li>Relatórios e estatísticas detalhadas</li>
                    <li>Controle de acesso por núcleo</li>
                </ul>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <div class="eta">Progresso estimado: 65% concluído</div>
        </div>
        
        <div class="actions">
            <a href="<?= htmlspecialchars($pagina_origem) ?>" class="btn btn-primary">
                ← Voltar
            </a>
            
            <a href="../dashboard/" class="btn btn-secondary">
                🏠 Dashboard
            </a>
        </div>
    </div>
    
    <script>
        // Adicionar um pouco de interatividade
        document.addEventListener('DOMContentLoaded', function() {
            // Efeito de digitação no título
            const title = document.querySelector('.title');
            const originalText = title.textContent;
            title.textContent = '';
            
            let i = 0;
            const typeWriter = () => {
                if (i < originalText.length) {
                    title.textContent += originalText.charAt(i);
                    i++;
                    setTimeout(typeWriter, 100);
                }
            };
            
            setTimeout(typeWriter, 500);
            
            // Animação dos botões ao carregar
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach((btn, index) => {
                btn.style.opacity = '0';
                btn.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    btn.style.transition = 'all 0.5s ease';
                    btn.style.opacity = '1';
                    btn.style.transform = 'translateY(0)';
                }, 1500 + (index * 200));
            });
        });
        
        // Adicionar efeito de clique nos botões
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                // Criar efeito de ripple
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.style.position = 'absolute';
                ripple.style.borderRadius = '50%';
                ripple.style.background = 'rgba(255,255,255,0.3)';
                ripple.style.transform = 'scale(0)';
                ripple.style.animation = 'ripple 0.6s linear';
                ripple.style.pointerEvents = 'none';
                
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });
        
        // CSS para animação do ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>