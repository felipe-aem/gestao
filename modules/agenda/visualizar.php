<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once __DIR__ . '/includes/HistoricoHelper.php';

$usuario_logado = Auth::user();
$id = $_GET['id'] ?? 0;
$tipo = $_GET['tipo'] ?? null;

if (!$id) {
    header('Location: index.php?erro=ID não informado');
    exit;
}

// Detectar tipo automaticamente se não informado
if (empty($tipo)) {
    $tabelas = [
        'tarefas' => 'tarefa',
        'prazos' => 'prazo',
        'audiencias' => 'audiencia',
        'agenda' => 'compromisso'
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
    
    // Se ainda não encontrou, assume tarefa como padrão
    if (empty($tipo)) {
        $tipo = 'tarefa';
    }
}

// Buscar dados
try {
    switch ($tipo) {
        case 'tarefa':
            $sql = "SELECT t.*, 
                    pr.numero_processo, pr.cliente_nome, pr.cliente_id,
                    u.nome as responsavel_nome,
                    uc.nome as criado_por_nome,
                    uenv.nome as enviar_para_nome
                    FROM tarefas t
                    LEFT JOIN processos pr ON t.processo_id = pr.id
                    LEFT JOIN usuarios u ON t.responsavel_id = u.id
                    LEFT JOIN usuarios uc ON t.criado_por = uc.id
                    LEFT JOIN usuarios uenv ON t.enviar_para_usuario_id = uenv.id
                    WHERE t.id = ? AND t.deleted_at IS NULL";
            break;
                    
        case 'prazo':
            $sql = "SELECT p.*, 
                    pr.numero_processo, pr.cliente_nome, pr.cliente_id,
                    u.nome as responsavel_nome,
                    uc.nome as criado_por_nome
                    FROM prazos p
                    LEFT JOIN processos pr ON p.processo_id = pr.id
                    LEFT JOIN usuarios u ON p.responsavel_id = u.id
                    LEFT JOIN usuarios uc ON p.criado_por = uc.id
                    WHERE p.id = ? AND p.deleted_at IS NULL";
            break;
            
        case 'audiencia':
            $sql = "SELECT a.*, 
                    pr.numero_processo,
                    pr.cliente_nome,
                    pr.id as processo_id,
                    pr.cliente_id,
                    u.nome as responsavel_nome,
                    uc.nome as criado_por_nome
                    FROM audiencias a
                    INNER JOIN processos pr ON a.processo_id = pr.id
                    LEFT JOIN usuarios u ON a.responsavel_id = u.id
                    LEFT JOIN usuarios uc ON a.criado_por = uc.id
                    WHERE a.id = ? AND a.deleted_at IS NULL";
            break;
            
        case 'compromisso':
        case 'evento':
            $sql = "SELECT ag.*, 
                    pr.numero_processo,
                    c.nome as cliente_nome,
                    ag.processo_id,
                    ag.cliente_id,
                    uc.nome as criado_por_nome
                    FROM agenda ag
                    LEFT JOIN processos pr ON ag.processo_id = pr.id
                    LEFT JOIN clientes c ON ag.cliente_id = c.id
                    LEFT JOIN usuarios uc ON ag.criado_por = uc.id
                    WHERE ag.id = ?";
            break;
            
        default:
            throw new Exception('Tipo não suportado ainda');
    }
    
    $stmt = executeQuery($sql, [$id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        header('Location: index.php?erro=Item não encontrado');
        exit;
    }
    
    // Envolvidos
    $envolvidos = [];
    if ($tipo === 'tarefa') {
        $sql_env = "SELECT e.*, u.nome, u.email
                    FROM tarefa_envolvidos e
                    INNER JOIN usuarios u ON e.usuario_id = u.id
                    WHERE e.tarefa_id = ?";
        $stmt_env = executeQuery($sql_env, [$id]);
        $envolvidos = $stmt_env->fetchAll();
    } elseif ($tipo === 'audiencia') {
        $sql_env = "SELECT e.*, u.nome, u.email
                    FROM audiencia_envolvidos e
                    INNER JOIN usuarios u ON e.usuario_id = u.id
                    WHERE e.audiencia_id = ?";
        $stmt_env = executeQuery($sql_env, [$id]);
        $envolvidos = $stmt_env->fetchAll();
    } elseif ($tipo === 'compromisso' || $tipo === 'evento') {
        try {
            $sql_env = "SELECT ap.*, u.nome, u.email, ap.status_participacao
                        FROM agenda_participantes ap
                        INNER JOIN usuarios u ON ap.usuario_id = u.id
                        WHERE ap.agenda_id = ?";
            $stmt_env = executeQuery($sql_env, [$id]);
            $envolvidos = $stmt_env->fetchAll();
        } catch (Exception $e) {
            error_log("Tabela agenda_participantes não existe: " . $e->getMessage());
            $envolvidos = [];
        }
    }
    
    // Etiquetas
    $etiquetas = [];
    if ($tipo === 'tarefa') {
        $sql_etiq = "SELECT e.* 
                     FROM tarefa_etiquetas te
                     INNER JOIN etiquetas e ON te.etiqueta_id = e.id
                     WHERE te.tarefa_id = ?";
        $stmt_etiq = executeQuery($sql_etiq, [$id]);
        $etiquetas = $stmt_etiq->fetchAll();
    } elseif ($tipo === 'audiencia') {
        $sql_etiq = "SELECT e.* 
                     FROM audiencia_etiquetas ae
                     INNER JOIN etiquetas e ON ae.etiqueta_id = e.id
                     WHERE ae.audiencia_id = ?";
        $stmt_etiq = executeQuery($sql_etiq, [$id]);
        $etiquetas = $stmt_etiq->fetchAll();
    } elseif ($tipo === 'compromisso' || $tipo === 'evento') {
        try {
            $sql_etiq = "SELECT e.* 
                         FROM agenda_etiquetas ae
                         INNER JOIN etiquetas e ON ae.etiqueta_id = e.id
                         WHERE ae.agenda_id = ?";
            $stmt_etiq = executeQuery($sql_etiq, [$id]);
            $etiquetas = $stmt_etiq->fetchAll();
        } catch (Exception $e) {
            error_log("Tabela agenda_etiquetas não existe: " . $e->getMessage());
            $etiquetas = [];
        }
    }
    
    // Calcular dias
    $dias_info = ['texto' => '', 'classe' => '', 'dias' => null];
    
    // Para audiência, usar data_inicio
    // Para audiência e compromisso, usar data_inicio
    $campo_data = ($tipo === 'audiencia' || $tipo === 'compromisso' || $tipo === 'evento') ? 'data_inicio' : 'data_vencimento';
    
    if (!empty($item[$campo_data])) {
        $data = new DateTime($item[$campo_data]);
        $hoje = new DateTime();
        $diff = $hoje->diff($data);
        
        if ($tipo === 'audiencia' || $tipo === 'compromisso' || $tipo === 'evento') {
            // Para audiências, mostrar se é hoje, foi ou será
            if ($data->format('Y-m-d') == $hoje->format('Y-m-d')) {
                $dias_info = [
                    'texto' => "Hoje às " . $data->format('H:i'),
                    'classe' => 'urgente',
                    'dias' => 0
                ];
            } elseif ($data < $hoje) {
                $dias_info = [
                    'texto' => "Realizada há " . $diff->days . " dia(s)",
                    'classe' => 'normal',
                    'dias' => -$diff->days
                ];
            } else {
                $dias_info = [
                    'texto' => "Em " . $diff->days . " dia(s)",
                    'classe' => $diff->days <= 3 ? 'urgente' : 'normal',
                    'dias' => $diff->days
                ];
            }
        } else {
            // Para tarefas/prazos (lógica original)
            if ($data < $hoje) {
                $dias_info = [
                    'texto' => "Atrasado há " . $diff->days . " dia(s)",
                    'classe' => 'atrasado',
                    'dias' => -$diff->days
                ];
            } else {
                $dias_info = [
                    'texto' => $diff->days == 0 ? "Vence hoje!" : "Vence em " . $diff->days . " dia(s)",
                    'classe' => $diff->days <= 3 ? 'urgente' : 'normal',
                    'dias' => $diff->days
                ];
            }
        }
    }
    
    // Buscar histórico
    $historico = HistoricoHelper::buscar($tipo, $id);
    
} catch (Exception $e) {
    header('Location: index.php?erro=' . urlencode($e->getMessage()));
    exit;
}

$config = [
    'tarefa' => [
        'icone' => 'fa-tasks',
        'cor' => '#ffc107',
        'titulo_tipo' => 'Tarefa'
    ],
    'prazo' => [
        'icone' => 'fa-calendar-check',
        'cor' => '#dc3545',
        'titulo_tipo' => 'Prazo'
    ],
    'audiencia' => [
        'icone' => 'fa-gavel',
        'cor' => '#667eea',
        'titulo_tipo' => 'Audiência'
    ],
    'compromisso' => [
        'icone' => 'fa-calendar-alt',
        'cor' => '#17a2b8',
        'titulo_tipo' => 'Evento'
    ],
    'evento' => [
        'icone' => 'fa-calendar-alt',
        'cor' => '#17a2b8',
        'titulo_tipo' => 'Evento'
    ]
];

$cfg = $config[$tipo];
$page_title = $cfg['titulo_tipo'] . ': ' . ($item['titulo'] ?? '');

// MONTAR CONTEÚDO
ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.visualizar-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.header-box {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.header-box h1 {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 0 0 15px 0;
    font-size: 26px;
}

.icone-tipo {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.badges-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.badge {
    padding: 6px 14px;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
}

.status-pendente { background: rgba(255, 193, 7, 0.2); color: #856404; }
.status-concluida { background: rgba(40, 167, 69, 0.2); color: #155724; }
.status-em_andamento { background: rgba(23, 162, 184, 0.2); color: #0c5460; }
.status-agendada { background: rgba(102, 126, 234, 0.2); color: #4c51bf; }
.status-realizada { background: rgba(40, 167, 69, 0.2); color: #155724; }
.status-adiada { background: rgba(255, 193, 7, 0.2); color: #856404; }
.status-cancelada { background: rgba(220, 53, 69, 0.2); color: #721c24; }
.urgente { background: rgba(220, 53, 69, 0.2); color: #721c24; }
.normal { background: rgba(23, 162, 184, 0.2); color: #0c5460; }
.atrasado { background: rgba(220, 53, 69, 0.3); color: #721c24; }

.acoes {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s;
}

.btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
.btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-danger { background: #dc3545; color: white; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.tabs-box {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.tabs-header {
    display: flex;
    border-bottom: 2px solid rgba(0,0,0,0.05);
    background: #f8f9fa;
}

.tab-btn {
    padding: 15px 25px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-weight: 600;
    color: #666;
    position: relative;
}

.tab-btn.active {
    color: #667eea;
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 2px;
    background: #667eea;
}

.tab-content {
    display: none;
    padding: 25px;
}

.tab-content.active {
    display: block;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
}

.info-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 10px;
    border-left: 4px solid <?= $cfg['cor'] ?>;
}

.info-label {
    font-size: 11px;
    font-weight: 700;
    color: #666;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 15px;
    color: #1a1a1a;
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    color: #155724;
    border-left: 4px solid #28a745;
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.alert-danger {
    background: rgba(220, 53, 69, 0.1);
    color: #721c24;
    border-left: 4px solid #dc3545;
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Histórico Timeline */
.historico-timeline {
    position: relative;
    padding-left: 50px;
}

.historico-timeline::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #667eea, rgba(102, 126, 234, 0.1));
}

.historico-item {
    position: relative;
    margin-bottom: 25px;
    padding-bottom: 25px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.historico-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.historico-icon {
    position: absolute;
    left: -38px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: 3px solid white;
}

.historico-content {
    background: rgba(0,0,0,0.02);
    padding: 15px;
    border-radius: 10px;
    border-left: 3px solid #667eea;
}

.historico-text {
    color: #333;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 8px;
}

.historico-meta {
    display: flex;
    gap: 20px;
    font-size: 12px;
    color: #666;
}

.historico-meta i {
    margin-right: 5px;
    color: #999;
}

.historico-usuario {
    font-weight: 600;
}

.historico-tempo {
    color: #999;
}
</style>

<div class="visualizar-container">
    <?php if (isset($_GET['erro'])): ?>
        <div class="alert-danger">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong>Erro:</strong> <?= htmlspecialchars($_GET['erro']) ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <div class="header-box">
        <h1>
            <div class="icone-tipo" style="background: <?= $cfg['cor'] ?>;">
                <i class="fas <?= $cfg['icone'] ?>"></i>
            </div>
            <?= htmlspecialchars($item['titulo']) ?>
        </h1>
        
        <div class="badges-row">
            <?php if (isset($item['status'])): ?>
                <span class="badge status-<?= $item['status'] ?>">
                    <?= ucfirst(str_replace('_', ' ', $item['status'])) ?>
                </span>
            <?php endif; ?>
            
            <?php 
            // Verificar se está concluída/realizada
            $status_concluido = isset($item['status']) && in_array(strtolower($item['status']), 
                ['concluida', 'concluído', 'cumprido', 'realizada', 'realizado']);
            
            // Só mostrar badge de vencimento se NÃO estiver concluída/realizada
            if ($dias_info['dias'] !== null && !$status_concluido): 
            ?>
                <span class="badge <?= $dias_info['classe'] ?>">
                    <i class="fas fa-clock"></i> <?= $dias_info['texto'] ?>
                </span>
            <?php endif; ?>
            
            <?php if (!empty($item['prioridade'])): ?>
                <span class="badge" style="background: rgba(255,107,107,0.2); color: #c92a2a;">
                    <i class="fas fa-flag"></i> <?= ucfirst($item['prioridade']) ?>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="acoes">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            
            <?php if ($tipo === 'tarefa' && $item['status'] !== 'concluida'): ?>
                <?php
                // Se é uma tarefa de REVISÃO, não mostra botão "Concluir"
                // Só mostra o botão "Responder Revisão"
                $eh_revisao = ($item['tipo_tarefa'] === 'revisao' || $item['tipo_prazo'] === 'revisao');
                ?>
                
                <?php if (!$eh_revisao): ?>
                    <button onclick="concluirTarefa(<?= $id ?>)" class="btn btn-success">
                        <i class="fas fa-check"></i> Concluir
                    </button>
                <?php endif; ?>
                
                <?php if ($eh_revisao): ?>
                    <?php
                    // Buscar ID da revisão
                    $sql_rev = "SELECT id FROM tarefa_revisoes 
                                WHERE tarefa_revisao_id = ? AND status = 'pendente'
                                LIMIT 1";
                    $stmt_rev = executeQuery($sql_rev, [$id]);
                    $revisao = $stmt_rev->fetch();
                    
                    if ($revisao):
                    ?>
                        <button onclick="abrirModalResponderRevisao(<?= $revisao['id'] ?>)" 
                                class="btn btn-primary">
                            <i class="fas fa-clipboard-check"></i> Responder Revisão
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($tipo === 'prazo' && $item['status'] !== 'concluido'): ?>
                <?php
                // Se é um prazo de REVISÃO, não mostra botão "Concluir"
                // Só mostra o botão "Responder Revisão"
                $eh_revisao_prazo = ($item['tipo_prazo'] === 'revisao');
                ?>
                
                <?php if (!$eh_revisao_prazo): ?>
                    <button onclick="concluirPrazo(<?= $id ?>)" class="btn btn-success">
                        <i class="fas fa-check"></i> Concluir
                    </button>
                <?php endif; ?>
                
                <?php if ($eh_revisao_prazo): ?>
                    <?php
                    // Buscar ID da revisão
                    $sql_rev_prazo = "SELECT id FROM tarefa_revisoes 
                                WHERE tarefa_revisao_id = ? AND tipo_origem = 'prazo' AND status = 'pendente'
                                LIMIT 1";
                    $stmt_rev_prazo = executeQuery($sql_rev_prazo, [$id]);
                    $revisao_prazo = $stmt_rev_prazo->fetch();
                    
                    if ($revisao_prazo):
                    ?>
                        <button onclick="abrirModalResponderRevisao(<?= $revisao_prazo['id'] ?>)" 
                                class="btn btn-primary">
                            <i class="fas fa-clipboard-check"></i> Responder Revisão
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php
            // Verificar se pode editar
            $niveis_permitidos = ['Admin', 'Gestor', 'Diretor'];
            $nivel_acesso = $usuario_logado['nivel_acesso'] ?? '';
            $pode_editar = !$status_concluido || in_array($nivel_acesso, $niveis_permitidos);
            ?>
            
            <?php if ($pode_editar): ?>
                <a href="editar.php?id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
            <?php else: ?>
                <button class="btn btn-primary" disabled style="opacity: 0.5; cursor: not-allowed;" 
                        title="Item concluído. Apenas gestores podem editar.">
                    <i class="fas fa-lock"></i> Editar
                </button>
            <?php endif; ?>
            
            <button onclick="if(confirm('Excluir?')) window.location='excluir.php?id=<?= $id ?>&tipo=<?= $tipo ?>'" 
                    class="btn btn-danger">
                <i class="fas fa-trash"></i> Excluir
            </button>
        </div>
    </div>
    
    <div class="tabs-box">
        <div class="tabs-header">
            <button class="tab-btn active" onclick="abrirTab(0)">
                <i class="fas fa-info-circle"></i> Detalhes
            </button>
            <button class="tab-btn" onclick="abrirTab(1)">
                <i class="fas fa-comments"></i> Comentários
            </button>
            <button class="tab-btn" onclick="abrirTab(2)">
                <i class="fas fa-history"></i> Histórico
            </button>
        </div>
        
        <div class="tab-content active" id="tab-0">
            <div class="info-grid">
                <?php if (!empty($item['responsavel_nome'])): ?>
                <div class="info-item">
                    <div class="info-label">
                        <?= $tipo === 'audiencia' ? 'Advogado Responsável' : 'Responsável' ?>
                    </div>
                    <div class="info-value">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($item['responsavel_nome']) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tipo === 'audiencia' && !empty($item['data_inicio'])): ?>
                <div class="info-item">
                    <div class="info-label">Data e Hora da Audiência</div>
                    <div class="info-value">
                        <i class="fas fa-calendar"></i> 
                        <?= date('d/m/Y', strtotime($item['data_inicio'])) ?>
                        às <?= date('H:i', strtotime($item['data_inicio'])) ?>
                    </div>
                </div>
                <?php elseif (!empty($item['data_vencimento'])): ?>
                <div class="info-item">
                    <div class="info-label">Vencimento</div>
                    <div class="info-value">
                        <i class="fas fa-calendar"></i> 
                        <?= date('d/m/Y H:i', strtotime($item['data_vencimento'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($tipo === 'audiencia' && !empty($item['local_evento'])): ?>
                <div class="info-item">
                    <div class="info-label">Local</div>
                    <div class="info-value">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['local_evento']) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($item['numero_processo'])): ?>
                <div class="info-item info-item-clickable" 
                     onclick="abrirProcesso(event, <?= $item['processo_id'] ?>)"
                     style="cursor: pointer; transition: all 0.3s;"
                     onmouseover="this.style.transform='translateX(5px)'; this.style.boxShadow='0 4px 15px rgba(102, 126, 234, 0.2)'"
                     onmouseout="this.style.transform='translateX(0)'; this.style.boxShadow='none'">
                    <div class="info-label">
                        Processo 
                        <i class="fas fa-external-link-alt" style="font-size: 10px; opacity: 0.5;"></i>
                    </div>
                    <div class="info-value">
                        <i class="fas fa-gavel"></i> 
                        <span id="numProcesso" 
                              onclick="copiarNumeroProcesso(event, '<?= htmlspecialchars($item['numero_processo']) ?>')" 
                              style="color: #667eea; font-weight: 600; cursor: copy; position: relative; padding: 2px 4px;"
                              onmouseover="this.style.background='rgba(102, 126, 234, 0.1)'; this.style.borderRadius='4px'"
                              onmouseout="this.style.background='transparent'"
                              title="Clique para copiar (apenas números)">
                            <?= htmlspecialchars($item['numero_processo']) ?>
                            <i class="fas fa-copy" style="font-size: 11px; margin-left: 5px; opacity: 0.6;"></i>
                        </span>
                        <br>
                        <small style="color: #666;">
                            <i class="fas fa-building"></i> <?= htmlspecialchars($item['cliente_nome']) ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($item['criado_por_nome'])): ?>
                <div class="info-item">
                    <div class="info-label">Criado por</div>
                    <div class="info-value">
                        <i class="fas fa-user-plus"></i> <?= htmlspecialchars($item['criado_por_nome']) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($item['descricao'])): ?>
            <div style="margin-top: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                <div class="info-label">
                    <?= $tipo === 'audiencia' ? 'Observações' : 'Descrição' ?>
                </div>
                <div style="margin-top: 10px; white-space: pre-wrap;"><?= htmlspecialchars($item['descricao']) ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($tipo === 'tarefa' && !empty($item['enviar_para_nome'])): ?>
            <div style="margin-top: 20px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
                        padding: 20px; border-radius: 10px; border-left: 4px solid #667eea;">
                <div class="info-label">🔄 FLUXO CONFIGURADO</div>
                <div style="margin-top: 10px;">
                    <strong>Enviar para:</strong> <?= htmlspecialchars($item['enviar_para_nome']) ?><br>
                    <?php if (!empty($item['fluxo_tipo'])): ?>
                        <strong>Tipo:</strong> <?= htmlspecialchars($item['fluxo_tipo']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($item['fluxo_instrucao'])): ?>
                        <strong>Instruções:</strong> <?= htmlspecialchars($item['fluxo_instrucao']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($envolvidos)): ?>
            <div style="margin-top: 20px;">
                <div class="info-label">Envolvidos</div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                    <?php foreach ($envolvidos as $env): ?>
                        <div style="background: white; padding: 8px 15px; border-radius: 20px; 
                                   box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($env['nome']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($etiquetas)): ?>
            <div style="margin-top: 20px;">
                <div class="info-label">Etiquetas</div>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">
                    <?php foreach ($etiquetas as $etiq): ?>
                        <span style="background: <?= htmlspecialchars($etiq['cor']) ?>; color: white;
                                    padding: 6px 12px; border-radius: 16px; font-size: 12px; font-weight: 600;">
                            <?= htmlspecialchars($etiq['nome']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="tab-1">
            <?php 
            $tipo_item = $tipo;
            $item_id = $id;
            
            $widget_path = __DIR__ . '/comentarios/comentarios_widget.php';
            
            if (file_exists($widget_path)) {
                try {
                    // Capturar qualquer erro
                    error_reporting(E_ALL);
                    ini_set('display_errors', 1);
                    
                    include $widget_path;
                    
                } catch (Exception $e) {
                    echo '<div style="padding: 40px; color: red;">';
                    echo '<h3>ERRO ao carregar widget:</h3>';
                    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                }
            } else {
                echo '<div style="padding: 40px; text-align: center; color: #999;">';
                echo '<p><i class="fas fa-comments" style="font-size: 48px; display: block; margin-bottom: 15px;"></i></p>';
                echo '<p>Widget não encontrado em:</p>';
                echo '<code>' . htmlspecialchars($widget_path) . '</code>';
                echo '</div>';
            }
            ?>
        </div>

        <div class="tab-content" id="tab-2">
            <?= HistoricoHelper::renderHistorico($tipo, $id) ?>
        </div>
    </div>
</div>

<script>
function abrirTab(index) {
    document.querySelectorAll('.tab-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i === index);
    });
    document.querySelectorAll('.tab-content').forEach((content, i) => {
        content.classList.toggle('active', i === index);
    });
}

function abrirProcesso(event, processoId) {
    if (event.target.id === 'numProcesso' || event.target.closest('#numProcesso')) {
        return;
    }
    window.open('../processos/visualizar.php?id=' + processoId, '_blank');
}

function copiarNumeroProcesso(event, numeroProcesso) {
    event.stopPropagation();
    event.preventDefault();
    
    const numeroLimpo = numeroProcesso.replace(/[^\d]/g, '');
    const elemento = event.currentTarget;
    const textoOriginal = elemento.innerHTML;
    
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(numeroLimpo)
            .then(() => mostrarFeedbackCopia(elemento, textoOriginal))
            .catch(() => copiarFallback(numeroLimpo, elemento, textoOriginal));
    } else {
        copiarFallback(numeroLimpo, elemento, textoOriginal);
    }
}

function copiarFallback(texto, elemento, textoOriginal) {
    const textarea = document.createElement('textarea');
    textarea.value = texto;
    textarea.style.position = 'fixed';
    textarea.style.left = '-999999px';
    document.body.appendChild(textarea);
    textarea.select();
    
    try {
        document.execCommand('copy');
        mostrarFeedbackCopia(elemento, textoOriginal);
    } catch (err) {
        alert('Número: ' + texto);
    }
    
    document.body.removeChild(textarea);
}

function mostrarFeedbackCopia(elemento, textoOriginal) {
    elemento.innerHTML = '<i class="fas fa-check" style="color: #28a745;"></i> Copiado!';
    elemento.style.color = '#28a745';
    
    setTimeout(() => {
        elemento.innerHTML = textoOriginal;
        elemento.style.color = '#667eea';
    }, 2000);
}

function concluirTarefa(id) {
    // CORRIGIDO: Perguntar ANTES de concluir
    // Abre o modal de pergunta primeiro, SEM marcar como concluída
    abrirModalPerguntaRevisao(id, 'tarefa', function(enviarParaRevisao, item) {
        
        if (enviarParaRevisao) {
            // Se escolheu enviar para revisão, abre o modal de revisão
            // NÃO marca como concluída ainda - só vai marcar quando enviar a revisão
            abrirModalRevisao(item.id, item.tipo);
        } else {
            // Se escolheu NÃO enviar para revisão, AGORA SIM conclui
            const btn = document.querySelector(`button[onclick*="concluirTarefa(${id})"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Concluindo...';
            }
            
            fetch('formularios/concluir_tarefa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `tarefa_id=${id}&enviar_revisao=nao`
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    alert(result.message || 'Tarefa concluída!');
                    window.location.reload();
                } else {
                    alert('Erro: ' + result.message);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i> Concluir';
                    }
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao concluir');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Concluir';
                }
            });
        }
    });
}

// Função para concluir prazo (similar)
function concluirPrazo(id) {
    // CORRIGIDO: Perguntar ANTES de concluir
    // Abre o modal de pergunta primeiro, SEM marcar como concluído
    abrirModalPerguntaRevisao(id, 'prazo', function(enviarParaRevisao, item) {
        
        if (enviarParaRevisao) {
            // Se escolheu enviar para revisão, abre o modal de revisão
            // NÃO marca como concluído ainda - só vai marcar quando enviar a revisão
            abrirModalRevisao(item.id, item.tipo);
        } else {
            // Se escolheu NÃO enviar para revisão, AGORA SIM conclui
            const btn = document.querySelector(`button[onclick*="concluirPrazo(${id})"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Concluindo...';
            }
            
            fetch('formularios/concluir_prazo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `prazo_id=${id}&enviar_revisao=nao`
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    alert(result.message || 'Prazo concluído!');
                    window.location.reload();
                } else {
                    alert('Erro: ' + result.message);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i> Concluir';
                    }
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao concluir');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Concluir';
                }
            });
        }
    });
}
</script>

<?php
$conteudo = ob_get_clean();

// Incluir modal de revisão
ob_start();
include 'includes/modal_revisao.php';
$modal = ob_get_clean();

require_once '../../includes/layout.php';
echo renderLayout($page_title, $conteudo, 'agenda');
echo $modal;
?>