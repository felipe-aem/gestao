<?php
/**
 * Helper para gerenciamento de tarefas de visita de prospecção
 * Alencar Martinazzo Advocacia
 */

class TarefasVisitaHelper {
    
    /**
     * Criar tarefa de visita automaticamente
     */
    public static function criarTarefaVisita($prospeccao_id, $tipo_tarefa, $responsavel_id, $usuario_criador_id, $dias_futuro = 7) {
        try {
            $data_agendada = date('Y-m-d', strtotime("+{$dias_futuro} days"));
            
            $sql = "INSERT INTO prospeccao_tarefas_visita (
                        prospeccao_id, tipo_tarefa, data_agendada, 
                        responsavel_id, criado_por, status, observacoes
                    ) VALUES (?, ?, ?, ?, ?, 'Pendente', ?)";
            
            $observacao = "Tarefa criada automaticamente ao mover para {$tipo_tarefa}";
            
            $stmt = executeQuery($sql, [
                $prospeccao_id,
                $tipo_tarefa,
                $data_agendada,
                $responsavel_id,
                $usuario_criador_id,
                $observacao
            ]);
            
            $tarefa_id = getConnection()->lastInsertId();
            
            // Registrar no histórico
            self::registrarHistoricoTarefa($tarefa_id, 'Criada', null, $data_agendada, null, $responsavel_id, $usuario_criador_id, $observacao);
            
            // Criar evento na agenda
            self::sincronizarComAgenda($tarefa_id);
            
            return $tarefa_id;
            
        } catch (Exception $e) {
            error_log("Erro ao criar tarefa de visita: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Registrar ação no histórico da tarefa
     */
    public static function registrarHistoricoTarefa($tarefa_id, $acao, $data_anterior, $data_nova, $responsavel_anterior_id, $responsavel_novo_id, $usuario_id, $observacao = '') {
        try {
            $sql = "INSERT INTO prospeccao_tarefas_historico (
                        tarefa_id, acao, data_anterior, data_nova,
                        responsavel_anterior_id, responsavel_novo_id,
                        observacao, usuario_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            executeQuery($sql, [
                $tarefa_id,
                $acao,
                $data_anterior,
                $data_nova,
                $responsavel_anterior_id,
                $responsavel_novo_id,
                $observacao,
                $usuario_id
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Sincronizar tarefa com a agenda
     */
    public static function sincronizarComAgenda($tarefa_id) {
        try {
            // Buscar dados da tarefa
            $sql = "SELECT ptv.*, p.nome as prospeccao_nome, p.telefone, p.cidade
                    FROM prospeccao_tarefas_visita ptv
                    INNER JOIN prospeccoes p ON ptv.prospeccao_id = p.id
                    WHERE ptv.id = ?";
            
            $stmt = executeQuery($sql, [$tarefa_id]);
            $tarefa = $stmt->fetch();
            
            if (!$tarefa) {
                return false;
            }
            
            // Verificar se já existe evento na agenda
            if (!empty($tarefa['agenda_id'])) {
                // Atualizar evento existente
                return self::atualizarEventoAgenda($tarefa);
            } else {
                // Criar novo evento
                return self::criarEventoAgenda($tarefa);
            }
            
        } catch (Exception $e) {
            error_log("Erro ao sincronizar com agenda: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Criar evento na agenda
     */
    private static function criarEventoAgenda($tarefa) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            // Determinar hora (se não tiver, usar 09:00)
            $hora = $tarefa['hora_agendada'] ?? '09:00:00';
            $data_inicio = $tarefa['data_agendada'] . ' ' . $hora;
            $data_fim = date('Y-m-d H:i:s', strtotime($data_inicio . ' +1 hour'));
            
            // Criar evento na agenda
            $titulo = "{$tarefa['tipo_tarefa']}: {$tarefa['prospeccao_nome']}";
            $descricao = "Tarefa de prospecção\n";
            $descricao .= "Cliente: {$tarefa['prospeccao_nome']}\n";
            $descricao .= "Telefone: {$tarefa['telefone']}\n";
            $descricao .= "Cidade: {$tarefa['cidade']}\n";
            if (!empty($tarefa['observacoes'])) {
                $descricao .= "\nObservações: {$tarefa['observacoes']}";
            }
            
            $sql_agenda = "INSERT INTO agenda (
                              titulo, descricao, data_inicio, data_fim,
                              tipo, status, prioridade, criado_por
                          ) VALUES (?, ?, ?, ?, 'Visita Cliente', 'Pendente', 'Media', ?)";
            
            $stmt = $pdo->prepare($sql_agenda);
            $stmt->execute([
                $titulo,
                $descricao,
                $data_inicio,
                $data_fim,
                $tarefa['criado_por']
            ]);
            
            $agenda_id = $pdo->lastInsertId();
            
            // Adicionar participante (responsável)
            $sql_participante = "INSERT INTO agenda_participantes (
                                    agenda_id, usuario_id, status_participacao
                                ) VALUES (?, ?, 'Organizador')";
            $stmt_part = $pdo->prepare($sql_participante);
            $stmt_part->execute([$agenda_id, $tarefa['responsavel_id']]);
            
            // Atualizar tarefa com o agenda_id
            $sql_update = "UPDATE prospeccao_tarefas_visita SET agenda_id = ? WHERE id = ?";
            $stmt_upd = $pdo->prepare($sql_update);
            $stmt_upd->execute([$agenda_id, $tarefa['id']]);
            
            $pdo->commit();
            
            // Registrar sincronização no histórico
            self::registrarHistoricoTarefa(
                $tarefa['id'],
                'Sincronizada Agenda',
                null,
                null,
                null,
                null,
                $tarefa['responsavel_id'],
                "Evento criado na agenda (ID: {$agenda_id})"
            );
            
            return $agenda_id;
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao criar evento na agenda: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Atualizar evento existente na agenda
     */
    private static function atualizarEventoAgenda($tarefa) {
        try {
            $hora = $tarefa['hora_agendada'] ?? '09:00:00';
            $data_inicio = $tarefa['data_agendada'] . ' ' . $hora;
            $data_fim = date('Y-m-d H:i:s', strtotime($data_inicio . ' +1 hour'));
            
            $titulo = "{$tarefa['tipo_tarefa']}: {$tarefa['prospeccao_nome']}";
            
            $sql = "UPDATE agenda 
                    SET titulo = ?, 
                        data_inicio = ?, 
                        data_fim = ?
                    WHERE id = ?";
            
            executeQuery($sql, [
                $titulo,
                $data_inicio,
                $data_fim,
                $tarefa['agenda_id']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao atualizar evento na agenda: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reagendar tarefa
     */
    public static function reagendarTarefa($tarefa_id, $nova_data, $nova_hora, $motivo, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            // Buscar dados atuais
            $sql_atual = "SELECT * FROM prospeccao_tarefas_visita WHERE id = ?";
            $stmt = $pdo->prepare($sql_atual);
            $stmt->execute([$tarefa_id]);
            $tarefa_atual = $stmt->fetch();
            
            // Atualizar tarefa
            $sql_update = "UPDATE prospeccao_tarefas_visita 
                          SET data_agendada = ?, 
                              hora_agendada = ?,
                              status = 'Reagendada',
                              motivo_reagendamento = ?
                          WHERE id = ?";
            
            $stmt_upd = $pdo->prepare($sql_update);
            $stmt_upd->execute([$nova_data, $nova_hora, $motivo, $tarefa_id]);
            
            // Registrar no histórico
            self::registrarHistoricoTarefa(
                $tarefa_id,
                'Reagendada',
                $tarefa_atual['data_agendada'],
                $nova_data,
                null,
                null,
                $usuario_id,
                $motivo
            );
            
            $pdo->commit();
            
            // Sincronizar com agenda
            self::sincronizarComAgenda($tarefa_id);
            
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao reagendar tarefa: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Transferir tarefa para outro responsável
     */
    public static function transferirTarefa($tarefa_id, $novo_responsavel_id, $motivo, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            // Buscar dados atuais
            $sql_atual = "SELECT * FROM prospeccao_tarefas_visita WHERE id = ?";
            $stmt = $pdo->prepare($sql_atual);
            $stmt->execute([$tarefa_id]);
            $tarefa_atual = $stmt->fetch();
            
            // Salvar responsável original se ainda não tiver
            $responsavel_original = $tarefa_atual['responsavel_original_id'] ?? $tarefa_atual['responsavel_id'];
            
            // Atualizar tarefa
            $sql_update = "UPDATE prospeccao_tarefas_visita 
                          SET responsavel_id = ?,
                              responsavel_original_id = ?
                          WHERE id = ?";
            
            $stmt_upd = $pdo->prepare($sql_update);
            $stmt_upd->execute([$novo_responsavel_id, $responsavel_original, $tarefa_id]);
            
            // Registrar no histórico
            self::registrarHistoricoTarefa(
                $tarefa_id,
                'Transferida',
                null,
                null,
                $tarefa_atual['responsavel_id'],
                $novo_responsavel_id,
                $usuario_id,
                $motivo
            );
            
            // Atualizar participantes na agenda
            if (!empty($tarefa_atual['agenda_id'])) {
                // Remover participante antigo
                $sql_del = "DELETE FROM agenda_participantes 
                           WHERE agenda_id = ? AND usuario_id = ?";
                $stmt_del = $pdo->prepare($sql_del);
                $stmt_del->execute([$tarefa_atual['agenda_id'], $tarefa_atual['responsavel_id']]);
                
                // Adicionar novo participante
                $sql_add = "INSERT INTO agenda_participantes (agenda_id, usuario_id, status_participacao)
                           VALUES (?, ?, 'Organizador')";
                $stmt_add = $pdo->prepare($sql_add);
                $stmt_add->execute([$tarefa_atual['agenda_id'], $novo_responsavel_id]);
            }
            
            $pdo->commit();
            
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao transferir tarefa: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Marcar tarefa como concluída
     */
    public static function concluirTarefa($tarefa_id, $observacoes, $usuario_id) {
        try {
            $pdo = getConnection();
            $pdo->beginTransaction();
            
            // Atualizar tarefa
            $sql_update = "UPDATE prospeccao_tarefas_visita 
                          SET status = 'Concluída',
                              data_conclusao = NOW(),
                              observacoes = CONCAT(COALESCE(observacoes, ''), '\n\nConcluída: ', ?)
                          WHERE id = ?";
            
            $stmt = $pdo->prepare($sql_update);
            $stmt->execute([$observacoes, $tarefa_id]);
            
            // Registrar no histórico
            self::registrarHistoricoTarefa(
                $tarefa_id,
                'Concluída',
                null,
                null,
                null,
                null,
                $usuario_id,
                $observacoes
            );
            
            // Atualizar status na agenda
            $sql_agenda = "UPDATE agenda a
                          INNER JOIN prospeccao_tarefas_visita ptv ON a.id = ptv.agenda_id
                          SET a.status = 'Concluído'
                          WHERE ptv.id = ?";
            $stmt_agenda = $pdo->prepare($sql_agenda);
            $stmt_agenda->execute([$tarefa_id]);
            
            $pdo->commit();
            
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            error_log("Erro ao concluir tarefa: " . $e->getMessage());
            return false;
        }
    }
}
?>