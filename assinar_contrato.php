<?php
/**
 * =====================================================================
 * SISTEMA DE ASSINATURA DIGITAL DE CONTRATOS - VERSÃO v7
 * =====================================================================
 * 
 * URL: https://imperiodoar.com.br/assinar_contrato.php
 */

session_start();

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados. Contate o suporte.");
}

$erro = '';
$sucesso = '';
$contratos = [];
$cliente = null;
$etapa = 'busca';
$cpf_busca = '';
$contrato_selecionado = null;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== FUNÇÕES AUXILIARES =====
function obterIPCliente() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return 'IP desconhecido';
}

function obterUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'User-Agent não identificado';
}

function formatarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) == 11) {
        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    } elseif (strlen($cpf) == 14) {
        return substr($cpf, 0, 2) . '.' . substr($cpf, 2, 3) . '.' . substr($cpf, 5, 3) . '/' . substr($cpf, 8, 4) . '-' . substr($cpf, 12, 2);
    }
    return $cpf;
}

function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

/**
 * Gera hash usando dados fixos (não depende do arquivo de imagem)
 */
function gerarHashDocumento($orcamento_id, $cpf_cliente, $ip, $user_agent) {
    $dados = [
        'orcamento_id' => $orcamento_id,
        'cliente_cpf' => $cpf_cliente,
        'data_hora' => date('Y-m-d H:i:s'),
        'ip' => $ip,
        'user_agent' => $user_agent,
        'nonce' => bin2hex(random_bytes(16)),
        'timestamp' => microtime(true)
    ];
    return hash('sha256', json_encode($dados));
}

function registrarLog($conexao, $orcamento_id, $acao, $descricao, $ip) {
    $sql = "INSERT INTO logs_contratos (orcamento_id, acao, descricao, ip, data_hora) 
            VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("isss", $orcamento_id, $acao, $descricao, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function enviarEmail($para, $assunto, $mensagem) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: contato@imperioar.com.br\r\n";
    $headers .= "Reply-To: contato@imperioar.com.br\r\n";
    error_log("Tentativa de envio de email para: " . $para);
    return mail($para, $assunto, $mensagem, $headers);
}

// ===== PROCESSAR REQUISIÇÕES =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = isset($_POST['acao']) ? $_POST['acao'] : '';
    
    if ($acao === 'buscar') {
        $cpf_cnpj = isset($_POST['cpf_cnpj']) ? preg_replace('/[^0-9]/', '', $_POST['cpf_cnpj']) : '';
        $cpf_busca = $cpf_cnpj;
        
        if (strlen($cpf_cnpj) < 11) {
            $erro = 'CPF/CNPJ inválido. Digite apenas números.';
        } else {
            try {
                $sql_cliente = "SELECT id, nome, cpf_cnpj, email, whatsapp FROM clientes WHERE cpf_cnpj = ?";
                $stmt = $conexao->prepare($sql_cliente);
                $stmt->bind_param("s", $cpf_cnpj);
                $stmt->execute();
                $cliente_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$cliente_data) {
                    $erro = 'Nenhum cliente encontrado com este CPF/CNPJ.';
                } else {
                    $cliente = $cliente_data;
                    
                    $sql_contratos = "SELECT o.id, o.numero, o.valor_total, o.valor_adicional, o.data_emissao, 
                                             o.data_validade, o.assinado, o.data_assinatura, 
                                             o.checklist_concluido, o.situacao
                                    FROM orcamentos o
                                    WHERE o.cliente_id = ?
                                    ORDER BY o.data_emissao DESC";
                    
                    $stmt = $conexao->prepare($sql_contratos);
                    $stmt->bind_param("i", $cliente['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    while ($row = $result->fetch_assoc()) {
                        $row['valor_exibicao'] = floatval($row['valor_total']) + floatval($row['valor_adicional'] ?? 0);
                        $contratos[] = $row;
                    }
                    $stmt->close();
                    
                    if (empty($contratos)) {
                        $erro = 'Nenhum contrato encontrado para este cliente.';
                    } else {
                        $etapa = 'lista';
                        $sucesso = 'Encontramos ' . count($contratos) . ' contrato(s) para você.';
                    }
                }
            } catch (Exception $e) {
                $erro = 'Erro: ' . $e->getMessage();
                error_log($e->getMessage());
            }
        }
    }
    
    elseif ($acao === 'selecionar_contrato' && isset($_POST['contrato_id'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $erro = 'Token de segurança inválido.';
            $etapa = 'busca';
        } else {
            $contrato_id = intval($_POST['contrato_id']);
            
            $sql = "SELECT o.*, c.id as cliente_id, c.nome, c.cpf_cnpj, c.email, c.whatsapp
                    FROM orcamentos o
                    LEFT JOIN clientes c ON o.cliente_id = c.id
                    WHERE o.id = ?";
            
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("i", $contrato_id);
            $stmt->execute();
            $orcamento = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($orcamento) {
                $orcamento['valor_exibicao'] = floatval($orcamento['valor_total']) + floatval($orcamento['valor_adicional'] ?? 0);
                $contrato_selecionado = $orcamento;
                $cliente = [
                    'id' => $orcamento['cliente_id'],
                    'nome' => $orcamento['nome'],
                    'cpf_cnpj' => $orcamento['cpf_cnpj'],
                    'email' => $orcamento['email'],
                    'whatsapp' => $orcamento['whatsapp']
                ];
                $etapa = 'visualizar';
            } else {
                $erro = 'Contrato não encontrado.';
                $etapa = 'busca';
            }
        }
    }
    
    elseif ($acao === 'salvar_assinatura' && isset($_POST['id_orcamento'])) {
        header('Content-Type: application/json');
        
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            http_response_code(403);
            echo json_encode(['erro' => 'Token de segurança inválido.']);
            exit;
        }
        
        $id_orcamento = intval($_POST['id_orcamento']);
        $assinatura_base64 = $_POST['assinatura_base64'] ?? '';
        
        if (!$id_orcamento || empty($assinatura_base64)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Dados inválidos']);
            exit;
        }
        
        $sql_check = "SELECT checklist_concluido, assinado FROM orcamentos WHERE id = ?";
        $stmt_check = $conexao->prepare($sql_check);
        $stmt_check->bind_param("i", $id_orcamento);
        $stmt_check->execute();
        $check_data = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();
        
        if ($check_data['assinado'] == 1) {
            http_response_code(400);
            echo json_encode(['erro' => 'Este contrato já foi assinado']);
            exit;
        }
        
        if ($check_data['checklist_concluido'] != 1) {
            http_response_code(400);
            echo json_encode(['erro' => 'O checklist técnico ainda não foi concluído.']);
            exit;
        }
        
        try {
            $dir = __DIR__ . '/storage/assinaturas';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            
            $assinatura_data = str_replace('data:image/png;base64,', '', $assinatura_base64);
            $assinatura_data = str_replace(' ', '+', $assinatura_data);
            $imagem = base64_decode($assinatura_data);
            
            $nome_arquivo = 'assinatura_' . $id_orcamento . '_' . time() . '.png';
            $caminho = $dir . '/' . $nome_arquivo;
            file_put_contents($caminho, $imagem);
            
            $ip = obterIPCliente();
            $user_agent = obterUserAgent();
            
            $sql_cliente = "SELECT cpf_cnpj FROM orcamentos o LEFT JOIN clientes c ON o.cliente_id = c.id WHERE o.id = ?";
            $stmt_cliente = $conexao->prepare($sql_cliente);
            $stmt_cliente->bind_param("i", $id_orcamento);
            $stmt_cliente->execute();
            $cliente_data = $stmt_cliente->get_result()->fetch_assoc();
            $stmt_cliente->close();
            
            $cpf_cliente = $cliente_data['cpf_cnpj'] ?? '';
            
            // Gerar hash com dados fixos
            $hash_documento = gerarHashDocumento($id_orcamento, $cpf_cliente, $ip, $user_agent);
            
            $sql = "UPDATE orcamentos SET 
                        assinado = 1, 
                        data_assinatura = NOW(), 
                        assinatura_arquivo = ?, 
                        ip_assinatura = ?,
                        hash_documento = ?,
                        user_agent_assinatura = ?
                    WHERE id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("ssssi", $nome_arquivo, $ip, $hash_documento, $user_agent, $id_orcamento);
            $stmt->execute();
            $stmt->close();
            
            registrarLog($conexao, $id_orcamento, 'ASSINADO', 'Contrato assinado digitalmente', $ip);
            
            $sql_dados = "SELECT o.*, c.nome as cliente_nome, c.email FROM orcamentos o LEFT JOIN clientes c ON o.cliente_id = c.id WHERE o.id = ?";
            $stmt_dados = $conexao->prepare($sql_dados);
            $stmt_dados->bind_param("i", $id_orcamento);
            $stmt_dados->execute();
            $dados = $stmt_dados->get_result()->fetch_assoc();
            $stmt_dados->close();
            
            if ($dados && !empty($dados['email'])) {
                $assunto = 'Contrato Assinado com Sucesso!';
                $msg = '<html><head><meta charset="UTF-8"><style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; }
                    .header { background: #28a745; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; }
                    .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; }
                </style></head><body>
                    <div class="container">
                        <div class="header"><h1>✅ Contrato Assinado com Sucesso!</h1></div>
                        <div class="content">
                            <p>Olá ' . htmlspecialchars($dados['cliente_nome'] ?? 'Cliente') . ',</p>
                            <p>Seu contrato foi assinado digitalmente em ' . date('d/m/Y H:i:s') . '.</p>
                            <p><strong>Identificador de Autenticidade:</strong><br>
                            <small style="font-family: monospace;">' . substr($hash_documento, 0, 32) . '...</small></p>
                            <p><strong>Contato:</strong><br>📞 (17) 99624-0725<br>📧 contato@imperioar.com.br</p>
                        </div>
                        <div class="footer">&copy; 2026 Império AR - Refrigeração<br><small>Lei 14.063/2020</small></div>
                    </div>
                </body></html>';
                enviarEmail($dados['email'], $assunto, $msg);
            }
            
            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Contrato assinado com sucesso!',
                'hash' => $hash_documento,
                'url_confirmacao' => BASE_URL . '/confirmacao_assinatura.php?id=' . $id_orcamento
            ]);
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            error_log('Erro assinatura: ' . $e->getMessage());
            echo json_encode(['erro' => $e->getMessage()]);
            exit;
        }
    }
}

// Processar retorno via GET
if (isset($_GET['cpf']) && !empty($_GET['cpf']) && $etapa === 'busca') {
    $cpf_busca = $_GET['cpf'];
    $sql_cliente = "SELECT id, nome, cpf_cnpj, email, whatsapp FROM clientes WHERE cpf_cnpj = ?";
    $stmt = $conexao->prepare($sql_cliente);
    $stmt->bind_param("s", $cpf_busca);
    $stmt->execute();
    $cliente_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($cliente_data) {
        $cliente = $cliente_data;
        $sql_contratos = "SELECT o.id, o.numero, o.valor_total, o.valor_adicional, o.data_emissao, 
                                 o.data_validade, o.assinado, o.data_assinatura, 
                                 o.checklist_concluido, o.situacao
                        FROM orcamentos o WHERE o.cliente_id = ? ORDER BY o.data_emissao DESC";
        $stmt = $conexao->prepare($sql_contratos);
        $stmt->bind_param("i", $cliente['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['valor_exibicao'] = floatval($row['valor_total']) + floatval($row['valor_adicional'] ?? 0);
            $contratos[] = $row;
        }
        $stmt->close();
        if (!empty($contratos)) {
            $etapa = 'lista';
            $sucesso = 'Encontramos ' . count($contratos) . ' contrato(s) para você.';
        } else {
            $erro = 'Nenhum contrato encontrado.';
            $etapa = 'busca';
        }
    } else {
        $erro = 'Cliente não encontrado.';
        $etapa = 'busca';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinar Contrato - Império AR Refrigeração</title>
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
            max-width: 900px;
            width: 100%;
            padding: 40px;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px solid #1e3c72;
            padding-bottom: 20px;
        }
        .header h1 { color: #1e3c72; font-size: 28px; margin-bottom: 10px; }
        .header p { color: #666; font-size: 14px; }
        .logo { font-size: 48px; margin-bottom: 15px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: bold; font-size: 14px; }
        input[type="text"] { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px; }
        input[type="text"]:focus { outline: none; border-color: #1e3c72; }
        .btn {
            display: inline-block; padding: 12px 30px; background: #1e3c72; color: white; border: none;
            border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none;
            text-align: center; width: 100%;
        }
        .btn:hover { background: #2a5298; transform: translateY(-2px); }
        .btn-sm { padding: 8px 16px; font-size: 13px; width: auto; }
        .btn-primary { background: #28a745; }
        .btn-primary:hover { background: #218838; }
        .btn-outline { background: transparent; border: 2px solid #1e3c72; color: #1e3c72; }
        .btn-outline:hover { background: #1e3c72; color: white; }
        .alerta { padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid; }
        .alerta-erro { background: #f8d7da; border-left-color: #dc3545; color: #721c24; }
        .alerta-sucesso { background: #d4edda; border-left-color: #28a745; color: #155724; }
        .alerta-info { background: #cfe2ff; border-left-color: #0d6efd; color: #084298; }
        .contratos-lista { margin: 20px 0; }
        .contrato-card {
            background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 15px;
        }
        .contrato-card.assinado { border-left: 4px solid #28a745; background: #f0fff4; }
        .contrato-card.pendente { border-left: 4px solid #ffc107; }
        .contrato-card.checklist-pendente { border-left: 4px solid #6c757d; background: #f8f9fa; }
        .contrato-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
        .contrato-numero { font-size: 18px; font-weight: bold; color: #1e3c72; }
        .contrato-status { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .status-assinado { background: #28a745; color: white; }
        .status-pendente { background: #ffc107; color: #856404; }
        .status-checklist { background: #6c757d; color: white; }
        .contrato-detalhes { display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px; color: #666; margin-bottom: 15px; }
        .cliente-info-card { background: #e8f4f8; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .cliente-info-card p { margin: 5px 0; }
        .voltar-btn {
            background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 5px;
            cursor: pointer; font-size: 14px; margin-bottom: 20px; display: inline-block; text-decoration: none;
        }
        .voltar-btn:hover { background: #5a6268; }
        .orcamento-info { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #1e3c72; }
        .valor-detalhado {
            background: #f8f9fa; border-radius: 8px; padding: 12px 15px; margin: 12px 0; border: 1px solid #e9ecef;
        }
        .valor-principal { font-size: 16px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #1e3c72; }
        .valor-total { font-size: 18px; font-weight: bold; color: #28a745; margin-left: 10px; }
        .valor-composicao { margin-top: 10px; }
        .composicao-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px; border-bottom: 1px dashed #dee2e6; }
        .composicao-item.destaque { background: #fff3cd; margin: 5px -5px; padding: 8px 5px; border-radius: 5px; border-bottom: none; flex-wrap: wrap; }
        .composicao-item.destaque span:first-child { font-weight: bold; color: #856404; }
        .composicao-item.destaque span:last-child { font-weight: bold; color: #e67e22; font-size: 14px; }
        .taxa-detalhe { width: 100%; margin-top: 8px; padding-top: 5px; font-size: 11px; color: #856404; border-top: 1px solid #ffe0b2; }
        .composicao-item.total { margin-top: 8px; padding-top: 8px; border-top: 2px solid #28a745; border-bottom: none; font-weight: bold; font-size: 14px; }
        .total-valor { font-size: 16px; color: #28a745; }
        .sem-adicional { background: #d4edda; padding: 8px 12px; border-radius: 5px; margin-top: 10px; font-size: 12px; color: #155724; }
        .contrato-acoes { text-align: right; margin-top: 10px; }
        .assinatura-container { border: 2px dashed #1e3c72; border-radius: 5px; padding: 10px; margin: 20px 0; background: #f5f5f5; }
        #canvas-assinatura { display: block; width: 100%; height: 200px; background: white; cursor: crosshair; border-radius: 3px; }
        .canvas-info { font-size: 12px; color: #999; margin-top: 10px; text-align: center; }
        .botoes-assinatura { display: flex; gap: 10px; margin-top: 15px; }
        .botoes-assinatura button { flex: 1; padding: 10px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .btn-limpar { background: #f8f9fa; color: #333; border: 1px solid #ddd; }
        .btn-confirmar { background: #28a745; color: white; }
        .loader { display: none; text-align: center; padding: 20px; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #1e3c72; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .ja-assinado { background: #e7f3ff; border: 2px solid #0d6efd; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .checklist-pendente-box { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .valor-destaque { font-size: 18px; font-weight: bold; color: #28a745; }
        .info-legal { background: #e8f4f8; padding: 12px; border-radius: 5px; margin: 15px 0; font-size: 12px; color: #1e3c72; border-left: 3px solid #1e3c72; }
        @media (max-width: 600px) { 
            .container { padding: 20px; } 
            .contrato-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .contrato-acoes { text-align: left; margin-top: 10px; }
            .composicao-item { flex-direction: column; align-items: flex-start; gap: 5px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">✍️</div>
            <h1>Assinar Contrato</h1>
            <p>Império AR - Refrigeração</p>
        </div>
        
        <?php if (!empty($erro)): ?>
            <div class="alerta alerta-erro">❌ <?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($sucesso)): ?>
            <div class="alerta alerta-sucesso">✅ <?php echo htmlspecialchars($sucesso); ?></div>
        <?php endif; ?>
        
        <?php if ($etapa === 'busca'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-group">
                    <label>🔍 Digite seu CPF ou CNPJ:</label>
                    <input type="text" name="cpf_cnpj" placeholder="000.000.000-00" required maxlength="18" value="<?php echo htmlspecialchars($cpf_busca); ?>">
                    <small style="color: #999;">Digite apenas números, pontos e barras</small>
                </div>
                <button type="submit" class="btn" name="acao" value="buscar">🔎 Buscar Meus Contratos</button>
            </form>
        <?php endif; ?>
        
        <?php if ($etapa === 'lista' && $cliente && !empty($contratos)): ?>
            <div class="cliente-info-card">
                <p><strong>👤 Cliente:</strong> <?php echo htmlspecialchars($cliente['nome']); ?></p>
                <p><strong>📄 CPF/CNPJ:</strong> <?php echo formatarCPF($cliente['cpf_cnpj']); ?></p>
                <p><strong>📧 E-mail:</strong> <?php echo htmlspecialchars($cliente['email'] ?? 'Não informado'); ?></p>
                <p><strong>📞 WhatsApp:</strong> <?php echo htmlspecialchars($cliente['whatsapp'] ?? 'Não informado'); ?></p>
            </div>
            
            <h3 style="margin-bottom: 15px;">📋 Seus Contratos (<?php echo count($contratos); ?>)</h3>
            
            <div class="contratos-lista">
                <?php foreach ($contratos as $contrato): 
                    $ja_assinado = $contrato['assinado'] == 1;
                    $checklist_pronto = $contrato['checklist_concluido'] == 1;
                    $tem_adicional = ($contrato['valor_adicional'] ?? 0) > 0;
                    $valor_base = floatval($contrato['valor_total']);
                    $valor_adicional = floatval($contrato['valor_adicional'] ?? 0);
                    $valor_total_completo = $valor_base + $valor_adicional;
                    
                    $status_class = $ja_assinado ? 'assinado' : ($checklist_pronto ? 'pendente' : 'checklist-pendente');
                    $status_text = $ja_assinado ? '✅ Assinado' : ($checklist_pronto ? '📝 Pendente de Assinatura' : '⏳ Aguardando Checklist');
                    $status_badge = $ja_assinado ? 'status-assinado' : ($checklist_pronto ? 'status-pendente' : 'status-checklist');
                ?>
                <div class="contrato-card <?php echo $status_class; ?>">
                    <div class="contrato-header">
                        <span class="contrato-numero"><?php echo htmlspecialchars($contrato['numero'] ?? 'CONTRATO #' . $contrato['id']); ?></span>
                        <span class="contrato-status <?php echo $status_badge; ?>"><?php echo $status_text; ?></span>
                    </div>
                    <div class="contrato-detalhes">
                        <span>📅 Emissão: <?php echo formatarData($contrato['data_emissao']); ?></span>
                        <span>⏰ Validade: <?php echo formatarData($contrato['data_validade']); ?></span>
                    </div>
                    
                    <div class="valor-detalhado">
                        <div class="valor-principal">
                            💰 <strong>VALOR TOTAL DO CONTRATO:</strong> 
                            <span class="valor-total"><?php echo formatarMoeda($valor_total_completo); ?></span>
                        </div>
                        
                        <?php if ($tem_adicional): ?>
                            <div class="valor-composicao">
                                <div class="composicao-item">
                                    <span>📦 Valor base dos serviços:</span>
                                    <span><?php echo formatarMoeda($valor_base); ?></span>
                                </div>
                                <div class="composicao-item destaque">
                                    <span>🧹 Taxa de Limpeza Especializada:</span>
                                    <span>+ <?php echo formatarMoeda($valor_adicional); ?></span>
                                    <div class="taxa-detalhe">
                                        <small>⚠️ Necessidade identificada na vistoria técnica</small>
                                    </div>
                                </div>
                                <div class="composicao-item total">
                                    <span>✅ VALOR TOTAL A PAGAR:</span>
                                    <span class="total-valor"><?php echo formatarMoeda($valor_total_completo); ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="sem-adicional"><small>✅ Valor total já inclui todos os serviços e materiais.</small></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="contrato-acoes">
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="acao" value="selecionar_contrato">
                            <input type="hidden" name="contrato_id" value="<?php echo $contrato['id']; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $ja_assinado ? 'btn-outline' : 'btn-primary'; ?>">
                                <?php echo $ja_assinado ? '👁️ Visualizar' : '✍️ Assinar Agora'; ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button onclick="location.reload()" class="voltar-btn">↩️ Buscar Outro CPF/CNPJ</button>
        <?php endif; ?>
        
        <?php if ($etapa === 'visualizar' && $contrato_selecionado && $cliente): 
            $orcamento = $contrato_selecionado;
            $ja_assinado = $orcamento['assinado'] == 1;
            $checklist_pendente = $orcamento['checklist_concluido'] != 1;
            $tem_adicional = ($orcamento['valor_adicional'] ?? 0) > 0;
            $valor_base = floatval($orcamento['valor_total']);
            $valor_adicional = floatval($orcamento['valor_adicional'] ?? 0);
            $valor_total_completo = $valor_base + $valor_adicional;
            $token = hash('sha256', $orcamento['id'] . ($cliente['cpf_cnpj'] ?? '') . 'contrato_seguro');
        ?>
            <a href="?cpf=<?php echo urlencode($cliente['cpf_cnpj']); ?>" class="voltar-btn">↩️ Voltar para Lista de Contratos</a>
            
            <div class="orcamento-info">
                <h3>📋 INFORMAÇÕES DO CONTRATO</h3>
                <div style="margin-bottom: 20px;">
                    <p><strong>👤 Cliente:</strong> <?php echo htmlspecialchars($cliente['nome']); ?></p>
                    <p><strong>📄 CPF/CNPJ:</strong> <?php echo formatarCPF($cliente['cpf_cnpj']); ?></p>
                    <p><strong>📧 E-mail:</strong> <?php echo htmlspecialchars($cliente['email'] ?? 'Não informado'); ?></p>
                    <p><strong>📞 WhatsApp:</strong> <?php echo htmlspecialchars($cliente['whatsapp'] ?? 'Não informado'); ?></p>
                    <p><strong>📅 Data Emissão:</strong> <?php echo formatarData($orcamento['data_emissao']); ?></p>
                    <p><strong>⏰ Data Validade:</strong> <?php echo formatarData($orcamento['data_validade']); ?></p>
                    <p><strong>🔢 Número:</strong> <?php echo htmlspecialchars($orcamento['numero'] ?? '#' . $orcamento['id']); ?></p>
                </div>
                
                <div class="valor-detalhado">
                    <div class="valor-principal">
                        💰 <strong>VALOR TOTAL DO CONTRATO:</strong> 
                        <span class="valor-total"><?php echo formatarMoeda($valor_total_completo); ?></span>
                    </div>
                    
                    <?php if ($tem_adicional): ?>
                        <div class="valor-composicao">
                            <div class="composicao-item">
                                <span>📦 Valor base dos serviços:</span>
                                <span><?php echo formatarMoeda($valor_base); ?></span>
                            </div>
                            <div class="composicao-item destaque">
                                <span>🧹 Taxa de Limpeza Especializada:</span>
                                <span>+ <?php echo formatarMoeda($valor_adicional); ?></span>
                                <div class="taxa-detalhe">
                                    <small>⚠️ Necessidade identificada na vistoria técnica</small>
                                </div>
                            </div>
                            <div class="composicao-item total">
                                <span>✅ VALOR TOTAL A PAGAR:</span>
                                <span class="total-valor"><?php echo formatarMoeda($valor_total_completo); ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="sem-adicional"><small>✅ Valor total já inclui todos os serviços e materiais.</small></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($ja_assinado): ?>
                <div class="ja-assinado">
                    <p><strong>ℹ️ Este contrato já foi assinado em:</strong> <?php echo date('d/m/Y H:i:s', strtotime($orcamento['data_assinatura'])); ?></p>
                    <?php if (!empty($orcamento['hash_documento'])): ?>
                        <p><strong>🔐 Identificador:</strong> <code><?php echo substr($orcamento['hash_documento'], 0, 32); ?>...</code></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($checklist_pendente): ?>
                <div class="checklist-pendente-box">
                    <p><strong>⚠️ Checklist Técnico Pendente</strong></p>
                    <p>O checklist técnico ainda não foi concluído. Aguarde a vistoria da equipe.</p>
                </div>
            <?php endif; ?>
            
            <div class="info-legal">
                <strong>⚖️ Validade Legal</strong><br>
                Esta assinatura eletrônica possui validade jurídica conforme a <strong>Lei Federal nº 14.063/2020</strong>.
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo BASE_URL; ?>/visualizar_contrato.php?id=<?php echo $orcamento['id']; ?>&token=<?php echo $token; ?>" target="_blank" class="btn btn-outline" style="display: inline-block; width: auto;">📄 Visualizar Contrato em PDF</a>
            </div>
            
            <?php if (!$checklist_pendente && !$ja_assinado): ?>
            <form id="form-assinatura">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-group">
                    <label>✍️ Sua Assinatura Digital:</label>
                    <div class="alerta alerta-info">ℹ️ Desenhe sua assinatura no quadro abaixo. Esta assinatura tem validade legal conforme Lei 14.063/2020.</div>
                    
                    <div class="assinatura-container">
                        <canvas id="canvas-assinatura"></canvas>
                        <div class="canvas-info">Clique e arraste para desenhar sua assinatura</div>
                        <div class="botoes-assinatura">
                            <button type="button" class="btn-limpar" id="btn-limpar">🔄 Limpar</button>
                            <button type="button" class="btn-confirmar" id="btn-confirmar">✅ Confirmar Assinatura</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 30px;">
                    <label style="font-weight: normal; display: flex; align-items: center;">
                        <input type="checkbox" id="aceitar-termos" required style="width: auto; margin-right: 10px;">
                        <span>Eu, <?php echo htmlspecialchars($cliente['nome']); ?>, aceito os termos do contrato e confirmo a assinatura.</span>
                    </label>
                </div>
                
                <input type="hidden" name="acao" value="salvar_assinatura">
                <input type="hidden" name="id_orcamento" value="<?php echo $orcamento['id']; ?>">
                <input type="hidden" name="assinatura_base64" id="assinatura_base64">
                
                <div class="loader" id="loader"><div class="spinner"></div><p>Processando assinatura...</p></div>
                
                <button type="submit" class="btn" id="btn-enviar" style="margin-top: 20px;">📤 Assinar e Enviar Contrato</button>
            </form>
            
            <script>
            (function() {
                const canvas = document.getElementById('canvas-assinatura');
                const ctx = canvas.getContext('2d');
                let isDrawing = false;
                let hasSignature = false;
                
                function resizeCanvas() {
                    canvas.width = canvas.offsetWidth;
                    canvas.height = canvas.offsetHeight;
                }
                resizeCanvas();
                window.addEventListener('resize', resizeCanvas);
                
                canvas.addEventListener('mousedown', (e) => {
                    isDrawing = true;
                    hasSignature = true;
                    const rect = canvas.getBoundingClientRect();
                    ctx.beginPath();
                    ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
                });
                
                canvas.addEventListener('mousemove', (e) => {
                    if (!isDrawing) return;
                    const rect = canvas.getBoundingClientRect();
                    ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
                    ctx.strokeStyle = '#1e3c72';
                    ctx.lineWidth = 2;
                    ctx.lineCap = 'round';
                    ctx.stroke();
                });
                
                canvas.addEventListener('mouseup', () => { isDrawing = false; });
                canvas.addEventListener('mouseout', () => { isDrawing = false; });
                
                canvas.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    const touch = e.touches[0];
                    const rect = canvas.getBoundingClientRect();
                    isDrawing = true;
                    hasSignature = true;
                    ctx.beginPath();
                    ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
                });
                
                canvas.addEventListener('touchmove', (e) => {
                    e.preventDefault();
                    if (!isDrawing) return;
                    const touch = e.touches[0];
                    const rect = canvas.getBoundingClientRect();
                    ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
                    ctx.stroke();
                });
                
                canvas.addEventListener('touchend', () => { isDrawing = false; });
                
                document.getElementById('btn-limpar').addEventListener('click', () => {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    hasSignature = false;
                    document.getElementById('btn-confirmar').disabled = false;
                });
                
                document.getElementById('btn-confirmar').addEventListener('click', () => {
                    if (!hasSignature) {
                        alert('Desenhe sua assinatura antes de confirmar!');
                        return;
                    }
                    document.getElementById('assinatura_base64').value = canvas.toDataURL('image/png');
                    alert('✅ Assinatura confirmada!');
                    document.getElementById('btn-confirmar').disabled = true;
                });
                
                document.getElementById('form-assinatura').addEventListener('submit', (e) => {
                    e.preventDefault();
                    
                    if (!document.getElementById('assinatura_base64').value) {
                        alert('⚠️ Confirme sua assinatura antes de enviar!');
                        return;
                    }
                    
                    if (!document.getElementById('aceitar-termos').checked) {
                        alert('⚠️ Você deve aceitar os termos do contrato!');
                        return;
                    }
                    
                    const formData = new FormData(document.getElementById('form-assinatura'));
                    document.getElementById('loader').style.display = 'block';
                    document.getElementById('btn-enviar').disabled = true;
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.sucesso) {
                            window.location.href = data.url_confirmacao;
                        } else {
                            alert('Erro: ' + data.erro);
                            document.getElementById('loader').style.display = 'none';
                            document.getElementById('btn-enviar').disabled = false;
                        }
                    })
                    .catch(error => {
                        alert('Erro ao enviar: ' + error);
                        document.getElementById('loader').style.display = 'none';
                        document.getElementById('btn-enviar').disabled = false;
                    });
                });
            })();
            </script>
            <?php elseif ($ja_assinado): ?>
            <div style="text-align: center;">
                <a href="<?php echo BASE_URL; ?>/confirmacao_assinatura.php?id=<?php echo $orcamento['id']; ?>" class="btn">📄 Ver Comprovante</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
