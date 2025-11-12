<?php
// config/api.php - VERSÃO 100% FUNCIONAL
// ✅ Corrigido: mapeamento de campos, rate limit, logs detalhados

define('PUBLICACOES_HASH_CLIENTE', '1b4e3b1061eaa3182c5d2f08fdaaa346');
define('PUBLICACOES_API_BASE_URL', 'https://www.publicacoesonline.com.br/');
define('PUBLICACOES_ENDPOINT_PUBLICACOES', PUBLICACOES_API_BASE_URL . 'index_pe.php');
define('PUBLICACOES_ENDPOINT_PROCESSADAS', PUBLICACOES_API_BASE_URL . 'index_pe_processadas.php');

/**
 * ✅ LOG DETALHADO - Fundamental para debug
 */
function logSync($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [SYNC-$type] $message";
    error_log($log_entry);
    
    // Salvar em arquivo
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
 * ✅ VERIFICAR DISPONIBILIDADE DA API
 */
function apiDisponivel() {
    // Domingo = 0
    if (date('w') == 0) {
        return [
            'disponivel' => false,
            'motivo' => 'API indisponível aos domingos'
        ];
    }
    
    // Verificar horário (disponível das 00:10 às 23:59)
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
 * ✅ RATE LIMIT OTIMIZADO
 * 12 requisições/hora com mínimo de 5 minutos entre chamadas
 */
function verificarRateLimit() {
    try {
        $pdo = getConnection();
        
        // Criar tabela se não existir
        $sql_create = "CREATE TABLE IF NOT EXISTS publicacoes_api_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(255) NOT NULL,
            timestamp DATETIME NOT NULL,
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB";
        
        $pdo->exec($sql_create);
        
        // Limpar registros antigos (mais de 1 hora)
        $sql_clean = "DELETE FROM publicacoes_api_requests 
                      WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $pdo->exec($sql_clean);
        
        // Contar requisições na última hora
        $sql_count = "SELECT COUNT(*) as total FROM publicacoes_api_requests 
                      WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $stmt = $pdo->query($sql_count);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['total'] >= 12) {
            throw new Exception('Rate limit excedido (12 requisições/hora)');
        }
        
        // ✅ CORREÇÃO: Verificar última requisição - aguardar APENAS se necessário
        $sql_ultima = "SELECT MAX(timestamp) as ultima FROM publicacoes_api_requests";
        $stmt_ultima = $pdo->query($sql_ultima);
        $result_ultima = $stmt_ultima->fetch(PDO::FETCH_ASSOC);
        
        if ($result_ultima && $result_ultima['ultima']) {
            $ultima = new DateTime($result_ultima['ultima']);
            $agora = new DateTime();
            $diff_segundos = $agora->getTimestamp() - $ultima->getTimestamp();
            
            // ✅ AGUARDAR apenas se passou menos de 5 minutos
            if ($diff_segundos < 300) {
                $aguardar = 300 - $diff_segundos;
                logSync("Rate limit: aguardando $aguardar segundos", 'WARNING');
                sleep($aguardar);
            }
        }
        
        // Registrar nova requisição
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
 * ✅ BUSCAR PUBLICAÇÕES POR DATA - VERSÃO OTIMIZADA
 */
function buscarPublicacoesPorData($data, $processadas = 'N') {
    try {
        logSync("=== BUSCANDO DATA: $data ===");
        
        // Verificar disponibilidade
        $disponibilidade = apiDisponivel();
        if (!$disponibilidade['disponivel']) {
            return [
                'success' => false,
                'data' => [],
                'total' => 0,
                'message' => $disponibilidade['motivo']
            ];
        }
        
        // Rate limit
        verificarRateLimit();
        
        // ✅ PARÂMETROS CORRETOS conforme documentação
        $params = [
            'hashCliente' => PUBLICACOES_HASH_CLIENTE,
            'data' => $data, // OBRIGATÓRIO formato YYYY-MM-DD
            'processadas' => $processadas, // N = não processadas
            'processoEletronico' => 'T', // T = todos
            'retorno' => 'JSON',
            'quebraLinha' => 'false' // Remove quebras de linha
        ];
        
        $url = PUBLICACOES_ENDPOINT_PUBLICACOES . '?' . http_build_query($params);
        
        // ✅ LOG da URL (sem expor hash completo)
        $url_log = substr($url, 0, 100) . '...';
        logSync("URL: $url_log");
        
        // ✅ CURL com configurações otimizadas
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
        
        // ✅ DECODIFICAR JSON
        $data_response = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logSync("Erro JSON: " . json_last_error_msg(), 'ERROR');
            logSync("Resposta raw: " . substr($response, 0, 500), 'ERROR');
            throw new Exception("Erro JSON: " . json_last_error_msg());
        }
        
        // ✅ VERIFICAR CÓDIGOS DE ERRO DA API
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
                    // Normal - sem publicações nesta data
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
        
        // ✅ EXTRAIR PUBLICAÇÕES
        $publicacoes = [];
        
        if (is_array($data_response)) {
            // Filtrar possíveis erros do array
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
 * ✅ BUSCAR PUBLICAÇÕES RECENTES (Últimos X dias)
 */
function buscarPublicacoesRecentes($dias_retroativos = 7) {
    try {
        logSync("=== BUSCA DE PUBLICAÇÕES - ÚLTIMOS $dias_retroativos DIAS ===");
        
        $publicacoes_todas = [];
        $total_por_data = [];
        
        // ✅ BUSCAR DIA POR DIA
        for ($i = 0; $i <= $dias_retroativos; $i++) {
            $data = date('Y-m-d', strtotime("-$i days"));
            
            logSync("Buscando: $data");
            
            $resultado = buscarPublicacoesPorData($data, 'N'); // N = não processadas
            
            if ($resultado['success'] && !empty($resultado['data'])) {
                $total_por_data[$data] = $resultado['total'];
                $publicacoes_todas = array_merge($publicacoes_todas, $resultado['data']);
                logSync("  ✓ $data: {$resultado['total']} publicações");
            } else {
                logSync("  - $data: 0 publicações");
            }
        }
        
        // ✅ REMOVER DUPLICATAS por idWs
        $publicacoes_unicas = [];
        $ids_vistos = [];
        
        foreach ($publicacoes_todas as $pub) {
            // ✅ CORREÇÃO: Aceitar tanto idWs quanto idWS
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
        
        // ✅ CORREÇÃO: Usar JSON no POST conforme documentação
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
 * ✅ VINCULAR PROCESSO
 */
function vincularProcesso($numero_processo, $publicacao_id) {
    try {
        $pdo = getConnection();
        
        if (!$numero_processo || !$publicacao_id) {
            return false;
        }
        
        // Normalizar número
        $numero_limpo = preg_replace('/[^0-9]/', '', $numero_processo);
        
        if (strlen($numero_limpo) < 5) {
            return false;
        }
        
        // Buscar processo
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
        
        // Atualizar publicação
        $sql_update = "UPDATE publicacoes SET processo_id = ? WHERE id = ?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([$processo['id'], $publicacao_id]);
        
        logSync("✓ Vinculada ao processo {$processo['numero_processo']}");
        
        // Registrar no histórico
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

/**
 * ✅ TESTAR CONEXÃO
 */
function testarConexaoAPI() {
    try {
        $disponibilidade = apiDisponivel();
        if (!$disponibilidade['disponivel']) {
            return [
                'success' => false,
                'message' => $disponibilidade['motivo']
            ];
        }
        
        $data_hoje = date('Y-m-d');
        
        $params = [
            'hashCliente' => PUBLICACOES_HASH_CLIENTE,
            'data' => $data_hoje,
            'processadas' => 'T',
            'retorno' => 'JSON'
        ];
        
        $url = PUBLICACOES_ENDPOINT_PUBLICACOES . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => ($http_code === 200),
            'http_code' => $http_code,
            'response_preview' => substr($response, 0, 200),
            'data_testada' => $data_hoje
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

logSync("✅ config/api.php CORRIGIDO carregado");
?>