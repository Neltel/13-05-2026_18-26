<?php
// diagnostico.php - Script para identificar o erro
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnóstico do Sistema</h1>";

// 1. Verificar se consegue incluir o config.php
echo "<h2>1. Testando config.php</h2>";
if (file_exists('../config.php')) {
    echo "✅ config.php encontrado<br>";
    require_once '../config.php';
    echo "✅ config.php incluído com sucesso<br>";
    
    // Verificar se a conexão funciona
    if (isset($conn) && $conn->ping()) {
        echo "✅ Conexão com banco de dados OK<br>";
        
        // Testar query simples
        $test = $conn->query("SELECT COUNT(*) as total FROM produtos");
        if ($test) {
            $row = $test->fetch_assoc();
            echo "✅ Query funcionou! Total de produtos: " . $row['total'] . "<br>";
        } else {
            echo "❌ Erro na query: " . $conn->error . "<br>";
        }
    } else {
        echo "❌ Problema na conexão com banco de dados<br>";
    }
} else {
    echo "❌ config.php NÃO encontrado em ../config.php<br>";
    echo "Buscando em outros locais...<br>";
    
    // Procurar config.php
    $dirs = ['.', '..', '../config', './config', '/home', dirname(__FILE__)];
    foreach ($dirs as $dir) {
        if (file_exists($dir . '/config.php')) {
            echo "✅ Encontrado em: " . $dir . "/config.php<br>";
        }
    }
}

// 2. Verificar a estrutura de pastas
echo "<h2>2. Estrutura de Pastas</h2>";
echo "Diretório atual: " . __DIR__ . "<br>";
echo "Arquivos no diretório atual:<br>";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file != '.' && $file != '..') {
        echo "- " . $file . (is_dir($file) ? " (pasta)" : "") . "<br>";
    }
}

// 3. Verificar configurações do PHP
echo "<h2>3. Configurações do PHP</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "display_errors: " . ini_get('display_errors') . "<br>";
echo "error_reporting: " . error_reporting() . "<br>";

// 4. Testar erro 500 específico
echo "<h2>4. Teste de Sintaxe</h2>";
echo "Script de diagnóstico concluído com sucesso!";
?>