<?php
// IMPORTANTE: Configurar sessões ANTES de session_start()
require_once '../../config/database.php';

session_start();
require_once '../../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = $_POST['email'] ?? '';
$senha = $_POST['senha'] ?? '';

if (empty($email) || empty($senha)) {
    $_SESSION['erro'] = 'Por favor, preencha todos os campos.';
    header('Location: login.php');
    exit;
}

if (Auth::login($email, $senha)) {
    header('Location: ../dashboard/');
    exit;
} else {
    $_SESSION['erro'] = 'E-mail ou senha incorretos.';
    header('Location: login.php');
    exit;
}
?>