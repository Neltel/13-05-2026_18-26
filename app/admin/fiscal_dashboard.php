<?php
// Dashboard Fiscal - Resumo geral
$mes_atual = date('Y-m');
$ano_atual = date('Y');

// Totais PJ
$sql_pj = "SELECT 
    SUM(CASE WHEN categoria IN ('Vendas de Serviços', 'Vendas de Produtos', 'Outras Receitas') THEN valor ELSE 0 END) as total_entradas,
    SUM(CASE WHEN categoria IN ('Compra de Peças', 'Material de Consumo', 'Pagamento Ajudantes', 'Combustível', 'Alimentação', 'Ferramentas/Equipamentos', 'Manutenção de Veículo', 'Despesas Administrativas') THEN valor ELSE 0 END) as total_saidas
FROM financeiro_arquivos 
WHERE tipo_conta = 'PJ' AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_atual'";

$result_pj = $conn->query($sql_pj);
$pj = $result_pj->fetch_assoc();
$pj_entradas = $pj['total_entradas'] ?? 0;
$pj_saidas = $pj['total_saidas'] ?? 0;
$pj_saldo = $pj_entradas - $pj_saidas;

// Totais PF
$sql_pf = "SELECT 
    SUM(CASE WHEN categoria IN ('Transferência PJ para PF', 'Pró-labore', 'Retirada de Lucro', 'Outras Receitas PF') THEN valor ELSE 0 END) as total_entradas,
    SUM(CASE WHEN categoria IN ('Despesas Pessoais', 'Investimentos', 'Pagamento de Contas') THEN valor ELSE 0 END) as total_saidas
FROM financeiro_arquivos 
WHERE tipo_conta = 'PF' AND DATE_FORMAT(data_referencia, '%Y-%m') = '$mes_atual'";

$result_pf = $conn->query($sql_pf);
$pf = $result_pf->fetch_assoc();
$pf_entradas = $pf['total_entradas'] ?? 0;
$pf_saidas = $pf['total_saidas'] ?? 0;
$pf_saldo = $pf_entradas - $pf_saidas;

// Pagamentos pendentes
$pag_pendentes = $conn->query("SELECT COUNT(*) as total FROM pagamentos_ajudantes WHERE status = 'pendente'")->fetch_assoc()['total'];
?>

<div class="row">
    <!-- Cards Resumo -->
    <div class="col-md-3 mb-3">
        <div class="card card-stats bg-primary text-white" onclick="window.location.href='?aba=pj'">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Saldo PJ</h6>
                        <h3 class="mb-0">R$ <?= number_format($pj_saldo, 2, ',', '.') ?></h3>
                    </div>
                    <i class="fas fa-building fa-2x opacity-50"></i>
                </div>
                <small class="opacity-75">Entradas: R$ <?= number_format($pj_entradas, 2, ',', '.') ?> | Saídas: R$ <?= number_format($pj_saidas, 2, ',', '.') ?></small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card card-stats bg-success text-white" onclick="window.location.href='?aba=pf'">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Saldo PF</h6>
                        <h3 class="mb-0">R$ <?= number_format($pf_saldo, 2, ',', '.') ?></h3>
                    </div>
                    <i class="fas fa-user fa-2x opacity-50"></i>
                </div>
                <small class="opacity-75">Entradas: R$ <?= number_format($pf_entradas, 2, ',', '.') ?> | Saídas: R$ <?= number_format($pf_saidas, 2, ',', '.') ?></small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card card-stats bg-warning text-dark" onclick="window.location.href='?aba=pagamentos'">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Pagamentos Pendentes</h6>
                        <h3 class="mb-0"><?= $pag_pendentes ?></h3>
                    </div>
                    <i class="fas fa-clock fa-2x opacity-50"></i>
                </div>
                <small>Aguardando confirmação</small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card card-stats bg-info text-white" onclick="window.location.href='?aba=ajudantes'">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="card-title">Ajudantes Ativos</h6>
                        <h3 class="mb-0"><?= $conn->query("SELECT COUNT(*) as total FROM ajudantes WHERE ativo = 1")->fetch_assoc()['total'] ?></h3>
                    </div>
                    <i class="fas fa-users fa-2x opacity-50"></i>
                </div>
                <small>Cadastrados no sistema</small>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <!-- Últimos Lançamentos PJ -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-building me-2"></i> Últimos Lançamentos PJ</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr><th>Data</th><th>Categoria</th><th>Valor</th><th>Arquivo</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $ultimos_pj = $conn->query("SELECT * FROM financeiro_arquivos WHERE tipo_conta = 'PJ' ORDER BY created_at DESC LIMIT 5");
                            while($row = $ultimos_pj->fetch_assoc()):
                                $classe_valor = strpos($row['categoria'], 'Vendas') !== false ? 'text-success' : 'text-danger';
                                $sinal = strpos($row['categoria'], 'Vendas') !== false ? '+' : '-';
                            ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['data_referencia'])) ?></td>
                                <td><?= $row['categoria'] ?></td>
                                <td class="<?= $classe_valor ?>"><?= $sinal ?> R$ <?= number_format($row['valor'], 2, ',', '.') ?></td>
                                <td>
                                    <?php if($row['caminho_arquivo']): ?>
                                        <a href="download_arquivo.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" target="_blank"><i class="fas fa-download"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Últimos Lançamentos PF -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-user me-2"></i> Últimos Lançamentos PF</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr><th>Data</th><th>Categoria</th><th>Valor</th><th>Arquivo</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $ultimos_pf = $conn->query("SELECT * FROM financeiro_arquivos WHERE tipo_conta = 'PF' ORDER BY created_at DESC LIMIT 5");
                            while($row = $ultimos_pf->fetch_assoc()):
                                $classe_valor = strpos($row['categoria'], 'Receitas') !== false || strpos($row['categoria'], 'Transferência') !== false ? 'text-success' : 'text-danger';
                                $sinal = (strpos($row['categoria'], 'Receitas') !== false || strpos($row['categoria'], 'Transferência') !== false) ? '+' : '-';
                            ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['data_referencia'])) ?></td>
                                <td><?= $row['categoria'] ?></td>
                                <td class="<?= $classe_valor ?>"><?= $sinal ?> R$ <?= number_format($row['valor'], 2, ',', '.') ?></td>
                                <td>
                                    <?php if($row['caminho_arquivo']): ?>
                                        <a href="download_arquivo.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" target="_blank"><i class="fas fa-download"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>