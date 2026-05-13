<?php
/**
 * =====================================================================
 * SALVAR CHECKLIST - PROCESSAMENTO (COM ATUALIZAÇÃO DE VALORES)
 * =====================================================================
 * 
 * URL: /app/admin/salvar_checklist.php
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';

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

// ===== VARIÁVEIS PARA FEEDBACK =====
$mensagem_sucesso = '';
$mensagem_erro = '';
$debug_info = [];

// ===== OBTÉM DADOS DO POST =====
$orcamento_id = isset($_POST['orcamento_id']) ? intval($_POST['orcamento_id']) : 0;
$liberar_assinatura = isset($_POST['liberar_assinatura']) ? 1 : 0;

if (!$orcamento_id) {
    die("ID do orçamento não informado");
}

// ===== BUSCAR DADOS DO ORÇAMENTO =====
$sql_check = "SELECT o.id, o.valor_total, o.valor_base_servicos, o.valor_impostos, c.cpf_cnpj, c.nome as cliente_nome 
              FROM orcamentos o 
              LEFT JOIN clientes c ON o.cliente_id = c.id 
              WHERE o.id = ?";
$stmt_check = $conexao->prepare($sql_check);
$stmt_check->bind_param("i", $orcamento_id);
$stmt_check->execute();
$orcamento = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

if (!$orcamento) {
    die("Orçamento não encontrado");
}

// ===== CRIA DIRETÓRIO PARA FOTOS =====
$upload_dir = __DIR__ . '/../../storage/checklist/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ===== FUNÇÃO PARA UPLOAD DE FOTOS =====
function uploadFoto($file, $campo, $orcamento_id, $existente = null) {
    if (isset($file) && $file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext_allow = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array($ext, $ext_allow)) {
            return $existente;
        }
        
        $nome_arquivo = $campo . '_' . $orcamento_id . '_' . time() . '.' . $ext;
        $caminho = __DIR__ . '/../../storage/checklist/' . $nome_arquivo;
        
        if (move_uploaded_file($file['tmp_name'], $caminho)) {
            return $nome_arquivo;
        }
    }
    return $existente;
}

// ===== 1. COLETAR DADOS DO CHECKLIST DA OBRA =====
$endereco_local = isset($_POST['endereco_local']) ? $_POST['endereco_local'] : null;
$pavimento = isset($_POST['pavimento']) ? $_POST['pavimento'] : null;
$acesso_tipo = isset($_POST['acesso_tipo']) ? $_POST['acesso_tipo'] : null;
$distancia_km = isset($_POST['distancia_km']) && $_POST['distancia_km'] !== '' ? floatval($_POST['distancia_km']) : null;
$disjuntor_dedicado = isset($_POST['disjuntor_dedicado']) ? 1 : 0;
$fio_bitola = isset($_POST['fio_bitola']) ? $_POST['fio_bitola'] : null;
$aterramento = isset($_POST['aterramento']) ? 1 : 0;
$dps = isset($_POST['dps']) ? 1 : 0;
$complexidade_eletrica = isset($_POST['complexidade_eletrica']) && $_POST['complexidade_eletrica'] !== '' ? $_POST['complexidade_eletrica'] : null;
$observacao_eletrica = isset($_POST['observacao_eletrica']) ? $_POST['observacao_eletrica'] : null;
$tubulacao_cobre = isset($_POST['tubulacao_cobre']) ? 1 : 0;
$tubulacao_bitola = isset($_POST['tubulacao_bitola']) ? $_POST['tubulacao_bitola'] : null;
$isolamento_estado = isset($_POST['isolamento_estado']) && $_POST['isolamento_estado'] !== '' ? $_POST['isolamento_estado'] : null;
$drenagem_estado = isset($_POST['drenagem_estado']) && $_POST['drenagem_estado'] !== '' ? $_POST['drenagem_estado'] : null;
$complexidade_tubulacao = isset($_POST['complexidade_tubulacao']) && $_POST['complexidade_tubulacao'] !== '' ? $_POST['complexidade_tubulacao'] : null;
$observacao_tubulacao = isset($_POST['observacao_tubulacao']) ? $_POST['observacao_tubulacao'] : null;
$altura_trabalho = isset($_POST['altura_trabalho']) && $_POST['altura_trabalho'] !== '' ? floatval($_POST['altura_trabalho']) : null;
$ponto_ancoragem = isset($_POST['ponto_ancoragem']) ? 1 : 0;
$epi_disponivel = isset($_POST['epi_disponivel']) ? $_POST['epi_disponivel'] : null;
$risco_identificado = isset($_POST['risco_identificado']) ? $_POST['risco_identificado'] : null;
$complexidade_altura = isset($_POST['complexidade_altura']) && $_POST['complexidade_altura'] !== '' ? $_POST['complexidade_altura'] : null;
$classificacao_obra = isset($_POST['classificacao_obra']) && $_POST['classificacao_obra'] !== '' ? $_POST['classificacao_obra'] : null;
$equipe_necessaria = isset($_POST['equipe_necessaria']) && $_POST['equipe_necessaria'] !== '' ? intval($_POST['equipe_necessaria']) : 1;
$prazo_maximo_dias = isset($_POST['prazo_maximo_dias']) && $_POST['prazo_maximo_dias'] !== '' ? intval($_POST['prazo_maximo_dias']) : null;
$data_inicio_prevista = isset($_POST['data_inicio_prevista']) && $_POST['data_inicio_prevista'] !== '' ? $_POST['data_inicio_prevista'] : null;
$observacoes_gerais = isset($_POST['observacoes_gerais']) ? $_POST['observacoes_gerais'] : null;

// Fotos
$foto_quadro_eletrico = uploadFoto(
    $_FILES['foto_quadro_eletrico'] ?? null,
    'quadro_eletrico',
    $orcamento_id,
    $_POST['foto_quadro_eletrico_existente'] ?? null
);
$foto_acesso = uploadFoto(
    $_FILES['foto_acesso'] ?? null,
    'acesso',
    $orcamento_id,
    $_POST['foto_acesso_existente'] ?? null
);
$foto_ambiente = uploadFoto(
    $_FILES['foto_ambiente'] ?? null,
    'ambiente',
    $orcamento_id,
    $_POST['foto_ambiente_existente'] ?? null
);

// ===== 2. CALCULAR TAXAS ADICIONAIS DOS EQUIPAMENTOS =====
$total_limpeza = 0;
$total_equipamentos_sujos = 0;
$equipamentos_dados = [];

if (isset($_POST['equipamentos']) && is_array($_POST['equipamentos'])) {
    foreach ($_POST['equipamentos'] as $equip) {
        $precisa_limpeza = isset($equip['limpeza_necessaria']) ? 1 : 0;
        if ($precisa_limpeza) {
            $total_limpeza += 350.00;
            $total_equipamentos_sujos++;
        }
        $equipamentos_dados[] = $equip;
    }
}

// ===== 3. ATUALIZAR VALORES NO ORÇAMENTO (MESMO SEM LIBERAR) =====
$valor_original = floatval($orcamento['valor_total']);
$valor_base_atualizado = $valor_original + $total_limpeza;
$valor_impostos_atualizado = $valor_base_atualizado * 0.07;

// Atualizar valores no orçamento (sempre que salvar o checklist)
$sql_update_valores = "UPDATE orcamentos SET 
                        valor_base_servicos = ?,
                        valor_impostos = ?,
                        valor_adicional = ?
                       WHERE id = ?";
$stmt_valores = $conexao->prepare($sql_update_valores);
$stmt_valores->bind_param("dddi", $valor_base_atualizado, $valor_impostos_atualizado, $total_limpeza, $orcamento_id);
$stmt_valores->execute();
$stmt_valores->close();

$debug_info[] = "Valores atualizados: Base = R$ " . number_format($valor_base_atualizado, 2) . " | Taxa limpeza = R$ " . number_format($total_limpeza, 2);

// ===== 4. SALVAR CHECKLIST DA OBRA =====
try {
    $sql_check_obra = "SELECT id FROM checklist_obra WHERE orcamento_id = ?";
    $stmt = $conexao->prepare($sql_check_obra);
    $stmt->bind_param("i", $orcamento_id);
    $stmt->execute();
    $existe_obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existe_obra) {
        $sql = "UPDATE checklist_obra SET 
                    endereco_local = ?, pavimento = ?, acesso_tipo = ?, distancia_km = ?, 
                    disjuntor_dedicado = ?, fio_bitola = ?, aterramento = ?, dps = ?, 
                    complexidade_eletrica = ?, observacao_eletrica = ?, tubulacao_cobre = ?, 
                    tubulacao_bitola = ?, isolamento_estado = ?, drenagem_estado = ?, 
                    complexidade_tubulacao = ?, observacao_tubulacao = ?, altura_trabalho = ?, 
                    ponto_ancoragem = ?, epi_disponivel = ?, risco_identificado = ?, 
                    complexidade_altura = ?, classificacao_obra = ?, equipe_necessaria = ?, 
                    prazo_maximo_dias = ?, data_inicio_prevista = ?, observacoes_gerais = ?, 
                    foto_quadro_eletrico = ?, foto_acesso = ?, foto_ambiente = ?, 
                    updated_at = NOW()
                WHERE orcamento_id = ?";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param(
            "sssdiisssssssssssdssssiisssssi",
            $endereco_local, $pavimento, $acesso_tipo, $distancia_km,
            $disjuntor_dedicado, $fio_bitola, $aterramento, $dps,
            $complexidade_eletrica, $observacao_eletrica, $tubulacao_cobre, $tubulacao_bitola,
            $isolamento_estado, $drenagem_estado, $complexidade_tubulacao, $observacao_tubulacao,
            $altura_trabalho, $ponto_ancoragem, $epi_disponivel, $risco_identificado,
            $complexidade_altura, $classificacao_obra, $equipe_necessaria, $prazo_maximo_dias,
            $data_inicio_prevista, $observacoes_gerais,
            $foto_quadro_eletrico, $foto_acesso, $foto_ambiente,
            $orcamento_id
        );
        $stmt->execute();
        $stmt->close();
        $mensagem_sucesso .= "✅ Checklist da obra ATUALIZADO! ";
        $debug_info[] = "UPDATE checklist_obra OK";
    } else {
        $sql = "INSERT INTO checklist_obra (
                    orcamento_id, endereco_local, pavimento, acesso_tipo, distancia_km, 
                    disjuntor_dedicado, fio_bitola, aterramento, dps, complexidade_eletrica, 
                    observacao_eletrica, tubulacao_cobre, tubulacao_bitola, isolamento_estado, 
                    drenagem_estado, complexidade_tubulacao, observacao_tubulacao, altura_trabalho, 
                    ponto_ancoragem, epi_disponivel, risco_identificado, complexidade_altura, 
                    classificacao_obra, equipe_necessaria, prazo_maximo_dias, data_inicio_prevista, 
                    observacoes_gerais, foto_quadro_eletrico, foto_acesso, foto_ambiente, data_vistoria
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())";
        
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param(
            "isssdiisssssssssssdssssiisssss",
            $orcamento_id, $endereco_local, $pavimento, $acesso_tipo, $distancia_km,
            $disjuntor_dedicado, $fio_bitola, $aterramento, $dps,
            $complexidade_eletrica, $observacao_eletrica, $tubulacao_cobre, $tubulacao_bitola,
            $isolamento_estado, $drenagem_estado, $complexidade_tubulacao, $observacao_tubulacao,
            $altura_trabalho, $ponto_ancoragem, $epi_disponivel, $risco_identificado,
            $complexidade_altura, $classificacao_obra, $equipe_necessaria, $prazo_maximo_dias,
            $data_inicio_prevista, $observacoes_gerais,
            $foto_quadro_eletrico, $foto_acesso, $foto_ambiente
        );
        $stmt->execute();
        $stmt->close();
        $mensagem_sucesso .= "✅ Checklist da obra CRIADO! ";
        $debug_info[] = "INSERT checklist_obra OK";
    }
} catch (Exception $e) {
    $mensagem_erro .= "❌ Erro checklist obra: " . $e->getMessage() . " ";
    $debug_info[] = "Erro: " . $e->getMessage();
}

// ===== 5. SALVAR EQUIPAMENTOS =====
$total_equipamentos = 0;
if (!empty($equipamentos_dados)) {
    try {
        $sql_del = "DELETE FROM checklist_equipamentos WHERE orcamento_id = ?";
        $stmt_del = $conexao->prepare($sql_del);
        $stmt_del->bind_param("i", $orcamento_id);
        $stmt_del->execute();
        $stmt_del->close();
        
        $sql_insert = "INSERT INTO checklist_equipamentos (
                            orcamento_id, equipamento_nome, equipamento_tipo, equipamento_marca, 
                            equipamento_modelo, equipamento_btu, serpentina_estado, filtros_estado, 
                            ventilador_estado, placa_estado, pressao_gas, limpeza_necessaria, 
                            taxa_limpeza, dias_adicionais, observacao, data_vistoria
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt_insert = $conexao->prepare($sql_insert);
        
        foreach ($equipamentos_dados as $equip) {
            $eq_nome = isset($equip['equipamento_nome']) ? $equip['equipamento_nome'] : 'Equipamento';
            $eq_tipo = isset($equip['equipamento_tipo']) ? $equip['equipamento_tipo'] : null;
            $eq_marca = isset($equip['equipamento_marca']) ? $equip['equipamento_marca'] : null;
            $eq_modelo = isset($equip['equipamento_modelo']) ? $equip['equipamento_modelo'] : null;
            $eq_btu = isset($equip['equipamento_btu']) ? $equip['equipamento_btu'] : null;
            $serpentina = isset($equip['serpentina_estado']) ? $equip['serpentina_estado'] : null;
            $filtros = isset($equip['filtros_estado']) ? $equip['filtros_estado'] : null;
            $ventilador = isset($equip['ventilador_estado']) ? $equip['ventilador_estado'] : null;
            $placa = isset($equip['placa_estado']) ? $equip['placa_estado'] : null;
            $pressao = isset($equip['pressao_gas']) ? $equip['pressao_gas'] : null;
            $limpeza = isset($equip['limpeza_necessaria']) ? 1 : 0;
            $taxa = $limpeza ? 350.00 : 0;
            $dias = $limpeza ? 1 : 0;
            $obs = isset($equip['observacao']) ? $equip['observacao'] : null;
            
            $stmt_insert->bind_param(
                "issssssssssiids",
                $orcamento_id, $eq_nome, $eq_tipo, $eq_marca, $eq_modelo, $eq_btu,
                $serpentina, $filtros, $ventilador, $placa, $pressao,
                $limpeza, $taxa, $dias, $obs
            );
            $stmt_insert->execute();
            $total_equipamentos++;
        }
        $stmt_insert->close();
        
        $mensagem_sucesso .= "✅ {$total_equipamentos} equipamento(s) salvo(s)! ";
        $debug_info[] = "{$total_equipamentos} equipamentos salvos, {$total_equipamentos_sujos} com limpeza necessária";
    } catch (Exception $e) {
        $mensagem_erro .= "❌ Erro equipamentos: " . $e->getMessage() . " ";
        $debug_info[] = "Erro equipamentos: " . $e->getMessage();
    }
} else {
    $debug_info[] = "Nenhum equipamento enviado";
}

// ===== 6. ATUALIZAR CHECKLIST_RESUMO =====
try {
    $sql_check_resumo = "SELECT id FROM checklist_resumo WHERE orcamento_id = ?";
    $stmt = $conexao->prepare($sql_check_resumo);
    $stmt->bind_param("i", $orcamento_id);
    $stmt->execute();
    $existe_resumo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $tempo_execucao = $classificacao_obra . " - " . ($prazo_maximo_dias ?? 'N/D') . " dias";
    
    if ($existe_resumo) {
        $sql_resumo = "UPDATE checklist_resumo SET 
                        tempo_maximo_execucao = ?,
                        observacoes_gerais = ?,
                        total_equipamentos_sujos = ?,
                        taxa_limpeza_total = ?,
                        dias_adicionais = ?,
                        updated_at = NOW()
                       WHERE orcamento_id = ?";
        $stmt_resumo = $conexao->prepare($sql_resumo);
        $stmt_resumo->bind_param("ssidii", $tempo_execucao, $observacoes_gerais, $total_equipamentos_sujos, $total_limpeza, $total_equipamentos_sujos, $orcamento_id);
    } else {
        $sql_resumo = "INSERT INTO checklist_resumo (
                        orcamento_id, data_checklist, tempo_maximo_execucao, observacoes_gerais,
                        total_equipamentos_sujos, taxa_limpeza_total, dias_adicionais
                       ) VALUES (?, NOW(), ?, ?, ?, ?, ?)";
        $stmt_resumo = $conexao->prepare($sql_resumo);
        $stmt_resumo->bind_param("issidi", $orcamento_id, $tempo_execucao, $observacoes_gerais, $total_equipamentos_sujos, $total_limpeza, $total_equipamentos_sujos);
    }
    $stmt_resumo->execute();
    $stmt_resumo->close();
    $debug_info[] = "Checklist resumo atualizado";
} catch (Exception $e) {
    $debug_info[] = "Erro resumo: " . $e->getMessage();
}

// ===== 7. ATUALIZAR ORÇAMENTO SE LIBERADO =====
if ($liberar_assinatura) {
    try {
        $sql_up = "UPDATE orcamentos SET 
                    checklist_concluido = 1,
                    checklist_data = NOW(),
                    checklist_usuario_id = ?,
                    checklist_status = 'concluido',
                    checklist_data_conclusao = NOW()
                   WHERE id = ?";
        
        $stmt_up = $conexao->prepare($sql_up);
        $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : 1;
        $stmt_up->bind_param("ii", $usuario_id, $orcamento_id);
        $stmt_up->execute();
        $stmt_up->close();
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'IP desconhecido';
        $sql_log = "INSERT INTO logs_contratos (orcamento_id, acao, descricao, ip, data_hora) VALUES (?, 'CHECKLIST_CONCLUIDO', 'Checklist técnico concluído e liberado para assinatura', ?, NOW())";
        $stmt_log = $conexao->prepare($sql_log);
        $stmt_log->bind_param("is", $orcamento_id, $ip);
        $stmt_log->execute();
        $stmt_log->close();
        
        $mensagem_sucesso .= "🔓 Checklist LIBERADO para assinatura! ";
        $debug_info[] = "Orçamento liberado para assinatura";
    } catch (Exception $e) {
        $mensagem_erro .= "❌ Erro liberação: " . $e->getMessage() . " ";
        $debug_info[] = "Erro liberação: " . $e->getMessage();
    }
}

// ===== EXIBIR RESULTADO =====
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultado - Salvar Checklist</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 40px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { max-width: 700px; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 30px; text-align: center; }
        .sucesso { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .erro { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .debug { background: #e9ecef; color: #495057; border: 1px solid #ced4da; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: left; font-family: monospace; font-size: 12px; }
        .btn { display: inline-block; padding: 12px 25px; background: #1e3c72; color: white; text-decoration: none; border-radius: 5px; margin: 10px; transition: all 0.3s; }
        .btn:hover { background: #2a5298; transform: translateY(-2px); }
        .btn-voltar { background: #6c757d; }
        .btn-voltar:hover { background: #5a6268; }
        h2 { color: #1e3c72; margin-bottom: 20px; }
        .info { background: #e8f4f8; padding: 15px; border-radius: 5px; margin: 15px 0; font-size: 14px; text-align: left; }
        .valores { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; text-align: left; }
    </style>
</head>
<body>
    <div class="container">
        <h2>📋 Resultado do Salvamento</h2>
        
        <?php if (!empty($mensagem_sucesso)): ?>
            <div class="sucesso">
                <strong>✅ SUCESSO!</strong><br>
                <?php echo nl2br(htmlspecialchars($mensagem_sucesso)); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($mensagem_erro)): ?>
            <div class="erro">
                <strong>❌ ERRO!</strong><br>
                <?php echo nl2br(htmlspecialchars($mensagem_erro)); ?>
            </div>
        <?php endif; ?>
        
        <div class="valores">
            <strong>💰 VALORES ATUALIZADOS:</strong><br>
            Valor original do orçamento: <strong>R$ <?php echo number_format($valor_original, 2, ',', '.'); ?></strong><br>
            Taxa de limpeza adicionada: <strong>R$ <?php echo number_format($total_limpeza, 2, ',', '.'); ?></strong> (<?php echo $total_equipamentos_sujos; ?> equipamento(s) com limpeza)<br>
            <strong style="color: #28a745;">Novo valor base: R$ <?php echo number_format($valor_base_atualizado, 2, ',', '.'); ?></strong><br>
            <strong style="color: #dc3545;">VALOR TOTAL FINAL: R$ <?php echo number_format($valor_base_atualizado, 2, ',', '.'); ?></strong>
        </div>
        
        <div class="info">
            <strong>📄 Informações:</strong><br>
            Orçamento ID: <?php echo $orcamento_id; ?><br>
            Cliente: <?php echo htmlspecialchars($orcamento['cliente_nome'] ?? 'N/A'); ?><br>
            <?php if ($liberar_assinatura): ?>
                <strong style="color: #ff9800;">🔓 Modo: LIBERAR PARA ASSINATURA</strong>
            <?php else: ?>
                <strong>💾 Modo: SALVAR APENAS (valores atualizados)</strong>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($debug_info)): ?>
        <div class="debug">
            <strong>🐛 DEBUG:</strong><br>
            <?php foreach ($debug_info as $info): ?>
                • <?php echo htmlspecialchars($info); ?><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div>
            <a href="<?php echo BASE_URL; ?>/app/admin/gerar_contrato_pdf.php?id=<?php echo $orcamento_id; ?>" class="btn">
                📄 Voltar para o Contrato
            </a>
            <a href="<?php echo BASE_URL; ?>/app/admin/orcamentos.php" class="btn btn-voltar">
                📋 Voltar para Orçamentos
            </a>
        </div>
        
        <p style="margin-top: 20px; font-size: 12px; color: #999;">
            Os valores foram atualizados no banco de dados. A taxa de limpeza (R$ 350,00 por equipamento) foi adicionada ao valor total.
        </p>
    </div>
</body>
</html>
