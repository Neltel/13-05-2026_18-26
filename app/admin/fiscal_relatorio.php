<?php
// Relatório Mensal MEI - Cálculo do Lucro Disponível
$mes_referencia = $_GET['mes'] ?? date('Y-m');
$ano = substr($mes_referencia, 0, 4);
$mes = substr($mes_referencia, 5, 2);

// 1. Total de Entradas PJ (Faturamento)
$sql_entradas = "SELECT SUM(valor) as total 
                 FROM financeiro_arquivos 
                 WHERE tipo_conta = 'PJ' 
                 AND categoria IN ('Vendas de Serviços', 'Vendas de Produtos', 'Outras Receitas')
                 AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_referencia'";
$result_entradas = $conn->query($sql_entradas);
$total_entradas = $result_entradas->fetch_assoc()['total'] ?? 0;

// 2. Custos de Peças e Materiais
$sql_pecas = "SELECT SUM(valor) as total 
              FROM financeiro_arquivos 
              WHERE tipo_conta = 'PJ' 
              AND categoria IN ('Compra de Peças', 'Material de Consumo')
              AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_referencia'";
$result_pecas = $conn->query($sql_pecas);
$total_pecas = $result_pecas->fetch_assoc()['total'] ?? 0;

// 3. Pagamentos de Ajudantes
$sql_ajudantes = "SELECT SUM(valor) as total 
                  FROM pagamentos_ajudantes 
                  WHERE status = 'pago'
                  AND DATE_FORMAT(data_pagamento, '%Y-%m') = '$mes_referencia'";
$result_ajudantes = $conn->query($sql_ajudantes);
$total_ajudantes = $result_ajudantes->fetch_assoc()['total'] ?? 0;

// 4. Outras Despesas PJ
$sql_outras = "SELECT SUM(valor) as total 
               FROM financeiro_arquivos 
               WHERE tipo_conta = 'PJ' 
               AND categoria IN ('Combustível', 'Alimentação', 'Ferramentas/Equipamentos', 'Manutenção de Veículo', 'Despesas Administrativas')
               AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_referencia'";
$result_outras = $conn->query($sql_outras);
$total_outras = $result_outras->fetch_assoc()['total'] ?? 0;

// 5. Lucro Disponível para Transferência PF
$lucro_disponivel = $total_entradas - ($total_pecas + $total_ajudantes + $total_outras);

// Buscar transferências já realizadas no mês
$sql_transferencias = "SELECT SUM(valor) as total 
                       FROM financeiro_arquivos 
                       WHERE tipo_conta = 'PF' 
                       AND categoria = 'Transferência PJ para PF'
                       AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_referencia'";
$result_transf = $conn->query($sql_transferencias);
$total_transferido = $result_transf->fetch_assoc()['total'] ?? 0;

$saldo_a_transferir = $lucro_disponivel - $total_transferido;

// Limite MEI 2026
$limite_mei_anual = 81000; // 81 mil reais
$faturamento_acumulado = 0;
$sql_acumulado = "SELECT SUM(valor) as total 
                  FROM financeiro_arquivos 
                  WHERE tipo_conta = 'PJ' 
                  AND categoria IN ('Vendas de Serviços', 'Vendas de Produtos', 'Outras Receitas')
                  AND YEAR(data_referencia) = $ano";
$result_acum = $conn->query($sql_acumulado);
$faturamento_acumulado = $result_acum->fetch_assoc()['total'] ?? 0;
$percentual_limite = ($faturamento_acumulado / $limite_mei_anual) * 100;
$disponivel_anual = $limite_mei_anual - $faturamento_acumulado;
?>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i> Relatório MEI - <?= date('F/Y', strtotime($mes_referencia . '-01')) ?></h6>
                <div>
                    <input type="month" class="form-control form-control-sm" value="<?= $mes_referencia ?>" onchange="window.location.href='?aba=relatorio&mes='+this.value" style="width: auto; display: inline-block;">
                </div>
            </div>
            <div class="card-body">
                <!-- Cards Principais -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6 class="card-title">Faturamento PJ</h6>
                                <h3 class="mb-0">R$ <?= number_format($total_entradas, 2, ',', '.') ?></h3>
                                <small>Total de vendas no período</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h6 class="card-title">Custos Totais</h6>
                                <h3 class="mb-0">R$ <?= number_format($total_pecas + $total_ajudantes + $total_outras, 2, ',', '.') ?></h3>
                                <small>Peças: R$ <?= number_format($total_pecas, 2, ',', '.') ?> | Ajudantes: R$ <?= number_format($total_ajudantes, 2, ',', '.') ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6 class="card-title">Lucro Disponível</h6>
                                <h3 class="mb-0">R$ <?= number_format($lucro_disponivel, 2, ',', '.') ?></h3>
                                <small>Valor que pode ser transferido para PF</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h6 class="card-title">A Transferir</h6>
                                <h3 class="mb-0">R$ <?= number_format($saldo_a_transferir, 2, ',', '.') ?></h3>
                                <small>Já transferido: R$ <?= number_format($total_transferido, 2, ',', '.') ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabela de Composição -->
                <div class="table-responsive mb-4">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr><th colspan="2" class="text-center">Cálculo do Lucro Disponível</th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-success">
                                <td width="50%"><strong>(+) Total de Entradas PJ (Faturamento)</strong></td>
                                <td class="text-end fw-bold">R$ <?= number_format($total_entradas, 2, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) Custos de Peças e Materiais</strong></td>
                                <td class="text-end text-danger">- R$ <?= number_format($total_pecas, 2, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) Pagamentos de Ajudantes</strong></td>
                                <td class="text-end text-danger">- R$ <?= number_format($total_ajudantes, 2, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) Outras Despesas PJ</strong></td>
                                <td class="text-end text-danger">- R$ <?= number_format($total_outras, 2, ',', '.') ?></td>
                            </tr>
                            <tr class="table-info">
                                <td><strong>= LUCRO DISPONÍVEL PARA TRANSFERÊNCIA</strong></td>
                                <td class="text-end fw-bold">R$ <?= number_format($lucro_disponivel, 2, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td><strong>(-) Transferências já realizadas este mês (PJ → PF)</strong></td>
                                <td class="text-end text-danger">- R$ <?= number_format($total_transferido, 2, ',', '.') ?></td>
                            </tr>
                            <tr class="table-warning">
                                <td><strong>= SALDO A TRANSFERIR PARA PF</strong></td>
                                <td class="text-end fw-bold">R$ <?= number_format($saldo_a_transferir, 2, ',', '.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Alerta Limite MEI -->
                <div class="alert <?= $percentual_limite > 90 ? 'alert-danger' : ($percentual_limite > 70 ? 'alert-warning' : 'alert-info') ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="fas fa-chart-line me-2"></i> Acompanhamento do Limite MEI <?= $ano ?></strong><br>
                            <small>Limite anual: R$ 81.000,00</small>
                        </div>
                        <div class="text-end">
                            <strong>Faturamento acumulado: R$ <?= number_format($faturamento_acumulado, 2, ',', '.') ?></strong><br>
                            <small>Disponível para faturar: R$ <?= number_format($disponivel_anual, 2, ',', '.') ?> (<?= number_format($percentual_limite, 1) ?>% utilizado)</small>
                        </div>
                    </div>
                    <div class="progress mt-2">
                        <div class="progress-bar <?= $percentual_limite > 90 ? 'bg-danger' : ($percentual_limite > 70 ? 'bg-warning' : 'bg-info') ?>" 
                             style="width: <?= min($percentual_limite, 100) ?>%">
                            <?= number_format($percentual_limite, 1) ?>%
                        </div>
                    </div>
                    <?php if($percentual_limite > 90): ?>
                        <div class="mt-2 text-danger">
                            <i class="fas fa-exclamation-triangle me-1"></i> 
                            <strong>ATENÇÃO:</strong> Você está próximo do limite do MEI! Considere fazer o desenquadramento para ME ou diminuir o faturamento.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Botões de Ação -->
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-success" onclick="window.location.href='?aba=pf&categoria=Transferência%20PJ%20para%20PF'">
                        <i class="fas fa-exchange-alt me-1"></i> Registrar Transferência PJ → PF
                    </button>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Imprimir Relatório
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>