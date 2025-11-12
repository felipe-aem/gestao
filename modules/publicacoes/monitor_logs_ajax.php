<?php
/**
 * MONITOR DE LOGS COM AJAX EM TEMPO REAL
 * 
 * Salve como: modules/publicacoes/monitor_logs_ajax.php
 * Acesse: https://gestao.alencarmartinazzo.adv.br/modules/publicacoes/monitor_logs_ajax.php
 */

require_once '../../includes/auth.php';
Auth::protect();

$log_file = __DIR__ . '/../../logs/sincronizacao_publicacoes.log';

// Se for requisição AJAX para pegar novas linhas
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $last_position = isset($_GET['pos']) ? (int)$_GET['pos'] : 0;
    $lines = [];
    
    if (file_exists($log_file)) {
        $handle = fopen($log_file, 'r');
        
        // Ir para a última posição lida
        fseek($handle, $last_position);
        
        // Ler novas linhas
        while (!feof($handle)) {
            $line = fgets($handle);
            if (trim($line)) {
                $type = 'info';
                
                if (strpos($line, '[SYNC-ERROR]') !== false || strpos($line, '❌') !== false) {
                    $type = 'error';
                } elseif (strpos($line, '[SYNC-WARNING]') !== false || strpos($line, '⚠️') !== false) {
                    $type = 'warning';
                } elseif (strpos($line, '✅') !== false || strpos($line, 'CONCLUÍDA') !== false) {
                    $type = 'success';
                }
                
                $lines[] = [
                    'text' => $line,
                    'type' => $type
                ];
            }
        }
        
        $new_position = ftell($handle);
        fclose($handle);
        
        echo json_encode([
            'success' => true,
            'lines' => $lines,
            'position' => $new_position,
            'file_size' => filesize($log_file)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Log file not found'
        ]);
    }
    exit;
}

// HTML principal
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Monitor AJAX - Logs em Tempo Real</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            overflow: hidden;
        }
        
        .header {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            color: #00ff00;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 2s infinite;
        }
        
        .status-active {
            background: #00ff00;
            box-shadow: 0 0 10px #00ff00;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: 2px solid #00ff00;
            background: transparent;
            color: #00ff00;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #00ff00;
            color: #1a1a1a;
        }
        
        .btn.active {
            background: #00ff00;
            color: #1a1a1a;
        }
        
        .stats {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            color: #888;
            font-size: 11px;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .stat-value {
            color: #00ff00;
            font-size: 18px;
            font-weight: bold;
        }
        
        .stat-value.error {
            color: #ff0000;
        }
        
        .log-container {
            background: #0d0d0d;
            border: 2px solid #00ff00;
            border-radius: 10px;
            padding: 20px;
            height: calc(100vh - 350px);
            overflow-y: auto;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .log-line {
            margin-bottom: 3px;
            white-space: pre-wrap;
            word-wrap: break-word;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .log-line.error {
            color: #ff0000;
            font-weight: bold;
        }
        
        .log-line.warning {
            color: #ffaa00;
        }
        
        .log-line.success {
            color: #00ff00;
            font-weight: bold;
        }
        
        .log-line.info {
            color: #00aaff;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #00ff00;
            border-radius: 4px;
        }
        
        .connection-status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #2d2d2d;
            padding: 10px 20px;
            border-radius: 5px;
            border: 2px solid #00ff00;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <span class="status-indicator status-active" id="statusIndicator"></span>
            📊 Monitor AJAX - Logs em Tempo Real
        </h1>
        <div class="controls">
            <button class="btn active" id="pauseBtn" onclick="togglePause()">⏸️ Pausar</button>
            <button class="btn" onclick="clearLog()">🗑️ Limpar</button>
            <button class="btn" onclick="scrollToBottom()">⬇️ Final</button>
            <a href="index.php" class="btn">← Voltar</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat-item">
            <div class="stat-label">Total Linhas</div>
            <div class="stat-value" id="totalLines">0</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Sucessos</div>
            <div class="stat-value" id="successCount">0</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Erros</div>
            <div class="stat-value error" id="errorCount">0</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Avisos</div>
            <div class="stat-value" id="warningCount">0</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Tamanho</div>
            <div class="stat-value" id="fileSize">0 KB</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Atualizado</div>
            <div class="stat-value" id="lastUpdate">Agora</div>
        </div>
    </div>

    <div class="log-container" id="logContainer">
        <div class="log-line info">🚀 Aguardando novas linhas de log...</div>
    </div>

    <div class="connection-status" id="connectionStatus">
        🟢 Conectado | Atualizando a cada 2s
    </div>

    <script>
        let filePosition = 0;
        let isPaused = false;
        let totalLines = 0;
        let successCount = 0;
        let errorCount = 0;
        let warningCount = 0;
        let autoScroll = true;
        
        // Carregar log inicial
        window.onload = function() {
            // Carregar últimas 100 linhas
            loadInitialLog();
            
            // Iniciar polling
            startPolling();
        };
        
        function loadInitialLog() {
            // Aqui você pode fazer uma requisição para carregar as últimas N linhas
            // Por enquanto, vamos começar do zero
            filePosition = 0;
        }
        
        function startPolling() {
            setInterval(function() {
                if (!isPaused) {
                    fetchNewLines();
                }
            }, 2000); // A cada 2 segundos
        }
        
        function fetchNewLines() {
            fetch('?ajax=1&pos=' + filePosition)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.lines.length > 0) {
                        appendLines(data.lines);
                        filePosition = data.position;
                        updateStats(data);
                        updateLastUpdate();
                    }
                })
                .catch(error => {
                    console.error('Erro ao buscar logs:', error);
                    document.getElementById('connectionStatus').innerHTML = '🔴 Erro de conexão';
                });
        }
        
        function appendLines(lines) {
            const container = document.getElementById('logContainer');
            
            lines.forEach(line => {
                const div = document.createElement('div');
                div.className = 'log-line ' + line.type;
                div.textContent = line.text;
                container.appendChild(div);
                
                totalLines++;
                
                // Atualizar contadores
                if (line.type === 'success') successCount++;
                if (line.type === 'error') errorCount++;
                if (line.type === 'warning') warningCount++;
            });
            
            // Auto-scroll
            if (autoScroll) {
                scrollToBottom();
            }
            
            // Limitar número de linhas (manter últimas 1000)
            const allLines = container.querySelectorAll('.log-line');
            if (allLines.length > 1000) {
                for (let i = 0; i < allLines.length - 1000; i++) {
                    allLines[i].remove();
                }
            }
            
            // Atualizar stats
            document.getElementById('totalLines').textContent = totalLines;
            document.getElementById('successCount').textContent = successCount;
            document.getElementById('errorCount').textContent = errorCount;
            document.getElementById('warningCount').textContent = warningCount;
        }
        
        function updateStats(data) {
            // Tamanho do arquivo
            const size = data.file_size;
            let sizeText;
            if (size < 1024) {
                sizeText = size + ' B';
            } else if (size < 1048576) {
                sizeText = (size / 1024).toFixed(2) + ' KB';
            } else {
                sizeText = (size / 1048576).toFixed(2) + ' MB';
            }
            document.getElementById('fileSize').textContent = sizeText;
        }
        
        function updateLastUpdate() {
            const now = new Date();
            const time = now.toLocaleTimeString('pt-BR');
            document.getElementById('lastUpdate').textContent = time;
        }
        
        function togglePause() {
            isPaused = !isPaused;
            const btn = document.getElementById('pauseBtn');
            
            if (isPaused) {
                btn.innerHTML = '▶️ Retomar';
                btn.classList.remove('active');
                document.getElementById('connectionStatus').innerHTML = '⏸️ Pausado';
            } else {
                btn.innerHTML = '⏸️ Pausar';
                btn.classList.add('active');
                document.getElementById('connectionStatus').innerHTML = '🟢 Conectado | Atualizando a cada 2s';
            }
        }
        
        function clearLog() {
            if (confirm('Limpar todas as linhas do monitor?')) {
                document.getElementById('logContainer').innerHTML = 
                    '<div class="log-line info">🚀 Log limpo. Aguardando novas linhas...</div>';
                totalLines = 0;
                successCount = 0;
                errorCount = 0;
                warningCount = 0;
                document.getElementById('totalLines').textContent = '0';
                document.getElementById('successCount').textContent = '0';
                document.getElementById('errorCount').textContent = '0';
                document.getElementById('warningCount').textContent = '0';
            }
        }
        
        function scrollToBottom() {
            const container = document.getElementById('logContainer');
            container.scrollTop = container.scrollHeight;
        }
        
        // Detectar scroll manual
        document.getElementById('logContainer').addEventListener('scroll', function() {
            const container = this;
            const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
            autoScroll = isAtBottom;
        });
    </script>
</body>
</html>