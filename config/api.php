<?php
// config/api.php - VERSÃO ÚLTIMOS 7 DIAS COM RECONEXÃO
// ✅ Busca últimos 7 dias + reconecta MySQL se cair

define('PUBLICACOES_HASH_CLIENTE', '1b4e3b1061eaa3182c5d2f08fdaaa346');
define('PUBLICACOES_API_BASE_URL', 'https://www.publicacoesonline.com.br/');
define('PUBLICACOES_ENDPOINT_PUBLICACOES', PUBLICACOES_API_BASE_URL . 'index_pe.php');
define('PUBLICACOES_ENDPOINT_PROCESSADAS', PUBLICACOES_API_BASE_URL . 'index_pe_processadas.php');

/**
 * ✅ LOG DETALHADO
 */
function logSync($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [SYNC-$type] $message";
    error_log($log_entry);
    
    $log_file = __DIR__ . '/../logs/sincronizacao_publicacoes.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    if (is_dir($log_dir) && is_writable($log_dir)) {
        file_put_contents($log_file, $log_entry . PHP_EOL, FILE_APPEND);
    }
}

/**
 * ✅ OBTER CONEXÃO COM RECONEXÃO AUTOMÁTICA
 */
function getConnectionSafe() {
    static $pdo = null;
    static $last_check = 0;
    
    $now = time();
    
    // Verifica a cada 30 segundos se conexão está viva
    if ($pdo && ($now - $last_check) > 30) {
        try {
            $pdo->query('SELECT 1');
        } catch (Exception $e) {
            logSync("⚠️ Conexão MySQL caiu - reconectando", 'WARNING');
            $pdo = null;
        }
    }
    
    // Reconecta se necessário
    if (!$pdo) {
        try {
            $pdo = getConnection();
            logSync("✓ Conexão MySQL estabelecida");
        } catch (Exception $e) {
            logSync("ERRO ao conectar MySQL: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    $last_check = $now;
    return $pdo;
}

/**
 * ✅ VERIFICAR DISPONIBILIDADE
 */
function apiDisponivel() {
    if (date('w') == 0) {
        return [
            'disponivel' => false,
            'motivo' => 'API indisponível aos domingos'
        ];
    }
    
    $hora = (int)date('H');
    $minuto = (int)date('i');
    
    if ($hora == 0 && $minuto < 10) {
        return [
            'disponivel' => false,
            'motivo' => 'API disponível apenas após 00:10'
        ];
    }
    
    return ['disponivel' => true, 'motivo' => ''];
}

/**
 * ✅ RATE LIMIT - SEM ESPERA LONGA
 */
function verificarRateLimit() {
    try {
        $pdo = getConnectionSafe();
        
        $sql_create = "CREATE TABLE IF NOT EXISTS publicacoes_api_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(255) NOT NULL,
            timestamp DATETIME NOT NULL,
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB";
        
        $pdo->exec($sql_create);
        
        // Limpar antigos
        $sql_clean = "DELETE FROM publicacoes_api_requests 
                      WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $pdo->exec($sql_clean);
        
        // Contar última hora
        $sql_count = "SELECT COUNT(*) as total FROM publicacoes_api_requests 
                      WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $stmt = $pdo->query($sql_count);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['total'] >= 12) {
            throw new Exception('Rate limit excedido (12 requisições/hora)');
        }
        
        // Registrar requisição
        $sql_insert = "INSERT INTO publicacoes_api_requests (endpoint, timestamp) 
                       VALUES ('publicacoes_sync', NOW())";
        $pdo->exec($sql_insert);
        
        return true;
        
    } catch (Exception $e) {
        logSync("Erro rate limit: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * ✅ BUSCAR PUBLICAÇÕES POR DATA
 */
function buscarPublicacoesPorData($data, $processadas = 'N') {
    try {
        logSync("=== BUSCANDO DATA: $data ===");
        
        $disponibilidade = apiDisponivel();
        if (!$disponibilidade['disponivel']) {
            return [
                'success' => false,
                'data' => [],
                'total' => 0,
                'message' => $disponibilidade['motivo']
            ];
        }
        
        verificarRateLimit();
        
        $params = [
            'hashCliente' => PUBLICACOES_HASH_CLIENTE,
            'data' => $data,
            'processadas' => $processadas,
            'processoEletronico' => 'T',
            'retorno' => 'JSON',
            'quebraLinha' => 'false'
        ];
        
        $url = PUBLICACOES_ENDPOINT_PUBLICACOES . '?' . http_build_query($params);
        $url_log = substr($url, 0, 100) . '...';
        logSync("URL: $url_log");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("Erro cURL: $curl_error");
        }
        
        if ($http_code !== 200) {
            logSync("HTTP $http_code - Resposta: " . substr($response, 0, 300), 'ERROR');
            throw new Exception("HTTP $http_code");
        }
        
        logSync("✓ HTTP 200 - Resposta recebida");
        
        $data_response = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logSync("Erro JSON: " . json_last_error_msg(), 'ERROR');
            logSync("Resposta raw: " . substr($response, 0, 500), 'ERROR');
            throw new Exception("Erro JSON: " . json_last_error_msg());
        }
        
        if (isset($data_response['codigo'])) {
            $codigo = $data_response['codigo'];
            $mensagem = $data_response['mensagem'] ?? 'Sem mensagem';
            
            logSync("Código API: $codigo - $mensagem", 'WARNING');
            
            switch ($codigo) {
                case 100:
                case 101:
                case 102:
                    throw new Exception("Erro de autenticação ($codigo): $mensagem");
                
                case 910:
                    throw new Exception("Rate limit excedido pela API (código 910)");
                
                case 912:
                    logSync("Sem publicações em $data (código 912)");
                    return [
                        'success' => true,
                        'data' => [],
                        'total' => 0,
                        'message' => 'Nenhuma publicação em ' . $data
                    ];
                
                default:
                    logSync("Aviso API ($codigo): $mensagem", 'WARNING');
            }
        }
        
        $publicacoes = [];
        
        if (is_array($data_response)) {
            $publicacoes = array_filter($data_response, function($item) {
                return is_array($item) && !isset($item['codigo']);
            });
            $publicacoes = array_values($publicacoes);
        }
        
        $total = count($publicacoes);
        logSync("Total: $total publicações");
        
        return [
            'success' => true,
            'data' => $publicacoes,
            'total' => $total,
            'message' => $total > 0 ? "Encontradas $total" : "Nenhuma"
        ];
        
    } catch (Exception $e) {
        logSync("ERRO: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'data' => [],
            'total' => 0,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * ✅ BUSCAR ÚLTIMOS 7 DIAS (UMA DATA POR VEZ - SEM ESPERA)
 */
function buscarPublicacoesRecentes($dias_retroativos = 7) {
    try {
        logSync("=== BUSCA DE PUBLICAÇÕES - ÚLTIMOS $dias_retroativos DIAS ===");
        
        $publicacoes_todas = [];
        $total_por_data = [];
        
        // ✅ BUSCAR APENAS 1 DATA POR SINCRONIZAÇÃO (para evitar rate limit)
        // Buscar a data mais antiga primeiro
        for ($i = $dias_retroativos; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            
            // ✅ Verificar se já processamos esta data hoje
            $pdo = getConnectionSafe();
            $sql_check = "SELECT COUNT(*) as total 
                         FROM publicacoes 
                         WHERE DATE(created_at) = CURDATE() 
                         AND DATE(data_publicacao) = ?";
            $stmt = $pdo->prepare($sql_check);
            $stmt->execute([$data]);
            $ja_processado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ja_processado && $ja_processado['total'] > 0) {
                logSync("⏭️ Data $data já processada hoje ({$ja_processado['total']} pubs) - pulando");
                continue;
            }
            
            logSync("Buscando: $data");
            
            $resultado = buscarPublicacoesPorData($data, 'N'); // N = não processadas
            
            if ($resultado['success'] && !empty($resultado['data'])) {
                $total_por_data[$data] = $resultado['total'];
                $publicacoes_todas = array_merge($publicacoes_todas, $resultado['data']);
                logSync("  ✓ $data: {$resultado['total']} publicações");
            } else {
                logSync("  - $data: 0 publicações");
            }
            
            // ✅ NÃO AGUARDA - deixa o usuário chamar de novo em 5 min
            // Se encontrou publicações, para aqui para processar
            if (!empty($publicacoes_todas)) {
                logSync("✓ Encontradas publicações - processando esta primeira");
                break;
            }
        }
        
        // Remover duplicatas
        $publicacoes_unicas = [];
        $ids_vistos = [];
        
        foreach ($publicacoes_todas as $pub) {
            $id = $pub['idWs'] ?? $pub['idWS'] ?? $pub['id'] ?? null;
            
            if ($id && !in_array($id, $ids_vistos)) {
                $publicacoes_unicas[] = $pub;
                $ids_vistos[] = $id;
            }
        }
        
        $total = count($publicacoes_unicas);
        logSync("=== TOTAL ÚNICO: $total publicações ===");
        
        return [
            'success' => true,
            'data' => $publicacoes_unicas,
            'total' => $total,
            'por_data' => $total_por_data,
            'message' => $total > 0 ? "Encontradas $total" : "Nenhuma nova"
        ];
        
    } catch (Exception $e) {
        logSync("ERRO: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'data' => [],
            'total' => 0,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * ✅ MARCAR COMO PROCESSADA
 */
function marcarComoProcessadaAPI($ids_ws) {
    try {
        if (empty($ids_ws)) {
            return true;
        }
        
        $lista_ids = is_array($ids_ws) ? implode(',', $ids_ws) : $ids_ws;
        logSync("Marcando como processadas: $lista_ids");
        
        $post_data = [
            'hashCliente' => PUBLICACOES_HASH_CLIENTE,
            'listaIdsRetorno' => $lista_ids
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, PUBLICACOES_ENDPOINT_PROCESSADAS);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            logSync("✓ Marcadas como processadas");
            return true;
        } else {
            logSync("Aviso: HTTP $http_code ao marcar", 'WARNING');
            return false;
        }
        
    } catch (Exception $e) {
        logSync("Erro ao marcar: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * ✅ VINCULAR PROCESSO - COM RECONEXÃO
 */
function vincularProcesso($numero_processo, $publicacao_id) {
    try {
        $pdo = getConnectionSafe();
        
        if (!$numero_processo || !$publicacao_id) {
            return false;
        }
        
        $numero_limpo = preg_replace('/[^0-9]/', '', $numero_processo);
        
        if (strlen($numero_limpo) < 5) {
            return false;
        }
        
        $sql = "SELECT id, numero_processo 
                FROM processos 
                WHERE deleted_at IS NULL 
                AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(numero_processo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
                    OR numero_processo LIKE ?
                )
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$numero_limpo%", "%$numero_processo%"]);
        $processo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$processo) {
            return false;
        }
        
        $sql_update = "UPDATE publicacoes SET processo_id = ? WHERE id = ?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([$processo['id'], $publicacao_id]);
        
        logSync("✓ Vinculada ao processo {$processo['numero_processo']}");
        
        if (file_exists(__DIR__ . '/../includes/ProcessoHistoricoHelper.php')) {
            require_once __DIR__ . '/../includes/ProcessoHistoricoHelper.php';
            ProcessoHistoricoHelper::registrar(
                $processo['id'],
                "Nova Publicação",
                "Publicação vinculada automaticamente - " . $numero_processo,
                'publicacao',
                $publicacao_id
            );
        }
        
        return true;
        
    } catch (Exception $e) {
        logSync("Erro ao vincular: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

logSync("✅ config/api.php ÚLTIMOS 7 DIAS COM RECONEXÃO carregado");
?>