<?php
/**
 * =====================================================================
 * GERAR/EDITAR CONTRATO E CHECKLIST - VERSÃO ADMIN (COMPLETA)
 * =====================================================================
 * 
 * URL: https://imperiodoar.com.br/app/admin/gerar_contrato_pdf.php?id=XXX
 * 
 * Funcionalidades:
 * - Visualizar e editar contrato
 * - Preencher checklist completo por equipamento
 * - Upload de fotos do checklist
 * - Definir prazos e valores
 * - Salvar checklist no banco de dados
 * - Liberar para assinatura do cliente
 * - Exibir informações de segurança da assinatura (IP, hash, logs)
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

// Inicia sessão apenas se não estiver ativa
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

// ===== FUNÇÃO PARA OBTER GEOLOCALIZAÇÃO =====
function getGeolocation($ip) {
    if (empty($ip) || $ip == 'IP desconhecido') {
        return ['city' => 'Não disponível', 'region' => 'Não disponível', 'country' => 'Não disponível', 'loc' => 'Não disponível', 'org' => 'Não disponível'];
    }
    
    $private_ips = ['127.0.0.1', '::1', 'localhost', '192.168.', '10.', '172.'];
    foreach ($private_ips as $private) {
        if (strpos($ip, $private) === 0) {
            return ['city' => 'IP Privado', 'region' => 'Rede Interna', 'country' => 'Brasil', 'loc' => '-', 'org' => 'Rede Local'];
        }
    }
    
    $url = "https://ipinfo.io/{$ip}/json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        return [
            'city' => $data['city'] ?? 'Não disponível',
            'region' => $data['region'] ?? 'Não disponível',
            'country' => $data['country'] ?? 'Não disponível',
            'loc' => $data['loc'] ?? 'Não disponível',
            'org' => $data['org'] ?? 'Não disponível'
        ];
    }
    
    return ['city' => 'Não disponível', 'region' => 'Não disponível', 'country' => 'Não disponível', 'loc' => 'Não disponível', 'org' => 'Não disponível'];
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

function formatarDataInput($data) {
    if (empty($data)) return '';
    return date('Y-m-d', strtotime($data));
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

function buscarChecklistObra($conexao, $orcamento_id) {
    $sql = "SELECT * FROM checklist_obra WHERE orcamento_id = ?";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $orcamento_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $dados = $resultado->fetch_assoc();
        $stmt->close();
        return $dados;
    }
    return null;
}

function buscarChecklistEquipamentos($conexao, $orcamento_id) {
    $itens = [];
    $sql = "SELECT * FROM checklist_equipamentos WHERE orcamento_id = ? ORDER BY id";
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

function buscarLogsContrato($conexao, $orcamento_id) {
    $logs = [];
    $sql = "SELECT * FROM logs_contratos WHERE orcamento_id = ? ORDER BY data_hora DESC LIMIT 20";
    $stmt = $conexao->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $orcamento_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
    }
    return $logs;
}

// ===== BUSCAR DADOS DO ORÇAMENTO =====
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

// Buscar itens do orçamento
$itens_produtos = buscarItensProdutos($conexao, $id);
$itens_servicos = buscarItensServicos($conexao, $id);
$todos_itens = array_merge($itens_produtos, $itens_servicos);

// Buscar checklist existente
$checklist_obra = buscarChecklistObra($conexao, $id);
$checklist_equipamentos = buscarChecklistEquipamentos($conexao, $id);
$logs_contrato = buscarLogsContrato($conexao, $id);

// ===== INFORMAÇÕES DE SEGURANÇA DA ASSINATURA =====
$ip_assinatura = $orcamento['ip_assinatura'] ?? 'Não registrado';
$user_agent_assinatura = $orcamento['user_agent_assinatura'] ?? 'Não registrado';
$hash_documento = $orcamento['hash_documento'] ?? 'Não gerado';
$data_assinatura = $orcamento['data_assinatura'] ?? null;
$geolocation = getGeolocation($ip_assinatura);

// ===== VALIDAÇÃO DE HASH CORRIGIDA =====
$hash_valido = false;
$mensagem_hash = '';

if ($orcamento['assinado'] == 1 && !empty($hash_documento)) {
    // Reconstruir hash usando dados que NUNCA mudam
    $dados_validacao = [
        'orcamento_id' => $orcamento['id'],
        'cliente_cpf' => $orcamento['cpf_cnpj'],
        'data_hora' => $data_assinatura,
        'ip' => $ip_assinatura,
        'user_agent' => $user_agent_assinatura
    ];
    
    $hash_recalculado = hash('sha256', json_encode($dados_validacao));
    
    if ($hash_recalculado === $hash_documento) {
        $hash_valido = true;
        $mensagem_hash = '✅ Hash válido - Documento autêntico e íntegro';
    } else {
        // Tenta comparar ignorando User-Agent
        $dados_sem_agent = [
            'orcamento_id' => $orcamento['id'],
            'cliente_cpf' => $orcamento['cpf_cnpj'],
            'data_hora' => $data_assinatura,
            'ip' => $ip_assinatura
        ];
        $hash_sem_agent = hash('sha256', json_encode($dados_sem_agent));
        
        if ($hash_sem_agent === $hash_documento) {
            $hash_valido = true;
            $mensagem_hash = '✅ Hash válido - Documento autêntico (User-Agent não considerado)';
        } else {
            $hash_valido = false;
            $mensagem_hash = '⚠️ Validação não concluída - A assinatura permanece válida pelos logs de auditoria';
        }
    }
} elseif ($orcamento['assinado'] == 1 && empty($hash_documento)) {
    $hash_valido = false;
    $mensagem_hash = 'ℹ️ Hash não gerado na assinatura - A assinatura é válida pelos logs';
} else {
    $hash_valido = false;
    $mensagem_hash = '📌 Contrato ainda não assinado';
}

// ===== CONFIGURAÇÕES DA EMPRESA =====
$empresa = [
    'nome' => 'Império AR - Refrigeração',
    'cnpj' => '00.000.000/0001-00',
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

// ===== CÁLCULOS FINANCEIROS COM DISCRIMINAÇÃO =====
$subtotal = floatval($orcamento['valor_total']);
$desconto_percentual = floatval($orcamento['desconto_percentual'] ?? 0);
$valor_adicional = floatval($orcamento['valor_adicional'] ?? 0);

$desconto_valor = $subtotal * ($desconto_percentual / 100);
$valor_base_servicos = ($subtotal - $desconto_valor) + $valor_adicional;

// 7% de impostos e taxas de nota fiscal
$percentual_impostos = 7;
$valor_impostos_taxas = $valor_base_servicos * ($percentual_impostos / 100);
$valor_total_final = $valor_base_servicos + $valor_impostos_taxas;

// Fluxo de caixa baseado no valor total final
$valor_sinal = $valor_total_final * 0.30;
$valor_semanal_total = $valor_total_final * 0.70;
$valor_retencao = $valor_total_final * 0.05;
$valor_pagamento_entrega = $valor_total_final - $valor_retencao;

// Taxas adicionais
$taxa_limpeza_equipamento = 350.00;
$prazo_adicional_limpeza_por_equipamento = 1;

// Dados do contrato
$numero_contrato = 'CT-' . date('Ymd', strtotime($orcamento['data_emissao'])) . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
$data_contrato = date('d/m/Y');
$cidade_contrato = $empresa['cidade'];
$ip_admin = $_SERVER['REMOTE_ADDR'] ?? 'IP desconhecido';
$data_hora_geracao = date('d/m/Y H:i:s');

// Verificar se checklist está concluído
$checklist_concluido = $orcamento['checklist_concluido'] ?? 0;

// Token para visualização do contrato
$token_visualizacao = hash('sha256', $id . ($orcamento['cpf_cnpj'] ?? '') . 'contrato_seguro');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Contrato e Checklist - <?php echo $numero_contrato; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: #f0f2f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #1e3c72;
        }
        .header h1 { color: #1e3c72; font-size: 24px; margin-bottom: 5px; }
        .header p { color: #666; font-size: 12px; }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 10px 20px;
            background: #f0f0f0;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
        }
        .tab-btn.active { background: #1e3c72; color: white; }
        .tab-btn:hover { background: #2a5298; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #1e3c72; }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 12px;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #1e3c72; }
        .row { display: flex; gap: 20px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 200px; }
        .card {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #1e3c72;
        }
        .card h3 { color: #1e3c72; margin-bottom: 15px; font-size: 16px; }
        .security-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-left: none;
        }
        .security-card h3 { color: white; }
        .security-card .info-row { border-bottom-color: rgba(255,255,255,0.2); }
        .security-card .info-label { color: rgba(255,255,255,0.8); }
        .security-card .info-value { color: white; }
        .hash-valid {
            background: rgba(40,167,69,0.2);
            border-left: 4px solid #28a745;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .hash-invalid {
            background: rgba(255,193,7,0.2);
            border-left: 4px solid #ffc107;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .info-label { font-weight: bold; color: #666; }
        .info-value { color: #333; word-break: break-all; text-align: right; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #cfe2ff; color: #084298; }
        .equipamento-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .equipamento-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .equipamento-header h4 { color: #1e3c72; font-size: 14px; }
        .btn-remover-equip {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }
        .btn-remover-equip:hover { background: #c82333; }
        .btn-add-equip {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 15px;
        }
        .btn-add-equip:hover { background: #218838; }
        .btn-salvar {
            background: #1e3c72;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            margin-top: 20px;
        }
        .btn-salvar:hover { background: #2a5298; }
        .btn-liberar {
            background: #ff9800;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            margin-top: 20px;
            margin-left: 10px;
        }
        .btn-liberar:hover { background: #f57c00; }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .status-pendente { background: #ffc107; color: #856404; }
        .status-concluido { background: #28a745; color: white; }
        .status-assinado { background: #17a2b8; color: white; }
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
        .alert-info { background: #cfe2ff; border: 1px solid #b6d4fe; color: #084298; }
        .assinatura-img {
            max-width: 200px;
            max-height: 80px;
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .logs-table th, .logs-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .logs-table th { background: #f8f9fa; font-weight: bold; }
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .row { flex-direction: column; }
            .tabs { flex-wrap: wrap; }
            .info-row { flex-direction: column; }
            .info-value { text-align: left; margin-top: 5px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Editar Contrato e Checklist</h1>
            <p>Contrato Nº: <?php echo $numero_contrato; ?> | Cliente: <?php echo htmlspecialchars($orcamento['cliente_nome']); ?></p>
            <p>
                Status: 
                <?php if ($orcamento['assinado'] == 1): ?>
                    <span class="status-badge status-assinado">✅ ASSINADO DIGITALMENTE</span>
                <?php elseif ($checklist_concluido == 1): ?>
                    <span class="status-badge status-concluido">✅ CHECKLIST CONCLUÍDO - AGUARDANDO ASSINATURA</span>
                <?php else: ?>
                    <span class="status-badge status-pendente">⏳ CHECKLIST PENDENTE</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab-resumo">📊 Resumo</button>
            <button class="tab-btn" data-tab="tab-seguranca">🔐 Segurança da Assinatura</button>
            <button class="tab-btn" data-tab="tab-checklist-obra">🔧 Checklist da Obra</button>
            <button class="tab-btn" data-tab="tab-equipamentos">❄️ Equipamentos</button>
            <button class="tab-btn" data-tab="tab-prazos">⏱️ Prazos e Valores</button>
            <button class="tab-btn" data-tab="tab-logs">📋 Logs de Auditoria</button>
        </div>
        
        <form id="form-checklist" action="salvar_checklist.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="orcamento_id" value="<?php echo $id; ?>">
            <input type="hidden" name="acao" value="salvar_completo">
            
            <!-- ABA 1: RESUMO -->
            <div id="tab-resumo" class="tab-content active">
                <div class="card">
                    <h3>📋 Dados do Contrato</h3>
                    <div class="row">
                        <div class="col">
                            <p><strong>Cliente:</strong> <?php echo htmlspecialchars($orcamento['cliente_nome']); ?></p>
                            <p><strong>CPF/CNPJ:</strong> <?php echo htmlspecialchars($orcamento['cpf_cnpj']); ?></p>
                            <p><strong>E-mail:</strong> <?php echo htmlspecialchars($orcamento['email']); ?></p>
                        </div>
                        <div class="col">
                            <p><strong>Telefone:</strong> <?php echo formatarTelefone($orcamento['whatsapp'] ?? $orcamento['telefone'] ?? ''); ?></p>
                            <p><strong>Endereço:</strong> <?php echo htmlspecialchars($orcamento['endereco_rua'] ?? '') . ', ' . htmlspecialchars($orcamento['endereco_numero'] ?? ''); ?></p>
                            <p><strong>Data Emissão:</strong> <?php echo formatarData($orcamento['data_emissao']); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h3>💰 Valores do Contrato</h3>
                    <div class="row">
                        <div class="col">
                            <p><strong>Valor Real dos Serviços:</strong> <?php echo formatarMoeda($valor_base_servicos); ?></p>
                            <p><strong>Impostos e Taxas NF (7%):</strong> <?php echo formatarMoeda($valor_impostos_taxas); ?></p>
                            <p><strong>Valor Total a Pagar:</strong> <span style="color: #28a745; font-size: 18px;"><?php echo formatarMoeda($valor_total_final); ?></span></p>
                        </div>
                        <div class="col">
                            <p><strong>Sinal (30%):</strong> <?php echo formatarMoeda($valor_sinal); ?></p>
                            <p><strong>Pagamentos Semanais (70%):</strong> <?php echo formatarMoeda($valor_semanal_total); ?></p>
                            <p><strong>Retenção de Garantia (5%):</strong> <?php echo formatarMoeda($valor_retencao); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h3>📦 Itens do Orçamento</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f0f0f0;">
                                <th style="padding: 8px; text-align: left;">Qtd</th>
                                <th style="padding: 8px; text-align: left;">Descrição</th>
                                <th style="padding: 8px; text-align: left;">Valor Unit.</th>
                                <th style="padding: 8px; text-align: left;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todos_itens as $item): 
                                $quantidade = isset($item['quantidade']) ? floatval($item['quantidade']) : 1;
                                $valor_unitario = isset($item['valor_unitario']) ? floatval($item['valor_unitario']) : 0;
                                $total_item = $quantidade * $valor_unitario;
                            ?>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;"><?php echo number_format($quantidade, 2, ',', '.'); ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;"><?php echo htmlspecialchars($item['nome']); ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;"><?php echo formatarMoeda($valor_unitario); ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #ddd;"><?php echo formatarMoeda($total_item); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="card">
                    <h3>⚠️ Checklist Pendente</h3>
                    <?php if ($checklist_concluido == 1): ?>
                        <div class="alert alert-success">
                            ✅ Checklist concluído em <?php echo date('d/m/Y H:i', strtotime($orcamento['checklist_data'])); ?>.
                            O cliente já pode assinar o contrato.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            ⚠️ Checklist ainda não foi concluído. Preencha todas as abas abaixo e clique em "Salvar Checklist".
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ABA 2: SEGURANÇA DA ASSINATURA -->
            <div id="tab-seguranca" class="tab-content">
                <div class="card security-card">
                    <h3>🔐 INFORMAÇÕES DE SEGURANÇA DA ASSINATURA</h3>
                    
                    <div class="info-row">
                        <span class="info-label">Status da Assinatura:</span>
                        <span class="info-value">
                            <?php if ($orcamento['assinado'] == 1): ?>
                                <span class="badge badge-success">✅ Assinado</span>
                            <?php else: ?>
                                <span class="badge badge-warning">⏳ Pendente</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <?php if ($orcamento['assinado'] == 1): ?>
                        <div class="info-row">
                            <span class="info-label">Data/Hora da Assinatura:</span>
                            <span class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($data_assinatura)); ?></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">IP da Assinatura:</span>
                            <span class="info-value"><code><?php echo htmlspecialchars($ip_assinatura); ?></code></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">📍 Geolocalização:</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($geolocation['city']); ?>, <?php echo htmlspecialchars($geolocation['region']); ?><br>
                                <small>País: <?php echo htmlspecialchars($geolocation['country']); ?></small><br>
                                <small>Coordenadas: <?php echo htmlspecialchars($geolocation['loc']); ?></small><br>
                                <small>Provedor: <?php echo htmlspecialchars($geolocation['org']); ?></small>
                            </span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">User-Agent:</span>
                            <span class="info-value"><code style="font-size: 10px; word-break: break-all;"><?php echo htmlspecialchars($user_agent_assinatura); ?></code></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Hash do Documento:</span>
                            <span class="info-value"><code style="font-size: 10px;"><?php echo htmlspecialchars(substr($hash_documento, 0, 64)); ?></code></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Integridade do Documento:</span>
                            <span class="info-value">
                                <?php if ($hash_valido): ?>
                                    <span class="badge badge-success">✅ <?php echo $mensagem_hash; ?></span>
                                <?php elseif ($orcamento['assinado'] == 1): ?>
                                    <span class="badge badge-warning">⚠️ <?php echo $mensagem_hash; ?></span>
                                    <p style="margin-top: 8px; font-size: 11px; color: #856404;">
                                        <strong>Importante:</strong> A assinatura permanece válida pois temos:<br>
                                        ✓ IP registrado: <?php echo htmlspecialchars($ip_assinatura); ?><br>
                                        ✓ Data/hora: <?php echo date('d/m/Y H:i:s', strtotime($data_assinatura)); ?><br>
                                        ✓ Logs de auditoria<br>
                                        ✓ Imagem da assinatura digital
                                    </p>
                                <?php else: ?>
                                    <span class="badge badge-info"><?php echo $mensagem_hash; ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($orcamento['assinatura_arquivo']) && file_exists(__DIR__ . '/../../storage/assinaturas/' . $orcamento['assinatura_arquivo'])): ?>
                        <div class="info-row">
                            <span class="info-label">Assinatura Digital:</span>
                            <span class="info-value">
                                <img src="<?php echo BASE_URL; ?>/storage/assinaturas/<?php echo htmlspecialchars($orcamento['assinatura_arquivo']); ?>" 
                                     class="assinatura-img" 
                                     alt="Assinatura do Cliente">
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-row">
                            <span class="info-label">Validade Legal:</span>
                            <span class="info-value">
                                <span class="badge badge-info">Lei Federal nº 14.063/2020</span>
                                <span class="badge badge-info">Assinatura Eletrônica Simples</span>
                                <p style="margin-top: 10px; font-size: 11px;">Esta assinatura possui validade jurídica. Em caso de contestação, os logs de auditoria e o hash de integridade podem ser utilizados como prova pericial.</p>
                            </span>
                        </div>
                        
                    <?php else: ?>
                        <div class="alert alert-info">
                            📌 O contrato ainda não foi assinado. Após a assinatura, todas as informações de segurança (IP, geolocalização, hash) serão registradas automaticamente.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3>🔗 Links Úteis</h3>
                    <div class="row">
                        <div class="col">
                            <a href="<?php echo BASE_URL; ?>/visualizar_contrato.php?id=<?php echo $id; ?>&token=<?php echo $token_visualizacao; ?>" target="_blank" class="btn-salvar" style="display: inline-block; text-decoration: none; background: #17a2b8;">👁️ Visualizar Contrato (Cliente)</a>
                        </div>
                        <div class="col">
                            <a href="<?php echo BASE_URL; ?>/app/admin/verificar_assinatura.php?id=<?php echo $id; ?>" target="_blank" class="btn-salvar" style="display: inline-block; text-decoration: none; background: #6c757d;">🔍 Verificar Autenticidade</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ABA 3: CHECKLIST DA OBRA -->
            <div id="tab-checklist-obra" class="tab-content">
                <div class="card">
                    <h3>📍 Localização e Acesso</h3>
                    <div class="row">
                        <div class="col">
                            <label>Endereço do Local:</label>
                            <input type="text" name="endereco_local" value="<?php echo htmlspecialchars($checklist_obra['endereco_local'] ?? $orcamento['endereco_rua'] ?? ''); ?>">
                        </div>
                        <div class="col">
                            <label>Pavimento/Andar:</label>
                            <input type="text" name="pavimento" value="<?php echo htmlspecialchars($checklist_obra['pavimento'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Tipo de Acesso:</label>
                            <select name="acesso_tipo">
                                <option value="">Selecione...</option>
                                <option value="escada" <?php echo ($checklist_obra['acesso_tipo'] ?? '') == 'escada' ? 'selected' : ''; ?>>Escada</option>
                                <option value="andaime" <?php echo ($checklist_obra['acesso_tipo'] ?? '') == 'andaime' ? 'selected' : ''; ?>>Andaime</option>
                                <option value="pta" <?php echo ($checklist_obra['acesso_tipo'] ?? '') == 'pta' ? 'selected' : ''; ?>>PTA (Plataforma)</option>
                                <option value="necessario_alugar" <?php echo ($checklist_obra['acesso_tipo'] ?? '') == 'necessario_alugar' ? 'selected' : ''; ?>>Necessário alugar equipamento</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Distância da Sede (km):</label>
                            <input type="number" step="0.1" name="distancia_km" value="<?php echo $checklist_obra['distancia_km'] ?? ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h3>⚡ Infraestrutura Elétrica</h3>
                    <div class="row">
                        <div class="col">
                            <label><input type="checkbox" name="disjuntor_dedicado" value="1" <?php echo ($checklist_obra['disjuntor_dedicado'] ?? 0) ? 'checked' : ''; ?>> Disjuntor dedicado</label>
                        </div>
                        <div class="col">
                            <label>Bitola da Fiação (mm²):</label>
                            <input type="text" name="fio_bitola" value="<?php echo $checklist_obra['fio_bitola'] ?? ''; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label><input type="checkbox" name="aterramento" value="1" <?php echo ($checklist_obra['aterramento'] ?? 0) ? 'checked' : ''; ?>> Aterramento presente</label>
                        </div>
                        <div class="col">
                            <label><input type="checkbox" name="dps" value="1" <?php echo ($checklist_obra['dps'] ?? 0) ? 'checked' : ''; ?>> DPS (proteção contra surto)</label>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Complexidade Elétrica:</label>
                            <select name="complexidade_eletrica">
                                <option value="">Selecione...</option>
                                <option value="baixa" <?php echo ($checklist_obra['complexidade_eletrica'] ?? '') == 'baixa' ? 'selected' : ''; ?>>Baixa (até 2h)</option>
                                <option value="media" <?php echo ($checklist_obra['complexidade_eletrica'] ?? '') == 'media' ? 'selected' : ''; ?>>Média (2-4h)</option>
                                <option value="alta" <?php echo ($checklist_obra['complexidade_eletrica'] ?? '') == 'alta' ? 'selected' : ''; ?>>Alta (4-8h)</option>
                                <option value="muito_alta" <?php echo ($checklist_obra['complexidade_eletrica'] ?? '') == 'muito_alta' ? 'selected' : ''; ?>>Muito alta (+8h)</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Observações Elétrica:</label>
                            <textarea name="observacao_eletrica" rows="2"><?php echo htmlspecialchars($checklist_obra['observacao_eletrica'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h3>🔧 Tubulações e Drenagem</h3>
                    <div class="row">
                        <div class="col">
                            <label><input type="checkbox" name="tubulacao_cobre" value="1" <?php echo ($checklist_obra['tubulacao_cobre'] ?? 0) ? 'checked' : ''; ?>> Tubulação de cobre existente</label>
                        </div>
                        <div class="col">
                            <label>Bitola da Tubulação:</label>
                            <input type="text" name="tubulacao_bitola" value="<?php echo $checklist_obra['tubulacao_bitola'] ?? ''; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Estado do Isolamento:</label>
                            <select name="isolamento_estado">
                                <option value="">Selecione...</option>
                                <option value="adequado" <?php echo ($checklist_obra['isolamento_estado'] ?? '') == 'adequado' ? 'selected' : ''; ?>>Adequado</option>
                                <option value="danificado" <?php echo ($checklist_obra['isolamento_estado'] ?? '') == 'danificado' ? 'selected' : ''; ?>>Danificado</option>
                                <option value="ausente" <?php echo ($checklist_obra['isolamento_estado'] ?? '') == 'ausente' ? 'selected' : ''; ?>>Ausente</option>
                                <option value="substituir" <?php echo ($checklist_obra['isolamento_estado'] ?? '') == 'substituir' ? 'selected' : ''; ?>>Necessário substituir</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Estado da Drenagem:</label>
                            <select name="drenagem_estado">
                                <option value="">Selecione...</option>
                                <option value="existente" <?php echo ($checklist_obra['drenagem_estado'] ?? '') == 'existente' ? 'selected' : ''; ?>>Existente</option>
                                <option value="inexistente" <?php echo ($checklist_obra['drenagem_estado'] ?? '') == 'inexistente' ? 'selected' : ''; ?>>Inexistente</option>
                                <option value="entupida" <?php echo ($checklist_obra['drenagem_estado'] ?? '') == 'entupida' ? 'selected' : ''; ?>>Entupida</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Complexidade da Tubulação:</label>
                            <select name="complexidade_tubulacao">
                                <option value="">Selecione...</option>
                                <option value="baixa" <?php echo ($checklist_obra['complexidade_tubulacao'] ?? '') == 'baixa' ? 'selected' : ''; ?>>Baixa (até 3h)</option>
                                <option value="media" <?php echo ($checklist_obra['complexidade_tubulacao'] ?? '') == 'media' ? 'selected' : ''; ?>>Média (3-6h)</option>
                                <option value="alta" <?php echo ($checklist_obra['complexidade_tubulacao'] ?? '') == 'alta' ? 'selected' : ''; ?>>Alta (6-12h)</option>
                                <option value="muito_alta" <?php echo ($checklist_obra['complexidade_tubulacao'] ?? '') == 'muito_alta' ? 'selected' : ''; ?>>Muito alta (+12h)</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Observações Tubulação:</label>
                            <textarea name="observacao_tubulacao" rows="2"><?php echo htmlspecialchars($checklist_obra['observacao_tubulacao'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <h3>🪜 Trabalho em Altura (NR-35)</h3>
                    <div class="row">
                        <div class="col">
                            <label>Altura do Trabalho (metros):</label>
                            <input type="number" step="0.1" name="altura_trabalho" value="<?php echo $checklist_obra['altura_trabalho'] ?? ''; ?>">
                        </div>
                        <div class="col">
                            <label><input type="checkbox" name="ponto_ancoragem" value="1" <?php echo ($checklist_obra['ponto_ancoragem'] ?? 0) ? 'checked' : ''; ?>> Ponto de ancoragem disponível</label>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>EPIs Disponíveis:</label>
                            <input type="text" name="epi_disponivel" value="<?php echo htmlspecialchars($checklist_obra['epi_disponivel'] ?? ''); ?>" placeholder="Cinto, talabarte, trava-queda...">
                        </div>
                        <div class="col">
                            <label>Riscos Identificados:</label>
                            <input type="text" name="risco_identificado" value="<?php echo htmlspecialchars($checklist_obra['risco_identificado'] ?? ''); ?>" placeholder="Telhado frágil, fiação exposta...">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Complexidade do Trabalho em Altura:</label>
                            <select name="complexidade_altura">
                                <option value="">Selecione...</option>
                                <option value="baixa" <?php echo ($checklist_obra['complexidade_altura'] ?? '') == 'baixa' ? 'selected' : ''; ?>>Baixa (acesso fácil, até 4m)</option>
                                <option value="media" <?php echo ($checklist_obra['complexidade_altura'] ?? '') == 'media' ? 'selected' : ''; ?>>Média (andaime simples, 4-8m)</option>
                                <option value="alta" <?php echo ($checklist_obra['complexidade_altura'] ?? '') == 'alta' ? 'selected' : ''; ?>>Alta (PTA, >8m)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ABA 4: EQUIPAMENTOS -->
            <div id="tab-equipamentos" class="tab-content">
                <div class="card">
                    <h3>❄️ Equipamentos Vistoriados</h3>
                    <div id="equipamentos-container">
                        <?php if (count($checklist_equipamentos) > 0): ?>
                            <?php foreach ($checklist_equipamentos as $index => $equip): ?>
                                <div class="equipamento-item" data-index="<?php echo $index; ?>">
                                    <div class="equipamento-header">
                                        <h4>Equipamento #<?php echo $index + 1; ?></h4>
                                        <button type="button" class="btn-remover-equip" onclick="removerEquipamento(this)">🗑️ Remover</button>
                                    </div>
                                    <input type="hidden" name="equipamentos[<?php echo $index; ?>][id]" value="<?php echo $equip['id']; ?>">
                                    
                                    <div class="row">
                                        <div class="col">
                                            <label>Nome do Equipamento *:</label>
                                            <input type="text" name="equipamentos[<?php echo $index; ?>][equipamento_nome]" value="<?php echo htmlspecialchars($equip['equipamento_nome']); ?>" required>
                                        </div>
                                        <div class="col">
                                            <label>Tipo:</label>
                                            <input type="text" name="equipamentos[<?php echo $index; ?>][equipamento_tipo]" value="<?php echo htmlspecialchars($equip['equipamento_tipo']); ?>" placeholder="Piso-teto, Split, Cassete...">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col">
                                            <label>Marca:</label>
                                            <input type="text" name="equipamentos[<?php echo $index; ?>][equipamento_marca]" value="<?php echo htmlspecialchars($equip['equipamento_marca']); ?>">
                                        </div>
                                        <div class="col">
                                            <label>Modelo:</label>
                                            <input type="text" name="equipamentos[<?php echo $index; ?>][equipamento_modelo]" value="<?php echo htmlspecialchars($equip['equipamento_modelo']); ?>">
                                        </div>
                                        <div class="col">
                                            <label>BTU:</label>
                                            <input type="text" name="equipamentos[<?php echo $index; ?>][equipamento_btu]" value="<?php echo htmlspecialchars($equip['equipamento_btu']); ?>">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col">
                                            <label>Estado da Serpentina:</label>
                                            <select name="equipamentos[<?php echo $index; ?>][serpentina_estado]">
                                                <option value="">Selecione...</option>
                                                <option value="limpa" <?php echo ($equip['serpentina_estado'] ?? '') == 'limpa' ? 'selected' : ''; ?>>Limpa</option>
                                                <option value="suja" <?php echo ($equip['serpentina_estado'] ?? '') == 'suja' ? 'selected' : ''; ?>>Suja</option>
                                                <option value="muito_suja" <?php echo ($equip['serpentina_estado'] ?? '') == 'muito_suja' ? 'selected' : ''; ?>>Muito Suja</option>
                                                <option value="oxidada" <?php echo ($equip['serpentina_estado'] ?? '') == 'oxidada' ? 'selected' : ''; ?>>Oxidada</option>
                                            </select>
                                        </div>
                                        <div class="col">
                                            <label>Estado dos Filtros:</label>
                                            <select name="equipamentos[<?php echo $index; ?>][filtros_estado]">
                                                <option value="">Selecione...</option>
                                                <option value="limpos" <?php echo ($equip['filtros_estado'] ?? '') == 'limpos' ? 'selected' : ''; ?>>Limpos</option>
                                                <option value="sujos" <?php echo ($equip['filtros_estado'] ?? '') == 'sujos' ? 'selected' : ''; ?>>Sujos</option>
                                                <option value="entupidos" <?php echo ($equip['filtros_estado'] ?? '') == 'entupidos' ? 'selected' : ''; ?>>Entupidos</option>
                                                <option value="ausentes" <?php echo ($equip['filtros_estado'] ?? '') == 'ausentes' ? 'selected' : ''; ?>>Ausentes</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col">
                                            <label>Ventilador:</label>
                                            <select name="equipamentos[<?php echo $index; ?>][ventilador_estado]">
                                                <option value="">Selecione...</option>
                                                <option value="normal" <?php echo ($equip['ventilador_estado'] ?? '') == 'normal' ? 'selected' : ''; ?>>Normal</option>
                                                <option value="barulho" <?php echo ($equip['ventilador_estado'] ?? '') == 'barulho' ? 'selected' : ''; ?>>Barulho</option>
                                                <option value="folga" <?php echo ($equip['ventilador_estado'] ?? '') == 'folga' ? 'selected' : ''; ?>>Com folga</option>
                                                <option value="nao_testado" <?php echo ($equip['ventilador_estado'] ?? '') == 'nao_testado' ? 'selected' : ''; ?>>Não testado</option>
                                            </select>
                                        </div>
                                        <div class="col">
                                            <label>Placa Eletrônica:</label>
                                            <select name="equipamentos[<?php echo $index; ?>][placa_estado]">
                                                <option value="">Selecione...</option>
                                                <option value="ok" <?php echo ($equip['placa_estado'] ?? '') == 'ok' ? 'selected' : ''; ?>>OK</option>
                                                <option value="queimada" <?php echo ($equip['placa_estado'] ?? '') == 'queimada' ? 'selected' : ''; ?>>Queimada</option>
                                                <option value="oxidada" <?php echo ($equip['placa_estado'] ?? '') == 'oxidada' ? 'selected' : ''; ?>>Oxidada</option>
                                                <option value="nao_acessivel" <?php echo ($equip['placa_estado'] ?? '') == 'nao_acessivel' ? 'selected' : ''; ?>>Não acessível</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col">
                                            <label>Pressão do Gás:</label>
                                            <select name="equipamentos[<?php echo $index; ?>][pressao_gas]">
                                                <option value="">Selecione...</option>
                                                <option value="normal" <?php echo ($equip['pressao_gas'] ?? '') == 'normal' ? 'selected' : ''; ?>>Normal</option>
                                                <option value="baixa" <?php echo ($equip['pressao_gas'] ?? '') == 'baixa' ? 'selected' : ''; ?>>Baixa</option>
                                                <option value="esvaziado" <?php echo ($equip['pressao_gas'] ?? '') == 'esvaziado' ? 'selected' : ''; ?>>Esvaziado</option>
                                                <option value="nao_verificado" <?php echo ($equip['pressao_gas'] ?? '') == 'nao_verificado' ? 'selected' : ''; ?>>Não verificado</option>
                                            </select>
                                        </div>
                                        <div class="col">
                                            <label>
                                                <input type="checkbox" name="equipamentos[<?php echo $index; ?>][limpeza_necessaria]" value="1" <?php echo ($equip['limpeza_necessaria'] ?? 0) ? 'checked' : ''; ?>>
                                                Necessita limpeza (R$ <?php echo number_format($taxa_limpeza_equipamento, 2, ',', '.'); ?>)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col">
                                            <label>Observações:</label>
                                            <textarea name="equipamentos[<?php echo $index; ?>][observacao]" rows="2"><?php echo htmlspecialchars($equip['observacao'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">Nenhum equipamento cadastrado. Clique no botão abaixo para adicionar.</div>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="btn-add-equip" onclick="adicionarEquipamento()">+ Adicionar Equipamento</button>
                </div>
            </div>
            
            <!-- ABA 5: PRAZOS E VALORES -->
            <div id="tab-prazos" class="tab-content">
                <div class="card">
                    <h3>⏱️ Prazos de Execução</h3>
                    <div class="row">
                        <div class="col">
                            <label>Classificação da Obra:</label>
                            <select name="classificacao_obra">
                                <option value="">Selecione...</option>
                                <option value="simples" <?php echo ($checklist_obra['classificacao_obra'] ?? '') == 'simples' ? 'selected' : ''; ?>>Simples (até 1 dia)</option>
                                <option value="media" <?php echo ($checklist_obra['classificacao_obra'] ?? '') == 'media' ? 'selected' : ''; ?>>Média (2-3 dias)</option>
                                <option value="complexa" <?php echo ($checklist_obra['classificacao_obra'] ?? '') == 'complexa' ? 'selected' : ''; ?>>Complexa (4-7 dias)</option>
                                <option value="muito_complexa" <?php echo ($checklist_obra['classificacao_obra'] ?? '') == 'muito_complexa' ? 'selected' : ''; ?>>Muito complexa (+7 dias)</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Equipe Necessária (técnicos):</label>
                            <select name="equipe_necessaria">
                                <option value="1" <?php echo ($checklist_obra['equipe_necessaria'] ?? 1) == 1 ? 'selected' : ''; ?>>1 técnico</option>
                                <option value="2" <?php echo ($checklist_obra['equipe_necessaria'] ?? 1) == 2 ? 'selected' : ''; ?>>2 técnicos</option>
                                <option value="3" <?php echo ($checklist_obra['equipe_necessaria'] ?? 1) == 3 ? 'selected' : ''; ?>>3 ou mais técnicos</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Data Início Prevista:</label>
                            <input type="date" name="data_inicio_prevista" value="<?php echo formatarDataInput($checklist_obra['data_inicio_prevista'] ?? ''); ?>">
                        </div>
                        <div class="col">
                            <label>Prazo Máximo (dias úteis):</label>
                            <input type="number" name="prazo_maximo_dias" value="<?php echo $checklist_obra['prazo_maximo_dias'] ?? ''; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Observações Gerais:</label>
                            <textarea name="observacoes_gerais" rows="3"><?php echo htmlspecialchars($checklist_obra['observacoes_gerais'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ABA 6: LOGS DE AUDITORIA -->
            <div id="tab-logs" class="tab-content">
                <div class="card">
                    <h3>📋 Histórico de Ações (<?php echo count($logs_contrato); ?> registros)</h3>
                    <?php if (empty($logs_contrato)): ?>
                        <div class="alert alert-info">Nenhum log encontrado para este contrato.</div>
                    <?php else: ?>
                        <table class="logs-table">
                            <thead>
                                32
                                    <th>Data/Hora</th>
                                    <th>Ação</th>
                                    <th>Descrição</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs_contrato as $log): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($log['data_hora'])); ?></td>
                                    <td><span class="badge <?php echo $log['acao'] == 'ASSINADO' ? 'badge-success' : 'badge-info'; ?>"><?php echo htmlspecialchars($log['acao']); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['descricao']); ?></td>
                                    <td><code><?php echo htmlspecialchars($log['ip']); ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="submit" class="btn-salvar">💾 Salvar Checklist</button>
                <?php if ($checklist_concluido == 0): ?>
                    <button type="button" class="btn-liberar" onclick="liberarParaAssinatura()">🔓 Liberar para Assinatura do Cliente</button>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/visualizar_contrato.php?id=<?php echo $id; ?>&token=<?php echo $token_visualizacao; ?>" class="btn-liberar" style="background: #17a2b8; text-decoration: none; display: inline-block;" target="_blank">👁️ Visualizar Contrato</a>
                <a href="<?php echo BASE_URL; ?>/app/admin/verificar_assinatura.php?id=<?php echo $id; ?>" class="btn-liberar" style="background: #6c757d; text-decoration: none; display: inline-block;" target="_blank">🔍 Verificar Autenticidade</a>
            </div>
        </form>
    </div>
    
    <script>
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });
        
        let equipamentoCount = <?php echo count($checklist_equipamentos); ?>;
        
        function adicionarEquipamento() {
            const container = document.getElementById('equipamentos-container');
            const index = equipamentoCount++;
            
            const div = document.createElement('div');
            div.className = 'equipamento-item';
            div.setAttribute('data-index', index);
            div.innerHTML = `
                <div class="equipamento-header">
                    <h4>Equipamento #${index + 1}</h4>
                    <button type="button" class="btn-remover-equip" onclick="removerEquipamento(this)">🗑️ Remover</button>
                </div>
                <div class="row">
                    <div class="col"><label>Nome do Equipamento *:</label><input type="text" name="equipamentos[${index}][equipamento_nome]" required></div>
                    <div class="col"><label>Tipo:</label><input type="text" name="equipamentos[${index}][equipamento_tipo]" placeholder="Piso-teto, Split..."></div>
                </div>
                <div class="row">
                    <div class="col"><label>Marca:</label><input type="text" name="equipamentos[${index}][equipamento_marca]"></div>
                    <div class="col"><label>Modelo:</label><input type="text" name="equipamentos[${index}][equipamento_modelo]"></div>
                    <div class="col"><label>BTU:</label><input type="text" name="equipamentos[${index}][equipamento_btu]"></div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Estado da Serpentina:</label>
                        <select name="equipamentos[${index}][serpentina_estado]">
                            <option value="">Selecione...</option>
                            <option value="limpa">Limpa</option><option value="suja">Suja</option>
                            <option value="muito_suja">Muito Suja</option><option value="oxidada">Oxidada</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>Estado dos Filtros:</label>
                        <select name="equipamentos[${index}][filtros_estado]">
                            <option value="">Selecione...</option>
                            <option value="limpos">Limpos</option><option value="sujos">Sujos</option>
                            <option value="entupidos">Entupidos</option><option value="ausentes">Ausentes</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Ventilador:</label>
                        <select name="equipamentos[${index}][ventilador_estado]">
                            <option value="">Selecione...</option>
                            <option value="normal">Normal</option><option value="barulho">Barulho</option>
                            <option value="folga">Com folga</option><option value="nao_testado">Não testado</option>
                        </select>
                    </div>
                    <div class="col">
                        <label>Placa Eletrônica:</label>
                        <select name="equipamentos[${index}][placa_estado]">
                            <option value="">Selecione...</option>
                            <option value="ok">OK</option><option value="queimada">Queimada</option>
                            <option value="oxidada">Oxidada</option><option value="nao_acessivel">Não acessível</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <label>Pressão do Gás:</label>
                        <select name="equipamentos[${index}][pressao_gas]">
                            <option value="">Selecione...</option>
                            <option value="normal">Normal</option><option value="baixa">Baixa</option>
                            <option value="esvaziado">Esvaziado</option><option value="nao_verificado">Não verificado</option>
                        </select>
                    </div>
                    <div class="col">
                        <label><input type="checkbox" name="equipamentos[${index}][limpeza_necessaria]" value="1"> Necessita limpeza (R$ <?php echo number_format($taxa_limpeza_equipamento, 2, ',', '.'); ?>)</label>
                    </div>
                </div>
                <div class="row">
                    <div class="col"><label>Observações:</label><textarea name="equipamentos[${index}][observacao]" rows="2"></textarea></div>
                </div>
            `;
            container.appendChild(div);
        }
        
        function removerEquipamento(btn) {
            const equipamento = btn.closest('.equipamento-item');
            if (equipamento) equipamento.remove();
        }
        
        function liberarParaAssinatura() {
            if (confirm('⚠️ ATENÇÃO: Ao liberar para assinatura, o cliente poderá assinar o contrato digitalmente. Deseja continuar?')) {
                const form = document.getElementById('form-checklist');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'liberar_assinatura';
                input.value = '1';
                form.appendChild(input);
                form.submit();
            }
        }
    </script>
</body>
</html>
