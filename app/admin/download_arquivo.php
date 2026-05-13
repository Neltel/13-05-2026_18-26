<?php
// download_arquivo.php
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    die('Acesso negado!');
}

$conn = getConnection();
$id = intval($_GET['id'] ?? 0);

$query = "SELECT caminho_arquivo, nome_original FROM financeiro_arquivos WHERE id = $id";
$result = $conn->query($query);

if ($result && $row = $result->fetch_assoc()) {
    $caminho = $row['caminho_arquivo'];
    $nome_original = $row['nome_original'] ?? basename($caminho);
    
    if (file_exists($caminho)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $nome_original . '"');
        header('Content-Length: ' . filesize($caminho));
        readfile($caminho);
        exit;
    }
}

die('Arquivo não encontrado!');
?>