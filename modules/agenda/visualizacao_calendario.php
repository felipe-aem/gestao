<?php
/**
 * Visualização em Calendário da Agenda - VERSÃO 3.0 DEFINITIVA
 * Usa FullCalendar para exibir todos os eventos
 * ✅ Fundo BRANCO garantido
 * ✅ Títulos cortados em 30 caracteres
 */
?>

<!-- CSS INLINE COM MÁXIMA PRIORIDADE -->
<style>
/* ========================================== */
/* RESET TOTAL - FORÇA BRANCO EM TUDO */
/* ========================================== */

/* Remove QUALQUER background do FullCalendar */
#calendar,
#calendar *,
.fc,
.fc *,
.fc-view,
.fc-view *,
.fc-daygrid,
.fc-daygrid *,
table.fc-scrollgrid,
table.fc-scrollgrid * {
    background: none !important;
    background-color: transparent !important;
    background-image: none !important;
}

/* FORÇA branco apenas nas células dos dias */
.fc-daygrid-day {
    background: #ffffff !important;
    background-color: #ffffff !important;
}

/* Dias do mês atual - BRANCO ABSOLUTO */
td.fc-daygrid-day.fc-day:not(.fc-day-other) {
    background: #ffffff !important;
    background-color: #ffffff !important;
}

/* Dias de outros meses - cinza claro */
td.fc-daygrid-day.fc-day-other {
    background: #f8f8f8 !important;
    background-color: #f8f8f8 !important;
    opacity: 0.5 !important;
}
</style>

<div class="card">
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('calendar');
    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'pt-br',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: {
            today: 'Hoje',
            month: 'Mês',
            week: 'Semana',
            day: 'Dia',
            list: 'Lista'
        },
        initialView: 'dayGridMonth',
        navLinks: true,
        editable: false,
        dayMaxEvents: 3,
        height: 'auto',
        firstDay: 0,
        aspectRatio: 1.5,
        contentHeight: 'auto',
        fixedWeekCount: false,
        
        // ===== CARREGAR EVENTOS =====
        events: function(info, successCallback, failureCallback) {
            console.log('📅 Carregando eventos...', info.startStr, 'até', info.endStr);
            
            let filtros = {};
            try {
                const filtrosSalvos = sessionStorage.getItem('agenda_filtros');
                if (filtrosSalvos) {
                    filtros = JSON.parse(filtrosSalvos);
                }
            } catch (e) {
                console.error('Erro ao carregar filtros:', e);
            }
            
            const tipo = filtros.tipo || '<?= $filtro_tipo ?? "" ?>';
            const usuarios = filtros.usuarios || '';
            
            $.ajax({
                url: 'api_eventos.php',
                method: 'GET',
                data: {
                    start: info.startStr,
                    end: info.endStr,
                    tipo: tipo,
                    responsavel: usuarios.split(',')[0] || ''
                },
                success: function(response) {
                    console.log('✅ Eventos carregados:', response.total);
                    
                    if (response.success) {
                        successCallback(response.eventos);
                        setTimeout(() => aplicarCoresNasDatas(response.eventos), 200);
                    } else {
                        console.error('❌ Erro:', response.message);
                        failureCallback(response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Erro AJAX:', error);
                    failureCallback('Erro ao carregar eventos');
                }
            });
        },
        
        // ===== AO CLICAR EM EVENTO =====
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            
            const evento = info.event;
            const tipo = evento.extendedProps.tipo;
            const tipo_id = evento.extendedProps.tipo_id;
            
            console.log('📌 Clicou no evento:', tipo, tipo_id);
            window.open(`visualizar.php?id=${tipo_id}&tipo=${tipo}`, '_blank');
        },
        
        // ===== AO CLICAR EM DATA =====
        dateClick: function(info) {
            console.log('📅 Clicou na data:', info.dateStr);
            carregarEventosDia(info.dateStr);
        },
        
        // ===== RENDERIZAR EVENTO COM TÍTULO CORTADO =====
        eventContent: function(arg) {
            const tipo = arg.event.extendedProps.tipo;
            
            const icones = {
                'tarefa': 'fa-tasks',
                'prazo': 'fa-clock',
                'audiencia': 'fa-gavel',
                'atendimento': 'fa-user',
                'visita_comex': 'fa-briefcase',
                'visita_tax': 'fa-calculator',
                'reuniao': 'fa-calendar'
            };
            
            if (arg.event.display === 'background') {
                return true;
            }
            
            const icone = icones[tipo] || 'fa-calendar';
            
            // ✅ CORTA O TÍTULO EM 30 CARACTERES
            let titulo = arg.event.title;
            if (titulo.length > 30) {
                titulo = titulo.substring(0, 30) + '...';
            }
            
            return {
                html: `<div class="fc-event-main-frame">
                    <div class="fc-event-time">${arg.timeText || ''}</div>
                    <div class="fc-event-title-container">
                        <div class="fc-event-title fc-sticky">
                            <i class="fas ${icone} me-1"></i>${titulo}
                        </div>
                    </div>
                </div>`
            };
        },
        
        // ===== APÓS RENDERIZAR EVENTO =====
        eventDidMount: function(info) {
            const atrasado = info.event.extendedProps.atrasado;
            const concluido = info.event.extendedProps.concluido;
            const responsavel = info.event.extendedProps.responsavel;
            const tituloCompleto = info.event.extendedProps.titulo_completo;
            
            // Tooltip com título completo
            if (responsavel) {
                info.el.title = `${tituloCompleto || info.event.title}\n👤 ${responsavel}`;
            } else {
                info.el.title = tituloCompleto || info.event.title;
            }
        }
    });
    
    calendar.render();
    console.log('✅ Calendário renderizado');
    
    // ===== FORÇA FUNDO BRANCO ABSOLUTO =====
    function forcaFundoBranco() {
        console.log('🎨 [V3] Forçando fundo branco...');
        
        // Seleciona TODAS as células
        const todasCelulas = document.querySelectorAll('.fc-daygrid-day');
        
        todasCelulas.forEach(celula => {
            // Remove TODOS os backgrounds inline
            celula.style.background = '';
            celula.style.backgroundColor = '';
            celula.style.backgroundImage = '';
            
            // Se NÃO for dia de outro mês, força branco
            if (!celula.classList.contains('fc-day-other')) {
                celula.style.setProperty('background', '#ffffff', 'important');
                celula.style.setProperty('background-color', '#ffffff', 'important');
            }
            
            // Remove backgrounds de elementos internos
            const internos = celula.querySelectorAll('*:not(.fc-event):not(.fc-event *)');
            internos.forEach(el => {
                el.style.background = '';
                el.style.backgroundColor = '';
                el.style.backgroundImage = '';
            });
        });
        
        console.log(`✅ [V3] Processadas ${todasCelulas.length} células`);
    }
    
    // Executa múltiplas vezes para garantir
    setTimeout(forcaFundoBranco, 100);
    setTimeout(forcaFundoBranco, 300);
    setTimeout(forcaFundoBranco, 500);
    
    // Reaplica após qualquer mudança
    calendar.on('datesSet', function() {
        setTimeout(forcaFundoBranco, 100);
        setTimeout(forcaFundoBranco, 300);
    });
    
    calendar.on('eventsSet', function() {
        setTimeout(forcaFundoBranco, 100);
    });
    
    // ===== FUNÇÃO PARA APLICAR CORES NOS DIAS =====
    function aplicarCoresNasDatas(eventos) {
        console.log('🎨 Aplicando cores nos dias...');
        
        const eventosPorDia = {};
        
        eventos.forEach(evento => {
            const data = evento.start.split('T')[0];
            
            if (!eventosPorDia[data]) {
                eventosPorDia[data] = {
                    total: 0,
                    atrasados: 0,
                    pendentes: 0,
                    concluidos: 0
                };
            }
            
            eventosPorDia[data].total++;
            
            if (evento.extendedProps.concluido) {
                eventosPorDia[data].concluidos++;
            } else if (evento.extendedProps.atrasado) {
                eventosPorDia[data].atrasados++;
            } else {
                eventosPorDia[data].pendentes++;
            }
        });
        
        Object.keys(eventosPorDia).forEach(data => {
            const stats = eventosPorDia[data];
            const celula = document.querySelector(`[data-date="${data}"]`);
            
            if (celula) {
                celula.classList.remove('dia-todos-concluidos', 'dia-tem-atrasados', 'dia-tem-pendentes');
                
                if (stats.atrasados > 0) {
                    celula.classList.add('dia-tem-atrasados');
                } else if (stats.pendentes > 0) {
                    celula.classList.add('dia-tem-pendentes');
                } else if (stats.total > 0 && stats.concluidos === stats.total) {
                    celula.classList.add('dia-todos-concluidos');
                }
            }
        });
        
        console.log('✅ Cores aplicadas em', Object.keys(eventosPorDia).length, 'dias');
    }
    
    // ===== FUNÇÃO PARA CARREGAR EVENTOS DE UM DIA =====
    function carregarEventosDia(data) {
        $.ajax({
            url: 'api_eventos_dia.php',
            method: 'GET',
            data: { data: data },
            success: function(response) {
                if (response.success) {
                    mostrarModalEventosDia(data, response.eventos);
                } else {
                    Swal.fire('Erro', response.message, 'error');
                }
            },
            error: function() {
                Swal.fire('Erro', 'Erro ao carregar eventos do dia', 'error');
            }
        });
    }
    
    // ===== MODAL COM EVENTOS DO DIA =====
    function mostrarModalEventosDia(data, eventos) {
        const dataFormatada = new Date(data + 'T12:00:00').toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
            weekday: 'long'
        });
        
        let html = '';
        
        if (eventos.length === 0) {
            html = '<p class="text-muted text-center py-4">Nenhum evento neste dia</p>';
        } else {
            html = '<div class="list-group">';
            
            eventos.forEach(function(evento) {
                const icones = {
                    'tarefa': 'fa-tasks',
                    'prazo': 'fa-clock',
                    'audiencia': 'fa-gavel',
                    'atendimento': 'fa-user',
                    'visita_comex': 'fa-briefcase',
                    'visita_tax': 'fa-calculator',
                    'reuniao': 'fa-calendar'
                };
                
                const icone = icones[evento.tipo] || 'fa-calendar';
                const cor = evento.cor || '#667eea';
                const concluido = evento.concluido ? 'text-decoration-line-through opacity-50' : '';
                const badge = evento.concluido ? '<span class="badge bg-success ms-2">Concluído</span>' : 
                             evento.atrasado ? '<span class="badge bg-danger ms-2">Atrasado</span>' : '';
                
                html += `
                    <a href="visualizar.php?id=${evento.tipo_id}&tipo=${evento.tipo}" 
                       target="_blank"
                       class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between align-items-center">
                            <h6 class="mb-1 ${concluido}">
                                <i class="fas ${icone} me-2" style="color: ${cor}"></i>
                                ${evento.titulo}
                            </h6>
                            ${badge}
                        </div>
                        ${evento.horario ? `<small class="text-muted"><i class="fas fa-clock me-1"></i>${evento.horario}</small>` : ''}
                        ${evento.responsavel ? `<small class="text-muted ms-3"><i class="fas fa-user me-1"></i>${evento.responsavel}</small>` : ''}
                    </a>
                `;
            });
            
            html += '</div>';
        }
        
        Swal.fire({
            title: dataFormatada,
            html: html,
            width: '600px',
            customClass: {
                container: 'swal-wide'
            },
            showCloseButton: true,
            showConfirmButton: false
        });
    }
});
</script>

<style>
/* ===== ESTILOS DO CALENDÁRIO ===== */

/* Layout da tabela */
.fc-scrollgrid {
    border-collapse: collapse !important;
    table-layout: fixed !important;
    width: 100% !important;
}

/* Cabeçalho dos dias da semana */
.fc-col-header-cell {
    width: 14.28% !important;
    font-weight: 700;
    text-align: center;
    padding: 12px 8px !important;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: 1px solid rgba(255,255,255,0.2) !important;
}

/* Células dos dias */
.fc-daygrid-day {
    width: 14.28% !important;
    min-height: 120px !important;
    height: auto !important;
    border: 1px solid #e0e0e0 !important;
    vertical-align: top !important;
    padding: 4px !important;
    position: relative;
}

/* Número do dia */
.fc-daygrid-day-number {
    font-weight: 600;
    font-size: 14px;
    color: #333;
    padding: 4px 8px;
}

/* DIA DE HOJE - Azul */
.fc-day-today {
    background-color: rgba(102, 126, 234, 0.08) !important;
    border: 2px solid #667eea !important;
}

.fc-day-today .fc-daygrid-day-number {
    color: #667eea !important;
    background: rgba(102, 126, 234, 0.15);
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ===== EVENTOS ===== */

/* Atrasados - Vermelho */
.fc-event.evento-atrasado {
    background-color: #dc3545 !important;
    border-color: #dc3545 !important;
    color: white !important;
    font-weight: 700 !important;
}

/* Pendentes - Amarelo */
.fc-event.evento-pendente {
    background-color: #ffc107 !important;
    border-color: #ffc107 !important;
    color: #000000 !important;
    font-weight: 600 !important;
}

/* Concluídos - Verde discreto */
.fc-event.evento-concluido {
    background-color: rgba(40, 167, 69, 0.25) !important;
    border-color: rgba(40, 167, 69, 0.5) !important;
    opacity: 0.7 !important;
}

/* Hover em eventos */
.fc-event:hover {
    opacity: 0.9 !important;
    transform: scale(1.02);
    cursor: pointer;
    z-index: 9999 !important;
}

/* Título do evento - COM CORTE */
.fc-event-title {
    font-size: 12px !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 100% !important;
}

/* "+X MAIS" */
.fc-daygrid-more-link {
    background: #667eea !important;
    color: white !important;
    padding: 2px 8px !important;
    border-radius: 12px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    margin-top: 4px !important;
}

.fc-daygrid-more-link:hover {
    background: #5568d3 !important;
}

/* ===== POPOVER ===== */

.fc-popover {
    border-radius: 12px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2) !important;
    border: 2px solid #667eea !important;
}

.fc-popover-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: white !important;
    font-weight: 700 !important;
}

/* ===== BOTÕES ===== */

.fc-toolbar-title {
    font-size: 24px !important;
    font-weight: 700 !important;
}

.fc-button {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    border: none !important;
    font-weight: 600 !important;
    padding: 8px 16px !important;
    border-radius: 8px !important;
}

.fc-button:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4) !important;
}

/* ===== CORES DOS DIAS ===== */

/* Dia com ATRASADOS - vermelho */
.fc-daygrid-day.dia-tem-atrasados:not(.fc-day-other) {
    border-left: 4px solid #dc3545 !important;
}

/* Dia com PENDENTES - amarelo */
.fc-daygrid-day.dia-tem-pendentes:not(.fc-day-other) {
    border-left: 4px solid #ffc107 !important;
}

/* Dia TODOS concluídos - verde discreto */
.fc-daygrid-day.dia-todos-concluidos:not(.fc-day-other) {
    border-left: 4px solid #28a745 !important;
}

/* ===== MODAL ===== */

.swal-wide {
    max-width: 600px !important;
}

.list-group-item {
    transition: all 0.2s;
}

.list-group-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

.text-decoration-line-through {
    text-decoration: line-through !important;
}

/* ===== RESPONSIVO ===== */

@media (max-width: 768px) {
    .fc-daygrid-day {
        min-height: 80px !important;
    }
    
    .fc-toolbar-title {
        font-size: 18px !important;
    }
}
</style>