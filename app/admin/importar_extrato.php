<?php
/**
 * =====================================================================
 * IMPORTAÇÃO DE EXTRATO BANCÁRIO - COM MÚLTIPLOS ORÇAMENTOS
 * =====================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

global $conexao;
$mensagem = '';
$erro = '';
$transacoes_importadas = [];
$transacoes_existentes = [];
$transacoes_novas = [];

// ===== FUNÇÃO PARA BUSCAR TRANSAÇÃO EXISTENTE =====
function transacaoExiste($conexao, $data, $valor, $descricao, $tipo_conta = 'PJ') {
    $desc_like = '%' . addslashes(substr($descricao, 0, 50)) . '%';
    $sql = "SELECT id, tipo, valor, descricao, categoria, data_transacao, origem 
            FROM financeiro 
            WHERE data_transacao = '$data' 
            AND tipo_conta = '$tipo_conta'
            AND ABS(valor - $valor) < 0.01
            AND (descricao LIKE '$desc_like' OR observacao LIKE '$desc_like')
            LIMIT 1";
    $result = $conexao->query($sql);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

// ===== FUNÇÃO PARA BUSCAR CLIENTE PELA DESCRIÇÃO =====
function buscarClienteNaDescricao($conexao, $descricao) {
    $desc_upper = strtoupper($descricao);
    
    $sql = "SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC";
    $result = $conexao->query($sql);
    
    if ($result) {
        while ($cliente = $result->fetch_assoc()) {
            $nome_cliente = strtoupper($cliente['nome']);
            if (strpos($desc_upper, $nome_cliente) !== false) {
                return $cliente['id'];
            }
        }
    }
    return null;
}

// ===== PARSE DE ARQUIVO OFX =====
function parseOFX($conteudo) {
    $transacoes = [];
    preg_match_all('/<STMTTRN>.*?<\/STMTTRN>/s', $conteudo, $matches);
    
    foreach ($matches[0] as $transacao) {
        preg_match('/<DTPOSTED>(\d{4})(\d{2})(\d{2})/', $transacao, $data_match);
        $data = $data_match ? "{$data_match[1]}-{$data_match[2]}-{$data_match[3]}" : date('Y-m-d');
        preg_match('/<TRNAMT>(-?\d+\.\d+)/', $transacao, $valor_match);
        $valor = $valor_match ? floatval($valor_match[1]) : 0;
        preg_match('/<MEMO>(.*?)<\/MEMO>/', $transacao, $memo_match);
        $descricao = $memo_match ? trim($memo_match[1]) : '';
        if (empty($descricao)) {
            preg_match('/<NAME>(.*?)<\/NAME>/', $transacao, $name_match);
            $descricao = $name_match ? trim($name_match[1]) : '';
        }
        $descricao = trim(preg_replace('/\s+/', ' ', $descricao));
        
        if ($valor != 0 && !empty($descricao)) {
            $transacoes[] = [
                'data' => $data,
                'valor' => abs($valor),
                'tipo' => $valor < 0 ? 'saida' : 'entrada',
                'descricao' => $descricao,
                'original_descricao' => $descricao
            ];
        }
    }
    return $transacoes;
}

// ===== PARSE DE ARQUIVO CSV =====
function parseCSV($conteudo) {
    $transacoes = [];
    $linhas = explode("\n", $conteudo);
    array_shift($linhas);
    
    foreach ($linhas as $linha) {
        if (empty(trim($linha))) continue;
        $dados = str_getcsv($linha);
        if (count($dados) < 2) continue;
        
        $data = isset($dados[0]) ? date('Y-m-d', strtotime($dados[0])) : date('Y-m-d');
        $valor_str = str_replace(['R$', ' ', '.', ','], ['', '', '', '.'], $dados[1] ?? '0');
        $valor = floatval($valor_str);
        $descricao = $dados[3] ?? $dados[2] ?? 'Transação';
        
        if ($valor != 0) {
            $transacoes[] = [
                'data' => $data,
                'valor' => abs($valor),
                'tipo' => $valor < 0 ? 'saida' : 'entrada',
                'descricao' => $descricao,
                'original_descricao' => $descricao
            ];
        }
    }
    return $transacoes;
}

// ===== FUNÇÃO DE IA PARA CLASSIFICAR =====
function iaClassificarTransacao($descricao, $valor, $tipo) {
    $desc_lower = strtolower($descricao);
    
    $palavras_saida = [
        'materiais' => ['wlv', 'material', 'insumo', 'peca', 'ferramenta', 'equipamento', 'produto', 'cobre', 'tubo', 'capacitor', 'compressor'],
        'funcionarios' => ['salario', 'funcionario', 'colaborador', 'ajudante', 'diarista', 'pagamento', 'severino', 'neto'],
        'alimentacao' => ['mercado', 'supermercado', 'restaurante', 'lanche', 'ifood', 'comida', 'padaria'],
        'combustivel' => ['posto', 'gasolina', 'etanol', 'diesel', 'combustivel', 'shell', 'ipiranga'],
        'transporte' => ['uber', 'taxi', 'transporte', 'passagem', 'pedagio'],
        'prolabore' => ['pro-labore', 'retirada', 'pró-labore', 'prolabore']
    ];
    
    $palavras_entrada = [
        'cliente_pagamento' => ['venda', 'servico', 'instalação', 'manutencao', 'limpeza', 'cliente', 'pagamento', 'recebido', 'transferencia', 'deposito', 'pix', 'stone']
    ];
    
    if ($tipo == 'entrada') {
        foreach ($palavras_entrada as $cat => $palavras) {
            foreach ($palavras as $palavra) {
                if (strpos($desc_lower, $palavra) !== false) {
                    return ['categoria' => $cat, 'confianca' => 85];
                }
            }
        }
        return ['categoria' => 'outras_entradas', 'confianca' => 30];
    } else {
        foreach ($palavras_saida as $cat => $palavras) {
            foreach ($palavras as $palavra) {
                if (strpos($desc_lower, $palavra) !== false) {
                    return ['categoria' => $cat, 'confianca' => 80];
                }
            }
        }
        return ['categoria' => 'outras_saidas', 'confianca' => 30];
    }
}

// ===== PROCESSAR UPLOAD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['acao'])) {
    if (isset($_FILES['arquivo_extrato']) && $_FILES['arquivo_extrato']['error'] === UPLOAD_ERR_OK) {
        $arquivo = $_FILES['arquivo_extrato'];
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $conteudo = file_get_contents($arquivo['tmp_name']);
        $tipo_conta = $_POST['tipo_conta'] ?? 'PJ';
        
        if ($extensao === 'ofx' || $extensao === 'ofc') {
            $transacoes_importadas = parseOFX($conteudo);
        } elseif ($extensao === 'csv') {
            $transacoes_importadas = parseCSV($conteudo);
        } else {
            $erro = "Formato não suportado. Use OFX, OFC ou CSV.";
        }
        
        if (!empty($transacoes_importadas)) {
            $transacoes_novas = [];
            $transacoes_existentes = [];
            
            foreach ($transacoes_importadas as $trans) {
                $existente = transacaoExiste($conexao, $trans['data'], $trans['valor'], $trans['descricao'], $tipo_conta);
                if ($existente) {
                    $trans['existente_id'] = $existente['id'];
                    $trans['existente_categoria'] = $existente['categoria'];
                    $transacoes_existentes[] = $trans;
                } else {
                    $classificacao = iaClassificarTransacao($trans['descricao'], $trans['valor'], $trans['tipo']);
                    $trans['categoria_sugerida'] = $classificacao['categoria'];
                    $trans['confianca'] = $classificacao['confianca'];
                    
                    $cliente_id = buscarClienteNaDescricao($conexao, $trans['descricao']);
                    $trans['cliente_sugerido'] = $cliente_id;
                    
                    $transacoes_novas[] = $trans;
                }
            }
            
            $_SESSION['transacoes_existentes'] = $transacoes_existentes;
            $_SESSION['transacoes_novas'] = $transacoes_novas;
            $_SESSION['tipo_conta_importacao'] = $tipo_conta;
            
            if (empty($transacoes_novas)) {
                $mensagem = "Todas as transações já existem no sistema.";
            } else {
                header('Location: ' . BASE_URL . '/app/admin/importar_extrato.php?acao=classificar');
                exit;
            }
        } else {
            $erro = "Nenhuma transação encontrada no arquivo.";
        }
    }
}

// ===== SALVAR CLASSIFICAÇÕES COM MÚLTIPLOS ORÇAMENTOS =====
if (isset($_POST['acao']) && $_POST['acao'] === 'salvar_com_orcamentos') {
    $classificacoes = $_POST['classificacao'] ?? [];
    $descricoes_personalizadas = $_POST['descricao_personalizada'] ?? [];
    $clientes_ids = $_POST['cliente_id'] ?? [];
    $orcamentos_ids = $_POST['orcamento_id'] ?? [];
    $valores_parciais = $_POST['valor_parcial'] ?? [];
    $importadas = 0;
    
    $transacoes_novas = $_SESSION['transacoes_novas'] ?? [];
    $tipo_conta = $_SESSION['tipo_conta_importacao'] ?? 'PJ';
    
    $conexao->begin_transaction();
    
    try {
        foreach ($classificacoes as $index => $categoria) {
            if (empty($categoria)) continue;
            $transacao = $transacoes_novas[$index] ?? null;
            if (!$transacao) continue;
            
            $descricao_final = !empty($descricoes_personalizadas[$index]) ? addslashes($descricoes_personalizadas[$index]) : addslashes($transacao['descricao']);
            $observacao = "Importado do extrato - Original: " . addslashes($transacao['original_descricao']);
            $cliente_id = !empty($clientes_ids[$index]) ? intval($clientes_ids[$index]) : 'NULL';
            
            $sql = "INSERT INTO financeiro (tipo, valor, descricao, categoria, data_transacao, forma_pagamento, observacao, tipo_conta, origem, cliente_id) 
                    VALUES ('{$transacao['tipo']}', {$transacao['valor']}, '$descricao_final', '$categoria', '{$transacao['data']}', 'transferencia', '$observacao', '$tipo_conta', 'importacao', $cliente_id)";
            
            if (!$conexao->query($sql)) {
                throw new Exception("Erro ao inserir no financeiro: " . $conexao->error);
            }
            $financeiro_id = $conexao->insert_id;
            
            // Processar múltiplos orçamentos
            if ($categoria == 'cliente_pagamento' && !empty($orcamentos_ids[$index])) {
                $orcamentos_array = $orcamentos_ids[$index];
                $valores_array = $valores_parciais[$index] ?? [];
                $valor_total_pagamento = $transacao['valor'];
                $total_distribuido = 0;
                
                foreach ($orcamentos_array as $orc_key => $orcamento_id) {
                    if (empty($orcamento_id)) continue;
                    
                    $valor_parcial = isset($valores_array[$orc_key]) 
                        ? floatval(str_replace(',', '.', str_replace('.', '', $valores_array[$orc_key])))
                        : 0;
                    
                    if ($valor_parcial <= 0) continue;
                    $total_distribuido += $valor_parcial;
                    
                    // Buscar orçamento
                    $sql_orc = "SELECT valor_total, COALESCE(valor_pago, 0) as valor_pago FROM orcamentos WHERE id = $orcamento_id";
                    $orc_data = $conexao->query($sql_orc)->fetch_assoc();
                    
                    if ($orc_data) {
                        $novo_valor_pago = $orc_data['valor_pago'] + $valor_parcial;
                        $conexao->query("UPDATE orcamentos SET valor_pago = $novo_valor_pago, data_pagamento = '{$transacao['data']}' WHERE id = $orcamento_id");
                        
                        if ($novo_valor_pago >= $orc_data['valor_total']) {
                            $conexao->query("UPDATE orcamentos SET situacao = 'concluido' WHERE id = $orcamento_id");
                        }
                        
                        $numero_cob = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                        $sql_cob = "INSERT INTO cobrancas (numero, cliente_id, orcamento_id, valor, data_vencimento, status, data_recebimento, tipo_pagamento, observacao) 
                                    VALUES ('$numero_cob', $cliente_id, $orcamento_id, $valor_parcial, CURDATE(), 'recebida', CURDATE(), 'pix', 'Pagamento via importação de extrato')";
                        $conexao->query($sql_cob);
                    }
                }
                
                // Verificar se o total distribuído excede o pagamento
                if ($total_distribuido > $valor_total_pagamento) {
                    throw new Exception("Total distribuído (R$ " . number_format($total_distribuido, 2, ',', '.') . ") excede o valor do pagamento (R$ " . number_format($valor_total_pagamento, 2, ',', '.') . ")");
                }
            }
            
            $importadas++;
        }
        
        $conexao->commit();
        
        unset($_SESSION['transacoes_existentes']);
        unset($_SESSION['transacoes_novas']);
        unset($_SESSION['tipo_conta_importacao']);
        
        $_SESSION['mensagem'] = "$importadas transações importadas com sucesso!";
        header('Location: ' . BASE_URL . '/app/admin/financeiro.php');
        exit;
        
    } catch (Exception $e) {
        $conexao->rollback();
        $erro = "Erro ao processar: " . $e->getMessage();
    }
}

// ===== EXCLUIR TRANSAÇÃO EXISTENTE =====
if (isset($_GET['excluir']) && is_numeric($_GET['excluir'])) {
    $id = intval($_GET['excluir']);
    $sql = "DELETE FROM financeiro WHERE id = $id";
    if ($conexao->query($sql)) {
        $_SESSION['mensagem'] = "Transação excluída com sucesso!";
    } else {
        $erro = "Erro ao excluir: " . $conexao->error;
    }
    header('Location: ' . BASE_URL . '/app/admin/importar_extrato.php');
    exit;
}

// ===== CARREGAR CLIENTES PARA SELECT =====
$clientes_lista = [];
$sql_cli = "SELECT id, nome FROM clientes WHERE ativo = 1 ORDER BY nome ASC";
$result_cli = $conexao->query($sql_cli);
if ($result_cli) {
    while ($row = $result_cli->fetch_assoc()) {
        $clientes_lista[] = $row;
    }
}

// ===== CATEGORIAS =====
$categorias = [
    'entrada' => [
        'cliente_pagamento' => '💰 Pagamento de Cliente (vincula orçamento)',
        'outras_entradas' => '📌 Outras Entradas'
    ],
    'saida' => [
        'materiais' => '🔧 Materiais/Peças',
        'funcionarios' => '👨‍💼 Ajudantes/Funcionários',
        'alimentacao' => '🍔 Alimentação',
        'combustivel' => '⛽ Combustível',
        'transporte' => '🚗 Transporte',
        'prolabore' => '💰 Pró-labore',
        'outras_saidas' => '📌 Outras Saídas'
    ]
];

$transacoes_existentes = $_SESSION['transacoes_existentes'] ?? [];
$transacoes_novas = $_SESSION['transacoes_novas'] ?? [];
$tipo_conta_atual = $_SESSION['tipo_conta_importacao'] ?? 'PJ';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Extrato - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <style>
        :root { --primary: #1e3c72; --secondary: #2a5298; --success: #28a745; --danger: #dc3545; --warning: #ffc107; --info: #17a2b8; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%); min-height: 100vh; }
        .admin-container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 300px; padding: 30px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 20px; background: white; border-radius: 12px; }
        .page-header h1 { color: var(--primary); font-size: 28px; display: flex; align-items: center; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34ce57); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #e04b5a); color: white; }
        .btn-info { background: linear-gradient(135deg, var(--info), #138496); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .card-header h3 { margin: 0; font-size: 18px; }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: white; }
        .table thead { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .table th, .table td { padding: 15px; border-bottom: 1px solid #e0e0e0; text-align: left; vertical-align: top; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cce5ff; color: #004085; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .upload-area { border: 2px dashed #dee2e6; border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: var(--primary); background: #f8f9fa; }
        .valor-positivo { color: var(--success); font-weight: bold; }
        .valor-negativo { color: var(--danger); font-weight: bold; }
        .chip-existente { background: #e9ecef; padding: 5px 10px; border-radius: 20px; font-size: 11px; color: #6c757d; }
        .separator { margin: 30px 0; text-align: center; position: relative; }
        .separator span { background: white; padding: 10px 20px; border-radius: 30px; font-weight: bold; color: #6c757d; }
        .separator:before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #dee2e6; z-index: 0; }
        .separator span { position: relative; z-index: 1; }
        .orcamento-section { background: #e8f4fd; padding: 15px; border-radius: 10px; margin-top: 15px; border-left: 4px solid var(--info); transition: all 0.3s; }
        .orcamento-section.valid { border-left-color: var(--success); background: #e8f8e8; }
        .orcamento-section.invalid { border-left-color: var(--danger); background: #fde8e8; }
        .orcamento-item { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #dee2e6; }
        .orcamento-item:last-child { border-bottom: none; }
        .saldo-info { font-size: 11px; color: #666; margin-top: 5px; margin-left: 5px; }
        @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            
            <?php if (isset($_SESSION['mensagem'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['mensagem']; unset($_SESSION['mensagem']); ?></div>
            <?php endif; ?>
            <?php if ($erro): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ((isset($_GET['acao']) && $_GET['acao'] === 'classificar') && (!empty($transacoes_existentes) || !empty($transacoes_novas))): ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-robot"></i> Análise do Extrato</h1>
                    <div><a href="importar_extrato.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a></div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-microchip"></i> <strong>Classificação Inteligente</strong>
                    <ul style="margin-top:10px; margin-left:20px;">
                        <li>💰 <strong>Pagamentos de cliente</strong> - Selecione o cliente e distribua entre orçamentos</li>
                        <li>🔧 <strong>Compras de materiais</strong> - Atualizam o estoque automaticamente</li>
                        <li>👨‍💼 <strong>Pagamentos de funcionários</strong> - Vinculam ao módulo de ajudantes</li>
                    </ul>
                </div>

                <!-- TRANSAÇÕES JÁ EXISTENTES -->
                <?php if (!empty($transacoes_existentes)): ?>
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #6c757d, #5a6268);">
                        <h3><i class="fas fa-check-circle"></i> Transações Já Existentes (<?php echo count($transacoes_existentes); ?>)</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Data</th><th>Descrição</th><th>Valor</th><th>Tipo</th><th>Status</th><th>Ações</th></tr></thead>
                                <tbody>
                                    <?php foreach ($transacoes_existentes as $trans): ?>
                                    <tr class="table-secondary">
                                        <td><?php echo date('d/m/Y', strtotime($trans['data'])); ?></td>
                                        <td><?php echo htmlspecialchars(substr($trans['descricao'], 0, 60)); ?>...<br>
                                            <span class="chip-existente"><i class="fas fa-database"></i> ID: <?php echo $trans['existente_id']; ?></span>
                                        </td>
                                        <td class="<?php echo $trans['tipo'] == 'entrada' ? 'valor-positivo' : 'valor-negativo'; ?>">
                                            R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                        </td>
                                        <td><span class="badge badge-<?php echo $trans['tipo'] == 'entrada' ? 'success' : 'danger'; ?>"><?php echo $trans['tipo'] == 'entrada' ? 'Entrada' : 'Saída'; ?></span></td>
                                        <td><span class="badge badge-info"><i class="fas fa-check"></i> Já importada</span></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="importar_extrato.php?excluir=<?php echo $trans['existente_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Excluir esta transação?')">
                                                    <i class="fas fa-trash"></i> Excluir
                                                </a>
                                                <a href="financeiro.php?acao=editar&id=<?php echo $trans['existente_id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Editar
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TRANSAÇÕES NOVAS PARA CLASSIFICAR -->
                <?php if (!empty($transacoes_novas)): ?>
                <div class="separator"><span>📌 NOVAS TRANSAÇÕES PARA IMPORTAR (<?php echo count($transacoes_novas); ?>)</span></div>
                
                <form method="POST" id="formImportacao">
                    <input type="hidden" name="acao" value="salvar_com_orcamentos">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <?php foreach ($transacoes_novas as $index => $trans): ?>
                    <div class="card" style="margin-bottom: 20px;" id="card-<?php echo $index; ?>">
                        <div class="card-header" style="background: linear-gradient(135deg, <?php echo $trans['tipo'] == 'entrada' ? '#28a745' : '#dc3545'; ?>, <?php echo $trans['tipo'] == 'entrada' ? '#34ce57' : '#e04b5a'; ?>);">
                            <h3>
                                <i class="fas fa-<?php echo $trans['tipo'] == 'entrada' ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                Transação #<?php echo $index + 1; ?> - R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>
                                <span class="badge badge-light" style="float: right;"><?php echo $trans['tipo'] == 'entrada' ? 'ENTRADA' : 'SAÍDA'; ?></span>
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Descrição Original</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($trans['descricao']); ?>" readonly>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Descrição Final (pode editar)</label>
                                    <input type="text" name="descricao_personalizada[<?php echo $index; ?>]" class="form-control" value="<?php echo htmlspecialchars($trans['descricao']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label>Categoria</label>
                                    <select name="classificacao[<?php echo $index; ?>]" class="form-control categoria-select" data-index="<?php echo $index; ?>" required>
                                        <option value="">Selecione</option>
                                        <?php if ($trans['tipo'] == 'entrada'): ?>
                                            <?php foreach ($categorias['entrada'] as $key => $cat): ?>
                                                <option value="<?php echo $key; ?>" <?php echo ($trans['categoria_sugerida'] ?? '') == $key ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php foreach ($categorias['saida'] as $key => $cat): ?>
                                                <option value="<?php echo $key; ?>" <?php echo ($trans['categoria_sugerida'] ?? '') == $key ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <small class="text-muted">IA sugeriu: <?php echo $trans['categoria_sugerida'] ?? 'nenhuma'; ?> (<?php echo $trans['confianca'] ?? 0; ?>% confiança)</small>
                                </div>
                            </div>
                            
                            <!-- Se for entrada, mostrar campos de cliente e múltiplos orçamentos -->
                            <?php if ($trans['tipo'] == 'entrada'): ?>
                            <div id="orcamento_div_<?php echo $index; ?>" class="orcamento-section" style="display: none;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-user"></i> Cliente</label>
                                        <select name="cliente_id[<?php echo $index; ?>]" id="cliente_<?php echo $index; ?>" class="form-control cliente-select" data-index="<?php echo $index; ?>">
                                            <option value="">Selecione um cliente</option>
                                            <?php foreach ($clientes_lista as $cli): ?>
                                                <option value="<?php echo $cli['id']; ?>" <?php echo ($trans['cliente_sugerido'] ?? '') == $cli['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cli['nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-money-bill-wave"></i> Valor do Pagamento</label>
                                        <input type="text" id="valor_pagamento_<?php echo $index; ?>" class="form-control money" value="R$ <?php echo number_format($trans['valor'], 2, ',', '.'); ?>" readonly style="background: #f0f0f0; font-weight: bold;">
                                    </div>
                                </div>
                                
                                <!-- Lista de orçamentos múltiplos -->
                                <div class="form-group">
                                    <label><i class="fas fa-list"></i> Distribuir entre Orçamentos</label>
                                    <div id="orcamentos_lista_<?php echo $index; ?>" class="orcamentos-multiplos">
                                        <div class="orcamento-item" id="orc_item_<?php echo $index; ?>_0">
                                            <div class="form-row">
                                                <div class="form-group" style="flex: 2;">
                                                    <select name="orcamento_id[<?php echo $index; ?>][]" class="form-control orcamento-select" data-parent="<?php echo $index; ?>" data-idx="0" onchange="atualizarSaldoOrcamento(this, <?php echo $index; ?>, 0)">
                                                        <option value="">Selecione um orçamento</option>
                                                    </select>
                                                </div>
                                                <div class="form-group" style="flex: 1;">
                                                    <input type="text" name="valor_parcial[<?php echo $index; ?>][]" class="form-control money valor-parcial" placeholder="Valor" data-parent="<?php echo $index; ?>" data-idx="0" onchange="recalcularTotal(<?php echo $index; ?>)">
                                                </div>
                                                <div class="form-group" style="flex: 0 0 auto;">
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="removerOrcamento(<?php echo $index; ?>, 0)" style="display: none;">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="saldo-info" id="saldo_info_<?php echo $index; ?>_0" style="font-size: 12px; color: #666; margin-top: 5px; margin-left: 5px;"></div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-success" onclick="adicionarOrcamento(<?php echo $index; ?>)" style="margin-top: 10px;">
                                        <i class="fas fa-plus-circle"></i> Adicionar outro orçamento
                                    </button>
                                </div>
                                
                                <div class="alert alert-info" style="margin-top: 15px; padding: 10px;">
                                    <strong>Total distribuído:</strong> <span id="total_distribuido_<?php echo $index; ?>">R$ 0,00</span>
                                    <span id="alerta_saldo_<?php echo $index; ?>" style="display: none; color: #dc3545; margin-left: 15px;">⚠️ O valor ultrapassa o pagamento!</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="margin-top:30px; text-align:right;">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Importar Transações</button>
                        <a href="importar_extrato.php" class="btn btn-secondary btn-lg">Cancelar</a>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (empty($transacoes_existentes) && empty($transacoes_novas)): ?>
                <div class="alert alert-info">Nenhuma transação encontrada no arquivo para processar.</div>
                <?php endif; ?>

                <script>
                    // Armazenar dados dos orçamentos por cliente
                    let orcamentosPorCliente = {};
                    let contadorOrcamentos = {};
                    let valorTotalPagamento = {};
                    
                    // Função para mostrar/ocultar campos de orçamento baseado na categoria
                    function toggleOrcamentoField(selectCategoria, transacaoIndex) {
                        const categoria = selectCategoria.value;
                        const orcamentoDiv = document.getElementById('orcamento_div_' + transacaoIndex);
                        
                        if (orcamentoDiv) {
                            if (categoria === 'cliente_pagamento') {
                                orcamentoDiv.style.display = 'block';
                                const valorInput = document.getElementById('valor_pagamento_' + transacaoIndex);
                                if (valorInput) {
                                    let valor = valorInput.value.replace('R$ ', '').replace(/\./g, '').replace(',', '.');
                                    valorTotalPagamento[transacaoIndex] = parseFloat(valor) || 0;
                                }
                                recalcularTotal(transacaoIndex);
                            } else {
                                orcamentoDiv.style.display = 'none';
                            }
                        }
                    }
                    
                    // Função para carregar orçamentos via AJAX
                    function carregarOrcamentos(clienteId, transacaoIndex) {
                        if (!clienteId || clienteId === '') return;
                        
                        if (orcamentosPorCliente[clienteId]) {
                            preencherOrcamentosNoSelect(clienteId, transacaoIndex);
                            return;
                        }
                        
                        fetch('ajax_orcamentos.php?cliente_id=' + clienteId)
                            .then(response => response.json())
                            .then(data => {
                                orcamentosPorCliente[clienteId] = data;
                                preencherOrcamentosNoSelect(clienteId, transacaoIndex);
                            })
                            .catch(error => console.error('Erro:', error));
                    }
                    
                    function preencherOrcamentosNoSelect(clienteId, transacaoIndex) {
                        const orcamentos = orcamentosPorCliente[clienteId] || [];
                        const selects = document.querySelectorAll(`#orcamentos_lista_${transacaoIndex} .orcamento-select`);
                        
                        selects.forEach(select => {
                            const currentValue = select.value;
                            select.innerHTML = '<option value="">Selecione um orçamento</option>';
                            
                            if (orcamentos.length === 0) {
                                select.innerHTML += '<option value="" disabled>Nenhum orçamento pendente</option>';
                            } else {
                                orcamentos.forEach(orc => {
                                    const saldoFormatado = parseFloat(orc.saldo_pendente).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                    select.innerHTML += `<option value="${orc.id}" data-saldo="${orc.saldo_pendente}">${orc.numero} - Saldo: R$ ${saldoFormatado} (${orc.situacao})</option>`;
                                });
                            }
                            
                            if (currentValue) select.value = currentValue;
                        });
                    }
                    
                    function atualizarSaldoOrcamento(select, transacaoIndex, idx) {
                        const option = select.options[select.selectedIndex];
                        const saldo = option.getAttribute('data-saldo') || 0;
                        const saldoInfo = document.getElementById(`saldo_info_${transacaoIndex}_${idx}`);
                        
                        if (saldoInfo) {
                            const saldoFormatado = parseFloat(saldo).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                            saldoInfo.innerHTML = `<i class="fas fa-info-circle"></i> Saldo disponível: R$ ${saldoFormatado}`;
                        }
                        
                        const valorInput = document.querySelector(`#orc_item_${transacaoIndex}_${idx} .valor-parcial`);
                        if (valorInput) {
                            let valor = valorInput.value.replace('R$ ', '').replace(/\./g, '').replace(',', '.');
                            valor = parseFloat(valor) || 0;
                            if (valor > parseFloat(saldo)) {
                                valorInput.value = 'R$ ' + parseFloat(saldo).toFixed(2).replace('.', ',');
                            }
                        }
                        recalcularTotal(transacaoIndex);
                    }
                    
                    function adicionarOrcamento(transacaoIndex) {
                        if (!contadorOrcamentos[transacaoIndex]) contadorOrcamentos[transacaoIndex] = 0;
                        contadorOrcamentos[transacaoIndex]++;
                        const novoIdx = contadorOrcamentos[transacaoIndex];
                        const container = document.getElementById(`orcamentos_lista_${transacaoIndex}`);
                        const novoDiv = document.createElement('div');
                        novoDiv.className = 'orcamento-item';
                        novoDiv.id = `orc_item_${transacaoIndex}_${novoIdx}`;
                        
                        novoDiv.innerHTML = `
                            <div class="form-row">
                                <div class="form-group" style="flex: 2;">
                                    <select name="orcamento_id[${transacaoIndex}][]" class="form-control orcamento-select" data-parent="${transacaoIndex}" data-idx="${novoIdx}" onchange="atualizarSaldoOrcamento(this, ${transacaoIndex}, ${novoIdx})">
                                        <option value="">Selecione um orçamento</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <input type="text" name="valor_parcial[${transacaoIndex}][]" class="form-control money valor-parcial" placeholder="Valor" data-parent="${transacaoIndex}" data-idx="${novoIdx}" onchange="recalcularTotal(${transacaoIndex})">
                                </div>
                                <div class="form-group" style="flex: 0 0 auto;">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removerOrcamento(${transacaoIndex}, ${novoIdx})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="saldo-info" id="saldo_info_${transacaoIndex}_${novoIdx}" style="font-size: 12px; color: #666; margin-top: 5px; margin-left: 5px;"></div>
                        `;
                        
                        container.appendChild(novoDiv);
                        
                        const clienteSelect = document.getElementById(`cliente_${transacaoIndex}`);
                        const clienteId = clienteSelect ? clienteSelect.value : null;
                        
                        if (clienteId && orcamentosPorCliente[clienteId]) {
                            const select = novoDiv.querySelector('.orcamento-select');
                            const orcamentos = orcamentosPorCliente[clienteId];
                            select.innerHTML = '<option value="">Selecione um orçamento</option>';
                            orcamentos.forEach(orc => {
                                const saldoFormatado = parseFloat(orc.saldo_pendente).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                select.innerHTML += `<option value="${orc.id}" data-saldo="${orc.saldo_pendente}">${orc.numero} - Saldo: R$ ${saldoFormatado} (${orc.situacao})</option>`;
                            });
                        }
                        
                        const moneyInput = novoDiv.querySelector('.money');
                        if (moneyInput) {
                            $(moneyInput).mask('000.000.000.000.000,00', {reverse: true});
                            moneyInput.addEventListener('blur', () => recalcularTotal(transacaoIndex));
                        }
                    }
                    
                    function removerOrcamento(transacaoIndex, idx) {
                        const item = document.getElementById(`orc_item_${transacaoIndex}_${idx}`);
                        if (item) item.remove();
                        recalcularTotal(transacaoIndex);
                    }
                    
                    function recalcularTotal(transacaoIndex) {
                        let totalDistribuido = 0;
                        const valorTotal = valorTotalPagamento[transacaoIndex] || 0;
                        
                        document.querySelectorAll(`#orcamentos_lista_${transacaoIndex} .valor-parcial`).forEach(input => {
                            let valor = input.value.replace('R$ ', '').replace(/\./g, '').replace(',', '.');
                            valor = parseFloat(valor) || 0;
                            totalDistribuido += valor;
                        });
                        
                        const totalSpan = document.getElementById(`total_distribuido_${transacaoIndex}`);
                        const alertaSpan = document.getElementById(`alerta_saldo_${transacaoIndex}`);
                        const orcamentoDiv = document.getElementById(`orcamento_div_${transacaoIndex}`);
                        
                        if (totalSpan) {
                            totalSpan.innerHTML = 'R$ ' + totalDistribuido.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        }
                        
                        if (alertaSpan) {
                            alertaSpan.style.display = totalDistribuido > valorTotal ? 'inline' : 'none';
                        }
                        
                        if (orcamentoDiv) {
                            if (totalDistribuido > 0 && totalDistribuido <= valorTotal) {
                                orcamentoDiv.classList.add('valid');
                                orcamentoDiv.classList.remove('invalid');
                            } else if (totalDistribuido > valorTotal) {
                                orcamentoDiv.classList.add('invalid');
                                orcamentoDiv.classList.remove('valid');
                            } else {
                                orcamentoDiv.classList.remove('valid', 'invalid');
                            }
                        }
                    }
                    
                    // Inicializar eventos
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.categoria-select').forEach(select => {
                            const index = select.getAttribute('data-index');
                            if (index) {
                                select.addEventListener('change', () => toggleOrcamentoField(select, index));
                                toggleOrcamentoField(select, index);
                            }
                        });
                        
                        document.querySelectorAll('.cliente-select').forEach(select => {
                            const index = select.getAttribute('data-index');
                            if (index) {
                                if (select.value) carregarOrcamentos(select.value, index);
                                select.addEventListener('change', function() {
                                    carregarOrcamentos(this.value, index);
                                    contadorOrcamentos[index] = 0;
                                    const container = document.getElementById(`orcamentos_lista_${index}`);
                                    if (container) {
                                        container.innerHTML = `
                                            <div class="orcamento-item" id="orc_item_${index}_0">
                                                <div class="form-row">
                                                    <div class="form-group" style="flex: 2;">
                                                        <select name="orcamento_id[${index}][]" class="form-control orcamento-select" onchange="atualizarSaldoOrcamento(this, ${index}, 0)">
                                                            <option value="">Selecione um orçamento</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-group" style="flex: 1;">
                                                        <input type="text" name="valor_parcial[${index}][]" class="form-control money valor-parcial" placeholder="Valor" onchange="recalcularTotal(${index})">
                                                    </div>
                                                    <div class="form-group" style="flex: 0 0 auto;">
                                                        <button type="button" class="btn btn-sm btn-danger" onclick="removerOrcamento(${index}, 0)" style="display: none;">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="saldo-info" id="saldo_info_${index}_0"></div>
                                            </div>
                                        `;
                                        const moneyInput = document.querySelector(`#orcamentos_lista_${index} .money`);
                                        if (moneyInput) {
                                            $(moneyInput).mask('000.000.000.000.000,00', {reverse: true});
                                            moneyInput.addEventListener('blur', () => recalcularTotal(index));
                                        }
                                        if (this.value) carregarOrcamentos(this.value, index);
                                    }
                                });
                            }
                        });
                        
                        $('.money').mask('000.000.000.000.000,00', {reverse: true});
                    });
                </script>

            <?php else: ?>
                
                <div class="page-header">
                    <h1><i class="fas fa-cloud-upload-alt"></i> Importar Extrato Bancário</h1>
                    <div>
                        <a href="financeiro.php" class="btn btn-primary"><i class="fas fa-chart-line"></i> Financeiro</a>
                        <a href="gestao_fiscal.php" class="btn btn-info"><i class="fas fa-chart-pie"></i> Gestão Fiscal</a>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Selecione o tipo de conta e faça o upload do extrato</strong>
                    <ul style="margin-top:10px; margin-left:20px;">
                        <li>📄 Formatos aceitos: OFX, OFC, CSV</li>
                        <li>💰 Pagamentos de cliente: a IA sugere cliente e orçamento</li>
                        <li>🔧 Compras de materiais: atualizam o estoque</li>
                        <li>👨‍💼 Pagamentos de funcionários: vinculam automaticamente</li>
                    </ul>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-upload"></i> Upload do Extrato</h3></div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Tipo de Conta *</label>
                                    <select name="tipo_conta" id="tipo_conta" class="form-control" required>
                                        <option value="PJ">🏢 Pessoa Jurídica (PJ) - Conta Empresa</option>
                                        <option value="PF">👤 Pessoa Física (PF) - Conta Pessoal</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="upload-area" id="upload-area">
                                <i class="fas fa-file-invoice-dollar" style="font-size:48px; color:#6c757d; margin-bottom:15px;"></i>
                                <p><strong>Clique ou arraste o arquivo aqui</strong></p>
                                <p class="text-muted">Formatos: OFX, OFC, CSV</p>
                                <input type="file" name="arquivo_extrato" id="arquivo_extrato" accept=".ofx,.ofc,.csv" style="display:none;">
                                <button type="button" class="btn btn-primary" onclick="document.getElementById('arquivo_extrato').click()">
                                    <i class="fas fa-folder-open"></i> Selecionar Arquivo
                                </button>
                            </div>
                            <div id="file-info" style="margin-top:15px; display:none;"></div>
                            <div style="margin-top:30px; text-align:right;">
                                <button type="submit" class="btn btn-success btn-lg" id="btn-submit" style="display:none;">
                                    <i class="fas fa-upload"></i> Analisar Extrato
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-line"></i> Resumo Financeiro</h3></div>
                    <div class="card-body">
                        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div class="stat-card" style="padding: 15px; border-left: 4px solid #1e3c72;">
                                <div><strong>💰 Total Entradas PJ</strong><br>
                                <?php 
                                $total_pj = $conexao->query("SELECT COALESCE(SUM(valor),0) as total FROM financeiro WHERE tipo_conta='PJ' AND tipo='entrada'")->fetch_assoc()['total'];
                                echo '<span style="color: #28a745; font-size: 18px;">R$ ' . number_format($total_pj, 2, ',', '.') . '</span>';
                                ?>
                                </div>
                            </div>
                            <div class="stat-card" style="padding: 15px; border-left: 4px solid #dc3545;">
                                <div><strong>📉 Total Saídas PJ</strong><br>
                                <?php 
                                $total_pj_saida = $conexao->query("SELECT COALESCE(SUM(valor),0) as total FROM financeiro WHERE tipo_conta='PJ' AND tipo='saida'")->fetch_assoc()['total'];
                                echo '<span style="color: #dc3545; font-size: 18px;">R$ ' . number_format($total_pj_saida, 2, ',', '.') . '</span>';
                                ?>
                                </div>
                            </div>
                            <div class="stat-card" style="padding: 15px; border-left: 4px solid #28a745;">
                                <div><strong>💰 Total Entradas PF</strong><br>
                                <?php 
                                $total_pf = $conexao->query("SELECT COALESCE(SUM(valor),0) as total FROM financeiro WHERE tipo_conta='PF' AND tipo='entrada'")->fetch_assoc()['total'];
                                echo '<span style="color: #28a745; font-size: 18px;">R$ ' . number_format($total_pf, 2, ',', '.') . '</span>';
                                ?>
                                </div>
                            </div>
                            <div class="stat-card" style="padding: 15px; border-left: 4px solid #ffc107;">
                                <div><strong>📉 Total Saídas PF</strong><br>
                                <?php 
                                $total_pf_saida = $conexao->query("SELECT COALESCE(SUM(valor),0) as total FROM financeiro WHERE tipo_conta='PF' AND tipo='saida'")->fetch_assoc()['total'];
                                echo '<span style="color: #ffc107; font-size: 18px;">R$ ' . number_format($total_pf_saida, 2, ',', '.') . '</span>';
                                ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    const uploadArea = document.getElementById('upload-area');
                    const fileInput = document.getElementById('arquivo_extrato');
                    const fileInfo = document.getElementById('file-info');
                    const submitBtn = document.getElementById('btn-submit');
                    
                    uploadArea.addEventListener('click', () => fileInput.click());
                    uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('drag-over'); });
                    uploadArea.addEventListener('dragleave', () => { uploadArea.classList.remove('drag-over'); });
                    uploadArea.addEventListener('drop', (e) => { 
                        e.preventDefault(); uploadArea.classList.remove('drag-over'); 
                        const file = e.dataTransfer.files[0]; 
                        if (file && (file.name.endsWith('.ofx') || file.name.endsWith('.ofc') || file.name.endsWith('.csv'))) { 
                            fileInput.files = e.dataTransfer.files; 
                            fileInfo.style.display = 'block'; 
                            fileInfo.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> Arquivo: <strong>${file.name}</strong> (${(file.size / 1024).toFixed(2)} KB)</div>`; 
                            submitBtn.style.display = 'inline-flex'; 
                        } else { alert('Formato inválido. Use OFX ou CSV'); } 
                    });
                    
                    fileInput.addEventListener('change', (e) => { 
                        if (e.target.files.length > 0) { 
                            const file = e.target.files[0];
                            fileInfo.style.display = 'block'; 
                            fileInfo.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> Arquivo: <strong>${file.name}</strong> (${(file.size / 1024).toFixed(2)} KB)</div>`; 
                            submitBtn.style.display = 'inline-flex'; 
                        } 
                    });
                </script>

            <?php endif; ?>
        </main>
    </div>
</body>
</html>