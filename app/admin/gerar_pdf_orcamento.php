<?php
/**
 * =====================================================================
 * GERAR PDF DO ORÇAMENTO
 * =====================================================================
 * 
 * Responsabilidade: Gerar PDF de um orçamento específico
 * Chamado por: orcamentos.php (botão PDF)
 * URL: /app/admin/gerar_pdf_orcamento.php?id=XXX
 * 
 * Funcionalidades:
 * - Busca dados do orçamento no banco
 * - Busca itens (produtos e serviços)
 * - Busca equipamentos com necessidade de limpeza para exibir taxa adicional
 * - Gera PDF usando a classe PDF
 * - Exibe no navegador ou força download
 */

// ===== INICIALIZAÇÃO =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/PDF.php';

// Inicia sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VERIFICAÇÃO DE ACESSO =====
if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

// ===== OBTÉM ID DO ORÇAMENTO =====
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?erro=id_invalido');
    exit;
}

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return date('d/m/Y');
    return date('d/m/Y', strtotime($data));
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
    $sql = "SELECT os.*, s.nome, s.valor_unitario, cat.nome as categoria_nome 
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

function buscarEquipamentosComLimpeza($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT equipamento_nome, equipamento_marca, equipamento_modelo, equipamento_btu,
                   serpentina_estado, filtros_estado, ventilador_estado, placa_estado, pressao_gas,
                   observacao
            FROM checklist_equipamentos 
            WHERE orcamento_id = ? AND limpeza_necessaria = 1";
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

// ===== BUSCA DADOS DO ORÇAMENTO =====
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
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?erro=nao_encontrado');
    exit;
}

// Busca itens
$orcamento['produtos'] = buscarItensProdutos($conexao, $id);
$orcamento['servicos'] = buscarItensServicos($conexao, $id);

// Busca equipamentos com necessidade de limpeza
$equipamentos_limpeza = buscarEquipamentosComLimpeza($conexao, $id);
$total_equipamentos_limpeza = count($equipamentos_limpeza);
$total_taxa_limpeza = $total_equipamentos_limpeza * 350.00;

// ===== CONFIGURAÇÕES DA EMPRESA =====
$empresa = [
    'nome' => 'Império AR - Refrigeração',
    'cnpj' => '00.000.000/0001-00',
    'endereco' => 'Rua Exemplo, 123 - Centro, São Paulo/SP',
    'telefone' => '(11) 3333-4444',
    'whatsapp' => '(11) 99999-8888',
    'email' => 'contato@imperioar.com.br'
];

// Tenta buscar configurações do banco
$sql_config = "SELECT * FROM configuracoes WHERE id = 1";
$result_config = $conexao->query($sql_config);
if ($result_config && $row = $result_config->fetch_assoc()) {
    $empresa = [
        'nome' => $row['nome_empresa'] ?? $empresa['nome'],
        'cnpj' => $row['cnpj'] ?? $empresa['cnpj'],
        'endereco' => trim($row['endereco_rua'] ?? '') . ', ' . 
                     trim($row['endereco_numero'] ?? '') . ' - ' . 
                     trim($row['endereco_bairro'] ?? '') . ', ' . 
                     trim($row['endereco_cidade'] ?? '') . '/' . 
                     trim($row['endereco_estado'] ?? ''),
        'telefone' => $row['telefone'] ?? $empresa['telefone'],
        'whatsapp' => $row['whatsapp'] ?? $empresa['whatsapp'],
        'email' => $row['email'] ?? $empresa['email']
    ];
}

// ===== CÁLCULOS FINANCEIROS =====
$subtotal_geral = floatval($orcamento['valor_total']);
$desconto_valor = $subtotal_geral * (floatval($orcamento['desconto_percentual']) / 100);
$valor_adicional = floatval($orcamento['valor_adicional'] ?? 0);
$total_geral = ($subtotal_geral - $desconto_valor) + $valor_adicional;

// ===== GERA O HTML DO PDF =====
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Orçamento #' . ($orcamento['numero'] ?? $id) . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Helvetica", "Arial", sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #1e3c72;
        }
        
        .header h1 {
            color: #1e3c72;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header h2 {
            color: #666;
            font-size: 16px;
            font-weight: normal;
        }
        
        .empresa-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .empresa-info p {
            margin: 3px 0;
        }
        
        .orcamento-info {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
            padding: 15px;
            background: #e9ecef;
            border-radius: 5px;
        }
        
        .cliente-info {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        
        .cliente-info h3 {
            color: #1e3c72;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th {
            background: #1e3c72;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 12px;
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        .total-row {
            font-weight: bold;
            background: #f8f9fa;
        }
        
        .total-row td {
            border-top: 2px solid #1e3c72;
        }
        
        .resumo {
            margin: 20px 0;
            text-align: right;
        }
        
        .resumo p {
            margin: 5px 0;
        }
        
        .resumo .total {
            font-size: 16px;
            font-weight: bold;
            color: #28a745;
        }
        
        .observacoes {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #1e3c72;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .footer p {
            margin: 3px 0;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .badge-pendente { background: #fff3cd; color: #856404; }
        .badge-aprovado { background: #d4edda; color: #155724; }
        .badge-concluido { background: #d1ecf1; color: #0c5460; }
        .badge-cancelado { background: #f8d7da; color: #721c24; }
        
        .alerta-limpeza {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .alerta-limpeza h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .equipamento-item {
            margin: 10px 0;
            padding: 8px;
            background: white;
            border-radius: 3px;
        }
        
        .equipamento-nome {
            font-weight: bold;
            color: #1e3c72;
        }
        
        .condicoes {
            font-size: 11px;
            color: #666;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . $empresa['nome'] . '</h1>
        <h2>ORÇAMENTO</h2>
    </div>
    
    <div class="empresa-info">
        <p><strong>CNPJ:</strong> ' . $empresa['cnpj'] . '</p>
        <p><strong>Endereço:</strong> ' . $empresa['endereco'] . '</p>
        <p><strong>Telefone:</strong> ' . $empresa['telefone'] . ' | <strong>WhatsApp:</strong> ' . $empresa['whatsapp'] . '</p>
        <p><strong>Email:</strong> ' . $empresa['email'] . '</p>
    </div>
    
    <div class="orcamento-info">
        <div>
            <p><strong>Número:</strong> ' . ($orcamento['numero'] ?? 'ORÇ-' . str_pad($id, 5, '0', STR_PAD_LEFT)) . '</p>
            <p><strong>Data de Emissão:</strong> ' . formatarData($orcamento['data_emissao']) . '</p>
            <p><strong>Validade:</strong> ' . formatarData($orcamento['data_validade']) . '</p>
        </div>
        <div>
            <p><strong>Tipo:</strong> ' . ucfirst($orcamento['tipo_registro'] ?? 'Orçamento') . '</p>
            <p><strong>Status:</strong> <span class="badge badge-' . $orcamento['situacao'] . '">' . ucfirst($orcamento['situacao']) . '</span></p>
        </div>
    </div>
    
    <div class="cliente-info">
        <h3>DADOS DO CLIENTE</h3>
        <p><strong>Nome:</strong> ' . htmlspecialchars($orcamento['cliente_nome'] ?? 'Não informado') . '</p>
        <p><strong>CPF/CNPJ:</strong> ' . htmlspecialchars($orcamento['cpf_cnpj'] ?? 'Não informado') . '</p>
        <p><strong>Telefone/WhatsApp:</strong> ' . htmlspecialchars($orcamento['whatsapp'] ?? $orcamento['telefone'] ?? 'Não informado') . '</p>
        <p><strong>Email:</strong> ' . htmlspecialchars($orcamento['email'] ?? 'Não informado') . '</p>
        <p><strong>Endereço:</strong> ' . 
            htmlspecialchars($orcamento['endereco_rua'] ?? '') . ', ' . 
            htmlspecialchars($orcamento['endereco_numero'] ?? '') . ' - ' . 
            htmlspecialchars($orcamento['endereco_bairro'] ?? '') . ', ' . 
            htmlspecialchars($orcamento['endereco_cidade'] ?? '') . '/' . 
            htmlspecialchars($orcamento['endereco_estado'] ?? '') . ' - CEP: ' . 
            htmlspecialchars($orcamento['endereco_cep'] ?? '') . '
        </p>
    </div>';
    
    // Tabela de Produtos
    if (!empty($orcamento['produtos'])) {
        $html .= '<h3 style="margin-top: 20px;">PRODUTOS</h3>
         <table>
            <thead>
                32
                    <th>Produto</th>
                    <th>Qtd</th>
                    <th>Valor Unit.</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>';
        
        $subtotal_produtos = 0;
        foreach ($orcamento['produtos'] as $produto) {
            $subtotal = floatval($produto['quantidade']) * floatval($produto['valor_unitario']);
            $subtotal_produtos += $subtotal;
            
            $html .= '<tr>
                <td>' . htmlspecialchars($produto['nome']) . '</td>
                <td>' . $produto['quantidade'] . '</td>
                <td>R$ ' . number_format($produto['valor_unitario'], 2, ',', '.') . '</td>
                <td>R$ ' . number_format($subtotal, 2, ',', '.') . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
        </table>';
    } else {
        $subtotal_produtos = 0;
    }
    
    // Tabela de Serviços
    if (!empty($orcamento['servicos'])) {
        $html .= '<h3 style="margin-top: 20px;">SERVIÇOS</h3>
        <table>
            <thead>
                32
                    <th>Serviço</th>
                    <th>Qtd</th>
                    <th>Valor Unit.</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>';
        
        $subtotal_servicos = 0;
        foreach ($orcamento['servicos'] as $servico) {
            $subtotal = floatval($servico['quantidade']) * floatval($servico['valor_unitario']);
            $subtotal_servicos += $subtotal;
            
            $html .= '<tr>
                <td>' . htmlspecialchars($servico['nome']) . '</td>
                <td>' . $servico['quantidade'] . '</td>
                <td>R$ ' . number_format($servico['valor_unitario'], 2, ',', '.') . '</td>
                <td>R$ ' . number_format($subtotal, 2, ',', '.') . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
        </table>';
    } else {
        $subtotal_servicos = 0;
    }
    
    $subtotal_geral_calc = $subtotal_produtos + $subtotal_servicos;
    $desconto_valor_calc = $subtotal_geral_calc * (floatval($orcamento['desconto_percentual']) / 100);
    
    // ===== SEÇÃO DE EQUIPAMENTOS COM LIMPEZA (SE HOUVER) =====
    if (!empty($equipamentos_limpeza)) {
        $html .= '<div class="alerta-limpeza">
            <h4>⚠️ TAXA ADICIONAL DE LIMPEZA ESPECIALIZADA</h4>
            <p><strong>Equipamento(s) identificado(s) com necessidade de limpeza especializada:</strong></p>';
        
        foreach ($equipamentos_limpeza as $equip) {
            $condicoes = [];
            if ($equip['serpentina_estado'] == 'muito_suja') $condicoes[] = 'Serpentina muito suja';
            if ($equip['filtros_estado'] == 'entupidos') $condicoes[] = 'Filtros entupidos';
            if ($equip['ventilador_estado'] == 'barulho') $condicoes[] = 'Ventilador com barulho';
            if ($equip['ventilador_estado'] == 'folga') $condicoes[] = 'Ventilador com folga';
            if ($equip['placa_estado'] == 'oxidada') $condicoes[] = 'Placa oxidada';
            if ($equip['pressao_gas'] == 'baixa') $condicoes[] = 'Pressão de gás baixa';
            
            $html .= '<div class="equipamento-item">
                <span class="equipamento-nome">' . htmlspecialchars($equip['equipamento_nome']) . '</span>
                <span> (' . htmlspecialchars($equip['equipamento_marca'] . ' ' . $equip['equipamento_modelo']) . ' - ' . htmlspecialchars($equip['equipamento_btu']) . ' BTU)</span>
                <div class="condicoes">Condições: ' . implode(', ', $condicoes) . '</div>';
            
            if (!empty($equip['observacao'])) {
                $html .= '<div class="condicoes" style="color: #856404;">Obs: ' . htmlspecialchars($equip['observacao']) . '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '<p style="margin-top: 15px;"><strong>Taxa adicional de limpeza:</strong> R$ ' . number_format($total_taxa_limpeza, 2, ',', '.') . ' (' . $total_equipamentos_limpeza . ' equipamento(s) com contaminação crítica)</p>
        <p style="font-size: 11px; color: #856404;">Fundamentação: Art. 14 do CDC, Lei 13.589/2018 (PNAI), RDC ANVISA nº 9/2003</p>
        </div>';
    }
    
    // ===== RESUMO FINANCEIRO =====
    $html .= '<div class="resumo">
        <p><strong>Subtotal Produtos:</strong> ' . formatarMoeda($subtotal_produtos) . '</p>
        <p><strong>Subtotal Serviços:</strong> ' . formatarMoeda($subtotal_servicos) . '</p>
        <p><strong>Subtotal Geral:</strong> ' . formatarMoeda($subtotal_geral_calc) . '</p>';
    
    if (floatval($orcamento['desconto_percentual']) > 0) {
        $html .= '<p><strong>Desconto (' . $orcamento['desconto_percentual'] . '%):</strong> <span style="color: #dc3545;">- ' . formatarMoeda($desconto_valor_calc) . '</span></p>';
    }
    
    if (floatval($orcamento['valor_adicional']) > 0) {
        $html .= '<p><strong>Valor Adicional (Limpeza Especializada):</strong> + ' . formatarMoeda($orcamento['valor_adicional']) . '</p>';
    }
    
    $total_final = ($subtotal_geral_calc - $desconto_valor_calc) + floatval($orcamento['valor_adicional']);
    $html .= '<p class="total"><strong>TOTAL GERAL:</strong> ' . formatarMoeda($total_final) . '</p>
    </div>';
    
    if (!empty($orcamento['observacao'])) {
        $html .= '<div class="observacoes">
            <h4>Observações:</h4>
            <p>' . nl2br(htmlspecialchars($orcamento['observacao'])) . '</p>
        </div>';
    }
    
    $html .= '<div class="footer">
        <p>Este orçamento foi gerado eletronicamente e é válido mediante apresentação.</p>
        <p>Império AR - Especialistas em Conforto Térmico</p>
        <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>
    </div>
</body>
</html>';

// ===== GERA O PDF =====
try {
    // Inicializa classe PDF
    $pdf = new PDF();
    
    // Define o nome do arquivo
    $nome_arquivo = 'orcamento_' . ($orcamento['numero'] ?? $id) . '_' . date('Ymd') . '.pdf';
    
    // Verifica se existe método para gerar PDF
    if (method_exists($pdf, 'gerarOrcamentoPDF')) {
        // Se existir método específico, usa ele
        $pdf->gerarOrcamentoPDF($html, $nome_arquivo);
    } else {
        // Fallback: exibe HTML (para desenvolvimento)
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $nome_arquivo . '"');
        echo $html;
        exit;
    }
    
} catch (Exception $e) {
    // Em caso de erro, registra log e redireciona
    error_log("Erro ao gerar PDF: " . $e->getMessage());
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?erro=pdf_falhou');
    exit;
}
