<?php
// config/logger_config.php

return [
    // Configurações gerais
    'enabled' => true,
    'default_level' => 'INFO',
    'timezone' => 'America/Sao_Paulo',
    
    // Configurações de arquivo de backup (caso BD falhe)
    'file_backup' => [
        'enabled' => true,
        'path' => __DIR__ . '/../logs/',
        'max_size' => 10 * 1024 * 1024, // 10MB
        'rotation' => true
    ],
    
    // Configurações de performance
    'performance' => [
        'slow_query_threshold' => 2.0, // segundos
        'memory_threshold' => 50 * 1024 * 1024, // 50MB
        'batch_insert' => true,
        'batch_size' => 100
    ],
    
    // Configurações de segurança
    'security' => [
        'encrypt_sensitive_data' => true,
        'hash_ips' => false, // true para anonimizar IPs
        'max_log_size' => 10000, // caracteres
        'sanitize_input' => true
    ],
    
    // Configurações de notificação
    'notifications' => [
        'email' => [
            'enabled' => true,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_user' => 'seu-email@gmail.com',
            'smtp_pass' => 'sua-senha-app',
            'admin_emails' => ['admin@empresa.com', 'ti@empresa.com']
        ],
        'webhook' => [
            'enabled' => false,
            'url' => 'https://hooks.slack.com/services/...',
            'timeout' => 5
        ]
    ],
    
    // Níveis de log e suas configurações
    'levels' => [
        'DEBUG' => [
            'value' => 1,
            'color' => '#6c757d',
            'notify' => false,
            'file_backup' => false
        ],
        'INFO' => [
            'value' => 2,
            'color' => '#17a2b8',
            'notify' => false,
            'file_backup' => false
        ],
        'WARNING' => [
            'value' => 3,
            'color' => '#ffc107',
            'notify' => false,
            'file_backup' => true
        ],
        'ERROR' => [
            'value' => 4,
            'color' => '#dc3545',
            'notify' => true,
            'file_backup' => true
        ],
        'CRITICAL' => [
            'value' => 5,
            'color' => '#721c24',
            'notify' => true,
            'file_backup' => true
        ]
    ],
    
    // Configurações por categoria
    'categories' => [
        'AUTH' => [
            'retention_days' => 365,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => true
        ],
        'SECURITY' => [
            'retention_days' => 730,
            'notify_on' => ['WARNING', 'ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => true
        ],
        'ADMIN' => [
            'retention_days' => 365,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => false
        ],
        'USER' => [
            'retention_days' => 180,
            'notify_on' => ['CRITICAL'],
            'file_backup' => false,
            'real_time_alerts' => false
        ],
        'SYSTEM' => [
            'retention_days' => 90,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => true
        ],
        'ERROR' => [
            'retention_days' => 365,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => true
        ],
        'DATA' => [
            'retention_days' => 365,
            'notify_on' => ['WARNING', 'ERROR', 'CRITICAL'],
            'file_backup' => true,
            'real_time_alerts' => false
        ],
        'FILE' => [
            'retention_days' => 90,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => false,
            'real_time_alerts' => false
        ],
        'EMAIL' => [
            'retention_days' => 90,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => false,
            'real_time_alerts' => false
        ],
        'API' => [
            'retention_days' => 90,
            'notify_on' => ['ERROR', 'CRITICAL'],
            'file_backup' => false,
            'real_time_alerts' => false
        ]
    ]
];