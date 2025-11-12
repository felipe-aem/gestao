<?php
// includes/notificacoes_helper.php

class Notificacoes {
    
    /**
     * Criar nova notificação
     */
    public static function criar($usuario_id, $tipo, $titulo, $mensagem = '', $link = '', $prioridade = 'normal', $expira_em = null) {
        try {
            $sql = "INSERT INTO notificacoes_sistema 
                    (usuario_id, tipo, titulo, mensagem, link, prioridade, expira_em, data_criacao)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            executeQuery($sql, [
                $usuario_id, 
                $tipo, 
                $titulo, 
                $mensagem, 
                $link, 
                $prioridade,
                $expira_em
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao criar notificação: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Buscar notificações
     */
    public static function buscar($usuario_id, $apenas_nao_lidas = false, $limite = 10) {
        try {
            $where = "usuario_id = ? AND (expira_em IS NULL OR expira_em > NOW())";
            $params = [$usuario_id];
            
            if ($apenas_nao_lidas) {
                $where .= " AND lida = 0";
            }
            
            $sql = "SELECT * FROM notificacoes_sistema 
                    WHERE {$where}
                    ORDER BY lida ASC, data_criacao DESC
                    LIMIT ?";
            
            $params[] = $limite;
            
            $stmt = executeQuery($sql, $params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar notificações: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Contar não lidas
     */
    public static function contarNaoLidas($usuario_id) {
        try {
            $sql = "SELECT COUNT(*) as total FROM notificacoes_sistema 
                    WHERE usuario_id = ? 
                    AND lida = 0
                    AND (expira_em IS NULL OR expira_em > NOW())";
            
            $stmt = executeQuery($sql, [$usuario_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)$result['total'];
        } catch (Exception $e) {
            error_log("Erro ao contar notificações: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Marcar como lida
     */
    public static function marcarComoLida($notificacao_id, $usuario_id) {
        try {
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW()
                    WHERE id = ? AND usuario_id = ?";
            
            executeQuery($sql, [$notificacao_id, $usuario_id]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao marcar como lida: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Marcar todas como lidas
     */
    public static function marcarTodasComoLidas($usuario_id) {
        try {
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW()
                    WHERE usuario_id = ? AND lida = 0";
            
            executeQuery($sql, [$usuario_id]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao marcar todas como lidas: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * NOTIFICAÇÕES ESPECÍFICAS
     */
    
    // Publicação nova
    public static function publicacaoNova($usuario_id, $publicacao_id, $numero_processo) {
        return self::criar(
            $usuario_id,
            'publicacao_nova',
            'Nova Publicação',
            "Nova publicação no processo {$numero_processo}",
            '/modules/publicacoes/visualizar.php?id=' . $publicacao_id,
            'alta'
        );
    }
    
    // Publicação vinculada
    public static function publicacaoVinculada($usuario_id, $publicacao_id, $numero_processo) {
        return self::criar(
            $usuario_id,
            'publicacao_vinculada',
            'Publicação Vinculada',
            "Publicação vinculada ao processo {$numero_processo}",
            '/modules/publicacoes/visualizar.php?id=' . $publicacao_id,
            'normal'
        );
    }
    
    // Prazo vencendo
    public static function prazoVencendo($usuario_id, $prazo_id, $titulo, $dias) {
        $prioridade = match(true) {
            $dias == 0 => 'alta',
            $dias <= 2 => 'alta',
            $dias <= 5 => 'normal',
            default => 'baixa'
        };
        
        $msg = match($dias) {
            0 => "⚠️ Prazo vence HOJE!",
            1 => "Prazo vence amanhã",
            default => "Prazo vence em {$dias} dias"
        };
        
        return self::criar(
            $usuario_id,
            'prazo_vencendo',
            $titulo,
            $msg,
            '/modules/prazos/visualizar.php?id=' . $prazo_id,
            $prioridade
        );
    }
    
    // Prazo vencido
    public static function prazoVencido($usuario_id, $prazo_id, $titulo, $dias_atraso) {
        return self::criar(
            $usuario_id,
            'prazo_vencido',
            '🚨 Prazo Vencido',
            "Prazo '{$titulo}' venceu há {$dias_atraso} dia(s)",
            '/modules/prazos/visualizar.php?id=' . $prazo_id,
            'alta'
        );
    }
    
    // Tarefa atribuída
    public static function tarefaAtribuida($usuario_id, $tarefa_id, $titulo, $atribuidor) {
        return self::criar(
            $usuario_id,
            'tarefa_atribuida',
            'Nova Tarefa Atribuída',
            "{$atribuidor} atribuiu a tarefa: {$titulo}",
            '/modules/tarefas/visualizar.php?id=' . $tarefa_id,
            'normal'
        );
    }
    
    // Tarefa vencendo
    public static function tarefaVencendo($usuario_id, $tarefa_id, $titulo, $dias) {
        $msg = $dias == 0 ? "Vence hoje!" : "Vence em {$dias} dia(s)";
        
        return self::criar(
            $usuario_id,
            'tarefa_vencendo',
            $titulo,
            $msg,
            '/modules/tarefas/visualizar.php?id=' . $tarefa_id,
            'normal'
        );
    }
    
    // Convite para evento
    public static function conviteEvento($usuario_id, $evento_id, $titulo, $organizador) {
        return self::criar(
            $usuario_id,
            'audiencia_proxima',
            'Convite para Evento',
            "{$organizador} convidou você: {$titulo}",
            '/modules/agenda/visualizar.php?id=' . $evento_id,
            'normal'
        );
    }
    
    // Audiência próxima
    public static function audienciaProxima($usuario_id, $audiencia_id, $titulo, $data) {
        $data_obj = new DateTime($data);
        $data_formatada = $data_obj->format('d/m/Y H:i');
        
        return self::criar(
            $usuario_id,
            'audiencia_proxima',
            'Audiência Próxima',
            "{$titulo} em {$data_formatada}",
            '/modules/audiencias/visualizar.php?id=' . $audiencia_id,
            'alta'
        );
    }
    
    // Processo criado
    public static function processoCriado($usuario_id, $processo_id, $numero_processo) {
        return self::criar(
            $usuario_id,
            'processo_criado',
            'Novo Processo Criado',
            "Processo {$numero_processo} foi cadastrado",
            '/modules/processos/visualizar.php?id=' . $processo_id,
            'normal'
        );
    }
    
    // Processo atualizado
    public static function processoAtualizado($usuario_id, $processo_id, $numero_processo, $alteracao) {
        return self::criar(
            $usuario_id,
            'processo_atualizado',
            'Processo Atualizado',
            "Processo {$numero_processo}: {$alteracao}",
            '/modules/processos/visualizar.php?id=' . $processo_id,
            'normal'
        );
    }
}

function criarNotificacao($dados) {
    try {
        if (empty($dados['usuario_id'])) {
            error_log("criarNotificacao: usuario_id é obrigatório");
            return false;
        }
        
        if (empty($dados['tipo'])) {
            error_log("criarNotificacao: tipo é obrigatório");
            return false;
        }
        
        if (empty($dados['titulo'])) {
            error_log("criarNotificacao: titulo é obrigatório");
            return false;
        }
        
        $usuario_id = (int)$dados['usuario_id'];
        $tipo = $dados['tipo'];
        $titulo = $dados['titulo'];
        $mensagem = $dados['mensagem'] ?? '';
        $link = $dados['link'] ?? '';
        $prioridade = $dados['prioridade'] ?? 'normal';
        $expira_em = $dados['expira_em'] ?? null;
        
        return Notificacoes::criar(
            $usuario_id,
            $tipo,
            $titulo,
            $mensagem,
            $link,
            $prioridade,
            $expira_em
        );
        
    } catch (Exception $e) {
        error_log("Erro em criarNotificacao: " . $e->getMessage());
        return false;
    }
}
?>
