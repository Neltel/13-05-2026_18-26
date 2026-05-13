<?php
/**
 * =====================================================================
 * SEED DE DADOS INICIAIS
 * =====================================================================
 * 
 * DELETE ESTE ARQUIVO APÓS USAR!
 */

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

session_start();

if (!Auth::isLogado() || !Auth::isAdmin()) {
    die("Acesso negado");
}

global $conexao;

$mensagens = [];
$erros = [];

// ===== INSERE CATEGORIA DE PRODUTO =====

$sql = "INSERT INTO categorias_produtos (nome, descricao) VALUES ('Ar Condicionado', 'Equipamentos de ar condicionado')";
if ($conexao->query($sql)) {
    $mensagens[] = "✓ Categoria criada: Ar Condicionado";
} else {
    $erros[] = "✗ Erro ao criar categoria: " . $conexao->error;
}

// ===== INSERE PRODUTOS =====

$sql = "INSERT INTO produtos (nome, descricao, categoria_id, valor_compra, valor_venda, margem_lucro, estoque_atual, foto_url) 
        VALUES 
        ('Ar Condicionado 12000 BTU', 'Ar condicionado 12000 BTU Inverter', 1, 1500, 2500, 66.67, 5, '/public/uploads/produtos/ac-12000.jpg'),
        ('Ar Condicionado 18000 BTU', 'Ar condicionado 18000 BTU Inverter', 1, 2000, 3500, 75, 3, '/public/uploads/produtos/ac-18000.jpg'),
        ('Ar Condicionado 24000 BTU', 'Ar condicionado 24000 BTU Inverter', 1, 2800, 4500, 60.71, 2, '/public/uploads/produtos/ac-24000.jpg'),
        ('Gás Refrigerante R410A', 'Gás refrigerante R410A 13.6kg', 1, 150, 250, 66.67, 20, '/public/uploads/produtos/gas.jpg'),
        ('Tubo Cobre 3/8', 'Tubo de cobre 3/8 por metro', 1, 15, 25, 66.67, 50, '/public/uploads/produtos/tubo-cobre.jpg')";

if ($conexao->query($sql)) {
    $mensagens[] = "✓ Produtos criados: 5 produtos";
} else {
    $erros[] = "✗ Erro ao criar produtos: " . $conexao->error;
}

// ===== INSERE SERVIÇOS =====

$sql = "INSERT INTO servicos (nome, descricao, valor_unitario, tempo_execucao, foto_url) 
        VALUES 
        ('Instalação de Ar Condicionado', 'Instalação completa de ar condicionado', 500, 180, '/public/uploads/servicos/instalacao.jpg'),
        ('Manutenção Preventiva', 'Limpeza e manutenção do equipamento', 150, 90, '/public/uploads/servicos/manutencao.jpg'),
        ('Recarga de Gás', 'Recarga de gás refrigerante', 200, 60, '/public/uploads/servicos/recarga-gas.jpg'),
        ('Limpeza de Ar Condicionado', 'Limpeza completa do ar condicionado', 100, 45, '/public/uploads/servicos/limpeza.jpg'),
        ('Conserto e Reparo', 'Diagnóstico e reparo de problemas', 300, 120, '/public/uploads/servicos/reparo.jpg')";

if ($conexao->query($sql)) {
    $mensagens[] = "✓ Serviços criados: 5 serviços";
} else {
    $erros[] = "✗ Erro ao criar serviços: " . $conexao->error;
}

// ===== INSERE CLIENTES DE TESTE =====

$sql = "INSERT INTO clientes (nome, pessoa_tipo, cpf_cnpj, telefone, whatsapp, email, endereco_rua, endereco_numero, endereco_bairro, endereco_cidade, endereco_estado, ativo) 
        VALUES 
        ('João Silva', 'fisica', '12345678901', '1133334444', '11999998888', 'joao@email.com', 'Rua A', '123', 'Centro', 'São Paulo', 'SP', 1),
        ('Maria Santos', 'fisica', '98765432101', '1144445555', '11999997777', 'maria@email.com', 'Rua B', '456', 'Zona Sul', 'São Paulo', 'SP', 1),
        ('Empresa XYZ LTDA', 'juridica', '12345678000190', '1155556666', '11999996666', 'contato@xyz.com.br', 'Rua C', '789', 'Centro', 'São Paulo', 'SP', 1)";

if ($conexao->query($sql)) {
    $mensagens[] = "✓ Clientes criados: 3 clientes";
} else {
    $erros[] = "✗ Erro ao criar clientes: " . $conexao->error;
}

// ===== INSERE CONFIGURAÇÃO =====

$sql = "UPDATE configuracoes 
        SET nome_empresa = 'Império AR - Refrigeração',
            cnpj = '00.000.000/0001-00',
            endereco_rua = 'Rua Exemplo',
            endereco_numero = '123',
            endereco_bairro = 'Centro',
            endereco_cidade = 'São Paulo',
            endereco_estado = 'SP',
            endereco_cep = '01000-000',
            telefone = '(11) 3333-4444',
            whatsapp = '(11) 99999-8888',
            email = 'contato@imperioar.com.br'
        WHERE id = 1";

if ($conexao->query($sql)) {
    $mensagens[] = "✓ Configuração da empresa atualizada";
} else {
    $erros[] = "✗ Erro ao atualizar configuração: " . $conexao->error;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seed de Dados - Império AR</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        
        .message {
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .resultado {
            padding: 20px;
            border-radius: 4px;
            text-align: center;
            margin-top: 30px;
        }
        
        .resultado.sucesso {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .resultado.erro {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .botoes {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        a {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Seed de Dados Iniciais</h1>
        
        <?php foreach ($mensagens as $msg): ?>
        <div class="message success"><?php echo $msg; ?></div>
        <?php endforeach; ?>
        
        <?php foreach ($erros as $erro): ?>
        <div class="message error"><?php echo $erro; ?></div>
        <?php endforeach; ?>
        
        <?php if (empty($erros)): ?>
        <div class="resultado sucesso">
            <h2>✓ Dados iniciais criados com sucesso!</h2>
            <p>O sistema está pronto para uso.</p>
        </div>
        
        <div class="botoes">
            <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" class="btn-primary">
                → Ir para Dashboard
            </a>
        </div>
        
        <p style="margin-top: 20px; text-align: center; color: #666; font-size: 12px;">
            <strong>⚠️ DELETE este arquivo (seed-dados.php) após usar</strong>
        </p>
        
        <?php else: ?>
        <div class="resultado erro">
            <h2>✗ Erro ao criar dados</h2>
            <p>Verifique os erros acima</p>
        </div>
        
        <div class="botoes">
            <a href="<?php echo BASE_URL; ?>/logout.php" class="btn-primary">
                ← Voltar ao Login
            </a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
