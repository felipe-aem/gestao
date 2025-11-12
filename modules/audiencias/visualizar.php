<?php
// Redirecionar para visualização unificada
$id = $_GET['id'] ?? 0;
header("Location: ../agenda/visualizar.php?id={$id}&tipo=audiencia");
exit;