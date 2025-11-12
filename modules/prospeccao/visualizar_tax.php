<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'] ?? $_SESSION['usuario_id'] ?? null;

$pode_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']);

// Obter ID
$id = $_GET['id'] ?? null;

// MÓDULO FIXO
$modulo_codigo = 'TAX';
if (!$id) {
    header('Location: tax.php');
    exit;
}

// Buscar prospecto
try {
    $sql = "SELECT p.*, 
                   u.nome as responsavel_nome,
                   uc.nome as criado_por_nome,
                   DATEDIFF(CURRENT_DATE, p.data_ultima_atualizacao) as dias_na_fase,
                   DATEDIFF(CURRENT_DATE, p.data_cadastro) as dias_total
            FROM prospeccoes p
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            LEFT JOIN usuarios uc ON p.criado_por = uc.id
            WHERE p.id = ? AND p.ativo = 1";
    
    $stmt = executeQuery($sql, [$id]);
    $prospecto = $stmt->fetch();
    
    if (!$prospecto) {
        header('Location: tax.php?erro=nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    header('Location: tax.php?erro=busca');
    exit;
}

// Buscar histórico DETALHADO (incluindo todas as mudanças)
try {
    $sql_hist = "SELECT 
                    h.id,
                    h.prospeccao_id,
                    h.fase_anterior,
                    h.fase_nova,
                    h.valor_informado,
                    h.observacao,
                    h.usuario_id,
                    h.data_movimento,
                    u.nome as usuario_nome,
                    'mudanca_fase' as tipo_historico
                 FROM prospeccoes_historico h
                 LEFT JOIN usuarios u ON h.usuario_id = u.id
                 WHERE h.prospeccao_id = ?
                 ORDER BY h.data_movimento DESC";
    
    $stmt_hist = executeQuery($sql_hist, [$id]);
    $historico_completo = $stmt_hist->fetchAll();
    
    error_log("DEBUG TAX - Histórico completo count: " . count($historico_completo) . " para prospecto ID: " . $id);
    
} catch (Exception $e) {
    error_log("Erro ao buscar histórico: " . $e->getMessage());
    $historico_completo = [];
}

// Buscar apenas mudanças de fase (para estatísticas)
try {
    $sql_fases = "SELECT h.*, u.nome as usuario_nome
                  FROM prospeccoes_historico h
                  LEFT JOIN usuarios u ON h.usuario_id = u.id
                  WHERE h.prospeccao_id = ?
                  ORDER BY h.data_movimento DESC";
    
    $stmt_fases = executeQuery($sql_fases, [$id]);
    $historico = $stmt_fases->fetchAll();
} catch (Exception $e) {
    $historico = [];
}

// Buscar interações
try {
    $sql_int = "SELECT i.*, u.nome as usuario_nome
                FROM prospeccoes_interacoes i
                LEFT JOIN usuarios u ON i.usuario_id = u.id
                WHERE i.prospeccao_id = ?
                ORDER BY i.data_interacao DESC
                LIMIT 20";
    
    $stmt_int = executeQuery($sql_int, [$id]);
    $interacoes = $stmt_int->fetchAll();
} catch (Exception $e) {
    error_log("Erro ao buscar interações: " . $e->getMessage());
    $interacoes = [];
}

// Processar nova interação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_interacao'])) {
    try {
        if (!$usuario_id) {
            throw new Exception("Usuário não identificado");
        }
        
        $tipo = $_POST['tipo'];
        $descricao = trim($_POST['descricao']);
        
        if (empty($descricao)) {
            throw new Exception("Descrição não pode ser vazia");
        }
        
        $pdo = getConnection();
        $sql_add = "INSERT INTO prospeccoes_interacoes (prospeccao_id, tipo, descricao, usuario_id)
                    VALUES (?, ?, ?, ?)";
        
        $stmt_add = $pdo->prepare($sql_add);
        $result = $stmt_add->execute([$id, $tipo, $descricao, $usuario_id]);
        
        if ($result) {
            header('Location: visualizar_tax.php?id=' . $id . '&interacao=sucesso');
            exit;
        } else {
            throw new Exception("Falha ao inserir interação");
        }
        
    } catch (Exception $e) {
        error_log("Erro ao adicionar interação: " . $e->getMessage());
        $erro_interacao = "Erro ao adicionar interação: " . $e->getMessage();
    }
}

ob_start();
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .view-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Header compacto */
    .view-header {
        background: white;
        border-radius: 12px;
        padding: 20px 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .view-header-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .view-header h1 {
        font-size: 24px;
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
    }

    .tipo-cliente-badge {
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        background: #f39c12;
        color: white;
    }

    .tipo-cliente-badge.pf {
        background: #3498db;
    }

    .view-header-actions {
        display: flex;
        gap: 10px;
    }

    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-primary {
        background: #667eea;
        color: white;
    }

    .btn-primary:hover {
        background: #5568d3;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: #e9ecef;
        color: #495057;
    }

    .btn-secondary:hover {
        background: #dee2e6;
    }

    /* Status badges */
    .status-bar {
        background: white;
        border-radius: 12px;
        padding: 15px 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .status-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 8px;
        background: #f8f9fa;
        font-size: 13px;
        font-weight: 600;
    }

    .status-item.fase {
        background: #e3f2fd;
        color: #1976d2;
    }

    .status-item.meio {
        background: #f3e5f5;
        color: #7b1fa2;
    }

    .status-item.tempo {
        background: #fff3e0;
        color: #e65100;
    }

    /* Cards de informação */
    .info-section {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #ecf0f1;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }

    .info-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .info-label {
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700;
        color: #7f8c8d;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 15px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .info-value.destaque {
        font-size: 18px;
        color: #27ae60;
    }

    .info-value.percentual {
        font-size: 20px;
        color: #f39c12;
        font-weight: 700;
    }

    /* Card de empresa (PJ) */
    .empresa-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        color: white;
    }

    .empresa-card .section-title {
        color: white;
        border-bottom-color: rgba(255,255,255,0.3);
    }

    .empresa-card .info-label {
        color: rgba(255,255,255,0.8);
    }

    .empresa-card .info-value {
        color: white;
    }

    /* Observações */
    .observacoes-box {
        background: #f8f9fa;
        border-left: 4px solid #667eea;
        padding: 15px 20px;
        border-radius: 8px;
        font-size: 14px;
        line-height: 1.6;
        color: #495057;
    }

    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 30px;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #e0e0e0;
    }

    .timeline-item {
        position: relative;
        margin-bottom: 25px;
    }

    .timeline-item::before {
        content: '';
        position: absolute;
        left: -26px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #667eea;
        border: 3px solid white;
        box-shadow: 0 0 0 2px #667eea;
    }

    .timeline-item.mudanca-fase::before {
        background: #27ae60;
        box-shadow: 0 0 0 2px #27ae60;
    }

    .timeline-item.interacao::before {
        background: #3498db;
        box-shadow: 0 0 0 2px #3498db;
    }

    .timeline-content {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
    }

    .timeline-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 10px;
    }

    .timeline-title {
        font-weight: 600;
        color: #2c3e50;
        font-size: 14px;
    }

    .timeline-date {
        font-size: 12px;
        color: #95a5a6;
    }

    .timeline-description {
        font-size: 13px;
        color: #7f8c8d;
        line-height: 1.5;
    }

    .alert {
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-left: 4px solid #28a745;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }

    /* Grid de conteúdo (histórico + sidebar) */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }

    /* Formulário de interação */
    .interacao-form {
        padding: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s;
    }

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

    /* Estatísticas mini */
    .stats-mini {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        padding: 20px;
    }

    .stat-mini {
        text-align: center;
        padding: 20px 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        color: white;
    }

    .stat-mini-value {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .stat-mini-label {
        font-size: 11px;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #95a5a6;
        font-size: 14px;
    }

    .empty-state::before {
        content: '📭';
        display: block;
        font-size: 48px;
        margin-bottom: 15px;
    }

    /* Badges de fase */
    .fase-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        margin: 0 3px;
    }

    .badge-prospeccao {
        background: #e3f2fd;
        color: #1976d2;
    }

    .badge-negociacao {
        background: #fff3e0;
        color: #e65100;
    }

    .badge-fechados {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .badge-perdidos {
        background: #ffebee;
        color: #c62828;
    }

    .badge-revisitar {
        background: #f3e5f5;
        color: #7b1fa2;
    }

    @media (max-width: 768px) {
        .view-header {
            flex-direction: column;
            gap: 15px;
        }

        .view-header-actions {
            width: 100%;
        }

        .btn {
            flex: 1;
            justify-content: center;
        }

        .status-bar {
            flex-direction: column;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .content-grid {
            grid-template-columns: 1fr;
        }

        .stats-mini {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="view-container">
    <?php if (isset($_GET['sucesso'])): ?>
        <div class="alert alert-success">
            ✅ Prospecto atualizado com sucesso!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['interacao'])): ?>
        <div class="alert alert-success">
            ✅ Interação adicionada com sucesso!
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="view-header">
        <div class="view-header-left">
            <h1><?= htmlspecialchars($prospecto['nome']) ?></h1>
            <span class="tipo-cliente-badge <?= strtolower($prospecto['tipo_cliente']) ?>">
                <?= $prospecto['tipo_cliente'] === 'PJ' ? '🏢 Pessoa Jurídica' : '👤 Pessoa Física' ?>
            </span>
        </div>
        <div class="view-header-actions">
            <?php if ($pode_editar): ?>
                <a href="editar_tax.php?id=<?= $id ?>" class="btn btn-primary">
                    ✏️ Editar
                </a>
            <?php endif; ?>
            <a href="tax.php" class="btn btn-secondary">
                ← Voltar
            </a>
        </div>
    </div>

    <!-- Status Bar -->
    <div class="status-bar">
        <div class="status-item fase">
            📍 <?= $prospecto['fase'] ?>
        </div>
        <div class="status-item meio">
            🌐 <?= $prospecto['meio'] ?>
        </div>
        <div class="status-item tempo">
            📅 Cadastrado há <?= $prospecto['dias_total'] ?> <?= $prospecto['dias_total'] == 1 ? 'dia' : 'dias' ?>
        </div>
        <div class="status-item tempo">
            ⏱️ Na fase há <?= $prospecto['dias_na_fase'] ?> <?= $prospecto['dias_na_fase'] == 1 ? 'dia' : 'dias' ?>
        </div>
    </div>

    <!-- Informações de Visita/Revisita (se aplicável) -->
    <?php if ($prospecto['fase'] === 'Visita Semanal' || $prospecto['fase'] === 'Revisitar'): ?>
    <div class="info-section" style="background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%); color: white; margin-top: 20px; border: none;">
        <div class="section-title" style="color: white; border-bottom-color: rgba(255,255,255,0.3);">
            <?php if ($prospecto['fase'] === 'Visita Semanal'): ?>
                📅 INFORMAÇÕES DA VISITA SEMANAL
            <?php else: ?>
                🔄 INFORMAÇÕES DA REVISITA
            <?php endif; ?>
        </div>
        <div class="info-grid">
            <?php if ($prospecto['fase'] === 'Visita Semanal'): ?>
                <?php if ($prospecto['data_primeira_visita']): ?>
                <div class="info-field">
                    <div class="info-label" style="color: rgba(255,255,255,0.9);">📆 Data da Primeira Visita</div>
                    <div class="info-value" style="color: white; font-weight: bold; font-size: 16px;">
                        <?php 
                        $data = new DateTime($prospecto['data_primeira_visita']);
                        $dias_semana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                        echo $data->format('d/m/Y') . ' (' . $dias_semana[$data->format('w')] . ')';
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($prospecto['periodicidade']): ?>
                <div class="info-field">
                    <div class="info-label" style="color: rgba(255,255,255,0.9);">🔁 Periodicidade</div>
                    <div class="info-value" style="color: white; font-weight: bold; font-size: 16px;">
                        <?php
                        $periodicidades = [
                            'semanal' => 'Semanal (7 dias)',
                            'quinzenal' => 'Quinzenal (15 dias)',
                            'mensal' => 'Mensal (30 dias)'
                        ];
                        echo $periodicidades[$prospecto['periodicidade']] ?? ucfirst($prospecto['periodicidade']);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($prospecto['data_primeira_visita'] && $prospecto['periodicidade']): ?>
                <div class="info-field" style="grid-column: 1 / -1;">
                    <div class="info-label" style="color: rgba(255,255,255,0.9);">📅 Próximas 3 Visitas</div>
                    <div class="info-value" style="color: white; font-size: 15px;">
                        <?php
                        $data_base = new DateTime($prospecto['data_primeira_visita']);
                        $dias_intervalo = [
                            'semanal' => 7,
                            'quinzenal' => 15,
                            'mensal' => 30
                        ];
                        $intervalo = $dias_intervalo[$prospecto['periodicidade']] ?? 7;
                        
                        $proximas = [];
                        for ($i = 1; $i <= 3; $i++) {
                            $proxima = clone $data_base;
                            $proxima->modify("+".($intervalo * $i)." days");
                            $dia_semana = $dias_semana[$proxima->format('w')];
                            $proximas[] = $proxima->format('d/m/Y') . ' (' . $dia_semana . ')';
                        }
                        echo implode(' • ', $proximas);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <?php if ($prospecto['data_revisita']): ?>
                <div class="info-field" style="grid-column: 1 / -1;">
                    <div class="info-label" style="color: rgba(255,255,255,0.9);">📆 Data Agendada para Revisita</div>
                    <div class="info-value" style="color: white; font-weight: bold; font-size: 16px;">
                        <?php 
                        $data = new DateTime($prospecto['data_revisita']);
                        $dias_semana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                        echo $data->format('d/m/Y') . ' (' . $dias_semana[$data->format('w')] . ')';
                        
                        $hoje = new DateTime();
                        $diff = $hoje->diff($data);
                        if ($data > $hoje) {
                            echo ' <span style="background: rgba(255,255,255,0.25); padding: 4px 12px; border-radius: 6px; margin-left: 10px;">Em ' . $diff->days . ' ' . ($diff->days == 1 ? 'dia' : 'dias') . '</span>';
                        } else if ($data < $hoje) {
                            echo ' <span style="background: rgba(231,76,60,0.4); padding: 4px 12px; border-radius: 6px; margin-left: 10px;">⚠️ Atrasado ' . $diff->days . ' ' . ($diff->days == 1 ? 'dia' : 'dias') . '</span>';
                        } else {
                            echo ' <span style="background: rgba(46,204,113,0.4); padding: 4px 12px; border-radius: 6px; margin-left: 10px;">✅ HOJE</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Informações Gerais -->
    <div class="info-section">
        <div class="section-title">
            📋 INFORMAÇÕES GERAIS
        </div>
        <div class="info-grid">
            <div class="info-field">
                <div class="info-label">📱 Telefone</div>
                <div class="info-value"><?= htmlspecialchars($prospecto['telefone']) ?></div>
            </div>

            <div class="info-field">
                <div class="info-label">📍 Cidade</div>
                <div class="info-value"><?= htmlspecialchars($prospecto['cidade']) ?></div>
            </div>

            <div class="info-field">
                <div class="info-label">👤 Responsável</div>
                <div class="info-value"><?= htmlspecialchars($prospecto['responsavel_nome']) ?></div>
            </div>

            <?php if ($prospecto['valor_proposta']): ?>
                <div class="info-field">
                    <div class="info-label">💰 Valor Proposta</div>
                    <div class="info-value destaque">R$ <?= number_format($prospecto['valor_proposta'], 2, ',', '.') ?></div>
                </div>
            <?php endif; ?>


            <?php if (isset($prospecto['percentual_exito']) && $prospecto['percentual_exito'] > 0): ?>
                <div class="info-field">
                    <div class="info-label">📊 Honorários de Êxito</div>
                    <div class="info-value percentual">
                        <?= number_format($prospecto['percentual_exito'], 1, ',', '.') ?>%
                        <?php 
                        $valor_base = $prospecto['valor_proposta'] ?? 0;
                        if ($valor_base > 0):
                            $valor_honorarios = ($valor_base * $prospecto['percentual_exito']) / 100;
                        ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="info-field">
                <div class="info-label">📅 Cadastrado por</div>
                <div class="info-value"><?= htmlspecialchars($prospecto['criado_por_nome']) ?> em <?= date('d/m/Y H:i', strtotime($prospecto['data_cadastro'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Dados da Empresa (somente se for PJ) -->
    <?php if ($prospecto['tipo_cliente'] === 'PJ' && ($prospecto['responsavel_contato'] || $prospecto['cargo_responsavel'] || $prospecto['proprietario_principal'] || $prospecto['segmento_atuacao'])): ?>
        <div class="empresa-card">
            <div class="section-title">
                🏢 DADOS DA EMPRESA
            </div>
            <div class="info-grid">
                <?php if ($prospecto['responsavel_contato']): ?>
                    <div class="info-field">
                        <div class="info-label">👤 Responsável pelo Contato</div>
                        <div class="info-value"><?= htmlspecialchars($prospecto['responsavel_contato']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($prospecto['cargo_responsavel']): ?>
                    <div class="info-field">
                        <div class="info-label">💼 Cargo</div>
                        <div class="info-value"><?= htmlspecialchars($prospecto['cargo_responsavel']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($prospecto['proprietario_principal']): ?>
                    <div class="info-field">
                        <div class="info-label">👔 Proprietário Principal</div>
                        <div class="info-value"><?= htmlspecialchars($prospecto['proprietario_principal']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($prospecto['segmento_atuacao']): ?>
                    <div class="info-field">
                        <div class="info-label">🏭 Segmento de Atuação</div>
                        <div class="info-value"><?= htmlspecialchars($prospecto['segmento_atuacao']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Observações -->
    <?php if ($prospecto['observacoes']): ?>
        <div class="info-section">
            <div class="section-title">
                📝 OBSERVAÇÕES
            </div>
            <div class="observacoes-box">
                <?= nl2br(htmlspecialchars($prospecto['observacoes'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Histórico -->
    <?php if (!empty($historico_completo)): ?>
        <div class="info-section">
            <div class="section-title">
                📜 HISTÓRICO E INTERAÇÕES
            </div>
            <div class="timeline">
                <?php foreach ($historico_completo as $item): ?>
                    <div class="timeline-item <?= $item['tipo_historico'] ?>">
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <div class="timeline-title">
                                    <?php if ($item['tipo_historico'] === 'mudanca_fase'): ?>
                                        🔄 Mudança de Fase
                                        <?php if ($item['fase_anterior']): ?>
                                            : <?= $item['fase_anterior'] ?> → <?= $item['fase_nova'] ?>
                                        <?php else: ?>
                                            : <?= $item['fase_nova'] ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        💬 <?= htmlspecialchars($item['fase_nova']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-date">
                                    <?= date('d/m/Y H:i', strtotime($item['data_movimento'])) ?>
                                </div>
                            </div>
                            <div class="timeline-description">
                                <strong><?= htmlspecialchars($item['usuario_nome']) ?>:</strong>
                                <?= nl2br(htmlspecialchars($item['observacao'])) ?>
                                <?php if ($item['valor_informado']): ?>
                                    <br><strong>Valor:</strong> R$ <?= number_format($item['valor_informado'], 2, ',', '.') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Grid de Conteúdo: Interações + Estatísticas -->
    <div class="content-grid">
        <!-- Coluna Esquerda: Formulário de Interações -->
        <div>
            <?php if ($pode_editar): ?>
                <div class="info-section">
                    <div class="section-title">
                        ➕ ADICIONAR INTERAÇÃO
                    </div>

                    <?php if (isset($erro_interacao)): ?>
                        <div class="alert alert-error">
                            ⚠️ <?= htmlspecialchars($erro_interacao) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="interacao-form">
                        <div class="form-group">
                            <label>Tipo de Interação</label>
                            <select name="tipo" required>
                                <option value="Ligação">📞 Ligação</option>
                                <option value="Email">📧 Email</option>
                                <option value="WhatsApp">💬 WhatsApp</option>
                                <option value="Reunião">🤝 Reunião</option>
                                <option value="Observação">📝 Observação</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Descrição *</label>
                            <textarea name="descricao" required placeholder="Descreva a interação com detalhes..."></textarea>
                        </div>

                        <button type="submit" name="adicionar_interacao" class="btn btn-primary" style="width: 100%;">
                            ➕ Adicionar Interação
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Coluna Direita: Estatísticas -->
        <div>
            <div class="info-section">
                <div class="section-title">
                    📈 ESTATÍSTICAS
                </div>

                <div class="stats-mini">
                    <div class="stat-mini">
                        <div class="stat-mini-value">
                            <?= count(array_filter($historico, function($h) { 
                                return $h['fase_anterior'] !== null; 
                            })) ?>
                        </div>
                        <div class="stat-mini-label">Mudanças de Fase</div>
                    </div>

                    <div class="stat-mini">
                        <div class="stat-mini-value"><?= count($interacoes) ?></div>
                        <div class="stat-mini-label">Interações</div>
                    </div>

                    <div class="stat-mini">
                        <div class="stat-mini-value"><?= $prospecto['dias_total'] ?></div>
                        <div class="stat-mini-label">Dias no Sistema</div>
                    </div>

                    <div class="stat-mini">
                        <div class="stat-mini-value"><?= $prospecto['dias_na_fase'] ?></div>
                        <div class="stat-mini-label">Dias na Fase</div>
                    </div>
                </div>

                <?php if ($prospecto['dias_na_fase'] >= 7): ?>
                    <div style="margin: 20px; padding: 15px; background: #fff3e0; border-radius: 8px; border-left: 4px solid #f57c00;">
                        <strong style="color: #f57c00; display: block; margin-bottom: 8px;">⚠️ Atenção!</strong>
                        <p style="margin: 0; color: #7f8c8d; font-size: 13px; line-height: 1.5;">
                            Este prospecto está há <strong><?= $prospecto['dias_na_fase'] ?> dias</strong> na fase <strong><?= $prospecto['fase'] ?></strong>. 
                            Considere fazer um follow-up.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Visualizar Prospecto - TAX', $conteudo, 'prospeccao');
?>