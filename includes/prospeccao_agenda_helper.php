<?php
/**
 * ETAPA 2: FUNÇÕES DE INTEGRAÇÃO PROSPECÇÃO <-> AGENDA
 * Arquivo: includes/prospeccao_agenda_helper.php
 * 
 * Este arquivo contém todas as funções para integrar
 * o sistema de prospecção com a agenda
 */

require_once __DIR__ . '/../config/database.php';

class ProspeccaoAgendaHelper {
    
    /**
     * Criar tarefa de visita e evento na agenda
     */
    public static function criarTarefaVisita($prospeccao_id, $tipo_tarefa, $data_agendada, $hora_agendada, $responsavel_id, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            // 1. Criar tarefa de visita
            $sql_tarefa = "INSERT INTO prospeccao_tarefas_visita (
                            prospeccao_id,
                            tipo_tarefa,
                            data_agendada,
                            hora_agendada,
                            responsavel_id,
                            status,
                            criado_por,
                            criado_em
                           ) VALUES (?, ?, ?, ?, ?, 'Pendente', ?, NOW())";
            
            $stmt_tarefa = $pdo->prepare($sql_tarefa);
            $stmt_tarefa->execute([
                $prospeccao_id,
                $tipo_tarefa,
                $data_agendada,
                $hora_agendada,
                $responsavel_id,
                $usuario_id
            ]);
            
            $tarefa_id = $pdo->lastInsertId();
            
            // 2. Verificar se deve criar evento na agenda
            $criar_evento = self::getConfiguracao('criar_evento_agenda_automatico', 'true');
            
            $agenda_id = null;
            if ($criar_evento === 'true') {
                // Chamar procedure para criar evento
                $sql_procedure = "CALL criar_evento_visita_agenda(?, ?, ?, ?, ?, ?, ?, @agenda_id)";
                $stmt_proc = $pdo->prepare($sql_procedure);
                $stmt_proc->execute([
                    $tarefa_id,
                    $prospeccao_id,
                    $tipo_tarefa,
                    $data_agendada,
                    $hora_agendada,
                    $responsavel_id,
                    $usuario_id
                ]);
                
                // Pegar o ID retornado
                $result = $pdo->query("SELECT @agenda_id as agenda_id")->fetch();
                $agenda_id = $result['agenda_id'];
            }
            
            // 3. Registrar no histórico da prospecção
            self::registrarHistoricoProspeccao(
                $prospeccao_id,
                null,
                $tipo_tarefa === 'Visita Semanal' ? 'Visita Semanal' : null,
                "Tarefa de {$tipo_tarefa} criada e agendada para " . date('d/m/Y', strtotime($data_agendada)),
                $usuario_id
            );
            
            // 4. Criar notificação para o responsável
            $notificar = self::getConfiguracao('notificar_responsavel_visita', 'true');
            if ($notificar === 'true' && $responsavel_id != $usuario_id) {
                self::criarNotificacao(
                    $responsavel_id,
                    'tarefa_atribuida',
                    'Nova Tarefa de Visita',
                    "Você foi designado para uma {$tipo_tarefa}",
                    "/modules/prospeccao/visualizar.php?id={$prospeccao_id}",
                    'fas fa-calendar-check',
                    '#9b59b6'
                );
            }
            
            $pdo->commit();
            
            return [
                'success' => true,
                'tarefa_id' => $tarefa_id,
                'agenda_id' => $agenda_id,
                'message' => 'Tarefa criada com sucesso!'
            ];
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao criar tarefa de visita: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao criar tarefa: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reagendar tarefa de visita
     */
    public static function reagendarTarefa($tarefa_id, $nova_data, $nova_hora, $motivo, $usuario_id) {
        try {
            $pdo = getConnection();
            
            // Chamar procedure de reagendamento
            $sql = "CALL reagendar_tarefa_visita(?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tarefa_id,
                $nova_data,
                $nova_hora,
                $motivo,
                $usuario_id
            ]);
            
            // Buscar info da tarefa para notificação
            $tarefa = self::buscarTarefa($tarefa_id);
            
            // Notificar responsável
            if ($tarefa && $tarefa['responsavel_id'] != $usuario_id) {
                self::criarNotificacao(
                    $tarefa['responsavel_id'],
                    'tarefa_reagendada',
                    'Tarefa Reagendada',
                    "Sua visita foi reagendada para " . date('d/m/Y', strtotime($nova_data)),
                    "/modules/prospeccao/visualizar.php?id={$tarefa['prospeccao_id']}",
                    'fas fa-calendar-alt',
                    '#f39c12'
                );
            }
            
            return [
                'success' => true,
                'message' => 'Tarefa reagendada com sucesso!'
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao reagendar tarefa: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao reagendar: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Transferir tarefa para outro usuário
     */
    public static function transferirTarefa($tarefa_id, $novo_responsavel_id, $motivo, $usuario_id) {
        try {
            $pdo = getConnection();
            
            // Buscar responsável atual antes de transferir
            $tarefa = self::buscarTarefa($tarefa_id);
            $responsavel_antigo = $tarefa['responsavel_id'];
            
            // Chamar procedure de transferência
            $sql = "CALL transferir_tarefa_visita(?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tarefa_id,
                $novo_responsavel_id,
                $motivo,
                $usuario_id
            ]);
            
            // Notificar novo responsável
            self::criarNotificacao(
                $novo_responsavel_id,
                'tarefa_atribuida',
                'Tarefa Transferida para Você',
                "Uma tarefa de visita foi transferida para você",
                "/modules/prospeccao/visualizar.php?id={$tarefa['prospeccao_id']}",
                'fas fa-exchange-alt',
                '#16a085'
            );
            
            // Notificar responsável antigo
            if ($responsavel_antigo != $usuario_id) {
                self::criarNotificacao(
                    $responsavel_antigo,
                    'tarefa_transferida',
                    'Tarefa Transferida',
                    "Sua tarefa de visita foi transferida para outro usuário",
                    "/modules/prospeccao/visualizar.php?id={$tarefa['prospeccao_id']}",
                    'fas fa-exchange-alt',
                    '#95a5a6'
                );
            }
            
            return [
                'success' => true,
                'message' => 'Tarefa transferida com sucesso!'
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao transferir tarefa: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao transferir: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Concluir tarefa de visita
     */
    public static function concluirTarefa($tarefa_id, $observacoes, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            $tarefa = self::buscarTarefa($tarefa_id);
            
            // Atualizar status da tarefa
            $sql = "UPDATE prospeccao_tarefas_visita 
                    SET status = 'Concluída',
                        data_conclusao = NOW(),
                        observacoes = ?,
                        atualizado_em = NOW()
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$observacoes, $tarefa_id]);
            
            // Atualizar evento na agenda
            if ($tarefa['agenda_id']) {
                $sql_agenda = "UPDATE agenda 
                               SET status = 'Realizado',
                                   data_atualizacao = NOW()
                               WHERE id = ?";
                $stmt_agenda = $pdo->prepare($sql_agenda);
                $stmt_agenda->execute([$tarefa['agenda_id']]);
            }
            
            // Registrar no histórico
            $sql_hist = "INSERT INTO prospeccao_tarefas_historico (
                            tarefa_visita_id,
                            acao,
                            observacao,
                            usuario_id,
                            data_acao
                         ) VALUES (?, 'Concluída', ?, ?, NOW())";
            
            $stmt_hist = $pdo->prepare($sql_hist);
            $stmt_hist->execute([$tarefa_id, $observacoes, $usuario_id]);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Tarefa concluída com sucesso!'
            ];
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao concluir tarefa: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao concluir: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cancelar tarefa de visita
     */
    public static function cancelarTarefa($tarefa_id, $motivo, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            $tarefa = self::buscarTarefa($tarefa_id);
            
            // Atualizar status da tarefa
            $sql = "UPDATE prospeccao_tarefas_visita 
                    SET status = 'Cancelada',
                        observacoes = ?,
                        atualizado_em = NOW()
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$motivo, $tarefa_id]);
            
            // Atualizar evento na agenda
            if ($tarefa['agenda_id']) {
                $sql_agenda = "UPDATE agenda 
                               SET status = 'Cancelado',
                                   data_atualizacao = NOW()
                               WHERE id = ?";
                $stmt_agenda = $pdo->prepare($sql_agenda);
                $stmt_agenda->execute([$tarefa['agenda_id']]);
            }
            
            // Registrar no histórico
            $sql_hist = "INSERT INTO prospeccao_tarefas_historico (
                            tarefa_visita_id,
                            acao,
                            motivo,
                            usuario_id,
                            data_acao
                         ) VALUES (?, 'Cancelada', ?, ?, NOW())";
            
            $stmt_hist = $pdo->prepare($sql_hist);
            $stmt_hist->execute([$tarefa_id, $motivo, $usuario_id]);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Tarefa cancelada com sucesso!'
            ];
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao cancelar tarefa: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao cancelar: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Buscar informações de uma tarefa
     */
    private static function buscarTarefa($tarefa_id) {
        try {
            $sql = "SELECT tv.*, p.nome as prospeccao_nome
                    FROM prospeccao_tarefas_visita tv
                    LEFT JOIN prospeccoes p ON tv.prospeccao_id = p.id
                    WHERE tv.id = ?";
            
            $stmt = executeQuery($sql, [$tarefa_id]);
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Erro ao buscar tarefa: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Buscar configuração do sistema
     */
    private static function getConfiguracao($chave, $padrao = null) {
        try {
            $sql = "SELECT valor FROM prospeccao_configuracoes WHERE chave = ? LIMIT 1";
            $stmt = executeQuery($sql, [$chave]);
            $result = $stmt->fetch();
            
            return $result ? $result['valor'] : $padrao;
            
        } catch (Exception $e) {
            return $padrao;
        }
    }
    
    /**
     * Registrar no histórico da prospecção
     */
    private static function registrarHistoricoProspeccao($prospeccao_id, $fase_anterior, $fase_nova, $observacao, $usuario_id) {
        try {
            $sql = "INSERT INTO prospeccoes_historico (
                        prospeccao_id,
                        fase_anterior,
                        fase_nova,
                        observacao,
                        usuario_id,
                        data_movimento
                    ) VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt = executeQuery($sql, [
                $prospeccao_id,
                $fase_anterior,
                $fase_nova,
                $observacao,
                $usuario_id
            ]);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
        }
    }
    
    /**
     * Criar notificação para usuário
     */
    private static function criarNotificacao($usuario_id, $tipo, $titulo, $mensagem, $link, $icone, $cor) {
        try {
            $sql = "INSERT INTO notificacoes_sistema (
                        usuario_id,
                        tipo,
                        titulo,
                        mensagem,
                        link,
                        icone,
                        cor,
                        prioridade,
                        data_criacao
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'normal', NOW())";
            
            $stmt = executeQuery($sql, [
                $usuario_id,
                $tipo,
                $titulo,
                $mensagem,
                $link,
                $icone,
                $cor
            ]);
            
        } catch (Exception $e) {
            error_log("Erro ao criar notificação: " . $e->getMessage());
        }
    }
    
    /**
     * Calcular data padrão para visita semanal
     */
    public static function calcularDataVisitaSemanal() {
        $dias = self::getConfiguracao('dias_visita_semanal_padrao', '7');
        return date('Y-m-d', strtotime("+{$dias} days"));
    }
    
    /**
     * Calcular data padrão para revisita
     */
    public static function calcularDataRevisita() {
        $dias = self::getConfiguracao('dias_revisita_padrao', '30');
        return date('Y-m-d', strtotime("+{$dias} days"));
    }
}
?>