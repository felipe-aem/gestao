<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php'; 

$usuario_logado = Auth::user();
$session_token = $_SESSION['token'] ?? ''; // Token da sessão para passar aos popups

// Buscar TODOS os núcleos ativos
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

// Buscar TODOS os usuários ativos para o select de responsável
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Definir tipos de processo por núcleo
$tipos_por_nucleo = [];
foreach ($nucleos as $nucleo) {
    switch ($nucleo['nome']) {
        case 'Família':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Alimentos',
                'Cumprimento de Sentença – Prisão',
                'Cumprimento de Sentença – Penhora',
                'Execução de Alimentos - Prisão',
                'Execução de Alimentos - Penhora',
                'Inventário',
                'Medida Protetiva',
                'Pedidos diversos – completo',
                'Divórcio',
                'Dissolução',
                'Alimentos e Guarda',
                'Negatória de Paternidade'
            ];
            break;
        case 'Criminal':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Habeas Corpus',
                'Ação Penal',
                'Recurso Criminal',
                'Execução Penal'
            ];
            break;
        case 'Trabalhista':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Reclamação Trabalhista',
                'Recurso Ordinário',
                'Execução Trabalhista',
                'Mandado de Segurança'
            ];
            break;
        case 'Bancário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação Revisional',
                'Busca e Apreensão',
                'Execução',
                'Consignação em Pagamento'
            ];
            break;
        case 'Previdenciário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Aposentadoria',
                'Auxílio-doença',
                'Pensão por morte',
                'Revisão de benefício'
            ];
            break;
        case 'Cobrança':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação de Cobrança',
                'Ação de Cobrança de Aluguel',
                'Ação Revisional de Aluguel',
                'Usucapião',
                'Ação de Reintegração de Posse',
                'Ação Demarcatória',
                'Ação de Adjudicação Compulsória',
                'Rescisão de Contrato de Locação',
                'Renovatória de Locação'
            ];
            break;
            
        case 'Criminal Econômico':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Dissolução de Sociedade',
                'Recuperação Judicial',
                'Falência',
                'Ação de Cobrança Empresarial',
                'Arbitragem',
                'Contrato Social',
                'Medida Cautelar',
                'Ação Declaratória'
            ];
            break;
            
        case 'Sucessões':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Mandado de Segurança Tributário',
                'Ação Anulatória de Débito Fiscal',
                'Embargos à Execução Fiscal',
                'Ação Declaratória de Inexistência de Relação Jurídico-Tributária',
                'Impugnação de Lançamento',
                'Compensação Tributária',
                'Recurso Administrativo'
            ];
            break;
            
        case 'Empresarial':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Indenização por Danos Morais',
                'Indenização por Danos Materiais',
                'Revisão de Contrato',
                'Cancelamento de Contrato',
                'Devolução de Valores',
                'Defeito de Produto',
                'Vício de Serviço',
                'Cobrança Indevida'
            ];
        default:
            $tipos_por_nucleo[$nucleo['id']] = ['Outros'];
            break;
    }
}

// Buscar clientes para autocomplete
$sql = "SELECT id, nome, cpf_cnpj FROM clientes WHERE ativo = 1 ORDER BY nome LIMIT 200";
$stmt = executeQuery($sql);
$clientes = $stmt->fetchAll();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

// Situações processuais
$situacoes_processuais = [
    'Em Andamento',
    'Transitado',
    'Para Arquivamento',
    'Em Processo de Renúncia',
    'Baixado',
    'Renunciado',
    'Em Grau Recursal'
];

ob_start();
?>
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
        background-attachment: fixed;
        min-height: 100vh;
    }
    
    .content {
        flex: 1;
        padding: 30px;
    }
    
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
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .btn-voltar {
        padding: 12px 24px;
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .btn-voltar:hover {
        transform: translateY(-2px);
    }
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        max-width: 1200px;
        margin: 0 auto;
    }
            
    .form-section {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }
    
    .form-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .form-section h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    @media (min-width: 1200px) {
        .form-grid {
            grid-template-columns: 1fr 1fr 1fr;
        }
    }

    @media (min-width: 768px) and (max-width: 1199px) {
        .form-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #1a1a1a;
        box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .btn-submit {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .form-group {
		margin-bottom: 20px;
		position: relative;
	}

	.autocomplete-suggestions {
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
		z-index: 9999;
		display: none;
		box-shadow: 0 4px 10px rgba(0,0,0,0.1);
		margin-top: -1px;
	}

	.autocomplete-suggestion {
		padding: 12px;
		cursor: pointer;
		border-bottom: 1px solid rgba(0,0,0,0.05);
	}

	.autocomplete-suggestion:hover,
	.autocomplete-suggestion.active {
		background: rgba(26, 26, 26, 0.05);
	}

	.autocomplete-suggestion:last-child {
		border-bottom: none;
	}
    
    .autocomplete-suggestion {
        padding: 12px;
        cursor: pointer;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .autocomplete-suggestion:hover {
        background: rgba(26, 26, 26, 0.05);
    }
    
    .autocomplete-suggestion:last-child {
        border-bottom: none;
    }

    /* Estilos para Partes do Processo */
    .partes-container {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        background: rgba(0,0,0,0.02);
    }

    .parte-item {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        position: relative;
    }

    .parte-item:last-child {
        margin-bottom: 0;
    }

    .parte-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e9ecef;
    }

    .parte-header h4 {
        color: #1a1a1a;
        font-size: 16px;
        font-weight: 600;
    }

    .btn-remover-parte {
        background: #dc3545;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 5px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-remover-parte:hover {
        background: #c82333;
        transform: scale(1.05);
    }

    .btn-adicionar-parte {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        width: 100%;
        margin-top: 15px;
    }

    .btn-adicionar-parte:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        border-radius: 8px;
        margin-top: 10px;
    }

    .checkbox-group input[type="checkbox"] {
        width: auto !important;
        margin: 0;
    }

    .checkbox-group label {
        margin: 0 !important;
        font-weight: 600 !important;
        color: #155724;
    }
    
    .btn-add-cliente {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        flex-shrink: 0;
    }
    
    .btn-add-cliente:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
    }

    @media (max-width: 768px) {
        .cliente-options {
            flex-direction: column;
        }
    }
    
    /* ============================================================================
       CSS MELHORADO - Componente de Vinculação de Processos
       
       INSTRUÇÕES: 
       Adicione este CSS dentro do <style> no novo.php, logo após os estilos existentes
       ============================================================================ */
    
    /* Container do campo de vinculação */
    .card {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Animação de entrada */
    #area-busca-pai,
    #processo-pai-selecionado {
        animation: fadeInUp 0.4s ease-out;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Campo de busca estilizado */
    #busca-processo-pai-novo {
        width: 100%;
        padding: 14px 18px;
        font-size: 15px;
        border: 2px solid #dee2e6;
        border-radius: 10px;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }
    
    #busca-processo-pai-novo:hover {
        border-color: #b8bec4;
        background: #ffffff;
    }
    
    #busca-processo-pai-novo:focus {
        outline: none;
        border-color: #007bff;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.1);
    }
    
    #busca-processo-pai-novo::placeholder {
        color: #6c757d;
    }
    
    /* Texto informativo */
    .form-text {
        display: block;
        margin-top: 6px;
        font-size: 13px;
        color: #6c757d;
    }
    
    /* =============================================================================
   CSS AJUSTE FINO - Últimos Detalhes
   
   ADICIONE ESTE CSS APÓS O CSS EXISTENTE (substituindo as regras antigas)
   ============================================================================= */

/* ==================== LISTA DE RESULTADOS - AJUSTE FINO ==================== */

    /* Lista principal - padding interno e espaçamento */
    #resultados-busca-pai .list-group {
        margin: 0;
        padding: 0;
    }
    
    /* Cada item da lista - ajustes finos */
    #resultados-busca-pai .list-group-item {
        padding: 16px 20px !important;
        border: none !important;
        border-bottom: 1px solid #f0f0f0 !important;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        background: white;
        display: block !important;
    }
    
    /* Barra lateral esquerda - mais proeminente */
    #resultados-busca-pai .list-group-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 0;
        background: linear-gradient(180deg, #007bff 0%, #0056b3 100%);
        transition: width 0.25s ease;
        z-index: 1;
    }
    
    #resultados-busca-pai .list-group-item:hover::before {
        width: 4px;
    }
    
    /* Hover state - mais suave */
    #resultados-busca-pai .list-group-item:hover {
        background: linear-gradient(90deg, #f0f7ff 0%, #ffffff 100%) !important;
        padding-left: 24px !important;
        transform: translateX(0);
        box-shadow: inset 0 0 0 1px rgba(0,123,255,0.08);
    }
    
    #resultados-busca-pai .list-group-item:active {
        background: #e3f2fd !important;
    }
    
    /* ==================== CONTEÚDO DO ITEM - ORGANIZAÇÃO ==================== */
    
    /* Wrapper do conteúdo */
    #resultados-busca-pai .list-group-item > div {
        position: relative;
        z-index: 2;
    }
    
    /* Número do processo - destaque */
    #resultados-busca-pai .list-group-item h6 {
        font-size: 15px !important;
        font-weight: 700 !important;
        color: #2c3e50 !important;
        margin: 0 0 6px 0 !important;
        letter-spacing: 0.2px;
        line-height: 1.4;
        transition: color 0.2s ease;
    }
    
    #resultados-busca-pai .list-group-item:hover h6 {
        color: #007bff !important;
    }
    
    /* Nome do cliente */
    #resultados-busca-pai .list-group-item p {
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #495057 !important;
        margin: 0 0 8px 0 !important;
        line-height: 1.5;
    }
    
    /* ==================== BADGES - MELHORIAS ==================== */
    
    /* Container dos badges - melhor alinhamento */
    #resultados-busca-pai .list-group-item > div > div {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin-top: 2px;
    }
    
    /* Badges individuais */
    .processo-info-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 11px !important;
        font-size: 11.5px !important;
        font-weight: 600 !important;
        border-radius: 16px !important;
        margin: 0 !important;
        transition: all 0.2s ease;
        white-space: nowrap;
        line-height: 1.2;
    }
    
    /* Badge secundário (situação) */
    .badge-secondary {
        background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%) !important;
        color: white !important;
        box-shadow: 0 1px 3px rgba(108, 117, 125, 0.25);
    }
    
    #resultados-busca-pai .list-group-item:hover .badge-secondary {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(108, 117, 125, 0.35);
    }
    
    /* Badge info (núcleo) */
    .badge-info {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%) !important;
        color: white !important;
        box-shadow: 0 1px 3px rgba(23, 162, 184, 0.25);
    }
    
    #resultados-busca-pai .list-group-item:hover .badge-info {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(23, 162, 184, 0.35);
    }
    
    /* ==================== TEXTO RESPONSÁVEL ==================== */
    
    /* Separador e texto do responsável */
    #resultados-busca-pai .list-group-item small {
        font-size: 12.5px !important;
        color: #6c757d !important;
        font-weight: 500;
        margin-left: 2px;
    }
    
    #resultados-busca-pai .list-group-item small::before {
        content: '•';
        margin: 0 6px;
        color: #dee2e6;
    }
    
    /* ==================== MELHORIAS NO LAYOUT ==================== */
    
    /* Garantir que o layout seja consistente */
    #resultados-busca-pai .d-flex {
        display: flex;
        flex-direction: column;
        gap: 0;
    }
    
    #resultados-busca-pai .w-100 {
        width: 100%;
    }
    
    #resultados-busca-pai .flex-grow-1 {
        flex-grow: 1;
    }
    
    /* ==================== ANIMAÇÃO DE ENTRADA ==================== */
    
    /* Animação mais suave */
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    #resultados-busca-pai {
        animation: slideDown 0.25s ease-out;
    }
    
    /* ==================== SCROLLBAR - AJUSTE ==================== */
    
    #resultados-busca-pai::-webkit-scrollbar {
        width: 8px;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-track {
        background: #f8f9fa;
        border-radius: 0 12px 12px 0;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #007bff 0%, #0056b3 100%);
        border-radius: 8px;
        border: 2px solid #f8f9fa;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, #0056b3 0%, #004085 100%);
    }
    
    /* ==================== RESPONSIVIDADE AJUSTADA ==================== */
    
    @media (max-width: 768px) {
        #resultados-busca-pai .list-group-item {
            padding: 14px 16px !important;
        }
        
        #resultados-busca-pai .list-group-item:hover {
            padding-left: 20px !important;
        }
        
        #resultados-busca-pai .list-group-item h6 {
            font-size: 14px !important;
        }
        
        #resultados-busca-pai .list-group-item p {
            font-size: 13px !important;
        }
        
        .processo-info-badge {
            padding: 3px 9px !important;
            font-size: 10.5px !important;
        }
    }
    
    /* ==================== ESTADO SEM RESULTADOS ==================== */
    
    #resultados-busca-pai .text-muted {
        cursor: default !important;
        padding: 28px 20px !important;
        text-align: center;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
        color: #6c757d !important;
        font-size: 13.5px;
        font-weight: 500;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        margin: 8px;
    }
    
    #resultados-busca-pai .text-muted i {
        display: inline-block;
        margin-right: 6px;
        color: #007bff;
        font-size: 15px;
    }
    
    #resultados-busca-pai .text-muted:hover {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
        padding: 28px 20px !important;
        transform: none !important;
    }
    
    /* ==================== FIM DOS AJUSTES ==================== */
</style>

<?php include '../../modules/clientes/modal_cadastro_cliente_rapido.php'; ?>

<div class="page-header">
    <h2>Novo Processo</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<div class="content">
    <?php if (!empty($erro)): ?>
    <div class="alert alert-danger">
        <?= $erro ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($sucesso)): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($sucesso) ?>
    </div>
    <?php endif; ?>
    
    <div class="form-container">
        <form action="process_novo.php" method="POST" id="processoForm">
            <!-- Seção: Dados Básicos do Processo -->
            <div class="form-section">
                <h3>📋 Dados Básicos do Processo</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nucleo_id">Núcleo *</label>
                        <select name="nucleo_id" id="nucleo_id" required onchange="atualizarTiposProcesso()">
                            <option value="">Selecione o núcleo...</option>
                            <?php foreach ($nucleos as $nucleo): ?>
                                <option value="<?= $nucleo['id'] ?>">
                                    <?= htmlspecialchars($nucleo['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="numero_processo">Número do Processo *</label>
                        <input type="text" id="numero_processo" name="numero_processo" required
                               placeholder="0000000-00.0000.0.00.0000">
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_processo">Tipo de Processo *</label>
                        <select id="tipo_processo" name="tipo_processo" required>
                            <option value="">Primeiro selecione o núcleo</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="situacao_processual">Situação Processual *</label>
                        <select id="situacao_processual" name="situacao_processual" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($situacoes_processuais as $situacao): ?>
                            <option value="<?= $situacao ?>" <?= $situacao === 'Em Andamento' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($situacao) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="comarca">Comarca</label>
                        <input type="text" id="comarca" name="comarca" 
                               placeholder="Ex: Comarca de São Paulo">
                    </div>
                    
                    <div class="form-group">
                        <label for="valor_causa">Valor da Causa</label>
                        <input type="text" id="valor_causa" name="valor_causa" 
                               class="money-input" placeholder="0,00">
                    </div>
                    
                    <div class="form-group">
                        <label for="responsavel_id">Responsável pelo Processo *</label>
                        <select name="responsavel_id" id="responsavel_id" required>
                            <option value="">Selecione o responsável...</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= $usuario['id'] ?>">
                                    <?= htmlspecialchars($usuario['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="fase_atual">Fase Atual do Processo</label>
                        <input type="text" id="fase_atual" name="fase_atual" 
                               placeholder="Ex: Aguardando citação, Em instrução...">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <i class="fas fa-link"></i> Este processo é derivado de outro?
                            <small class="text-muted">(Opcional)</small>
                        </label>
                        
                        <div class="card">
                            <div class="card-body">
                                <!-- Estado inicial: sem processo vinculado -->
                                <div id="area-busca-pai">
                                    <!-- Campo de busca -->
                                    <div class="form-group">
                                        <input type="text" 
                                               class="form-control" 
                                               id="busca-processo-pai-novo"
                                               placeholder="Digite o número do processo ou nome do cliente..."
                                               autocomplete="off">
                                        <small class="form-text text-muted">
                                            Digite pelo menos 2 caracteres para buscar
                                        </small>
                                    </div>
                                    
                                    <!-- Resultados da busca -->
                                    <div id="resultados-busca-pai" 
                                         class="list-group" 
                                         style="display: none; max-height: 250px; overflow-y: auto;">
                                    </div>
                                </div>
                                
                                <!-- Estado: processo vinculado -->
                                <div id="processo-pai-selecionado" style="display: none;">
                                    <!-- Campo hidden para enviar no form -->
                                    <input type="hidden" id="processo_pai_id" name="processo_pai_id">
                                    <input type="hidden" id="tipo_vinculo" name="tipo_vinculo">
                                    <input type="hidden" id="descricao_vinculo" name="descricao_vinculo">
                                    
                                    <div class="alert alert-success mb-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <strong><i class="fas fa-check-circle"></i> Processo de Origem Vinculado:</strong>
                                                <div class="mt-2">
                                                    <h6 class="mb-1" id="pai-numero-processo"></h6>
                                                    <p class="mb-1" id="pai-cliente"></p>
                                                    <small class="text-muted" id="pai-info"></small>
                                                </div>
                                            </div>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary" 
                                                    onclick="removerProcessoPaiSelecionado()">
                                                <i class="fas fa-times"></i> Remover
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Tipo de relacionamento -->
                                    <div class="form-group">
                                        <label>
                                            <i class="fas fa-tag"></i> Tipo de Relacionamento *
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="tipo_relacionamento_novo"
                                               placeholder="Ex: Agravo de Instrumento, Recurso de Apelação..."
                                               list="tipos-relacionamento-sugeridos"
                                               required>
                                        <datalist id="tipos-relacionamento-sugeridos">
                                            <option value="Agravo de Instrumento">
                                            <option value="Recurso de Apelação">
                                            <option value="Recurso Especial">
                                            <option value="Recurso Extraordinário">
                                            <option value="Embargos de Declaração">
                                            <option value="Embargos Infringentes">
                                            <option value="Recurso Ordinário">
                                            <option value="Cumprimento de Sentença">
                                            <option value="Execução de Título">
                                            <option value="Impugnação ao Cumprimento">
                                            <option value="Incidente de Desconsideração">
                                            <option value="Conflito de Competência">
                                            <option value="Processo Conexo">
                                            <option value="Ação Cautelar">
                                            <option value="Medida Cautelar">
                                        </datalist>
                                        <small class="form-text text-muted">
                                            Escolha um tipo sugerido ou digite um personalizado
                                        </small>
                                    </div>
                                    
                                    <!-- Descrição (opcional) -->
                                    <div class="form-group">
                                        <label>
                                            <i class="fas fa-comment"></i> Descrição do Vínculo (opcional)
                                        </label>
                                        <textarea class="form-control" 
                                                  id="descricao_relacionamento_novo"
                                                  rows="2"
                                                  placeholder="Ex: Agravo contra decisão que indeferiu tutela antecipada..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    /**
                     * JavaScript para busca e vinculação de processo pai no cadastro
                     */
                    
                    let processosPaiTimeout = null;
                    let processoPaiSelecionadoObj = null;
                    
                    // Busca de processos (autocomplete)
                    document.getElementById('busca-processo-pai-novo')?.addEventListener('input', function() {
                        const termo = this.value.trim();
                        
                        clearTimeout(processosPaiTimeout);
                        
                        if (termo.length < 2) {
                            document.getElementById('resultados-busca-pai').style.display = 'none';
                            return;
                        }
                        
                        processosPaiTimeout = setTimeout(function() {
                            buscarProcessosPai(termo);
                        }, 300);
                    });
                    
                    /**
                     * Buscar processos para vincular
                     */
                    function buscarProcessosPai(termo) {
                        fetch(`api_relacionamentos.php?acao=buscar_processos&termo=${encodeURIComponent(termo)}&limite=10`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    exibirResultadosProcessosPai(data.processos);
                                } else {
                                    console.error('Erro ao buscar processos:', data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Erro na requisição:', error);
                            });
                    }
                    
                    /**
                     * Exibir resultados da busca
                     */
                    function exibirResultadosProcessosPai(processos) {
                        const container = document.getElementById('resultados-busca-pai');
                        
                        if (!processos || processos.length === 0) {
                            container.innerHTML = `
                                <div class="list-group-item text-muted">
                                    <i class="fas fa-info-circle"></i> Nenhum processo encontrado
                                </div>
                            `;
                            container.style.display = 'block';
                            return;
                        }
                        
                        let html = '';
                        processos.forEach(p => {
                            html += `
                                <a href="javascript:void(0)" 
                                   class="list-group-item list-group-item-action"
                                   onclick='selecionarProcessoPai(${JSON.stringify(p)})'>
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">${escapeHtml(p.numero_processo)}</h6>
                                            <p class="mb-1">${escapeHtml(p.cliente_nome)}</p>
                                            <div>
                                                <span class="badge badge-secondary processo-info-badge">
                                                    ${escapeHtml(p.situacao_processual)}
                                                </span>
                                                ${p.nucleo_nome ? `
                                                    <span class="badge badge-info processo-info-badge">
                                                        ${escapeHtml(p.nucleo_nome)}
                                                    </span>
                                                ` : ''}
                                                ${p.responsavel_nome ? `
                                                    <small class="text-muted">• ${escapeHtml(p.responsavel_nome)}</small>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            `;
                        });
                        
                        container.innerHTML = html;
                        container.style.display = 'block';
                    }
                    
                    /**
                     * Selecionar processo pai
                     */
                    function selecionarProcessoPai(processo) {
                        processoPaiSelecionadoObj = processo;
                        
                        // Preencher campos hidden
                        document.getElementById('processo_pai_id').value = processo.id;
                        
                        // Preencher informações visuais
                        document.getElementById('pai-numero-processo').textContent = processo.numero_processo;
                        document.getElementById('pai-cliente').textContent = processo.cliente_nome;
                        
                        let infoText = '';
                        if (processo.nucleo_nome) infoText += processo.nucleo_nome;
                        if (processo.situacao_processual) {
                            infoText += (infoText ? ' • ' : '') + processo.situacao_processual;
                        }
                        if (processo.responsavel_nome) {
                            infoText += (infoText ? ' • ' : '') + processo.responsavel_nome;
                        }
                        document.getElementById('pai-info').textContent = infoText;
                        
                        // Alternar views
                        document.getElementById('area-busca-pai').style.display = 'none';
                        document.getElementById('processo-pai-selecionado').style.display = 'block';
                        document.getElementById('resultados-busca-pai').style.display = 'none';
                        
                        // Focar no campo de tipo
                        document.getElementById('tipo_relacionamento_novo').focus();
                    }
                    
                    /**
                     * Remover processo pai selecionado
                     */
                    function removerProcessoPaiSelecionado() {
                        processoPaiSelecionadoObj = null;
                        
                        // Limpar campos
                        document.getElementById('processo_pai_id').value = '';
                        document.getElementById('tipo_relacionamento_novo').value = '';
                        document.getElementById('descricao_relacionamento_novo').value = '';
                        document.getElementById('busca-processo-pai-novo').value = '';
                        
                        // Alternar views
                        document.getElementById('area-busca-pai').style.display = 'block';
                        document.getElementById('processo-pai-selecionado').style.display = 'none';
                        
                        // Focar no campo de busca
                        document.getElementById('busca-processo-pai-novo').focus();
                    }
                    
                    /**
                     * Validação antes de submeter o form
                     */
                    document.querySelector('form')?.addEventListener('submit', function(e) {
                        const processoPaiId = document.getElementById('processo_pai_id')?.value;
                        const tipoRelacionamento = document.getElementById('tipo_relacionamento_novo')?.value;
                        const descricaoRelacionamento = document.getElementById('descricao_relacionamento_novo')?.value;
                        
                        // Se tem processo pai selecionado, validar tipo
                        if (processoPaiId && !tipoRelacionamento) {
                            e.preventDefault();
                            alert('Por favor, informe o tipo de relacionamento com o processo de origem.');
                            document.getElementById('tipo_relacionamento_novo').focus();
                            return false;
                        }
                        
                        // Copiar valores para os campos hidden (para garantir que sejam enviados)
                        if (processoPaiId && tipoRelacionamento) {
                            document.getElementById('tipo_vinculo').value = tipoRelacionamento;
                            document.getElementById('descricao_vinculo').value = descricaoRelacionamento || '';
                        }
                    });
                    
                    /**
                     * Escape HTML (segurança)
                     */
                    function escapeHtml(text) {
                        if (!text) return '';
                        const div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }
                    </script>
                    
                    <div class="form-group full-width">
                        <label for="anotacoes">Observações</label>
                        <textarea id="anotacoes" name="anotacoes" 
                                  placeholder="Observações importantes sobre o processo..."></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Seção: Partes do Processo -->
            <div class="form-section">
                <h3>👥 Partes do Processo</h3>
                
                <div class="partes-container" id="partesContainer">
                    <!-- As partes serão adicionadas aqui dinamicamente -->
                </div>
                
                <button type="button" class="btn-adicionar-parte" onclick="adicionarParte()">
                    + Adicionar Parte
                </button>
            </div>
            
            <button type="submit" class="btn-submit">Cadastrar Processo</button>
        </form>
    </div>
</div>

<script>
    // Dados
    const sessionToken = '<?= $session_token ?>'; // Token para autenticação dos popups
    const tiposPorNucleo = <?= json_encode($tipos_por_nucleo) ?>;
    const clientes = <?= json_encode($clientes) ?>;
    let parteCounter = 0;
    
    function atualizarTiposProcesso() {
        const selectNucleo = document.getElementById('nucleo_id');
        const selectTipo = document.getElementById('tipo_processo');
        const nucleoSelecionado = selectNucleo.value;
        
        selectTipo.innerHTML = '<option value="">Selecione...</option>';
        
        if (nucleoSelecionado && tiposPorNucleo[nucleoSelecionado]) {
            tiposPorNucleo[nucleoSelecionado].forEach(tipo => {
                const option = document.createElement('option');
                option.value = tipo;
                option.textContent = tipo;
                selectTipo.appendChild(option);
            });
        } else {
            selectTipo.innerHTML = '<option value="">Primeiro selecione o núcleo</option>';
        }
    }
    
    function adicionarParte() {
        parteCounter++;
        const container = document.getElementById('partesContainer');
        
        const parteDiv = document.createElement('div');
        parteDiv.className = 'parte-item';
        parteDiv.id = `parte-${parteCounter}`;
        
        parteDiv.innerHTML = `
            <div class="parte-header">
                <h4>Parte #${parteCounter}</h4>
                <button type="button" class="btn-remover-parte" onclick="removerParte(${parteCounter})">
                    🗑️ Remover
                </button>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Tipo de Parte *</label>
                    <select name="partes[${parteCounter}][tipo_parte]" required>
                        <option value="">Selecione...</option>
                        <option value="Autor">Autor</option>
                        <option value="Exequente">Exequente</option>
                        <option value="Réu">Réu</option>
                        <option value="Executado">Executado</option>
                        <option value="Outros">Outros</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Nome da Parte *</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" 
                               class="parte-nome-input" 
                               name="partes[${parteCounter}][nome]" 
                               data-parte-id="${parteCounter}"
                               placeholder="Digite o nome..." 
                               required
                               autocomplete="off"
                               style="flex: 1;">
                        <button type="button" class="btn-add-cliente" onclick="abrirCadastroCliente(${parteCounter})" title="Cadastrar Novo Cliente">
                            <i class="fas fa-user-plus"></i> Novo
                        </button>
                    </div>
                    <input type="hidden" name="partes[${parteCounter}][cliente_id]" id="cliente-id-${parteCounter}">
                    <div class="autocomplete-suggestions" id="suggestions-${parteCounter}"></div>
                </div>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" 
                       name="partes[${parteCounter}][e_nosso_cliente]" 
                       id="cliente-${parteCounter}" 
                       value="1">
                <label for="cliente-${parteCounter}">✓ Esta parte é quem nos contratou</label>
            </div>
        `;
        
        container.appendChild(parteDiv);
        setupAutocomplete(parteCounter);
    }
    
    function removerParte(id) {
        const parte = document.getElementById(`parte-${id}`);
        if (parte) {
            parte.remove();
        }
    }
    
    function setupAutocomplete(parteId) {
		const input = document.querySelector(`[data-parte-id="${parteId}"]`);
		const suggestions = document.getElementById(`suggestions-${parteId}`);
		const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
		let selectedIndex = -1;

		input.addEventListener('input', function() {
			const query = this.value.toLowerCase();
			selectedIndex = -1;

			if (query.length < 2) {
				suggestions.style.display = 'none';
				clienteIdInput.value = '';
				return;
			}

			const matches = clientes.filter(cliente => 
				cliente.nome.toLowerCase().includes(query) ||
				(cliente.cpf_cnpj && cliente.cpf_cnpj.includes(query))
			);

			if (matches.length > 0) {
				suggestions.innerHTML = matches.map(cliente => 
					`<div class="autocomplete-suggestion" data-id="${cliente.id}" data-nome="${cliente.nome}">
						<strong>${cliente.nome}</strong>
						${cliente.cpf_cnpj ? `<br><small>${cliente.cpf_cnpj}</small>` : ''}
					</div>`
				).join('');
				suggestions.style.display = 'block';
			} else {
				suggestions.style.display = 'none';
			}
		});

		input.addEventListener('keydown', function(e) {
			const items = suggestions.querySelectorAll('.autocomplete-suggestion');

			if (suggestions.style.display === 'none' || items.length === 0) return;

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				selectedIndex = (selectedIndex + 1) % items.length;
				updateSelection(items);
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				selectedIndex = selectedIndex <= 0 ? items.length - 1 : selectedIndex - 1;
				updateSelection(items);
			} else if (e.key === 'Enter' || e.key === 'Tab') {
				if (selectedIndex >= 0 && selectedIndex < items.length) {
					e.preventDefault();
					selectItem(items[selectedIndex]);
				}
			} else if (e.key === 'Escape') {
				suggestions.style.display = 'none';
				selectedIndex = -1;
			}
		});

		function updateSelection(items) {
			items.forEach((item, index) => {
				if (index === selectedIndex) {
					item.classList.add('active');
					item.scrollIntoView({ block: 'nearest' });
				} else {
					item.classList.remove('active');
				}
			});
		}

		function selectItem(item) {
			input.value = item.dataset.nome;
			clienteIdInput.value = item.dataset.id;
			suggestions.style.display = 'none';
			selectedIndex = -1;
		}

		suggestions.addEventListener('click', function(e) {
			const suggestion = e.target.closest('.autocomplete-suggestion');
			if (suggestion) {
				selectItem(suggestion);
			}
		});

		suggestions.addEventListener('mouseover', function(e) {
			const suggestion = e.target.closest('.autocomplete-suggestion');
			if (suggestion) {
				const items = suggestions.querySelectorAll('.autocomplete-suggestion');
				items.forEach((item, index) => {
					if (item === suggestion) {
						selectedIndex = index;
						item.classList.add('active');
					} else {
						item.classList.remove('active');
					}
				});
			}
		});

		document.addEventListener('click', function(e) {
			if (!e.target.closest(`[data-parte-id="${parteId}"]`) && 
				!e.target.closest(`#suggestions-${parteId}`)) {
				suggestions.style.display = 'none';
				selectedIndex = -1;
			}
		});
	}
    
    // Validação do formulário
    document.getElementById('processoForm').addEventListener('submit', function(e) {
        const nucleoId = document.getElementById('nucleo_id').value;
        if (!nucleoId) {
            e.preventDefault();
            alert('Por favor, selecione um núcleo.');
            return;
        }
        
        const partes = document.querySelectorAll('.parte-item');
        if (partes.length === 0) {
            e.preventDefault();
            alert('Por favor, adicione pelo menos uma parte ao processo.');
            return;
        }
        
        const temNossoCliente = document.querySelector('input[name*="[e_nosso_cliente]"]:checked');
        if (!temNossoCliente) {
            const confirmar = confirm('Nenhuma parte foi marcada como "quem nos contratou". Deseja continuar mesmo assim?');
            if (!confirmar) {
                e.preventDefault();
                return;
            }
        }
    });
    
    // Adicionar primeira parte automaticamente
    document.addEventListener('DOMContentLoaded', function() {
        adicionarParte();
    });
	
	// Função para formatar em Real Brasileiro
	function formatarReal(valor) {
		// Remove tudo que não é número
		valor = valor.replace(/\D/g, '');
		
		// Se vazio, retorna vazio
		if (valor === '' || valor === '0') {
			return '';
		}
		
		// Converte para número e formata
		valor = (parseInt(valor) / 100).toFixed(2);
		
		// Separa parte inteira da decimal
		let partes = valor.split('.');
		let inteiro = partes[0];
		let decimal = partes[1];
		
		// Adiciona separador de milhares
		inteiro = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
		
		// Retorna formatado
		return inteiro + ',' + decimal;
	}
	
	// Máscara de dinheiro - Aplicar nos campos
	document.querySelectorAll('.money-input').forEach(input => {
		// Aplicar máscara ao digitar
		input.addEventListener('input', function(e) {
			e.target.value = formatarReal(e.target.value);
		});
		
		// Ao perder o foco, garantir formato correto
		input.addEventListener('blur', function(e) {
			if (e.target.value === '' || e.target.value === '0,00') {
				e.target.value = '';
			}
		});
	});
	
	// Função para abrir cadastro de cliente
	function abrirCadastroCliente(parteId) {
		window.abrirModalCadastroCliente(parteId);
	}
	
	// Função chamada pelo popup de cliente quando um novo cliente é criado
	window.selecionarClienteCriado = function(clienteId, clienteNome, clienteDoc) {
		const parteId = window.parteIdAtual;
		
		// Adicionar cliente à lista
		clientes.push({
			id: clienteId,
			nome: clienteNome,
			cpf_cnpj: clienteDoc
		});
		
		// Selecionar no campo
		const input = document.querySelector(`[data-parte-id="${parteId}"]`);
		const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
		
		if (input && clienteIdInput) {
			input.value = clienteNome;
			clienteIdInput.value = clienteId;
			
			// Fechar sugestões se estiverem abertas
			const suggestions = document.getElementById(`suggestions-${parteId}`);
			if (suggestions) {
				suggestions.style.display = 'none';
			}
		}
	};
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Processo', $conteudo, 'processos');
?>