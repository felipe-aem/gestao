<?php
require_once '../../includes/auth.php';

Auth::logout();

$_SESSION['sucesso'] = 'Logout realizado com sucesso!';
header('Location: login.php');
exit;
?>