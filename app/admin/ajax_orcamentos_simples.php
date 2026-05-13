<?php
/**
 * AJAX simplificado para carregar orçamentos
 */
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;

if ($cliente_id <= 0) {
    echo json_encode([]);
    exit;
}

// Query simples
$sql = "SELECT id, numero FROM orcamentos WHERE cliente_id = $cliente_id AND situacao IN ('pendente', 'aprovado') ORDER BY id DESC LIMIT 20";
$result = $conexao->query($sql);

$orcamentos = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $orcamentos[] = $row;
    }
}

echo json_encode($orcamentos);
?>