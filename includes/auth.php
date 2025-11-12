<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

class Auth {
    
    // Verificar se usuário está logado
    public static function check() {
        error_log("=== DEBUG Auth::check() ===");
        error_log("SESSION usuario_id: " . ($_SESSION['usuario_id'] ?? 'NÃO EXISTE'));
        error_log("SESSION token: " . ($_SESSION['token'] ?? 'NÃO EXISTE'));
        
        if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['token'])) {
            error_log("FALHOU: Falta usuario_id ou token na sessão");
            return false;
        }
    
        // Verificar se a sessão ainda é válida no banco
        $sql = "SELECT * FROM sessoes WHERE token = ? AND usuario_id = ?";
        error_log("SQL: $sql");
        error_log("Params: token=" . $_SESSION['token'] . ", usuario_id=" . $_SESSION['usuario_id']);
        
        try {
            $stmt = executeQuery($sql, [$_SESSION['token'], $_SESSION['usuario_id']]);
            $sessao = $stmt->fetch();
            
            error_log("Sessão encontrada no banco: " . ($sessao ? 'SIM' : 'NÃO'));
            
            if ($sessao) {
                error_log("Última atividade: " . $sessao['ultima_atividade']);
            }
        } catch (Exception $e) {
            error_log("ERRO na query: " . $e->getMessage());
            return false;
        }
    
        if (!$sessao) {
            error_log("FALHOU: Sessão não encontrada no banco");
            self::logout();
            return false;
        }
    
        // Verificar timeout
        $ultima_atividade = strtotime($sessao['ultima_atividade']);
        $tempo_decorrido = time() - $ultima_atividade;
        error_log("Tempo desde última atividade: " . $tempo_decorrido . " segundos (timeout: " . SESSION_TIMEOUT . ")");
        
        if ($tempo_decorrido > SESSION_TIMEOUT) {
            error_log("FALHOU: Timeout excedido");
            self::logout();
            return false;
        }
    
        // Atualizar última atividade
        $updateSql = "UPDATE sessoes SET ultima_atividade = NOW() WHERE token = ?";
        executeQuery($updateSql, [$_SESSION['token']]);
        
        error_log("✅ Auth::check() PASSOU");
    
        return true;
    }
    
    // Obter dados do usuário logado
    public static function user() {
        if (self::check()) {
            return $_SESSION;
        }
        return null;
    }
    
    // Verificar nível de acesso
    public static function hasLevel($levels) {
        if (!self::check()) return false;
        
        $userLevel = $_SESSION['nivel_acesso'];
        if (is_array($levels)) {
            return in_array($userLevel, $levels);
        }
        return $userLevel === $levels;
    }
    
    // Verificar se pode gerenciar usuários
    public static function canManageUsers() {
        return self::hasLevel(['Admin', 'Socio', 'Diretor']);
    }
    
    // Fazer login
    public static function login($email, $senha) {
        $sql = "SELECT u.*, GROUP_CONCAT(un.nucleo_id) as nucleos 
                FROM usuarios u 
                LEFT JOIN usuarios_nucleos un ON u.id = un.usuario_id 
                WHERE u.email = ? AND u.ativo = 1 
                GROUP BY u.id";
        
        $stmt = executeQuery($sql, [$email]);
        $usuario = $stmt->fetch();
        
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Gerar token único
            $token = bin2hex(random_bytes(32));
            
            // Limpar sessões antigas do usuário
            $deleteSql = "DELETE FROM sessoes WHERE usuario_id = ?";
            executeQuery($deleteSql, [$usuario['id']]);
            
            // Criar nova sessão no banco
            $insertSql = "INSERT INTO sessoes (usuario_id, token, ip_address, user_agent) 
                          VALUES (?, ?, ?, ?)";
            executeQuery($insertSql, [
                $usuario['id'],
                $token,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            // Salvar na sessão PHP
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['nome'] = $usuario['nome'];
            $_SESSION['email'] = $usuario['email'];
            $_SESSION['nivel_acesso'] = $usuario['nivel_acesso'];
            $_SESSION['acesso_financeiro'] = $usuario['acesso_financeiro'] ?? 'Nenhum'; // ← ADICIONADO
            $_SESSION['nucleos'] = explode(',', $usuario['nucleos'] ?? '');
            $_SESSION['token'] = $token;
            
            // Atualizar último acesso
            $updateSql = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?";
            executeQuery($updateSql, [$usuario['id']]);
            
            // Registrar log
            self::log('Login', 'Usuário fez login no sistema');
            
            return true;
        }
        return false;
    }
    
    // Fazer logout
    public static function logout() {
        if (isset($_SESSION['token'])) {
            // Remover sessão do banco
            $sql = "DELETE FROM sessoes WHERE token = ?";
            executeQuery($sql, [$_SESSION['token']]);
            
            self::log('Logout', 'Usuário fez logout do sistema');
        }
        
        session_destroy();
    }
    
    // Registrar log
    public static function log($acao, $descricao = '') {
        if (isset($_SESSION['usuario_id'])) {
            $sql = "INSERT INTO logs_sistema (usuario_id, acao, descricao, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            try {
                executeQuery($sql, [$_SESSION['usuario_id'], $acao, $descricao, $ip, $userAgent]);
            } catch (Exception $e) {
                // Silenciar erro de log
            }
        }
    }
    
    // Middleware de proteção
    public static function protect($levels = null) {
        if (!self::check()) {
            header('Location: ' . SITE_URL . '/modules/auth/login.php');
            exit;
        }
        
        if ($levels && !self::hasLevel($levels)) {
            die('
                <div style="text-align: center; margin-top: 50px;">
                    <h2>Acesso Negado!</h2>
                    <p>Você não tem permissão para acessar esta página.</p>
                    <a href="' . SITE_URL . '/modules/dashboard/">Voltar ao Dashboard</a>
                </div>
            ');
        }
    }
}
?>