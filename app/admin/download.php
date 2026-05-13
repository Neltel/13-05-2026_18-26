<?php
// download.php - Acesso seguro para baixar arquivos
require_once '../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_tipo'] !== 'admin') {
    die('Acesso negado!');
}

$arquivo_id = intval($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'arquivo';

if ($tipo === 'recibo') {
    $query = "SELECT recibo_pdf as caminho FROM pagamentos_ajudantes WHERE id = $arquivo_id";
} else {
    $query = "SELECT caminho_arquivo as caminho FROM financeiro_arquivos WHERE id = $arquivo_id";
}

$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $caminho = $row['caminho'];
    if (file_exists($caminho)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
        readfile($caminho);
        exit;
    }
}
die('Arquivo não encontrado!');
?>