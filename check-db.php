<?php
require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/database.php';

global $conexao;

echo "<pre>";
echo "Conexão: " . ($conexao ? "OK ✓" : "ERRO ✗") . "\n";
echo "Host: " . DB_HOST . "\n";
echo "Banco: " . DB_NAME . "\n";
echo "Usuário: " . DB_USER . "\n\n";

// Testa tabelas
$tabelas = ['usuarios', 'clientes', 'produtos', 'servicos', 'orcamentos', 'vendas', 'agendamentos', 'cobrancas'];

foreach ($tabelas as $tabela) {
    $resultado = $conexao->query("SELECT COUNT(*) as total FROM {$tabela}");
    if ($resultado) {
        $linha = $resultado->fetch_assoc();
        echo "✓ {$tabela}: " . $linha['total'] . " registros\n";
    } else {
        echo "✗ {$tabela}: ERRO\n";
    }
}

echo "</pre>";
?>
