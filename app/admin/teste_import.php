<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Diagnóstico do Importar Extrato</h2>";

// Teste 1: Verificar se o arquivo existe
echo "<h3>1. Verificando arquivos:</h3>";
$arquivos = ['importar_extrato.php', 'financeiro.php', 'gestao_fiscal.php'];
foreach ($arquivos as $arq) {
    if (file_exists($arq)) {
        echo "✅ $arq encontrado<br>";
    } else {
        echo "❌ $arq NÃO encontrado<br>";
    }
}

// Teste 2: Verificar conexão com banco
echo "<h3>2. Testando conexão:</h3>";
require_once __DIR__ . '/../../config/database.php';
global $conexao;

if (isset($conexao) && $conexao) {
    echo "✅ Conexão com banco OK<br>";
    
    // Verificar tabela financeiro
    $result = $conexao->query("SHOW TABLES LIKE 'financeiro'");
    if ($result && $result->num_rows > 0) {
        echo "✅ Tabela financeiro existe<br>";
        
        // Contar registros
        $count = $conexao->query("SELECT COUNT(*) as total FROM financeiro")->fetch_assoc();
        echo "📊 Total de registros no financeiro: " . $count['total'] . "<br>";
    } else {
        echo "❌ Tabela financeiro NÃO encontrada<br>";
    }
} else {
    echo "❌ Falha na conexão com banco<br>";
}

// Teste 3: Verificar sintaxe do arquivo importar_extrato.php
echo "<h3>3. Verificando sintaxe:</h3>";
$content = file_get_contents('importar_extrato.php');
$lines = explode("\n", $content);
$line_count = count($lines);
echo "Arquivo tem $line_count linhas<br>";

// Verificar se tem erro de sintaxe básico
if (strpos($content, '<?php') !== false) {
    echo "✅ Tag PHP encontrada<br>";
} else {
    echo "❌ Tag PHP NÃO encontrada<br>";
}

if (strpos($content, 'session_start()') !== false) {
    echo "✅ session_start() encontrado<br>";
} else {
    echo "❌ session_start() NÃO encontrado<br>";
}

echo "<h3>Status final:</h3>";
echo "Se você está vendo esta mensagem, o diagnóstico funcionou!";
?>