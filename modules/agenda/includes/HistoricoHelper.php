<?php
class HistoricoHelper {
    
    /**
     * Registra uma ação no histórico
     */
    public static function registrar($tipo, $id, $tipo_acao, $dados = []) {
        try {
            $tabela_historico = match($tipo) {
                'tarefa' => 'tarefas_historico',
                'prazo' => 'prazos_historico',
                'audiencia' => 'audiencias_historico',
                'evento', 'compromisso' => 'agenda_historico',
                default => throw new Exception('Tipo inválido')
            };
            
            $campo_id = match($tipo) {
                'tarefa' => 'tarefa_id',
                'prazo' => 'prazo_id',
                'audiencia' => 'audiencia_id',
                'evento', 'compromisso' => 'agenda_id',
            };
            
            $usuario_id = $_SESSION['usuario_id'] ?? null;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            
            $sql = "INSERT INTO {$tabela_historico} 
                    ({$campo_id}, usuario_id, tipo_acao, campo_alterado, valor_anterior, valor_novo, observacao, ip_address) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $id,
                $usuario_id,
                $tipo_acao,
                $dados['campo_alterado'] ?? null,
                $dados['valor_anterior'] ?? null,
                $dados['valor_novo'] ?? null,
                $dados['observacao'] ?? null,
                $ip_address
            ];
            
            executeQuery($sql, $params);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao registrar histórico: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca o histórico de um item
     */
    public static function buscar($tipo, $id) {
        try {
            $tabela_historico = match($tipo) {
                'tarefa' => 'tarefas_historico',
                'evento', 'compromisso' => 'agenda_historico',
                'prazo' => 'prazos_historico',
                'audiencia' => 'audiencias_historico',
                default => throw new Exception('Tipo inválido')
            };
            
            $campo_id = match($tipo) {
                'tarefa' => 'tarefa_id',
                'prazo' => 'prazo_id',
                'evento', 'compromisso' => 'agenda_id',
                'audiencia' => 'audiencia_id',
            };
            
            $sql = "SELECT h.*, u.nome as usuario_nome
                    FROM {$tabela_historico} h
                    LEFT JOIN usuarios u ON h.usuario_id = u.id
                    WHERE h.{$campo_id} = ?
                    ORDER BY h.data_hora ASC";
            
            $stmt = executeQuery($sql, [$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Erro ao buscar histórico: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Formata a exibição de uma entrada de histórico
     */
    public static function formatarEntrada($entrada) {
        $icone = self::getIcone($entrada['tipo_acao']);
        $cor = self::getCor($entrada['tipo_acao']);
        $texto = self::getTexto($entrada);
        
        return [
            'icone' => $icone,
            'cor' => $cor,
            'texto' => $texto,
            'usuario' => $entrada['usuario_nome'] ?? 'Sistema',
            'data_hora' => $entrada['data_hora']
        ];
    }
    
    private static function getIcone($tipo_acao) {
        return match($tipo_acao) {
            'criacao' => 'fa-plus-circle',
            'alteracao' => 'fa-edit',
            'status_alterado' => 'fa-exchange-alt',
            'conclusao' => 'fa-check-circle',
            'cancelamento' => 'fa-times-circle',
            'reabertura' => 'fa-redo',
            'comentario' => 'fa-comment',
            'comentario_excluido' => 'fa-comment-slash',
            'anexo_adicionado' => 'fa-paperclip',
            'envolvido_adicionado' => 'fa-user-plus',
            'envolvido_removido' => 'fa-user-minus',
            'etiqueta_adicionada' => 'fa-tag',
            'etiqueta_removida' => 'fa-tag',
            'exclusao' => 'fa-trash-alt',
            default => 'fa-circle'
        };
    }
    
    private static function getCor($tipo_acao) {
        return match($tipo_acao) {
            'criacao' => '#28a745',
            'alteracao' => '#667eea',
            'status_alterado' => '#17a2b8',
            'conclusao' => '#28a745',
            'cancelamento' => '#dc3545',
            'reabertura' => '#ffc107',
            'comentario' => '#6f42c1',
            'comentario_excluido' => '#dc3545',
            'anexo_adicionado' => '#fd7e14',
            'envolvido_adicionado' => '#20c997',
            'envolvido_removido' => '#e83e8c',
            'etiqueta_adicionada' => '#6610f2',
            'etiqueta_removida' => '#6c757d',
            'exclusao' => '#dc3545',
            default => '#999'
        };
    }
    
    private static function getTexto($entrada) {
        $tipo = $entrada['tipo_acao'];
        $campo = $entrada['campo_alterado'];
        $anterior = $entrada['valor_anterior'];
        $novo = $entrada['valor_novo'];
        $obs = $entrada['observacao'];
        
        // Textos personalizados por tipo de ação
        $textos = [
            'criacao' => $obs ?: 'Criou este item',
            'conclusao' => 'Marcou como concluída',
            'cancelamento' => 'Cancelou este item',
            'reabertura' => 'Reabriu este item',
            'comentario' => $obs ?: 'Adicionou um comentário',
            'comentario_excluido' => self::formatarComentarioExcluido($entrada),
            'anexo_adicionado' => 'Adicionou um anexo',
            'envolvido_adicionado' => "Adicionou <strong>{$novo}</strong> como envolvido",
            'envolvido_removido' => "Removeu <strong>{$anterior}</strong> dos envolvidos",
            'etiqueta_adicionada' => "Adicionou a etiqueta <span style='background: #6610f2; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px;'>{$novo}</span>",
            'etiqueta_removida' => "Removeu a etiqueta <span style='background: #6c757d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px;'>{$anterior}</span>",
            'exclusao' => $obs ?: 'Excluiu este item',
        ];
        
        if (isset($textos[$tipo])) {
            return $textos[$tipo];
        }
        
        // Alteração de campo
        if ($tipo === 'alteracao' || $tipo === 'status_alterado') {
            $campos_traduzidos = [
                'titulo' => 'Título',
                'descricao' => 'Descrição',
                'status' => 'Status',
                'prioridade' => 'Prioridade',
                'responsavel_id' => 'Responsável',
                'data_vencimento' => 'Data de vencimento',
                'data_inicio' => 'Data de início',
                'data_fim' => 'Data de término',
                'local_evento' => 'Local',
                'tipo' => 'Tipo',
                'processo_id' => 'Processo'
            ];
            
            $campo_nome = $campos_traduzidos[$campo] ?? $campo;
            
            if ($anterior && $novo) {
                // Formatar valores especiais
                if (in_array($campo, ['data_vencimento', 'data_inicio', 'data_fim'])) {
                    // Normalizar para timestamp antes de comparar
                    $ts_anterior = strtotime($anterior);
                    $ts_novo = strtotime($novo);
                    
                    // Se as datas forem iguais (mesmo timestamp), não mostrar alteração
                    if ($ts_anterior == $ts_novo) {
                        return null; // Não registrar se for a mesma data
                    }
                    
                    $anterior = date('d/m/Y H:i', $ts_anterior);
                    $novo = date('d/m/Y H:i', $ts_novo);
                }
                
                return "Alterou <strong>{$campo_nome}</strong>: <span style='text-decoration: line-through; color: #999;'>{$anterior}</span> → <strong style='color: #28a745;'>{$novo}</strong>";
            }
        }
        
        return 'Realizou uma alteração';
    }
    
    /**
     * Formata a exibição de um comentário excluído no histórico
     * 
     * @param array $entrada Entrada do histórico
     * @return string HTML formatado
     */
    private static function formatarComentarioExcluido($entrada) {
        $obs = $entrada['observacao'] ?? '';
        
        // Se já tem observação formatada, usar ela como base
        if (!empty($obs)) {
            // Começar com a observação
            $texto = '<strong>Excluiu um comentário</strong>';
            
            // Tentar extrair dados do JSON
            $dados = null;
            if (!empty($entrada['valor_anterior'])) {
                $dados = json_decode($entrada['valor_anterior'], true);
            }
            
            // Se temos dados JSON, criar um box estilizado
            if ($dados && !empty($dados['texto_completo'])) {
                $preview = mb_substr($dados['texto_completo'], 0, 150);
                if (mb_strlen($dados['texto_completo']) > 150) {
                    $preview .= '...';
                }
                
                $texto .= '<div style="margin-top: 8px; padding: 10px; background: #fff3cd; border-left: 3px solid #ffc107; border-radius: 4px;">';
                $texto .= '<div style="font-size: 11px; color: #856404; font-weight: bold; margin-bottom: 5px;">📝 Conteúdo excluído:</div>';
                $texto .= '<div style="color: #856404; font-style: italic; margin-bottom: 8px;">"' . htmlspecialchars($preview) . '"</div>';
                
                // Autor e data em uma linha
                if (!empty($dados['usuario_nome']) || !empty($dados['data_criacao'])) {
                    $texto .= '<div style="font-size: 11px; color: #856404; border-top: 1px solid #ffeaa7; padding-top: 5px;">';
                    
                    if (!empty($dados['usuario_nome'])) {
                        $texto .= '<i class="fas fa-user"></i> ' . htmlspecialchars($dados['usuario_nome']);
                    }
                    
                    if (!empty($dados['data_criacao'])) {
                        if (!empty($dados['usuario_nome'])) {
                            $texto .= ' • ';
                        }
                        $data_formatada = date('d/m/Y H:i', strtotime($dados['data_criacao']));
                        $texto .= '<i class="fas fa-clock"></i> ' . $data_formatada;
                    }
                    
                    $texto .= '</div>';
                }
                
                // Menções
                if (!empty($dados['mencoes']) && is_array($dados['mencoes']) && count($dados['mencoes']) > 0) {
                    $texto .= '<div style="font-size: 11px; color: #856404; margin-top: 5px;">';
                    $texto .= '<i class="fas fa-at"></i> Mencionava: ' . htmlspecialchars(implode(', ', $dados['mencoes']));
                    $texto .= '</div>';
                }
                
                // Anexos
                if (!empty($dados['anexos']) && is_array($dados['anexos']) && count($dados['anexos']) > 0) {
                    $texto .= '<div style="font-size: 11px; color: #856404; margin-top: 5px;">';
                    $texto .= '<i class="fas fa-paperclip"></i> ' . count($dados['anexos']) . ' anexo(s): ';
                    $texto .= htmlspecialchars(implode(', ', $dados['anexos']));
                    $texto .= '</div>';
                }
                
                $texto .= '</div>';
            } else {
                // Não tem JSON, usar só a observação
                $texto .= '<div style="margin-top: 5px; color: #6c757d; font-size: 13px;">' . $obs . '</div>';
            }
            
            return $texto;
        }
        
        return 'Excluiu um comentário';
    }
    
    /**
     * Registra a criação com motivo detalhado
     */
    public static function registrarCriacao($tipo, $id, $motivo = null, $dados_adicionais = []) {
        // Construir observação detalhada
        $observacao_parts = [];
        
        if ($motivo) {
            $observacao_parts[] = $motivo;
        }
        
        // Adicionar informações sobre o item criado
        if (!empty($dados_adicionais['titulo'])) {
            $observacao_parts[] = "Título: " . $dados_adicionais['titulo'];
        }
        
        if (!empty($dados_adicionais['responsavel_nome'])) {
            $observacao_parts[] = "Responsável: " . $dados_adicionais['responsavel_nome'];
        }
        
        if (!empty($dados_adicionais['data_vencimento'])) {
            $data_formatada = date('d/m/Y H:i', strtotime($dados_adicionais['data_vencimento']));
            $observacao_parts[] = "Vencimento: " . $data_formatada;
        }
        
        if (!empty($dados_adicionais['processo_numero'])) {
            $observacao_parts[] = "Processo: " . $dados_adicionais['processo_numero'];
        }
        
        $observacao_final = !empty($observacao_parts) ? implode(' | ', $observacao_parts) : 'Item criado';
        
        return self::registrar($tipo, $id, 'criacao', [
            'observacao' => $observacao_final
        ]);
    }
    
    /**
     * Registra alteração de campo
     */
    public static function registrarAlteracao($tipo, $id, $campo, $valor_anterior, $valor_novo) {
        // Normalizar valores vazios
        if (empty($valor_anterior)) $valor_anterior = null;
        if (empty($valor_novo)) $valor_novo = null;
        
        // Para datas, comparar timestamps
        if (in_array($campo, ['data_vencimento', 'data_inicio', 'data_fim'])) {
            if ($valor_anterior !== null && $valor_novo !== null) {
                $ts_anterior = strtotime($valor_anterior);
                $ts_novo = strtotime($valor_novo);
                
                if ($ts_anterior == $ts_novo) {
                    return false; // Mesma data, não registrar
                }
            }
        }
        
        if ($valor_anterior == $valor_novo) {
            return false; // Não registra se não houve mudança
        }
        
        $tipo_acao = $campo === 'status' ? 'status_alterado' : 'alteracao';
        
        return self::registrar($tipo, $id, $tipo_acao, [
            'campo_alterado' => $campo,
            'valor_anterior' => $valor_anterior,
            'valor_novo' => $valor_novo
        ]);
    }
    
    /**
     * Registra comentário
     */
    public static function registrarComentario($tipo, $id, $comentario) {
        return self::registrar($tipo, $id, 'comentario', [
            'observacao' => $comentario
        ]);
    }
    
    /**
     * Registra exclusão do item
     */
    public static function registrarExclusao($tipo, $id, $dados_item = []) {
        $observacao_parts = ['Item excluído'];
        
        if (!empty($dados_item['titulo'])) {
            $observacao_parts[] = "Título: " . $dados_item['titulo'];
        }
        
        if (!empty($dados_item['status'])) {
            $observacao_parts[] = "Status na exclusão: " . $dados_item['status'];
        }
        
        if (!empty($dados_item['processo_numero'])) {
            $observacao_parts[] = "Processo: " . $dados_item['processo_numero'];
        }
        
        $observacao_final = implode(' | ', $observacao_parts);
        
        return self::registrar($tipo, $id, 'exclusao', [
            'observacao' => $observacao_final,
            'valor_anterior' => json_encode($dados_item) // Guardar snapshot completo
        ]);
    }
    
    /**
     * Renderiza o histórico completo
     */
    public static function renderHistorico($tipo, $id, $mostrar_excluido = false) {
        $historico = self::buscar($tipo, $id);
        
        if (empty($historico)) {
            return '<div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-history" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px;"></i>
                        <p>Nenhum histórico registrado ainda</p>
                    </div>';
        }
        
        $html = '<div class="historico-timeline">';
        
        // Verificar se há registro de exclusão
        $tem_exclusao = false;
        foreach ($historico as $entrada) {
            if ($entrada['tipo_acao'] === 'exclusao') {
                $tem_exclusao = true;
                break;
            }
        }
        
        // Adicionar alerta se item foi excluído
        if ($tem_exclusao && $mostrar_excluido) {
            $html .= '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 8px;"></i>
                        <strong style="color: #856404;">Este item foi excluído</strong>
                      </div>';
        }
        
        foreach ($historico as $entrada) {
            $formatado = self::formatarEntrada($entrada);
            
            $data_obj = new DateTime($formatado['data_hora']);
            $agora = new DateTime();
            $diff = $agora->diff($data_obj);
            
            // Tempo relativo
            if ($diff->y > 0) {
                $tempo = $diff->y . ' ano' . ($diff->y > 1 ? 's' : '') . ' atrás';
            } elseif ($diff->m > 0) {
                $tempo = $diff->m . ' mês' . ($diff->m > 1 ? 'es' : '') . ' atrás';
            } elseif ($diff->d > 0) {
                $tempo = $diff->d . ' dia' . ($diff->d > 1 ? 's' : '') . ' atrás';
            } elseif ($diff->h > 0) {
                $tempo = $diff->h . ' hora' . ($diff->h > 1 ? 's' : '') . ' atrás';
            } elseif ($diff->i > 0) {
                $tempo = $diff->i . ' minuto' . ($diff->i > 1 ? 's' : '') . ' atrás';
            } else {
                $tempo = 'Agora';
            }
            
            $data_formatada = $data_obj->format('d/m/Y H:i');
            
            $html .= '
            <div class="historico-item">
                <div class="historico-icon" style="background: ' . $formatado['cor'] . ';">
                    <i class="fas ' . $formatado['icone'] . '"></i>
                </div>
                <div class="historico-content">
                    <div class="historico-text">' . $formatado['texto'] . '</div>
                    <div class="historico-meta">
                        <span class="historico-usuario">
                            <i class="fas fa-user"></i> ' . htmlspecialchars($formatado['usuario']) . '
                        </span>
                        <span class="historico-tempo" title="' . $data_formatada . '">
                            <i class="fas fa-clock"></i> ' . $tempo . '
                        </span>
                    </div>
                </div>
            </div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
}
