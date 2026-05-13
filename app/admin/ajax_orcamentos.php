<?php
/**
 * AJAX para carregar orçamentos por cliente com saldo pendente
 */
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;

if ($cliente_id <= 0) {
    echo json_encode(['error' => 'Cliente não informado']);
    exit;
}

// Buscar orçamentos com saldo pendente
$sql = "SELECT 
            o.id, 
            o.numero, 
            o.valor_total, 
            o.situacao,
            o.data_emissao,
            c.nome as cliente_nome,
            COALESCE(SUM(cob.valor), 0) as total_pago
        FROM orcamentos o
        LEFT JOIN clientes c ON o.cliente_id = c.id
        LEFT JOIN cobrancas cob ON o.id = cob.orcamento_id AND cob.status = 'recebida'
        WHERE o.cliente_id = $cliente_id 
        AND o.situacao IN ('pendente', 'aprovado')
        GROUP BY o.id
        HAVING o.valor_total > total_pago
        ORDER BY o.data_emissao ASC
        LIMIT 20";

$result = $conexao->query($sql);
$orcamentos = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $saldo_pendente = $row['valor_total'] - $row['total_pago'];
        $orcamentos[] = [
            'id' => $row['id'],
            'numero' => $row['numero'],
            'valor_total' => $row['valor_total'],
            'total_pago' => $row['total_pago'],
            'saldo_pendente' => $saldo_pendente,
            'cliente_nome' => $row['cliente_nome'],
            'situacao' => $row['situacao'],
            'data_emissao' => $row['data_emissao']
        ];
    }
}

echo json_encode($orcamentos);
?>