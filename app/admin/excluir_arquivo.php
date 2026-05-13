<?php
// excluir_arquivo.php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    die('Acesso negado!');
}

$conn = getConnection();
$id = intval($_GET['id'] ?? 0);

// Buscar caminho do arquivo
$query = "SELECT caminho_arquivo FROM financeiro_arquivos WHERE id = $id";
$result = $conn->query($query);

if ($result && $row = $result->fetch_assoc()) {
    $caminho = $row['caminho_arquivo'];
    
    // Deletar arquivo físico
    if (file_exists($caminho)) {
        unlink($caminho);
    }
    
    // Deletar registro
    $conn->query("DELETE FROM financeiro_arquivos WHERE id = $id");
}

header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
?>