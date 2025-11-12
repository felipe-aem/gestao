<?php
// includes/EnvolvidosHelper.php

class EnvolvidosHelper {
    
    /**
     * Busca os envolvidos de uma tarefa
     */
    public static function buscarTarefa($tarefa_id, $limite = 2) {
        try {
            $sql = "SELECT u.nome, u.email 
                    FROM tarefa_envolvidos te 
                    INNER JOIN usuarios u ON te.usuario_id = u.id 
                    WHERE te.tarefa_id = ?
                    ORDER BY u.nome
                    " . ($limite ? "LIMIT $limite" : "");
            
            $stmt = executeQuery($sql, [$tarefa_id]);
            $envolvidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sql_count = "SELECT COUNT(*) as total FROM tarefa_envolvidos WHERE tarefa_id = ?";
            $stmt_count = executeQuery($sql_count, [$tarefa_id]);
            $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'envolvidos' => $envolvidos,
                'total' => $total
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar envolvidos da tarefa: " . $e->getMessage());
            return ['envolvidos' => [], 'total' => 0];
        }
    }
    
    /**
     * Busca os envolvidos de um prazo
     */
    public static function buscarPrazo($prazo_id, $limite = 2) {
        try {
            $sql = "SELECT u.nome, u.email 
                    FROM prazo_envolvidos pe 
                    INNER JOIN usuarios u ON pe.usuario_id = u.id 
                    WHERE pe.prazo_id = ?
                    ORDER BY u.nome
                    " . ($limite ? "LIMIT $limite" : "");
            
            $stmt = executeQuery($sql, [$prazo_id]);
            $envolvidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sql_count = "SELECT COUNT(*) as total FROM prazo_envolvidos WHERE prazo_id = ?";
            $stmt_count = executeQuery($sql_count, [$prazo_id]);
            $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'envolvidos' => $envolvidos,
                'total' => $total
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar envolvidos do prazo: " . $e->getMessage());
            return ['envolvidos' => [], 'total' => 0];
        }
    }
    
    /**
     * Busca os envolvidos de uma audiência
     */
    public static function buscarAudiencia($audiencia_id, $limite = 2) {
        try {
            $sql = "SELECT u.nome, u.email 
                    FROM audiencia_envolvidos ae 
                    INNER JOIN usuarios u ON ae.usuario_id = u.id 
                    WHERE ae.audiencia_id = ?
                    ORDER BY u.nome
                    " . ($limite ? "LIMIT $limite" : "");
            
            $stmt = executeQuery($sql, [$audiencia_id]);
            $envolvidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sql_count = "SELECT COUNT(*) as total FROM audiencia_envolvidos WHERE audiencia_id = ?";
            $stmt_count = executeQuery($sql_count, [$audiencia_id]);
            $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'envolvidos' => $envolvidos,
                'total' => $total
            ];
        } catch (Exception $e) {
            error_log("Erro ao buscar envolvidos da audiência: " . $e->getMessage());
            return ['envolvidos' => [], 'total' => 0];
        }
    }
    
    /**
     * Renderiza as tags de envolvidos (para listagens/cards)
     */
    public static function renderTags($envolvidos, $total, $limite = 2) {
        if (empty($envolvidos)) {
            return '';
        }
        
        $html = '<div class="envolvidos-inline">';
        
        foreach ($envolvidos as $env) {
            $nome = htmlspecialchars($env['nome']);
            $email = htmlspecialchars($env['email']);
            
            $html .= '<span class="envolvido-mini" title="' . $email . '">' . $nome . '</span>';
        }
        
        if ($total > $limite) {
            $restantes = $total - $limite;
            $html .= '<span class="envolvidos-contador" title="Mais ' . $restantes . ' envolvido(s)">+' . $restantes . '</span>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Renderiza os envolvidos em formato de lista (para visualização)
     */
    public static function renderLista($envolvidos) {
        if (empty($envolvidos)) {
            return '<span style="color: #999; font-style: italic;">Nenhum envolvido</span>';
        }
        
        $html = '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
        
        foreach ($envolvidos as $env) {
            $nome = htmlspecialchars($env['nome']);
            $email = htmlspecialchars($env['email']);
            
            $html .= '<span style="
                background: rgba(102, 126, 234, 0.1);
                color: #667eea;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                border: 1px solid rgba(102, 126, 234, 0.3);
            " title="' . $email . '">
                👥 ' . $nome . '
            </span>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Busca envolvidos de acordo com o tipo
     */
    public static function buscar($tipo, $id, $limite = 2) {
        switch ($tipo) {
            case 'tarefa':
                return self::buscarTarefa($id, $limite);
            case 'prazo':
                return self::buscarPrazo($id, $limite);
            case 'audiencia':
                return self::buscarAudiencia($id, $limite);
            default:
                return ['envolvidos' => [], 'total' => 0];
        }
    }
}
?>