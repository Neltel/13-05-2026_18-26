<?php
/**
 * Relatórios Técnicos - Império AR
 * Gerenciamento de relatórios técnicos de serviços com fotos
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$usuario = Auth::obter_usuario();
global $conexao;

if (!$conexao) {
    die("Erro de conexão com banco de dados");
}

// ===== FUNÇÕES AUXILIARES =====
function formatarMoeda($valor) {
    if (empty($valor) || !is_numeric($valor)) $valor = 0;
    return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
}

function formatarData($data, $hora = false) {
    if (empty($data)) return '-';
    if ($hora) {
        return date('d/m/Y H:i', strtotime($data));
    }
    return date('d/m/Y', strtotime($data));
}

// Gerar número do relatório
function gerarNumeroRelatorio($conexao) {
    $ano = date('Y');
    $mes = date('m');
    
    $sql = "SELECT COUNT(*) as total FROM relatorios_tecnicos WHERE YEAR(created_at) = $ano AND MONTH(created_at) = $mes";
    $result = $conexao->query($sql);
    $total = $result->fetch_assoc()['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    
    return "RTC-$ano$mes-$sequencial";
}

// Função para gerar sugestão de IA baseada na descrição
function gerarSugestaoIA($descricao, $equipamentos = []) {
    $sugestao = "=== RELATÓRIO TÉCNICO DETALHADO ===\n\n";
    
    // Análise baseada na descrição
    $descricao_lower = strtolower($descricao);
    
    if (strpos($descricao_lower, 'sujo') !== false || strpos($descricao_lower, 'limpeza') !== false) {
        $sugestao .= "🔧 *DIAGNÓSTICO DE LIMPEZA*\n";
        $sugestao .= "• Os equipamentos apresentam acúmulo significativo de sujeira nos componentes internos.\n";
        $sugestao .= "• A serpentina evaporadora está obstruída, reduzindo a eficiência térmica.\n";
        $sugestao .= "• Os filtros de ar estão entupidos, comprometendo o fluxo de ar.\n\n";
        
        $sugestao .= "🛠️ *SERVIÇOS REALIZADOS*\n";
        $sugestao .= "• Realizada limpeza química da serpentina evaporadora.\n";
        $sugestao .= "• Limpeza e sanitização dos filtros de ar.\n";
        $sugestao .= "• Verificação do sistema de drenagem.\n";
        $sugestao .= "• Limpeza da unidade condensadora (se aplicável).\n\n";
        
        $sugestao .= "📊 *MEDIÇÕES REALIZADAS*\n";
        $sugestao .= "• Temperatura ambiente: ______ °C\n";
        $sugestao .= "• Temperatura do ar na saída: ______ °C\n";
        $sugestao .= "• Delta T: ______ °C (ideal: 8-12°C)\n";
        $sugestao .= "• Pressão de trabalho: ______ PSI\n";
        $sugestao .= "• Tensão elétrica: ______ V\n";
        $sugestao .= "• Corrente de trabalho: ______ A\n\n";
        
        $sugestao .= "✅ *RECOMENDAÇÕES*\n";
        $sugestao .= "• Realizar manutenção preventiva a cada 3 meses.\n";
        $sugestao .= "• Limpar ou trocar os filtros mensalmente.\n";
        $sugestao .= "• Verificar periodicamente o nível de gás refrigerante.\n";
        $sugestao .= "• Manter as áreas ao redor das unidades sempre desobstruídas.\n";
    }
    
    if (strpos($descricao_lower, 'vazamento') !== false || strpos($descricao_lower, 'gas') !== false) {
        $sugestao .= "\n🔧 *DIAGNÓSTICO DE VAZAMENTO*\n";
        $sugestao .= "• Detectado vazamento de gás refrigerante no sistema.\n";
        $sugestao .= "• Pressão do sistema abaixo do especificado pelo fabricante.\n\n";
        
        $sugestao .= "🛠️ *SERVIÇOS REALIZADOS*\n";
        $sugestao .= "• Localizado e reparado o ponto de vazamento.\n";
        $sugestao .= "• Realizado vácuo no sistema por 30 minutos.\n";
        $sugestao .= "• Carga de gás refrigerante realizada conforme especificação.\n";
        $sugestao .= "• Teste de estanqueidade realizado com sucesso.\n\n";
    }
    
    if (strpos($descricao_lower, 'ruído') !== false || strpos($descricao_lower, 'barulho') !== false) {
        $sugestao .= "\n🔧 *DIAGNÓSTICO DE RUÍDO ANORMAL*\n";
        $sugestao .= "• Identificado ruído anormal durante a operação do equipamento.\n";
        $sugestao .= "• Possível desgaste nos rolamentos do motor.\n\n";
        
        $sugestao .= "🛠️ *SERVIÇOS REALIZADOS*\n";
        $sugestao .= "• Verificação e lubrificação dos componentes móveis.\n";
        $sugestao .= "• Ajuste da fixação do compressor e ventoinhas.\n";
        $sugestao .= "• Substituição de componentes desgastados.\n\n";
    }
    
    // Adicionar seção de equipamentos
    if (!empty($equipamentos)) {
        $sugestao .= "\n📋 *EQUIPAMENTOS ATENDIDOS*\n";
        foreach ($equipamentos as $i => $eq) {
            $sugestao .= ($i + 1) . ". " . ($eq['marca'] ?: 'Marca não informada') . " - " . ($eq['modelo'] ?: 'Modelo não informado');
            if ($eq['potencia']) $sugestao .= " ({$eq['potencia']} BTUs)";
            $sugestao .= "\n";
        }
        $sugestao .= "\n";
    }
    
    $sugestao .= "\n---\n";
    $sugestao .= "*IMPORTANTE:* Este relatório foi gerado com auxílio de IA. Recomenda-se revisar e complementar as informações conforme avaliação técnica presencial.\n";
    
    return $sugestao;
}

// Tipos de relatório
$tipos_relatorio = [
    'diagnostico' => 'Diagnóstico Técnico',
    'manutencao' => 'Relatório de Manutenção',
    'instalacao' => 'Relatório de Instalação',
    'reparo' => 'Relatório de Reparo'
];

// Status do relatório
$status_relatorio = [
    'rascunho' => 'Rascunho',
    'enviado' => 'Enviado ao Cliente',
    'visualizado' => 'Visualizado pelo Cliente'
];

// ===== PROCESSAR AÇÕES =====
$acao = $_GET['acao'] ?? 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$mensagem = '';
$erro = '';
$tipo_mensagem = '';

// Buscar clientes
$clientes = $conexao->query("SELECT id, nome, cpf_cnpj, telefone, whatsapp, email FROM clientes WHERE ativo = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

// Buscar equipamentos do cliente (via AJAX)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'equipamentos') {
    $cliente_id = intval($_GET['cliente_id']);
    $equipamentos_filtrados = [];
    
    if ($cliente_id > 0) {
        // Buscar equipamentos de preventivas do cliente
        $sql_eq = "SELECT e.*, p.numero as preventiva_numero
                    FROM pmp_equipamentos e
                    JOIN preventivas p ON e.preventiva_id = p.id
                    WHERE p.cliente_id = $cliente_id
                    ORDER BY e.id DESC";
        $result_eq = $conexao->query($sql_eq);
        if ($result_eq) {
            while ($row = $result_eq->fetch_assoc()) {
                $equipamentos_filtrados[] = $row;
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($equipamentos_filtrados);
    exit;
}

// Buscar orçamentos por cliente (via AJAX)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'orcamentos') {
    $cliente_id = intval($_GET['cliente_id']);
    $orcamentos_filtrados = [];
    
    if ($cliente_id > 0) {
        $sql_orc = "SELECT o.id, o.numero, o.valor_total, o.data_emissao, o.situacao, c.nome as cliente_nome 
                    FROM orcamentos o 
                    LEFT JOIN clientes c ON o.cliente_id = c.id 
                    WHERE o.cliente_id = $cliente_id
                    ORDER BY o.id DESC 
                    LIMIT 50";
        $result_orc = $conexao->query($sql_orc);
        if ($result_orc) {
            while ($row = $result_orc->fetch_assoc()) {
                $orcamentos_filtrados[] = $row;
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($orcamentos_filtrados);
    exit;
}

// Processar upload de fotos
function processarUploadFotos($relatorio_id, $equipamento_id) {
    global $conexao;
    
    $upload_dir = DIR_UPLOADS . '/relatorios/' . $relatorio_id . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $fotos = [];
    if (isset($_FILES['fotos']) && !empty($_FILES['fotos']['name'][0])) {
        for ($i = 0; $i < count($_FILES['fotos']['name']); $i++) {
            if ($_FILES['fotos']['error'][$i] === UPLOAD_ERR_OK) {
                $nome_original = $_FILES['fotos']['name'][$i];
                $ext = pathinfo($nome_original, PATHINFO_EXTENSION);
                $nome_arquivo = time() . '_' . $i . '.' . $ext;
                $caminho = $upload_dir . $nome_arquivo;
                
                if (move_uploaded_file($_FILES['fotos']['tmp_name'][$i], $caminho)) {
                    $url_foto = URL_UPLOADS . '/relatorios/' . $relatorio_id . '/' . $nome_arquivo;
                    $fotos[] = $url_foto;
                    
                    // Salvar no banco (você pode criar uma tabela relatorio_fotos se quiser)
                    $sql = "INSERT INTO relatorio_fotos (relatorio_id, equipamento_id, foto_url, created_at) 
                            VALUES (?, ?, ?, NOW())";
                    $stmt = $conexao->prepare($sql);
                    $stmt->bind_param("iis", $relatorio_id, $equipamento_id, $url_foto);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
    
    return $fotos;
}

// ===== PROCESSAR POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = $_POST['acao'] ?? '';
    
    if ($acao_post === 'salvar') {
        $cliente_id = intval($_POST['cliente_id']);
        $orcamento_id = !empty($_POST['orcamento_id']) ? intval($_POST['orcamento_id']) : null;
        $data_servico = $_POST['data_servico'] ?? date('Y-m-d');
        $tipo = $_POST['tipo'] ?? 'diagnostico';
        $conteudo = trim($_POST['conteudo'] ?? '');
        $equipamentos = isset($_POST['equipamentos']) ? $_POST['equipamentos'] : [];
        $status = $_POST['status'] ?? 'rascunho';
        
        $id_editar = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($cliente_id <= 0) {
            $erro = "Selecione um cliente";
        } elseif (empty($conteudo)) {
            $erro = "Informe o conteúdo do relatório";
        } else {
            // Gerar sugestão de IA baseada no conteúdo e equipamentos
            $conteudo_ia = gerarSugestaoIA($conteudo, $equipamentos);
            
            if ($id_editar) {
                // Atualizar relatório existente
                $sql = "UPDATE relatorios_tecnicos SET 
                            cliente_id = ?,
                            orcamento_id = ?,
                            data_servico = ?,
                            tipo = ?,
                            conteudo = ?,
                            conteudo_ia = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("iisssssi", 
                    $cliente_id, $orcamento_id, $data_servico,
                    $tipo, $conteudo, $conteudo_ia, $status, $id_editar);
                
                if ($stmt->execute()) {
                    $mensagem = "Relatório atualizado com sucesso!";
                    $tipo_mensagem = "success";
                    
                    // Processar fotos se houver
                    if (isset($_FILES['fotos']) && !empty($_FILES['fotos']['name'][0])) {
                        processarUploadFotos($id_editar, 0);
                    }
                } else {
                    $erro = "Erro ao atualizar: " . $stmt->error;
                }
                $stmt->close();
            } else {
                // Inserir novo relatório
                $numero = gerarNumeroRelatorio($conexao);
                $sql = "INSERT INTO relatorios_tecnicos (
                            numero, cliente_id, orcamento_id, data_servico,
                            tipo, conteudo, conteudo_ia, status, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("siisssss", 
                    $numero, $cliente_id, $orcamento_id, $data_servico,
                    $tipo, $conteudo, $conteudo_ia, $status);
                
                if ($stmt->execute()) {
                    $novo_id = $conexao->insert_id;
                    $mensagem = "Relatório criado com sucesso! Número: $numero";
                    $tipo_mensagem = "success";
                    
                    // Processar fotos se houver
                    if (isset($_FILES['fotos']) && !empty($_FILES['fotos']['name'][0])) {
                        processarUploadFotos($novo_id, 0);
                    }
                } else {
                    $erro = "Erro ao criar: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        if (!empty($erro)) {
            $acao = $id_editar ? 'editar' : 'novo';
        } elseif ($acao_post === 'salvar' && empty($erro)) {
            header('Location: ' . BASE_URL . '/app/admin/relatorios_tecnicos.php?mensagem=' . urlencode($mensagem));
            exit;
        }
    } elseif ($acao_post === 'deletar' && $id) {
        $sql = "DELETE FROM relatorios_tecnicos WHERE id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $mensagem = "Relatório removido com sucesso!";
            $tipo_mensagem = "success";
        } else {
            $erro = "Erro ao remover: " . $stmt->error;
        }
        $stmt->close();
    } elseif ($acao_post === 'gerar_ia') {
        // Gerar sugestão de IA via AJAX
        $conteudo = $_POST['conteudo'] ?? '';
        $equipamentos_json = $_POST['equipamentos'] ?? '[]';
        $equipamentos = json_decode($equipamentos_json, true);
        
        $sugestao = gerarSugestaoIA($conteudo, $equipamentos);
        
        header('Content-Type: application/json');
        echo json_encode(['sugestao' => $sugestao]);
        exit;
    }
}

// ===== PROCESSAR DELETE VIA GET =====
if ($acao === 'deletar' && $id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "DELETE FROM relatorios_tecnicos WHERE id = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header('Location: ' . BASE_URL . '/app/admin/relatorios_tecnicos.php?mensagem=Relatório removido com sucesso');
        exit;
    }
    $stmt->close();
}

// ===== BUSCAR DADOS =====
if ($acao === 'listar') {
    $relatorios = $conexao->query("
        SELECT r.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone
        FROM relatorios_tecnicos r
        LEFT JOIN clientes c ON r.cliente_id = c.id
        ORDER BY r.created_at DESC
        LIMIT 200
    ")->fetch_all(MYSQLI_ASSOC);
    
    if (isset($_GET['mensagem'])) {
        $mensagem = $_GET['mensagem'];
        $tipo_mensagem = "success";
    }
} elseif ($acao === 'novo') {
    $relatorio = [
        'id' => '',
        'numero' => gerarNumeroRelatorio($conexao),
        'cliente_id' => '',
        'orcamento_id' => '',
        'data_servico' => date('Y-m-d'),
        'tipo' => 'diagnostico',
        'conteudo' => '',
        'conteudo_ia' => '',
        'status' => 'rascunho'
    ];
    $equipamentos_cliente = [];
    $orcamentos_cliente = [];
} elseif ($acao === 'editar' && $id) {
    $relatorio = $conexao->query("SELECT * FROM relatorios_tecnicos WHERE id = $id")->fetch_assoc();
    if (!$relatorio) {
        header('Location: ' . BASE_URL . '/app/admin/relatorios_tecnicos.php?mensagem=Relatório não encontrado');
        exit;
    }
    
    // Buscar equipamentos do cliente
    if ($relatorio['cliente_id'] > 0) {
        $sql_eq = "SELECT e.*, p.numero as preventiva_numero
                    FROM pmp_equipamentos e
                    JOIN preventivas p ON e.preventiva_id = p.id
                    WHERE p.cliente_id = {$relatorio['cliente_id']}
                    ORDER BY e.id DESC";
        $result_eq = $conexao->query($sql_eq);
        if ($result_eq) {
            while ($row = $result_eq->fetch_assoc()) {
                $equipamentos_cliente[] = $row;
            }
        }
    }
    
    // Buscar fotos do relatório
    $fotos = [];
    $sql_fotos = "SELECT * FROM relatorio_fotos WHERE relatorio_id = $id";
    $result_fotos = $conexao->query($sql_fotos);
    if ($result_fotos) {
        while ($row = $result_fotos->fetch_assoc()) {
            $fotos[] = $row;
        }
    }
} elseif ($acao === 'ver' && $id) {
    $relatorio = $conexao->query("
        SELECT r.*, c.nome as cliente_nome, c.cpf_cnpj, c.telefone, c.email, c.endereco_rua, c.endereco_numero, c.endereco_bairro, c.endereco_cidade, c.endereco_estado,
               o.numero as orcamento_numero
        FROM relatorios_tecnicos r
        LEFT JOIN clientes c ON r.cliente_id = c.id
        LEFT JOIN orcamentos o ON r.orcamento_id = o.id
        WHERE r.id = $id
    ")->fetch_assoc();
    if (!$relatorio) {
        header('Location: ' . BASE_URL . '/app/admin/relatorios_tecnicos.php?mensagem=Relatório não encontrado');
        exit;
    }
    
    // Buscar fotos
    $fotos = [];
    $sql_fotos = "SELECT * FROM relatorio_fotos WHERE relatorio_id = $id";
    $result_fotos = $conexao->query($sql_fotos);
    if ($result_fotos) {
        while ($row = $result_fotos->fetch_assoc()) {
            $fotos[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Técnicos - Império AR</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        :root {
            --primary: #1e3c72;
            --primary-light: #2a5298;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --gray-800: #343a40;
        }

        body {
            background: linear-gradient(135deg, #f5f6fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 300px;
            padding: 30px;
            overflow-y: auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .page-header h1 {
            color: var(--primary);
            font-size: 28px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-badge {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
        }

        .btn-novo {
            background: var(--success);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-novo:hover {
            background: #1e7e34;
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            font-size: 28px;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 11px;
            color: var(--gray-600);
            margin-top: 5px;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--gray-100);
            border-bottom: 2px solid var(--gray-300);
            padding: 14px 15px;
            font-weight: 600;
            color: var(--gray-800);
            font-size: 13px;
        }

        .table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
        }

        .table tbody tr:hover {
            background: var(--gray-100);
        }

        /* Badges */
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-rascunho { background: #e2e3e5; color: #383d41; }
        .badge-enviado { background: #cfe2ff; color: #084298; }
        .badge-visualizado { background: #d1e7dd; color: #0f5132; }

        /* Buttons */
        .btn-view, .btn-edit, .btn-delete, .btn-pdf {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
            transition: all 0.2s;
        }
        .btn-view { background: var(--info); color: white; }
        .btn-view:hover { background: #138496; color: white; }
        .btn-edit { background: var(--primary); color: white; }
        .btn-edit:hover { background: var(--primary-light); color: white; }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #c82333; color: white; }
        .btn-pdf { background: #dc3545; color: white; }
        .btn-pdf:hover { background: #c82333; color: white; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-800);
            font-size: 13px;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }

        .btn-salvar {
            background: var(--success);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-salvar:hover {
            background: #1e7e34;
        }

        .btn-cancelar {
            background: var(--gray-600);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-cancelar:hover {
            background: #5a6268;
            color: white;
        }

        .btn-gerar-ia {
            background: #6f42c1;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            margin-top: 10px;
        }

        .btn-gerar-ia:hover {
            background: #5a32a3;
        }

        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--gray-300);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* View Card */
        .view-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .info-label {
            width: 200px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .info-value {
            flex: 1;
            color: var(--gray-600);
        }

        .conteudo-box {
            background: var(--gray-100);
            padding: 20px;
            border-radius: 8px;
            margin-top: 10px;
            line-height: 1.6;
        }

        .fotos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .foto-item {
            background: var(--gray-100);
            border-radius: 8px;
            padding: 10px;
            text-align: center;
        }

        .foto-item img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            cursor: pointer;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Status select */
        .status-select {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .status-select select {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <i class="fas fa-file-alt"></i>
                    Relatórios Técnicos
                </h1>
                <div style="display: flex; gap: 15px;">
                    <a href="relatorios_tecnicos.php?acao=novo" class="btn-novo">
                        <i class="fas fa-plus"></i> Novo Relatório
                    </a>
                    <div class="user-badge">
                        <i class="fas fa-user-circle me-2"></i>
                        <?php echo htmlspecialchars($usuario['nome'] ?? 'Admin'); ?>
                    </div>
                </div>
            </div>

            <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem ?? 'success'; ?>">
                <i class="fas fa-<?php echo ($tipo_mensagem ?? 'success') === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
            <?php endif; ?>

            <?php if ($acao === 'listar'): ?>
                <!-- Stats Cards -->
                <?php 
                $total_rascunho = 0;
                $total_enviado = 0;
                $total_visualizado = 0;
                
                foreach ($relatorios as $r) {
                    if ($r['status'] == 'rascunho') $total_rascunho++;
                    if ($r['status'] == 'enviado') $total_enviado++;
                    if ($r['status'] == 'visualizado') $total_visualizado++;
                }
                ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-number"><?php echo count($relatorios); ?></div>
                        <div class="stat-label">Total Relatórios</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-pen-fancy"></i></div>
                        <div class="stat-number"><?php echo $total_rascunho; ?></div>
                        <div class="stat-label">Rascunhos</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
                        <div class="stat-number"><?php echo $total_enviado; ?></div>
                        <div class="stat-label">Enviados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-eye"></i></div>
                        <div class="stat-number"><?php echo $total_visualizado; ?></div>
                        <div class="stat-label">Visualizados</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="table-relatorios">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Cliente</th>
                                    <th>Data Serviço</th>
                                    <th>Tipo</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($relatorios as $item): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['numero']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['cliente_nome'] ?? '-'); ?></td>
                                    <td><?php echo formatarData($item['data_servico']); ?></td>
                                    <td><?php echo $tipos_relatorio[$item['tipo']] ?? $item['tipo']; ?></td>
                                    <td><span class="badge-status badge-<?php echo $item['status']; ?>"><?php echo $status_relatorio[$item['status']] ?? $item['status']; ?></span></td>
                                    <td>
                                        <a href="relatorios_tecnicos.php?acao=ver&id=<?php echo $item['id']; ?>" class="btn-view" title="Visualizar">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="relatorios_tecnicos.php?acao=editar&id=<?php echo $item['id']; ?>" class="btn-edit" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="relatorios_tecnicos.php?acao=deletar&id=<?php echo $item['id']; ?>" class="btn-delete" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este relatório?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </span>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($acao === 'ver' && isset($relatorio)): ?>
                <div class="view-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: var(--primary); margin: 0;">
                            <i class="fas fa-file-alt"></i> Relatório Técnico
                        </h2>
                        <div>
                            <form method="POST" class="status-select" style="display: inline-block;">
                                <input type="hidden" name="acao" value="alterar_status">
                                <input type="hidden" name="relatorio_id" value="<?php echo $relatorio['id']; ?>">
                                <select name="novo_status" onchange="this.form.submit()" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #ddd;">
                                    <option value="rascunho" <?php echo $relatorio['status'] == 'rascunho' ? 'selected' : ''; ?>>📝 Rascunho</option>
                                    <option value="enviado" <?php echo $relatorio['status'] == 'enviado' ? 'selected' : ''; ?>>📨 Enviado ao Cliente</option>
                                    <option value="visualizado" <?php echo $relatorio['status'] == 'visualizado' ? 'selected' : ''; ?>>👁️ Visualizado pelo Cliente</option>
                                </select>
                            </form>
                            <a href="relatorios_tecnicos.php?acao=editar&id=<?php echo $relatorio['id']; ?>" class="btn-edit" style="margin-left: 10px;">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <a href="relatorios_tecnicos.php" class="btn-view" style="margin-left: 10px;">
                                <i class="fas fa-arrow-left"></i> Voltar
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Número do Relatório:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($relatorio['numero']); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Cliente:</div>
                        <div class="info-value"><?php echo htmlspecialchars($relatorio['cliente_nome']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data do Serviço:</div>
                        <div class="info-value"><?php echo formatarData($relatorio['data_servico']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Tipo de Relatório:</div>
                        <div class="info-value"><?php echo $tipos_relatorio[$relatorio['tipo']] ?? $relatorio['tipo']; ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Conteúdo do Relatório:</div>
                        <div class="info-value">
                            <div class="conteudo-box">
                                <?php echo nl2br(htmlspecialchars($relatorio['conteudo'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($relatorio['conteudo_ia']): ?>
                    <div class="info-row">
                        <div class="info-label">Sugestão IA:</div>
                        <div class="info-value">
                            <div class="conteudo-box" style="background: #e7f3ff;">
                                <i class="fas fa-robot me-2"></i>
                                <?php echo nl2br(htmlspecialchars($relatorio['conteudo_ia'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($fotos)): ?>
                    <div class="info-row">
                        <div class="info-label">Fotos do Serviço:</div>
                        <div class="info-value">
                            <div class="fotos-grid">
                                <?php foreach ($fotos as $foto): ?>
                                <div class="foto-item">
                                    <img src="<?php echo $foto['foto_url']; ?>" alt="Foto do serviço" style="max-width: 100%; border-radius: 8px; cursor: pointer;" onclick="window.open(this.src)">
                                    <small class="text-muted" style="display: block; margin-top: 5px;"><?php echo formatarData($foto['created_at'], true); ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 30px; padding: 20px; background: var(--gray-100); border-radius: 8px; text-align: center;">
                        <i class="fas fa-certificate" style="font-size: 24px; color: var(--primary);"></i>
                        <p style="margin-top: 10px; font-size: 12px; color: var(--gray-600);">
                            Documento emitido eletronicamente pela Império AR - Refrigeração<br>
                            CNPJ: 66.282.593/0001-34
                        </p>
                    </div>
                </div>

            <?php elseif ($acao === 'novo' || ($acao === 'editar' && isset($relatorio))): ?>
                <div class="form-card">
                    <h2 style="color: var(--primary); margin-bottom: 25px;">
                        <i class="fas fa-<?php echo $acao === 'novo' ? 'plus' : 'edit'; ?>"></i>
                        <?php echo $acao === 'novo' ? 'Novo Relatório Técnico' : 'Editar Relatório'; ?>
                    </h2>
                    
                    <form method="POST" enctype="multipart/form-data" id="formRelatorio">
                        <input type="hidden" name="acao" value="salvar">
                        <?php if ($acao === 'editar'): ?>
                        <input type="hidden" name="id" value="<?php echo $relatorio['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Cliente *</label>
                                <select name="cliente_id" id="cliente_id" required>
                                    <option value="">Selecione um cliente</option>
                                    <?php foreach ($clientes as $cli): ?>
                                    <option value="<?php echo $cli['id']; ?>" <?php echo ($relatorio['cliente_id'] ?? '') == $cli['id'] ? 'selected' : ''; ?> data-nome="<?php echo htmlspecialchars($cli['nome']); ?>">
                                        <?php echo htmlspecialchars($cli['nome']); ?> <?php echo $cli['cpf_cnpj'] ? ' - ' . htmlspecialchars($cli['cpf_cnpj']) : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Data do Serviço *</label>
                                <input type="date" name="data_servico" value="<?php echo $relatorio['data_servico'] ?? date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Tipo de Relatório *</label>
                                <select name="tipo" required>
                                    <?php foreach ($tipos_relatorio as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($relatorio['tipo'] ?? 'diagnostico') == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <?php foreach ($status_relatorio as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($relatorio['status'] ?? 'rascunho') == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Vincular a Orçamento (opcional)</label>
                                <select name="orcamento_id" id="orcamento_id">
                                    <option value="">-- Selecione um orçamento --</option>
                                    <?php if ($acao === 'editar' && isset($relatorio['orcamento_id']) && $relatorio['orcamento_id']): ?>
                                    <?php 
                                    $orc_atual = $conexao->query("SELECT id, numero FROM orcamentos WHERE id = {$relatorio['orcamento_id']}")->fetch_assoc();
                                    if ($orc_atual): ?>
                                    <option value="<?php echo $orc_atual['id']; ?>" selected><?php echo htmlspecialchars($orc_atual['numero']); ?></option>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </select>
                                <div id="loading-orcamentos" style="display: none; font-size: 12px; color: var(--info); margin-top: 5px;">
                                    <span class="loading-spinner"></span> Carregando orçamentos...
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Equipamentos (selecione os equipamentos atendidos)</label>
                            <select name="equipamentos[]" id="equipamentos" multiple style="height: 100px;">
                                <option value="">-- Selecione o cliente primeiro --</option>
                            </select>
                            <small class="text-muted">Segure Ctrl para selecionar múltiplos equipamentos</small>
                            <div id="loading-equipamentos" style="display: none; font-size: 12px; color: var(--info); margin-top: 5px;">
                                <span class="loading-spinner"></span> Carregando equipamentos...
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Conteúdo do Relatório *</label>
                            <textarea name="conteudo" id="conteudo" rows="10" placeholder="Descreva detalhadamente o diagnóstico, manutenção realizada, peças trocadas, recomendações..." required><?php echo htmlspecialchars($relatorio['conteudo'] ?? ''); ?></textarea>
                            <small class="text-muted">Descreva o que foi encontrado e o que foi feito. A IA irá gerar uma sugestão técnica baseada na sua descrição.</small>
                        </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn-gerar-ia" id="btnGerarIA">
                                <i class="fas fa-robot"></i> Gerar Sugestão Técnica com IA
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <label>Sugestão de IA</label>
                            <textarea name="conteudo_ia" id="conteudo_ia" rows="12" placeholder="Clique em 'Gerar Sugestão Técnica com IA' para criar um relatório técnico detalhado automaticamente"><?php echo htmlspecialchars($relatorio['conteudo_ia'] ?? ''); ?></textarea>
                            <small class="text-muted">Campo gerado automaticamente pela IA baseado na sua descrição. Revise e ajuste conforme necessário.</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Fotos do Serviço</label>
                            <input type="file" name="fotos[]" id="fotos" multiple accept="image/*" class="form-control">
                            <small class="text-muted">Você pode selecionar múltiplas fotos (JPEG, PNG, GIF). Máximo 5MB por foto.</small>
                        </div>
                        
                        <?php if ($acao === 'editar' && isset($fotos) && !empty($fotos)): ?>
                        <div class="form-group">
                            <label>Fotos já anexadas</label>
                            <div class="fotos-grid">
                                <?php foreach ($fotos as $foto): ?>
                                <div class="foto-item">
                                    <img src="<?php echo $foto['foto_url']; ?>" alt="Foto" style="max-width: 100%; border-radius: 8px;">
                                    <small class="text-muted"><?php echo formatarData($foto['created_at'], true); ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="submit" class="btn-salvar">
                                <i class="fas fa-save"></i> Salvar Relatório
                            </button>
                            <a href="relatorios_tecnicos.php" class="btn-cancelar">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        </div>
                    </form>
                </div>
                
                <script>
                    // Carregar orçamentos por cliente
                    function carregarOrcamentos(clienteId, orcamentoSelecionadoId = null) {
                        if (!clienteId || clienteId == '') {
                            var selectOrcamento = document.getElementById('orcamento_id');
                            selectOrcamento.innerHTML = '<option value="">-- Selecione um orçamento --</option>';
                            return;
                        }
                        
                        var loadingDiv = document.getElementById('loading-orcamentos');
                        var selectOrcamento = document.getElementById('orcamento_id');
                        
                        loadingDiv.style.display = 'block';
                        selectOrcamento.disabled = true;
                        
                        fetch('relatorios_tecnicos.php?ajax=orcamentos&cliente_id=' + clienteId)
                            .then(response => response.json())
                            .then(data => {
                                selectOrcamento.innerHTML = '<option value="">-- Selecione um orçamento --</option>';
                                
                                if (data.length === 0) {
                                    selectOrcamento.innerHTML += '<option value="" disabled>Nenhum orçamento encontrado para este cliente</option>';
                                } else {
                                    data.forEach(orc => {
                                        var option = document.createElement('option');
                                        option.value = orc.id;
                                        var valor = parseFloat(orc.valor_total || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, style: 'currency', currency: 'BRL' });
                                        option.textContent = orc.numero + ' (' + valor + ') - ' + orc.situacao;
                                        if (orcamentoSelecionadoId && orc.id == orcamentoSelecionadoId) {
                                            option.selected = true;
                                        }
                                        selectOrcamento.appendChild(option);
                                    });
                                }
                                
                                loadingDiv.style.display = 'none';
                                selectOrcamento.disabled = false;
                            })
                            .catch(error => {
                                console.error('Erro ao carregar orçamentos:', error);
                                loadingDiv.style.display = 'none';
                                selectOrcamento.disabled = false;
                                selectOrcamento.innerHTML = '<option value="">Erro ao carregar orçamentos</option>';
                            });
                    }
                    
                    // Carregar equipamentos por cliente
                    function carregarEquipamentos(clienteId, equipamentosSelecionados = []) {
                        if (!clienteId || clienteId == '') {
                            var selectEquip = document.getElementById('equipamentos');
                            selectEquip.innerHTML = '<option value="">-- Selecione o cliente primeiro --</option>';
                            return;
                        }
                        
                        var loadingDiv = document.getElementById('loading-equipamentos');
                        var selectEquip = document.getElementById('equipamentos');
                        
                        loadingDiv.style.display = 'block';
                        selectEquip.disabled = true;
                        
                        fetch('relatorios_tecnicos.php?ajax=equipamentos&cliente_id=' + clienteId)
                            .then(response => response.json())
                            .then(data => {
                                selectEquip.innerHTML = '';
                                
                                if (data.length === 0) {
                                    selectEquip.innerHTML = '<option value="" disabled>Nenhum equipamento encontrado para este cliente</option>';
                                } else {
                                    data.forEach(eq => {
                                        var option = document.createElement('option');
                                        option.value = JSON.stringify(eq);
                                        var texto = (eq.marca || '?') + ' - ' + (eq.modelo || '?');
                                        if (eq.potencia) texto += ' (' + eq.potencia + ' BTUs)';
                                        option.textContent = texto;
                                        selectEquip.appendChild(option);
                                    });
                                }
                                
                                loadingDiv.style.display = 'none';
                                selectEquip.disabled = false;
                            })
                            .catch(error => {
                                console.error('Erro ao carregar equipamentos:', error);
                                loadingDiv.style.display = 'none';
                                selectEquip.disabled = false;
                                selectEquip.innerHTML = '<option value="">Erro ao carregar equipamentos</option>';
                            });
                    }
                    
                    // Gerar sugestão de IA
                    document.getElementById('btnGerarIA').addEventListener('click', function() {
                        var conteudo = document.getElementById('conteudo').value;
                        var equipamentosSelect = document.getElementById('equipamentos');
                        var equipamentos = [];
                        
                        for (var i = 0; i < equipamentosSelect.options.length; i++) {
                            if (equipamentosSelect.options[i].selected && equipamentosSelect.options[i].value) {
                                try {
                                    var eq = JSON.parse(equipamentosSelect.options[i].value);
                                    equipamentos.push(eq);
                                } catch(e) {}
                            }
                        }
                        
                        if (!conteudo) {
                            alert('Por favor, descreva o serviço realizado no campo "Conteúdo do Relatório" primeiro.');
                            return;
                        }
                        
                        var btn = this;
                        var originalText = btn.innerHTML;
                        btn.innerHTML = '<span class="loading-spinner"></span> Gerando...';
                        btn.disabled = true;
                        
                        fetch('relatorios_tecnicos.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'acao=gerar_ia&conteudo=' + encodeURIComponent(conteudo) + '&equipamentos=' + encodeURIComponent(JSON.stringify(equipamentos))
                        })
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('conteudo_ia').value = data.sugestao;
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        })
                        .catch(error => {
                            console.error('Erro:', error);
                            alert('Erro ao gerar sugestão. Tente novamente.');
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        });
                    });
                    
                    // Evento de mudança do cliente
                    var clienteSelect = document.getElementById('cliente_id');
                    var orcamentoSelecionado = <?php echo json_encode($relatorio['orcamento_id'] ?? null); ?>;
                    
                    if (clienteSelect) {
                        clienteSelect.addEventListener('change', function() {
                            carregarOrcamentos(this.value, null);
                            carregarEquipamentos(this.value);
                        });
                        
                        if (clienteSelect.value && clienteSelect.value != '') {
                            carregarOrcamentos(clienteSelect.value, orcamentoSelecionado);
                            carregarEquipamentos(clienteSelect.value);
                        }
                    }
                </script>

            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            if ($('#table-relatorios').length) {
                $('#table-relatorios').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'
                    },
                    pageLength: 25,
                    order: [[2, 'desc']],
                    columnDefs: [{ orderable: false, targets: [5] }]
                });
            }
        });
    </script>
</body>
</html>