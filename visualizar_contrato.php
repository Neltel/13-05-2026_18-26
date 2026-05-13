<?php
/**
 * =====================================================================
 * VISUALIZAR CONTRATO EM PDF - VERSÃO PÚBLICA (CLIENTE) - DEFINITIVA
 * =====================================================================
 * 
 * URL: https://imperiodoar.com.br/visualizar_contrato.php?id=15&token=abc123
 * 
 * Funcionalidades:
 * - Sem login obrigatório
 * - Cliente visualiza seu contrato
 * - Exibe assinatura digital se já assinado
 * - Inclui todas as cláusulas atualizadas (Lei 13.473/2017, NR-35, etc.)
 * - Inclui os 4 Anexos (Termo de Recebimento, Orçamento, Checklist NR-35, Política de Cancelamento)
 * - Pagamento obrigatório no ato da entrega com retenção de 5%
 * - Exibe informações de equipamentos com necessidade de limpeza (quando aplicável)
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

// Obter parâmetros
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$token = isset($_GET['token']) ? $_GET['token'] : '';

if (!$id || !$token) {
    die("❌ Parâmetros inválidos. Link expirou ou está incompleto.");
}

// Buscar orçamento
$sql = "SELECT o.*, 
               c.nome as cliente_nome, 
               c.cpf_cnpj,
               c.telefone,
               c.whatsapp,
               c.email,
               c.endereco_rua,
               c.endereco_numero,
               c.endereco_complemento,
               c.endereco_bairro,
               c.endereco_cidade,
               c.endereco_estado,
               c.endereco_cep
        FROM orcamentos o
        LEFT JOIN clientes c ON o.cliente_id = c.id
        WHERE o.id = ?";

$stmt = $conexao->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$orcamento = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orcamento) {
    die("❌ Contrato não encontrado.");
}

// Validar token
$token_esperado = hash('sha256', $id . $orcamento['cpf_cnpj'] . 'contrato_seguro');
if ($token !== $token_esperado) {
    die("❌ Token inválido. Acesso negado.");
}

// ===== BUSCAR EQUIPAMENTOS COM NECESSIDADE DE LIMPEZA =====
$equipamentos_sujos = [];
$total_taxa_limpeza = 0;

$sql_limpeza = "SELECT equipamento_nome, equipamento_marca, equipamento_modelo, equipamento_btu, 
                       serpentina_estado, filtros_estado, ventilador_estado, placa_estado, pressao_gas,
                       observacao
                FROM checklist_equipamentos 
                WHERE orcamento_id = ? AND limpeza_necessaria = 1";
$stmt_limpeza = $conexao->prepare($sql_limpeza);
$stmt_limpeza->bind_param("i", $id);
$stmt_limpeza->execute();
$result_limpeza = $stmt_limpeza->get_result();
while ($row = $result_limpeza->fetch_assoc()) {
    $equipamentos_sujos[] = $row;
    $total_taxa_limpeza += 350.00;
}
$stmt_limpeza->close();

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return date('d/m/Y');
    return date('d/m/Y', strtotime($data));
}

function formatarTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 1) . ' ' . substr($telefone, 3, 4) . '-' . substr($telefone, 7);
    } elseif (strlen($telefone) == 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 4) . '-' . substr($telefone, 6);
    }
    return $telefone;
}

function numeroPorExtenso($valor) {
    $valor = floatval($valor);
    
    if ($valor > 999999.99) {
        return number_format($valor, 2, ',', '.');
    }
    
    $reais = floor($valor);
    $centavos = round(($valor - $reais) * 100);
    
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $dezenas = ['', 'dez', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cem', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    $especiais = ['onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    
    function converterGrupo($numero, $unidades, $dezenas, $centenas, $especiais) {
        if ($numero == 0) return '';
        $resultado = '';
        $centena = floor($numero / 100);
        if ($centena > 0) {
            $resultado .= ($centena == 1 && ($numero % 100) > 0) ? 'cento' : $centenas[$centena];
        }
        $resto_dezena = $numero % 100;
        if ($resto_dezena > 0) {
            if ($resultado != '') $resultado .= ' e ';
            if ($resto_dezena >= 11 && $resto_dezena <= 19) {
                $resultado .= $especiais[$resto_dezena - 11];
            } else {
                $dezena = floor($resto_dezena / 10);
                $unidade = $resto_dezena % 10;
                if ($dezena > 0) {
                    $resultado .= $dezenas[$dezena];
                    if ($unidade > 0) $resultado .= ' e ' . $unidades[$unidade];
                } else {
                    $resultado .= $unidades[$unidade];
                }
            }
        }
        return $resultado;
    }
    
    if ($reais == 0) {
        $extenso = 'zero';
    } else {
        $extenso = '';
        $milhar = floor($reais / 1000);
        $resto_centenas = $reais % 1000;
        if ($milhar > 0) {
            $extenso .= ($milhar == 1) ? 'um mil' : converterGrupo($milhar, $unidades, $dezenas, $centenas, $especiais) . ' mil';
        }
        if ($resto_centenas > 0) {
            if ($extenso != '') $extenso .= ' e ';
            $extenso .= converterGrupo($resto_centenas, $unidades, $dezenas, $centenas, $especiais);
        }
    }
    
    $extenso .= ' reais';
    
    if ($centavos > 0) {
        $extenso .= ' e ';
        if ($centavos == 1) {
            $extenso .= 'um centavo';
        } else if ($centavos >= 2 && $centavos <= 9) {
            $extenso .= $unidades[$centavos % 10] . ' centavos';
        } else if ($centavos >= 10 && $centavos <= 19) {
            $extenso .= $especiais[$centavos - 11] . ' centavos';
        } else if ($centavos >= 20 && $centavos <= 99) {
            $dezena_cent = floor($centavos / 10);
            $unidade_cent = $centavos % 10;
            $extenso .= $dezenas[$dezena_cent];
            if ($unidade_cent > 0) $extenso .= ' e ' . $unidades[$unidade_cent];
            $extenso .= ' centavos';
        }
    }
    return ucfirst($extenso);
}

function buscarItensProdutos($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT op.*, p.nome, p.valor_venda, c.nome as categoria_nome 
            FROM orcamento_produtos op 
            JOIN produtos p ON op.produto_id = p.id 
            LEFT JOIN categorias_produtos c ON p.categoria_id = c.id 
            WHERE op.orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $orcamento_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        $stmt->close();
    }
    return $itens;
}

function buscarItensServicos($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT os.*, s.nome, s.valor_unitario, s.tempo_execucao, cat.nome as categoria_nome 
            FROM orcamento_servicos os 
            JOIN servicos s ON os.servico_id = s.id 
            LEFT JOIN categorias_servicos cat ON s.categoria_id = cat.id 
            WHERE os.orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $orcamento_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $itens[] = $row;
        }
        $stmt->close();
    }
    return $itens;
}

// Buscar itens
$itens_produtos = buscarItensProdutos($conexao, $id);
$itens_servicos = buscarItensServicos($conexao, $id);
$todos_itens = array_merge($itens_produtos, $itens_servicos);

// Configurações da empresa
$empresa = [
    'nome' => 'IMPÉRIO DO AR INSTALAÇÕES E MANUTENÇÕES LTDA',
    'cnpj' => 'XX.XXX.XXX/XXXX-XX',
    'ie' => 'Isento',
    'endereco' => 'Rua Exemplo, 123 - Centro, São José do Rio Preto/SP',
    'telefone' => '(17) 99624-0725',
    'whatsapp' => '(17) 99624-0725',
    'email' => 'contato@imperioar.com.br',
    'cidade' => 'São José do Rio Preto',
    'uf' => 'SP',
    'site' => 'www.imperioar.com.br'
];

$sql_config = "SELECT * FROM configuracoes WHERE id = 1";
$result_config = $conexao->query($sql_config);
if ($result_config && $row = $result_config->fetch_assoc()) {
    $empresa = [
        'nome' => $row['nome_empresa'] ?? $empresa['nome'],
        'cnpj' => $row['cnpj'] ?? $empresa['cnpj'],
        'ie' => 'Isento',
        'endereco' => trim(($row['endereco_rua'] ?? '') . ', ' . 
                         ($row['endereco_numero'] ?? '') . ' - ' . 
                         ($row['endereco_bairro'] ?? '') . ', ' . 
                         ($row['endereco_cidade'] ?? 'São José do Rio Preto') . '/' . 
                         ($row['endereco_estado'] ?? 'SP')),
        'telefone' => $row['telefone'] ?? $empresa['telefone'],
        'whatsapp' => $row['whatsapp'] ?? $empresa['whatsapp'],
        'email' => $row['email'] ?? $empresa['email'],
        'cidade' => $row['endereco_cidade'] ?? 'São José do Rio Preto',
        'uf' => $row['endereco_estado'] ?? 'SP',
        'site' => $row['website'] ?? $empresa['site']
    ];
}

// Dados do contrato
$numero_contrato = 'CT-' . date('Ymd', strtotime($orcamento['data_emissao'])) . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
$data_contrato = date('d/m/Y');
$cidade_contrato = $empresa['cidade'];

// Cálculos financeiros
$subtotal = floatval($orcamento['valor_total']);
$desconto_percentual = floatval($orcamento['desconto_percentual'] ?? 0);
$valor_adicional = floatval($orcamento['valor_adicional'] ?? 0);
$total_taxa_limpeza = floatval($orcamento['valor_adicional'] ?? 0);

$desconto_valor = $subtotal * ($desconto_percentual / 100);
$valor_total = ($subtotal - $desconto_valor) + $valor_adicional;
$valor_extenso = numeroPorExtenso($valor_total);

// Fluxo de caixa
$valor_sinal = $valor_total * 0.30;
$valor_semanal_total = $valor_total * 0.70;
$valor_retencao = $valor_total * 0.05;
$valor_pagamento_entrega = $valor_total - $valor_retencao;

// Data para os anexos
$data_atual = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato de Prestação de Serviços - <?php echo $numero_contrato; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: #fff;
            padding: 20px;
        }
        
        .container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #1e3c72;
        }
        
        .header h1 {
            color: #1e3c72;
            font-size: 24px;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .header h2 {
            color: #2a5298;
            font-size: 16px;
            font-weight: normal;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 11px;
        }
        
        .titulo-contrato {
            text-align: center;
            margin: 25px 0;
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1e3c72;
        }
        
        .info-empresa {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #1e3c72;
            font-size: 11px;
        }
        
        .info-empresa p {
            margin: 3px 0;
        }
        
        .info-cliente {
            background: #f0f7ff;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #2a5298;
        }
        
        .info-cliente h3, .info-empresa h3 {
            margin-bottom: 10px;
            color: #1e3c72;
            font-size: 14px;
        }
        
        .tabela-itens {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .tabela-itens th {
            background: #1e3c72;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 12px;
        }
        
        .tabela-itens td {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tabela-itens .total-row {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .tabela-itens .total-row td {
            border-top: 2px solid #1e3c72;
        }
        
        .tabela-fluxo-caixa {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #fffbea;
            border: 2px solid #ff9800;
        }
        
        .tabela-fluxo-caixa th {
            background: #ff9800;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 12px;
            font-weight: bold;
        }
        
        .tabela-fluxo-caixa td {
            padding: 10px;
            border-bottom: 1px solid #ffe0b2;
        }
        
        .tabela-fluxo-caixa tr:last-child td {
            border-bottom: none;
            border-top: 2px solid #ff9800;
            font-weight: bold;
            background: #fff3e0;
        }
        
        .clausulas {
            margin: 25px 0;
        }
        
        .clausula {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        
        .clausula h4 {
            color: #1e3c72;
            margin-bottom: 5px;
            font-size: 13px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 3px;
        }
        
        .clausula p {
            margin-bottom: 8px;
            text-align: justify;
        }
        
        .clausula ul {
            margin-left: 20px;
            margin-bottom: 8px;
        }
        
        .clausula li {
            margin-bottom: 3px;
        }
        
        .valores {
            background: #e8f4f8;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .valores p {
            margin: 5px 0;
            font-size: 13px;
        }
        
        .alerta-importante {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            color: #856404;
        }
        
        .alerta-importante strong {
            display: block;
            margin-bottom: 10px;
        }
        
        .alerta-importante ul {
            margin-left: 20px;
        }
        
        .alerta-importante li {
            margin-bottom: 5px;
        }
        
        .assinaturas {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura {
            width: 45%;
            text-align: center;
        }
        
        .linha-assinatura {
            border-top: 2px solid #333;
            margin: 10px 0 5px;
            padding-top: 10px;
            min-height: 50px;
        }
        
        .assinatura-imagem {
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 3px;
            background: #f9f9f9;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 60px;
        }
        
        .assinatura-imagem img {
            max-width: 100%;
            max-height: 70px;
            object-fit: contain;
        }
        
        .assinatura-data {
            font-size: 9px;
            color: #999;
            margin-top: 3px;
        }
        
        .assinatura-nome {
            font-weight: bold;
            color: #333;
        }
        
        .assinatura-label {
            font-size: 10px;
            color: #666;
        }
        
        .testemunhas {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .testemunha {
            width: 45%;
        }
        
        .testemunha .linha {
            border-bottom: 1px solid #333;
            margin: 5px 0;
            height: 30px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
        }
        
        .footer strong {
            display: block;
            margin-bottom: 5px;
            color: #d32f2f;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        @media print {
            body {
                padding: 0;
            }
            .container {
                box-shadow: none;
                padding: 15px;
            }
            .btn-print {
                display: none;
            }
            .botoes-topo {
                display: none;
            }
        }
        
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e3c72;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .btn-print:hover {
            background: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.3);
        }
        
        .badge-categoria {
            display: inline-block;
            padding: 2px 8px;
            background: #6c757d;
            color: white;
            border-radius: 12px;
            font-size: 9px;
            margin-left: 5px;
        }
        
        .badge-assinado {
            background: #17a2b8;
            padding: 3px 8px;
            border-radius: 3px;
            color: white;
            font-size: 9px;
            font-weight: bold;
            display: inline-block;
            margin-top: 5px;
        }

        .botoes-topo {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            background: white;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 100;
        }
        
        .btn {
            flex: 1;
            padding: 10px 15px;
            background: #1e3c72;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .btn-secundario {
            background: #6c757d;
        }
        
        .btn-secundario:hover {
            background: #5a6268;
        }
        
        .anexo {
            margin-top: 40px;
            padding: 20px;
            background: #f9f9f9;
            border-left: 4px solid #ff9800;
        }
        
        .anexo h3 {
            color: #ff9800;
            margin-bottom: 15px;
        }
        
        .checklist-item {
            margin: 10px 0;
            display: flex;
            align-items: center;
        }
        
        .checklist-item input {
            margin-right: 10px;
            width: 20px;
            height: 20px;
        }
        
        .tabela-cancelamento {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .tabela-cancelamento th, .tabela-cancelamento td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .tabela-cancelamento th {
            background: #ff9800;
            color: white;
        }
        
        .tabela-orcamento {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .tabela-orcamento th, .tabela-orcamento td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .tabela-orcamento th {
            background: #1e3c72;
            color: white;
        }
        
        .termo-recebimento {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .termo-recebimento h4 {
            color: #2e7d32;
            margin-bottom: 15px;
        }
        
        .teste-item {
            margin: 10px 0;
            display: flex;
            align-items: center;
        }
        
        .teste-item input {
            margin-right: 10px;
        }

        .equipamento-limpeza {
            background: #fff8e7;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin: 15px 0;
        }
        
        .equipamento-limpeza h4 {
            color: #e67e22;
            margin-bottom: 10px;
        }
        
        .equipamento-limpeza table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        
        .equipamento-limpeza th, .equipamento-limpeza td {
            padding: 8px;
            border-bottom: 1px solid #ffe0b2;
            text-align: left;
        }
        
        .equipamento-limpeza th {
            background: #fff3cd;
            color: #856404;
        }
        
        .fundamentacao-legal {
            background: #e8f0fe;
            border-left: 4px solid #1e3c72;
            padding: 15px;
            margin: 15px 0;
            font-size: 11px;
        }
        
        .fundamentacao-legal h4 {
            color: #1e3c72;
            margin-bottom: 10px;
        }
        
        .fundamentacao-legal ul {
            margin-left: 20px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <!-- BOTÕES SUPERIORES -->
    <div class="botoes-topo">
        <button class="btn" onclick="window.print()">
            🖨️ Imprimir / Salvar PDF
        </button>
        <a href="<?php echo BASE_URL; ?>/assinar_contrato.php" class="btn btn-secundario">
            ✍️ Assinar Contrato
        </a>
    </div>
    
    <div class="container">
        <!-- HEADER DA EMPRESA -->
        <div class="header">
            <h1><?php echo strtoupper($empresa['nome']); ?></h1>
            <h2>PRESTAÇÃO DE SERVIÇOS TÉCNICOS ESPECIALIZADOS EM CLIMATIZAÇÃO</h2>
            <p>CNPJ: <?php echo $empresa['cnpj']; ?> | IE: <?php echo $empresa['ie']; ?></p>
            <p><?php echo $empresa['endereco']; ?></p>
            <p>Fone: <?php echo $empresa['telefone']; ?> | WhatsApp: <?php echo $empresa['whatsapp']; ?> | E-mail: <?php echo $empresa['email']; ?></p>
        </div>
        
        <!-- TÍTULO DO CONTRATO -->
        <div class="titulo-contrato">
            CONTRATO DE PRESTAÇÃO DE SERVIÇOS Nº <?php echo $numero_contrato; ?>
            <?php if ($orcamento['assinado'] == 1): ?>
                <br><span class="badge-assinado">✅ ASSINADO DIGITALMENTE</span>
            <?php endif; ?>
        </div>
        
        <!-- DATA E LOCAL -->
        <p style="text-align: right; margin-bottom: 20px;">
            <strong>Data:</strong> <?php echo $cidade_contrato; ?>, <?php echo $data_contrato; ?>.
        </p>
        
        <!-- DADOS DO CONTRATANTE -->
        <div class="info-cliente">
            <h3>📋 CONTRATANTE</h3>
            <p><strong>Nome/Razão Social:</strong> <?php echo htmlspecialchars($orcamento['cliente_nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>CPF/CNPJ:</strong> <?php echo htmlspecialchars($orcamento['cpf_cnpj'] ?? 'Não informado', ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>E-mail:</strong> <?php echo htmlspecialchars($orcamento['email'] ?? 'Não informado', ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Telefone/WhatsApp:</strong> <?php echo formatarTelefone($orcamento['whatsapp'] ?? $orcamento['telefone'] ?? ''); ?></p>
            <p><strong>Endereço:</strong> 
                <?php 
                $endereco = trim(($orcamento['endereco_rua'] ?? '') . ', ' . 
                               ($orcamento['endereco_numero'] ?? '') . 
                               (!empty($orcamento['endereco_complemento']) ? ' - ' . $orcamento['endereco_complemento'] : '') . ' - ' . 
                               ($orcamento['endereco_bairro'] ?? '') . ', ' . 
                               ($orcamento['endereco_cidade'] ?? '') . '/' . 
                               ($orcamento['endereco_estado'] ?? '') . ' - CEP: ' . 
                               ($orcamento['endereco_cep'] ?? ''));
                echo !empty(trim($endereco)) ? htmlspecialchars($endereco, ENT_QUOTES, 'UTF-8') : 'Não informado';
                ?>
            </p>
        </div>
        
        <!-- DADOS DA EMPRESA (CONTRATADA) -->
        <div class="info-empresa">
            <h3>🏢 CONTRATADA</h3>
            <p><strong><?php echo $empresa['nome']; ?></strong> - CNPJ: <?php echo $empresa['cnpj']; ?></p>
            <p><?php echo $empresa['endereco']; ?></p>
            <p>Fone: <?php echo $empresa['telefone']; ?> | WhatsApp: <?php echo $empresa['whatsapp']; ?></p>
        </div>
        
        <!-- LOCAL DA EXECUÇÃO -->
        <p style="margin-bottom: 15px; font-style: italic;">
            <strong>LOCAL DA EXECUÇÃO DOS SERVIÇOS:</strong> O mesmo endereço do CONTRATANTE, salvo acordo diverso entre as partes.
        </p>
        
        <!-- TABELA DE SERVIÇOS/PRODUTOS -->
        <h3 style="margin: 20px 0 10px;">📦 SERVIÇOS / EQUIPAMENTOS CONTRATADOS / PEÇAS / MATERIAIS</h3>
        <table class="tabela-itens">
            <thead>
                <tr>
                    <th width="5%">Qtd.</th>
                    <th width="10%">Un.</th>
                    <th width="50%">Descrição</th>
                    <th width="15%">Início Previsto</th>
                    <th width="10%">Valor Unit.</th>
                    <th width="10%">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $subtotal_itens = 0;
                foreach ($todos_itens as $item): 
                    $quantidade = isset($item['quantidade']) ? floatval($item['quantidade']) : 1;
                    $valor_unitario = isset($item['valor_unitario']) ? floatval($item['valor_unitario']) : 0;
                    $total_item = $quantidade * $valor_unitario;
                    $subtotal_itens += $total_item;
                    
                    $unidade = 'UN';
                    if (isset($item['categoria_nome'])) {
                        if (stripos($item['categoria_nome'], 'metro') !== false) $unidade = 'MT';
                        elseif (stripos($item['categoria_nome'], 'kg') !== false) $unidade = 'KG';
                        elseif (stripos($item['categoria_nome'], 'litro') !== false) $unidade = 'LT';
                    }
                ?>
                 <tr>
                    <td><?php echo number_format($quantidade, 2, ',', '.'); ?></td>
                    <td><?php echo $unidade; ?></td>
                    <td>
                        <?php echo htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($item['categoria_nome'])): ?>
                            <span class="badge-categoria"><?php echo htmlspecialchars($item['categoria_nome'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo formatarData($orcamento['data_emissao']); ?></td>
                    <td><?php echo formatarMoeda($valor_unitario); ?></td>
                    <td><?php echo formatarMoeda($total_item); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if ($desconto_percentual > 0): ?>
                <tr>
                    <td colspan="5" style="text-align: right;"><strong>Desconto (<?php echo number_format($desconto_percentual, 2, ',', '.'); ?>%):</strong></td>
                    <td><strong style="color: #dc3545;">- <?php echo formatarMoeda($desconto_valor); ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <?php if ($valor_adicional > 0): ?>
                <tr>
                    <td colspan="5" style="text-align: right;"><strong>Valor Adicional (Limpeza Especializada):</strong></td>
                    <td><strong>+ <?php echo formatarMoeda($valor_adicional); ?></strong></td>
                </tr>
                <?php endif; ?>
                
                <tr class="total-row">
                    <td colspan="5" style="text-align: right; font-size: 14px;"><strong>VALOR TOTAL BRUTO:</strong></td>
                    <td style="font-size: 16px; font-weight: bold; color: #28a745;"><?php echo formatarMoeda($valor_total); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- ==================== SEÇÃO DE EQUIPAMENTOS COM LIMPEZA (APARECE APENAS SE HOUVER) ==================== -->
        <?php if (!empty($equipamentos_sujos)): ?>
        <div class="alerta-importante" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h4 style="color: #856404; margin-bottom: 15px;">⚠️ TAXA ADICIONAL DE LIMPEZA ESPECIALIZADA</h4>
            
            <p><strong>Equipamento(s) identificado(s) com necessidade de limpeza especializada:</strong></p>
            <div class="equipamento-limpeza" style="background: white; padding: 15px; border-radius: 5px; margin: 10px 0;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #fff3cd;">
                            <th style="padding: 8px; text-align: left;">Equipamento</th>
                            <th style="padding: 8px; text-align: left;">Marca/Modelo</th>
                            <th style="padding: 8px; text-align: left;">BTU</th>
                            <th style="padding: 8px; text-align: left;">Condições Encontradas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipamentos_sujos as $equip): ?>
                        <tr>
                            <td style="padding: 8px; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($equip['equipamento_nome']); ?></td>
                            <td style="padding: 8px; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($equip['equipamento_marca'] . ' ' . $equip['equipamento_modelo']); ?></td>
                            <td style="padding: 8px; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($equip['equipamento_btu']); ?></td>
                            <td style="padding: 8px; border-bottom: 1px solid #dee2e6;">
                                <?php 
                                $condicoes = [];
                                if ($equip['serpentina_estado'] == 'muito_suja') $condicoes[] = 'Serpentina muito suja';
                                if ($equip['filtros_estado'] == 'entupidos') $condicoes[] = 'Filtros entupidos';
                                if ($equip['ventilador_estado'] == 'barulho') $condicoes[] = 'Ventilador com barulho';
                                if ($equip['ventilador_estado'] == 'folga') $condicoes[] = 'Ventilador com folga';
                                if ($equip['placa_estado'] == 'oxidada') $condicoes[] = 'Placa oxidada';
                                if ($equip['pressao_gas'] == 'baixa') $condicoes[] = 'Pressão de gás baixa';
                                echo implode(', ', $condicoes) ?: 'Acúmulo crítico de sujidade';
                                ?>
                            </td>
                        </tr>
                        <?php if (!empty($equip['observacao'])): ?>
                        <tr>
                            <td colspan="4" style="padding: 5px 8px 10px 8px; font-size: 11px; color: #666;">
                                <strong>Observação:</strong> <?php echo htmlspecialchars($equip['observacao']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <p style="margin: 15px 0;"><strong>Valor adicional cobrado: R$ <?php echo number_format($valor_adicional, 2, ',', '.'); ?></strong> (taxa única por equipamento com contaminação crítica)</p>
            
            <hr style="margin: 15px 0;">
            
            <h4 style="color: #856404;">📌 FUNDAMENTAÇÃO LEGAL E TÉCNICA:</h4>
            
            <p><strong>1. Riscos à Saúde (Legislação Sanitária):</strong><br>
            Conforme a <strong>Lei Federal nº 13.589/2018</strong>, que institui a Política Nacional de Qualidade do Ar Interior (PNAI), e a <strong>Resolução ANVISA - RDC nº 9/2003</strong>, os sistemas de climatização que apresentam acúmulo de sujidade, fungos e bactérias representam risco grave à saúde dos ocupantes. Estudos da <strong>Organização Mundial da Saúde (OMS)</strong> e da <strong>Anvisa</strong> demonstram que ambientes climatizados sem manutenção adequada podem concentrar patógenos causadores de:</p>
            <ul style="margin-left: 20px;">
                <li><strong>Legionelose (Doença do Legionário)</strong> - que vitimou o Ministro da Saúde em 2009, com taxa de letalidade de até 30%;</li>
                <li><strong>Síndrome do Edifício Doente (SED)</strong> - reconhecida pela OMS desde 1982;</li>
                <li><strong>Aspergilose, pneumonia atípica e doenças respiratórias graves</strong>.</li>
            </ul>
            
            <p><strong>2. Responsabilidade Civil e Obrigação de Segurança (CDC):</strong><br>
            O <strong>Art. 14 do Código de Defesa do Consumidor (Lei nº 8.078/90)</strong> estabelece que o fornecedor de serviços responde, independentemente da existência de culpa, pela reparação dos danos causados aos consumidores por defeitos relativos à prestação dos serviços. Assim, a CONTRATADA tem o dever legal de:</p>
            <ul style="margin-left: 20px;">
                <li>Recusar-se a executar o serviço se constatadas condições insalubres que possam colocar em risco a saúde do consumidor ou de seus prepostos;</li>
                <li>Exigir a realização de limpeza especializada antes da instalação ou manutenção;</li>
                <li>Informar claramente os riscos e os custos adicionais necessários para sanear a contaminação.</li>
            </ul>
            
            <p><strong>3. Previsão Contratual e Boa-fé Objetiva (Art. 422 do Código Civil):</strong><br>
            A <strong>Cláusula 4ª do presente Contrato</strong> já prevê a possibilidade de acréscimos decorrentes de condições não previamente identificadas. A cobrança adicional pela limpeza especializada encontra amparo no princípio da boa-fé objetiva e no dever de transparência, sendo comunicada expressamente antes da execução, com discriminação dos motivos e valores.</p>
            
            <p><strong>4. Obrigação do CONTRATANTE quanto à manutenção (Art. 602 do Código Civil):</strong><br>
            O <strong>Art. 602 do Código Civil</strong> dispõe que o dono da obra é obrigado a fornecer os materiais necessários à execução do serviço. A limpeza prévia do equipamento é condição essencial para a correta execução da instalação/manutenção contratada, sob pena de comprometimento do resultado final e da garantia.</p>
            
            <div style="background: #d4edda; padding: 15px; margin: 15px 0; border-radius: 5px;">
                <p style="margin: 0; color: #155724;"><strong>✅ CONCLUSÃO:</strong> A cobrança adicional de R$ <?php echo number_format($valor_adicional, 2, ',', '.'); ?> refere-se exclusivamente ao serviço técnico especializado de limpeza profunda do(s) equipamento(s) listado(s), indispensável para:</p>
                <ul style="margin: 10px 0 0 20px; color: #155724;">
                    <li>Garantir a eficiência energética e o perfeito funcionamento do sistema;</li>
                    <li>Eliminar riscos à saúde dos ocupantes do ambiente;</li>
                    <li>Cumprir as exigências sanitárias (ANVISA/OMS);</li>
                    <li>Preservar a validade da garantia oferecida pela CONTRATADA.</li>
                </ul>
            </div>
            
            <p style="margin-top: 15px; font-size: 11px; color: #856404;">
                <strong>Data da vistoria técnica:</strong> <?php echo date('d/m/Y'); ?><br>
                <strong>Responsável pela vistoria:</strong> Equipe Técnica Império AR<br>
                <strong>Fundamentação adicional:</strong> Portaria MS/GM nº 3.523/1998 (diretrizes para manutenção de sistemas de climatização), Resolução CONAMA nº 382/2006 (controle de emissões), e Recomendação nº 1/2021 do Ministério Público do Trabalho sobre qualidade do ar em ambientes climatizados.
            </p>
        </div>
        <?php endif; ?>
        
        <!-- FLUXO DE CAIXA ESTRUTURADO -->
        <h3 style="margin: 20px 0 10px;">💰 FLUXO DE CAIXA - CONDIÇÕES DE PAGAMENTO</h3>
        <table class="tabela-fluxo-caixa">
            <thead>
                <tr>
                    <th width="40%">ETAPA</th>
                    <th width="30%">PERCENTUAL</th>
                    <th width="30%">VALOR</th>
                </tr>
            </thead>
            <tbody>
                 <tr>
                    <td><strong>1. SINAL DE MOBILIZAÇÃO</strong> (Antes do início)</td>
                    <td>30%</td>
                    <td><strong><?php echo formatarMoeda($valor_sinal); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>2. PAGAMENTOS SEMANAIS</strong> (Conforme execução)</td>
                    <td>70%</td>
                    <td><strong><?php echo formatarMoeda($valor_semanal_total); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>3. PAGAMENTO NO ATO DA ENTREGA</strong></td>
                    <td>95%</td>
                    <td><strong style="color: #ff5722;"><?php echo formatarMoeda($valor_pagamento_entrega); ?></strong></td>
                </tr>
                <tr style="background: #e8f5e9;">
                    <td><strong>Menos: Retenção de Garantia (5%)</strong></td>
                    <td>-5%</td>
                    <td><strong style="color: #28a745;">- <?php echo formatarMoeda($valor_retencao); ?></strong></td>
                </tr>
                <tr class="total-row">
                    <td><strong>VALOR TOTAL A PAGAR</strong></td>
                    <td><strong>100%</strong></td>
                    <td><strong style="color: #d32f2f; font-size: 16px;"><?php echo formatarMoeda($valor_total); ?></strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="alerta-importante">
            <strong>⚠️ ATENÇÃO - PAGAMENTO OBRIGATÓRIO NO ATO DA ENTREGA:</strong>
            <ul>
                <li>O serviço <strong>NÃO será iniciado</strong> sem confirmação do sinal de 30% até 24 horas antes.</li>
                <li>Pagamentos semanais devem ser realizados conforme andamento da obra.</li>
                <li><strong>NO ATO DA ENTREGA E RECEBIMENTO TÉCNICO</strong>, o CONTRATANTE é obrigado a realizar o pagamento integral de <strong><?php echo formatarMoeda($valor_total); ?></strong>. A empresa reterá apenas 5% (<strong><?php echo formatarMoeda($valor_retencao); ?></strong>) como garantia. Caso não pague no ato da entrega, a obra não será considerada concluída e incidirá multa de 5% + juros de 1% ao mês.</li>
            </ul>
        </div>
        
        <!-- VALOR POR EXTENSO -->
        <div class="valores">
            <p><strong>Valor por extenso:</strong> <?php echo $valor_extenso; ?>.</p>
            <p><strong>Valor a Pagar no Ato da Entrega:</strong> <?php echo formatarMoeda($valor_pagamento_entrega); ?> (95% do total).</p>
            <p><strong>Retenção de Garantia (90 dias):</strong> <?php echo formatarMoeda($valor_retencao); ?> (5% do total).</p>
            <p><strong>Forma de Pagamento:</strong> Dinheiro (sem acréscimos), transferência bancária, boleto bancário ou cartão de crédito. OBS: Transferências bancárias, boletos e pagamentos em cartão poderão ter acréscimos de taxas operacionais nos valores. Apenas pagamentos em dinheiro não sofrerão alterações.</p>
            <!--<p><strong>Nota Fiscal:</strong> Caso o CONTRATANTE exija a emissão de Nota Fiscal (NF-e/NFS-e), será acrescido ao valor total do serviço entre 15% (quinze por cento) a 25% (vinte e cinco por cento), conforme alíquota de ISS (Imposto Sobre Serviços) vigente no município sede da CONTRATADA, <?php echo $empresa['cidade']; ?>/<?php echo $empresa['uf']; ?>, conforme Lei Complementar nº 116/03.</p>-->
        </div>
        
        <!-- CLÁUSULAS DO CONTRATO -->
        <div class="clausulas">
            <h3 style="margin-bottom: 15px;">⚖️ CLÁUSULAS CONTRATUAIS</h3>
            
            <div class="clausula">
                <h4>CLÁUSULA 1ª - DO OBJETO E VINCULAÇÃO</h4>
                <p><strong>1.1.</strong> Constitui objeto do presente contrato a prestação dos serviços técnicos especializados em climatização, compreendendo instalação, manutenção (corretiva ou preventiva), reparo, comissionamento e/ou retrofit de sistemas de ar condicionado, incluindo sistemas do tipo VRF (Variable Refrigerant Flow), conforme descrição detalhada na tabela "SERVIÇOS / EQUIPAMENTOS CONTRATADOS / PEÇAS / MATERIAIS" deste instrumento.</p>
                <p><strong>1.2. Vinculação ao Orçamento:</strong> Este contrato está estritamente vinculado ao Orçamento nº <strong><?php echo htmlspecialchars($orcamento['numero'] ?? '#' . $id, ENT_QUOTES, 'UTF-8'); ?></strong>, emitido em <strong><?php echo formatarData($orcamento['data_emissao']); ?></strong>, que passa a fazer parte integrante deste documento, independente de transcrição. A CONTRATADA obriga-se a seguir rigorosamente as especificações técnicas, marcas, modelos e prazos ali descritos.</p>
                <p><strong>Fundamentação Legal:</strong> Art. 421 do Código Civil - Liberdade contratual e função social do contrato; Art. 422 do Código Civil - Boa-fé objetiva.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 2ª - DA PRECIFICAÇÃO E FORMAS DE PAGAMENTO</h4>
                <p><strong>2.1.</strong> Fica estabelecida a diferenciação de preços entre as modalidades de pagamento em espécie/PIX e cartão de crédito/boleto bancário, conforme autorizado pela <strong>Lei Federal nº 13.473/2017</strong>.</p>
                <p><strong>2.2.</strong> A emissão de Nota Fiscal após a aprovação do orçamento implica a incidência do ISS (Imposto Sobre Serviços) conforme alíquota do município de <?php echo $empresa['cidade']; ?>. O valor do ISS será discriminado na Nota Fiscal e será de responsabilidade do CONTRATANTE, caso não tenha sido incluído no orçamento original, conforme <strong>Lei Complementar nº 116/2003</strong>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 3ª - DO FLUXO DE CAIXA E MEDIÇÃO SEMANAL</h4>
                <p><strong>3.1.</strong> Os pagamentos serão realizados semanalmente, em valor correspondente a, no mínimo, 20% (vinte por cento) do saldo remanescente do contrato, ou conforme cronograma de desembolso atrelado às etapas físicas da obra, a ser definido no orçamento.</p>
                <p><strong>3.2.</strong> A falta de aporte financeiro nas datas acordadas autoriza a CONTRATADA a <strong>paralisar imediatamente os serviços</strong>, sem qualquer ônus ou penalidade, nos termos do <strong>Art. 476 do Código Civil</strong>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 4ª - DA RETENÇÃO DE ENTREGA (5%) E ENCARGOS MORATÓRIOS</h4>
                <p><strong>4.1.</strong> O saldo final correspondente a 5% (cinco por cento) do valor total do contrato será retido como garantia de serviço por 90 (noventa) dias corridos a contar da assinatura do Termo de Recebimento Definitivo.</p>
                <p><strong>4.2.</strong> O atraso no pagamento do saldo final sujeitará o CONTRATANTE aos seguintes encargos, <strong>cobrados cumulativamente</strong>:</p>
                <ul>
                    <li><strong>a)</strong> Multa de mora de 2% (dois por cento) sobre o valor devido;</li>
                    <li><strong>b)</strong> Juros de mora de 1% (um por cento) ao mês, ou fração proporcional, calculados pro rata die.</li>
                </ul>
                <p><strong>Parágrafo Único:</strong> A multa de mora (2%) constitui cláusula penal moratória e os juros (1% a.m.) constituem encargo pela utilização do capital, não constituindo dupla punição, conforme autoriza a <strong>Súmula 388 do Superior Tribunal de Justiça</strong> e o <strong>Art. 52, §1º da Lei nº 8.078/90 (CDC)</strong>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 5ª - DAS DESPESAS DE VIAGEM, ESTADIA, LOCOMOÇÃO E FERRAMENTAS</h4>
                <p><strong>5.1.</strong> Para serviços realizados a distância superior a 60km (sessenta quilômetros) da sede da CONTRATADA, todas as despesas de deslocamento, estadia, locomoção e ferramentas pesadas correrão por conta do CONTRATANTE, nos termos desta cláusula, conforme <strong>Art. 602 do Código Civil</strong>.</p>
                <p><strong>5.2. Do Deslocamento de Ida e Volta (Sede x Local do Serviço):</strong></p>
                <ul>
                    <li><strong>a) Por veículo próprio da CONTRATADA:</strong> Será devido o reembolso no valor de <strong>R$ 2,50 (dois reais e cinquenta centavos) por quilômetro rodado</strong> (considerando-se a distância total de ida e volta), ou conforme tabelas atualizadas da VIABEM ou SINAEM, prevalecendo o maior valor.</li>
                    <li><strong>b) Por veículo fornecido pelo CONTRATANTE:</strong> O CONTRATANTE disponibilizará veículo em perfeitas condições de uso. Neste caso, <strong>não será devido o reembolso de R$ 2,50/km</strong>.</li>
                    <li><strong>c) Por passagens aéreas ou rodoviárias:</strong> O CONTRATANTE providenciará e arcará com a aquisição das passagens de ida e volta.</li>
                </ul>
                <p><strong>5.3. Da Locomoção na Cidade Destino:</strong></p>
                <ul>
                    <li><strong>a) Fornecimento de veículo pelo CONTRATANTE:</strong> O CONTRATANTE disponibilizará veículo adequado.</li>
                    <li><strong>b) Utilização de aplicativos de mobilidade urbana:</strong> Caso o CONTRATANTE opte por não fornecer veículo, arcará com todas as despesas de deslocamento por aplicativos (Uber, 99, táxi) ou locação de veículo.</li>
                </ul>
                <p><strong>5.4. Da Acomodação:</strong> O CONTRATANTE arcará com as despesas de hospedagem em hotel ou flat que possua serviço de quarto, café da manhã, cozinha compacta (para estadias > 3 dias), internet banda larga e estacionamento coberto.</p>
                <p><strong>5.5. Da Alimentação:</strong> O CONTRATANTE arcará com as despesas de alimentação, compreendendo almoço e jantar, mediante reembolso ou per diem.</p>
                <p><strong>5.6. Das Ferramentas e Equipamentos:</strong></p>
                <ul>
                    <li><strong>Ferramentas leves e de uso pessoal:</strong> Jogo de chaves, alicates, multímetro básico, furadeira portátil, etc. - <strong>CONTRATADA</strong></li>
                    <li><strong>Ferramentas pesadas e equipamentos específicos:</strong> Manifold, bomba de vácuo, cilindros de nitrogênio, recuperadora de gás, balança de carga, scanner para VRF, ferramentas para soldagem, andaimes, escadas extensas, perfuratrizes de grande porte - <strong>CONTRATANTE</strong></li>
                </ul>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 6ª - DA INFRAESTRUTURA, SEGURANÇA E ACESSO</h4>
                <p><strong>6.1.</strong> O CONTRATANTE deve fornecer acesso seguro ao local de trabalho, incluindo, quando aplicável, pontos de ancoragem para trabalho em altura, plataformas, andaimes e demais estruturas necessárias para a execução dos serviços em conformidade com as normas de segurança do trabalho (<strong>NR-35 do MTE</strong>).</p>
                <p><strong>6.2.</strong> A recusa da CONTRATADA em executar serviços que apresentem risco iminente à integridade física de seus colaboradores, por falta de condições seguras de trabalho (NR-35, NR-10), <strong>não constitui inadimplemento contratual</strong> e não gera qualquer multa ou penalidade, nos termos do <strong>Art. 157 da CLT</strong>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 7ª - DA RESPONSABILIDADE CIVIL E DANOS</h4>
                <p><strong>7.1.</strong> A CONTRATADA não se responsabiliza por danos causados a objetos não removidos pelo CONTRATANTE das áreas de circulação e trabalho, sendo de responsabilidade exclusiva do CONTRATANTE a remoção prévia de móveis, objetos de valor, obras de arte, equipamentos eletrônicos e demais bens que possam ser danificados durante a execução dos serviços.</p>
                <p><strong>7.2.</strong> A responsabilidade civil da CONTRATADA fica limitada ao valor do contrato, excluindo-se danos morais, lucros cessantes ou danos indiretos, <strong>salvo em caso de dolo, culpa grave, ou em caso de danos pessoais, morte ou lesão corporal</strong>, hipóteses em que a responsabilidade será apurada na forma da lei, sem qualquer limitação (<strong>Súmula 387 do STJ</strong>).</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 8ª - DA GARANTIA E VÍCIOS</h4>
                <p><strong>8.1.</strong> A CONTRATADA oferece garantia mínima de 90 (noventa) dias sobre a instalação executada, contados a partir da data de assinatura do Termo de Recebimento Definitivo (<strong>Art. 26, inciso II da Lei nº 8.078/90 - CDC</strong>).</p>
                <p><strong>8.2.</strong> A garantia limita-se a vícios de instalação, excluindo-se: (a) Defeitos de fabricação dos equipamentos; (b) Danos decorrentes de uso inadequado, falta de manutenção preventiva, oscilações de energia elétrica, ou interferência de terceiros não autorizados.</p>
                <p><strong>8.3.</strong> Em se tratando de equipamentos usados, fica a CONTRATADA isenta de responsabilidade por vícios ocultos preexistentes, sendo o CONTRATANTE ciente das condições do equipamento ao aprovar o orçamento.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 9ª - DO DESCARTE DE RESÍDUOS</h4>
                <p><strong>9.1.</strong> O descarte de entulhos, embalagens, materiais inservíveis e resíduos de construção civil gerados durante a execução dos serviços é de responsabilidade exclusiva do CONTRATANTE, nos termos da legislação ambiental aplicável.</p>
                <p><strong>9.2.</strong> A CONTRATADA se responsabiliza apenas pelo descarte adequado de gases refrigerantes eventualmente recuperados.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 10ª - DO CASO FORTUITO E FORÇA MAIOR</h4>
                <p><strong>10.1.</strong> Chuvas intensas, tempestades, alagamentos, ventanias, eventos climáticos extremos, bem como greves, paralisações, interdições de vias públicas ou qualquer outro evento imprevisível e inevitável, suspendem automaticamente o cronograma de execução dos serviços pelo tempo necessário (<strong>Art. 393 do Código Civil</strong>).</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 11ª - DO DIREITO DE IMAGEM E LGPD</h4>
                <p><strong>11.1.</strong> O CONTRATANTE autoriza a CONTRATADA a realizar registros fotográficos e/ou filmagens dos serviços executados, antes, durante e após a conclusão, para fins de comprovação da execução, divulgação em portfólio técnico, redes sociais e site institucional, <strong>vedada a utilização das imagens para anúncios pagos ou campanhas comerciais de terceiros sem autorização prévia e específica</strong>.</p>
                <p><strong>11.2.</strong> O tratamento de dados pessoais do CONTRATANTE e de seus prepostos observará estritamente a <strong>Lei Geral de Proteção de Dados (Lei nº 13.709/2018)</strong>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 12ª - DA TAXA DE REINCIDÊNCIA</h4>
                <p><strong>12.1.</strong> Chamados técnicos para solução de problemas identificados como decorrentes de causas externas à instalação realizada pela CONTRATADA, tais como falta de energia elétrica, desarme de disjuntor por sobrecarga, mau funcionamento de equipamentos do cliente, problemas na rede elétrica, ou intervenção de terceiros, ensejarão a cobrança de <strong>taxa de visita no valor de R$ 150,00 (cento e cinquenta reais)</strong>, acrescida de mão de obra adicional se necessário.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 13ª - DA RESCISÃO E DESISTÊNCIA</h4>
                <p><strong>13.1.</strong> A desistência do serviço pelo CONTRATANTE, após a aprovação do orçamento, sujeita-o ao pagamento dos <strong>custos já incorridos e comprovados pela CONTRATADA</strong>, compreendendo: mão de obra executada, materiais adquiridos especificamente para a obra, deslocamento e hospedagem já contratados, <strong>até o limite de 10% (dez por cento) do valor total do contrato</strong>.</p>
                <p><strong>13.2.</strong> Além da indenização prevista no item 13.1, em caso de desistência <strong>após o início do deslocamento ou da mobilização de equipe e equipamentos</strong>, o CONTRATANTE arcará com <strong>100% (cem por cento) dos custos já incorridos</strong>, mediante comprovação.</p>
                <p><strong>13.3.</strong> A rescisão unilateral por parte da CONTRATADA, por inadimplemento do CONTRATANTE, não exime este do pagamento dos serviços já executados, acrescidos dos custos já incorridos (<strong>Art. 603 do Código Civil</strong>).</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 14ª - DO TRANSPORTE E LOGÍSTICA DE EQUIPAMENTOS</h4>
                <p><strong>14.1.</strong> A retirada de equipamentos para reparo em oficina ocorrerá por conta e risco do CONTRATANTE, seja quanto ao transporte, seja quanto à guarda e segurança durante o deslocamento, salvo se expressamente acordado de forma diversa em orçamento específico.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 15ª - DA INADIMPLÊNCIA E ÓRGÃOS DE CRÉDITO</h4>
                <p><strong>15.1.</strong> Em caso de inadimplência de qualquer parcela do contrato, fica a CONTRATADA autorizada a proceder ao protesto do título representativo da dívida, bem como à inclusão do nome do CONTRATANTE em cadastros de proteção ao crédito (SPC, SERASA e similares), <strong>após notificação escrita do CONTRATANTE com prazo mínimo de 10 (dez) dias úteis para regularização</strong> (<strong>Art. 43, §2º da Lei nº 8.078/90 - CDC</strong>).</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 16ª - DA IMPOSSIBILIDADE SUPERVENIENTE</h4>
                <p><strong>16.1.</strong> Se, no curso da execução dos serviços, sobrevier fato imprevisível que torne a execução do contrato impossível ou excessivamente onerosa para a CONTRATADA, esta poderá resolver o contrato, devendo restituir ao CONTRATANTE os valores já pagos, deduzidos os custos já incorridos e os serviços já executados (<strong>Art. 478 do Código Civil</strong>).</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 17ª - DA PROTEÇÃO DE DADOS (LGPD)</h4>
                <p><strong>17.1.</strong> A CONTRATADA atuará como Operadora de dados pessoais, tratando as informações estritamente necessárias à execução do presente contrato.</p>
                <p><strong>17.2.</strong> O CONTRATANTE declara estar ciente de que seus dados pessoais e dos seus prepostos serão armazenados pelo prazo de 5 (cinco) anos após o término do contrato, para fins de cumprimento de obrigações legais e fiscais.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 18ª - DAS VISTORIAS E INSPEÇÕES</h4>
                <p><strong>18.1.</strong> Antes do início dos trabalhos, a CONTRATADA realizará vistoria do local para identificar riscos e necessidades, conforme <strong>Checklist de Conformidade NR-35 (Anexo III)</strong>.</p>
                <p><strong>18.2.</strong> Caso a vistoria revele condições não previamente informadas (infiltrações, instalação elétrica inadequada, falta de espaço, etc.), a CONTRATADA poderá solicitar ajustes no orçamento.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 19ª - DO FORO</h4>
                <p><strong>19.1.</strong> As partes elegem o Foro da Comarca de <strong><?php echo $empresa['cidade']; ?>/<?php echo $empresa['uf']; ?></strong> para dirimir quaisquer controvérsias oriundas do presente contrato, com renúncia expressa a qualquer outro, por mais privilegiado que seja.</p>
                <p><strong>19.2.</strong> <strong>Se o CONTRATANTE for pessoa física consumidora de serviço</strong>, poderá optar pelo foro do seu domicílio, nos termos do <strong>Art. 101, inciso I, da Lei nº 8.078/90 (Código de Defesa do Consumidor)</strong>, ou pelo foro eleito acima. <strong>Se o CONTRATANTE for pessoa jurídica</strong>, elegem as partes o foro de <?php echo $empresa['cidade']; ?>/<?php echo $empresa['uf']; ?>.</p>
            </div>
            
            <div class="clausula">
                <h4>CLÁUSULA 20ª - DAS DISPOSIÇÕES GERAIS</h4>
                <p><strong>20.1.</strong> Este contrato constitui o acordo integral entre as partes, substituindo e anulando quaisquer entendimentos, ajustes ou negociações anteriores, verbais ou escritas.</p>
                <p><strong>20.2.</strong> Em caso de dúvida ou conflito na interpretação deste contrato, prevalecerá a interpretação mais favorável ao CONTRATANTE, <strong>quando este for consumidor pessoa física</strong>, nos termos do <strong>Art. 47 da Lei nº 8.078/90 (CDC)</strong>.</p>
            </div>
        </div>
        
        <!-- ANEXO II - ORÇAMENTO DETALHADO -->
        <div class="anexo">
            <h3>📊 ANEXO II - ORÇAMENTO DETALHADO</h3>
            <table class="tabela-orcamento">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Descrição do Serviço</th>
                        <th>Quantidade</th>
                        <th>Valor Unitário</th>
                        <th>Valor Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $item_num = 1;
                    foreach ($todos_itens as $item): 
                        $quantidade = isset($item['quantidade']) ? floatval($item['quantidade']) : 1;
                        $valor_unitario = isset($item['valor_unitario']) ? floatval($item['valor_unitario']) : 0;
                        $total_item = $quantidade * $valor_unitario;
                    ?>
                    <tr>
                        <td><?php echo $item_num++; ?></td>
                        <td><?php echo htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($quantidade, 2, ',', '.'); ?></td>
                        <td><?php echo formatarMoeda($valor_unitario); ?></td>
                        <td><?php echo formatarMoeda($total_item); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($desconto_percentual > 0): ?>
                    <tr>
                        <td colspan="4" style="text-align: right;"><strong>Desconto (<?php echo number_format($desconto_percentual, 2, ',', '.'); ?>%)</strong></td>
                        <td><strong style="color: #dc3545;">- <?php echo formatarMoeda($desconto_valor); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($valor_adicional > 0): ?>
                    <tr>
                        <td colspan="4" style="text-align: right;"><strong>Valor Adicional (Limpeza Especializada)</strong></td>
                        <td><strong>+ <?php echo formatarMoeda($valor_adicional); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #f8f9fa; font-weight: bold;">
                        <td colspan="4" style="text-align: right;"><strong>VALOR TOTAL</strong></td>
                        <td><strong><?php echo formatarMoeda($valor_total); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            
            <p><strong>Tipo de Pagamento:</strong> _________________________________</p>
            <p><strong>Cronograma de Pagamento (Etapas):</strong></p>
            <table class="tabela-orcamento">
                <thead>
                    <tr>
                        <th>Etapa</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Data Prevista</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>Sinal de Mobilização (30%)</td>
                        <td><?php echo formatarMoeda($valor_sinal); ?></td>
                        <td>Até 24h antes do início</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>Pagamentos Semanais (70%)</td>
                        <td><?php echo formatarMoeda($valor_semanal_total); ?></td>
                        <td>Conforme execução</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>Pagamento no Ato da Entrega (95%)</td>
                        <td><?php echo formatarMoeda($valor_pagamento_entrega); ?></td>
                        <td>No ato da entrega</td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td>Retenção de Garantia (5%)</td>
                        <td><?php echo formatarMoeda($valor_retencao); ?></td>
                        <td>Após 90 dias</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- ANEXO IV - POLÍTICA DE CANCELAMENTO -->
        <div class="anexo">
            <h3>📑 ANEXO IV - POLÍTICA DE CANCELAMENTO</h3>
            
            <h4>1. DO CANCELAMENTO PELO CONTRATANTE</h4>
            <p>O cancelamento do serviço pelo CONTRATANTE seguirá as seguintes regras:</p>
            <table class="tabela-cancelamento">
                <thead>
                    <tr>
                        <th>Período do Cancelamento</th>
                        <th>Valor a ser Pago</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Antes do início do deslocamento</td>
                        <td>Custos administrativos comprovados, <strong>limitados a 10% do valor total</strong></td>
                    </tr>
                    <tr>
                        <td>Após o início do deslocamento</td>
                        <td><strong>100% dos custos já incorridos</strong> (passagens, hospedagem, materiais, mão de obra executada), mediante comprovação</td>
                    </tr>
                </tbody>
            </table>
            
            <h4>2. DO CANCELAMENTO PELA CONTRATADA</h4>
            <p>A CONTRATADA poderá cancelar o contrato nos seguintes casos:</p>
            <ul>
                <li>Inadimplemento do CONTRATANTE (falta de pagamento);</li>
                <li>Falta de condições seguras de trabalho (NR-35, NR-10);</li>
                <li>Impossibilidade superveniente (Art. 478 do Código Civil).</li>
            </ul>
            
            <p><strong>Data:</strong> <?php echo $data_atual; ?></p>
        </div>
        
        <!-- ASSINATURAS DO CONTRATO PRINCIPAL -->
        <p style="margin: 30px 0 10px; text-align: center;">E, por estarem assim justos e contratados, assinam o presente instrumento em 02 (duas) vias de igual teor e forma, juntamente com as testemunhas abaixo.</p>
        
        <p style="text-align: center; margin: 20px 0;"><?php echo $empresa['cidade']; ?>, <?php echo $data_contrato; ?>.</p>
        
        <div class="assinaturas">
            <div class="assinatura">
                <div class="linha-assinatura"></div>
                <div class="assinatura-nome"><?php echo $empresa['nome']; ?></div>
                <div class="assinatura-label">CONTRATADA</div>
            </div>
            
            <div class="assinatura">
                <?php if ($orcamento['assinado'] == 1 && !empty($orcamento['assinatura_arquivo'])): ?>
                    <div class="assinatura-imagem">
                        <img src="<?php echo BASE_URL; ?>/storage/assinaturas/<?php echo htmlspecialchars($orcamento['assinatura_arquivo'], ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Assinatura Digital do Cliente"
                             title="Assinatura digital autenticada">
                    </div>
                    <div class="assinatura-data">
                        Assinado digitalmente em<br>
                        <?php echo date('d/m/Y H:i:s', strtotime($orcamento['data_assinatura'])); ?>
                    </div>
                <?php else: ?>
                    <div class="linha-assinatura"></div>
                <?php endif; ?>
                <div class="assinatura-nome"><?php echo htmlspecialchars($orcamento['cliente_nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="assinatura-label">CONTRATANTE</div>
            </div>
        </div>
        
        <!-- TESTEMUNHAS -->
        <div class="testemunhas">
            <div class="testemunha">
                <p><strong>Testemunha 1:</strong></p>
                <div class="linha"></div>
                <p>Nome: _________________________ CPF: _________________________</p>
            </div>
            
            <div class="testemunha">
                <p><strong>Testemunha 2:</strong></p>
                <div class="linha"></div>
                <p>Nome: _________________________ CPF: _________________________</p>
            </div>
        </div>
        
        <!-- FOOTER COM CERTIFICAÇÃO DIGITAL -->
        <div class="footer">
            <strong>CERTIFICAÇÃO DIGITAL - LEI 14.063/20</strong>
            <p>Este contrato foi gerado eletronicamente em <?php echo date('d/m/Y H:i:s'); ?>.</p>
            <?php if ($orcamento['assinado'] == 1): ?>
                <p style="color: #28a745; font-weight: bold;">✅ Assinado digitalmente em <?php echo date('d/m/Y H:i:s', strtotime($orcamento['data_assinatura'])); ?></p>
            <?php endif; ?>
            <p>Para que tenha validade jurídica, deve ser impresso e assinado pelas partes de forma manuscrita ou via plataforma de assinatura eletrônica certificada.</p>
            <p><strong>Documento gerado por <?php echo $empresa['nome']; ?> - <?php echo $empresa['site']; ?></strong></p>
            <p style="font-size: 8px; color: #999; margin-top: 10px;">Contrato Nº <?php echo $numero_contrato; ?> | ID Orçamento: #<?php echo $id; ?></p>
        </div>
    </div>
    
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</body>
</html>
