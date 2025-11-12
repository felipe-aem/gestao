<?php
require_once '../../../config/database.php';

echo "Testando variáveis PDO:<br><br>";

echo "1. \$GLOBALS['pdo']: ";
var_dump(isset($GLOBALS['pdo']));
echo "<br>";

echo "2. \$pdo: ";
var_dump(isset($pdo));
echo "<br>";

echo "3. Todas as variáveis globais com 'pdo':<br>";
foreach ($GLOBALS as $key => $value) {
    if (stripos($key, 'pdo') !== false) {
        echo "   - $key: " . gettype($value) . "<br>";
    }
}

echo "<br>4. Testando executeQuery:<br>";
try {
    $result = executeQuery("SELECT 1 as test");
    echo "executeQuery funciona!<br>";
    var_dump($result->fetch());
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}