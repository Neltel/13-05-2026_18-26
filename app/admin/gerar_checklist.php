<?php
/**
 * =====================================================================
 * GERAR CHECKLIST DO CONTRATO - VERSÃO ADMIN
 * =====================================================================
 * 
 * Responsabilidade: Formulário para preencher checklist técnico
 * URL: /app/admin/gerar_checklist.php?id=XXX
 * 
 * Funcionalidades:
 * - Cadastrar múltiplos equipamentos
 * - Checklist detalhado com categorias
 * - Upload de fotos
 * - Definir tempo máximo de execução
 * - Calcular taxa de limpeza automática
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!Auth::isLogado() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

global $conexao;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: ' . BASE_URL . '/app/admin/orcamentos.php?erro=id_invalido');
    exit;
}

// Buscar dados do orçamento
$sql = "SELECT o.*, c.nome as cliente_nome 
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

// Buscar checklist resumo
$checklist_resumo = null;
$sql_resumo = "SELECT * FROM checklist_resumo WHERE orcamento_id = ?";
$stmt_resumo = $conexao->prepare($sql_resumo);
$stmt_resumo->bind_param("i", $id);
$stmt_resumo->execute();
$resumo_result = $stmt_resumo->get_result();
if ($resumo_result->num_rows > 0) {
    $checklist_resumo = $resumo_result->fetch_assoc();
}
$stmt_resumo->close();

// Buscar equipamentos e itens
$equipamentos = [];
$sql_equip = "SELECT * FROM checklist_equipamentos WHERE orcamento_id = ? ORDER BY ordem ASC";
$stmt_equip = $conexao->prepare($sql_equip);
$stmt_equip->bind_param("i", $id);
$stmt_equip->execute();
$equip_result = $stmt_equip->get_result();
while ($equip = $equip_result->fetch_assoc()) {
    $equip['itens'] = [];
    $sql_itens = "SELECT * FROM checklist_itens WHERE equipamento_id = ? ORDER BY ordem ASC";
    $stmt_itens = $conexao->prepare($sql_itens);
    $stmt_itens->bind_param("i", $equip['id']);
    $stmt_itens->execute();
    $itens_result = $stmt_itens->get_result();
    while ($item = $itens_result->fetch_assoc()) {
        $equip['itens'][] = $item;
    }
    $stmt_itens->close();
    $equipamentos[] = $equip;
}
$stmt_equip->close();

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'salvar_resumo') {
        $tempo_maximo = $conexao->real_escape_string($_POST['tempo_maximo_execucao'] ?? '');
        $observacoes_gerais = $conexao->real_escape_string($_POST['observacoes_gerais'] ?? '');
        $status_checklist = $_POST['status_checklist'] ?? 'pendente';
        
        if ($checklist_resumo) {
            $sql_update = "UPDATE checklist_resumo SET 
                          tempo_maximo_execucao = ?,
                          observacoes_gerais = ?,
                          status = ?,
                          updated_at = NOW()
                          WHERE orcamento_id = ?";
            $stmt_update = $conexao->prepare($sql_update);
            $stmt_update->bind_param("sssi", $tempo_maximo, $observacoes_gerais, $status_checklist, $id);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            $sql_insert = "INSERT INTO checklist_resumo (orcamento_id, tempo_maximo_execucao, observacoes_gerais, status, data_checklist) 
                          VALUES (?, ?, ?, ?, NOW())";
            $stmt_insert = $conexao->prepare($sql_insert);
            $stmt_insert->bind_param("isss", $id, $tempo_maximo, $observacoes_gerais, $status_checklist);
            $stmt_insert->execute();
            $stmt_insert->close();
            $checklist_resumo = ['id' => $conexao->insert_id];
        }
        
        // Atualizar status no orçamento
        $sql_upd_orc = "UPDATE orcamentos SET checklist_status = ? WHERE id = ?";
        $stmt_upd = $conexao->prepare($sql_upd_orc);
        $stmt_upd->bind_param("si", $status_checklist, $id);
        $stmt_upd->execute();
        $stmt_upd->close();
        
        $_SESSION['mensagem'] = 'Resumo do checklist salvo com sucesso!';
        header("Location: gerar_checklist.php?id=$id");
        exit;
    }
    
    elseif ($acao === 'salvar_equipamento') {
        $equip_id = intval($_POST['equip_id'] ?? 0);
        $nome_equipamento = $conexao->real_escape_string($_POST['nome_equipamento']);
        $tipo_equipamento = $_POST['tipo_equipamento'];
        $marca = $conexao->real_escape_string($_POST['marca'] ?? '');
        $modelo = $conexao->real_escape_string($_POST['modelo'] ?? '');
        $potencia_btu = $conexao->real_escape_string($_POST['potencia_btu'] ?? '');
        $gas_refrigerante = $conexao->real_escape_string($_POST['gas_refrigerante'] ?? '');
        
        if ($equip_id > 0) {
            $sql = "UPDATE checklist_equipamentos SET 
                   nome_equipamento = ?,
                   tipo_equipamento = ?,
                   marca = ?,
                   modelo = ?,
                   potencia_btu = ?,
                   gas_refrigerante = ?,
                   updated_at = NOW()
                   WHERE id = ? AND orcamento_id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("ssssssii", $nome_equipamento, $tipo_equipamento, $marca, $modelo, $potencia_btu, $gas_refrigerante, $equip_id, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            // Buscar última ordem
            $sql_ordem = "SELECT MAX(ordem) as max_ordem FROM checklist_equipamentos WHERE orcamento_id = ?";
            $stmt_ordem = $conexao->prepare($sql_ordem);
            $stmt_ordem->bind_param("i", $id);
            $stmt_ordem->execute();
            $ordem_result = $stmt_ordem->get_result();
            $ordem_data = $ordem_result->fetch_assoc();
            $nova_ordem = ($ordem_data['max_ordem'] ?? 0) + 1;
            $stmt_ordem->close();
            
            $sql = "INSERT INTO checklist_equipamentos (orcamento_id, nome_equipamento, tipo_equipamento, marca, modelo, potencia_btu, gas_refrigerante, ordem) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("issssssi", $id, $nome_equipamento, $tipo_equipamento, $marca, $modelo, $potencia_btu, $gas_refrigerante, $nova_ordem);
            $stmt->execute();
            $equip_id = $conexao->insert_id;
            $stmt->close();
            
            // Criar itens padrão
            $itens_padrao = [
                ['estrutura', 'Estrutura de suporte/instalação está em boas condições?', 1],
                ['estado_equipamento', 'Equipamento apresenta sinais de oxidação/corrosão?', 2],
                ['estado_equipamento', 'Ventilador/ventoinha está funcionando corretamente?', 3],
                ['instalacao_eletrica', 'Fiação elétrica está em bom estado?', 4],
                ['instalacao_eletrica', 'Capacitores aparentam estar em boas condições?', 5],
                ['tubulacao', 'Tubulação de cobre está em bom estado?', 6],
                ['tubulacao', 'Isolamento térmico da tubulação está íntegro?', 7],
                ['drenagem', 'Drenagem está desobstruída?', 8],
                ['drenagem', 'Bandeja de drenagem está limpa?', 9],
                ['ambiente', 'Local tem fácil acesso para manutenção?', 10],
                ['ambiente', 'Espaço suficiente para instalação/remanejo?', 11],
                ['complexidade', 'Altura de trabalho (necessita plataforma?)', 12],
                ['complexidade', 'Complexidade da infraestrutura existente', 13],
                ['fotos', 'Fotos do equipamento antes da intervenção', 14]
            ];
            
            foreach ($itens_padrao as $item) {
                $sql_item = "INSERT INTO checklist_itens (equipamento_id, categoria, item_descricao, status, ordem) 
                            VALUES (?, ?, ?, 'pendente', ?)";
                $stmt_item = $conexao->prepare($sql_item);
                $stmt_item->bind_param("issi", $equip_id, $item[0], $item[1], $item[2]);
                $stmt_item->execute();
                $stmt_item->close();
            }
        }
        
        $_SESSION['mensagem'] = 'Equipamento salvo com sucesso!';
        header("Location: gerar_checklist.php?id=$id");
        exit;
    }
    
    elseif ($acao === 'excluir_equipamento') {
        $equip_id = intval($_POST['equip_id']);
        $sql = "DELETE FROM checklist_equipamentos WHERE id = ? AND orcamento_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("ii", $equip_id, $id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['mensagem'] = 'Equipamento excluído com sucesso!';
        header("Location: gerar_checklist.php?id=$id");
        exit;
    }
    
    elseif ($acao === 'salvar_itens') {
        $itens = $_POST['itens'] ?? [];
        
        foreach ($itens as $item_id => $item_data) {
            $status = $item_data['status'];
            $observacao = $conexao->real_escape_string($item_data['observacao'] ?? '');
            
            $sql = "UPDATE checklist_itens SET 
                   status = ?,
                   observacao = ?,
                   updated_at = NOW()
                   WHERE id = ?";
            $stmt = $conexao->prepare($sql);
            $stmt->bind_param("ssi", $status, $observacao, $item_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Recalcular taxa de limpeza
        $total_sujos = 0;
        $sql_sujos = "SELECT COUNT(DISTINCT ce.id) as total 
                     FROM checklist_equipamentos ce
                     LEFT JOIN checklist_itens ci ON ce.id = ci.equipamento_id
                     WHERE ce.orcamento_id = ? 
                     AND ci.item_descricao LIKE '%suj%'
                     AND ci.status = 'problema'";
        $stmt_sujos = $conexao->prepare($sql_sujos);
        $stmt_sujos->bind_param("i", $id);
        $stmt_sujos->execute();
        $sujos_result = $stmt_sujos->get_result();
        $sujos_data = $sujos_result->fetch_assoc();
        $total_sujos = $sujos_data['total'] ?? 0;
        $stmt_sujos->close();
        
        $taxa_limpeza = $total_sujos * 350;
        $dias_adicionais = $total_sujos;
        
        if ($checklist_resumo) {
            $sql_upd = "UPDATE checklist_resumo SET 
                       total_equipamentos_sujos = ?,
                       taxa_limpeza_total = ?,
                       dias_adicionais = ?,
                       updated_at = NOW()
                       WHERE orcamento_id = ?";
            $stmt_upd = $conexao->prepare($sql_upd);
            $stmt_upd->bind_param("idii", $total_sujos, $taxa_limpeza, $dias_adicionais, $id);
            $stmt_upd->execute();
            $stmt_upd->close();
        }
        
        $_SESSION['mensagem'] = 'Checklist salvo com sucesso! Taxa de limpeza: R$ ' . number_format($taxa_limpeza, 2, ',', '.');
        header("Location: gerar_checklist.php?id=$id");
        exit;
    }
    
    elseif ($acao === 'upload_foto') {
        $item_id = intval($_POST['item_id']);
        
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
            $nome_arquivo = 'checklist_' . $id . '_' . $item_id . '_' . time() . '.' . $ext;
            $caminho = __DIR__ . '/../../storage/checklist_fotos/' . $nome_arquivo;
            
            if (!is_dir(__DIR__ . '/../../storage/checklist_fotos')) {
                mkdir(__DIR__ . '/../../storage/checklist_fotos', 0755, true);
            }
            
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $caminho)) {
                $sql = "UPDATE checklist_itens SET foto_url = ? WHERE id = ?";
                $stmt = $conexao->prepare($sql);
                $stmt->bind_param("si", $nome_arquivo, $item_id);
                $stmt->execute();
                $stmt->close();
                
                echo json_encode(['sucesso' => true, 'arquivo' => $nome_arquivo]);
                exit;
            }
        }
        
        echo json_encode(['sucesso' => false, 'erro' => 'Erro no upload']);
        exit;
    }
    
    elseif ($acao === 'concluir_checklist') {
        $sql = "UPDATE checklist_resumo SET status = 'concluido', updated_at = NOW() WHERE orcamento_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        
        $sql_upd = "UPDATE orcamentos SET checklist_status = 'concluido', checklist_data_conclusao = NOW() WHERE id = ?";
        $stmt_upd = $conexao->prepare($sql_upd);
        $stmt_upd->bind_param("i", $id);
        $stmt_upd->execute();
        $stmt_upd->close();
        
        $_SESSION['mensagem'] = 'Checklist concluído! Cliente agora pode assinar o contrato.';
        header("Location: gerar_checklist.php?id=$id");
        exit;
    }
}

// Mensagem de sessão
$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);

// Verificar se checklist está concluído
$checklist_concluido = ($orcamento['checklist_status'] === 'concluido');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checklist Técnico - Orçamento #<?php echo $orcamento['numero'] ?? $id; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .card-header h2 {
            font-size: 18px;
            color: #1e3c72;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #1e3c72;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2a5298;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 13px;
            color: #555;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        
        select.form-control {
            cursor: pointer;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 60px;
        }
        
        .equipamento-item {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .equipamento-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .equipamento-header h3 {
            font-size: 16px;
            color: #1e3c72;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-novo { background: #28a745; color: white; }
        .badge-usado { background: #ffc107; color: #333; }
        .badge-remanejado { background: #17a2b8; color: white; }
        
        .checklist-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .checklist-table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            font-size: 12px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .checklist-table td {
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        
        .status-ok { color: #28a745; font-weight: bold; }
        .status-pendente { color: #ffc107; font-weight: bold; }
        .status-problema { color: #dc3545; font-weight: bold; }
        .status-nao-aplicavel { color: #6c757d; font-weight: bold; }
        
        .foto-preview {
            max-width: 100px;
            max-height: 100px;
            cursor: pointer;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            max-width: 90%;
            max-height: 90%;
        }
        
        .modal-content img {
            max-width: 100%;
            max-height: 90vh;
        }
        
        .close-modal {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            cursor: pointer;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        
        .alert-info {
            background: #cfe2ff;
            border-left: 4px solid #0d6efd;
            color: #084298;
        }
        
        .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .col {
            flex: 1;
            min-width: 250px;
        }
        
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }
        
        .resumo-info {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .resumo-info p {
            margin: 5px 0;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .checklist-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Checklist Técnico</h1>
            <p>Orçamento: <?php echo htmlspecialchars($orcamento['numero'] ?? '#' . $id, ENT_QUOTES, 'UTF-8'); ?> | Cliente: <?php echo htmlspecialchars($orcamento['cliente_nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Status do Checklist: 
                <?php if ($orcamento['checklist_status'] === 'concluido'): ?>
                    <span style="background: #28a745; padding: 3px 10px; border-radius: 20px;">✅ Concluído</span>
                <?php elseif ($orcamento['checklist_status'] === 'em_andamento'): ?>
                    <span style="background: #ffc107; padding: 3px 10px; border-radius: 20px;">🔄 Em Andamento</span>
                <?php else: ?>
                    <span style="background: #6c757d; padding: 3px 10px; border-radius: 20px;">⏳ Pendente</span>
                <?php endif; ?>
            </p>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensagem); ?></div>
        <?php endif; ?>
        
        <?php if ($checklist_concluido): ?>
            <div class="alert alert-info">
                ✅ Checklist concluído em <?php echo date('d/m/Y H:i', strtotime($orcamento['checklist_data_conclusao'])); ?>.
                O cliente já pode assinar o contrato.
            </div>
        <?php endif; ?>
        
        <!-- FORMULÁRIO DE RESUMO -->
        <div class="card">
            <div class="card-header">
                <h2>📊 Informações Gerais do Checklist</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col">
                            <div class="form-group">
                                <label>Tempo Máximo para Execução</label>
                                <input type="text" name="tempo_maximo_execucao" class="form-control" 
                                       placeholder="Ex: 5 dias úteis, 10 dias corridos"
                                       value="<?php echo htmlspecialchars($checklist_resumo['tempo_maximo_execucao'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <small class="form-text text-muted">Defina o prazo estimado para conclusão da obra</small>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-group">
                                <label>Status do Checklist</label>
                                <select name="status_checklist" class="form-control">
                                    <option value="pendente" <?php echo ($orcamento['checklist_status'] === 'pendente') ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="em_andamento" <?php echo ($orcamento['checklist_status'] === 'em_andamento') ? 'selected' : ''; ?>>Em Andamento</option>
                                    <option value="concluido" <?php echo ($orcamento['checklist_status'] === 'concluido') ? 'selected' : ''; ?>>Concluído</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Observações Gerais</label>
                        <textarea name="observacoes_gerais" class="form-control" rows="3" placeholder="Observações importantes sobre a obra, complexidades, necessidade de equipamentos especiais, etc..."><?php echo htmlspecialchars($checklist_resumo['observacoes_gerais'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    
                    <button type="submit" name="acao" value="salvar_resumo" class="btn btn-primary">💾 Salvar Informações Gerais</button>
                </form>
            </div>
        </div>
        
        <!-- EQUIPAMENTOS -->
        <div class="card">
            <div class="card-header">
                <h2>🖥️ Equipamentos</h2>
                <button onclick="abrirModalEquipamento()" class="btn btn-success btn-sm">➕ Novo Equipamento</button>
            </div>
            <div class="card-body">
                <?php if (empty($equipamentos)): ?>
                    <p style="text-align: center; color: #999; padding: 40px;">Nenhum equipamento cadastrado. Clique em "Novo Equipamento" para começar.</p>
                <?php else: ?>
                    <?php foreach ($equipamentos as $equip): ?>
                        <div class="equipamento-item">
                            <div class="equipamento-header">
                                <h3>
                                    <?php echo htmlspecialchars($equip['nome_equipamento'], ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="badge badge-<?php echo $equip['tipo_equipamento']; ?>">
                                        <?php 
                                        $tipos = ['novo' => 'Novo', 'usado' => 'Usado', 'remanejado' => 'Remanejado'];
                                        echo $tipos[$equip['tipo_equipamento']] ?? $equip['tipo_equipamento'];
                                        ?>
                                    </span>
                                </h3>
                                <div>
                                    <button onclick="abrirModalEquipamento(<?php echo $equip['id']; ?>)" class="btn btn-primary btn-sm">✏️ Editar</button>
                                    <button onclick="excluirEquipamento(<?php echo $equip['id']; ?>)" class="btn btn-danger btn-sm">🗑️ Excluir</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="form-itens" data-equip-id="<?php echo $equip['id']; ?>">
                                    <table class="checklist-table">
                                        <thead>
                                            <tr>
                                                <th width="5%">#</th>
                                                <th width="35%">Item</th>
                                                <th width="15%">Status</th>
                                                <th width="30%">Observação</th>
                                                <th width="15%">Foto</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($equip['itens'] as $idx => $item): ?>
                                                <tr>
                                                    <td><?php echo $idx + 1; ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['item_descricao'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                        <br><small class="text-muted"><?php echo $item['categoria']; ?></small>
                                                    </td>
                                                    <td>
                                                        <select name="itens[<?php echo $item['id']; ?>][status]" class="form-control" style="width: 120px;">
                                                            <option value="ok" <?php echo ($item['status'] === 'ok') ? 'selected' : ''; ?>>✅ OK</option>
                                                            <option value="pendente" <?php echo ($item['status'] === 'pendente') ? 'selected' : ''; ?>>⏳ Pendente</option>
                                                            <option value="problema" <?php echo ($item['status'] === 'problema') ? 'selected' : ''; ?>>⚠️ Problema</option>
                                                            <option value="nao_aplicavel" <?php echo ($item['status'] === 'nao_aplicavel') ? 'selected' : ''; ?>>🚫 Não Aplicável</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <textarea name="itens[<?php echo $item['id']; ?>][observacao]" class="form-control" rows="2" placeholder="Observação..."><?php echo htmlspecialchars($item['observacao'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if (!empty($item['foto_url'])): ?>
                                                            <img src="<?php echo BASE_URL; ?>/storage/checklist_fotos/<?php echo $item['foto_url']; ?>" 
                                                                 class="foto-preview" 
                                                                 onclick="verFoto('<?php echo BASE_URL; ?>/storage/checklist_fotos/<?php echo $item['foto_url']; ?>')">
                                                            <br>
                                                            <button type="button" onclick="uploadFoto(<?php echo $item['id']; ?>)" class="btn btn-secondary btn-sm" style="margin-top: 5px;">📷 Trocar</button>
                                                        <?php else: ?>
                                                            <button type="button" onclick="uploadFoto(<?php echo $item['id']; ?>)" class="btn btn-secondary btn-sm">📷 Adicionar Foto</button>
                                                        <?php endif; ?>
                                                        <input type="file" id="foto_<?php echo $item['id']; ?>" style="display: none;" accept="image/*">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <hr>
                                    <button type="submit" name="acao" value="salvar_itens" class="btn btn-primary">💾 Salvar Itens deste Equipamento</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- RESUMO DE TAXAS -->
        <?php if ($checklist_resumo && ($checklist_resumo['total_equipamentos_sujos'] > 0)): ?>
            <div class="card">
                <div class="card-header">
                    <h2>💰 Taxas Adicionais</h2>
                </div>
                <div class="card-body">
                    <div class="resumo-info">
                        <p><strong>🧹 Equipamentos com sujeira excessiva:</strong> <?php echo $checklist_resumo['total_equipamentos_sujos']; ?></p>
                        <p><strong>💰 Taxa de limpeza por equipamento:</strong> R$ 350,00</p>
                        <p><strong>💵 Total de taxa de limpeza:</strong> <span style="color: #dc3545; font-size: 18px;">R$ <?php echo number_format($checklist_resumo['taxa_limpeza_total'], 2, ',', '.'); ?></span></p>
                        <p><strong>📅 Dias adicionais na entrega:</strong> <?php echo $checklist_resumo['dias_adicionais']; ?> dia(s)</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- BOTÃO CONCLUIR CHECKLIST -->
        <?php if ($orcamento['checklist_status'] !== 'concluido' && !empty($equipamentos)): ?>
            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <form method="POST" action="" onsubmit="return confirm('Tem certeza que deseja concluir o checklist? Após concluído, o cliente poderá assinar o contrato.');">
                        <button type="submit" name="acao" value="concluir_checklist" class="btn btn-success" style="padding: 12px 30px; font-size: 16px;">
                            ✅ Concluir Checklist e Liberar Assinatura
                        </button>
                        <p style="margin-top: 10px; font-size: 12px; color: #666;">Após concluir, o cliente poderá acessar e assinar o contrato digitalmente.</p>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- LINK VOLTAR -->
        <div style="text-align: center; margin-top: 20px;">
            <a href="<?php echo BASE_URL; ?>/app/admin/orcamentos.php" class="btn btn-secondary">← Voltar para Orçamentos</a>
            <a href="<?php echo BASE_URL; ?>/app/admin/gerar_contrato_pdf.php?id=<?php echo $id; ?>" class="btn btn-primary" target="_blank">📄 Visualizar Contrato</a>
        </div>
    </div>
    
    <!-- MODAL EQUIPAMENTO -->
    <div id="modalEquipamento" class="modal" style="display: none;">
        <div class="modal-content" style="background: white; padding: 30px; border-radius: 10px; max-width: 500px;">
            <span class="close-modal" onclick="fecharModalEquipamento()" style="color: #333; top: 10px; right: 20px;">&times;</span>
            <h2 style="margin-bottom: 20px;" id="modalEquipamentoTitulo">Adicionar Equipamento</h2>
            <form method="POST" action="" id="formEquipamento">
                <input type="hidden" name="acao" value="salvar_equipamento">
                <input type="hidden" name="equip_id" id="equip_id" value="0">
                
                <div class="form-group">
                    <label>Nome/Identificação do Equipamento *</label>
                    <input type="text" name="nome_equipamento" id="nome_equipamento" class="form-control" required placeholder="Ex: Piso-teto Sala 1, Split Quarto, etc">
                </div>
                
                <div class="form-group">
                    <label>Tipo de Equipamento</label>
                    <select name="tipo_equipamento" id="tipo_equipamento" class="form-control">
                        <option value="usado">Usado (existente)</option>
                        <option value="novo">Novo</option>
                        <option value="remanejado">Remanejado</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Marca</label>
                    <input type="text" name="marca" id="marca" class="form-control" placeholder="Ex: LG, Samsung, Gree, etc">
                </div>
                
                <div class="form-group">
                    <label>Modelo</label>
                    <input type="text" name="modelo" id="modelo" class="form-control" placeholder="Ex: S3-W18K, etc">
                </div>
                
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label>Potência (BTU)</label>
                            <input type="text" name="potencia_btu" id="potencia_btu" class="form-control" placeholder="Ex: 18000">
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label>Gás Refrigerante</label>
                            <input type="text" name="gas_refrigerante" id="gas_refrigerante" class="form-control" placeholder="Ex: R-410A, R-32">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">💾 Salvar Equipamento</button>
            </form>
        </div>
    </div>
    
    <!-- MODAL FOTO -->
    <div id="modalFoto" class="modal" style="display: none;">
        <span class="close-modal" onclick="fecharModalFoto()">&times;</span>
        <div class="modal-content">
            <img id="fotoGrande" src="" alt="Foto">
        </div>
    </div>
    
    <script>
        function abrirModalEquipamento(id = 0) {
            const modal = document.getElementById('modalEquipamento');
            const titulo = document.getElementById('modalEquipamentoTitulo');
            
            if (id > 0) {
                titulo.innerText = 'Editar Equipamento';
                <?php foreach ($equipamentos as $equip): ?>
                    if (id === <?php echo $equip['id']; ?>) {
                        document.getElementById('equip_id').value = <?php echo $equip['id']; ?>;
                        document.getElementById('nome_equipamento').value = '<?php echo addslashes($equip['nome_equipamento']); ?>';
                        document.getElementById('tipo_equipamento').value = '<?php echo $equip['tipo_equipamento']; ?>';
                        document.getElementById('marca').value = '<?php echo addslashes($equip['marca'] ?? ''); ?>';
                        document.getElementById('modelo').value = '<?php echo addslashes($equip['modelo'] ?? ''); ?>';
                        document.getElementById('potencia_btu').value = '<?php echo addslashes($equip['potencia_btu'] ?? ''); ?>';
                        document.getElementById('gas_refrigerante').value = '<?php echo addslashes($equip['gas_refrigerante'] ?? ''); ?>';
                    }
                <?php endforeach; ?>
            } else {
                titulo.innerText = 'Adicionar Equipamento';
                document.getElementById('equip_id').value = '0';
                document.getElementById('nome_equipamento').value = '';
                document.getElementById('tipo_equipamento').value = 'usado';
                document.getElementById('marca').value = '';
                document.getElementById('modelo').value = '';
                document.getElementById('potencia_btu').value = '';
                document.getElementById('gas_refrigerante').value = '';
            }
            
            modal.style.display = 'flex';
        }
        
        function fecharModalEquipamento() {
            document.getElementById('modalEquipamento').style.display = 'none';
        }
        
        function excluirEquipamento(id) {
            if (confirm('Tem certeza que deseja excluir este equipamento? Todos os itens do checklist serão removidos.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const inputAcao = document.createElement('input');
                inputAcao.type = 'hidden';
                inputAcao.name = 'acao';
                inputAcao.value = 'excluir_equipamento';
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'equip_id';
                inputId.value = id;
                form.appendChild(inputAcao);
                form.appendChild(inputId);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function uploadFoto(itemId) {
            const fileInput = document.getElementById('foto_' + itemId);
            fileInput.click();
            
            fileInput.onchange = function() {
                if (this.files && this.files[0]) {
                    const formData = new FormData();
                    formData.append('acao', 'upload_foto');
                    formData.append('item_id', itemId);
                    formData.append('foto', this.files[0]);
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            const response = JSON.parse(xhr.responseText);
                            if (response.sucesso) {
                                location.reload();
                            } else {
                                alert('Erro ao fazer upload: ' + (response.erro || 'Erro desconhecido'));
                            }
                        } else {
                            alert('Erro na comunicação com o servidor');
                        }
                    };
                    xhr.send(formData);
                }
            };
        }
        
        function verFoto(url) {
            const modal = document.getElementById('modalFoto');
            const img = document.getElementById('fotoGrande');
            img.src = url;
            modal.style.display = 'flex';
        }
        
        function fecharModalFoto() {
            document.getElementById('modalFoto').style.display = 'none';
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modalEquip = document.getElementById('modalEquipamento');
            const modalFoto = document.getElementById('modalFoto');
            if (event.target === modalEquip) {
                modalEquip.style.display = 'none';
            }
            if (event.target === modalFoto) {
                modalFoto.style.display = 'none';
            }
        };
    </script>
</body>
</html>
