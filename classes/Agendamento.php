<?php
/**
 * =====================================================================
 * CLASSE AGENDAMENTO - Gerenciar Agendamentos
 * =====================================================================
 * 
 * Responsabilidade: CRUD de agendamentos, calendário
 * Uso: $agendamento = new Agendamento(); $agendamento->criar(...);
 * Recebe: Dados de cliente, serviço, data, horário
 * Retorna: Arrays com agendamentos e disponibilidades
 * 
 * Operações:
 * - Criar novo agendamento
 * - Verificar disponibilidade de datas/horários
 * - Alterar status de agendamento
 * - Listar agendamentos por período
 * - Enviar notificações WhatsApp
 * - Gerar calendário mensal
 */

require_once __DIR__ . '/../config/database.php';

class Agendamento {
    
    private $db;
    private $tabela = 'agendamentos';
    
    /**
     * Construtor
     */
    public function __construct() {
        global $conexao;
        $this->db = $conexao;
    }
    
    /**
     * Método: criar()
     * Responsabilidade: Criar novo agendamento
     * Parâmetros:
     *   $cliente_id - int com ID do cliente
     *   $servico_id - int com ID do serviço
     *   $data - string no formato 'Y-m-d'
     *   $horario_inicio - string no formato 'H:i'
     *   $dados - array com dados opcionais
     * Retorna: int (ID do novo agendamento) ou false
     * 
     * Exemplo:
     *   $agendamento_id = $agendamento->criar(123, 10, '2026-03-01', '10:00', [
     *       'observacao' => 'Cliente solicitou manhã',
     *       'notificar_24h' => true
     *   ]);
     */
    public function criar($cliente_id, $servico_id, $data, $horario_inicio, $dados = []) {
        if (empty($cliente_id) || empty($servico_id) || empty($data) || empty($horario_inicio)) {
            return false;
        }
        
        // Valida data (não pode ser no passado)
        if (strtotime($data) < strtotime('today')) {
            return false;
        }
        
        // Valida se o horário está disponível
        if (!$this->verificar_disponibilidade($data, $horario_inicio, $servico_id)) {
            return false;
        }
        
        // Busca serviço para obter tempo de execução
        $sql = "SELECT tempo_execucao FROM servicos WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $servico_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $servico = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$servico) {
            return false;
        }
        
        // Calcula horário de término
        $tempo_minutos = $servico['tempo_execucao'];
        $horario_fim = date('H:i', strtotime($horario_inicio) + ($tempo_minutos * 60));
        
        // Prepara dados
        $sql = "INSERT INTO agendamentos 
                (cliente_id, servico_id, data_agendamento, horario_inicio, horario_fim, status, observacao, notificacao_24h, notificacao_1h) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        
        $status = 'agendado';
        $observacao = $dados['observacao'] ?? null;
        $notificacao_24h = $dados['notificar_24h'] ?? 1;
        $notificacao_1h = $dados['notificar_1h'] ?? 1;
        
        $stmt->bind_param(
            "iissssiii",
            $cliente_id,
            $servico_id,
            $data,
            $horario_inicio,
            $horario_fim,
            $status,
            $observacao,
            $notificacao_24h,
            $notificacao_1h
        );
        
        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $stmt->close();
            return $id;
        }
        
        $stmt->close();
        return false;
    }
    
    /**
     * Método: obter()
     * Responsabilidade: Obter dados completos de um agendamento
     * Parâmetros: $id (int)
     * Retorna: array com dados do agendamento ou false
     * 
     * Uso:
     *   $agendamento = $agendamento_obj->obter(123);
     */
    public function obter($id) {
        if (empty($id)) {
            return false;
        }
        
        $sql = "SELECT * FROM {$this->tabela} WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $resultado = $stmt->get_result();
        $linha = $resultado->fetch_assoc();
        $stmt->close();
        
        return $linha;
    }
    
    /**
     * Método: listar()
     * Responsabilidade: Listar agendamentos com filtros
     * Parâmetros:
     *   $filtro - array com filtros
     *   $limite - int com registros por página
     *   $pagina - int com número da página
     * Retorna: array com agendamentos
     * 
     * Filtros:
     *   - cliente_id: filtrar por cliente
     *   - status: status do agendamento
     */
    public function listar($filtro = [], $limite = 50, $pagina = 1) {
        $where = "WHERE 1=1";
        $params = [];
        $tipos = "";
        
        if (!empty($filtro['cliente_id'])) {
            $where .= " AND cliente_id = ?";
            $params[] = $filtro['cliente_id'];
            $tipos .= "i";
        }
        
        if (!empty($filtro['status'])) {
            $where .= " AND status = ?";
            $params[] = $filtro['status'];
            $tipos .= "s";
        }
        
        $offset = ($pagina - 1) * $limite;
        
        $sql = "SELECT * FROM {$this->tabela} {$where} ORDER BY data_agendamento ASC, horario_inicio ASC LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($params)) {
            $params[] = $limite;
            $params[] = $offset;
            $tipos .= "ii";
            
            $stmt->bind_param($tipos, ...$params);
        } else {
            $stmt->bind_param("ii", $limite, $offset);
        }
        
        $stmt->execute();
        $resultado = $stmt->get_result();
        $agendamentos = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $agendamentos[] = $linha;
        }
        
        $stmt->close();
        return $agendamentos;
    }
    
    /**
     * Método: obter_por_data()
     * Responsabilidade: Obter agendamentos de uma data específica
     * Parâmetros: $data (string 'Y-m-d')
     * Retorna: array com agendamentos
     * 
     * Uso:
     *   $agendamentos = $agendamento_obj->obter_por_data('2026-03-15');
     */
    public function obter_por_data($data) {
        if (empty($data)) {
            return [];
        }
        
        $sql = "SELECT * FROM {$this->tabela} WHERE data_agendamento = ? ORDER BY horario_inicio ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $data);
        $stmt->execute();
        
        $resultado = $stmt->get_result();
        $agendamentos = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $agendamentos[] = $linha;
        }
        
        $stmt->close();
        return $agendamentos;
    }
    
    /**
     * Método: obter_por_periodo()
     * Responsabilidade: Obter agendamentos de um per��odo
     * Parâmetros:
     *   $data_inicio - string 'Y-m-d'
     *   $data_fim - string 'Y-m-d'
     * Retorna: array com agendamentos
     * 
     * Uso:
     *   $agendamentos = $agendamento_obj->obter_por_periodo('2026-03-01', '2026-03-31');
     */
    public function obter_por_periodo($data_inicio, $data_fim) {
        if (empty($data_inicio) || empty($data_fim)) {
            return [];
        }
        
        $sql = "SELECT * FROM {$this->tabela} 
                WHERE data_agendamento BETWEEN ? AND ?
                ORDER BY data_agendamento ASC, horario_inicio ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ss", $data_inicio, $data_fim);
        $stmt->execute();
        
        $resultado = $stmt->get_result();
        $agendamentos = [];
        
        while ($linha = $resultado->fetch_assoc()) {
            $agendamentos[] = $linha;
        }
        
        $stmt->close();
        return $agendamentos;
    }
    
    /**
     * Método: obter_hoje()
     * Responsabilidade: Obter agendamentos de hoje
     * Parâmetros: none
     * Retorna: array com agendamentos
     * 
     * Uso:
     *   $hoje = $agendamento_obj->obter_hoje();
     */
    public function obter_hoje() {
        return $this->obter_por_data(date('Y-m-d'));
    }
    
    /**
     * Método: obter_semana()
     * Responsabilidade: Obter agendamentos da semana atual
     * Parâmetros: none
     * Retorna: array com agendamentos
     * 
     * Nota: Semana começa segunda-feira e termina domingo
     * 
     * Uso:
     *   $semana = $agendamento_obj->obter_semana();
     */
    public function obter_semana() {
        $hoje = new DateTime();
        
        // Se segunda
        $segunda = clone $hoje;
        if ($segunda->format('w') == 0) {
            $segunda->modify('-1 day');
        } elseif ($segunda->format('w') != 1) {
            $segunda->modify('Monday this week');
        }
        
        // Domingo
        $domingo = clone $segunda;
        $domingo->modify('+6 days');
        
        return $this->obter_por_periodo(
            $segunda->format('Y-m-d'),
            $domingo->format('Y-m-d')
        );
    }
    
    /**
     * Método: obter_proximos_dias()
     * Responsabilidade: Obter agendamentos dos próximos N dias
     * Parâmetros: $dias (padrão: 7)
     * Retorna: array com agendamentos
     * 
     * Uso:
     *   $proximos = $agendamento_obj->obter_proximos_dias(3);
     */
    public function obter_proximos_dias($dias = 7) {
        if ($dias <= 0) {
            $dias = 7;
        }
        
        $data_inicio = date('Y-m-d');
        $data_fim = date('Y-m-d', strtotime("+{$dias} days"));
        
        return $this->obter_por_periodo($data_inicio, $data_fim);
    }
    
    /**
     * Método: verificar_disponibilidade()
     * Responsabilidade: Verificar se horário está disponível
     * Parâmetros:
     *   $data - string 'Y-m-d'
     *   $horario - string 'H:i'
     *   $servico_id - int (para calcular duração)
     *   $agendamento_id - int (para edição)
     * Retorna: bool (true se disponível)
     * 
     * Uso:
     *   if ($agendamento_obj->verificar_disponibilidade('2026-03-15', '10:00', 10)) {
     *       // Horário disponível
     *   }
     */
    public function verificar_disponibilidade($data, $horario, $servico_id = null, $agendamento_id = null) {
        if (empty($data) || empty($horario)) {
            return false;
        }
        
        // Se informou serviço, busca tempo de execução
        $tempo_execucao = 60; // padrão em minutos
        if (!empty($servico_id)) {
            $sql = "SELECT tempo_execucao FROM servicos WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("i", $servico_id);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $servico = $resultado->fetch_assoc();
            $stmt->close();
            
            if ($servico) {
                $tempo_execucao = $servico['tempo_execucao'];
            }
        }
        
        // Calcula horário de término
        $horario_fim = date('H:i', strtotime($horario) + ($tempo_execucao * 60));
        
        // Busca agendamentos que conflitem nesta data
        $agendamentos = $this->obter_por_data($data);
        
        foreach ($agendamentos as $agend) {
            // Se está editando, ignora agendamento atual
            if (!empty($agendamento_id) && $agend['id'] == $agendamento_id) {
                continue;
            }
            
            // Verifica sobreposição de horários
            $inicio_existente = strtotime($agend['horario_inicio']);
            $fim_existente = strtotime($agend['horario_fim']);
            $inicio_novo = strtotime($horario);
            $fim_novo = strtotime($horario_fim);
            
            // Se houver sobreposição
            if ($inicio_novo < $fim_existente && $fim_novo > $inicio_existente) {
                return false; // Não disponível
            }
        }
        
        return true; // Disponível
    }
    
    /**
     * Método: obter_horarios_disponiveis()
     * Responsabilidade: Obter horários disponíveis para um serviço em uma data
     * Parâmetros:
     *   $data - string 'Y-m-d'
     *   $servico_id - int
     *   $intervalo - int (intervalo em minutos entre horários, padrão: 30)
     * Retorna: array com horários disponíveis
     * 
     * Nota: Considera horário comercial (08:00 até 17:00)
     * 
     * Uso:
     *   $horarios = $agendamento_obj->obter_horarios_disponiveis('2026-03-15', 10);
     */
    public function obter_horarios_disponiveis($data, $servico_id, $intervalo = 30) {
        if (empty($data) || empty($servico_id)) {
            return [];
        }
        
        // Horários comerciais
        $hora_inicio = 8; // 08:00
        $hora_fim = 17; // 17:00
        
        $sql = "SELECT tempo_execucao FROM servicos WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $servico_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $servico = $resultado->fetch_assoc();
        $stmt->close();
        
        if (!$servico) {
            return [];
        }
        
        $tempo_servico = $servico['tempo_execucao'];
        
        $horarios_disponiveis = [];
        
        // Percorre cada horário
        for ($hora = $hora_inicio; $hora < $hora_fim; $hora++) {
            for ($minuto = 0; $minuto < 60; $minuto += $intervalo) {
                $horario = sprintf('%02d:%02d', $hora, $minuto);
                
                // Verifica se está disponível
                if ($this->verificar_disponibilidade($data, $horario, $servico_id)) {
                    // Também verifica se o serviço cabe no horário
                    $horario_fim = date('H:i', strtotime($horario) + ($tempo_servico * 60));
                    $hora_fim_calc = intval(substr($horario_fim, 0, 2));
                    
                    if ($hora_fim_calc <= $hora_fim) {
                        $horarios_disponiveis[] = $horario;
                    }
                }
            }
        }
        
        return $horarios_disponiveis;
    }
    
    /**
     * Método: alterar_status()
     * Responsabilidade: Alterar status do agendamento
     * Parâmetros:
     *   $id - int
     *   $status - string
     * Retorna: bool
     * 
     * Uso:
     *   $agendamento_obj->alterar_status(123, 'confirmado');
     */
    public function alterar_status($id, $status) {
        if (empty($id)) {
            return false;
        }
        
        $status_validos = [
            'agendado',
            'confirmado',
            'em_execucao',
            'finalizado',
            'cancelado'
        ];
        
        if (!in_array($status, $status_validos)) {
            return false;
        }
        
        $sql = "UPDATE {$this->tabela} SET status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("si", $status, $id);
        
        $resultado = $stmt->execute();
        $stmt->close();
        
        return $resultado;
    }
    
    /**
     * Método: atualizar()
     * Responsabilidade: Atualizar dados de um agendamento
     * Parâmetros: $id, $dados
     * Retorna: bool
     * 
     * Nota: Permite atualizar data, horário e observações
     */
    public function atualizar($id, $dados) {
        if (empty($id) || empty($dados)) {
            return false;
        }
        
        // Campos permitidos
        $campos_permitidos = ['data_agendamento', 'horario_inicio', 'horario_fim', 'observacao', 'notificacao_24h', 'notificacao_1h'];
        
        $update_campos = [];
        $update_valores = [];
        $tipos = "";
        
        foreach ($dados as $campo => $valor) {
            if (in_array($campo, $campos_permitidos)) {
                $update_campos[] = "$campo = ?";
                $update_valores[] = $valor;
                
                if (is_int($valor)) {
                    $tipos .= "i";
                } else {
                    $tipos .= "s";
                }
            }
        }
        
        if (empty($update_campos)) {
            return false;
        }
        
        $tipos .= "i";
        $update_valores[] = $id;
        
        $sql = "UPDATE {$this->tabela} SET " . implode(", ", $update_campos) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($tipos, ...$update_valores);
        
        $resultado = $stmt->execute();
        $stmt->close();
        
        return $resultado;
    }
    
    /**
     * Método: deletar()
     * Responsabilidade: Cancelar um agendamento
     * Parâmetros: $id (int)
     * Retorna: bool
     */
    public function deletar($id) {
        if (empty($id)) {
            return false;
        }
        
        return $this->alterar_status($id, 'cancelado');
    }
    
    /**
     * Método: contar()
     * Responsabilidade: Contar total de agendamentos
     * Parâmetros: $filtro (array)
     * Retorna: int
     */
    public function contar($filtro = []) {
        $where = "WHERE 1=1";
        $params = [];
        $tipos = "";
        
        if (!empty($filtro['cliente_id'])) {
            $where .= " AND cliente_id = ?";
            $params[] = $filtro['cliente_id'];
            $tipos .= "i";
        }
        
        if (!empty($filtro['status'])) {
            $where .= " AND status = ?";
            $params[] = $filtro['status'];
            $tipos .= "s";
        }
        
        $sql = "SELECT COUNT(*) as total FROM {$this->tabela} {$where}";
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($tipos, ...$params);
        }
        
        $stmt->execute();
        $resultado = $stmt->get_result();
        $linha = $resultado->fetch_assoc();
        $stmt->close();
        
        return (int)$linha['total'];
    }
    
    /**
     * Método: obter_calendario_mes()
     * Responsabilidade: Obter calendário com agendamentos do mês
     * Parâmetros:
     *   $mes - int (1-12)
     *   $ano - int
     * Retorna: array com calendário
     * 
     * Uso:
     *   $calendario = $agendamento_obj->obter_calendario_mes(3, 2026);
     */
    public function obter_calendario_mes($mes, $ano) {
        if (empty($mes) || empty($ano) || $mes < 1 || $mes > 12) {
            return [];
        }
        
        // Data inicial e final do mês
        $data_inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $ultimo_dia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
        $data_fim = sprintf('%04d-%02d-%02d', $ano, $mes, $ultimo_dia);
        
        // Busca agendamentos
        $agendamentos = $this->obter_por_periodo($data_inicio, $data_fim);
        
        // Monta calendário
        $calendario = [];
        
        for ($dia = 1; $dia <= $ultimo_dia; $dia++) {
            $data = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            
            $calendario[$data] = [
                'dia' => $dia,
                'data' => $data,
                'agendamentos' => []
            ];
            
            // Busca agendamentos para este dia
            foreach ($agendamentos as $agend) {
                if ($agend['data_agendamento'] == $data) {
                    $calendario[$data]['agendamentos'][] = $agend;
                }
            }
        }
        
        return $calendario;
    }
}

?>
