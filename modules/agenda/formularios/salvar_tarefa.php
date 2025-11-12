<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

try {
    $usuario_logado = Auth::user();
    
    // Validar campos obrigatórios
    if (empty($_POST['titulo'])) {
        throw new Exception('O título da tarefa é obrigatório');
    }
    
    if (empty($_POST['responsavel_id'])) {
        throw new Exception('O responsável é obrigatório');
    }
    
    if (empty($_POST['data_vencimento'])) {
        throw new Exception('A data de vencimento é obrigatória');
    }
    
    // Sanitizar dados
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao'] ?? '');
    $processo_id = !empty($_POST['processo_id']) ? (int)$_POST['processo_id'] : null;
    $responsavel_id = (int)$_POST['responsavel_id'];
    $data_vencimento = $_POST['data_vencimento'];
    $hora_vencimento = !empty($_POST['hora_vencimento']) ? $_POST['hora_vencimento'] : '23:59';
    $prioridade = $_POST['prioridade'] ?? 'normal';
    $status = $_POST['status'] ?? 'pendente';
    $envolvidos = $_POST['envolvidos'] ?? [];
    
    // Combinar data e hora
    $data_hora_vencimento = $data_vencimento . ' ' . $hora_vencimento . ':00';
    
    // Validar data
    $data_obj = DateTime::createFromFormat('Y-m-d H:i:s', $data_hora_vencimento);
    if (!$data_obj) {
        // Tentar formato antigo datetime-local para compatibilidade
        $data_obj = DateTime::createFromFormat('Y-m-d\TH:i', $data_vencimento);
        if (!$data_obj) {
            throw new Exception('Data de vencimento inválida');
        }
    }
    $data_vencimento_formatada = $data_obj->format('Y-m-d H:i:s');
    
    // Campos de fluxo
    $enviar_para_usuario_id = !empty($_POST['enviar_para_usuario_id']) ? (int)$_POST['enviar_para_usuario_id'] : null;
    $fluxo_tipo = trim($_POST['fluxo_tipo'] ?? '');
    $fluxo_instrucao = trim($_POST['fluxo_instrucao'] ?? '');
    
    // Iniciar transação
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // Inserir tarefa
        $sql = "INSERT INTO tarefas (
                    titulo, descricao, processo_id, responsavel_id, 
                    data_vencimento, prioridade, status, criado_por, data_criacao,
                    enviar_para_usuario_id, fluxo_tipo, fluxo_instrucao
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $titulo,
            $descricao,
            $processo_id,
            $responsavel_id,
            $data_vencimento_formatada,
            $prioridade,
            $status,
            $usuario_logado['usuario_id'],
            $enviar_para_usuario_id,
            $fluxo_tipo ?: null,
            $fluxo_instrucao ?: null
        ]);
        
        // Pegar o ID
        $tarefa_id = $pdo->lastInsertId();
        
        // ====== REGISTRAR NO HISTÓRICO COM DETALHES ======
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        // Buscar nome do responsável
        $sql_resp = "SELECT nome FROM usuarios WHERE id = ?";
        $stmt_resp = $pdo->prepare($sql_resp);
        $stmt_resp->execute([$responsavel_id]);
        $responsavel = $stmt_resp->fetch();
        $responsavel_nome = $responsavel ? $responsavel['nome'] : 'Desconhecido';
        
        // Buscar número do processo (se houver)
        $processo_numero = null;
        if ($processo_id) {
            $sql_proc = "SELECT numero_processo FROM processos WHERE id = ?";
            $stmt_proc = $pdo->prepare($sql_proc);
            $stmt_proc->execute([$processo_id]);
            $processo = $stmt_proc->fetch();
            $processo_numero = $processo ? $processo['numero_processo'] : null;
        }
        
        // Montar motivo da criação
        $motivo = 'Tarefa criada via sistema de agenda';
        
        // Registrar criação com todos os detalhes
        HistoricoHelper::registrarCriacao('tarefa', $tarefa_id, $motivo, [
            'titulo' => $titulo,
            'responsavel_nome' => $responsavel_nome,
            'data_vencimento' => $data_vencimento_formatada,
            'processo_numero' => $processo_numero
        ]);
        
        // Inserir envolvidos
        if (!empty($envolvidos) && is_array($envolvidos)) {
            $sql_envolvido = "INSERT INTO tarefa_envolvidos (tarefa_id, usuario_id) VALUES (?, ?)";
            $stmt_envolvido = $pdo->prepare($sql_envolvido);
            
            foreach ($envolvidos as $usuario_id) {
                if (!empty($usuario_id) && is_numeric($usuario_id)) {
                    $stmt_envolvido->execute([$tarefa_id, (int)$usuario_id]);
                }
            }
        }
        
        // Inserir etiquetas
        if (!empty($_POST['etiquetas']) && is_array($_POST['etiquetas'])) {
            $sql_etiqueta = "INSERT INTO tarefa_etiquetas (tarefa_id, etiqueta_id, criado_por) VALUES (?, ?, ?)";
            $stmt_etiqueta = $pdo->prepare($sql_etiqueta);
            
            foreach ($_POST['etiquetas'] as $etiqueta_id) {
                if (!empty($etiqueta_id) && is_numeric($etiqueta_id)) {
                    $stmt_etiqueta->execute([$tarefa_id, (int)$etiqueta_id, $usuario_logado['usuario_id']]);
                }
            }
        }
        
        // Registrar histórico no processo (se vinculado)
        if ($processo_id) {
            $sql_historico = "INSERT INTO processo_historico (
                                processo_id, usuario_id, acao, descricao, data_acao
                             ) VALUES (?, ?, ?, ?, NOW())";
            $stmt_historico = $pdo->prepare($sql_historico);
            $stmt_historico->execute([
                $processo_id,
                $usuario_logado['usuario_id'],
                'Tarefa Criada',
                "Tarefa criada: {$titulo}"
            ]);
        }
        
        // ====== NOTIFICAÇÕES ======
        require_once '../../../includes/notificacoes_helper.php';
        
        // Notificar responsável (se não for o criador)
        if ($responsavel_id != $usuario_logado['usuario_id']) {
            criarNotificacao([
                'usuario_id' => $responsavel_id,
                'tipo' => 'tarefa_atribuida',
                'titulo' => 'Nova tarefa atribuída',
                'mensagem' => "{$usuario_logado['nome']} atribuiu uma tarefa para você: {$titulo}",
                'link' => "/modules/agenda/?acao=visualizar&id={$tarefa_id}",
                'prioridade' => $prioridade === 'urgente' ? 'alta' : 'normal'
            ]);
        }
        
        // Notificar envolvidos (exceto criador e responsável)
        if (!empty($envolvidos) && is_array($envolvidos)) {
            foreach ($envolvidos as $usuario_id) {
                $usuario_id = (int)$usuario_id;
                
                // Não notificar o criador nem o responsável
                if ($usuario_id != $usuario_logado['usuario_id'] && $usuario_id != $responsavel_id) {
                    criarNotificacao([
                        'usuario_id' => $usuario_id,
                        'tipo' => 'tarefa_envolvido',
                        'titulo' => 'Você foi adicionado como envolvido',
                        'mensagem' => "{$usuario_logado['nome']} adicionou você como envolvido na tarefa: {$titulo}",
                        'link' => "/modules/agenda/?acao=visualizar&id={$tarefa_id}",
                        'prioridade' => 'normal'
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Tarefa criada com sucesso!',
            'tarefa_id' => $tarefa_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Erro ao salvar tarefa: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
