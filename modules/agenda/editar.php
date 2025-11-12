<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';
require_once __DIR__ . '/includes/AgendaPermissoes.php';


$usuario_logado = Auth::user();
$id = $_GET['id'] ?? 0;
$tipo = $_GET['tipo'] ?? 'tarefa';

if (!$id) {
    header('Location: index.php?erro=ID não informado');
    exit;
}

// Detectar tipo automaticamente se não informado
if (!isset($_GET['tipo'])) {
    $tabelas = [
        'tarefas' => 'tarefa',
        'prazos' => 'prazo',
        'audiencias' => 'audiencia',
        'agenda' => 'evento'
    ];
    
    foreach ($tabelas as $tabela => $tipo_detectado) {
        $sql_check = "SELECT 1 FROM {$tabela} WHERE id = ? LIMIT 1";
        try {
            $stmt = executeQuery($sql_check, [$id]);
            if ($stmt->fetch()) {
                $tipo = $tipo_detectado;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

// Buscar dados do item
try {
    switch ($tipo) {
        case 'tarefa':
            $sql = "SELECT t.*, pr.numero_processo, pr.cliente_nome 
                    FROM tarefas t
                    LEFT JOIN processos pr ON t.processo_id = pr.id
                    WHERE t.id = ? AND t.deleted_at IS NULL";
            break;
            
        case 'prazo':
            $sql = "SELECT p.*, pr.numero_processo, pr.cliente_nome 
                    FROM prazos p
                    INNER JOIN processos pr ON p.processo_id = pr.id
                    WHERE p.id = ? AND p.deleted_at IS NULL";
            break;
            
        case 'audiencia':
            $sql = "SELECT a.*, pr.numero_processo, pr.cliente_nome 
                    FROM audiencias a
                    INNER JOIN processos pr ON a.processo_id = pr.id
                    WHERE a.id = ? AND a.deleted_at IS NULL";
            break;
            
        case 'evento':
        case 'compromisso':
            $sql = "SELECT ag.*, 
                    pr.numero_processo,
                    c.nome as cliente_nome,
                    ag.processo_id,
                    ag.cliente_id
                    FROM agenda ag
                    LEFT JOIN processos pr ON ag.processo_id = pr.id
                    LEFT JOIN clientes c ON ag.cliente_id = c.id
                    WHERE ag.id = ? AND ag.deleted_at IS NULL";
            break;
            
        default:
            throw new Exception('Tipo não suportado');
    }
    
    $stmt = executeQuery($sql, [$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        header('Location: index.php?erro=Item não encontrado');
        exit;
    }
    
    // ====== VERIFICAR PERMISSÕES DE EDIÇÃO ======
    // Para TAREFAS: apenas gestores podem editar
    if (!AgendaPermissoes::podeEditar($item, $tipo, $usuario_logado)) {
        $mensagem_erro = AgendaPermissoes::getMensagemErro('editar', $tipo);
        $_SESSION['erro_edicao'] = $mensagem_erro;
        header('Location: visualizar.php?id=' . $id . '&tipo=' . $tipo . '&erro=' . urlencode($mensagem_erro));
        exit;
    }
    
    // Verificar se está concluído (apenas gestores podem editar itens concluídos)
    if (AgendaPermissoes::estaConcluido($item) && !AgendaPermissoes::ehGestor($usuario_logado)) {
        $mensagem_erro = 'Item concluído não pode ser editado. Apenas gestores podem reabrir.';
        $_SESSION['erro_edicao'] = $mensagem_erro;
        header('Location: visualizar.php?id=' . $id . '&tipo=' . $tipo . '&erro=' . urlencode($mensagem_erro));
        exit;
    }
    
} catch (Exception $e) {
    header('Location: index.php?erro=' . urlencode($e->getMessage()));
    exit;
}

// Buscar usuários
try {
    $sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// Buscar processos
try {
    $sql = "SELECT id, numero_processo, cliente_nome FROM processos WHERE ativo = 1 ORDER BY data_criacao DESC LIMIT 200";
    $stmt = executeQuery($sql);
    $processos = $stmt->fetchAll();
} catch (Exception $e) {
    $processos = [];
}

// Buscar etiquetas ativas
try {
    $sql_etiquetas = "SELECT id, nome, cor, icone 
                      FROM etiquetas 
                      WHERE ativo = 1 AND tipo IN (?, 'geral')
                      ORDER BY nome";
    $stmt_etiquetas = executeQuery($sql_etiquetas, [$tipo]);
    $etiquetas = $stmt_etiquetas->fetchAll();
} catch (Exception $e) {
    $etiquetas = [];
}

// Buscar etiquetas já vinculadas
$etiquetas_vinculadas = [];
try {
    $tabela_etiqueta = $tipo === 'tarefa' ? 'tarefa_etiquetas' : 
                       ($tipo === 'prazo' ? 'prazo_etiquetas' : 'audiencia_etiquetas');
    $campo_id = $tipo === 'tarefa' ? 'tarefa_id' : 
                ($tipo === 'prazo' ? 'prazo_id' : 'audiencia_id');
    
    $sql_vinculadas = "SELECT etiqueta_id FROM {$tabela_etiqueta} WHERE {$campo_id} = ?";
    $stmt_vinculadas = executeQuery($sql_vinculadas, [$id]);
    $etiquetas_vinculadas = $stmt_vinculadas->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Erro ao buscar etiquetas vinculadas: " . $e->getMessage());
}

// Buscar envolvidos
$envolvidos_ids = [];
try {
    if ($tipo === 'evento' || $tipo === 'compromisso') {
        // Para eventos, buscar participantes
        $sql_envolvidos = "SELECT usuario_id FROM agenda_participantes WHERE agenda_id = ?";
        $stmt_envolvidos = executeQuery($sql_envolvidos, [$id]);
        $envolvidos_ids = $stmt_envolvidos->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tabela_envolvido = $tipo === 'tarefa' ? 'tarefa_envolvidos' : 
                            ($tipo === 'prazo' ? 'prazo_envolvidos' : 'audiencia_envolvidos');
        $campo_id = $tipo === 'tarefa' ? 'tarefa_id' : 
                    ($tipo === 'prazo' ? 'prazo_id' : 'audiencia_id');
        
        $sql_envolvidos = "SELECT usuario_id FROM {$tabela_envolvido} WHERE {$campo_id} = ?";
        $stmt_envolvidos = executeQuery($sql_envolvidos, [$id]);
        $envolvidos_ids = $stmt_envolvidos->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log("Erro ao buscar envolvidos: " . $e->getMessage());
    $envolvidos_ids = [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $titulo = trim($_POST['titulo']);
        
        if (empty($titulo)) {
            throw new Exception('Título é obrigatório');
        }
        
        switch ($tipo) {
            case 'tarefa':
                $data_vencimento = !empty($_POST['data_vencimento']) ? $_POST['data_vencimento'] : null;
                
                $sql = "UPDATE tarefas SET 
                        titulo = ?,
                        descricao = ?,
                        data_vencimento = ?,
                        status = ?,
                        prioridade = ?,
                        responsavel_id = ?,
                        processo_id = ?,
                        data_atualizacao = NOW()
                        WHERE id = ?";

                $params = [
                    $titulo,
                    $_POST['descricao'] ?: null,
                    $data_vencimento,
                    $_POST['status'],
                    $_POST['prioridade'],
                    $_POST['responsavel_id'],
                    $_POST['processo_id'] ?: null,
                    $id
                ];
                break;
                
            case 'prazo':
                if (empty($_POST['data_vencimento'])) {
                    throw new Exception('Data de vencimento é obrigatória para prazos');
                }
                
                if (empty($_POST['processo_id'])) {
                    throw new Exception('Processo é obrigatório para prazos');
                }
                
                $sql = "UPDATE prazos SET 
                        titulo = ?,
                        descricao = ?,
                        data_vencimento = ?,
                        status = ?,
                        prioridade = ?,
                        responsavel_id = ?,
                        processo_id = ?,
                        data_atualizacao = NOW()
                        WHERE id = ?";

                $params = [
                    $titulo,
                    $_POST['descricao'] ?: null,
                    $_POST['data_vencimento'],
                    $_POST['status'],
                    $_POST['prioridade'],
                    $_POST['responsavel_id'],
                    $_POST['processo_id'],
                    $id
                ];
                break;
                
            case 'audiencia':
                if (empty($_POST['data_inicio'])) {
                    throw new Exception('Data/hora é obrigatória para audiências');
                }
                
                if (empty($_POST['processo_id'])) {
                    throw new Exception('Processo é obrigatório para audiências');
                }
                
                $sql = "UPDATE audiencias SET 
                        titulo = ?,
                        descricao = ?,
                        data_inicio = ?,
                        data_fim = ?,
                        tipo = ?,
                        local_evento = ?,
                        status = ?,
                        prioridade = ?,
                        responsavel_id = ?,
                        processo_id = ?,
                        data_atualizacao = NOW(),
                        atualizado_por = ?
                        WHERE id = ?";

                $params = [
                    $titulo,
                    $_POST['descricao'] ?: null,
                    $_POST['data_inicio'],
                    $_POST['data_fim'] ?: null,
                    'Audiência',
                    $_POST['local_evento'] ?: null,
                    $_POST['status'],
                    $_POST['prioridade'],
                    $_POST['responsavel_id'],
                    $_POST['processo_id'],
                    $usuario_logado['usuario_id'],
                    $id
                ];
                break;
                
            case 'evento':
            case 'compromisso':
                if (empty($_POST['data_inicio'])) {
                    throw new Exception('Data/hora de início é obrigatória');
                }
                
                if (empty($_POST['data_fim'])) {
                    throw new Exception('Data/hora de término é obrigatória');
                }
                
                $sql = "UPDATE agenda SET 
                        titulo = ?,
                        descricao = ?,
                        data_inicio = ?,
                        data_fim = ?,
                        tipo = ?,
                        local_evento = ?,
                        status = ?,
                        prioridade = ?,
                        cliente_id = ?,
                        processo_id = ?,
                        observacoes = ?,
                        lembrete_minutos = ?,
                        updated_at = NOW()
                        WHERE id = ?";

                $params = [
                    $titulo,
                    $_POST['descricao'] ?: null,
                    $_POST['data_inicio'],
                    $_POST['data_fim'],
                    $_POST['tipo'] ?: 'Compromisso',
                    $_POST['local_evento'] ?: null,
                    $_POST['status'],
                    $_POST['prioridade'],
                    $_POST['cliente_id'] ?: null,
                    $_POST['processo_id'] ?: null,
                    $_POST['observacoes'] ?: null,
                    $_POST['lembrete_minutos'] ?: 15,
                    $id
                ];
                break;
        }

        executeQuery($sql, $params);
        
        // ✅ REGISTRAR HISTÓRICO DAS ALTERAÇÕES
        require_once __DIR__ . '/includes/HistoricoHelper.php';
        
        // Definir campos a monitorar por tipo
        $campos_monitorar = [];
        
        switch ($tipo) {
            case 'tarefa':
                $campos_monitorar = ['titulo', 'descricao', 'status', 'prioridade', 'responsavel_id', 'data_vencimento', 'processo_id'];
                break;
                
            case 'prazo':
                $campos_monitorar = ['titulo', 'descricao', 'status', 'prioridade', 'responsavel_id', 'data_vencimento', 'processo_id'];
                break;
                
            case 'audiencia':
                $campos_monitorar = ['titulo', 'descricao', 'status', 'prioridade', 'responsavel_id', 'data_inicio', 'data_fim', 'tipo', 'local_evento', 'processo_id'];
                break;
                
            case 'evento':
            case 'compromisso':
                $campos_monitorar = ['titulo', 'descricao', 'status', 'prioridade', 'data_inicio', 'data_fim', 'tipo', 'local_evento', 'observacoes', 'cliente_id', 'processo_id'];
                break;
        }
        
        // Comparar e registrar cada alteração
        foreach ($campos_monitorar as $campo) {
            if (!isset($item[$campo])) {
                continue; // Campo não existe no item original
            }
            
            $valor_anterior = $item[$campo];
            $valor_novo = $_POST[$campo] ?? null;
            
            // Normalizar valores vazios para NULL
            if ($valor_anterior === '') $valor_anterior = null;
            if ($valor_novo === '') $valor_novo = null;
            
            // Comparação especial para datas
            if (in_array($campo, ['data_vencimento', 'data_inicio', 'data_fim'])) {
                // Se ambos estiverem vazios, não registrar
                if ($valor_anterior === null && $valor_novo === null) {
                    continue;
                }
                
                // Comparar timestamps se ambos existirem
                if ($valor_anterior !== null && $valor_novo !== null) {
                    $ts_anterior = strtotime($valor_anterior);
                    $ts_novo = strtotime($valor_novo);
                    
                    // Se forem iguais, pular
                    if ($ts_anterior == $ts_novo) {
                        continue;
                    }
                }
            }
            
            // Registrar se houve mudança real
            if ($valor_anterior != $valor_novo) {
                HistoricoHelper::registrarAlteracao($tipo, $id, $campo, $valor_anterior, $valor_novo);
            }
        }
        
        // Atualizar etiquetas
        $tabela_etiqueta = $tipo === 'tarefa' ? 'tarefa_etiquetas' : 
                          ($tipo === 'prazo' ? 'prazo_etiquetas' : 'audiencia_etiquetas');
        $campo_id = $tipo === 'tarefa' ? 'tarefa_id' : 
                    ($tipo === 'prazo' ? 'prazo_id' : 'audiencia_id');
        
        $sql_del_etiq = "DELETE FROM {$tabela_etiqueta} WHERE {$campo_id} = ?";
        executeQuery($sql_del_etiq, [$id]);
        
        if (!empty($_POST['etiquetas'])) {
            foreach ($_POST['etiquetas'] as $etiqueta_id) {
                $sql_ins_etiq = "INSERT INTO {$tabela_etiqueta} ({$campo_id}, etiqueta_id, criado_por) VALUES (?, ?, ?)";
                executeQuery($sql_ins_etiq, [$id, $etiqueta_id, $usuario_logado['usuario_id']]);
            }
        }
                
        // Atualizar envolvidos
        $tabela_envolvido = $tipo === 'tarefa' ? 'tarefa_envolvidos' : 
                           ($tipo === 'prazo' ? 'prazo_envolvidos' : 'audiencia_envolvidos');
        
        $sql_del_env = "DELETE FROM {$tabela_envolvido} WHERE {$campo_id} = ?";
        executeQuery($sql_del_env, [$id]);
        
        if (!empty($_POST['envolvidos'])) {
            foreach ($_POST['envolvidos'] as $usuario_id_env) {
                $sql_ins_env = "INSERT INTO {$tabela_envolvido} ({$campo_id}, usuario_id) VALUES (?, ?)";
                executeQuery($sql_ins_env, [$id, $usuario_id_env]);
            }
        }
        
        $_SESSION['success_message'] = ucfirst($tipo) . ' atualizada com sucesso!';
        header('Location: visualizar.php?id=' . $id . '&tipo=' . $tipo);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Usar dados do POST se houver erro, senão usar dados do banco
$dados = $_POST ?: $item;

$config = [
    'tarefa' => [
        'icone' => 'fa-tasks',
        'cor' => '#ffc107',
        'titulo' => 'Editar Tarefa'
    ],
    'prazo' => [
        'icone' => 'fa-calendar-check',
        'cor' => '#dc3545',
        'titulo' => 'Editar Prazo'
    ],
    'audiencia' => [
        'icone' => 'fa-gavel',
        'cor' => '#6f42c1',
        'titulo' => 'Editar Audiência'
    ]
];

$cfg = $config[$tipo];

ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }
    
    .btn-voltar {
        padding: 10px 20px;
        background: #6c757d;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        transform: translateY(-1px);
        color: white;
        text-decoration: none;
    }
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        margin-bottom: 30px;
    }
    
    .form-section {
        margin-bottom: 30px;
    }
    
    .form-section h3 {
        color: #1a1a1a;
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .form-grid.two-cols {
        grid-template-columns: 1fr 1fr;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-group label {
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group label.required::after {
        content: ' *';
        color: #dc3545;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        font-family: inherit;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .form-group small {
        margin-top: 5px;
        color: #666;
        font-size: 12px;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }
    
    .alert-warning {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid rgba(255, 193, 7, 0.3);
        color: #856404;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
    }
    
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .tipo-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        background: rgba(0,0,0,0.02);
        border-radius: 8px;
        border: 2px solid transparent;
        cursor: pointer;
        transition: all 0.3s;
        margin-bottom: 15px;
    }
    
    .tipo-checkbox:hover {
        background: rgba(102, 126, 234, 0.05);
        border-color: #667eea;
    }
    
    .tipo-checkbox input[type="radio"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    
    .tipo-checkbox.selected {
        background: rgba(102, 126, 234, 0.1);
        border-color: #667eea;
    }
    
    /* Busca de processo */
    .processo-busca-container {
        position: relative;
    }
    
    .processo-busca-input {
        width: 100%;
        padding: 12px 40px 12px 12px;
    }
    
    .processo-busca-icon {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
    }
    
    .processo-resultados {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .processo-resultados.active {
        display: block;
    }
    
    .processo-item {
        padding: 10px 12px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 13px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .processo-item:hover {
        background: rgba(102, 126, 234, 0.1);
    }
    
    .processo-numero {
        font-weight: 600;
        color: #667eea;
    }
    
    .processo-cliente {
        color: #666;
        font-size: 12px;
    }
    
    .processo-selecionado {
        padding: 12px;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        border-radius: 8px;
        font-size: 13px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
    }
    
    .btn-remover-processo {
        background: transparent;
        border: none;
        color: #dc3545;
        cursor: pointer;
        font-size: 18px;
        padding: 0;
        width: 24px;
        height: 24px;
    }
    
    /* Etiquetas */
    .etiquetas-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }
    
    .btn-criar-etiqueta {
        padding: 6px 12px;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .etiquetas-container {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        min-height: 40px;
    }
    
    .etiqueta-checkbox {
        display: none;
    }
    
    .etiqueta-label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 2px solid transparent;
        opacity: 0.6;
        transition: all 0.3s;
    }
    
    .etiqueta-checkbox:checked + .etiqueta-label {
        opacity: 1;
        border-color: rgba(0,0,0,0.2);
        transform: translateY(-2px);
    }
    
    /* Envolvidos */
    .usuarios-selector select {
        width: 100%;
        min-height: 120px;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }
    
    .usuarios-selector select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .usuarios-selector select option {
        padding: 8px;
        border-radius: 4px;
        margin: 2px 0;
    }
    
    .usuarios-selector select option:checked {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-weight: 600;
    }
    
    /* Modal Etiqueta */
    .modal-etiqueta {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }
    
    .modal-etiqueta.active {
        display: flex;
    }
    
    .modal-content-etiqueta {
        background: white;
        border-radius: 15px;
        padding: 25px;
        width: 90%;
        max-width: 500px;
    }
    
    .modal-header-etiqueta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
    }
    
    .btn-close-form {
        background: transparent;
        border: none;
        color: #999;
        font-size: 24px;
        cursor: pointer;
        transition: all 0.3s;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
    }
    
    .btn-close-form:hover {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }
    
    .cores-etiqueta {
        display: grid;
        grid-template-columns: repeat(8, 1fr);
        gap: 10px;
        margin: 15px 0;
    }
    
    .cor-option {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        cursor: pointer;
        border: 3px solid transparent;
        transition: all 0.2s;
    }
    
    .cor-option.selected {
        border-color: #1a1a1a;
        transform: scale(1.1);
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .form-grid,
        .form-grid.two-cols {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
    }
</style>

<div class="page-header">
    <h2>✏️ <?= $cfg['titulo'] ?></h2>
    <a href="visualizar.php?id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn-voltar">
        <i class="fas fa-arrow-left"></i> Voltar
    </a>
</div>

<?php if ($status_concluido): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-circle"></i> 
    <strong>Atenção:</strong> Esta <?= $tipo ?> está concluída. Você está editando como <strong><?= htmlspecialchars($usuario_logado['nivel_acesso']) ?></strong>. 
    Alterar o status permitirá que ela volte a aparecer como ativa.
</div>
<?php endif; ?>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['erro'])): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($_GET['erro']) ?>
</div>
<?php endif; ?>

<form method="POST" class="form-container">
    <!-- Informações Básicas -->
    <div class="form-section">
        <h3>📋 Informações <?= $tipo === 'tarefa' ? 'da Tarefa' : ($tipo === 'prazo' ? 'do Prazo' : 'da Audiência') ?></h3>
        
        <div class="form-group full-width">
            <label for="titulo" class="required">Título</label>
            <input type="text" id="titulo" name="titulo" required 
                   value="<?= htmlspecialchars($dados['titulo']) ?>"
                   placeholder="Ex: Elaborar petição inicial, Revisar documentos...">
        </div>
        
        <div class="form-group full-width">
            <label for="descricao">Descrição</label>
            <textarea id="descricao" name="descricao" 
                      placeholder="Descreva os detalhes..."><?= htmlspecialchars($dados['descricao'] ?? '') ?></textarea>
        </div>
    </div>

    <!-- Data, Status e Prioridade -->
    <div class="form-section">
        <h3>📅 Prazo, Status e Prioridade</h3>
        <div class="form-grid">
            <?php if ($tipo === 'audiencia'): ?>
                <div class="form-group">
                    <label for="data_inicio" class="required">Data/Hora Início</label>
                    <input type="datetime-local" id="data_inicio" name="data_inicio" required
                           value="<?= $dados['data_inicio'] ? date('Y-m-d\TH:i', strtotime($dados['data_inicio'])) : '' ?>">
                </div>
                
                <div class="form-group">
                    <label for="data_fim">Data/Hora Término</label>
                    <input type="datetime-local" id="data_fim" name="data_fim"
                           value="<?= !empty($dados['data_fim']) ? date('Y-m-d\TH:i', strtotime($dados['data_fim'])) : '' ?>">
                
                <div class="form-group full-width">
                    <label for="local_evento">Local</label>
                    <input type="text" id="local_evento" name="local_evento"
                           value="<?= htmlspecialchars($dados['local_evento'] ?? '') ?>"
                           placeholder="Ex: Fórum, sala, endereço...">
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label for="data_vencimento" <?= $tipo === 'prazo' ? 'class="required"' : '' ?>>
                        Data de Vencimento
                    </label>
                    <input type="datetime-local" id="data_vencimento" name="data_vencimento" 
                           <?= $tipo === 'prazo' ? 'required' : '' ?>
                           value="<?= !empty($dados['data_vencimento']) ? date('Y-m-d\TH:i', strtotime($dados['data_vencimento'])) : '' ?>">
                    <?php if ($tipo === 'tarefa'): ?>
                        <small>⚠️ Deixe em branco se não houver prazo específico</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="status">Status <?= $status_concluido ? '<span style="color: #ffc107;">(🔄 Atualmente concluída)</span>' : '' ?></label>
                <select id="status" name="status">
                    <option value="pendente" <?= ($dados['status'] ?? '') === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                    <option value="em_andamento" <?= ($dados['status'] ?? '') === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                    <?php if ($tipo === 'audiencia'): ?>
                        <option value="agendada" <?= ($dados['status'] ?? '') === 'agendada' ? 'selected' : '' ?>>Agendada</option>
                        <option value="realizada" <?= ($dados['status'] ?? '') === 'realizada' ? 'selected' : '' ?>>Realizada</option>
                    <?php endif; ?>
                    <option value="concluida" <?= ($dados['status'] ?? '') === 'concluida' ? 'selected' : '' ?>>Concluída</option>
                    <option value="cancelada" <?= ($dados['status'] ?? '') === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="prioridade">Prioridade</label>
                <select id="prioridade" name="prioridade">
                    <option value="baixa" <?= ($dados['prioridade'] ?? '') === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="normal" <?= ($dados['prioridade'] ?? '') === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="alta" <?= ($dados['prioridade'] ?? '') === 'alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgente" <?= ($dados['prioridade'] ?? '') === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Responsável -->
    <div class="form-section">
        <h3>👤 Responsável</h3>
        <div class="form-grid">
            <div class="form-group">
                <label for="responsavel_id">Atribuir para</label>
                <select id="responsavel_id" name="responsavel_id">
                    <?php foreach ($usuarios as $usuario): ?>
                    <option value="<?= $usuario['id'] ?>" 
                            <?= ($dados['responsavel_id'] ?? '') == $usuario['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($usuario['nome']) ?>
                        <?= $usuario['id'] == $usuario_logado['usuario_id'] ? ' (Você)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Vinculação -->
    <?php if ($tipo === 'tarefa'): ?>
    <div class="form-section">
        <h3>🔗 Vinculação</h3>
        
        <div style="margin-bottom: 20px;">
            <div class="tipo-checkbox <?= empty($dados['processo_id']) ? 'selected' : '' ?>" onclick="toggleTipoTarefa('avulsa')">
                <input type="radio" name="tipo_tarefa" value="avulsa" id="tipo_avulsa" 
                       <?= empty($dados['processo_id']) ? 'checked' : '' ?>>
                <label for="tipo_avulsa" style="cursor: pointer; margin: 0;">
                    <strong>📌 Tarefa Avulsa</strong><br>
                    <small style="color: #666;">Não vinculada a nenhum processo específico</small>
                </label>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <div class="tipo-checkbox <?= !empty($dados['processo_id']) ? 'selected' : '' ?>" onclick="toggleTipoTarefa('processo')">
                <input type="radio" name="tipo_tarefa" value="processo" id="tipo_processo"
                       <?= !empty($dados['processo_id']) ? 'checked' : '' ?>>
                <label for="tipo_processo" style="cursor: pointer; margin: 0;">
                    <strong>⚖️ Vinculada a Processo</strong><br>
                    <small style="color: #666;">Relacionada a um processo judicial</small>
                </label>
            </div>
        </div>
        
        <div id="processo-fields" style="<?= empty($dados['processo_id']) ? 'display: none;' : '' ?>">
            <div class="form-group">
                <label for="processo_busca">Buscar Processo</label>
                <div class="processo-busca-container">
                    <input type="text" 
                           class="form-control processo-busca-input" 
                           id="processo_busca" 
                           placeholder="Digite para buscar..."
                           autocomplete="off">
                    <i class="fas fa-search processo-busca-icon"></i>
                    <div class="processo-resultados" id="processoResultados"></div>
                </div>
                <input type="hidden" name="processo_id" id="processo_id" value="<?= $dados['processo_id'] ?? '' ?>">
                <div id="processoSelecionado">
                    <?php if (!empty($dados['processo_id']) && !empty($dados['numero_processo'])): ?>
                    <div class="processo-selecionado">
                        <div>
                            <div class="processo-numero"><?= htmlspecialchars($dados['numero_processo']) ?></div>
                            <div class="processo-cliente"><?= htmlspecialchars($dados['cliente_nome']) ?></div>
                        </div>
                        <button type="button" class="btn-remover-processo" onclick="removerProcesso()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="form-section">
        <h3>⚖️ Processo</h3>
        <div class="form-group">
            <label for="processo_busca">Buscar Processo <span class="required">*</span></label>
            <div class="processo-busca-container">
                <input type="text" 
                       class="form-control processo-busca-input" 
                       id="processo_busca" 
                       placeholder="Digite para buscar..."
                       autocomplete="off">
                <i class="fas fa-search processo-busca-icon"></i>
                <div class="processo-resultados" id="processoResultados"></div>
            </div>
            <input type="hidden" name="processo_id" id="processo_id" value="<?= $dados['processo_id'] ?? '' ?>" required>
            <div id="processoSelecionado">
                <?php if (!empty($dados['processo_id']) && !empty($dados['numero_processo'])): ?>
                <div class="processo-selecionado">
                    <div>
                        <div class="processo-numero"><?= htmlspecialchars($dados['numero_processo']) ?></div>
                        <div class="processo-cliente"><?= htmlspecialchars($dados['cliente_nome']) ?></div>
                    </div>
                    <button type="button" class="btn-remover-processo" onclick="removerProcesso()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Etiquetas -->
    <div class="form-section">
        <div class="etiquetas-header">
            <h3 style="margin: 0;">🏷️ Etiquetas</h3>
            <button type="button" class="btn-criar-etiqueta" id="btnNovaEtiqueta">
                <i class="fas fa-plus"></i> Nova
            </button>
        </div>
        <div class="etiquetas-container" id="etiquetasContainer">
            <?php if (empty($etiquetas)): ?>
                <small style="color: #999; font-size: 12px;">Clique em "Nova" para criar uma etiqueta</small>
            <?php else: ?>
                <?php foreach ($etiquetas as $etiqueta): ?>
                    <input type="checkbox" class="etiqueta-checkbox" 
                           id="etiqueta_<?= $etiqueta['id'] ?>" 
                           name="etiquetas[]" value="<?= $etiqueta['id'] ?>"
                           <?= in_array($etiqueta['id'], $etiquetas_vinculadas) ? 'checked' : '' ?>>
                    <label for="etiqueta_<?= $etiqueta['id'] ?>" 
                           class="etiqueta-label" 
                           style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                        <?= htmlspecialchars($etiqueta['nome']) ?>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Envolvidos -->
    <div class="form-section">
        <h3>👥 Envolvidos</h3>
        <div class="usuarios-selector">
            <select name="envolvidos[]" multiple size="6">
                <?php foreach ($usuarios as $usuario): ?>
                <option value="<?= $usuario['id'] ?>"
                        <?= in_array($usuario['id'], $envolvidos_ids) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($usuario['nome']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <small>💡 Use Ctrl/Cmd + clique para selecionar múltiplos</small>
    </div>

    <div class="form-actions">
        <a href="visualizar.php?id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">💾 Salvar Alterações</button>
    </div>
</form>

<!-- Modal Etiqueta -->
<div class="modal-etiqueta" id="modalEtiqueta">
    <div class="modal-content-etiqueta">
        <div class="modal-header-etiqueta">
            <h4><i class="fas fa-tag"></i> Nova Etiqueta</h4>
            <button type="button" class="btn-close-form" id="btnFecharModalEtiqueta">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formEtiqueta">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                    Nome <span style="color: #dc3545;">*</span>
                </label>
                <input type="text" class="form-control" id="nomeEtiqueta" 
                       name="nome" required placeholder="Ex: Urgente" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cor</label>
                <div class="cores-etiqueta" id="coresEtiqueta">
                    <div class="cor-option selected" data-cor="#667eea" style="background: #667eea;"></div>
                    <div class="cor-option" data-cor="#dc3545" style="background: #dc3545;"></div>
                    <div class="cor-option" data-cor="#28a745" style="background: #28a745;"></div>
                    <div class="cor-option" data-cor="#ffc107" style="background: #ffc107;"></div>
                    <div class="cor-option" data-cor="#17a2b8" style="background: #17a2b8;"></div>
                    <div class="cor-option" data-cor="#6f42c1" style="background: #6f42c1;"></div>
                    <div class="cor-option" data-cor="#fd7e14" style="background: #fd7e14;"></div>
                    <div class="cor-option" data-cor="#e83e8c" style="background: #e83e8c;"></div>
                </div>
                <input type="hidden" name="cor" id="corEtiqueta" value="#667eea">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Tipo</label>
                <select class="form-control" name="tipo" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                    <option value="<?= $tipo ?>"><?= ucfirst($tipo) ?></option>
                    <option value="geral">Geral</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" id="btnCancelarEtiqueta">
                    Cancelar
                </button>
                <button type="submit" class="btn" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                    Criar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
console.log('🔧 [EDITAR] Inicializando...');

function toggleTipoTarefa(tipo) {
    const radioAvulsa = document.getElementById('tipo_avulsa');
    const radioProcesso = document.getElementById('tipo_processo');
    const processoFields = document.getElementById('processo-fields');
    const processoSelect = document.getElementById('processo_id');
    
    if (tipo === 'avulsa') {
        radioAvulsa.checked = true;
        radioProcesso.checked = false;
        processoFields.style.display = 'none';
        processoSelect.value = '';
        removerProcesso();
    } else {
        radioAvulsa.checked = false;
        radioProcesso.checked = true;
        processoFields.style.display = 'block';
    }
    
    // Atualizar visual dos checkboxes
    document.querySelectorAll('.tipo-checkbox').forEach(el => el.classList.remove('selected'));
    if (tipo === 'avulsa') {
        document.querySelector('.tipo-checkbox:has(#tipo_avulsa)').classList.add('selected');
    } else {
        document.querySelector('.tipo-checkbox:has(#tipo_processo)').classList.add('selected');
    }
}

function removerProcesso() {
    const processoId = document.getElementById('processo_id');
    const processoSelecionado = document.getElementById('processoSelecionado');
    if (processoId) processoId.value = '';
    if (processoSelecionado) processoSelecionado.innerHTML = '';
}

// Busca de processos
const processoBusca = document.getElementById('processo_busca');
const processoResultados = document.getElementById('processoResultados');
let timeout = null;

if (processoBusca && processoResultados) {
    processoBusca.addEventListener('input', function() {
        const termo = this.value.trim();
        
        clearTimeout(timeout);
        
        if (termo.length < 2) {
            processoResultados.classList.remove('active');
            processoResultados.innerHTML = '';
            return;
        }
        
        timeout = setTimeout(() => buscarProcessos(termo), 300);
    });
    
    document.addEventListener('click', function(e) {
        if (!processoBusca.contains(e.target) && !processoResultados.contains(e.target)) {
            processoResultados.classList.remove('active');
        }
    });
}

async function buscarProcessos(termo) {
    console.log('🔍 [BUSCA] Buscando:', termo);
    try {
        const url = `/modules/agenda/formularios/buscar_processos.php?termo=${encodeURIComponent(termo)}`;
        const response = await fetch(url);
        
        const text = await response.text();
        const processos = JSON.parse(text);
        console.log('✅ [BUSCA] Encontrados:', processos.length);
        
        if (processos.length === 0) {
            processoResultados.innerHTML = '<div class="processo-item" style="color:#999">Nenhum encontrado</div>';
        } else {
            processoResultados.innerHTML = processos.map(p => `
                <div class="processo-item" data-id="${p.id}" data-numero="${p.numero_processo}" data-cliente="${p.cliente_nome}">
                    <div class="processo-numero">${p.numero_processo}</div>
                    <div class="processo-cliente">${p.cliente_nome}</div>
                </div>
            `).join('');
            
            processoResultados.querySelectorAll('.processo-item').forEach(item => {
                item.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const numero = this.dataset.numero;
                    const cliente = this.dataset.cliente;
                    
                    const processoId = document.getElementById('processo_id');
                    const processoBusca = document.getElementById('processo_busca');
                    const processoSelecionado = document.getElementById('processoSelecionado');
                    
                    if (processoId) processoId.value = id;
                    if (processoBusca) processoBusca.value = '';
                    processoResultados.classList.remove('active');
                    
                    if (processoSelecionado) {
                        processoSelecionado.innerHTML = `
                            <div class="processo-selecionado">
                                <div>
                                    <div class="processo-numero">${numero}</div>
                                    <div class="processo-cliente">${cliente}</div>
                                </div>
                                <button type="button" class="btn-remover-processo" onclick="removerProcesso()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                    }
                });
            });
        }
        
        processoResultados.classList.add('active');
    } catch (error) {
        console.error('❌ [BUSCA] Erro:', error);
    }
}

// Modal etiqueta
const btnNova = document.getElementById('btnNovaEtiqueta');
const btnFechar = document.getElementById('btnFecharModalEtiqueta');
const btnCancelar = document.getElementById('btnCancelarEtiqueta');
const modal = document.getElementById('modalEtiqueta');

if (btnNova && modal) {
    btnNova.addEventListener('click', function(e) {
        e.preventDefault();
        modal.classList.add('active');
        const input = document.getElementById('nomeEtiqueta');
        if (input) setTimeout(() => input.focus(), 100);
    });
}

function fecharModalEtiqueta() {
    if (modal) modal.classList.remove('active');
    const form = document.getElementById('formEtiqueta');
    if (form) form.reset();
    document.querySelectorAll('.cor-option').forEach(c => c.classList.remove('selected'));
    const primeira = document.querySelector('.cor-option');
    if (primeira) primeira.classList.add('selected');
    const corInput = document.getElementById('corEtiqueta');
    if (corInput) corInput.value = '#667eea';
}

if (btnFechar) btnFechar.addEventListener('click', fecharModalEtiqueta);
if (btnCancelar) btnCancelar.addEventListener('click', fecharModalEtiqueta);
if (modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) fecharModalEtiqueta();
    });
}

// Cores
document.querySelectorAll('.cor-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.cor-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        const corInput = document.getElementById('corEtiqueta');
        if (corInput) corInput.value = this.dataset.cor;
    });
});

// Form etiqueta
const formEtiqueta = document.getElementById('formEtiqueta');
if (formEtiqueta) {
    formEtiqueta.addEventListener('submit', async function(e) {
        e.preventDefault();
        console.log('💾 [ETIQUETA] Salvando...');
        
        const btnSubmit = this.querySelector('button[type="submit"]');
        const textoOriginal = btnSubmit.textContent;
        
        try {
            btnSubmit.disabled = true;
            btnSubmit.textContent = '⏳ Salvando...';
            
            const formData = new FormData(this);
            
            const response = await fetch('/modules/agenda/formularios/salvar_etiqueta.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('🎉 Etiqueta salva!');
                
                fecharModalEtiqueta();
                
                // Adicionar à lista
                const container = document.getElementById('etiquetasContainer');
                if (container) {
                    const small = container.querySelector('small');
                    if (small) small.remove();
                    
                    container.insertAdjacentHTML('beforeend', `
                        <input type="checkbox" class="etiqueta-checkbox" 
                               id="etiqueta_${result.etiqueta_id}" 
                               name="etiquetas[]" value="${result.etiqueta_id}" checked>
                        <label for="etiqueta_${result.etiqueta_id}" 
                               class="etiqueta-label" 
                               style="background: ${result.cor}; color: white;">
                            ${result.nome}
                        </label>
                    `);
                }
            } else {
                alert('❌ Erro: ' + result.message);
            }
            
        } catch (error) {
            console.error('❌ Erro ao salvar:', error);
            alert('❌ Erro: ' + error.message);
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.textContent = textoOriginal;
        }
    });
}

// Alertar ao concluir
document.getElementById('status').addEventListener('change', function() {
    if (this.value === 'concluida' || this.value === 'realizada') {
        if (!confirm('Tem certeza que deseja marcar como concluída?')) {
            this.value = '<?= $dados['status'] ?? 'pendente' ?>';
        }
    }
});

console.log('✅ [EDITAR] Script carregado!');
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout($cfg['titulo'], $conteudo, 'agenda');
?>