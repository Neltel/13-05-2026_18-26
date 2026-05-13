<?php
/**
 * =====================================================================
 * VISUALIZAÇÃO DE RECIBO - VERSÃO CORRIGIDA
 * =====================================================================
 * 
 * Responsabilidade: Exibir recibo de pagamento
 * Chamado por: orcamentos.php?acao=recibo&id=XXX
 * 
 * Esta versão corrige o erro "Acesso negado"
 */

// ===== VERIFICAÇÃO DE SEGURANÇA REFORÇADA =====
// Verifica se o usuário está logado (deve vir do orcamentos.php)
if (!isset($_SESSION) || !isset($_SESSION['usuario_id'])) {
    // Se não estiver logado, redireciona para login
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php');
    exit;
}

// Verifica se as variáveis necessárias existem
$acesso_negado = false;

if (!isset($orcamento_recibo) || empty($orcamento_recibo)) {
    $acesso_negado = true;
    $mensagem_erro = "Recibo não encontrado ou dados inválidos.";
}

if (!isset($numero_recibo)) {
    $numero_recibo = 'REC-' . date('Ymd') . '-0000';
}

// Se acesso negado, exibe mensagem de erro em vez do recibo
if ($acesso_negado):
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erro - Recibo não encontrado</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .erro-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }
        .erro-icon {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .erro-titulo {
            color: #333;
            margin-bottom: 15px;
        }
        .erro-mensagem {
            color: #666;
            margin-bottom: 25px;
        }
        .btn-voltar {
            display: inline-block;
            padding: 10px 25px;
            background: #1e3c72;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .btn-voltar:hover {
            background: #2a5298;
        }
    </style>
</head>
<body>
    <div class="erro-container">
        <div class="erro-icon">⚠️</div>
        <h1 class="erro-titulo">Acesso Negado</h1>
        <p class="erro-mensagem"><?php echo $mensagem_erro ?? 'Você não tem permissão para acessar este recibo.'; ?></p>
        <a href="<?php echo defined('BASE_URL') ? BASE_URL . '/app/admin/orcamentos.php' : '?acao=listar'; ?>" class="btn-voltar">
            ← Voltar para Orçamentos
        </a>
    </div>
</body>
</html>
<?php
    exit;
endif;

// ===== SE TUDO OK, EXIBE O RECIBO NORMAL =====
// (todo o HTML do recibo que eu já gerei anteriormente continua aqui)
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo - Império AR</title>
    <style>
        /* TODO O CSS QUE EU JÁ GEREI ANTES */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: #f5f5f5;
            padding: 30px;
        }
        
        .recibo-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            position: relative;
        }
        
        .recibo-container::before {
            content: "RECIBO";
            position: absolute;
            top: 20px;
            right: 40px;
            font-size: 60px;
            font-weight: bold;
            color: rgba(0,0,0,0.03);
            transform: rotate(-5deg);
            pointer-events: none;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .logo h1 {
            color: #1e3c72;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 12px;
        }
        
        .recibo-info {
            text-align: right;
        }
        
        .recibo-info .numero {
            font-size: 18px;
            font-weight: bold;
            color: #1e3c72;
            margin-bottom: 5px;
        }
        
        .recibo-info .data {
            color: #666;
            font-size: 14px;
        }
        
        .cliente-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .cliente-info h3 {
            color: #1e3c72;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .cliente-info p {
            margin-bottom: 5px;
            color: #555;
        }
        
        .valor-info {
            text-align: center;
            margin: 30px 0;
            padding: 30px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
        }
        
        .valor-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }
        
        .valor-numero {
            font-size: 48px;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 5px;
        }
        
        .valor-extenso {
            font-size: 16px;
            color: #555;
            font-style: italic;
        }
        
        .detalhes {
            margin: 30px 0;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .detalhes-header {
            background: #1e3c72;
            color: white;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .detalhes-body {
            padding: 20px;
        }
        
        .detalhes-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .detalhes-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .assinatura {
            margin: 40px 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        
        .assinatura-cliente,
        .assinatura-emitente {
            width: 45%;
            text-align: center;
        }
        
        .linha-assinatura {
            border-top: 2px solid #333;
            margin: 10px 0 5px;
            padding-top: 10px;
        }
        
        .assinatura-nome {
            font-weight: bold;
            color: #333;
        }
        
        .assinatura-label {
            font-size: 12px;
            color: #666;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        
        .buttons {
            margin-top: 30px;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 0 5px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #1e3c72;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-print {
            background: #28a745;
            color: white;
        }
        
        .btn-print:hover {
            background: #218838;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .recibo-container {
                box-shadow: none;
                padding: 20px;
            }
            
            .buttons {
                display: none;
            }
            
            .recibo-container::before {
                color: rgba(0,0,0,0.05);
            }
        }
    </style>
</head>
<body>
    <div class="recibo-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <h1>Império AR</h1>
                <p>Especialistas em Conforto Térmico</p>
            </div>
            <div class="recibo-info">
                <div class="numero"><?php echo $numero_recibo; ?></div>
                <div class="data"><?php echo date('d/m/Y'); ?></div>
            </div>
        </div>
        
        <!-- Informações do Cliente -->
        <div class="cliente-info">
            <h3>📋 DADOS DO CLIENTE</h3>
            <p><strong>Nome:</strong> <?php echo htmlspecialchars($orcamento_recibo['cliente_nome'] ?? 'Não informado'); ?></p>
            <p><strong>CPF/CNPJ:</strong> <?php echo htmlspecialchars($orcamento_recibo['cpf_cnpj'] ?? 'Não informado'); ?></p>
            <p><strong>Telefone:</strong> <?php echo htmlspecialchars($orcamento_recibo['telefone'] ?? $orcamento_recibo['whatsapp'] ?? 'Não informado'); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($orcamento_recibo['email'] ?? 'Não informado'); ?></p>
        </div>
        
        <!-- Valor -->
        <div class="valor-info">
            <div class="valor-label">VALOR RECEBIDO</div>
            <div class="valor-numero"><?php echo 'R$ ' . number_format($orcamento_recibo['valor_total'] ?? 0, 2, ',', '.'); ?></div>
            <div class="valor-extenso">
                <?php
                // Função simples para valor por extenso (apenas exemplo)
                $valor = $orcamento_recibo['valor_total'] ?? 0;
                $reais = floor($valor);
                $centavos = round(($valor - $reais) * 100);
                
                function number_to_words($number) {
                    $words = [
                        0 => 'zero', 1 => 'um', 2 => 'dois', 3 => 'três', 4 => 'quatro',
                        5 => 'cinco', 6 => 'seis', 7 => 'sete', 8 => 'oito', 9 => 'nove',
                        10 => 'dez', 11 => 'onze', 12 => 'doze', 13 => 'treze', 14 => 'quatorze',
                        15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete', 18 => 'dezoito',
                        19 => 'dezenove', 20 => 'vinte', 30 => 'trinta', 40 => 'quarenta',
                        50 => 'cinquenta', 60 => 'sessenta', 70 => 'setenta', 80 => 'oitenta',
                        90 => 'noventa', 100 => 'cem', 200 => 'duzentos', 300 => 'trezentos',
                        400 => 'quatrocentos', 500 => 'quinhentos', 600 => 'seiscentos',
                        700 => 'setecentos', 800 => 'oitocentos', 900 => 'novecentos'
                    ];
                    
                    if ($number <= 20) return $words[$number];
                    if ($number < 100) {
                        $dezena = floor($number / 10) * 10;
                        $unidade = $number % 10;
                        return $words[$dezena] . ($unidade ? ' e ' . $words[$unidade] : '');
                    }
                    if ($number == 100) return 'cem';
                    if ($number < 1000) {
                        $centena = floor($number / 100) * 100;
                        $resto = $number % 100;
                        return ($centena == 100 ? 'cento' : $words[$centena]) . ($resto ? ' e ' . number_to_words($resto) : '');
                    }
                    return $number . ' (valor por extenso não disponível)';
                }
                
                $extenso = number_to_words($reais) . ' reais';
                if ($centavos > 0) {
                    $extenso .= ' e ' . number_to_words($centavos) . ' centavos';
                }
                
                echo ucfirst($extenso);
                ?>
            </div>
        </div>
        
        <!-- Detalhes do Pagamento -->
        <div class="detalhes">
            <div class="detalhes-header">📄 DETALHES DO PAGAMENTO</div>
            <div class="detalhes-body">
                <div class="detalhes-item">
                    <span>Referente a:</span>
                    <strong><?php echo ($orcamento_recibo['tipo_registro'] ?? 'Orçamento'); ?> #<?php echo $orcamento_recibo['numero'] ?? $orcamento_recibo['id']; ?></strong>
                </div>
                
                <?php if (!empty($cobranca)): ?>
                <div class="detalhes-item">
                    <span>Forma de Pagamento:</span>
                    <strong><?php echo ucfirst($cobranca['forma_pagamento'] ?? 'Não informado'); ?></strong>
                </div>
                
                <div class="detalhes-item">
                    <span>Tipo:</span>
                    <strong><?php echo ucfirst($cobranca['tipo_pagamento'] ?? 'À vista'); ?></strong>
                </div>
                
                <?php if (!empty($cobranca['percentual_taxa']) && $cobranca['percentual_taxa'] != 0): ?>
                <div class="detalhes-item">
                    <span>Taxa aplicada:</span>
                    <strong><?php echo $cobranca['percentual_taxa']; ?>%</strong>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($cobranca['nota_fiscal'])): ?>
                <div class="detalhes-item">
                    <span>Nota Fiscal:</span>
                    <strong>Inclusa (+10%)</strong>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
                
                <?php if (!empty($orcamento_recibo['observacao'])): ?>
                <div class="detalhes-item">
                    <span>Observações:</span>
                    <strong><?php echo nl2br(htmlspecialchars($orcamento_recibo['observacao'])); ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Itens do Orçamento (se houver) -->
        <?php if (!empty($orcamento_recibo['itens_produtos']) || !empty($orcamento_recibo['itens_servicos'])): ?>
        <div class="detalhes">
            <div class="detalhes-header">🛒 ITENS</div>
            <div class="detalhes-body">
                <?php foreach (($orcamento_recibo['itens_produtos'] ?? []) as $item): ?>
                <div class="detalhes-item">
                    <span><?php echo htmlspecialchars($item['nome']); ?> x<?php echo $item['quantidade']; ?></span>
                    <strong>R$ <?php echo number_format($item['subtotal'], 2, ',', '.'); ?></strong>
                </div>
                <?php endforeach; ?>
                
                <?php foreach (($orcamento_recibo['itens_servicos'] ?? []) as $item): ?>
                <div class="detalhes-item">
                    <span><?php echo htmlspecialchars($item['nome']); ?> x<?php echo $item['quantidade']; ?></span>
                    <strong>R$ <?php echo number_format($item['subtotal'], 2, ',', '.'); ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Assinaturas -->
        <div class="assinatura">
            <div class="assinatura-cliente">
                <div class="linha-assinatura"></div>
                <div class="assinatura-nome"><?php echo htmlspecialchars($orcamento_recibo['cliente_nome'] ?? 'Cliente'); ?></div>
                <div class="assinatura-label">Cliente / Recebedor</div>
            </div>
            
            <div class="assinatura-emitente">
                <div class="linha-assinatura"></div>
                <div class="assinatura-nome">Império AR</div>
                <div class="assinatura-label">Emitente</div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Este recibo é válido como comprovante de pagamento. Gerado em <?php echo date('d/m/Y H:i:s'); ?></p>
            <p>Império AR - CNPJ: 00.000.000/0001-00 - contato@imperioar.com.br</p>
        </div>
        
        <!-- Botões de Ação -->
        <div class="buttons">
            <button onclick="window.print()" class="btn btn-print">
                🖨️ Imprimir Recibo
            </button>
            <a href="?acao=listar" class="btn btn-secondary">
                ⬅️ Voltar
            </a>
        </div>
    </div>
    
    <script>
        // Auto-print opcional (descomente se quiser)
        // window.onload = function() {
        //     setTimeout(function() {
        //         window.print();
        //     }, 500);
        // };
    </script>
</body>
</html>
