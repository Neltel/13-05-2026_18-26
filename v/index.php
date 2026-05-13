<?php
/**
 * URL encurtada para contratos
 * URL: https://imperiodoar.com.br/v/CODIGO_BASE64
 */

// Obter o código da URL
$code = isset($_GET['code']) ? $_GET['code'] : (isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '');

// Se não veio como parâmetro, pegar da URL amigável
if (empty($code) && isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
    $parts = explode('/', trim($uri, '/'));
    $code = end($parts);
}

if (empty($code)) {
    die('Link inválido');
}

// Decodificar
$decoded = base64_decode($code);
if (!$decoded) {
    die('Link inválido');
}

// Separar ID e token
$parts = explode('|', $decoded);
if (count($parts) != 2) {
    die('Link inválido');
}

$id = intval($parts[0]);
$token = $parts[1];

// Redirecionar para o contrato
header('Location: /visualizar_contrato.php?id=' . $id . '&token=' . $token);
exit;
