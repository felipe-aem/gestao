<?php
require_once '../../../includes/auth.php';
Auth::protect();
require_once '../../../config/database.php';

header('Content-Type: application/json');

try {
    $usuario_logado = Auth::user();
    
    // Pegar ID do usuário logado de forma mais robusta
    $usuario_id = isset($usuario_logado['id']) ? $usuario_logado['id'] : 
                  (isset($usuario_logado['usuario_id']) ? $usuario_logado['usuario_id'] :
                  (isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : null));
    
    if (!$usuario_id) {
        throw new Exception('Usuário não identificado');
    }
    
    // Validar campos obrigatórios
    if (empty($_POST['titulo'])) {
        throw new Exception('O título é obrigatório');
    }
    
    if (empty($_POST['processo_id'])) {
        throw new Exception('O processo é obrigatório');
    }
    
    if (empty($_POST['data_vencimento'])) {
        throw new Exception('A data de vencimento é obrigatória');
    }
    
    if (empty($_POST['responsavel_id'])) {
        throw new Exception('Selecione um responsável');
    }
    
    // Preparar dados
    $titulo = trim($_POST['titulo']);
    $processo_id = intval($_POST['processo_id']);
    $data_vencimento = $_POST['data_vencimento'];
    $hora_vencimento = !empty($_POST['hora_vencimento']) ? $_POST['hora_vencimento'] : '23:59';
    $dias_alerta = isset($_POST['dias_alerta']) ? intval($_POST['dias_alerta']) : 3;
    $prioridade = !empty($_POST['prioridade']) ? $_POST['prioridade'] : 'normal';
    $descricao = !empty($_POST['descricao']) ? trim($_POST['descricao']) : null;
    $responsavel_id = intval($_POST['responsavel_id']);
    $envolvidos = isset($_POST['envolvidos']) && is_array($_POST['envolvidos']) ? $_POST['envolvidos'] : [];
    $etiquetas = isset($_POST['etiquetas']) && is_array($_POST['etiquetas']) ? $_POST['etiquetas'] : [];
    
    // Combinar data e hora
    $data_hora_vencimento = $data_vencimento . ' ' . $hora_vencimento . ':00';
    
    // Obter conexão PDO
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // Validar processo
        $sql_processo = "SELECT id, numero_processo FROM processos WHERE id = ? LIMIT 1";
        $stmt_processo = $pdo->prepare($sql_processo);
        $stmt_processo->execute([$processo_id]);
        $processo = $stmt_processo->fetch(PDO::FETCH_ASSOC);
        
        if (!$processo) {
            throw new Exception('Processo não encontrado');
        }
        
        // Inserir o prazo
        $sql = "INSERT INTO prazos (
                    processo_id,
                    titulo,
                    descricao,
                    data_vencimento,
                    dias_alerta,
                    prioridade,
                    status,
                    responsavel_id,
                    criado_por,
                    data_criacao
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $processo_id,
            $titulo,
            $descricao,
            $data_hora_vencimento,
            $dias_alerta,
            $prioridade,
            $responsavel_id,
            $usuario_id
        ]);
        
        // Pegar ID do prazo inserido
        $prazo_id = $pdo->lastInsertId();
        
        if (!$prazo_id) {
            throw new Exception('Erro ao obter ID do prazo criado');
        }
        
        // ====== REGISTRAR NO HISTÓRICO COM DETALHES ======
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        // Buscar nome do responsável
        $sql_resp = "SELECT nome FROM usuarios WHERE id = ?";
        $stmt_resp = $pdo->prepare($sql_resp);
        $stmt_resp->execute([$responsavel_id]);
        $responsavel = $stmt_resp->fetch(PDO::FETCH_ASSOC);
        $responsavel_nome = $responsavel ? $responsavel['nome'] : 'Desconhecido';
        
        // Registrar criação com todos os detalhes
        HistoricoHelper::registrarCriacao('prazo', $prazo_id, 'Prazo criado via sistema de agenda', [
            'titulo' => $titulo,
            'responsavel_nome' => $responsavel_nome,
            'data_vencimento' => $data_hora_vencimento,
            'processo_numero' => $processo['numero_processo']
        ]);
        
        // Inserir envolvidos
        if (!empty($envolvidos)) {
            $sql_envolvido = "INSERT INTO prazo_envolvidos (prazo_id, usuario_id) VALUES (?, ?)";
            $stmt_envolvido = $pdo->prepare($sql_envolvido);
            
            foreach ($envolvidos as $envolvido_id) {
                $envolvido_id = intval($envolvido_id);
                if ($envolvido_id > 0) {
                    $stmt_envolvido->execute([$prazo_id, $envolvido_id]);
                }
            }
        }
        
        // Inserir etiquetas
        if (!empty($etiquetas)) {
            $sql_etiqueta = "INSERT INTO prazo_etiquetas (prazo_id, etiqueta_id, criado_por) 
                             VALUES (?, ?, ?)";
            $stmt_etiqueta = $pdo->prepare($sql_etiqueta);
            
            foreach ($etiquetas as $etiqueta_id) {
                $etiqueta_id = intval($etiqueta_id);
                if ($etiqueta_id > 0) {
                    $stmt_etiqueta->execute([$prazo_id, $etiqueta_id, $usuario_id]);
                }
            }
        }
        
        // Registrar no histórico do processo
        $sql_historico = "INSERT INTO processo_historico (
                            processo_id, usuario_id, acao, descricao, data_acao
                         ) VALUES (?, ?, ?, ?, NOW())";
        $stmt_historico = $pdo->prepare($sql_historico);
        $stmt_historico->execute([
            $processo_id,
            $usuario_id,
            'Prazo Criado',
            "Prazo criado: {$titulo}"
        ]);
        
        // Criar notificações
        if (function_exists('criarNotificacao')) {
            require_once '../../../includes/notificacoes_helper.php';
            
            // Notificar responsável
            if ($responsavel_id != $usuario_id) {
                criarNotificacao([
                    'usuario_id' => $responsavel_id,
                    'tipo' => 'prazo_atribuido',
                    'titulo' => 'Novo prazo atribuído',
                    'mensagem' => "{$usuario_logado['nome']} atribuiu um prazo para você: {$titulo}",
                    'link' => "/modules/agenda/?acao=visualizar&id={$prazo_id}&tipo=prazo",
                    'prioridade' => $prioridade === 'urgente' ? 'alta' : 'normal'
                ]);
            }
            
            // Notificar envolvidos
            foreach ($envolvidos as $envolvido_id) {
                $envolvido_id = intval($envolvido_id);
                if ($envolvido_id != $usuario_id && $envolvido_id != $responsavel_id) {
                    criarNotificacao([
                        'usuario_id' => $envolvido_id,
                        'tipo' => 'prazo_envolvido',
                        'titulo' => 'Você foi adicionado a um prazo',
                        'mensagem' => "{$usuario_logado['nome']} adicionou você ao prazo: {$titulo}",
                        'link' => "/modules/agenda/?acao=visualizar&id={$prazo_id}&tipo=prazo",
                        'prioridade' => 'normal'
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Prazo criado com sucesso!',
            'prazo_id' => $prazo_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Erro ao salvar prazo: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}