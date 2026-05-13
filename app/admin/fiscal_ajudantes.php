<?php
// Gerenciamento de Ajudantes
$busca = $_GET['busca'] ?? '';

$where = "1=1";
if ($busca) {
    $where .= " AND (nome LIKE '%$busca%' OR documento LIKE '%$busca%' OR telefone LIKE '%$busca%')";
}

$query = "SELECT * FROM ajudantes WHERE $where ORDER BY nome";
$result = $conn->query($query);
?>

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-user-plus me-2"></i> Novo Ajudante</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="cadastrar_ajudante">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome Completo <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tipo <span class="text-danger">*</span></label>
                        <select name="tipo" class="form-select" required>
                            <option value="PF">Pessoa Física (PF)</option>
                            <option value="PJ">Pessoa Jurídica (PJ)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">CPF / CNPJ <span class="text-danger">*</span></label>
                        <input type="text" name="documento" class="form-control documento" placeholder="000.000.000-00" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Chave PIX</label>
                        <input type="text" name="chave_pix" class="form-control" placeholder="CPF, email ou telefone">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="telefone" class="form-control telefone" placeholder="(00) 00000-0000">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Endereço</label>
                        <textarea name="endereco" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i> Cadastrar
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-users me-2"></i> Lista de Ajudantes</h6>
                <form method="GET" class="d-flex">
                    <input type="hidden" name="aba" value="ajudantes">
                    <input type="text" name="busca" class="form-control form-control-sm me-2" placeholder="Buscar..." value="<?= htmlspecialchars($busca) ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Documento</th>
                                <th>Tipo</th>
                                <th>Chave PIX</th>
                                <th>Telefone</th>
                                <th width="100">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['nome']) ?></td>
                                    <td><?= htmlspecialchars($row['documento']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $row['tipo'] == 'PF' ? 'info' : 'warning' ?>">
                                            <?= $row['tipo'] ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['chave_pix'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['telefone'] ?? '-') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" onclick="gerarPagamento(<?= $row['id'] ?>, '<?= addslashes($row['nome']) ?>', '<?= $row['tipo'] ?>')">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                        Nenhum ajudante cadastrado
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para gerar pagamento -->
<div class="modal fade" id="modalPagamento" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i> Gerar Pagamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="gerar_pagamento_ajudante">
                <input type="hidden" name="ajudante_id" id="ajudante_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ajudante</label>
                        <input type="text" id="ajudante_nome" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Valor do Pagamento (R$) <span class="text-danger">*</span></label>
                        <input type="text" name="valor" class="form-control money" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data do Pagamento <span class="text-danger">*</span></label>
                        <input type="date" name="data_pagamento" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data de Referência do Serviço <span class="text-danger">*</span></label>
                        <input type="date" name="data_referencia_servico" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição do Serviço <span class="text-danger">*</span></label>
                        <textarea name="descricao_servico" class="form-control" rows="3" placeholder="Ex: Instalação de ar condicionado 12.000 BTUs" required></textarea>
                    </div>
                    
                    <div class="mb-3" id="div_nota_fiscal" style="display:none">
                        <label class="form-label">Número da Nota Fiscal <span class="text-danger">*</span></label>
                        <input type="text" name="nota_fiscal_numero" class="form-control" placeholder="Ex: 12345">
                        <small class="text-muted">Para PJ é obrigatório informar a NF</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Gerar Pagamento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function gerarPagamento(id, nome, tipo) {
    $('#ajudante_id').val(id);
    $('#ajudante_nome').val(nome);
    
    if(tipo === 'PJ') {
        $('#div_nota_fiscal').show();
        $('#div_nota_fiscal input').prop('required', true);
    } else {
        $('#div_nota_fiscal').hide();
        $('#div_nota_fiscal input').prop('required', false);
    }
    
    $('#modalPagamento').modal('show');
}

$('.money').mask('000.000.000.000.000,00', {reverse: true});

// Máscaras para CPF/CNPJ e telefone
$('.documento').mask('000.000.000-00', {onKeyPress: function(val, e, field, options) {
    if(val.length > 11) {
        $('.documento').mask('00.000.000/0000-00');
    } else {
        $('.documento').mask('000.000.000-00');
    }
}});

$('.telefone').mask('(00) 00000-0000');
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>