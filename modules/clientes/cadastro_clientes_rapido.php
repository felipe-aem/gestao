<?php
/**
 * POPUP DE CADASTRO RÁPIDO DE CLIENTE - v4.0 FINAL
 * Não usa Auth::protect() para evitar loop de redirect
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
error_log("=== CADASTRO CLIENTE RAPIDO v4.0 ===");

require_once '../../config/database.php';

// Buscar clientes para o autocomplete
require_once '../../includes/auth.php'; // Só para ter Auth::user()
$usuario_logado = Auth::user();

$usuario_logado = Auth::user();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro Rápido - Cliente</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h2 {
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-fechar {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s;
        }
        
        .btn-fechar:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }
        
        .form-content {
            padding: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            flex: 1;
        }
        
        .radio-option input[type="radio"] {
            width: auto;
            margin: 0;
            cursor: pointer;
        }
        
        .radio-option label {
            margin: 0 !important;
            cursor: pointer;
            font-weight: 600 !important;
            color: #495057;
            font-size: 14px;
        }
        
        .radio-option input[type="radio"]:checked + label {
            color: #28a745;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .btn {
            flex: 1;
            padding: 14px 25px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-cancelar {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancelar:hover {
            background: #5a6268;
        }
        
        .info-message {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            color: #004085;
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .info-message i {
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        /* Loader */
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>
                <i class="fas fa-user-plus"></i>
                Cadastro Rápido
            </h2>
            <button type="button" class="btn-fechar" onclick="window.close()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="form-content">
            <?php if (!empty($erro)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?= $erro ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($sucesso)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($sucesso) ?></span>
            </div>
            <?php endif; ?>
            
            <div class="info-message">
                <i class="fas fa-info-circle"></i>
                <span>Preencha apenas os dados essenciais. Você poderá completar o cadastro depois.</span>
            </div>
            
            <form action="process_cliente_rapido.php" method="POST" id="clienteForm">
                <div class="form-group">
                    <label for="nome">Nome Completo / Razão Social *</label>
                    <input type="text" 
                           id="nome" 
                           name="nome" 
                           required 
                           placeholder="Digite o nome completo..."
                           autocomplete="off"
                           autofocus>
                </div>
                
                <div class="form-group">
                    <label for="cpf_cnpj">CPF / CNPJ *</label>
                    <input type="text" 
                           id="cpf_cnpj" 
                           name="cpf_cnpj" 
                           required 
                           placeholder="000.000.000-00 ou 00.000.000/0000-00"
                           autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label>Tipo de Pessoa *</label>
                    <div class="radio-group">
                        <div class="radio-option">
                            <input type="radio" 
                                   id="tipo_pf" 
                                   name="tipo_pessoa" 
                                   value="fisica" 
                                   checked
                                   onchange="atualizarMascara()">
                            <label for="tipo_pf">
                                <i class="fas fa-user"></i> Pessoa Física
                            </label>
                        </div>
                        <div class="radio-option">
                            <input type="radio" 
                                   id="tipo_pj" 
                                   name="tipo_pessoa" 
                                   value="juridica"
                                   onchange="atualizarMascara()">
                            <label for="tipo_pj">
                                <i class="fas fa-building"></i> Pessoa Jurídica
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-cancelar" onclick="window.close()">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-submit" id="btnSubmit">
                        <i class="fas fa-check"></i>
                        Salvar Cliente
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Máscaras para CPF e CNPJ
        function mascaraCPF(valor) {
            return valor
                .replace(/\D/g, '')
                .replace(/(\d{3})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        }
        
        function mascaraCNPJ(valor) {
            return valor
                .replace(/\D/g, '')
                .replace(/(\d{2})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d)/, '$1/$2')
                .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
        }
        
        function atualizarMascara() {
            const input = document.getElementById('cpf_cnpj');
            const tipoPessoa = document.querySelector('input[name="tipo_pessoa"]:checked').value;
            
            // Limpar valor
            input.value = '';
            
            // Atualizar placeholder
            if (tipoPessoa === 'fisica') {
                input.placeholder = '000.000.000-00';
                input.maxLength = 14;
            } else {
                input.placeholder = '00.000.000/0000-00';
                input.maxLength = 18;
            }
        }
        
        // Aplicar máscara ao digitar
        document.getElementById('cpf_cnpj').addEventListener('input', function(e) {
            const tipoPessoa = document.querySelector('input[name="tipo_pessoa"]:checked').value;
            
            if (tipoPessoa === 'fisica') {
                e.target.value = mascaraCPF(e.target.value);
            } else {
                e.target.value = mascaraCNPJ(e.target.value);
            }
        });
        
        // Validação do formulário
        document.getElementById('clienteForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btnSubmit = document.getElementById('btnSubmit');
            const nome = document.getElementById('nome').value.trim();
            const cpfCnpj = document.getElementById('cpf_cnpj').value.replace(/\D/g, '');
            const tipoPessoa = document.querySelector('input[name="tipo_pessoa"]:checked').value;
            
            // Validar nome
            if (nome.length < 3) {
                alert('O nome deve ter pelo menos 3 caracteres.');
                return;
            }
            
            // Validar CPF/CNPJ
            if (tipoPessoa === 'fisica' && cpfCnpj.length !== 11) {
                alert('CPF deve ter 11 dígitos.');
                return;
            }
            
            if (tipoPessoa === 'juridica' && cpfCnpj.length !== 14) {
                alert('CNPJ deve ter 14 dígitos.');
                return;
            }
            
            // Desabilitar botão e mostrar loading
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="fas fa-spinner spinner"></i> Salvando...';
            
            try {
                // Enviar formulário via AJAX
                const formData = new FormData(this);
                const response = await fetch('<?= SITE_URL ?>/modules/clientes/process_cliente_rapido.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Notificar janela pai (popup de processo)
                    if (window.opener && !window.opener.closed) {
                        window.opener.selecionarClienteCriado(
                            result.cliente_id,
                            result.cliente_nome,
                            result.cliente_doc
                        );
                    }
                    
                    // Fechar popup
                    window.close();
                } else {
                    alert('Erro: ' + result.message);
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="fas fa-check"></i> Salvar Cliente';
                }
            } catch (error) {
                console.error(error);
                alert('Erro ao salvar cliente. Tente novamente.');
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<i class="fas fa-check"></i> Salvar Cliente';
            }
        });
        
        // Fechar com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.close();
            }
        });
    </script>
</body>
</html>