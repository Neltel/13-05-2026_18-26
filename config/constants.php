<?php
/**
 * =====================================================
 * CONSTANTES DO SISTEMA
 * =====================================================
 * 
 * Responsabilidade: Definir constantes de uso geral
 * Uso: Incluir em todos os arquivos que precisam
 * Recebe: Nada
 * Retorna: Constantes PHP
 * 
 * Tipos de constantes aqui:
 * - Mensagens de erro/sucesso
 * - Enums (valores fixos)
 * - Configurações de validação
 * - Padrões de regex
 */

// ===== MENSAGENS DE RESPOSTA PADRÃO =====

define('MSG_SUCESSO', 'Operação realizada com sucesso!');
define('MSG_ERRO', 'Erro ao processar a solicitação.');
define('MSG_NAO_ENCONTRADO', 'Registro não encontrado.');
define('MSG_JA_EXISTE', 'Este registro já existe no sistema.');
define('MSG_ACESSO_NEGADO', 'Acesso negado. Você não tem permissão para acessar este recurso.');
define('MSG_SESSAO_EXPIRADA', 'Sua sessão expirou. Faça login novamente.');
define('MSG_DADOS_INVALIDOS', 'Dados inválidos. Verifique os campos e tente novamente.');

// ===== TIPOS DE USUÁRIO =====

define('TIPO_ADMIN', 'admin');
define('TIPO_CLIENTE', 'cliente');

// ===== ENUMS - SITUAÇÕES DE ORÇAMENTO =====

define('ORCAMENTO_PENDENTE', 'pendente');
define('ORCAMENTO_APROVADO', 'aprovado');
define('ORCAMENTO_REJEITADO', 'rejeitado');
define('ORCAMENTO_CONVERTIDO', 'convertido');

// ===== ENUMS - SITUAÇÕES DE PEDIDO =====

define('PEDIDO_PENDENTE', 'pendente');
define('PEDIDO_EM_ANDAMENTO', 'em_andamento');
define('PEDIDO_FINALIZADO', 'finalizado');
define('PEDIDO_CANCELADO', 'cancelado');

// ===== ENUMS - SITUAÇÕES DE AGENDAMENTO =====

define('AGENDAMENTO_AGENDADO', 'agendado');
define('AGENDAMENTO_CONFIRMADO', 'confirmado');
define('AGENDAMENTO_EM_EXECUCAO', 'em_execucao');
define('AGENDAMENTO_FINALIZADO', 'finalizado');
define('AGENDAMENTO_CANCELADO', 'cancelado');

// ===== ENUMS - SITUAÇÕES DE COBRANÇA =====

define('COBRANCA_PENDENTE', 'pendente');
define('COBRANCA_VENCIDA', 'vencida');
define('COBRANCA_A_RECEBER', 'a_receber');
define('COBRANCA_RECEBIDA', 'recebida');

// ===== ENUMS - TIPOS DE GARANTIA =====

define('GARANTIA_INSTALACAO', 'instalacao');
define('GARANTIA_MANUTENCAO', 'manutencao');
define('GARANTIA_REPARO', 'reparo');
define('GARANTIA_PECAS', 'pecas');
define('GARANTIA_SERVICOS_GERAIS', 'servicos_gerais');
define('GARANTIA_PERSONALIZADA', 'personalizado');

// ===== ENUMS - TIPOS DE PAGAMENTO =====

define('PAGAMENTO_DINHEIRO', 'dinheiro');
define('PAGAMENTO_DEBITO', 'debito');
define('PAGAMENTO_CREDITO', 'credito');
define('PAGAMENTO_PIX', 'pix');
define('PAGAMENTO_CHEQUE', 'cheque');

// ===== ENUMS - FREQUÊNCIA DE PREVENTIVA =====

define('FREQ_SEMANAL', 'semanal');
define('FREQ_QUINZENAL', 'quinzenal');
define('FREQ_MENSAL', 'mensal');
define('FREQ_TRIMESTRAL', 'trimestral');
define('FREQ_SEMESTRAL', 'semestral');
define('FREQ_ANUAL', 'anual');

// ===== ENUMS - STATUS DE PREVENTIVA =====

define('PREVENTIVA_ATIVA', 'ativo');
define('PREVENTIVA_INATIVA', 'inativo');
define('PREVENTIVA_PAUSADA', 'pausado');

// ===== VALIDAÇÃO DE DADOS =====

// Padrão para email
define('REGEX_EMAIL', '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/');

// Padrão para telefone (com ou sem máscara)
define('REGEX_TELEFONE', '/^(\(?\d{2}\)?\s?)?9?\d{4}-?\d{4}$/');

// Padrão para CPF (somente números)
define('REGEX_CPF', '/^[0-9]{11}$/');

// Padrão para CNPJ (somente números)
define('REGEX_CNPJ', '/^[0-9]{14}$/');

// Padrão para CEP
define('REGEX_CEP', '/^[0-9]{5}-?[0-9]{3}$/');

// ===== CAMPOS OBRIGATÓRIOS POR FORMULÁRIO =====

define('CAMPOS_CLIENTE', ['nome', 'cpf_cnpj', 'telefone', 'endereco_rua', 'endereco_numero', 'endereco_bairro', 'endereco_cidade', 'endereco_estado', 'endereco_cep']);

define('CAMPOS_PRODUTO', ['nome', 'categoria_id', 'valor_venda']);

define('CAMPOS_SERVICO', ['nome', 'valor_unitario', 'tempo_execucao']);

define('CAMPOS_ORCAMENTO', ['cliente_id']);

// ===== TAXAS E PERCENTUAIS PADRÃO =====

// Desconto padrão para dinheiro (5%)
define('DESCONTO_DINHEIRO', 5);

// Acréscimo cartão de crédito (padrão 3.5%, configurável)
define('ACRESCIMO_CREDITO', 3.5);

// Acréscimo cartão de débito (padrão 1.2%, configurável)
define('ACRESCIMO_DEBITO', 1.2);

// Acréscimo nota fiscal (padrão 10%)
define('ACRESCIMO_NOTA_FISCAL', 10);

// ===== DIAS DE VALIDADE =====

// Validade padrão de orçamento (30 dias)
define('DIAS_VALIDADE_ORCAMENTO', 30);

// Validade padrão de garantia (30 dias)
define('DIAS_VALIDADE_GARANTIA', 30);

// ===== CORES E TEMAS =====

define('COR_PRIMARIA', '#667eea');
define('COR_SECUNDARIA', '#764ba2');
define('COR_SUCESSO', '#28a745');
define('COR_AVISO', '#ffc107');
define('COR_ERRO', '#dc3545');
define('COR_INFO', '#17a2b8');

// ===== VERSÃO DO SISTEMA =====

define('VERSAO_SISTEMA', '1.0.0');
define('DATA_VERSAO', '2026-02-14');

?>
