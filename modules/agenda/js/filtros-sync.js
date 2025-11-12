/**
 * Sistema de Sincronização de Filtros
 */

(function() {
    'use strict';
    
    const STORAGE_KEY = 'agenda_filtros';
    
    // FUNÇÃO GLOBAL - DEVE SER PRIMEIRA
    window.trocarVisualizacao = function(view) {
        console.log('🔄 Trocando para:', view);
        
        const form = document.getElementById('formFiltros');
        if (form) {
            const usuariosSelect = document.getElementById('usuariosSelect');
            const usuariosHidden = document.getElementById('usuariosHidden');
            if (usuariosSelect && usuariosHidden) {
                const ids = Array.from(usuariosSelect.selectedOptions).map(option => option.value);
                usuariosHidden.value = ids.join(',');
            }
            
            const filtros = {
                view: view,
                tipo: form.querySelector('[name="tipo"]')?.value || '',
                periodo: form.querySelector('[name="periodo"]')?.value || 'proximos_30',
                busca: form.querySelector('[name="busca"]')?.value || '',
                nucleo: form.querySelector('[name="nucleo"]')?.value || '',
                usuarios: form.querySelector('[name="usuarios"]')?.value || '',
                data_inicio: form.querySelector('[name="data_inicio"]')?.value || '',
                data_fim: form.querySelector('[name="data_fim"]')?.value || '',
                timestamp: Date.now()
            };
            
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(filtros));
            console.log('✅ Filtros salvos:', filtros);
        }
        
        const params = new URLSearchParams();
        params.set('view', view);
        
        const filtrosSalvos = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}');
        if (filtrosSalvos.tipo) params.set('tipo', filtrosSalvos.tipo);
        if (filtrosSalvos.periodo) params.set('periodo', filtrosSalvos.periodo);
        if (filtrosSalvos.busca) params.set('busca', filtrosSalvos.busca);
        if (filtrosSalvos.nucleo) params.set('nucleo', filtrosSalvos.nucleo);
        if (filtrosSalvos.usuarios) params.set('usuarios', filtrosSalvos.usuarios);
        if (filtrosSalvos.data_inicio) params.set('data_inicio', filtrosSalvos.data_inicio);
        if (filtrosSalvos.data_fim) params.set('data_fim', filtrosSalvos.data_fim);
        
        const url = '?' + params.toString();
        console.log('📍 Redirecionando para:', url);
        
        window.location.href = url;
        return false;
    };
    
    function salvarFiltros() {
        const form = document.getElementById('formFiltros');
        if (!form) return;
        
        const usuariosSelect = document.getElementById('usuariosSelect');
        const usuariosHidden = document.getElementById('usuariosHidden');
        if (usuariosSelect && usuariosHidden) {
            const ids = Array.from(usuariosSelect.selectedOptions).map(option => option.value);
            usuariosHidden.value = ids.join(',');
        }
        
        const filtros = {
            tipo: form.querySelector('[name="tipo"]')?.value || '',
            periodo: form.querySelector('[name="periodo"]')?.value || 'proximos_30',
            busca: form.querySelector('[name="busca"]')?.value || '',
            nucleo: form.querySelector('[name="nucleo"]')?.value || '',
            usuarios: form.querySelector('[name="usuarios"]')?.value || '',
            data_inicio: form.querySelector('[name="data_inicio"]')?.value || '',
            data_fim: form.querySelector('[name="data_fim"]')?.value || '',
            timestamp: Date.now()
        };
        
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(filtros));
    }
    
    function sincronizarFiltrosAoCarregar() {
        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.has('tipo') || urlParams.has('periodo') || urlParams.has('busca') || 
            urlParams.has('nucleo') || urlParams.has('usuarios')) {
            
            const filtros = {
                tipo: urlParams.get('tipo') || '',
                periodo: urlParams.get('periodo') || 'proximos_30',
                busca: urlParams.get('busca') || '',
                nucleo: urlParams.get('nucleo') || '',
                usuarios: urlParams.get('usuarios') || '',
                data_inicio: urlParams.get('data_inicio') || '',
                data_fim: urlParams.get('data_fim') || '',
                timestamp: Date.now()
            };
            
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(filtros));
        }
    }
    
    function inicializar() {
        sincronizarFiltrosAoCarregar();
        
        const form = document.getElementById('formFiltros');
        if (form) {
            form.addEventListener('submit', salvarFiltros);
            
            const selects = form.querySelectorAll('select, input');
            selects.forEach(campo => {
                campo.addEventListener('change', salvarFiltros);
            });
        }
        
        console.log('✅ Sistema de filtros inicializado');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
})();