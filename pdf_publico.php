<?php
// pdf_publico.php - Versão pública para download de PDF
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/PDF.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$token = $_GET['token'] ?? '';

if ($id <= 0 || empty($token)) {
    die("Link inválido");
}

// Verificar token (para segurança básica)
$token_esperado = md5('imperioar_secreto_' . $id . date('Y-m-d'));
if ($token !== $token_esperado) {
    die("Link expirado ou inválido");
}

// Gerar PDF
$pdf = new PDF($conexao);
$pdf->gerarOrcamento($id, true); // true = modo download
?>
