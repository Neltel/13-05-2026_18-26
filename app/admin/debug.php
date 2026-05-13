<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Teste de Erros</h2>";

// Testar conexão
require_once '../../config/database.php';
echo "✅ database.php carregado<br>";

// Testar conexão
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo "❌ Erro conexão: " . $conn->connect_error . "<br>";
} else {
    echo "✅ Conexão OK<br>";
}

// Testar session
session_start();
echo "✅ Session OK<br>";

echo "<h3>PHP está funcionando corretamente!</h3>";
?>