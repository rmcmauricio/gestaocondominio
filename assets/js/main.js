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

  function init() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        initMobileNav();
        initHeaderScroll();
        initSmoothScroll();
        initFormValidation();
        initAutoHideAlerts();
        initLanguageDropdown();
      });
    } else {
      initMobileNav();
      initHeaderScroll();
      initSmoothScroll();
      initFormValidation();
      initAutoHideAlerts();
      initLanguageDropdown();
    }
  }

  // Start initialization
  init();

})();
