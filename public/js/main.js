/**
 * =====================================================================
 * JAVASCRIPT PRINCIPAL
 * =====================================================================
 * 
 * Responsabilidade: Funcionalidades gerais do site
 * Inclui: Navegação, validação, mensagens, utilitários
 */

// ===== UTILITÁRIOS =====

/**
 * Função: showAlert
 * Responsabilidade: Exibir alerta ao usuário
 * Parâmetros: mensagem, tipo ('success', 'danger', 'warning', 'info')
 * Retorna: void
 */
function showAlert(mensagem, tipo = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo}`;
    alertDiv.textContent = mensagem;
    
    const container = document.querySelector('.container') || document.body;
    container.insertBefore(alertDiv, container.firstChild);
    
    // Remove após 5 segundos
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}

/**
 * Função: formatCurrency
 * Responsabilidade: Formatar número como moeda brasileira
 * Parâmetros: valor (number)
 * Retorna: string formatada (ex: "R$ 1.234,56")
 */
function formatCurrency(valor) {
    return 'R$ ' + valor.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Função: formatDate
 * Responsabilidade: Formatar data para formato brasileiro
 * Parâmetros: data (Date ou string 'YYYY-MM-DD')
 * Retorna: string formatada (ex: "14/02/2026")
 */
function formatDate(data) {
    if (typeof data === 'string') {
        data = new Date(data + 'T00:00:00');
    }
    
    return data.toLocaleDateString('pt-BR');
}

/**
 * Função: formatPhone
 * Responsabilidade: Formatar telefone
 * Parâmetros: telefone (string de números)
 * Retorna: string formatada (ex: "(11) 99999-9999")
 */
function formatPhone(telefone) {
    const cleaned = telefone.replace(/\D/g, '');
    
    if (cleaned.length === 11) {
        return `(${cleaned.substring(0, 2)}) ${cleaned.substring(2, 7)}-${cleaned.substring(7)}`;
    } else if (cleaned.length === 10) {
        return `(${cleaned.substring(0, 2)}) ${cleaned.substring(2, 6)}-${cleaned.substring(6)}`;
    }
    
    return telefone;
}

/**
 * Função: validateEmail
 * Responsabilidade: Validar email
 * Parâmetros: email (string)
 * Retorna: boolean
 */
function validateEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

/**
 * Função: validateCPF
 * Responsabilidade: Validar CPF
 * Parâmetros: cpf (string com ou sem máscara)
 * Retorna: boolean
 */
function validateCPF(cpf) {
    const cleaned = cpf.replace(/\D/g, '');
    
    if (cleaned.length !== 11) return false;
    
    // Verifica se todos os dígitos são iguais
    if (/(\d)\1{10}/.test(cleaned)) return false;
    
    // Calcula primeiro dígito
    let soma = 0;
    for (let i = 0; i < 9; i++) {
        soma += parseInt(cleaned[i]) * (10 - i);
    }
    let digito1 = 11 - (soma % 11);
    if (digito1 > 9) digito1 = 0;
    
    if (parseInt(cleaned[9]) !== digito1) return false;
    
    // Calcula segundo dígito
    soma = 0;
    for (let i = 0; i < 10; i++) {
        soma += parseInt(cleaned[i]) * (11 - i);
    }
    let digito2 = 11 - (soma % 11);
    if (digito2 > 9) digito2 = 0;
    
    if (parseInt(cleaned[10]) !== digito2) return false;
    
    return true;
}

/**
 * Função: validateCNPJ
 * Responsabilidade: Validar CNPJ
 * Parâmetros: cnpj (string com ou sem máscara)
 * Retorna: boolean
 */
function validateCNPJ(cnpj) {
    const cleaned = cnpj.replace(/\D/g, '');
    
    if (cleaned.length !== 14) return false;
    
    // Verifica se todos os dígitos são iguais
    if (/(\d)\1{13}/.test(cleaned)) return false;
    
    // Cálculo do primeiro dígito
    let tamanho = cleaned.length - 2;
    let numeros = cleaned.substring(0, tamanho);
    let digitos = cleaned.substring(tamanho);
    let soma = 0;
    let pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    let resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado !== parseInt(digitos.charAt(0))) return false;
    
    // Cálculo do segundo dígito (similar)
    tamanho = tamanho + 1;
    numeros = cleaned.substring(0, tamanho);
    soma = 0;
    pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado !== parseInt(digitos.charAt(1))) return false;
    
    return true;
}

/**
 * Função: maskInput
 * Responsabilidade: Aplicar máscara em input de formulário
 * Parâmetros:
 *   elemento (HTMLElement)
 *   tipo ('phone', 'date', 'currency', 'cpf', 'cnpj')
 * Retorna: void
 * 
 * Uso:
 *   maskInput(document.getElementById('phone'), 'phone');
 */
function maskInput(elemento, tipo) {
    elemento.addEventListener('input', function() {
        let valor = this.value.replace(/\D/g, '');
        
        switch(tipo) {
            case 'phone':
                if (valor.length > 11) valor = valor.slice(0, 11);
                if (valor.length > 2) {
                    valor = `(${valor.slice(0, 2)}) ${valor.slice(2)}`;
                }
                if (valor.length > 9) {
                    valor = `(${valor.slice(1, 3)}) ${valor.slice(5, 10)}-${valor.slice(10)}`;
                }
                break;
            
            case 'date':
                if (valor.length > 8) valor = valor.slice(0, 8);
                if (valor.length > 4) {
                    valor = `${valor.slice(0, 4)}-${valor.slice(4)}`;
                }
                if (valor.length > 7) {
                    valor = `${valor.slice(0, 7)}-${valor.slice(7)}`;
                }
                break;
            
            case 'cpf':
                if (valor.length > 11) valor = valor.slice(0, 11);
                if (valor.length > 8) {
                    valor = `${valor.slice(0, 3)}.${valor.slice(3, 6)}.${valor.slice(6, 9)}-${valor.slice(9)}`;
                } else if (valor.length > 5) {
                    valor = `${valor.slice(0, 3)}.${valor.slice(3, 6)}.${valor.slice(6)}`;
                } else if (valor.length > 2) {
                    valor = `${valor.slice(0, 3)}.${valor.slice(3)}`;
                }
                break;
            
            case 'cnpj':
                if (valor.length > 14) valor = valor.slice(0, 14);
                if (valor.length > 11) {
                    valor = `${valor.slice(0, 2)}.${valor.slice(2, 5)}.${valor.slice(5, 8)}/${valor.slice(8, 12)}-${valor.slice(12)}`;
                } else if (valor.length > 8) {
                    valor = `${valor.slice(0, 2)}.${valor.slice(2, 5)}.${valor.slice(5, 8)}/${valor.slice(8)}`;
                } else if (valor.length > 5) {
                    valor = `${valor.slice(0, 2)}.${valor.slice(2, 5)}.${valor.slice(5)}`;
                } else if (valor.length > 2) {
                    valor = `${valor.slice(0, 2)}.${valor.slice(2)}`;
                }
                break;
            
            case 'currency':
                valor = (valor / 100).toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });
                break;
        }
        
        this.value = valor;
    });
}

/**
 * Função: ajax
 * Responsabilidade: Fazer requisição AJAX
 * Parâmetros:
 *   url (string)
 *   opcoes (objeto com method, headers, body, etc)
 * Retorna: Promise
 * 
 * Uso:
 *   ajax('/api/clientes', {method: 'GET'})
 *       .then(data => console.log(data))
 *       .catch(error => console.error(error));
 */
function ajax(url, opcoes = {}) {
    const defaults = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    const config = { ...defaults, ...opcoes };
    
    return fetch(url, config)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        });
}

// ===== INICIALIZAÇÃO =====

document.addEventListener('DOMContentLoaded', function() {
    // Aplica máscaras em inputs se existirem
    const phoneInputs = document.querySelectorAll('[data-mask="phone"]');
    phoneInputs.forEach(input => maskInput(input, 'phone'));
    
    const cpfInputs = document.querySelectorAll('[data-mask="cpf"]');
    cpfInputs.forEach(input => maskInput(input, 'cpf'));
    
    const cnpjInputs = document.querySelectorAll('[data-mask="cnpj"]');
    cnpjInputs.forEach(input => maskInput(input, 'cnpj'));
    
    const dateInputs = document.querySelectorAll('[data-mask="date"]');
    dateInputs.forEach(input => maskInput(input, 'date'));
    
    console.log('✓ Sistema inicializado');
});
