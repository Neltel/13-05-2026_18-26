/**
 * =====================================================================
 * JAVASCRIPT CLIENTE
 * =====================================================================
 * 
 * Responsabilidade: Funcionalidades específicas da área do cliente
 * Inclui: Agendamentos, calculadora, perfil
 */

/**
 * Função: selecionarServico
 * Responsabilidade: Selecionar um serviço para agendamento
 * Parâmetros:
 *   servicoId (int)
 *   elemento (HTMLElement)
 * Retorna: void
 */
function selecionarServico(servicoId, elemento) {
    // Remove seleção anterior
    document.querySelectorAll('.servico-option').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Marca como selecionado
    elemento.classList.add('selected');
    
    // Atualiza campo oculto
    const input = document.querySelector('input[name="servico_id"]');
    if (input) {
        input.value = servicoId;
    }
    
    // Carrega horários disponíveis
    carregarHorarios(servicoId);
}

/**
 * Função: carregarHorarios
 * Responsabilidade: Carregar horários disponíveis para um serviço
 * Parâmetros: servicoId (int)
 * Retorna: void
 */
function carregarHorarios(servicoId) {
    const dataInput = document.querySelector('input[name="data_agendamento"]');
    
    if (!dataInput || !dataInput.value) {
        showAlert('Selecione uma data primeiro', 'warning');
        return;
    }
    
    const data = dataInput.value;
    
    ajax(`/app/api/agendamentos.php?action=horarios&servico_id=${servicoId}&data=${data}`)
        .then(response => {
            if (response.success) {
                exibirHorarios(response.horarios);
            } else {
                showAlert('Nenhum horário disponível para esta data', 'warning');
            }
        })
        .catch(error => {
            showAlert(`Erro: ${error.message}`, 'danger');
        });
}

/**
 * Função: exibirHorarios
 * Responsabilidade: Exibir lista de horários disponíveis
 * Parâmetros: horarios (array de strings 'HH:ii')
 * Retorna: void
 */
function exibirHorarios(horarios) {
    const container = document.querySelector('.horarios-grid');
    
    if (!container) return;
    
    container.innerHTML = '';
    
    horarios.forEach(horario => {
        const btn = document.createElement('div');
        btn.className = 'horario-option';
        btn.textContent = horario;
        btn.onclick = function() {
            // Remove seleção anterior
            document.querySelectorAll('.horario-option').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Marca como selecionado
            this.classList.add('selected');
            
            // Atualiza campo oculto
            const input = document.querySelector('input[name="horario_inicio"]');
            if (input) {
                input.value = horario;
            }
        };
        
        container.appendChild(btn);
    });
}

/**
 * Função: selecionarHorario
 * Responsabilidade: Selecionar um horário
 * Parâmetros:
 *   horario (string 'HH:ii')
 *   elemento (HTMLElement)
 * Retorna: void
 */
function selecionarHorario(horario, elemento) {
    // Remove seleção anterior
    document.querySelectorAll('.horario-option').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Marca como selecionado
    elemento.classList.add('selected');
    
    // Atualiza campo
    const input = document.querySelector('input[name="horario_inicio"]');
    if (input) {
        input.value = horario;
    }
}

/**
 * Função: calcularCargaTermica
 * Responsabilidade: Calcular carga térmica do ambiente
 * Parâmetros: none
 * Retorna: void
 */
function calcularCargaTermica() {
    // Obtém valores do formulário
    const comprimento = parseFloat(document.querySelector('input[name="comprimento"]')?.value) || 0;
    const largura = parseFloat(document.querySelector('input[name="largura"]')?.value) || 0;
    const altura = parseFloat(document.querySelector('input[name="altura"]')?.value) || 0;
    const pessoas = parseInt(document.querySelector('input[name="pessoas"]')?.value) || 0;
    const ambiente = document.querySelector('select[name="ambiente"]')?.value || 'normal';
    
    // Validação
    if (comprimento <= 0 || largura <= 0 || altura <= 0) {
        showAlert('Preencha corretamente as dimensões', 'warning');
        return;
    }
    
    // Calcula volume
    const volume = comprimento * largura * altura;
    
    // BTU base (25 BTU por metro cúbico)
    let btu = volume * 25;
    
    // Ajusta por número de pessoas
    btu += pessoas * 600; // 600 BTU por pessoa
    
    // Ajusta por tipo de ambiente
    const multiplicadores = {
        'muito_quente': 1.3,
        'quente': 1.2,
        'normal': 1.0,
        'frio': 0.8
    };
    
    btu *= multiplicadores[ambiente] || 1.0;
    
    // Arredonda para número comercial
    let btuComercial = Math.ceil(btu / 1000) * 1000;
    
    // Exibe resultado
    const resultDiv = document.querySelector('.calc-result');
    if (resultDiv) {
        resultDiv.innerHTML = `
            <h3>📊 Resultado do Cálculo</h3>
            <div class="calc-result-item">
                <span>BTU Necessário:</span>
                <strong>${btuComercial.toLocaleString('pt-BR')} BTU</strong>
            </div>
            <div class="calc-result-item">
                <span>BTU Exato:</span>
                <strong>${btu.toFixed(0).toLocaleString('pt-BR')} BTU</strong>
            </div>
            <div class="calc-result-item">
                <span>Volume do Ambiente:</span>
                <strong>${volume.toFixed(2)} m³</strong>
            </div>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                <strong>Recomendação:</strong> Um equipamento com ${btuComercial.toLocaleString('pt-BR')} BTU é adequado para suas necessidades.
            </p>
        `;
    }
}

// ===== EVENT LISTENERS =====

document.addEventListener('DOMContentLoaded', function() {
    // Calculadora de carga térmica
    const calcBtn = document.querySelector('button[onclick*="calcularCargaTermica"]');
    if (calcBtn) {
        calcBtn.addEventListener('click', calcularCargaTermica);
    }
    
    // Adiciona evento ao campo de data
    const dateInput = document.querySelector('input[name="data_agendamento"]');
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            const servicoInput = document.querySelector('input[name="servico_id"]');
            if (servicoInput && servicoInput.value) {
                carregarHorarios(servicoInput.value);
            }
        });
    }
    
    console.log('✓ Cliente iniciado');
});
