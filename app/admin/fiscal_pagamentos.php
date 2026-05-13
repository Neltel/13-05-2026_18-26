<?php
// Listagem de Pagamentos
$status = $_GET['status'] ?? 'todos';
$filtro_mes = $_GET['mes'] ?? date('Y-m');

$where = "DATE_FORMAT(p.data_pagamento, '%Y-%m') = '$filtro_mes'";
if ($status != 'todos') {
    $where .= " AND p.status = '$status'";
}

$query = "SELECT p.*, a.nome, a.documento, a.tipo 
          FROM pagamentos_ajudantes p
          JOIN ajudantes a ON p.ajudante_id = a.id
          WHERE $where 
          ORDER BY p.data_pagamento DESC";
$result = $conn->query($query);
?>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i> Pagamentos Realizados</h6>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" onchange="window.location.href='?aba=pagamentos&status=<?= $status ?>&mes='+this.value" style="width:auto">
                        <?php
                        for($i = 0; $i < 12; $i++) {
                            $data = date('Y-m', strtotime("-$i months"));
                            $selected = ($data == $filtro_mes) ? 'selected' : '';
                            echo "<option value='$data' $selected>" . date('F/Y', strtotime($data)) . "</option>";
                        }
                        ?>
                    </select>
                    <select class="form-select form-select-sm" onchange="window.location.href='?aba=pagamentos&status='+this.value+'&mes=<?= $filtro_mes ?>'" style="width:auto">
                        <option value="todos" <?= $status == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendente" <?= $status == 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                        <option value="pago" <?= $status == 'pago' ? 'selected' : '' ?>>Pagos</option>
                        <option value="cancelado" <?= $status == 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
                    </select>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Ajudante</th>
                                <th>Tipo</th>
                                <th>Documento</th>
                                <th>Serviço</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th>Recibo/NF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_pagamentos = 0;
                            if($result->num_rows > 0):
                                while($row = $result->fetch_assoc()):
                                    $total_pagamentos += $row['valor'];
                            ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['data_pagamento'])) ?></td>
                                <td><?= htmlspecialchars($row['nome']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['tipo'] == 'PF' ? 'info' : 'warning' ?>">
                                        <?= $row['tipo'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['documento']) ?></td>
                                <td><?= htmlspecialchars(substr($row['descricao_servico'], 0, 50)) ?>...</td>
                                <td>R$ <?= number_format($row['valor'], 2, ',', '.') ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['status'] == 'pago' ? 'success' : ($row['status'] == 'pendente' ? 'warning' : 'danger') ?>">
                                        <?= $row['status'] == 'pago' ? 'Pago' : ($row['status'] == 'pendente' ? 'Pendente' : 'Cancelado') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($row['recibo_pdf'] && file_exists($row['recibo_pdf'])): ?>
                                        <a href="<?= $row['recibo_pdf'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-file-pdf"></i> Recibo
                                        </a>
                                    <?php elseif($row['nota_fiscal_numero']): ?>
                                        <span class="badge bg-secondary">NF: <?= $row['nota_fiscal_numero'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    Nenhum pagamento encontrado
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if($result->num_rows > 0): ?>
                            <tr class="table-light fw-bold">
                                <td colspan="5" class="text-end">Total de Pagamentos:</td>
                                <td class="text-danger">R$ <?= number_format($total_pagamentos, 2, ',', '.') ?></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>