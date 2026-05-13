<?php
/**
 * =====================================================================
 * PROCESSADOR INTELIGENTE DE TRANSAÇÕES
 * =====================================================================
 * 
 * Este script é chamado automaticamente ao importar um extrato
 * e decide o que fazer com cada transação baseado em regras
 */

function processarTransacaoInteligente($conexao, $transacao) {
    $descricao = strtoupper($transacao['descricao']);
    $valor = $transacao['valor'];
    $data = $transacao['data'];
    $tipo = $transacao['tipo']; // 'entrada' ou 'saida'
    
    // Buscar regra que corresponde à descrição
    $sql = "SELECT * FROM regras_importacao WHERE ativo = 1 AND '$descricao' LIKE CONCAT('%', palavra_chave, '%') ORDER BY prioridade DESC LIMIT 1";
    $result = $conexao->query($sql);
    $regra = $result->fetch_assoc();
    
    if (!$regra) {
        return ['status' => 'pendente', 'mensagem' => 'Nenhuma regra encontrada'];
    }
    
    $resultado = ['status' => 'processado', 'acoes' => []];
    
    // 1. PROCESSAR PAGAMENTO DE CLIENTE
    if ($regra['tipo_transacao'] == 'cliente_pagamento' && $tipo == 'entrada') {
        $orcamento_id = null;
        
        if ($regra['buscar_orcamento']) {
            // Buscar orçamento pendente do cliente
            $sql_cliente = "SELECT id FROM clientes WHERE nome LIKE '%" . addslashes($descricao) . "%' OR cpf_cnpj LIKE '%" . addslashes($descricao) . "%' LIMIT 1";
            $cliente = $conexao->query($sql_cliente)->fetch_assoc();
            
            if ($cliente) {
                $sql_orc = "SELECT id, valor_total, valor_pago FROM orcamentos 
                            WHERE cliente_id = {$cliente['id']} 
                            AND situacao IN ('pendente', 'aprovado')
                            AND (valor_pago IS NULL OR valor_pago < valor_total)
                            ORDER BY data_emissao DESC LIMIT 1";
                $orc = $conexao->query($sql_orc)->fetch_assoc();
                
                if ($orc) {
                    $orcamento_id = $orc['id'];
                    $novo_valor_pago = ($orc['valor_pago'] ?? 0) + $valor;
                    
                    // Atualizar valor pago do orçamento
                    $conexao->query("UPDATE orcamentos SET valor_pago = $novo_valor_pago, data_pagamento = '$data' WHERE id = $orcamento_id");
                    
                    // Se pagou tudo, marcar como concluído
                    if ($novo_valor_pago >= $orc['valor_total']) {
                        $conexao->query("UPDATE orcamentos SET situacao = 'concluido' WHERE id = $orcamento_id");
                        $resultado['acoes'][] = "Orçamento #{$orc['id']} quitado e concluído";
                    } else {
                        $resultado['acoes'][] = "Pagamento de R$ " . number_format($valor, 2, ',', '.') . " aplicado ao orçamento #{$orc['id']}";
                    }
                    
                    // Registrar cobrança recebida
                    $numero_cob = 'COB-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $conexao->query("INSERT INTO cobrancas (numero, cliente_id, orcamento_id, valor, data_vencimento, status, data_recebimento, tipo_pagamento) 
                                    VALUES ('$numero_cob', {$cliente['id']}, $orcamento_id, $valor, CURDATE(), 'recebida', '$data', 'pix')");
                }
            }
        }
        
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = "Pagamento de cliente - " . $transacao['descricao'];
    }
    
    // 2. PROCESSAR PAGAMENTO DE FUNCIONÁRIO
    elseif ($regra['tipo_transacao'] == 'funcionario_pagamento' && $tipo == 'saida') {
        // Tentar identificar o funcionário/ajudante
        $sql_ajudante = "SELECT id, nome FROM ajudantes WHERE '$descricao' LIKE CONCAT('%', nome, '%') LIMIT 1";
        $ajudante = $conexao->query($sql_ajudante)->fetch_assoc();
        
        if ($ajudante) {
            // Registrar pagamento
            $conexao->query("INSERT INTO pagamentos_ajudantes (ajudante_id, valor, data_pagamento, descricao_servico, status) 
                            VALUES ({$ajudante['id']}, $valor, '$data', 'Pagamento automático - {$transacao['descricao']}', 'pago')");
            $resultado['acoes'][] = "Pagamento registrado para ajudante: {$ajudante['nome']}";
        } else {
            // Criar ajudante automaticamente?
            $resultado['acoes'][] = "Pagamento de funcionário identificado, mas ajudante não cadastrado";
        }
        
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = "Pagamento de funcionário - " . $transacao['descricao'];
    }
    
    // 3. PROCESSAR COMPRA DE PRODUTOS (baixar estoque)
    elseif ($regra['tipo_transacao'] == 'compra_produto' && $tipo == 'saida') {
        if ($regra['baixar_estoque'] && $regra['buscar_produto']) {
            // Tentar identificar qual produto foi comprado
            $produtos_comprados = [];
            
            // Buscar produtos pela descrição
            $sql_prod = "SELECT id, nome, estoque_atual FROM produtos WHERE '$descricao' LIKE CONCAT('%', nome, '%') AND ativo = 1 LIMIT 5";
            $produtos = $conexao->query($sql_prod);
            
            while ($prod = $produtos->fetch_assoc()) {
                $produtos_comprados[] = $prod;
            }
            
            if (!empty($produtos_comprados)) {
                foreach ($produtos_comprados as $prod) {
                    // Dar baixa no estoque (assumindo que comprou 1 unidade, ou tentar extrair quantidade)
                    $quantidade = 1;
                    
                    // Tentar extrair quantidade da descrição (ex: "2x TUBO")
                    if (preg_match('/(\d+)\s*[Xx]/', $descricao, $matches)) {
                        $quantidade = intval($matches[1]);
                    }
                    
                    $novo_estoque = $prod['estoque_atual'] + $quantidade; // Compra AUMENTA o estoque
                    $conexao->query("UPDATE produtos SET estoque_atual = $novo_estoque WHERE id = {$prod['id']}");
                    
                    // Registrar no histórico de consumo
                    $conexao->query("INSERT INTO historico_consumo_produtos (produto_id, orcamento_id, quantidade, data_uso) 
                                    VALUES ({$prod['id']}, NULL, $quantidade, '$data')");
                    
                    $resultado['acoes'][] = "Estoque de '{$prod['nome']}' atualizado: +{$quantidade} unidades (novo estoque: {$novo_estoque})";
                }
            } else {
                $resultado['acoes'][] = "Compra de material identificada, mas produto não encontrado no catálogo";
            }
        }
        
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = "Compra de material - " . $transacao['descricao'];
    }
    
    // 4. PROCESSAR PRÓ-LABORE
    elseif ($regra['tipo_transacao'] == 'prolabore' && $tipo == 'saida') {
        // Registrar como pró-labore na conta PF
        $conexao->query("INSERT INTO financeiro_arquivos (tipo_conta, categoria, valor, data_referencia, descricao, observacao) 
                        VALUES ('PF', 'Pró-labore', $valor, '$data', 'Pró-labore - {$transacao['descricao']}', 'Transferência automática via extrato')");
        
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = "Pró-labore - " . $transacao['descricao'];
        $resultado['acoes'][] = "Pró-labore registrado na conta PF";
    }
    
    // 5. PROCESSAR REPOSIÇÃO DE ESTOQUE
    elseif ($regra['tipo_transacao'] == 'reposicao_estoque' && $tipo == 'entrada') {
        if ($regra['acrescentar_estoque']) {
            // Aumentar estoque geral de materiais
            $conexao->query("UPDATE produtos SET estoque_atual = estoque_atual + 1 WHERE categoria_id IN (SELECT id FROM categorias_produtos WHERE nome LIKE '%material%' OR nome LIKE '%peça%')");
            $resultado['acoes'][] = "Reposição de estoque registrada";
        }
        
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = "Reposição de estoque - " . $transacao['descricao'];
    }
    
    // 6. DESPESAS GERAIS
    else {
        $resultado['categoria'] = $regra['categoria'];
        $resultado['descricao'] = $transacao['descricao'];
        $resultado['acoes'][] = "Despesa classificada como: {$regra['categoria']}";
    }
    
    return $resultado;
}
?>