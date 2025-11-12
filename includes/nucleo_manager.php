<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

class NucleoManager {
    
    // Definir núcleo ativo na sessão
    public static function setNucleoAtivo($nucleo_id) {
        $_SESSION['nucleo_ativo'] = $nucleo_id;
    }
    
    // Obter núcleo ativo da sessão
    public static function getNucleoAtivo() {
        return $_SESSION['nucleo_ativo'] ?? null;
    }
    
    // Limpar núcleo ativo
    public static function clearNucleoAtivo() {
        unset($_SESSION['nucleo_ativo']);
    }
    
    // Obter dados do núcleo ativo
    public static function getDadosNucleoAtivo() {
        $nucleo_id = self::getNucleoAtivo();
        if (!$nucleo_id) return null;
        
        $sql = "SELECT * FROM nucleos WHERE id = ? AND ativo = 1";
        $stmt = executeQuery($sql, [$nucleo_id]);
        return $stmt->fetch();
    }
    
    // Obter núcleos que o usuário tem acesso
    public static function getNucleosUsuario($usuario_id) {
        $sql = "SELECT n.* FROM nucleos n 
                INNER JOIN usuarios_nucleos un ON n.id = un.nucleo_id 
                WHERE un.usuario_id = ? AND n.ativo = 1 
                ORDER BY n.nome";
        $stmt = executeQuery($sql, [$usuario_id]);
        return $stmt->fetchAll();
    }
    
    // Verificar se usuário tem acesso ao núcleo
    public static function usuarioTemAcesso($usuario_id, $nucleo_id) {
        $sql = "SELECT COUNT(*) as count FROM usuarios_nucleos 
                WHERE usuario_id = ? AND nucleo_id = ?";
        $stmt = executeQuery($sql, [$usuario_id, $nucleo_id]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    // Middleware para proteger páginas que precisam de núcleo específico
    public static function protectWithNucleo($nucleos_permitidos = null) {
        $nucleo_ativo = self::getNucleoAtivo();
        
        if (!$nucleo_ativo) {
            header('Location: ' . SITE_URL . '/modules/dashboard/selecionar_nucleo.php');
            exit;
        }
        
        // Se especificou núcleos permitidos, verificar
        if ($nucleos_permitidos) {
            $dados_nucleo = self::getDadosNucleoAtivo();
            if (!$dados_nucleo || !in_array($dados_nucleo['nome'], $nucleos_permitidos)) {
                die('
                    <div style="text-align: center; margin-top: 50px;">
                        <h2>Acesso Negado!</h2>
                        <p>Você não tem acesso a este núcleo.</p>
                        <a href="' . SITE_URL . '/modules/dashboard/selecionar_nucleo.php">Selecionar Núcleo</a>
                    </div>
                ');
            }
        }
        
        return self::getDadosNucleoAtivo();
    }
}
?>