<?php
/**
 * Instalador de CRON para Sincronização Automática
 * Arquivo: modules/publicacoes/install_cron.php
 */
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se é admin
$usuario = Auth::user();

// Permitir múltiplos formatos de admin
$niveis_permitidos = ['admin', 'administrador', 'master', 'root'];
$nivel_usuario = strtolower($usuario['nivel_acesso'] ?? '');

// Se tiver um ID, permitir admin com ID = 1 também
$is_admin = in_array($nivel_usuario, $niveis_permitidos) || ($usuario['usuario_id'] == 1);

if (!$is_admin) {
    // Mostrar mensagem mais amigável
    echo "<!DOCTYPE html>
    <html lang='pt-BR'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Acesso Negado</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .error-box {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.2);
                text-align: center;
                max-width: 500px;
            }
            h1 { color: #dc3545; margin-bottom: 20px; }
            p { color: #666; margin: 15px 0; }
            .info { 
                background: #f8f9fa; 
                padding: 15px; 
                border-radius: 8px; 
                margin: 20px 0;
                text-align: left;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 8px;
                margin-top: 20px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class='error-box'>
            <h1>🔒 Acesso Negado</h1>
            <p>Apenas administradores podem configurar o CRON automático.</p>
            
            <div class='info'>
                <strong>Seu usuário:</strong><br>
                ID: {$usuario['usuario_id']}<br>
                Nome: {$usuario['nome']}<br>
                Nível: {$usuario['nivel_acesso']}
            </div>
            
            <p><small>Para obter acesso de administrador, entre em contato com o responsável do sistema.</small></p>
            
            <a href='index.php' class='btn'>← Voltar para Publicações</a>
        </div>
    </body>
    </html>";
    exit;
}

$caminho_script = dirname(__DIR__, 2) . '/cli/process_publications.php';
$mensagem = '';
$tipo = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $frequencia = $_POST['frequencia'] ?? 'hora';
    
    // Montar comando CRON
    $comando_php = "php " . escapeshellarg($caminho_script);
    
    $cron_lines = [
        '# Sincronização Automática de Publicações - Gestão Jurídica',
        '# Adicionado em: ' . date('d/m/Y H:i:s'),
        ''
    ];
    
    switch ($frequencia) {
        case '30min':
            $cron_lines[] = "*/30 * * * * $comando_php";
            $desc = "A cada 30 minutos";
            break;
        case 'hora':
            $cron_lines[] = "0 * * * * $comando_php";
            $desc = "A cada hora (no minuto 0)";
            break;
        case '2horas':
            $cron_lines[] = "0 */2 * * * $comando_php";
            $desc = "A cada 2 horas";
            break;
        case '4horas':
            $cron_lines[] = "0 */4 * * * $comando_php";
            $desc = "A cada 4 horas";
            break;
        case 'comercial':
            $cron_lines[] = "0 8,12,16,18 * * 1-5 $comando_php";
            $desc = "4x ao dia em horário comercial (8h, 12h, 16h, 18h) de segunda a sexta";
            break;
        case 'diario':
            $cron_lines[] = "0 8 * * * $comando_php";
            $desc = "Uma vez por dia às 8h da manhã";
            break;
        default:
            $cron_lines[] = "0 * * * * $comando_php";
            $desc = "A cada hora";
    }
    
    $cron_content = implode("\n", $cron_lines) . "\n";
    
    $mensagem = "
    <h3>✅ Configuração gerada com sucesso!</h3>
    <p><strong>Frequência:</strong> $desc</p>
    <p><strong>Comando:</strong></p>
    <pre style='background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto;'>$cron_content</pre>
    
    <div style='background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;'>
        <h4>📋 Como instalar o CRON:</h4>
        
        <h5>OPÇÃO 1: Via cPanel (mais fácil)</h5>
        <ol>
            <li>Acesse o <strong>cPanel</strong> da sua hospedagem</li>
            <li>Procure por <strong>\"Cron Jobs\"</strong> ou <strong>\"Tarefas Cron\"</strong></li>
            <li>Clique em <strong>\"Adicionar novo Cron Job\"</strong></li>
            <li>Configure conforme abaixo:</li>
        </ol>
        <ul style='list-style: none; padding-left: 20px;'>
            <li>• <strong>Minuto:</strong> " . explode(' ', $cron_lines[count($cron_lines)-1])[0] . "</li>
            <li>• <strong>Hora:</strong> " . explode(' ', $cron_lines[count($cron_lines)-1])[1] . "</li>
            <li>• <strong>Dia:</strong> " . explode(' ', $cron_lines[count($cron_lines)-1])[2] . "</li>
            <li>• <strong>Mês:</strong> " . explode(' ', $cron_lines[count($cron_lines)-1])[3] . "</li>
            <li>• <strong>Dia da semana:</strong> " . explode(' ', $cron_lines[count($cron_lines)-1])[4] . "</li>
            <li>• <strong>Comando:</strong> <code>$comando_php</code></li>
        </ul>
        
        <h5>OPÇÃO 2: Via Terminal SSH</h5>
        <ol>
            <li>Conecte via SSH</li>
            <li>Execute: <code>crontab -e</code></li>
            <li>Cole o conteúdo acima no final do arquivo</li>
            <li>Salve e saia (CTRL+X, Y, Enter)</li>
        </ol>
        
        <h5>OPÇÃO 3: Arquivo crontab.txt (para suporte)</h5>
        <ol>
            <li>Copie o conteúdo acima</li>
            <li>Salve em um arquivo <code>crontab.txt</code></li>
            <li>Envie para o suporte da hospedagem configurar</li>
        </ol>
    </div>
    ";
    $tipo = 'success';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Sincronização Automática</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }
        h1 { color: #1a1a1a; margin-bottom: 10px; }
        h2 { color: #1a1a1a; margin: 20px 0 10px 0; font-size: 20px; }
        h3 { color: #667eea; margin-bottom: 15px; }
        h4 { color: #333; margin: 15px 0 10px 0; font-size: 16px; }
        h5 { color: #555; margin: 10px 0 5px 0; font-size: 14px; }
        p { margin: 10px 0; line-height: 1.6; }
        pre { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 8px; 
            overflow-x: auto; 
            font-size: 13px;
            border: 1px solid #dee2e6;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .radio-option {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .radio-option:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        .radio-option input[type="radio"] {
            margin-right: 12px;
            margin-top: 3px;
        }
        .radio-content strong {
            display: block;
            color: #1a1a1a;
            margin-bottom: 4px;
        }
        .radio-content span {
            color: #666;
            font-size: 13px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .alert {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        ul, ol { margin-left: 20px; margin-top: 10px; }
        li { margin: 5px 0; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>⚙️ Configurar Sincronização Automática</h1>
            <p style="color: #666;">Configure a frequência de busca automática de novas publicações</p>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="card">
                <div class="alert alert-<?= $tipo ?>">
                    <?= $mensagem ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>📊 Recomendações de Frequência</h2>
            
            <div class="info-box">
                <strong>🔍 Conforme a documentação da API:</strong>
                <ul>
                    <li>Limite: <strong>12 requisições por hora</strong></li>
                    <li>Intervalo mínimo: <strong>5 minutos</strong> entre requisições</li>
                    <li>Recomendação: <strong>4 consultas por dia</strong></li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Escolha a frequência de sincronização:</label>
                    
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="frequencia" value="comercial" checked>
                            <div class="radio-content">
                                <strong>⭐ Horário Comercial (Recomendado)</strong>
                                <span>4x ao dia: 8h, 12h, 16h, 18h (segunda a sexta)<br>
                                Ideal para escritórios - Segue a recomendação da API</span>
                            </div>
                        </label>
                        
                        <label class="radio-option">
                            <input type="radio" name="frequencia" value="4horas">
                            <div class="radio-content">
                                <strong>A cada 4 horas</strong>
                                <span>6x ao dia: 0h, 4h, 8h, 12h, 16h, 20h<br>
                                Cobertura 24/7 - Boa para casos urgentes</span>
                            </div>
                        </label>
                        
                        <label class="radio-option">
                            <input type="radio" name="frequencia" value="2horas">
                            <div class="radio-content">
                                <strong>A cada 2 horas</strong>
                                <span>12x ao dia - Máximo recomendado pela API<br>
                                Monitoramento intensivo</span>
                            </div>
                        </label>
                        
                        <label class="radio-option">
                            <input type="radio" name="frequencia" value="hora">
                            <div class="radio-content">
                                <strong>⚠️ A cada 1 hora</strong>
                                <span>24x ao dia - EXCEDE limite da API<br>
                                Use apenas se realmente necessário</span>
                            </div>
                        </label>
                        
                        <label class="radio-option">
                            <input type="radio" name="frequencia" value="diario">
                            <div class="radio-content">
                                <strong>Uma vez por dia</strong>
                                <span>Às 8h da manhã<br>
                                Economia de recursos - Para baixo volume</span>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div class="warning-box">
                    <strong>⚠️ Importante:</strong>
                    <ul>
                        <li>O sistema usa o endpoint <code>index_data_publicacao_nao_lida.php</code></li>
                        <li>Busca apenas publicações NÃO LIDAS (mais eficiente)</li>
                        <li>Marca automaticamente como lida após importar</li>
                        <li>Vincula automaticamente aos processos quando possível</li>
                    </ul>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        🔧 Gerar Configuração CRON
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        ← Voltar
                    </a>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2>📖 Como Funciona</h2>
            
            <h3>1️⃣ Busca Automática</h3>
            <p>O CRON executa o script <code>cli/process_publications.php</code> na frequência configurada.</p>
            
            <h3>2️⃣ Importação Inteligente</h3>
            <ul>
                <li>Busca apenas publicações NÃO LIDAS</li>
                <li>Evita duplicatas (verifica pelo ID)</li>
                <li>Marca como lida na API após importar</li>
                <li>Vincula automaticamente aos processos existentes</li>
            </ul>
            
            <h3>3️⃣ Logs e Monitoramento</h3>
            <p>Todos os logs ficam em:</p>
            <ul>
                <li><code>logs/sincronizacao_publicacoes.log</code> (arquivo)</li>
                <li>Tabela <code>publicacoes_sincronizacao_log</code> (banco de dados)</li>
            </ul>
            
            <h3>4️⃣ Verificação Manual</h3>
            <p>Você ainda pode usar o botão "Sincronizar Agora" a qualquer momento!</p>
        </div>
    </div>
</body>
</html>