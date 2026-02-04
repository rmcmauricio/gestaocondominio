/**
 * Cookie Consent Management
 * Handles cookie consent banner and preferences
 */

(function() {
    'use strict';

    const COOKIE_NAME = 'cookie_consent';
    const COOKIE_EXPIRY_DAYS = 365;
    const COOKIE_VERSION = '1.0';

    /**
     * Get cookie value by name
     */
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }

    /**
     * Set cookie
     */
    function setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = `expires=${date.toUTCString()}`;
        document.cookie = `${name}=${value}; ${expires}; path=/; SameSite=Lax`;
    }

    /**
     * Get consent preferences
     */
    function getConsentPreferences() {
        const cookieValue = getCookie(COOKIE_NAME);
        if (!cookieValue) {
            return null;
        }

        try {
            return JSON.parse(decodeURIComponent(cookieValue));
        } catch (e) {
            console.error('Error parsing cookie consent:', e);
            return null;
        }
    }

    /**
     * Save consent preferences
     */
    function saveConsentPreferences(preferences) {
        const consentData = {
            version: COOKIE_VERSION,
            timestamp: new Date().toISOString(),
            essential: true, // Always true, cannot be disabled
            functional: preferences.functional || false,
            analytics: preferences.analytics || false
        };

        const cookieValue = encodeURIComponent(JSON.stringify(consentData));
        setCookie(COOKIE_NAME, cookieValue, COOKIE_EXPIRY_DAYS);

        // Send to server (optional, for logging/analytics)
        if (typeof window.BASE_URL !== 'undefined') {
            fetch(`${window.BASE_URL}api/cookie-consent`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(consentData)
            }).catch(err => {
                console.error('Error saving consent to server:', err);
            });
        }

        // Hide banner
        hideBanner();
        
        // Trigger custom event
        document.dispatchEvent(new CustomEvent('cookieConsentUpdated', {
            detail: consentData
        }));
    }

    /**
     * Check if consent has been given
     */
    function hasConsent() {
        const preferences = getConsentPreferences();
        return preferences !== null;
    }

    /**
     * Show cookie banner
     */
    function showBanner() {
        const banner = document.getElementById('cookieConsentBanner');
        if (banner) {
            banner.classList.add('show');
            document.body.style.paddingBottom = banner.offsetHeight + 'px';
        }
    }

    /**
     * Hide cookie banner
     */
    function hideBanner() {
        const banner = document.getElementById('cookieConsentBanner');
        if (banner) {
            banner.classList.remove('show');
            document.body.style.paddingBottom = '';
        }
    }

    /**
     * Show preferences modal
     */
    function showPreferencesModal() {
        const modalElement = document.getElementById('cookiePreferencesModal');
        if (!modalElement) {
            console.error('Cookie preferences modal not found');
            return;
        }

        // Get or create modal instance
        let modal = bootstrap.Modal.getInstance(modalElement);
        if (!modal) {
            modal = new bootstrap.Modal(modalElement);
        }

        const preferences = getConsentPreferences() || {
            essential: true,
            functional: false,
            analytics: false
        };

        // Set toggle states
        const essentialToggle = document.getElementById('cookieEssential');
        const functionalToggle = document.getElementById('cookieFunctional');
        const analyticsToggle = document.getElementById('cookieAnalytics');

        if (essentialToggle) {
            essentialToggle.checked = preferences.essential;
            essentialToggle.disabled = true; // Always required
        }

        if (functionalToggle) {
            functionalToggle.checked = preferences.functional;
        }

        if (analyticsToggle) {
            analyticsToggle.checked = preferences.analytics;
        }

        modal.show();
    }

    /**
     * Accept all cookies
     */
    function acceptAll() {
        saveConsentPreferences({
            functional: true,
            analytics: true
        });
    }

    /**
     * Reject all non-essential cookies
     */
    function rejectAll() {
        saveConsentPreferences({
            functional: false,
            analytics: false
        });
    }

    /**
     * Save preferences from modal
     */
    function savePreferences() {
        const preferences = {
            functional: document.getElementById('cookieFunctional').checked,
            analytics: document.getElementById('cookieAnalytics').checked
        };

        saveConsentPreferences(preferences);
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('cookiePreferencesModal'));
        if (modal) {
            modal.hide();
        }
    }

    /**
     * Initialize cookie consent system
     */
    function init() {
        // Check if consent has been given
        if (!hasConsent()) {
            // Show banner after a short delay
            setTimeout(() => {
                showBanner();
            }, 500);
        }

        // Bind event listeners
        const acceptAllBtn = document.getElementById('cookieAcceptAll');
        const rejectAllBtn = document.getElementById('cookieRejectAll');
        const customizeBtn = document.getElementById('cookieCustomize');
        const savePreferencesBtn = document.getElementById('cookieSavePreferences');
        const acceptSelectedBtn = document.getElementById('cookieAcceptSelected');

        if (acceptAllBtn) {
            acceptAllBtn.addEventListener('click', acceptAll);
        }

        if (rejectAllBtn) {
            rejectAllBtn.addEventListener('click', rejectAll);
        }

        if (customizeBtn) {
            customizeBtn.addEventListener('click', showPreferencesModal);
        }

        if (savePreferencesBtn) {
            savePreferencesBtn.addEventListener('click', savePreferences);
        }

        if (acceptSelectedBtn) {
            acceptSelectedBtn.addEventListener('click', savePreferences);
        }

        // Expose global function to open preferences modal
        window.openCookiePreferences = showPreferencesModal;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
