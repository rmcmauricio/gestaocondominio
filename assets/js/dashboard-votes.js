/**
 * Dashboard Votes JavaScript
 * Handles inline voting from the dashboard
 */

(function() {
    'use strict';

    // Ensure BASE_URL is available
    if (typeof window.BASE_URL === 'undefined') {
        window.BASE_URL = '/';
    }

    /**
     * Submit vote from dashboard
     */
    function submitDashboardVote(voteId, condominiumId, optionId, optionLabel) {
        const button = document.querySelector(`[data-vote-id="${voteId}"][data-option-id="${optionId}"]`);
        if (!button) {
            return;
        }

        // Disable all buttons for this vote
        const allButtons = document.querySelectorAll(`[data-vote-id="${voteId}"]`);
        allButtons.forEach(btn => {
            btn.disabled = true;
        });

        // Show loading state
        const originalText = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> A registar...';

        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                         document.querySelector('#csrf_token')?.value ||
                         document.querySelector('input[name="csrf_token"]')?.value || '';

        // Prepare form data
        const formData = new FormData();
        formData.append('vote_option_id', optionId);
        formData.append('csrf_token', csrfToken);

        // Submit vote via AJAX
        fetch(`${window.BASE_URL}condominiums/${condominiumId}/votes/${voteId}/vote`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Erro: ' + data.error);
                // Re-enable buttons
                allButtons.forEach(btn => {
                    btn.disabled = false;
                });
                button.innerHTML = originalText;
                return;
            }

            if (data.success) {
                // Update UI to show vote was registered
                const voteCard = button.closest('.card');
                if (voteCard) {
                    const cardBody = voteCard.querySelector('.card-body');
                    if (cardBody) {
                        cardBody.innerHTML = `
                            <h6 class="card-title">${voteCard.querySelector('h6').textContent}</h6>
                            <div class="alert alert-success alert-sm py-2 mb-2">
                                <i class="bi bi-check-circle"></i> 
                                <small>Votou em: <strong>${optionLabel}</strong></small>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-people"></i> ${data.results ? data.results.reduce((sum, r) => sum + parseInt(r.vote_count || 0), 0) : 0} voto${data.results ? (data.results.reduce((sum, r) => sum + parseInt(r.vote_count || 0), 0) != 1 ? 's' : '') : ''} registado${data.results ? (data.results.reduce((sum, r) => sum + parseInt(r.vote_count || 0), 0) != 1 ? 's' : '') : ''}
                            </small>
                        `;
                    }
                }

                // Show success message
                showNotification('Voto registado com sucesso!', 'success');
            }
        })
        .catch(error => {
            console.error('Erro ao registar voto:', error);
            alert('Erro ao registar voto. Por favor, tente novamente.');
            // Re-enable buttons
            allButtons.forEach(btn => {
                btn.disabled = false;
            });
            button.innerHTML = originalText;
        });
    }

    /**
     * Show notification
     */
    function showNotification(message, type) {
        // Try to use Bootstrap alert if available
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
        alertDiv.setAttribute('role', 'alert');
        // Garantir fundo opaco para melhor legibilidade
        if (type === 'success') {
            alertDiv.style.backgroundColor = '#d1e7dd';
            alertDiv.style.color = '#0f5132';
            alertDiv.style.borderColor = '#badbcc';
        } else {
            alertDiv.style.backgroundColor = '#f8d7da';
            alertDiv.style.color = '#842029';
            alertDiv.style.borderColor = '#f5c2c7';
        }
        alertDiv.style.opacity = '1';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        // Insert at the top of the main content
        const mainContent = document.querySelector('.main-content') || document.body;
        mainContent.insertBefore(alertDiv, mainContent.firstChild);

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        // Attach click handlers to vote buttons
        const voteButtons = document.querySelectorAll('.vote-btn-dashboard');
        voteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const voteId = this.dataset.voteId;
                const condominiumId = this.dataset.condominiumId;
                const optionId = this.dataset.optionId;
                const optionLabel = this.dataset.optionLabel;

                if (!voteId || !condominiumId || !optionId) {
                    console.error('Missing vote data');
                    return;
                }

                // Confirm vote
                if (confirm(`Tem certeza que deseja votar em "${optionLabel}"?`)) {
                    submitDashboardVote(voteId, condominiumId, optionId, optionLabel);
                }
            });
        });
    });
})();
