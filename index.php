<?php
// index.php (da raiz do projeto)

require_once 'includes/auth.php';

// Se estiver logado, redireciona para o dashboard
if (Auth::check()) {
    header('Location: modules/dashboard/');
} else {
    header('Location: modules/auth/login.php');
}
exit;
?>