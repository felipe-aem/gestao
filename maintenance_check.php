<?php
// Inicia sessão SE ainda não foi iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define a senha secreta
$senha_bypass = '11220190032'; // Troque por algo seguro

// Verifica se tem a senha na URL
if (isset($_GET['access']) && $_GET['access'] === $senha_bypass) {
    $_SESSION['bypass_maintenance'] = true;
}

// Logout da sessão
if (isset($_GET['logout'])) {
    unset($_SESSION['bypass_maintenance']);
    session_destroy();
    header('Location: /');
    exit;
}

// Se não tem permissão, mostra manutenção
if (!isset($_SESSION['bypass_maintenance']) || $_SESSION['bypass_maintenance'] !== true) {
    http_response_code(503);
    
    $maintenance_file = __DIR__ . '/maintenance.html';
    
    if (file_exists($maintenance_file)) {
        readfile($maintenance_file);
    } else {
        echo '<h1>Site em Manutenção</h1>';
        echo '<p>Voltamos em breve!</p>';
    }
    exit;
}
?>