<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Iniciando teste...<br>";

require_once '../../../includes/auth.php';
echo "Auth carregado<br>";

Auth::protect();
echo "Autenticado<br>";

require_once '../../../config/database.php';
echo "Database carregado<br>";

echo "Sessão: <pre>";
print_r($_SESSION);
echo "</pre>";

$usuario_id = $_SESSION['usuario_id'];
echo "Usuario ID: $usuario_id<br>";

$nome = 'Teste';
$cor = '#667eea';
$tipo = 'tarefa';

echo "Tentando inserir...<br>";

$sql = "INSERT INTO etiquetas (nome, cor, tipo, criado_por, ativo) VALUES (?, ?, ?, ?, 1)";
executeQuery($sql, [$nome, $cor, $tipo, $usuario_id]);

echo "Inserido!<br>";

global $pdo;
$id = $pdo->lastInsertId();

echo "ID: $id<br>";

echo json_encode(['success' => true, 'etiqueta_id' => $id, 'nome' => $nome, 'cor' => $cor]);
