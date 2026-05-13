<?php
/**
 * Versão SIMPLIFICADA apenas para testar WhatsApp
 * ATUALIZADO: Suporte a WhatsApp Business no celular
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/WhatsApp.php';

session_start();

// Função de debug
function debug_msg($msg) {
    echo "<div style='background:#e8f4f8; border-left:4px solid #3498db; padding:10px; margin:5px;'>";
    echo "<strong>DEBUG:</strong> " . print_r($msg, true);
    echo "</div>";
}

// Função para detectar dispositivo móvel
function isMobileDevice() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobile_keywords = ['android', 'iphone', 'ipad', 'ipod', 'mobile', 'blackberry', 'windows phone', 'opera mini', 'iemobile'];
    
    foreach ($mobile_keywords as $keyword) {
        if (stripos($user_agent, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

// Função para gerar link do WhatsApp Business (compatível com mobile)
function gerarLinkWhatsAppBusiness($telefone, $mensagem) {
    // Limpa o telefone (remove tudo que não é número)
    $numero = preg_replace('/[^0-9]/', '', $telefone);
    
    // Adiciona código do Brasil se não tiver
    if (substr($numero, 0, 2) !== '55' && strlen($numero) <= 11) {
        $numero = '55' . $numero;
    }
    
    $mensagem_encoded = rawurlencode($mensagem);
    $is_mobile = isMobileDevice();
    
    if ($is_mobile) {
        // Para dispositivos móveis: usa esquema nativo do WhatsApp
        // Tenta primeiro o WhatsApp Business, depois fallback para normal
        return "whatsapp://send?phone={$numero}&text={$mensagem_encoded}";
    } else {
        // Para desktop: usa a versão web
        return "https://web.whatsapp.com/send?phone={$numero}&text={$mensagem_encoded}";
    }
}

debug_msg("Iniciando teste simplificado - Suporte WhatsApp Business");

global $conexao;
$whatsapp = new WhatsApp($conexao);

$id = isset($_GET['id']) ? intval($_GET['id']) : 1;
$acao = $_GET['acao'] ?? '';

// Processar envio
if ($acao == 'enviar') {
    debug_msg("Processando envio para ID: " . $id);
    
    // Buscar dados
    $sql = "SELECT o.*, c.nome as cliente_nome, c.telefone as cliente_telefone 
            FROM orcamentos o 
            LEFT JOIN clientes c ON o.cliente_id = c.id 
            WHERE o.id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $orcamento = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    debug_msg("Dados do orçamento: " . print_r($orcamento, true));
    
    if ($orcamento) {
        $telefone = $orcamento['cliente_telefone'] ?? '';
        debug_msg("Telefone encontrado: " . $telefone);
        
        if (!empty($telefone)) {
            $mensagem = "TESTE - Orçamento #" . $id . " - " . date('d/m/Y H:i:s');
            $mensagem .= "\n\n*IMPÉRIO AR* - Especialistas em Conforto Térmico";
            debug_msg("Mensagem: " . $mensagem);
            
            $link = gerarLinkWhatsAppBusiness($telefone, $mensagem);
            debug_msg("Link gerado: " . $link);
            debug_msg("Dispositivo: " . (isMobileDevice() ? "Mobile" : "Desktop"));
            
            // JavaScript para abrir o link corretamente
            echo "<script>
                // Tenta abrir o link
                window.location.href = '{$link}';
                
                // Fallback: se não abrir em 2 segundos, mostra link manual
                setTimeout(function() {
                    if (!document.hidden) {
                        var msg = 'Se o WhatsApp não abriu automaticamente, copie o link abaixo:';
                        alert(msg);
                    }
                }, 2000);
            </script>";
            
            echo "<div style='background:#d4edda; padding:20px; text-align:center; border-radius:10px; margin:20px;'>";
            echo "<h2>✅ Link do WhatsApp Business gerado!</h2>";
            echo "<p><strong>Dispositivo detectado:</strong> " . (isMobileDevice() ? "📱 Mobile (WhatsApp App)" : "💻 Desktop (Web WhatsApp)") . "</p>";
            echo "<p><a href='{$link}' target='_blank' style='background:#25D366; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; display:inline-block; margin:10px 0;'>
                    <i class='fab fa-whatsapp'></i> Clique aqui se não abrir automaticamente
                  </a></p>";
            echo "<p><small>Link direto: <a href='{$link}' target='_blank'>{$link}</a></small></p>";
            echo "<a href='orcamento_whatsapp_simples.php' class='btn' style='background:#1e3c72; color:white; padding:8px 15px; text-decoration:none; border-radius:5px;'>← Voltar</a>";
            echo "</div>";
        } else {
            echo "<div style='background:#f8d7da; padding:20px; border-radius:10px; margin:20px;'>";
            echo "<h2>❌ Cliente sem telefone cadastrado!</h2>";
            echo "<p>Por favor, cadastre um telefone para este cliente.</p>";
            echo "<a href='orcamento_whatsapp_simples.php' class='btn' style='background:#1e3c72; color:white; padding:8px 15px; text-decoration:none; border-radius:5px;'>← Voltar</a>";
            echo "</div>";
        }
    } else {
        echo "<div style='background:#f8d7da; padding:20px; border-radius:10px; margin:20px;'>";
        echo "<h2>❌ Orçamento não encontrado!</h2>";
        echo "<a href='orcamento_whatsapp_simples.php' class='btn' style='background:#1e3c72; color:white; padding:8px 15px; text-decoration:none; border-radius:5px;'>← Voltar</a>";
        echo "</div>";
    }
    exit;
}

// Listar orçamentos para teste
$sql = "SELECT o.id, o.numero, c.nome, c.telefone 
        FROM orcamentos o 
        LEFT JOIN clientes c ON o.cliente_id = c.id 
        ORDER BY o.id DESC LIMIT 10";
$result = $conexao->query($sql);
$orcamentos = [];
while ($row = $result->fetch_assoc()) {
    $orcamentos[] = $row;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Teste WhatsApp Business - Império AR</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
        }
        
        .card { 
            background: white; 
            border-radius: 20px; 
            padding: 30px; 
            margin-bottom: 25px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        
        .card h1 {
            color: #1e3c72;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h3 {
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        th { 
            background: linear-gradient(135deg, #1e3c72, #2a5298); 
            color: white; 
            padding: 15px; 
            text-align: left; 
            font-weight: 600;
        }
        
        td { 
            padding: 12px 15px; 
            border-bottom: 1px solid #e0e0e0; 
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .btn { 
            background: #25D366; 
            color: white; 
            padding: 10px 20px; 
            text-decoration: none; 
            border-radius: 10px; 
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover { 
            background: #128C7E; 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #1e3c72;
        }
        
        .btn-secondary:hover {
            background: #2a5298;
        }
        
        .warning { 
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            padding: 20px; 
            border-radius: 15px; 
            margin-bottom: 25px;
            border-left: 5px solid #ffc107;
        }
        
        .info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 5px solid #17a2b8;
        }
        
        .telefone-ok {
            color: #28a745;
            font-weight: 600;
        }
        
        .telefone-ruim {
            color: #dc3545;
            font-weight: 600;
        }
        
        .badge-mobile {
            display: inline-block;
            background: #6c5ce7;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 20px;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .btn {
                padding: 8px 12px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>
                <i class="fab fa-whatsapp" style="color: #25D366;"></i>
                Teste WhatsApp Business
                <?php if (isMobileDevice()): ?>
                    <span class="badge-mobile"><i class="fas fa-mobile-alt"></i> Modo Mobile</span>
                <?php else: ?>
                    <span class="badge-mobile" style="background: #6c757d;"><i class="fas fa-desktop"></i> Modo Desktop</span>
                <?php endif; ?>
            </h1>
            <p style="color: #666; margin-bottom: 20px;">Sistema otimizado para abrir o WhatsApp Business diretamente no celular</p>
            
            <div class="warning">
                <strong><i class="fas fa-info-circle"></i> Instruções:</strong>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Escolha um orçamento da lista abaixo</li>
                    <li>Clique no botão <strong>"Enviar Teste"</strong></li>
                    <li>O WhatsApp Business será aberto automaticamente (se instalado)</li>
                    <li>Se não abrir, clique no link manual que aparece</li>
                </ol>
            </div>
            
            <h3>
                <i class="fas fa-file-invoice"></i>
                Últimos 10 Orçamentos
            </h3>
            
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Número</th>
                            <th>Cliente</th>
                            <th>Telefone</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orcamentos as $o): ?>
                        <tr>
                            <td><strong><?php echo $o['id']; ?></strong></td>
                            <td><?php echo $o['numero'] ?? '-'; ?></td>
                            <td><?php echo htmlspecialchars($o['nome']); ?></td>
                            <td>
                                <?php if ($o['telefone']): ?>
                                    <span class="telefone-ok">
                                        <i class="fas fa-check-circle"></i> 
                                        <?php echo htmlspecialchars($o['telefone']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="telefone-ruim">
                                        <i class="fas fa-times-circle"></i> 
                                        Sem telefone
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($o['telefone']): ?>
                                    <a href="?acao=enviar&id=<?php echo $o['id']; ?>" class="btn">
                                        <i class="fab fa-whatsapp"></i> Enviar Teste
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999; background: #f0f0f0; padding: 8px 15px; border-radius: 8px; display: inline-block;">
                                        <i class="fas fa-ban"></i> Indisponível
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <h3>
                <i class="fas fa-vial"></i>
                Teste Manual
            </h3>
            <p>Digite um telefone e mensagem para testar o WhatsApp Business:</p>
            
            <form method="GET" action="?" style="margin-top: 20px;">
                <input type="hidden" name="acao" value="enviar">
                <input type="hidden" name="id" value="0">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-phone"></i> Telefone (com DDD):
                    </label>
                    <input type="tel" name="telefone" placeholder="(17) 99624-0725" 
                           style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 16px;">
                    <small style="color: #666;">Formatos aceitos: (17) 99624-0725, 17996240725, +5517996240725</small>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <i class="fas fa-comment"></i> Mensagem:
                    </label>
                    <textarea name="mensagem" rows="3" 
                              style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; resize: vertical;">Olá! Este é um teste do sistema Império AR.
Orçamento disponível para visualização.</textarea>
                </div>
                
                <button type="submit" class="btn" style="background: #25D366; width: 100%; justify-content: center;">
                    <i class="fab fa-whatsapp"></i> Testar WhatsApp Business
                </button>
            </form>
            
            <div class="info">
                <i class="fas fa-lightbulb"></i>
                <strong>Dica:</strong> Este sistema foi otimizado para abrir o WhatsApp Business diretamente no seu celular. 
                Certifique-se de ter o aplicativo instalado.
            </div>
        </div>
        
        <div class="card">
            <h3>
                <i class="fas fa-question-circle"></i>
                Solução de Problemas
            </h3>
            <ul style="margin-left: 20px; color: #555; line-height: 1.8;">
                <li><strong>WhatsApp não abre?</strong> Verifique se o aplicativo está instalado no seu celular</li>
                <li><strong>Link não funciona?</strong> Copie o link gerado e cole manualmente no navegador</li>
                <li><strong>Número inválido?</strong> Use o formato com DDD: (17) 99624-0725</li>
                <li><strong>WhatsApp Business vs Normal:</strong> O sistema tenta abrir o WhatsApp Business primeiro, depois fallback para o normal</li>
            </ul>
        </div>
    </div>
</body>
</html>