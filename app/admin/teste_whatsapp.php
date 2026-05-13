<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';

// Função de debug
function debug_log($msg) {
    echo "<pre style='background:#f0f0f0; padding:10px; margin:5px; border-left:3px solid blue;'>";
    echo "[DEBUG] " . print_r($msg, true);
    echo "</pre>";
}

debug_log("Iniciando teste do WhatsApp");

// Testar conexão
global $conexao;
if (!$conexao) {
    die("ERRO: Conexão com banco de dados falhou");
}
debug_log("Conexão OK");

// Testar classe WhatsApp
$whatsapp = new WhatsApp($conexao);
debug_log("Classe WhatsApp instanciada");

// Telefone de teste (substitua pelo seu número)
$telefone_teste = "17996240725"; // Seu número sem formatação
$mensagem_teste = "Teste do sistema Império AR - " . date('d/m/Y H:i:s');

debug_log("Telefone: " . $telefone_teste);
debug_log("Mensagem: " . $mensagem_teste);

// Gerar link
$link = $whatsapp->gerarLink($telefone_teste, $mensagem_teste);
debug_log("Link gerado: " . $link);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Teste WhatsApp</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; margin: 0 auto; }
        h1 { color: #25D366; }
        .btn { display: inline-block; padding: 10px 20px; background: #25D366; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .btn:hover { background: #128C7E; }
        .debug { background: #f0f0f0; padding: 10px; border-radius: 5px; margin: 10px 0; font-family: monospace; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h1>📱 Teste do WhatsApp</h1>
        
        <div class="debug">
            <strong>Link gerado:</strong><br>
            <a href="<?php echo $link; ?>" target="_blank"><?php echo $link; ?></a>
        </div>
        
        <div>
            <a href="<?php echo $link; ?>" class="btn" target="_blank">
                <i class="fab fa-whatsapp"></i> Abrir WhatsApp (Teste)
            </a>
            
            <a href="?acao=redirecionar" class="btn" style="background: #1e3c72;">
                <i class="fas fa-redirect"></i> Redirecionar
            </a>
        </div>
        
        <?php
        if (isset($_GET['acao']) && $_GET['acao'] == 'redirecionar') {
            debug_log("Redirecionando para: " . $link);
            header("Location: " . $link);
            exit;
        }
        ?>
        
        <hr>
        
        <h3>Instruções:</h3>
        <ol>
            <li>Clique no botão "Abrir WhatsApp"</li>
            <li>Verifique se abriu o WhatsApp Web/App</li>
            <li>Verifique se a mensagem de teste aparece</li>
            <li>Se não funcionar, veja o link gerado e tente copiar/colar no navegador</li>
        </ol>
    </div>
</body>
</html>
