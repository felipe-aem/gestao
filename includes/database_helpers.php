<?php
/**
 * Funções auxiliares para operações de banco de dados
 * Arquivo: /includes/database_helpers.php
 */

require_once __DIR__ . '/admin_constants.php';

/**
 * Executa query de forma segura com tratamento de erro padronizado
 */
function executeSecureQuery($sql, $params = []) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Erro SQL: " . $e->getMessage() . " | Query: " . $sql);
        throw new Exception("Erro na operação do banco de dados");
    }
}

/**
 * Registra log de sistema de forma padronizada
 */
function registrarLogSistema($usuario_id, $acao, $detalhes = null, $ip = null) {
    $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'N/A');
    
    $sql = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address, data_acao) 
            VALUES (?, ?, ?, ?, NOW())";
    
    try {
        executeSecureQuery($sql, [$usuario_id, $acao, $detalhes, $ip]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
        // Não propagar erro de log para não quebrar a operação principal
    }
}

/**
 * Busca estatísticas do sistema
 */
function getSystemStats() {
    try {
        $stats = [];
        
        // Total de usuários
        $stmt = executeSecureQuery("SELECT COUNT(*) as total FROM usuarios");
        $stats['usuarios'] = $stmt->fetch()['total'];
        
        // Usuários ativos
        $stmt = executeSecureQuery("SELECT COUNT(*) as total FROM usuarios WHERE ativo = 1");
        $stats['usuarios_ativos'] = $stmt->fetch()['total'];
        
        // Total de clientes
        $stmt = executeSecureQuery("SELECT COUNT(*) as total FROM clientes");
        $stats['clientes'] = $stmt->fetch()['total'];
        
        // Eventos futuros
        $stmt = executeSecureQuery("SELECT COUNT(*) as total FROM agenda WHERE data_inicio >= NOW()");
        $stats['eventos_futuros'] = $stmt->fetch()['total'];
        
        // Logs hoje
        $stmt = executeSecureQuery("SELECT COUNT(*) as total FROM logs_sistema WHERE DATE(data_acao) = CURDATE()");
        $stats['logs_hoje'] = $stmt->fetch()['total'];
        
        // Usuários online (últimos 15 minutos)
        $stmt = executeSecureQuery("SELECT COUNT(DISTINCT usuario_id) as total FROM logs_sistema 
                WHERE data_acao >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stats['usuarios_online'] = $stmt->fetch()['total'];
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Erro ao buscar estatísticas: " . $e->getMessage());
        return [
            'usuarios' => 0,
            'usuarios_ativos' => 0, 
            'clientes' => 0,
            'eventos_futuros' => 0,
            'logs_hoje' => 0,
            'usuarios_online' => 0
        ];
    }
}

/**
 * Busca dados de um usuário por ID
 */
function getUserById($user_id) {
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = executeSecureQuery($sql, [$user_id]);
    return $stmt->fetch();
}

/**
 * Busca estatísticas de um usuário específico
 */
function getUserStats($user_id) {
    $stats = [];
    
    try {
        // Logins nos últimos 30 dias
        $sql = "SELECT COUNT(*) as total FROM logs_sistema 
                WHERE usuario_id = ? AND acao LIKE '%Login%' 
                AND data_acao >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = executeSecureQuery($sql, [$user_id]);
        $stats['logins_30_dias'] = $stmt->fetch()['total'];
        
        // Total de ações
        $sql = "SELECT COUNT(*) as total FROM logs_sistema WHERE usuario_id = ?";
        $stmt = executeSecureQuery($sql, [$user_id]);
        $stats['total_acoes'] = $stmt->fetch()['total'];
        
        // Eventos criados (se a coluna existir)
        if (columnExists('agenda', 'criado_por')) {
            $sql = "SELECT COUNT(*) as total FROM agenda WHERE criado_por = ?";
            $stmt = executeSecureQuery($sql, [$user_id]);
            $stats['eventos_criados'] = $stmt->fetch()['total'];
        } else {
            $stats['eventos_criados'] = 0;
        }
        
        // Clientes criados (se a coluna existir)
        if (columnExists('clientes', 'criado_por')) {
            $sql = "SELECT COUNT(*) as total FROM clientes WHERE criado_por = ?";
            $stmt = executeSecureQuery($sql, [$user_id]);
            $stats['clientes_criados'] = $stmt->fetch()['total'];
        } else {
            $stats['clientes_criados'] = 0;
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Erro ao buscar estatísticas do usuário: " . $e->getMessage());
        return [
            'logins_30_dias' => 0,
            'total_acoes' => 0,
            'eventos_criados' => 0,
            'clientes_criados' => 0
        ];
    }
}

/**
 * Verifica se uma coluna existe em uma tabela
 */
function columnExists($table, $column) {
    try {
        $sql = "SHOW COLUMNS FROM `$table` LIKE ?";
        $stmt = executeSecureQuery($sql, [$column]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Busca últimas ações de um usuário
 */
function getUserRecentActions($user_id, $limit = 10) {
    try {
        $sql = "SELECT * FROM logs_sistema 
                WHERE usuario_id = ? 
                ORDER BY data_acao DESC 
                LIMIT ?";
        $stmt = executeSecureQuery($sql, [$user_id, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erro ao buscar ações do usuário: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca últimos logins do sistema
 */
function getRecentLogins($limit = 5) {
    try {
        $sql = "SELECT u.nome, u.ultimo_login, u.ip_ultimo_login 
                FROM usuarios u 
                WHERE u.ultimo_login IS NOT NULL 
                ORDER BY u.ultimo_login DESC 
                LIMIT ?";
        $stmt = executeSecureQuery($sql, [$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erro ao buscar últimos logins: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca logs recentes do sistema
 */
function getRecentLogs($limit = 10) {
    try {
        $sql = "SELECT ls.*, u.nome as usuario_nome 
                FROM logs_sistema ls 
                LEFT JOIN usuarios u ON ls.usuario_id = u.id 
                ORDER BY ls.data_acao DESC 
                LIMIT ?";
        $stmt = executeSecureQuery($sql, [$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erro ao buscar logs recentes: " . $e->getMessage());
        return [];
    }
}

/**
 * Busca logs com filtros
 */
function getLogsWithFilters($filters = [], $page = 1, $limit = 50) {
    $offset = ($page - 1) * $limit;
    $where_conditions = [];
    $params = [];
    
    // Aplicar filtros
    if (!empty($filters['usuario'])) {
        $where_conditions[] = "u.nome LIKE ?";
        $params[] = "%{$filters['usuario']}%";
    }
    
    if (!empty($filters['acao'])) {
        $where_conditions[] = "ls.acao LIKE ?";
        $params[] = "%{$filters['acao']}%";
    }
    
    if (!empty($filters['data_inicio'])) {
        $where_conditions[] = "DATE(ls.data_acao) >= ?";
        $params[] = $filters['data_inicio'];
    }
    
    if (!empty($filters['data_fim'])) {
        $where_conditions[] = "DATE(ls.data_acao) <= ?";
        $params[] = $filters['data_fim'];
    }
    
    if (!empty($filters['ip'])) {
        $where_conditions[] = "ls.ip_address LIKE ?";
        $params[] = "%{$filters['ip']}%";
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    try {
        // Buscar logs
        $sql = "SELECT ls.*, u.nome as usuario_nome 
                FROM logs_sistema ls 
                LEFT JOIN usuarios u ON ls.usuario_id = u.id 
                $where_clause 
                ORDER BY ls.data_acao DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = executeSecureQuery($sql, $params);
        $logs = $stmt->fetchAll();
        
        // Contar total
        $count_sql = "SELECT COUNT(*) as total 
                      FROM logs_sistema ls 
                      LEFT JOIN usuarios u ON ls.usuario_id = u.id 
                      $where_clause";
        
        $count_params = array_slice($params, 0, -2); // Remove limit e offset
        $stmt = executeSecureQuery($count_sql, $count_params);
        $total = $stmt->fetch()['total'];
        
        return [
            'logs' => $logs,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar logs filtrados: " . $e->getMessage());
        return [
            'logs' => [],
            'total' => 0,
            'total_pages' => 0
        ];
    }
}

/**
 * Atualiza último login do usuário
 */
function updateUserLastLogin($user_id, $ip = null) {
    $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'N/A');
    
    try {
        $sql = "UPDATE usuarios SET 
                ultimo_login = NOW(), 
                ip_ultimo_login = ?,
                tentativas_login = 0
                WHERE id = ?";
        executeSecureQuery($sql, [$ip, $user_id]);
        
        // Registrar log
        registrarLogSistema($user_id, LOG_ACTIONS['LOGIN'], "Login realizado", $ip);
        
    } catch (Exception $e) {
        error_log("Erro ao atualizar último login: " . $e->getMessage());
    }
}

/**
 * Busca configurações do sistema
 */
function getSystemConfig() {
    try {
        $sql = "SELECT chave, valor FROM configuracoes";
        $stmt = executeSecureQuery($sql);
        $config_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Valores padrão
        $defaults = [
            'nome_sistema' => 'Sistema Jurídico',
            'email_sistema' => 'admin@sistema.com',
            'timezone' => 'America/Sao_Paulo',
            'backup_automatico' => 1,
            'logs_retention_days' => LOG_RETENTION_DAYS,
            'max_login_attempts' => MAX_LOGIN_ATTEMPTS,
            'session_timeout' => SESSION_TIMEOUT_MINUTES,
            'manutencao_modo' => 0,
            'manutencao_mensagem' => 'Sistema em manutenção. Tente novamente em alguns minutos.'
        ];
        
        return array_merge($defaults, $config_data);
        
    } catch (Exception $e) {
        error_log("Erro ao buscar configurações: " . $e->getMessage());
        return $defaults ?? [];
    }
}

/**
 * Salva configuração do sistema
 */
function saveSystemConfig($chave, $valor) {
    try {
        $sql = "INSERT INTO configuracoes (chave, valor) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
        executeSecureQuery($sql, [$chave, $valor]);
        return true;
    } catch (Exception $e) {
        error_log("Erro ao salvar configuração: " . $e->getMessage());
        return false;
    }
}

/**
 * Classe para respostas API padronizadas
 */
class ApiResponse {
    public static function success($data = null, $message = null) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => time()
        ]);
        exit;
    }
    
    public static function error($message, $code = 400, $data = null) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
        exit;
    }
}

/**
 * Verifica se é uma requisição AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}
?>