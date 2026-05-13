<?php
/**
 * =====================================================================
 * LOGOUT
 * =====================================================================
 * 
 * Responsabilidade: Fazer logout do usuário
 * Recebe: GET request
 * Faz: Limpa sessão e redireciona para home
 */

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

session_start();

// Faz logout
Auth::logout();

// Redireciona para home
header('Location: ' . BASE_URL . '/');
exit;

?>
