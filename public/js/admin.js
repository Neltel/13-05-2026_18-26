/**
 * =====================================================================
 * JAVASCRIPT ADMIN
 * =====================================================================
 * 
 * Responsabilidade: Funcionalidades específicas do painel admin
 * Inclui: Navegação, modais, CRUD, listagens
 */

/**
 * Função: toggleSidebar
 * Responsabilidade: Alternar visibilidade do sidebar em mobile
 * Parâmetros: none
 * Retorna: void
 */
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('active');
    }
}

/**
 * Função: openModal
 * Responsabilidade: Abrir um modal
 * Parâmetros: modalId (string com ID do modal)
 * Retorna: void
 * 
 * Uso:
 *   openModal('modal-criar-cliente');
 */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

/**
 * Função: closeModal
 * Responsabilidade: Fechar um modal
 * Parâmetros: modalId (string)
 * Retorna: void
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

/**
 * Função: loadSection
 * Responsabilidade: Carregar seção do dashboard via AJAX
 * Parâmetros: sectionName (string)
 * Retorna: void
 * 
 * Uso:
 *   loadSection('clientes');
 */
function loadSection(sectionName) {
    const mainContent = document.querySelector('.main-content');
    
    if (!mainContent) return;
    
    // Mostra loading
    mainContent.innerHTML = '<div class="loading">Carregando...</div>';
    
    // Busca conteúdo da seção
    ajax(`/app/admin/${sectionName}.php`)
        .then(html => {
            mainContent.innerHTML = html;
            console.log(`✓ Seção ${sectionName} carregada`);
        })
        .catch(error => {
            mainContent.innerHTML = `<div class="alert alert-danger">Erro ao carregar: ${error.message}</div>`;
            console.error(`✗ Erro ao carregar ${sectionName}:`, error);
        });
}

/**
 * Função: deleteItem
 * Responsabilidade: Deletar um item com confirmação
 * Parâmetros:
 *   tipo (string: 'cliente', 'produto', etc)
 *   id (int)
 *   nome (string com nome do item para confirmação)
 * Retorna: void
 */
function deleteItem(tipo, id, nome) {
    if (!confirm(`Tem certeza que deseja deletar "${nome}"?`)) {
        return;
    }
    
    ajax(`/app/api/${tipo}.php?action=delete&id=${id}`, {
        method: 'DELETE'
    })
        .then(response => {
            if (response.success) {
                showAlert(`${tipo} deletado com sucesso!`, 'success');
                // Recarrega a lista
                location.reload();
            } else {
                showAlert(`Erro ao deletar ${tipo}: ${response.message}`, 'danger');
            }
        })
        .catch(error => {
            showAlert(`Erro: ${error.message}`, 'danger');
        });
}

/**
 * Função: editItem
 * Responsabilidade: Abrir formulário para editar item
 * Parâmetros:
 *   tipo (string)
 *   id (int)
 * Retorna: void
 */
function editItem(tipo, id) {
    ajax(`/app/api/${tipo}.php?action=get&id=${id}`)
        .then(response => {
            if (response.success) {
                // Preenche formulário com dados
                Object.keys(response.data).forEach(key => {
                    const input = document.querySelector(`[name="${key}"]`);
                    if (input) {
                        input.value = response.data[key];
                    }
                });
                
                // Abre modal
                openModal(`modal-${tipo}`);
            } else {
                showAlert('Erro ao carregar dados', 'danger');
            }
        });
}

/**
 * Função: submitForm
 * Responsabilidade: Enviar formulário via AJAX
 * Parâmetros:
 *   formId (string com ID do formulário)
 *   apiEndpoint (string com URL da API)
 *   modalId (string do modal a fechar)
 * Retorna: void
 * 
 * Uso:
 *   submitForm('form-cliente', '/app/api/clientes.php', 'modal-cliente');
 */
function submitForm(formId, apiEndpoint, modalId) {
    const form = document.getElementById(formId);
    
    if (!form) return;
    
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    ajax(apiEndpoint, {
        method: 'POST',
        body: JSON.stringify(data)
    })
        .then(response => {
            if (response.success) {
                showAlert(response.message, 'success');
                closeModal(modalId);
                // Recarrega a página
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert(`Erro: ${response.message}`, 'danger');
            }
        })
        .catch(error => {
            showAlert(`Erro: ${error.message}`, 'danger');
        });
}

/**
 * Função: exportToExcel
 * Responsabilidade: Exportar tabela para Excel
 * Parâmetros:
 *   tableId (string com ID da tabela)
 *   filename (string com nome do arquivo)
 * Retorna: void
 * 
 * Uso:
 *   exportToExcel('table-clientes', 'clientes.xlsx');
 */
function exportToExcel(tableId, filename) {
    const table = document.getElementById(tableId);
    
    if (!table) return;
    
    let html = '<table><thead>';
    
    // Headers
    const headers = table.querySelectorAll('thead th');
    html += '<tr>';
    headers.forEach(header => {
        html += `<th>${header.textContent}</th>`;
    });
    html += '</tr></thead><tbody>';
    
    // Dados
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        html += '<tr>';
        row.querySelectorAll('td').forEach(td => {
            html += `<td>${td.textContent}</td>`;
        });
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    
    // Cria arquivo
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
}

// ===== EVENT LISTENERS =====

document.addEventListener('DOMContentLoaded', function() {
    // Navegação do sidebar
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove classe active
            navItems.forEach(i => i.classList.remove('active'));
            
            // Adiciona classe active ao clicado
            this.classList.add('active');
            
            // Carrega seção
            const section = this.dataset.section;
            if (section) {
                loadSection(section);
            }
        });
    });
    
    // Fechar modal ao clicar no X
    const modalCloses = document.querySelectorAll('.modal-close');
    modalCloses.forEach(closeBtn => {
        closeBtn.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                closeModal(modal.id);
            }
        });
    });
    
    // Fechar modal ao clicar fora
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    
    console.log('✓ Admin iniciado');
});
