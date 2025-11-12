<?php
/**
 * RevisaoHelperHierarquico.php
 *
 * Helper para gerenciar o fluxo de revisão usando modelo HIERÁRQUICO
 * Em vez de usar a tabela fluxo_revisao, criamos tarefas/prazos FILHOS
 * para cada etapa (revisão, correção, protocolo)
 *
 * FLUXO:
 * 1. Tarefa/Prazo Original (pai) - tipo_fluxo='original'
 * 2. Enviar para Revisão → Cria tarefa/prazo filho - tipo_fluxo='revisao'
 * 3. Aceitar → Cria tarefa/prazo filho - tipo_fluxo='protocolo'
 * 4. Recusar → Cria tarefa/prazo filho - tipo_fluxo='correcao'
 *
 * Vantagens:
 * - Cada etapa é uma tarefa/prazo real no sistema
 * - Histórico completo em cada registro
 * - Cada etapa pode ter documentos, comentários, envolvidos próprios
 * - Hierarquia clara (parent_id)
 */

class RevisaoHelperHierarquico {

    /**
     * Envia uma tarefa/prazo para revisão
     * Cria uma nova tarefa/prazo FILHA para o revisor
     *
     * @param string $tipo 'tarefa' ou 'prazo'
     * @param int $item_id ID da tarefa/prazo original
     * @param int $revisor_id ID do revisor
     * @param int $usuario_id ID do solicitante
     * @param string $comentario Comentário do solicitante
     * @param array $arquivos_ids IDs dos documentos anexados
     * @return array ['success' => bool, 'item_revisao_id' => int, 'message' => string]
     */
    public static function enviarParaRevisao($tipo, $item_id, $revisor_id, $usuario_id, $comentario = null, $arquivos_ids = []) {
        $pdo = getConnection();
        $pdo->beginTransaction();

        try {
            // Buscar item original
            $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';
            $sql = "SELECT * FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_id]);
            $item_original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item_original) {
                throw new Exception(ucfirst($tipo) . " não encontrada");
            }

            // Verificar se é o responsável ou envolvido
            if ($item_original['responsavel_id'] != $usuario_id) {
                // Verificar se é envolvido
                $tabela_envolvidos = $tipo . '_envolvidos';
                $campo_id = $tipo . '_id';
                $sql = "SELECT COUNT(*) as total FROM {$tabela_envolvidos}
                        WHERE {$campo_id} = ? AND usuario_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_id, $usuario_id]);
                $envolvido = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($envolvido['total'] == 0) {
                    throw new Exception("Você não tem permissão para enviar este item para revisão");
                }
            }

            // Determinar o ciclo de revisão
            // Buscar o item raiz (parent_id = NULL ou tipo_fluxo = 'original')
            $item_raiz_id = self::buscarItemRaiz($tipo, $item_id);

            // Contar quantas revisões já existem para este item raiz
            $sql = "SELECT COUNT(*) as total FROM {$tabela}
                    WHERE parent_id = ? AND tipo_fluxo = 'revisao' AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_raiz_id]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            $ciclo_numero = intval($resultado['total']) + 1;

            // Buscar informações do revisor
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$revisor_id]);
            $revisor = $stmt->fetch(PDO::FETCH_ASSOC);
            $revisor_nome = $revisor ? $revisor['nome'] : 'Revisor';

            // Criar tarefa/prazo de REVISÃO (filho)
            $titulo_revisao = "REVISÃO: " . $item_original['titulo'];
            $descricao_revisao = "Item enviado para revisão (Ciclo #{$ciclo_numero}).\n\n";
            if ($comentario) {
                $descricao_revisao .= "Comentário do solicitante:\n" . $comentario . "\n\n";
            }
            $descricao_revisao .= "---\nDescrição original:\n" . ($item_original['descricao'] ?? '');

            // Data de vencimento para revisão (2 dias úteis)
            $data_vencimento_revisao = date('Y-m-d 23:59:00', strtotime('+2 days'));

            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefas (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'revisao', ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_revisao,
                    $descricao_revisao,
                    $item_original['processo_id'],
                    $revisor_id, // Revisor é o RESPONSÁVEL desta tarefa
                    $data_vencimento_revisao,
                    $item_original['prioridade'] ?? 'normal',
                    $usuario_id, // Solicitante é o criador
                    $item_raiz_id, // parent_id aponta para o original
                    $ciclo_numero,
                    $item_original['publicacao_id']
                ]);
            } else {
                $sql = "INSERT INTO prazos (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id, prazo_fatal_original
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'revisao', ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_revisao,
                    $descricao_revisao,
                    $item_original['processo_id'],
                    $revisor_id, // Revisor é o RESPONSÁVEL deste prazo
                    $data_vencimento_revisao,
                    $item_original['prioridade'] ?? 'alta',
                    $usuario_id, // Solicitante é o criador
                    $item_raiz_id, // parent_id aponta para o original
                    $ciclo_numero,
                    $item_original['publicacao_id'],
                    $item_original['data_vencimento'] // Preservar prazo original
                ]);
            }

            $item_revisao_id = $pdo->lastInsertId();

            // Copiar etiquetas do original
            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefa_etiquetas (tarefa_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM tarefa_etiquetas
                        WHERE tarefa_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_revisao_id, $usuario_id, $item_id]);
            } else {
                $sql = "INSERT INTO prazo_etiquetas (prazo_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM prazo_etiquetas
                        WHERE prazo_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_revisao_id, $usuario_id, $item_id]);
            }

            // Adicionar solicitante como envolvido na tarefa de revisão
            $tabela_envolvidos = $tipo . '_envolvidos';
            $campo_id = $tipo . '_id';
            $sql = "INSERT INTO {$tabela_envolvidos} ({$campo_id}, usuario_id) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_revisao_id, $usuario_id]);

            // Registrar no histórico do item de revisão
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao($tipo, $item_revisao_id,
                "Item enviado para revisão - Ciclo #{$ciclo_numero}",
                [
                    'revisor_nome' => $revisor_nome,
                    'solicitante_id' => $usuario_id,
                    'item_original_id' => $item_raiz_id,
                    'ciclo' => $ciclo_numero,
                    'comentario' => $comentario
                ]
            );

            // Registrar no histórico do item original
            HistoricoHelper::registrar($tipo, $item_id, 'enviado_revisao', [
                'revisor_nome' => $revisor_nome,
                'revisor_id' => $revisor_id,
                'item_revisao_id' => $item_revisao_id,
                'ciclo' => $ciclo_numero,
                'comentario' => $comentario
            ]);

            // Marcar item original como "em_revisao"
            $sql = "UPDATE {$tabela} SET status = 'em_revisao' WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_id]);

            // Criar notificação para o revisor
            require_once __DIR__ . '/notificacoes_helper.php';
            criarNotificacao([
                'usuario_id' => $revisor_id,
                'tipo' => $tipo . '_revisao',
                'titulo' => 'Nova revisão solicitada',
                'mensagem' => "Você recebeu uma solicitação de revisão: {$item_original['titulo']}",
                'link' => "/modules/agenda/?acao=visualizar&tipo={$tipo}&id={$item_revisao_id}",
                'prioridade' => ($item_original['prioridade'] ?? 'normal') === 'urgente' ? 'alta' : 'normal'
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'item_revisao_id' => $item_revisao_id,
                'message' => ucfirst($tipo) . " enviada para revisão com sucesso! (Ciclo #{$ciclo_numero})"
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao enviar para revisão: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Aceita uma revisão
     * Marca a tarefa de revisão como concluída e cria uma tarefa de PROTOCOLO
     *
     * @param string $tipo 'tarefa' ou 'prazo'
     * @param int $item_revisao_id ID da tarefa/prazo de revisão
     * @param int $revisor_id ID do revisor
     * @param string $comentario Comentário do revisor
     * @param array $arquivos_ids IDs dos documentos anexados
     * @return array
     */
    public static function aceitarRevisao($tipo, $item_revisao_id, $revisor_id, $comentario = null, $arquivos_ids = []) {
        $pdo = getConnection();
        $pdo->beginTransaction();

        try {
            // Buscar item de revisão
            $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';
            $sql = "SELECT * FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_revisao_id]);
            $item_revisao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item_revisao) {
                throw new Exception("Item de revisão não encontrado");
            }

            if ($item_revisao['tipo_fluxo'] !== 'revisao') {
                throw new Exception("Este item não é uma revisão");
            }

            // Verificar se é o responsável (revisor)
            if ($item_revisao['responsavel_id'] != $revisor_id) {
                throw new Exception("Apenas o revisor pode aceitar esta revisão");
            }

            // Buscar item original
            $item_original_id = $item_revisao['parent_id'];
            $sql = "SELECT * FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_original_id]);
            $item_original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item_original) {
                throw new Exception("Item original não encontrado");
            }

            // Buscar nome do solicitante original
            $sql = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_revisao['criado_por']]);
            $solicitante = $stmt->fetch(PDO::FETCH_ASSOC);
            $solicitante_nome = $solicitante ? $solicitante['nome'] : 'Solicitante';

            // Marcar item de revisão como CONCLUÍDO
            $sql = "UPDATE {$tabela} SET
                    status = 'concluida',
                    data_conclusao = NOW(),
                    concluido_por = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$revisor_id, $item_revisao_id]);

            // Criar tarefa/prazo de PROTOCOLO (filho do original)
            $titulo_protocolo = "PROTOCOLO: " . $item_original['titulo'];
            $descricao_protocolo = "Revisão aceita. Pronto para protocolo.\n\n";
            if ($comentario) {
                $descricao_protocolo .= "Comentário do revisor:\n" . $comentario . "\n\n";
            }
            $descricao_protocolo .= "---\nDescrição original:\n" . ($item_original['descricao'] ?? '');

            // Data de vencimento para protocolo (preservar prazo original se for prazo, ou 3 dias se for tarefa)
            if ($tipo === 'prazo' && !empty($item_revisao['prazo_fatal_original'])) {
                $data_vencimento_protocolo = $item_revisao['prazo_fatal_original'];
            } else {
                $data_vencimento_protocolo = date('Y-m-d 23:59:00', strtotime('+3 days'));
            }

            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefas (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'protocolo', ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_protocolo,
                    $descricao_protocolo,
                    $item_original['processo_id'],
                    $item_revisao['criado_por'], // Volta para o solicitante original
                    $data_vencimento_protocolo,
                    $item_original['prioridade'] ?? 'normal',
                    $revisor_id, // Revisor é o criador desta tarefa
                    $item_original_id, // parent_id aponta para o original
                    $item_revisao['revisao_ciclo'],
                    $item_original['publicacao_id']
                ]);
            } else {
                $sql = "INSERT INTO prazos (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id, prazo_fatal_original
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'protocolo', ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_protocolo,
                    $descricao_protocolo,
                    $item_original['processo_id'],
                    $item_revisao['criado_por'], // Volta para o solicitante original
                    $data_vencimento_protocolo,
                    $item_original['prioridade'] ?? 'alta',
                    $revisor_id, // Revisor é o criador deste prazo
                    $item_original_id, // parent_id aponta para o original
                    $item_revisao['revisao_ciclo'],
                    $item_original['publicacao_id'],
                    $item_revisao['prazo_fatal_original'] ?? $item_original['data_vencimento']
                ]);
            }

            $item_protocolo_id = $pdo->lastInsertId();

            // Copiar etiquetas do original
            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefa_etiquetas (tarefa_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM tarefa_etiquetas
                        WHERE tarefa_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_protocolo_id, $revisor_id, $item_original_id]);
            } else {
                $sql = "INSERT INTO prazo_etiquetas (prazo_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM prazo_etiquetas
                        WHERE prazo_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_protocolo_id, $revisor_id, $item_original_id]);
            }

            // Registrar no histórico
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao($tipo, $item_protocolo_id,
                "Pronto para protocolo - Revisão aceita",
                [
                    'revisor_nome' => self::getNomeUsuario($revisor_id),
                    'solicitante_nome' => $solicitante_nome,
                    'item_revisao_id' => $item_revisao_id,
                    'item_original_id' => $item_original_id,
                    'ciclo' => $item_revisao['revisao_ciclo'],
                    'comentario' => $comentario
                ]
            );

            HistoricoHelper::registrar($tipo, $item_revisao_id, 'revisao_aceita', [
                'revisor_id' => $revisor_id,
                'item_protocolo_id' => $item_protocolo_id,
                'comentario' => $comentario
            ]);

            // Marcar original como aguardando_protocolo
            $sql = "UPDATE {$tabela} SET status = 'aguardando_protocolo' WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_original_id]);

            // Criar notificação para o solicitante
            require_once __DIR__ . '/notificacoes_helper.php';
            criarNotificacao([
                'usuario_id' => $item_revisao['criado_por'],
                'tipo' => $tipo . '_revisao_aceita',
                'titulo' => 'Revisão aceita - Pronto para protocolo',
                'mensagem' => "Sua revisão foi aceita: {$item_original['titulo']}. Agora você pode protocolar.",
                'link' => "/modules/agenda/?acao=visualizar&tipo={$tipo}&id={$item_protocolo_id}",
                'prioridade' => 'alta'
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'item_protocolo_id' => $item_protocolo_id,
                'message' => 'Revisão aceita com sucesso! Tarefa de protocolo criada.'
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao aceitar revisão: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Recusa uma revisão
     * Marca a tarefa de revisão como concluída e cria uma tarefa de CORREÇÃO
     *
     * @param string $tipo 'tarefa' ou 'prazo'
     * @param int $item_revisao_id ID da tarefa/prazo de revisão
     * @param int $revisor_id ID do revisor
     * @param string $observacao Observação do revisor (obrigatória)
     * @param array $arquivos_ids IDs dos documentos anexados
     * @return array
     */
    public static function recusarRevisao($tipo, $item_revisao_id, $revisor_id, $observacao, $arquivos_ids = []) {
        $pdo = getConnection();
        $pdo->beginTransaction();

        try {
            if (empty($observacao)) {
                throw new Exception("A observação é obrigatória ao recusar uma revisão");
            }

            // Buscar item de revisão
            $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';
            $sql = "SELECT * FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_revisao_id]);
            $item_revisao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item_revisao) {
                throw new Exception("Item de revisão não encontrado");
            }

            if ($item_revisao['tipo_fluxo'] !== 'revisao') {
                throw new Exception("Este item não é uma revisão");
            }

            // Verificar se é o responsável (revisor)
            if ($item_revisao['responsavel_id'] != $revisor_id) {
                throw new Exception("Apenas o revisor pode recusar esta revisão");
            }

            // Buscar item original
            $item_original_id = $item_revisao['parent_id'];
            $sql = "SELECT * FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_original_id]);
            $item_original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item_original) {
                throw new Exception("Item original não encontrado");
            }

            // Marcar item de revisão como CONCLUÍDO (mas recusado)
            $sql = "UPDATE {$tabela} SET
                    status = 'revisao_recusada',
                    data_conclusao = NOW(),
                    concluido_por = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$revisor_id, $item_revisao_id]);

            // Criar tarefa/prazo de CORREÇÃO (filho do original)
            $titulo_correcao = "CORREÇÃO: " . $item_original['titulo'];
            $descricao_correcao = "Revisão recusada. Necessário fazer correções.\n\n";
            $descricao_correcao .= "⚠️ OBSERVAÇÕES DO REVISOR:\n" . $observacao . "\n\n";
            $descricao_correcao .= "---\nDescrição original:\n" . ($item_original['descricao'] ?? '');

            // Data de vencimento para correção (2 dias)
            $data_vencimento_correcao = date('Y-m-d 23:59:00', strtotime('+2 days'));

            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefas (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'correcao', ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_correcao,
                    $descricao_correcao,
                    $item_original['processo_id'],
                    $item_revisao['criado_por'], // Volta para o solicitante original
                    $data_vencimento_correcao,
                    'alta', // Prioridade alta para correções
                    $revisor_id, // Revisor é o criador desta tarefa
                    $item_original_id, // parent_id aponta para o original
                    $item_revisao['revisao_ciclo'], // Mesmo ciclo
                    $item_original['publicacao_id']
                ]);
            } else {
                $sql = "INSERT INTO prazos (
                            titulo, descricao, processo_id, responsavel_id,
                            data_vencimento, prioridade, status, criado_por,
                            data_criacao, parent_id, tipo_fluxo, revisao_ciclo,
                            publicacao_id, prazo_fatal_original
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, NOW(), ?, 'correcao', ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $titulo_correcao,
                    $descricao_correcao,
                    $item_original['processo_id'],
                    $item_revisao['criado_por'], // Volta para o solicitante original
                    $data_vencimento_correcao,
                    'urgente', // Prioridade urgente para correções de prazo
                    $revisor_id, // Revisor é o criador deste prazo
                    $item_original_id, // parent_id aponta para o original
                    $item_revisao['revisao_ciclo'], // Mesmo ciclo
                    $item_original['publicacao_id'],
                    $item_revisao['prazo_fatal_original'] ?? $item_original['data_vencimento']
                ]);
            }

            $item_correcao_id = $pdo->lastInsertId();

            // Copiar etiquetas do original
            if ($tipo === 'tarefa') {
                $sql = "INSERT INTO tarefa_etiquetas (tarefa_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM tarefa_etiquetas
                        WHERE tarefa_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_correcao_id, $revisor_id, $item_original_id]);
            } else {
                $sql = "INSERT INTO prazo_etiquetas (prazo_id, etiqueta_id, criado_por)
                        SELECT ?, etiqueta_id, ?
                        FROM prazo_etiquetas
                        WHERE prazo_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$item_correcao_id, $revisor_id, $item_original_id]);
            }

            // Adicionar revisor como envolvido na tarefa de correção
            $tabela_envolvidos = $tipo . '_envolvidos';
            $campo_id = $tipo . '_id';
            $sql = "INSERT INTO {$tabela_envolvidos} ({$campo_id}, usuario_id) VALUES (?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_correcao_id, $revisor_id]);

            // Registrar no histórico
            require_once __DIR__ . '/../modules/agenda/includes/HistoricoHelper.php';
            HistoricoHelper::registrarCriacao($tipo, $item_correcao_id,
                "Correção necessária - Revisão recusada",
                [
                    'revisor_nome' => self::getNomeUsuario($revisor_id),
                    'item_revisao_id' => $item_revisao_id,
                    'item_original_id' => $item_original_id,
                    'ciclo' => $item_revisao['revisao_ciclo'],
                    'observacao' => $observacao
                ]
            );

            HistoricoHelper::registrar($tipo, $item_revisao_id, 'revisao_recusada', [
                'revisor_id' => $revisor_id,
                'item_correcao_id' => $item_correcao_id,
                'observacao' => $observacao
            ]);

            // Marcar original como em_correcao
            $sql = "UPDATE {$tabela} SET status = 'em_correcao' WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$item_original_id]);

            // Criar notificação para o solicitante
            require_once __DIR__ . '/notificacoes_helper.php';
            criarNotificacao([
                'usuario_id' => $item_revisao['criado_por'],
                'tipo' => $tipo . '_revisao_recusada',
                'titulo' => 'Revisão recusada - Correção necessária',
                'mensagem' => "Sua revisão foi recusada: {$item_original['titulo']}. Veja as observações e faça as correções necessárias.",
                'link' => "/modules/agenda/?acao=visualizar&tipo={$tipo}&id={$item_correcao_id}",
                'prioridade' => 'alta'
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'item_correcao_id' => $item_correcao_id,
                'message' => 'Revisão recusada. Tarefa de correção criada para o solicitante.'
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao recusar revisão: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ==================== MÉTODOS AUXILIARES ====================

    /**
     * Busca o ID do item raiz (original) na hierarquia
     */
    private static function buscarItemRaiz($tipo, $item_id) {
        $pdo = getConnection();
        $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';

        $sql = "SELECT id, parent_id, tipo_fluxo FROM {$tabela} WHERE id = ? AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return $item_id;
        }

        // Se não tem pai ou é original, este é a raiz
        if (empty($item['parent_id']) || $item['tipo_fluxo'] === 'original') {
            return $item_id;
        }

        // Caso contrário, buscar recursivamente
        return self::buscarItemRaiz($tipo, $item['parent_id']);
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
     * Lista revisões pendentes para um revisor
     */
    public static function listarRevisoesPendentes($tipo, $revisor_id) {
        $pdo = getConnection();
        $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';

        $sql = "SELECT t.*,
                u_criador.nome as solicitante_nome,
                u_criador.email as solicitante_email,
                proc.numero_processo,
                cli.nome as cliente_nome,
                DATEDIFF(NOW(), t.data_criacao) as dias_aguardando
                FROM {$tabela} t
                LEFT JOIN usuarios u_criador ON t.criado_por = u_criador.id
                LEFT JOIN processos proc ON t.processo_id = proc.id
                LEFT JOIN clientes cli ON proc.cliente_id = cli.id
                WHERE t.responsavel_id = ?
                AND t.tipo_fluxo = 'revisao'
                AND t.status = 'pendente'
                AND t.deleted_at IS NULL
                ORDER BY t.prioridade DESC, t.data_criacao ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$revisor_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista histórico de um item (pai e todos os filhos)
     */
    public static function listarHistoricoCompleto($tipo, $item_id) {
        $pdo = getConnection();
        $tabela = ($tipo === 'tarefa') ? 'tarefas' : 'prazos';

        // Buscar item raiz
        $item_raiz_id = self::buscarItemRaiz($tipo, $item_id);

        // Buscar todos os itens da hierarquia
        $sql = "SELECT t.*,
                u_resp.nome as responsavel_nome,
                u_criador.nome as criador_nome,
                proc.numero_processo
                FROM {$tabela} t
                LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
                LEFT JOIN usuarios u_criador ON t.criado_por = u_criador.id
                LEFT JOIN processos proc ON t.processo_id = proc.id
                WHERE (t.id = ? OR t.parent_id = ?)
                AND t.deleted_at IS NULL
                ORDER BY t.data_criacao ASC,
                         CASE tipo_fluxo
                            WHEN 'original' THEN 1
                            WHEN 'revisao' THEN 2
                            WHEN 'correcao' THEN 3
                            WHEN 'protocolo' THEN 4
                         END";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_raiz_id, $item_raiz_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
