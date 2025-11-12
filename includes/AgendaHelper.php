<?php
/**
 * AgendaHelper - Helper centralizado para criação de eventos
 * 
 * Este helper centraliza TODA a lógica de criação de tarefas, prazos e audiências,
 * garantindo que SEMPRE seja registrado histórico e enviadas notificações.
 * 
 * USO:
 * - Módulo de publicações usa este helper
 * - Módulos antigos (tarefas, prazos) são redirecionados para agenda
 * - Agenda usa seus próprios formulários que chamam este helper
 */

class AgendaHelper {
    
    /**
     * Cria uma tarefa com histórico e notificações
     * 
     * @param array $dados Dados da tarefa
     * @return array ['success' => bool, 'tarefa_id' => int, 'message' => string]
     */
    public static function criarTarefa($dados) {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        try {
            // Validar dados obrigatórios
            self::validarDadosTarefa($dados);
            
            // Preparar dados
            $processo_id = !empty($dados['processo_id']) ? $dados['processo_id'] : null;
            $publicacao_id = !empty($dados['publicacao_id']) ? $dados['publicacao_id'] : null;
            
            // Inserir tarefa
            $sql = "INSERT INTO tarefas (
                        titulo, descricao, processo_id, responsavel_id, 
                        data_vencimento, prioridade, status, criado_por, 
                        data_criacao, publicacao_id
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $dados['titulo'],
                $dados['descricao'] ?? '',
                $processo_id,
                $dados['responsavel_id'],
                $dados['data_vencimento'],
                $dados['prioridade'] ?? 'normal',
                $dados['criado_por'],
                $publicacao_id
            ]);
            
            $tarefa_id = $pdo->lastInsertId();
            error_log("Tarefa criada com ID: $tarefa_id");
            
            // Salvar etiquetas
            if (!empty($dados['etiquetas']) && is_array($dados['etiquetas'])) {
                self::salvarEtiquetas('tarefa', $tarefa_id, $dados['etiquetas'], $dados['criado_por']);
            }
            
            // Salvar envolvidos
            if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
                self::salvarEnvolvidos('tarefa', $tarefa_id, $dados['envolvidos'], $dados['responsavel_id']);
            }
            
            // Buscar informações para o histórico
            $responsavel_nome = self::getNomeUsuario($dados['responsavel_id']);
            $processo_numero = $processo_id ? self::getNumeroProcesso($processo_id) : null;
            
            // Registrar no histórico
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao('tarefa', $tarefa_id, 
                $publicacao_id ? 'Tarefa criada a partir de publicação' : 'Tarefa criada', 
                [
                    'titulo' => $dados['titulo'],
                    'responsavel_nome' => $responsavel_nome,
                    'data_vencimento' => $dados['data_vencimento'],
                    'processo_numero' => $processo_numero,
                    'publicacao_id' => $publicacao_id
                ]
            );
            
            // Registrar no histórico do processo (se vinculado)
            if ($processo_id) {
                self::registrarHistoricoProcesso(
                    $processo_id, 
                    $dados['criado_por'], 
                    'Tarefa Criada', 
                    "Tarefa criada: {$dados['titulo']}"
                );
            }
            
            // Criar notificações
            require_once __DIR__ . '/notificacoes_helper.php';
            self::criarNotificacoesTarefa($tarefa_id, $dados);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'tarefa_id' => $tarefa_id,
                'message' => 'Tarefa criada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao criar tarefa: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Cria um prazo com histórico e notificações
     * 
     * @param array $dados Dados do prazo
     * @return array ['success' => bool, 'prazo_id' => int, 'message' => string]
     */
    public static function criarPrazo($dados) {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        try {
            // Validar dados obrigatórios
            self::validarDadosPrazo($dados);
            
            // Preparar dados
            $publicacao_id = !empty($dados['publicacao_id']) ? $dados['publicacao_id'] : null;
            
            // Inserir prazo
            $sql = "INSERT INTO prazos (
                        titulo, descricao, processo_id, responsavel_id, 
                        data_vencimento, prioridade, status, criado_por, 
                        data_criacao, publicacao_id
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $dados['titulo'],
                $dados['descricao'] ?? '',
                $dados['processo_id'],
                $dados['responsavel_id'],
                $dados['data_vencimento'],
                $dados['prioridade'] ?? 'normal',
                $dados['criado_por'],
                $publicacao_id
            ]);
            
            $prazo_id = $pdo->lastInsertId();
            error_log("Prazo criado com ID: $prazo_id");
            
            // Salvar etiquetas
            if (!empty($dados['etiquetas']) && is_array($dados['etiquetas'])) {
                self::salvarEtiquetas('prazo', $prazo_id, $dados['etiquetas'], $dados['criado_por']);
            }
            
            // Salvar envolvidos
            if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
                self::salvarEnvolvidos('prazo', $prazo_id, $dados['envolvidos'], $dados['responsavel_id']);
            }
            
            // Buscar informações para o histórico
            $responsavel_nome = self::getNomeUsuario($dados['responsavel_id']);
            $processo_numero = self::getNumeroProcesso($dados['processo_id']);
            
            // Registrar no histórico
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao('prazo', $prazo_id, 
                $publicacao_id ? 'Prazo criado a partir de publicação' : 'Prazo criado', 
                [
                    'titulo' => $dados['titulo'],
                    'responsavel_nome' => $responsavel_nome,
                    'data_vencimento' => $dados['data_vencimento'],
                    'processo_numero' => $processo_numero,
                    'publicacao_id' => $publicacao_id
                ]
            );
            
            // Registrar no histórico do processo
            self::registrarHistoricoProcesso(
                $dados['processo_id'], 
                $dados['criado_por'], 
                'Prazo Criado', 
                "Prazo criado: {$dados['titulo']}"
            );
            
            // Criar notificações
            require_once __DIR__ . '/notificacoes_helper.php';
            self::criarNotificacoesPrazo($prazo_id, $dados);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'prazo_id' => $prazo_id,
                'message' => 'Prazo criado com sucesso!'
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao criar prazo: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Cria uma audiência com histórico e notificações
     * 
     * @param array $dados Dados da audiência
     * @return array ['success' => bool, 'audiencia_id' => int, 'message' => string]
     */
    public static function criarAudiencia($dados) {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        try {
            // Validar dados obrigatórios
            self::validarDadosAudiencia($dados);
            
            // Preparar dados
            $publicacao_id = !empty($dados['publicacao_id']) ? $dados['publicacao_id'] : null;
            
            // Inserir audiência
            $sql = "INSERT INTO audiencias (
                        titulo, descricao, processo_id, responsavel_id, 
                        data_inicio, data_fim, local, tipo_audiencia,
                        prioridade, status, criado_por, data_criacao, publicacao_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $dados['titulo'],
                $dados['descricao'] ?? '',
                $dados['processo_id'],
                $dados['responsavel_id'],
                $dados['data_inicio'],
                $dados['data_fim'] ?? $dados['data_inicio'],
                $dados['local'] ?? '',
                $dados['tipo_audiencia'] ?? 'geral',
                $dados['prioridade'] ?? 'normal',
                $dados['criado_por'],
                $publicacao_id
            ]);
            
            $audiencia_id = $pdo->lastInsertId();
            error_log("Audiência criada com ID: $audiencia_id");
            
            // Salvar etiquetas
            if (!empty($dados['etiquetas']) && is_array($dados['etiquetas'])) {
                self::salvarEtiquetas('audiencia', $audiencia_id, $dados['etiquetas'], $dados['criado_por']);
            }
            
            // Salvar envolvidos
            if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
                self::salvarEnvolvidos('audiencia', $audiencia_id, $dados['envolvidos'], $dados['responsavel_id']);
            }
            
            // Buscar informações para o histórico
            $responsavel_nome = self::getNomeUsuario($dados['responsavel_id']);
            $processo_numero = self::getNumeroProcesso($dados['processo_id']);
            
            // Registrar no histórico
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao('audiencia', $audiencia_id, 
                $publicacao_id ? 'Audiência criada a partir de publicação' : 'Audiência criada', 
                [
                    'titulo' => $dados['titulo'],
                    'responsavel_nome' => $responsavel_nome,
                    'data_inicio' => $dados['data_inicio'],
                    'data_fim' => $dados['data_fim'] ?? $dados['data_inicio'],
                    'local' => $dados['local'] ?? '',
                    'processo_numero' => $processo_numero,
                    'publicacao_id' => $publicacao_id
                ]
            );
            
            // Registrar no histórico do processo
            self::registrarHistoricoProcesso(
                $dados['processo_id'], 
                $dados['criado_por'], 
                'Audiência Agendada', 
                "Audiência agendada: {$dados['titulo']}"
            );
            
            // Criar notificações
            require_once __DIR__ . '/notificacoes_helper.php';
            self::criarNotificacoesAudiencia($audiencia_id, $dados);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'audiencia_id' => $audiencia_id,
                'message' => 'Audiência criada com sucesso!'
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao criar audiência: " . $e->getMessage());
            throw $e;
        }
    }
    
    // ==================== MÉTODOS AUXILIARES ====================
    
    /**
     * Salva etiquetas de um evento
     */
    private static function salvarEtiquetas($tipo, $referencia_id, $etiquetas, $criado_por) {
        $pdo = getConnection();
        $tabela = $tipo . '_etiquetas';
        $campo_id = $tipo . '_id';
        
        foreach ($etiquetas as $etiqueta_id) {
            if (!empty($etiqueta_id) && is_numeric($etiqueta_id)) {
                $sql = "INSERT INTO {$tabela} ({$campo_id}, etiqueta_id, criado_por) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE criado_por = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$referencia_id, $etiqueta_id, $criado_por, $criado_por]);
            }
        }
    }
    
    /**
     * Salva envolvidos de um evento
     */
    private static function salvarEnvolvidos($tipo, $referencia_id, $envolvidos, $responsavel_id) {
        $pdo = getConnection();
        $tabela = $tipo . '_envolvidos';
        $campo_id = $tipo . '_id';
        
        foreach ($envolvidos as $usuario_id) {
            if (!empty($usuario_id) && is_numeric($usuario_id) && $usuario_id != $responsavel_id) {
                $sql = "INSERT INTO {$tabela} ({$campo_id}, usuario_id) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE usuario_id = usuario_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$referencia_id, $usuario_id]);
            }
        }
    }
    
    /**
     * Registra ação no histórico do processo
     */
    private static function registrarHistoricoProcesso($processo_id, $usuario_id, $acao, $descricao) {
        $pdo = getConnection();
        $sql = "INSERT INTO processo_historico (
                    processo_id, usuario_id, acao, descricao, data_acao
                ) VALUES (?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$processo_id, $usuario_id, $acao, $descricao]);
    }
    
    /**
     * Cria notificações para uma tarefa
     */
    private static function criarNotificacoesTarefa($tarefa_id, $dados) {
        // Notificar responsável (se não for o criador)
        if ($dados['responsavel_id'] != $dados['criado_por']) {
            $origem = !empty($dados['publicacao_id']) ? 'a partir de uma publicação' : '';
            criarNotificacao([
                'usuario_id' => $dados['responsavel_id'],
                'tipo' => 'tarefa_atribuida',
                'titulo' => 'Nova tarefa atribuída',
                'mensagem' => "Uma tarefa foi criada {$origem} e atribuída para você: {$dados['titulo']}",
                'link' => "/modules/agenda/?acao=visualizar&tipo=tarefa&id={$tarefa_id}",
                'prioridade' => ($dados['prioridade'] ?? 'normal') === 'urgente' ? 'alta' : 'normal'
            ]);
        }
        
        // Notificar envolvidos (exceto criador e responsável)
        if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
            foreach ($dados['envolvidos'] as $usuario_id) {
                if ($usuario_id != $dados['criado_por'] && $usuario_id != $dados['responsavel_id']) {
                    criarNotificacao([
                        'usuario_id' => $usuario_id,
                        'tipo' => 'tarefa_envolvido',
                        'titulo' => 'Você foi adicionado como envolvido',
                        'mensagem' => "Você foi adicionado como envolvido na tarefa: {$dados['titulo']}",
                        'link' => "/modules/agenda/?acao=visualizar&tipo=tarefa&id={$tarefa_id}",
                        'prioridade' => 'normal'
                    ]);
                }
            }
        }
    }
    
    /**
     * Cria notificações para um prazo
     */
    private static function criarNotificacoesPrazo($prazo_id, $dados) {
        // Notificar responsável (se não for o criador)
        if ($dados['responsavel_id'] != $dados['criado_por']) {
            $origem = !empty($dados['publicacao_id']) ? 'a partir de uma publicação' : '';
            criarNotificacao([
                'usuario_id' => $dados['responsavel_id'],
                'tipo' => 'prazo_atribuido',
                'titulo' => 'Novo prazo atribuído',
                'mensagem' => "Um prazo foi criado {$origem} e atribuído para você: {$dados['titulo']}",
                'link' => "/modules/agenda/?acao=visualizar&tipo=prazo&id={$prazo_id}",
                'prioridade' => ($dados['prioridade'] ?? 'normal') === 'urgente' ? 'alta' : 'normal'
            ]);
        }
        
        // Notificar envolvidos (exceto criador e responsável)
        if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
            foreach ($dados['envolvidos'] as $usuario_id) {
                if ($usuario_id != $dados['criado_por'] && $usuario_id != $dados['responsavel_id']) {
                    criarNotificacao([
                        'usuario_id' => $usuario_id,
                        'tipo' => 'prazo_envolvido',
                        'titulo' => 'Você foi adicionado como envolvido',
                        'mensagem' => "Você foi adicionado como envolvido no prazo: {$dados['titulo']}",
                        'link' => "/modules/agenda/?acao=visualizar&tipo=prazo&id={$prazo_id}",
                        'prioridade' => 'normal'
                    ]);
                }
            }
        }
    }
    
    /**
     * Cria notificações para uma audiência
     */
    private static function criarNotificacoesAudiencia($audiencia_id, $dados) {
        // Notificar responsável (se não for o criador)
        if ($dados['responsavel_id'] != $dados['criado_por']) {
            $origem = !empty($dados['publicacao_id']) ? 'a partir de uma publicação' : '';
            criarNotificacao([
                'usuario_id' => $dados['responsavel_id'],
                'tipo' => 'audiencia_agendada',
                'titulo' => 'Nova audiência agendada',
                'mensagem' => "Uma audiência foi criada {$origem} e atribuída para você: {$dados['titulo']}",
                'link' => "/modules/agenda/?acao=visualizar&tipo=audiencia&id={$audiencia_id}",
                'prioridade' => ($dados['prioridade'] ?? 'normal') === 'urgente' ? 'alta' : 'normal'
            ]);
        }
        
        // Notificar envolvidos (exceto criador e responsável)
        if (!empty($dados['envolvidos']) && is_array($dados['envolvidos'])) {
            foreach ($dados['envolvidos'] as $usuario_id) {
                if ($usuario_id != $dados['criado_por'] && $usuario_id != $dados['responsavel_id']) {
                    criarNotificacao([
                        'usuario_id' => $usuario_id,
                        'tipo' => 'audiencia_envolvido',
                        'titulo' => 'Você foi adicionado como envolvido',
                        'mensagem' => "Você foi adicionado como envolvido na audiência: {$dados['titulo']}",
                        'link' => "/modules/agenda/?acao=visualizar&tipo=audiencia&id={$audiencia_id}",
                        'prioridade' => 'normal'
                    ]);
                }
            }
        }
    }
    
    /**
     * Busca nome do usuário
     */
    private static function getNomeUsuario($usuario_id) {
        $pdo = getConnection();
        $sql = "SELECT nome FROM usuarios WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        return $usuario ? $usuario['nome'] : 'Desconhecido';
    }
    
    /**
     * Busca número do processo
     */
    private static function getNumeroProcesso($processo_id) {
        $pdo = getConnection();
        $sql = "SELECT numero_processo FROM processos WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$processo_id]);
        $processo = $stmt->fetch();
        return $processo ? $processo['numero_processo'] : null;
    }
    
    // ==================== VALIDAÇÕES ====================
    
    private static function validarDadosTarefa($dados) {
        if (empty($dados['titulo'])) {
            throw new Exception('O título da tarefa é obrigatório');
        }
        if (empty($dados['responsavel_id'])) {
            throw new Exception('O responsável é obrigatório');
        }
        if (empty($dados['data_vencimento'])) {
            throw new Exception('A data de vencimento é obrigatória');
        }
        if (empty($dados['criado_por'])) {
            throw new Exception('Criador não identificado');
        }
    }
    
    private static function validarDadosPrazo($dados) {
        if (empty($dados['titulo'])) {
            throw new Exception('O título do prazo é obrigatório');
        }
        if (empty($dados['responsavel_id'])) {
            throw new Exception('O responsável é obrigatório');
        }
        if (empty($dados['data_vencimento'])) {
            throw new Exception('A data de vencimento é obrigatória');
        }
        if (empty($dados['processo_id'])) {
            throw new Exception('O processo é obrigatório para prazos');
        }
        if (empty($dados['criado_por'])) {
            throw new Exception('Criador não identificado');
        }
    }
    
    private static function validarDadosAudiencia($dados) {
        if (empty($dados['titulo'])) {
            throw new Exception('O título da audiência é obrigatório');
        }
        if (empty($dados['responsavel_id'])) {
            throw new Exception('O responsável é obrigatório');
        }
        if (empty($dados['data_inicio'])) {
            throw new Exception('A data de início é obrigatória');
        }
        if (empty($dados['processo_id'])) {
            throw new Exception('O processo é obrigatório para audiências');
        }
        if (empty($dados['criado_por'])) {
            throw new Exception('Criador não identificado');
        }
    }
}