/**
 * Help System JavaScript
 * Functions for opening help modals and pages
 */

/**
 * Open help modal with content for a specific section
 * @param {string} section - The help section identifier
 */
function openHelpModal(section) {
    if (!section) {
        console.error('Help section is required');
        return;
    }

    const modal = new bootstrap.Modal(document.getElementById('helpModal'));
    const contentDiv = document.getElementById('helpModalContent');
    const titleSpan = document.getElementById('helpModalTitle');
    const fullPageLink = document.getElementById('helpModalFullPageLink');

    // Show loading state
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">A carregar...</span>
            </div>
            <p class="text-muted mt-3 mb-0">A carregar conteúdo de ajuda...</p>
        </div>
    `;
    
    // Set full page link
    fullPageLink.href = window.BASE_URL + 'help/' + section;
    
    // Show modal
    modal.show();

    // Load content via AJAX
    fetch(window.BASE_URL + 'help/' + section + '/modal')
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro ao carregar conteúdo');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                contentDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> ${data.error}
                    </div>
                `;
                return;
            }

            // Update title
            if (data.title) {
                titleSpan.textContent = data.title;
            }

            // Update content
            contentDiv.innerHTML = data.content;
        })
        .catch(error => {
            console.error('Erro ao carregar ajuda:', error);
            contentDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Erro ao carregar conteúdo de ajuda. Por favor, tente novamente ou 
                    <a href="${window.BASE_URL}help/${section}" target="_blank">abra a página completa</a>.
                </div>
            `;
        });
}

/**
 * Open help page in new tab
 * @param {string} section - The help section identifier
 */
function openHelpPage(section) {
    if (!section) {
        console.error('Help section is required');
        return;
    }
    window.open(window.BASE_URL + 'help/' + section, '_blank');
}

/**
 * Load help content into a target element
 * @param {string} section - The help section identifier
 * @param {HTMLElement} target - Target element to load content into
 */
function loadHelpContent(section, target) {
    if (!section || !target) {
        console.error('Section and target are required');
        return;
    }

    target.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">A carregar...</span>
            </div>
        </div>
    `;

    fetch(window.BASE_URL + 'help/' + section + '/modal')
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro ao carregar conteúdo');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                target.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            target.innerHTML = data.content;
        })
        .catch(error => {
            console.error('Erro ao carregar ajuda:', error);
            target.innerHTML = `
                <div class="alert alert-danger">
                    Erro ao carregar conteúdo de ajuda.
                </div>
            `;
        });
}

// Ensure BASE_URL is available
if (typeof window.BASE_URL === 'undefined') {
    window.BASE_URL = '/';
}
