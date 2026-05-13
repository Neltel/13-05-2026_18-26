<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

session_start();

if (!Auth::isLogado() || !Auth::isAdmin()) {
    http_response_code(403);
    exit('Acesso negado');
}

$campo = $_GET['campo'] ?? '';
$valor = $_GET['valor'] ?? '';
$id_atual = isset($_GET['id']) ? intval($_GET['id']) : 0;

$response = ['exists' => false, 'message' => ''];

if (empty($valor)) {
    echo json_encode($response);
    exit;
}

global $conexao;

switch ($campo) {
    case 'cpf_cnpj':
        $valor = preg_replace('/\D/', '', $valor);
        $sql = "SELECT nome FROM clientes WHERE cpf_cnpj = ?";
        if ($id_atual > 0) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $conexao->prepare($sql);
        if ($id_atual > 0) {
            $stmt->bind_param("si", $valor, $id_atual);
        } else {
            $stmt->bind_param("s", $valor);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $response['exists'] = true;
            $response['message'] = "⚠️ Este CPF/CNPJ já pertence a: " . htmlspecialchars($cliente['nome']);
        }
        $stmt->close();
        break;
        
    case 'email':
        $sql = "SELECT nome FROM clientes WHERE email = ?";
        if ($id_atual > 0) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $conexao->prepare($sql);
        if ($id_atual > 0) {
            $stmt->bind_param("si", $valor, $id_atual);
        } else {
            $stmt->bind_param("s", $valor);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $response['exists'] = true;
            $response['message'] = "⚠️ Este Email já pertence a: " . htmlspecialchars($cliente['nome']);
        }
        $stmt->close();
        break;
        
    case 'telefone':
        $valor = preg_replace('/\D/', '', $valor);
        $sql = "SELECT nome FROM clientes WHERE telefone = ?";
        if ($id_atual > 0) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $conexao->prepare($sql);
        if ($id_atual > 0) {
            $stmt->bind_param("si", $valor, $id_atual);
        } else {
            $stmt->bind_param("s", $valor);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $response['exists'] = true;
            $response['message'] = "⚠️ Este Telefone já pertence a: " . htmlspecialchars($cliente['nome']);
        }
        $stmt->close();
        break;
        
    case 'whatsapp':
        $valor = preg_replace('/\D/', '', $valor);
        $sql = "SELECT nome FROM clientes WHERE whatsapp = ?";
        if ($id_atual > 0) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $conexao->prepare($sql);
        if ($id_atual > 0) {
            $stmt->bind_param("si", $valor, $id_atual);
        } else {
            $stmt->bind_param("s", $valor);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $cliente = $result->fetch_assoc();
            $response['exists'] = true;
            $response['message'] = "⚠️ Este WhatsApp já pertence a: " . htmlspecialchars($cliente['nome']);
        }
        $stmt->close();
        break;
}

echo json_encode($response);
?>
