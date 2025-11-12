<?php
// includes/notificacoes_gatilhos.php
/**
 * Helper para disparar notificações em ações do sistema
 */

require_once __DIR__ . '/notificacoes_helper.php';

class NotificacoesGatilhos {
    
    /**
     * Notificar quando publicação é vinculada a processo
     */
    public static function publicacaoVinculada($publicacao_id, $processo_id) {
        try {
            // Buscar dados do processo
            $sql = "SELECT p.responsavel_id, p.numero_processo, u.nome as responsavel_nome
                    FROM processos p
                    LEFT JOIN usuarios u ON p.responsavel_id = u.id
                    WHERE p.id = ?";
            $stmt = executeQuery($sql, [$processo_id]);
            $processo = $stmt->fetch();
            
            if (!$processo || !$processo['responsavel_id']) {
                return false;
            }
            
            // Não notificar se for o próprio responsável fazendo a vinculação
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            if ($processo['responsavel_id'] == $usuario_atual) {
                return false;
            }
            
            // Criar notificação
            return Notificacoes::publicacaoVinculada(
                $processo['responsavel_id'],
                $publicacao_id,
                $processo['numero_processo']
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar publicação vinculada: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notificar quando tarefa é atribuída
     */
    public static function tarefaAtribuida($tarefa_id, $responsavel_id, $titulo) {
        try {
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            
            // Não notificar se atribuiu para si mesmo
            if ($responsavel_id == $usuario_atual) {
                return false;
            }
            
            // Buscar nome do usuário que atribuiu
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = executeQuery($sql, [$usuario_atual]);
            $usuario = $stmt->fetch();
            
            $atribuidor = $usuario ? $usuario['nome'] : 'Alguém';
            
            return Notificacoes::tarefaAtribuida(
                $responsavel_id,
                $tarefa_id,
                $titulo,
                $atribuidor
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar tarefa atribuída: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notificar quando alguém é convidado para evento
     */
    public static function conviteEvento($evento_id, $participante_id, $titulo) {
        try {
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            
            // Não notificar o organizador
            if ($participante_id == $usuario_atual) {
                return false;
            }
            
            // Buscar nome do organizador
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = executeQuery($sql, [$usuario_atual]);
            $usuario = $stmt->fetch();
            
            $organizador = $usuario ? $usuario['nome'] : 'Alguém';
            
            return Notificacoes::conviteEvento(
                $participante_id,
                $evento_id,
                $titulo,
                $organizador
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar convite: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notificar quando processo é criado
     */
    public static function processoCriado($processo_id, $responsavel_id, $numero_processo) {
        try {
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            
            // Não notificar se criou para si mesmo
            if ($responsavel_id == $usuario_atual) {
                return false;
            }
            
            return Notificacoes::processoCriado(
                $responsavel_id,
                $processo_id,
                $numero_processo
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar processo criado: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notificar quando tarefa muda de responsável
     */
    public static function tarefaReatribuida($tarefa_id, $responsavel_antigo_id, $responsavel_novo_id, $titulo) {
        try {
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            
            // Notificar apenas o novo responsável
            if ($responsavel_novo_id == $usuario_atual) {
                return false;
            }
            
            // Buscar nome do usuário que reatribuiu
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = executeQuery($sql, [$usuario_atual]);
            $usuario = $stmt->fetch();
            
            $reatribuidor = $usuario ? $usuario['nome'] : 'Alguém';
            
            return Notificacoes::criar(
                $responsavel_novo_id,
                'tarefa_atribuida',
                'Tarefa Reatribuída',
                "{$reatribuidor} reatribuiu a tarefa '{$titulo}' para você",
                '/modules/tarefas/visualizar.php?id=' . $tarefa_id,
                'normal'
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar reatribuição: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notificar todos os participantes de um processo sobre atualização
     */
    public static function processoAtualizado($processo_id, $alteracao) {
        try {
            // Buscar processo e responsável
            $sql = "SELECT p.responsavel_id, p.numero_processo 
                    FROM processos p
                    WHERE p.id = ?";
            $stmt = executeQuery($sql, [$processo_id]);
            $processo = $stmt->fetch();
            
            if (!$processo || !$processo['responsavel_id']) {
                return false;
            }
            
            $usuario_atual = $_SESSION['usuario_id'] ?? 0;
            
            // Não notificar quem fez a alteração
            if ($processo['responsavel_id'] == $usuario_atual) {
                return false;
            }
            
            return Notificacoes::processoAtualizado(
                $processo['responsavel_id'],
                $processo_id,
                $processo['numero_processo'],
                $alteracao
            );
            
        } catch (Exception $e) {
            error_log("Erro ao notificar atualização de processo: " . $e->getMessage());
            return false;
        }
    }
}
?>