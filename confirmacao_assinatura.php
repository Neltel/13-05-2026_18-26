<?php
/**
 * Página de confirmação após assinatura
 * URL: https://imperiodoar.com.br/confirmacao_assinatura.php?id=15
 */

session_start();

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados. Contate o suporte.");
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$orcamento = null;

if ($id > 0) {
    $sql = "SELECT o.*, c.nome as cliente_nome, c.email, c.cpf_cnpj
            FROM orcamentos o
            LEFT JOIN clientes c ON o.cliente_id = c.id
            WHERE o.id = ? AND o.assinado = 1";
    
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $orcamento = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

function safeHtml($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

// Gerar token
$cpf_cliente_token = $orcamento['cpf_cnpj'] ?? '';
if (empty($cpf_cliente_token)) {
    $cpf_cliente_token = 'cliente_' . $id;
}
$token = hash('sha256', $id . $cpf_cliente_token . 'contrato_seguro');

// URL COMPLETA com token (funciona!)
$contrato_url = BASE_URL . '/visualizar_contrato.php?id=' . $id . '&token=' . $token;

// Para WhatsApp: URL codificada com %26 no lugar do & (evita corte)
$whatsapp_url = BASE_URL . '/visualizar_contrato.php?id=' . $id . '%26token=' . $token;

$whatsapp_text = " *CONTRATO ASSINADO* - Império AR%0A%0A";
$whatsapp_text .= "Olá, segue meu contrato assinado:%0A";
$whatsapp_text .= " *Contrato:* " . ($orcamento['numero'] ?? '#' . $id) . "%0A";
$whatsapp_text .= " *Cliente:* " . safeHtml($orcamento['cliente_nome']) . "%0A";
$whatsapp_text .= " *Data:* " . date('d/m/Y H:i:s', strtotime($orcamento['data_assinatura'])) . "%0A";
$whatsapp_text .= " *Valor:* R$ " . number_format(floatval($orcamento['valor_total'] ?? 0), 2, ',', '.') . "%0A%0A";
$whatsapp_text .= " *Link para visualizar:* " . $whatsapp_url . "%0A%0A";
$whatsapp_text .= "Império AR - Refrigeração (17) 99624-0725";

$whatsapp_link = "https://wa.me/?text=" . $whatsapp_text;

// URL para home do cliente (NÃO ADMIN)
$home_cliente_url = BASE_URL . '/assinar_contrato.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Contrato Assinado - Império AR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 650px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .sucesso-icon { font-size: 80px; margin-bottom: 20px; animation: bounce 1s infinite; }
        @keyframes bounce { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-10px);} }
        h1 { color: #28a745; margin-bottom: 15px; font-size: 28px; }
        .subtitulo { color: #666; margin-bottom: 30px; font-size: 14px; }
        .detalhes {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
            text-align: left;
        }
        .detalhes p { margin: 8px 0; color: #333; font-size: 14px; }
        .detalhes strong { color: #1e3c72; }
        .acoes { margin: 30px 0; }
        .btn-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 20px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            transition: all 0.3s;
            min-width: 200px;
        }
        .btn-primary { background: #1e3c72; color: white; }
        .btn-primary:hover { background: #2a5298; transform: translateY(-2px); }
        .btn-warning { background: #25D366; color: white; }
        .btn-warning:hover { background: #128C7E; transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; transform: translateY(-2px); }
        .info-box {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        .info-box p { margin: 5px 0; font-size: 13px; color: #1e3c72; }
        .link-box {
            background: #f0f0f0;
            padding: 12px;
            border-radius: 6px;
            margin: 15px 0;
            word-break: break-all;
            font-family: monospace;
            font-size: 12px;
            text-align: left;
        }
        .link-box a { color: #1e3c72; text-decoration: none; }
        .qrcode-box {
            margin: 20px 0;
            padding: 15px;
            background: white;
            border-radius: 8px;
            text-align: center;
        }
        .qrcode-box canvas { max-width: 150px; height: auto; margin: 0 auto; }
        .contato {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #999;
        }
        .contato a { color: #1e3c72; text-decoration: none; }
        .erro {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        @media (max-width: 600px) {
            .container { padding: 25px; }
            h1 { font-size: 24px; }
            .sucesso-icon { font-size: 60px; }
            .btn { width: 100%; min-width: auto; }
            .btn-group { flex-direction: column; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs2-fix/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <?php if ($orcamento && $orcamento['assinado'] == 1): ?>
            <div class="sucesso-icon">✅</div>
            
            <h1>Contrato Assinado com Sucesso!</h1>
            <div class="subtitulo">
                Seu contrato foi assinado digitalmente e está pronto para download
            </div>
            
            <div class="detalhes">
                <p><strong>👤 Cliente:</strong> <?php echo safeHtml($orcamento['cliente_nome']); ?></p>
                <p><strong>📄 Contrato:</strong> <?php echo safeHtml($orcamento['numero'] ?? '#' . $id); ?></p>
                <p><strong>📅 Data da Assinatura:</strong> <?php echo date('d/m/Y H:i:s', strtotime($orcamento['data_assinatura'])); ?></p>
                <p><strong>💰 Valor Total:</strong> <span style="color: #28a745; font-size: 16px;">R$ <?php echo number_format(floatval($orcamento['valor_total'] ?? 0), 2, ',', '.'); ?></span></p>
            </div>
            
            <div class="acoes">
                <h3 style="margin-bottom: 15px; color: #1e3c72;">📄 Ações disponíveis:</h3>
                <div class="btn-group">
                    <a href="<?php echo $contrato_url; ?>" target="_blank" class="btn btn-primary">
                        📄 Baixar / Imprimir Contrato
                    </a>
                    
                    <a href="<?php echo $whatsapp_link; ?>" target="_blank" class="btn btn-warning">
                        💬 Compartilhar no WhatsApp
                    </a>
                </div>
            </div>
            
            <!-- QR Code com link completo -->
            <div class="qrcode-box">
                <p><strong>📱 Acesse pelo celular:</strong></p>
                <div id="qrcode" style="display: flex; justify-content: center;"></div>
                <p style="font-size: 11px; color: #666; margin-top: 8px;">Aponte a câmera do celular para este QR Code</p>
            </div>
            
            <!-- Link completo (funciona perfeitamente) -->
            <div class="link-box">
                <p><strong>🔗 Link do contrato (copie e cole):</strong></p>
                <a href="<?php echo $contrato_url; ?>" target="_blank"><?php echo $contrato_url; ?></a>
            </div>
            
            <div class="info-box">
                <p><strong>ℹ️ Informações importantes:</strong></p>
                <p>✓ O contrato já está assinado digitalmente e tem validade jurídica</p>
                <p>✓ Clique em "Baixar / Imprimir" para visualizar, salvar ou imprimir o documento</p>
                <p>✓ O link acima é permanente e pode ser compartilhado com quem você quiser</p>
            </div>
            
            <a href="<?php echo $home_cliente_url; ?>" class="btn btn-secondary" style="margin-top: 10px;">
                🏠 Voltar para Início
            </a>
            
            <script>
                new QRCode(document.getElementById("qrcode"), {
                    text: "<?php echo $contrato_url; ?>",
                    width: 150,
                    height: 150,
                    colorDark: "#1e3c72",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            </script>
            
        <?php else: ?>
            <div class="erro">
                <strong>❌ Contrato não encontrado</strong>
                <p style="margin-top: 10px;">O contrato solicitado não foi encontrado ou ainda não foi assinado.</p>
                <p style="margin-top: 5px;">Verifique o link ou entre em contato conosco.</p>
            </div>
            
            <a href="<?php echo $home_cliente_url; ?>" class="btn btn-secondary">
                ↩️ Voltar para Assinar Contrato
            </a>
        <?php endif; ?>
        
        <div class="contato">
            <p>📞 Dúvidas? Entre em contato:</p>
            <p>📱 WhatsApp: <strong>(17) 99624-0725</strong></p>
            <p>📧 E-mail: <a href="mailto:contato@imperioar.com.br">contato@imperioar.com.br</a></p>
        </div>
    </div>
</body>
</html>
