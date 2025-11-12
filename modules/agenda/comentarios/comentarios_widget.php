<!-- WIDGET DE COMENTÁRIOS V2 - VERSÃO FINAL PRODUCTION -->

<div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h3 style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
        <i class="fas fa-comments" style="color: #667eea;"></i> 
        Comentários 
        <span id="totalComentarios" style="background: #667eea; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;">0</span>
    </h3>
    
    <!-- Formulário -->
    <form id="formComentario" style="margin: 20px 0;">
        <div style="position: relative;">
            <textarea 
                id="textoComentario"
                placeholder="Adicione um comentário... (Use @ para mencionar alguém)"
                style="width: 100%; min-height: 100px; padding: 12px; border: 2px solid #e0e0e0; border-radius: 12px; font-size: 14px; resize: vertical; font-family: inherit; background: white;"
                required></textarea>
            
            <!-- Lista de menções (dropdown) -->
            <div id="mencoesList" style="display: none; position: fixed; background: white; border: 2px solid #667eea; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; z-index: 9999; width: 250px;"></div>
        </div>
        
        <!-- Anexos selecionados -->
        <div id="anexosPreview" style="margin: 10px 0; display: none;"></div>
        
        <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
            <button 
                type="submit" 
                style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-paper-plane"></i> Comentar
            </button>
            
            <label style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-paperclip"></i> Anexar
                <input type="file" id="inputAnexos" multiple style="display: none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt">
            </label>
            
            <button 
                type="button"
                onclick="limparFormulario()"
                style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-times"></i> Limpar
            </button>
        </div>
    </form>
    
    <!-- Lista de comentários -->
    <div id="comentariosLista" style="margin-top: 30px;">
        <p style="text-align: center; color: #999;"><i class="fas fa-spinner fa-spin"></i> Carregando comentários...</p>
    </div>
</div>

<style>
/* Estilos para menções */
.mencao {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    display: inline-block;
}

.mencao:hover {
    opacity: 0.8;
}

/* Item da lista de menções */
.mencao-item {
    padding: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.mencao-item:hover {
    background: #f8f9fa;
}

.mencao-item.selected {
    background: #667eea;
    color: white;
}

/* Anexos */
.anexo-preview {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-right: 8px;
    margin-bottom: 8px;
}

.anexo-preview button {
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}

.anexo-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: #e7f3ff;
    border: 1px solid #2196f3;
    border-radius: 6px;
    margin-right: 8px;
    margin-top: 8px;
    font-size: 13px;
}

.anexo-item i {
    color: #2196f3;
}

.anexo-item a {
    color: #2196f3;
    text-decoration: none;
    font-weight: 600;
}

.anexo-item a:hover {
    text-decoration: underline;
}

/* Comentário */
.comentario-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 15px;
    border-left: 4px solid #667eea;
    transition: all 0.2s;
}

.comentario-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateX(2px);
}

.comentario-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.comentario-autor {
    display: flex;
    align-items: center;
    gap: 10px;
}

.comentario-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 16px;
}

.comentario-data {
    color: #999;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.comentario-texto {
    margin: 10px 0;
    line-height: 1.6;
    color: #333;
    word-wrap: break-word;
}

.comentario-acoes {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

.comentario-acoes button {
    background: none;
    border: none;
    color: #667eea;
    cursor: pointer;
    font-size: 13px;
    padding: 5px 10px;
    border-radius: 4px;
    transition: all 0.2s;
}

.comentario-acoes button:hover {
    background: #667eea15;
}
</style>

<script>
// ============================================================================
// SISTEMA DE COMENTÁRIOS V2 - VERSÃO FINAL PRODUCTION
// ============================================================================

(function() {
    'use strict';
    
    console.log('🚀 Widget V2 carregado');
    
    // Variáveis do PHP
    const TIPO_ITEM = '<?= $tipo_item ?? "UNDEFINED" ?>';
    const ITEM_ID = <?= $item_id ?? 0 ?>;
    const USUARIO_ID = <?= $_SESSION['usuario_id'] ?? 0 ?>;
    const USUARIO_NOME = '<?= $_SESSION['nome'] ?? "UNDEFINED" ?>';
    
    console.log('📝 Config:', { TIPO_ITEM, ITEM_ID, USUARIO_ID });
    
    // Lista de usuários para menções
    let usuariosDisponiveis = [];
    let anexosSelecionados = [];
    let mencaoAtual = { posicao: -1, texto: '' };
    let indiceSelecionado = -1;
    
    // ===== CARREGAR LISTA DE USUÁRIOS =====
    async function carregarUsuarios() {
        try {
            const response = await fetch('/modules/agenda/comentarios/listar_usuarios.php');
            const result = await response.json();
            
            if (result.success) {
                usuariosDisponiveis = result.usuarios;
                console.log('👥 Usuários carregados:', usuariosDisponiveis.length);
            } else {
                console.error('❌ Erro ao carregar usuários:', result.message);
            }
        } catch (error) {
            console.error('❌ Erro:', error);
        }
    }
    
    // ===== CARREGAR COMENTÁRIOS =====
    async function carregarComentarios() {
        const lista = document.getElementById('comentariosLista');
        const badge = document.getElementById('totalComentarios');
        
        try {
            const url = `/modules/agenda/comentarios/buscar_comentarios.php?tipo_item=${TIPO_ITEM}&item_id=${ITEM_ID}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                const comentarios = result.comentarios;
                badge.textContent = comentarios.length;
                
                if (comentarios.length === 0) {
                    lista.innerHTML = '<p style="text-align: center; color: #999; padding: 40px;">Nenhum comentário ainda. Seja o primeiro! 🎉</p>';
                } else {
                    lista.innerHTML = comentarios.map(c => renderComentario(c)).join('');
                }
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error('❌ Erro ao carregar comentários:', error);
            lista.innerHTML = `<p style="color: red; text-align: center;"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar comentários</p>`;
        }
    }
    
    // ===== RENDERIZAR COMENTÁRIO =====
    function renderComentario(c) {
        const iniciais = c.usuario_nome.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        const dataFormatada = formatarDataCompleta(c.data_criacao);
        
        // Usar comentário processado do PHP (se existir) ou processar aqui
        const textoComMencoes = c.comentario_processado || processarMencoes(c.comentario);
        
        let anexosHtml = '';
        if (c.anexos && c.anexos.length > 0) {
            anexosHtml = '<div style="margin-top: 10px;">' + 
                c.anexos.map(a => `
                    <div class="anexo-item">
                        <i class="fas fa-paperclip"></i>
                        <a href="${a.caminho_arquivo}" target="_blank" download="${a.nome_original}">
                            ${a.nome_original}
                        </a>
                        <small>(${formatarTamanho(a.tamanho_arquivo)})</small>
                    </div>
                `).join('') +
            '</div>';
        }
        
        return `
            <div class="comentario-card">
                <div class="comentario-header">
                    <div class="comentario-autor">
                        <div class="comentario-avatar">${iniciais}</div>
                        <div>
                            <strong style="color: #333;">${c.usuario_nome}</strong>
                            <div class="comentario-data">
                                <i class="far fa-clock"></i>
                                ${dataFormatada}
                            </div>
                        </div>
                    </div>
                    ${c.usuario_id == USUARIO_ID ? `
                        <button onclick="excluirComentario(${c.id})" style="background: none; border: none; color: #dc3545; cursor: pointer; padding: 5px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : ''}
                </div>
                <div class="comentario-texto">${textoComMencoes}</div>
                ${anexosHtml}
            </div>
        `;
    }
    
    // ===== FORMATAR DATA COMPLETA =====
    function formatarDataCompleta(dataStr) {
        const data = new Date(dataStr);
        const agora = new Date();
        const diff = Math.floor((agora - data) / 1000);
        
        if (diff < 60) return 'agora mesmo';
        if (diff < 3600) return `${Math.floor(diff / 60)} min atrás`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h atrás`;
        if (diff < 604800) return `${Math.floor(diff / 86400)} dia${Math.floor(diff / 86400) > 1 ? 's' : ''} atrás`;
        
        const dia = String(data.getDate()).padStart(2, '0');
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const ano = data.getFullYear();
        const hora = String(data.getHours()).padStart(2, '0');
        const min = String(data.getMinutes()).padStart(2, '0');
        
        return `${dia}/${mes}/${ano} às ${hora}:${min}`;
    }
    
    // ===== PROCESSAR MENÇÕES =====
    function processarMencoes(texto) {
        // Converte @[id:nome] para badge HTML
        return texto.replace(/@\[(\d+):([^\]]+)\]/g, (match, id, nome) => {
            return `<span class="mencao" data-usuario-id="${id}" title="Ver perfil de ${nome}">@${nome}</span>`;
        });
    }
    
    // ===== FORMATAR TAMANHO ARQUIVO =====
    function formatarTamanho(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
    
    // ===== DETECTAR @ PARA MENÇÕES =====
    const textarea = document.getElementById('textoComentario');
    const mencoesList = document.getElementById('mencoesList');
    
    textarea.addEventListener('input', function(e) {
        // Atualizar highlight visual das menções
        
        const texto = this.value;
        const posicaoCursor = this.selectionStart;
        
        let ultimoAt = texto.lastIndexOf('@', posicaoCursor - 1);
        
        if (ultimoAt !== -1) {
            const textoAposAt = texto.substring(ultimoAt + 1, posicaoCursor);
            
            if (!textoAposAt.includes(' ')) {
                mostrarSugestoesMencoes(textoAposAt, ultimoAt);
            } else {
                esconderSugestoes();
            }
        } else {
            esconderSugestoes();
        }
    });
    
    // ===== NAVEGAÇÃO POR TECLADO =====
    textarea.addEventListener('keydown', function(e) {
        if (mencoesList.style.display === 'none') return;
        
        const itens = mencoesList.querySelectorAll('.mencao-item');
        if (itens.length === 0) return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            indiceSelecionado = Math.min(indiceSelecionado + 1, itens.length - 1);
            atualizarSelecao(itens);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            indiceSelecionado = Math.max(indiceSelecionado - 1, 0);
            atualizarSelecao(itens);
        } else if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (indiceSelecionado >= 0) {
                itens[indiceSelecionado].click();
            }
        } else if (e.key === 'Escape') {
            esconderSugestoes();
        }
    });
    
    function atualizarSelecao(itens) {
        itens.forEach((item, i) => {
            if (i === indiceSelecionado) {
                item.classList.add('selected');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('selected');
            }
        });
    }
    
    // ===== MOSTRAR SUGESTÕES =====
    function mostrarSugestoesMencoes(filtro, posicaoAt) {
        const filtrados = usuariosDisponiveis.filter(u => 
            u.nome.toLowerCase().includes(filtro.toLowerCase())
        );
        
        if (filtrados.length === 0) {
            esconderSugestoes();
            return;
        }
        
        mencaoAtual = { posicao: posicaoAt, texto: filtro };
        indiceSelecionado = 0;
        
        mencoesList.innerHTML = filtrados.slice(0, 5).map((u, i) => {
            const iniciais = u.nome.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
            return `
                <div class="mencao-item ${i === 0 ? 'selected' : ''}" onclick="selecionarMencao(${u.id}, '${u.nome.replace(/'/g, "\\'")}')">
                    <div style="width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 12px;">
                        ${iniciais}
                    </div>
                    <div>
                        <strong>${u.nome}</strong>
                        ${u.email ? `<br><small style="color: #999;">${u.email}</small>` : ''}
                    </div>
                </div>
            `;
        }).join('');
        
        const rect = textarea.getBoundingClientRect();
        mencoesList.style.display = 'block';
        mencoesList.style.top = (rect.bottom + 5) + 'px';
        mencoesList.style.left = rect.left + 'px';
    }
    
    // Mapa de menções (nome -> id)
    let mencoesMapa = {};
    
    // ===== SELECIONAR MENÇÃO =====
    window.selecionarMencao = function(usuarioId, usuarioNome) {
        const texto = textarea.value;
        const posicaoCursor = textarea.selectionStart;
        
        const antes = texto.substring(0, mencaoAtual.posicao);
        const depois = texto.substring(posicaoCursor);
        
        // Guardar mapeamento nome -> id
        mencoesMapa[usuarioNome] = usuarioId;
        
        // Insere menção com símbolos especiais para destaque visual
        // Usando caracteres Unicode que ficam visíveis no textarea
        const mencao = `⦗@${usuarioNome}⦘ `;
        textarea.value = antes + mencao + depois;
        
        const novaPosicao = antes.length + mencao.length;
        textarea.setSelectionRange(novaPosicao, novaPosicao);
        textarea.focus();
        
        esconderSugestoes();
    };
    
    function esconderSugestoes() {
        mencoesList.style.display = 'none';
        indiceSelecionado = -1;
    }
    
    // ===== ATUALIZAR HIGHLIGHT DE MENÇÕES =====
    
    // Sincronizar scroll
    textarea.addEventListener('scroll', function() {
        const highlight = document.getElementById('textareaHighlight');
        if (highlight) {
            highlight.scrollTop = this.scrollTop;
            highlight.scrollLeft = this.scrollLeft;
        }
    });
    
    // ===== GERENCIAR ANEXOS =====
    document.getElementById('inputAnexos').addEventListener('change', function(e) {
        const arquivos = Array.from(e.target.files);
        anexosSelecionados = [...anexosSelecionados, ...arquivos];
        atualizarPreviewAnexos();
    });
    
    function atualizarPreviewAnexos() {
        const preview = document.getElementById('anexosPreview');
        
        if (anexosSelecionados.length === 0) {
            preview.style.display = 'none';
            return;
        }
        
        preview.style.display = 'block';
        preview.innerHTML = anexosSelecionados.map((arquivo, i) => `
            <div class="anexo-preview">
                <i class="fas fa-${getIconeArquivo(arquivo.name)}"></i>
                <span>${arquivo.name}</span>
                <small>(${formatarTamanho(arquivo.size)})</small>
                <button type="button" onclick="removerAnexo(${i})">×</button>
            </div>
        `).join('');
    }
    
    window.removerAnexo = function(indice) {
        anexosSelecionados.splice(indice, 1);
        atualizarPreviewAnexos();
    };
    
    function getIconeArquivo(nome) {
        const ext = nome.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) return 'image';
        if (['pdf'].includes(ext)) return 'file-pdf';
        if (['doc', 'docx'].includes(ext)) return 'file-word';
        if (['xls', 'xlsx'].includes(ext)) return 'file-excel';
        return 'file';
    }
    
    // ===== LIMPAR FORMULÁRIO =====
    window.limparFormulario = function() {
        textarea.value = '';
        anexosSelecionados = [];
        mencoesMapa = {}; // Limpar mapa de menções
        atualizarPreviewAnexos();
        document.getElementById('inputAnexos').value = '';
    };
    
    // ===== SALVAR COMENTÁRIO =====
    document.getElementById('formComentario').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const comentario = textarea.value.trim();
        if (!comentario) return;
        
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        
        try {
            const formData = new FormData();
            formData.append('tipo_item', TIPO_ITEM);
            formData.append('item_id', ITEM_ID);
            // Comentário será adicionado depois da conversão
            
            anexosSelecionados.forEach((arquivo) => {
                formData.append('anexos[]', arquivo);
            });
            
            // Extrair menções usando o mapa de nomes -> IDs
            const mencoes = [];
            
            // Procurar por ⦗@Nome⦘ no texto
            const regexMencoes = /⦗@([^⦘]+)⦘/g;
            let match;
            
            while ((match = regexMencoes.exec(comentario)) !== null) {
                const nome = match[1].trim();
                if (mencoesMapa[nome]) {
                    mencoes.push(mencoesMapa[nome]);
                }
            }
            
            // Converter para formato @[id:nome] para salvar no banco
            let comentarioParaSalvar = comentario;
            for (const [nome, id] of Object.entries(mencoesMapa)) {
                // Remove os símbolos ⦗ ⦘ e converte para formato do banco
                const regex = new RegExp(`⦗@${nome.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}⦘`, 'g');
                comentarioParaSalvar = comentarioParaSalvar.replace(regex, `@[${id}:${nome}]`);
            }
            
            formData.append('comentario', comentarioParaSalvar); // Usar comentário convertido
            formData.append('mencoes', JSON.stringify(mencoes));
            
            const response = await fetch('/modules/agenda/comentarios/salvar_comentario.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                limparFormulario();
                carregarComentarios();
                
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Comentário salvo!',
                        text: mencoes.length > 0 ? `${mencoes.length} pessoa(s) mencionada(s)` : '',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    alert('✅ Comentário salvo!');
                }
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error('❌ Erro:', error);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro!',
                    text: error.message
                });
            } else {
                alert('❌ Erro: ' + error.message);
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Comentar';
        }
    });
    
    // ===== EXCLUIR COMENTÁRIO =====
    window.excluirComentario = async function(comentarioId) {
        if (!confirm('Excluir este comentário?')) return;
        
        try {
            const formData = new FormData();
            formData.append('comentario_id', comentarioId);
            
            const response = await fetch('/modules/agenda/comentarios/excluir_comentario.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                carregarComentarios();
                alert('✅ Excluído!');
            } else {
                alert('❌ ' + result.message);
            }
        } catch (error) {
            console.error(error);
            alert('❌ Erro ao excluir');
        }
    };
    
    // ===== INICIALIZAR =====
    carregarUsuarios();
    carregarComentarios();
    
    console.log('✅ Widget inicializado');
    
})();
</script>