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
   * Auto-hide Alerts
   */
  function initAutoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
      if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
        setTimeout(() => {
          alert.style.transition = 'opacity 0.5s ease-out';
          alert.style.opacity = '0';
          setTimeout(() => {
            alert.remove();
          }, 500);
        }, 5000);
      }
    });
  }

  /**
   * Initialize all functions when DOM is ready
   */
  /**
   * Initialize Language Dropdown
   */
  function initLanguageDropdown() {
    const languageDropdown = document.getElementById('languageDropdown');
    const dropdown = languageDropdown?.closest('.dropdown');
    const dropdownMenu = dropdown?.querySelector('.dropdown-menu');
    
    if (languageDropdown && dropdown && dropdownMenu) {
      // Ensure dropdown is closed on page load - force hide
      dropdown.classList.remove('show');
      languageDropdown.setAttribute('aria-expanded', 'false');
      dropdownMenu.style.display = 'none';
      dropdownMenu.style.opacity = '0';
      dropdownMenu.style.visibility = 'hidden';
      
      // Toggle dropdown on button click
      languageDropdown.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const isExpanded = this.getAttribute('aria-expanded') === 'true';
        
        if (isExpanded) {
          dropdown.classList.remove('show');
          this.setAttribute('aria-expanded', 'false');
          dropdownMenu.style.display = 'none';
          dropdownMenu.style.opacity = '0';
          dropdownMenu.style.visibility = 'hidden';
        } else {
          dropdown.classList.add('show');
          this.setAttribute('aria-expanded', 'true');
          dropdownMenu.style.display = 'block';
          dropdownMenu.style.opacity = '1';
          dropdownMenu.style.visibility = 'visible';
        }
      });
      
      // Close dropdown when clicking outside
      document.addEventListener('click', function(event) {
        if (!dropdown.contains(event.target)) {
          dropdown.classList.remove('show');
          languageDropdown.setAttribute('aria-expanded', 'false');
          dropdownMenu.style.display = 'none';
          dropdownMenu.style.opacity = '0';
          dropdownMenu.style.visibility = 'hidden';
        }
      });
      
      // Close dropdown when clicking a language option
      const languageLinks = dropdownMenu.querySelectorAll('a');
      languageLinks.forEach(link => {
        link.addEventListener('click', function() {
          dropdown.classList.remove('show');
          languageDropdown.setAttribute('aria-expanded', 'false');
          dropdownMenu.style.display = 'none';
          dropdownMenu.style.opacity = '0';
          dropdownMenu.style.visibility = 'hidden';
        });
      });
    }
  }

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
          
          // Ensure dropdown menu has proper z-index and positioning
          const dropdownElement = button.closest('.dropdown');
          const dropdownMenu = dropdownElement?.querySelector('.dropdown-menu');
          
          if (dropdownMenu) {
            // Ensure parent container allows overflow
            if (dropdownElement) {
              dropdownElement.style.position = 'relative';
              dropdownElement.style.overflow = 'visible';
            }
            
            // Ensure menu is visible when dropdown is shown
            button.addEventListener('shown.bs.dropdown', function() {
              dropdownMenu.style.display = 'block';
              dropdownMenu.style.zIndex = '1050';
              dropdownMenu.style.position = 'absolute';
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
        initAutoHideAlerts();
        initLanguageDropdown();
        initDemoProfileDropdown();
        initQuickActionsDropdowns();
      });
    } else {
      initMobileNav();
      initHeaderScroll();
      initSmoothScroll();
      initFormValidation();
      initAutoHideAlerts();
      initLanguageDropdown();
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

  // Start initialization
  init();

})();
