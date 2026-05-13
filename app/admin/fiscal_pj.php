<?php
// Conta PJ - Upload e listagem
$filtro_categoria = $_GET['categoria'] ?? '';
$filtro_mes = $_GET['mes'] ?? date('Y-m');

$where = "tipo_conta = 'PJ' AND DATE_FORMAT(data_referencia, '%Y-%m') = '$filtro_mes'";
if ($filtro_categoria) {
    $where .= " AND categoria = '" . $conn->real_escape_string($filtro_categoria) . "'";
}

// Buscar categorias disponíveis
$categorias_entrada = $conn->query("SELECT DISTINCT categoria FROM financeiro_arquivos WHERE tipo_conta = 'PJ' AND categoria IN ('Vendas de Serviços', 'Vendas de Produtos', 'Outras Receitas') ORDER BY categoria");
$categorias_saida = $conn->query("SELECT DISTINCT categoria FROM financeiro_arquivos WHERE tipo_conta = 'PJ' AND categoria IN ('Compra de Peças', 'Material de Consumo', 'Pagamento Ajudantes', 'Combustível', 'Alimentação', 'Ferramentas/Equipamentos', 'Manutenção de Veículo', 'Despesas Administrativas') ORDER BY categoria");

$query = "SELECT fa.*, a.nome as ajudante_nome 
          FROM financeiro_arquivos fa
          LEFT JOIN ajudantes a ON fa.ajudante_id = a.id
          WHERE $where 
          ORDER BY data_referencia DESC, created_at DESC";
$result = $conn->query($query);
?>

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-upload me-2"></i> Upload de Arquivo</h6>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_arquivo">
                    <input type="hidden" name="tipo_conta" value="PJ">
                    
                    <div class="mb-3">
                        <label class="form-label">Categoria <span class="text-danger">*</span></label>
                        <select name="categoria" class="form-select" required>
                            <option value="">Selecione...</option>
                            <optgroup label="Entradas (Receitas)">
                                <?php
                                $categorias_ent = ['Vendas de Serviços', 'Vendas de Produtos', 'Outras Receitas'];
                                foreach($categorias_ent as $cat):
                                ?>
                                    <option value="<?= $cat ?>">💰 <?= $cat ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Saídas (Despesas)">
                                <?php
                                $categorias_said = ['Compra de Peças', 'Material de Consumo', 'Pagamento Ajudantes', 'Combustível', 'Alimentação', 'Ferramentas/Equipamentos', 'Manutenção de Veículo', 'Despesas Administrativas'];
                                foreach($categorias_said as $cat):
                                ?>
                                    <option value="<?= $cat ?>">📉 <?= $cat ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Valor (R$) <span class="text-danger">*</span></label>
                        <input type="text" name="valor" class="form-control money" placeholder="0,00" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data de Referência <span class="text-danger">*</span></label>
                        <input type="date" name="data_referencia" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao" class="form-control" placeholder="Ex: Venda de serviço para cliente X">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Observação</label>
                        <textarea name="observacao" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Arquivo (PDF/Imagem) <span class="text-danger">*</span></label>
                        <div class="upload-area" onclick="$('#arquivo_pj').click()">
                            <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i>
                            <p class="mb-0">Clique para selecionar o comprovante</p>
                            <small class="text-muted">PDF, JPG ou PNG</small>
                        </div>
                        <input type="file" name="arquivo" id="arquivo_pj" style="display:none" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i> Salvar Lançamento
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i> Histórico PJ</h6>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" onchange="window.location.href='?aba=pj&mes='+this.value" style="width:auto">
                        <?php
                        for($i = 0; $i < 12; $i++) {
                            $data = date('Y-m', strtotime("-$i months"));
                            $selected = ($data == $filtro_mes) ? 'selected' : '';
                            echo "<option value='$data' $selected>" . date('F/Y', strtotime($data)) . "</option>";
                        }
                        ?>
                    </select>
                    <a href="?aba=pj" class="btn btn-sm btn-secondary">Limpar</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Categoria</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Arquivo</th>
                                <th width="80">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_pj = 0;
                            if($result->num_rows > 0):
                                while($row = $result->fetch_assoc()):
                                    $total_pj += ($row['categoria'] == 'Vendas de Serviços' || $row['categoria'] == 'Vendas de Produtos' || $row['categoria'] == 'Outras Receitas') ? $row['valor'] : -$row['valor'];
                            ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['data_referencia'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= (strpos($row['categoria'], 'Vendas') !== false || $row['categoria'] == 'Outras Receitas') ? 'success' : 'danger' ?>">
                                        <?= $row['categoria'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['descricao'] ?? '-') ?></td>
                                <td class="<?= (strpos($row['categoria'], 'Vendas') !== false || $row['categoria'] == 'Outras Receitas') ? 'text-success' : 'text-danger' ?> fw-bold">
                                    <?= (strpos($row['categoria'], 'Vendas') !== false || $row['categoria'] == 'Outras Receitas') ? '+' : '-' ?> 
                                    R$ <?= number_format($row['valor'], 2, ',', '.') ?>
                                </td>
                                <td>
                                    <?php if($row['caminho_arquivo'] && file_exists($row['caminho_arquivo'])): ?>
                                        <a href="download_arquivo.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info" target="_blank" title="Baixar comprovante">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-times-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" onclick="excluirArquivo(<?= $row['id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            else:
                            ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    Nenhum lançamento encontrado para este período
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if($result->num_rows > 0): ?>
                            <tr class="table-light fw-bold">
                                <td colspan="3" class="text-end">Total do Período:</td>
                                <td class="text-<?= $total_pj >= 0 ? 'success' : 'danger' ?>">
                                    R$ <?= number_format($total_pj, 2, ',', '.') ?>
                                </td>
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

<script>
$('#arquivo_pj').on('change', function() {
    var fileName = $(this).val().split('\\').pop();
    $('.upload-area p').html('<i class="fas fa-file-pdf me-1"></i> ' + fileName);
});

$('.money').mask('000.000.000.000.000,00', {reverse: true});

function excluirArquivo(id) {
    if(confirm('Tem certeza que deseja excluir este lançamento?')) {
        window.location.href = 'excluir_arquivo.php?id=' + id;
    }
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>