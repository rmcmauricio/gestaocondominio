/**
 * MVC Framework - Main JavaScript
 * Clean and modern interactions
 */

(function() {
  'use strict';

  /**
   * Mobile Navigation Toggle
   */
  function initMobileNav() {
    const mobileNavToggle = document.getElementById('mobileNavToggle');
    const navmenu = document.getElementById('navmenu');
    
    if (mobileNavToggle && navmenu) {
      mobileNavToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        navmenu.classList.toggle('active');
        
        const icon = this.querySelector('i');
        if (icon) {
          if (navmenu.classList.contains('active')) {
            icon.classList.remove('bi-list');
            icon.classList.add('bi-x-lg');
          } else {
            icon.classList.remove('bi-x-lg');
            icon.classList.add('bi-list');
          }
        }
      });
      
      // Close menu when clicking outside
      document.addEventListener('click', function(event) {
        if (!navmenu.contains(event.target) && !mobileNavToggle.contains(event.target)) {
          navmenu.classList.remove('active');
          const icon = mobileNavToggle.querySelector('i');
          if (icon) {
            icon.classList.remove('bi-x-lg');
            icon.classList.add('bi-list');
          }
        }
      });
      
      // Close menu when clicking a link
      const navLinks = navmenu.querySelectorAll('a');
      navLinks.forEach(link => {
        link.addEventListener('click', function() {
          navmenu.classList.remove('active');
          const icon = mobileNavToggle.querySelector('i');
          if (icon) {
            icon.classList.remove('bi-x-lg');
            icon.classList.add('bi-list');
          }
        });
      });
    }
  }

  /**
   * Header Scroll Effect
   */
  function initHeaderScroll() {
    const header = document.querySelector('.header');
    if (!header) return;
    
    window.addEventListener('scroll', function() {
      if (window.scrollY > 50) {
        header.style.boxShadow = 'var(--shadow-md)';
      } else {
        header.style.boxShadow = 'var(--shadow-sm)';
      }
    });
  }

  /**
   * Smooth Scroll for Anchor Links
   */
  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        const href = this.getAttribute('href');
        if (href === '#' || href === '') return;
        
        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          const headerHeight = document.querySelector('.header')?.offsetHeight || 64;
          const targetPosition = target.offsetTop - headerHeight;
          
          window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
          });
        }
      });
    });
  }

  /**
   * Form Validation Enhancement
   */
  function initFormValidation() {
    const forms = document.querySelectorAll('form[method="post"]');
    
    forms.forEach(form => {
      form.addEventListener('submit', function(e) {
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
          if (!field.value.trim()) {
            isValid = false;
            field.classList.add('is-invalid');
          } else {
            field.classList.remove('is-invalid');
          }
        });
        
        if (!isValid) {
          e.preventDefault();
          const firstInvalid = form.querySelector('.is-invalid');
          if (firstInvalid) {
            firstInvalid.focus();
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }
      });
      
      // Remove invalid class on input
      form.querySelectorAll('input, textarea, select').forEach(field => {
        field.addEventListener('input', function() {
          this.classList.remove('is-invalid');
        });
      });
    });
  }

  /**
   * Submit button: spinner + disable on form submit (evita duplo clique em POST)
   * Se o submit for cancelado (ex: validação), restaura o botão.
   */
  function initSubmitButtonSpinner() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
      const method = (form.getAttribute('method') || 'get').toLowerCase();
      if (method !== 'post') return;
      if (form.hasAttribute('data-no-submit-spinner')) return;

      // Capture: ao submeter, desativar botão e mostrar spinner
      form.addEventListener('submit', function(e) {
        const formId = this.id;
        let btn = null;
        if (document.activeElement && (document.activeElement.form === this ||
            (document.activeElement.getAttribute('form') === formId && document.activeElement.matches('button[type=submit], input[type=submit]')))) {
          btn = document.activeElement;
        }
        if (!btn) btn = this.querySelector('button[type=submit], input[type=submit]');
        if (btn && !btn.disabled) {
          btn.dataset.originalHtml = (btn.tagName === 'INPUT') ? btn.value : btn.innerHTML;
          btn.disabled = true;
          const label = (btn.textContent || btn.value || 'A processar...').trim();
          if (btn.tagName === 'INPUT') {
            btn.value = 'A processar...';
          } else {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ' + (label.length > 20 ? 'A processar...' : label);
          }
        }
      }, true);

      // Bubble: se o submit foi cancelado (ex: validação), repor botão
      form.addEventListener('submit', function(e) {
        if (e.defaultPrevented) {
          const formId = this.id;
          const toRestore = [];
          this.querySelectorAll('button[type=submit], input[type=submit]').forEach(function(b) {
            if (b.dataset.originalHtml !== undefined) toRestore.push(b);
          });
          if (formId) {
            document.querySelectorAll('button[type=submit][form="' + formId + '"], input[type=submit][form="' + formId + '"]').forEach(function(b) {
              if (b.dataset.originalHtml !== undefined) toRestore.push(b);
            });
          }
          toRestore.forEach(function(btn) {
            btn.disabled = false;
            if (btn.tagName === 'INPUT') {
              btn.value = btn.dataset.originalHtml;
            } else {
              btn.innerHTML = btn.dataset.originalHtml;
            }
            delete btn.dataset.originalHtml;
          });
        }
      }, false);
    });
  }

  /**
   * Auto-hide Alerts
   * Alerts will auto-hide after 90 seconds (90000ms)
   */
  function initAutoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
      // Skip alerts that are inside pending subscription cards or have data-no-auto-hide attribute
      if (alert.closest('.card.border-warning') || alert.hasAttribute('data-no-auto-hide')) {
        return;
      }
      
      if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
        setTimeout(() => {
          alert.style.transition = 'opacity 0.5s ease-out';
          alert.style.opacity = '0';
          setTimeout(() => {
            alert.remove();
          }, 500);
        }, 90000); // 90 seconds
      }
    });
  }

  /**
   * Initialize all functions when DOM is ready
   */

  /**
   * Initialize Demo Profile Dropdown
   * Bootstrap 5 handles dropdowns automatically with data-bs-toggle="dropdown"
   * This function ensures proper initialization and mobile-friendly behavior
   */
  function initDemoProfileDropdown() {
    const demoProfileDropdown = document.getElementById('demoProfileDropdown');
    if (demoProfileDropdown && typeof bootstrap !== 'undefined') {
      try {
        // Check if dropdown is already initialized
        let dropdownInstance = bootstrap.Dropdown.getInstance(demoProfileDropdown);
        if (!dropdownInstance) {
          // Initialize if not already done
          dropdownInstance = new bootstrap.Dropdown(demoProfileDropdown);
        }
        
        // Add mobile-friendly backdrop and click-outside handling
        const dropdownElement = demoProfileDropdown.closest('.dropdown');
        const dropdownMenu = dropdownElement?.querySelector('.dropdown-menu');
        
        if (dropdownElement && dropdownMenu) {
          // Close dropdown when clicking outside on mobile
          document.addEventListener('click', function(event) {
            const isClickInside = dropdownElement.contains(event.target);
            const isDropdownOpen = dropdownElement.classList.contains('show');
            
            if (!isClickInside && isDropdownOpen) {
              dropdownInstance.hide();
            }
          });
          
          // Prevent dropdown from closing when clicking inside the menu
          if (dropdownMenu) {
            dropdownMenu.addEventListener('click', function(event) {
              event.stopPropagation();
            });
          }
        }
      } catch (e) {
        // Bootstrap will handle it via data attributes
        console.debug('Bootstrap dropdown will be handled via data attributes');
      }
    }
  }

  /**
   * Initialize Quick Actions Dropdowns
   * Ensures all quick actions dropdowns work properly
   */
  function initQuickActionsDropdowns() {
    // Wait for Bootstrap to be available
    if (typeof bootstrap === 'undefined') {
      // Retry after a short delay
      setTimeout(initQuickActionsDropdowns, 100);
      return;
    }

    // Find all dropdown buttons with dropdown-toggle that are in dropdown containers
    const allDropdownButtons = document.querySelectorAll('.dropdown button[data-bs-toggle="dropdown"]');
    
    allDropdownButtons.forEach(button => {
      // Check if this is a quick actions dropdown by checking the text content
      const buttonText = button.textContent || button.innerText;
      if (buttonText.includes('Ações Rápidas') || button.id.startsWith('quickActionsDropdown')) {
        try {
          // Check if dropdown is already initialized
          let dropdownInstance = bootstrap.Dropdown.getInstance(button);
          if (!dropdownInstance) {
            // Initialize if not already done
            dropdownInstance = new bootstrap.Dropdown(button);
          }
          
          const dropdownElement = button.closest('.dropdown');
          const dropdownMenu = dropdownElement?.querySelector('.dropdown-menu');
          
          if (dropdownElement && dropdownMenu) {
            dropdownElement.style.position = 'relative';
            dropdownElement.style.overflow = 'visible';
            dropdownMenu.style.zIndex = '1050';
            
            // Garantir fecho ao clicar fora (Bootstrap pode falhar em alguns contextos)
            document.addEventListener('click', function handleCloseQuickActions(event) {
              if (!dropdownElement.contains(event.target) && dropdownElement.classList.contains('show')) {
                dropdownInstance.hide();
              }
            });
          }
        } catch (e) {
          console.debug('Quick actions dropdown initialization error:', e);
        }
      }
    });
  }

  function init() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        initMobileNav();
        initHeaderScroll();
        initSmoothScroll();
        initFormValidation();
        initSubmitButtonSpinner();
        initAutoHideAlerts();
        initDemoProfileDropdown();
        initQuickActionsDropdowns();
      });
    } else {
      initMobileNav();
      initHeaderScroll();
      initSmoothScroll();
      initFormValidation();
      initSubmitButtonSpinner();
      initAutoHideAlerts();
      initDemoProfileDropdown();
      initQuickActionsDropdowns();
    }
  }

  // Breakpoint aligned with CSS: sidebar hidden when <= 1320px
  var SIDEBAR_HIDDEN_BREAKPOINT = 1320;

  // Toggle sidebar when it is hidden (viewport <= SIDEBAR_HIDDEN_BREAKPOINT)
  function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    
    if (sidebar) {
      const isShowing = sidebar.classList.contains('show');
      sidebar.classList.toggle('show');
      // Overlay visibility: CSS .sidebar.show ~ .sidebar-overlay handles it
      
      // Update icon on menu button
      if (toggleBtn) {
        const icon = toggleBtn.querySelector('i');
        if (icon) {
          if (isShowing) {
            icon.classList.remove('bi-x-lg');
            icon.classList.add('bi-list');
          } else {
            icon.classList.remove('bi-list');
            icon.classList.add('bi-x-lg');
          }
        }
      }
      
      // Prevent body scroll when sidebar is open (sidebar hidden breakpoint)
      if (window.innerWidth <= SIDEBAR_HIDDEN_BREAKPOINT) {
        if (!isShowing) {
          document.body.style.overflow = 'hidden';
        } else {
          document.body.style.overflow = '';
        }
      }
    }
  }

  // Make toggleSidebar available globally
  window.toggleSidebar = toggleSidebar;

  // Close sidebar when clicking outside (at sidebar-hidden breakpoint)
  document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const isToggleButton = event.target.closest('#sidebarToggleBtn') ||
                          event.target.closest('[onclick*="toggleSidebar"]');
    
    if (sidebar && sidebar.classList.contains('show') && window.innerWidth <= SIDEBAR_HIDDEN_BREAKPOINT) {
      if (!sidebar.contains(event.target) && !isToggleButton && event.target !== overlay) {
        sidebar.classList.remove('show');
        document.body.style.overflow = '';
        
        if (toggleBtn) {
          const icon = toggleBtn.querySelector('i');
          if (icon) {
            icon.classList.remove('bi-x-lg');
            icon.classList.add('bi-list');
          }
        }
      }
    }
  });

  // Close sidebar when clicking a link inside it
  document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (sidebar && sidebar.classList.contains('show') && window.innerWidth <= SIDEBAR_HIDDEN_BREAKPOINT) {
      const link = event.target.closest('.sidebar-nav a');
      if (link) {
        setTimeout(function() {
          sidebar.classList.remove('show');
          document.body.style.overflow = '';
          
          if (toggleBtn) {
            const icon = toggleBtn.querySelector('i');
            if (icon) {
              icon.classList.remove('bi-x-lg');
              icon.classList.add('bi-list');
            }
          }
        }, 300);
      }
    }
  });

  // Handle sidebar submenu collapse/expand
  document.addEventListener('DOMContentLoaded', function() {
    // API submenu
    const apiSubmenu = document.getElementById('api-submenu');
    const apiSubmenuToggle = document.querySelector('[data-bs-target="#api-submenu"]');
    
    if (apiSubmenu && apiSubmenuToggle) {
      // Check if submenu is already expanded on page load
      if (apiSubmenu.classList.contains('show')) {
        apiSubmenuToggle.setAttribute('aria-expanded', 'true');
      }
      
      apiSubmenu.addEventListener('show.bs.collapse', function() {
        apiSubmenuToggle.setAttribute('aria-expanded', 'true');
      });
      
      apiSubmenu.addEventListener('hide.bs.collapse', function() {
        apiSubmenuToggle.setAttribute('aria-expanded', 'false');
      });
    }
    
    // Finances submenu
    const financesSubmenu = document.getElementById('finances-submenu');
    const financesSubmenuToggle = document.querySelector('[data-bs-target="#finances-submenu"]');
    
    if (financesSubmenu && financesSubmenuToggle) {
      // Check if submenu is already expanded on page load
      if (financesSubmenu.classList.contains('show')) {
        financesSubmenuToggle.setAttribute('aria-expanded', 'true');
      }
      
      financesSubmenu.addEventListener('show.bs.collapse', function() {
        financesSubmenuToggle.setAttribute('aria-expanded', 'true');
      });
      
      financesSubmenu.addEventListener('hide.bs.collapse', function() {
        financesSubmenuToggle.setAttribute('aria-expanded', 'false');
      });
    }
  });

  // Start initialization
  init();

})();
