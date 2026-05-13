<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Teste Completo do Sistema Fiscal</h2>";

// Teste 1: Verificar database.php
echo "<h3>1. Procurando database.php:</h3>";
$caminhos = [
    '../../config/database.php',
    '../config/database.php',
    'config/database.php'
];

foreach ($caminhos as $caminho) {
    if (file_exists($caminho)) {
        echo "✅ Encontrado: $caminho<br>";
        require_once $caminho;
        break;
    } else {
        echo "❌ Não encontrado: $caminho<br>";
    }
}

// Teste 2: Verificar função getConnection
echo "<h3>2. Testando conexão:</h3>";
if (function_exists('getConnection')) {
    echo "✅ Função getConnection existe<br>";
    $conn = getConnection();
    if ($conn) {
        echo "✅ Conexão com banco OK!<br>";
        
        // Mostrar tabelas existentes
        $tables = $conn->query("SHOW TABLES");
        echo "<h3>3. Tabelas no banco:</h3><ul>";
        while($row = $tables->fetch_array()) {
            echo "<li>" . $row[0] . "</li>";
        }
        echo "</ul>";
        
        // Verificar se as novas tabelas foram criadas
        echo "<h3>4. Verificando tabelas do módulo fiscal:</h3>";
        $novas_tabelas = ['ajudantes', 'financeiro_arquivos', 'pagamentos_ajudantes', 'categorias_fiscais'];
        foreach ($novas_tabelas as $tabela) {
            $check = $conn->query("SHOW TABLES LIKE '$tabela'");
            if ($check && $check->num_rows > 0) {
                echo "✅ Tabela '$tabela' existe<br>";
            } else {
                echo "❌ Tabela '$tabela' NÃO existe - Execute o script SQL no phpMyAdmin<br>";
            }
        }
        
    } else {
        echo "❌ Falha na conexão<br>";
    }
} else {
    echo "❌ Função getConnection não encontrada<br>";
}

// Teste 5: Sessão
echo "<h3>5. Testando Sessão:</h3>";
session_start();
if (isset($_SESSION['usuario_id'])) {
    echo "✅ Usuário logado: " . $_SESSION['usuario_id'] . "<br>";
} else {
    echo "⚠️ Nenhum usuário logado (você precisa fazer login primeiro)<br>";
    echo "👉 <a href='../../login.php'>Fazer Login</a><br>";
}

echo "<h3>Status do Sistema:</h3>";
echo "Se você está vendo esta mensagem, o PHP está funcionando corretamente!";
?>