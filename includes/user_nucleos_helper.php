<?php
/**
 * Helper para gerenciamento de núcleos de usuários
 * Adicione este arquivo em includes/user_nucleos_helper.php
 */

class UserNucleosHelper {
    
    /**
     * Obter todos os núcleos ativos
     */
    public static function getNucleosAtivos() {
        $sql = "SELECT id, nome, descricao, ativo FROM nucleos WHERE ativo = 1 ORDER BY nome";
        return executeQuery($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter núcleos de um usuário específico
     */
    public static function getNucleosUsuario($usuario_id) {
        $sql = "SELECT n.id, n.nome, n.descricao, un.data_vinculo 
                FROM nucleos n
                INNER JOIN usuario_nucleos un ON n.id = un.nucleo_id
                WHERE un.usuario_id = ? AND un.ativo = 1 AND n.ativo = 1
                ORDER BY n.nome";
        return executeQuery($sql, [$usuario_id])->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar se usuário tem acesso a um núcleo específico
     */
    public static function usuarioTemAcessoNucleo($usuario_id, $nucleo_id) {
        $sql = "SELECT COUNT(*) as total 
                FROM usuario_nucleos un
                INNER JOIN nucleos n ON un.nucleo_id = n.id
                WHERE un.usuario_id = ? AND un.nucleo_id = ? 
                AND un.ativo = 1 AND n.ativo = 1";
        $result = executeQuery($sql, [$usuario_id, $nucleo_id])->fetch();
        return $result['total'] > 0;
    }
    
    /**
     * Atribuir núcleos a um usuário (substitui os existentes)
     */
    public static function atribuirNucleosUsuario($usuario_id, $nucleos_ids) {
        try {
            $GLOBALS['pdo']->beginTransaction();
            
            // Desativar todos os vínculos existentes
            $sql_desativar = "UPDATE usuario_nucleos SET ativo = 0 WHERE usuario_id = ?";
            executeQuery($sql_desativar, [$usuario_id]);
            
            // Inserir/ativar novos vínculos
            if (!empty($nucleos_ids)) {
                $sql_insert = "INSERT INTO usuario_nucleos (usuario_id, nucleo_id, ativo) 
                              VALUES (?, ?, 1)
                              ON DUPLICATE KEY UPDATE ativo = 1, data_vinculo = NOW()";
                
                foreach ($nucleos_ids as $nucleo_id) {
                    executeQuery($sql_insert, [$usuario_id, intval($nucleo_id)]);
                }
            }
            
            $GLOBALS['pdo']->commit();
            return true;
            
        } catch (Exception $e) {
            $GLOBALS['pdo']->rollBack();
            throw $e;
        }
    }
    
    /**
     * Adicionar núcleo a um usuário (mantém os existentes)
     */
    public static function adicionarNucleoUsuario($usuario_id, $nucleo_id) {
        $sql = "INSERT INTO usuario_nucleos (usuario_id, nucleo_id, ativo) 
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE ativo = 1, data_vinculo = NOW()";
        return executeQuery($sql, [$usuario_id, $nucleo_id]);
    }
    
    /**
     * Remover núcleo de um usuário
     */
    public static function removerNucleoUsuario($usuario_id, $nucleo_id) {
        $sql = "UPDATE usuario_nucleos SET ativo = 0 
                WHERE usuario_id = ? AND nucleo_id = ?";
        return executeQuery($sql, [$usuario_id, $nucleo_id]);
    }
    
    /**
     * Obter usuários de um núcleo específico
     */
    public static function getUsuariosNucleo($nucleo_id, $nivel_minimo = null) {
        $where_nivel = "";
        $params = [$nucleo_id];
        
        if ($nivel_minimo) {
            $niveis_hierarquia = [
                'Assistente' => 1,
                'Advogado' => 2,
                'Gestor' => 3,
                'Diretor' => 4,
                'Socio' => 5,
                'Admin' => 6
            ];
            
            $nivel_num = $niveis_hierarquia[$nivel_minimo] ?? 1;
            $niveis_validos = array_keys(array_filter($niveis_hierarquia, function($v) use ($nivel_num) {
                return $v >= $nivel_num;
            }));
            
            $placeholders = str_repeat('?,', count($niveis_validos) - 1) . '?';
            $where_nivel = " AND u.nivel_acesso IN ($placeholders)";
            $params = array_merge($params, $niveis_validos);
        }
        
        $sql = "SELECT u.id, u.nome, u.email, u.nivel_acesso, un.data_vinculo
                FROM usuarios u
                INNER JOIN usuario_nucleos un ON u.id = un.usuario_id
                WHERE un.nucleo_id = ? AND un.ativo = 1 AND u.ativo = 1 $where_nivel
                ORDER BY u.nome";
        
        return executeQuery($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter estatísticas dos núcleos
     */
    public static function getEstatisticasNucleos() {
        $sql = "SELECT 
                    n.id,
                    n.nome,
                    n.descricao,
                    COUNT(CASE WHEN un.ativo = 1 AND u.ativo = 1 THEN 1 END) as total_usuarios,
                    COUNT(CASE WHEN un.ativo = 1 AND u.ativo = 1 AND u.nivel_acesso IN ('Admin', 'Socio', 'Diretor') THEN 1 END) as gestores,
                    COUNT(CASE WHEN un.ativo = 1 AND u.ativo = 1 AND u.nivel_acesso = 'Advogado' THEN 1 END) as advogados,
                    COUNT(CASE WHEN un.ativo = 1 AND u.ativo = 1 AND u.nivel_acesso = 'Assistente' THEN 1 END) as assistentes
                FROM nucleos n
                LEFT JOIN usuario_nucleos un ON n.id = un.nucleo_id
                LEFT JOIN usuarios u ON un.usuario_id = u.id
                WHERE n.ativo = 1
                GROUP BY n.id, n.nome, n.descricao
                ORDER BY n.nome";
        
        return executeQuery($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar se usuário pode gerenciar núcleo
     */
    public static function usuarioPodeGerenciarNucleo($usuario_id, $nucleo_id = null) {
        // Obter dados do usuário
        $sql_user = "SELECT nivel_acesso FROM usuarios WHERE id = ? AND ativo = 1";
        $usuario = executeQuery($sql_user, [$usuario_id])->fetch();
        
        if (!$usuario) return false;
        
        // Níveis que podem gerenciar qualquer núcleo
        $niveis_admin = ['Admin', 'Socio'];
        if (in_array($usuario['nivel_acesso'], $niveis_admin)) {
            return true;
        }
        
        // Diretores e Gestores podem gerenciar núcleos aos quais têm acesso
        if (in_array($usuario['nivel_acesso'], ['Diretor', 'Gestor'])) {
            if ($nucleo_id) {
                return self::usuarioTemAcessoNucleo($usuario_id, $nucleo_id);
            }
            return true; // Pode gerenciar ao menos alguns núcleos
        }
        
        return false;
    }
    
    /**
     * Obter hierarquia de núcleos (para futuras implementações)
     */
    public static function getHieraquiaNiveis() {
        return [
            'Admin' => [
                'level' => 6,
                'label' => 'Administrador',
                'permissions' => ['all']
            ],
            'Socio' => [
                'level' => 5,
                'label' => 'Sócio',
                'permissions' => ['manage_all', 'view_all', 'reports']
            ],
            'Diretor' => [
                'level' => 4,
                'label' => 'Diretor',
                'permissions' => ['manage_nucleos', 'view_nucleos', 'reports']
            ],
            'Gestor' => [
                'level' => 3,
                'label' => 'Gestor',
                'permissions' => ['manage_cases', 'view_nucleos']
            ],
            'Advogado' => [
                'level' => 2,
                'label' => 'Advogado',
                'permissions' => ['manage_own_cases', 'view_nucleos']
            ],
            'Assistente' => [
                'level' => 1,
                'label' => 'Assistente',
                'permissions' => ['view_cases', 'support']
            ]
        ];
    }
    
    /**
     * Filtrar query por núcleos do usuário
     */
    public static function aplicarFiltroNucleos($base_query, $usuario_id, $alias_nucleo = 'nucleo_id') {
        // Se for admin/socio, não aplica filtro
        $sql_user = "SELECT nivel_acesso FROM usuarios WHERE id = ? AND ativo = 1";
        $usuario = executeQuery($sql_user, [$usuario_id])->fetch();
        
        if ($usuario && in_array($usuario['nivel_acesso'], ['Admin', 'Socio'])) {
            return $base_query;
        }
        
        // Aplicar filtro de núcleos
        $nucleos_usuario = self::getNucleosUsuario($usuario_id);
        if (empty($nucleos_usuario)) {
            return $base_query . " AND 1=0"; // Não tem acesso a nenhum núcleo
        }
        
        $nucleos_ids = array_column($nucleos_usuario, 'id');
        $placeholders = str_repeat('?,', count($nucleos_ids) - 1) . '?';
        
        return $base_query . " AND $alias_nucleo IN ($placeholders)";
    }
    
    /**
     * Validar se núcleos existem e estão ativos
     */
    public static function validarNucleos($nucleos_ids) {
        if (empty($nucleos_ids)) {
            return false;
        }
        
        $nucleos_ids = array_map('intval', $nucleos_ids);
        $placeholders = str_repeat('?,', count($nucleos_ids) - 1) . '?';
        
        $sql = "SELECT COUNT(*) as total FROM nucleos 
                WHERE id IN ($placeholders) AND ativo = 1";
        $result = executeQuery($sql, $nucleos_ids)->fetch();
        
        return $result['total'] === count($nucleos_ids);
    }
}

/**
 * Funções auxiliares para templates
 */

/**
 * Renderizar seletor de núcleos
 */
function renderNucleosSelector($name = 'nucleos[]', $selected = [], $required = false) {
    $nucleos = UserNucleosHelper::getNucleosAtivos();
    $html = '<div class="nucleos-grid">';
    
    foreach ($nucleos as $nucleo) {
        $checked = in_array($nucleo['id'], $selected) ? 'checked' : '';
        $html .= sprintf(
            '<div class="nucleo-option" onclick="toggleNucleo(%d)">
                <input type="checkbox" id="nucleo_%d" name="%s" value="%d" %s %s>
                <div class="nucleo-info">
                    <div class="nucleo-nome">%s</div>
                    <div class="nucleo-desc">%s</div>
                </div>
            </div>',
            $nucleo['id'],
            $nucleo['id'],
            $name,
            $nucleo['id'],
            $checked,
            $required ? 'required' : '',
            htmlspecialchars($nucleo['nome']),
            htmlspecialchars($nucleo['descricao'] ?? '')
        );
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * Renderizar badges de núcleos
 */
function renderNucleosBadges($nucleos) {
    if (empty($nucleos)) {
        return '<span class="badge badge-secondary">Nenhum núcleo</span>';
    }
    
    $html = '';
    foreach ($nucleos as $nucleo) {
        $nome = is_array($nucleo) ? $nucleo['nome'] : $nucleo;
        $html .= sprintf('<span class="badge badge-primary">%s</span> ', htmlspecialchars($nome));
    }
    
    return $html;
}

/**
 * Verificar permissão para ação em núcleo
 */
function checkNucleoPermission($usuario_id, $nucleo_id, $action = 'view') {
    $permissions = [
        'view' => ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado', 'Assistente'],
        'edit' => ['Admin', 'Socio', 'Diretor', 'Gestor'],
        'manage' => ['Admin', 'Socio', 'Diretor'],
        'admin' => ['Admin', 'Socio']
    ];
    
    // Obter nível do usuário
    $sql = "SELECT nivel_acesso FROM usuarios WHERE id = ? AND ativo = 1";
    $usuario = executeQuery($sql, [$usuario_id])->fetch();
    
    if (!$usuario) return false;
    
    // Verificar se o nível tem permissão para a ação
    $allowed_levels = $permissions[$action] ?? [];
    if (!in_array($usuario['nivel_acesso'], $allowed_levels)) {
        return false;
    }
    
    // Se for admin/socio, tem acesso total
    if (in_array($usuario['nivel_acesso'], ['Admin', 'Socio'])) {
        return true;
    }
    
    // Verificar se tem acesso ao núcleo específico
    return UserNucleosHelper::usuarioTemAcessoNucleo($usuario_id, $nucleo_id);
}
?>