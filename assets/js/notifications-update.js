/**
 * Notifications Update Handler
 * Updates header counters when notifications/messages are marked as read
 */

(function() {
    'use strict';

    const baseUrl = document.getElementById('base_url')?.value || window.BASE_URL || '';
    
    /**
     * Update header counters
     */
    function updateHeaderCounters() {
        fetch(baseUrl + 'notifications/counts', {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(data) {
            // Update messages counter
            const messagesCounter = document.getElementById('header-messages-count');
            if (messagesCounter) {
                if (data.unread_messages_count > 0) {
                    messagesCounter.textContent = data.unread_messages_count;
                    messagesCounter.style.display = '';
                } else {
                    messagesCounter.style.display = 'none';
                }
            }

            // Update notifications counter
            const notificationsCounter = document.getElementById('header-notifications-count');
            if (notificationsCounter) {
                if (data.unread_notifications_count > 0) {
                    notificationsCounter.textContent = data.unread_notifications_count;
                    notificationsCounter.style.display = '';
                } else {
                    notificationsCounter.style.display = 'none';
                }
            }
        })
        .catch(function(error) {
            console.log('Error updating header counters:', error);
        });
    }

    /**
     * Handle mark as read form submission
     */
    function handleMarkAsReadForm(e) {
        e.preventDefault();
        
        const form = e.target;
        const formAction = form.getAttribute('action');
        const formData = new FormData(form);
        const csrfToken = document.getElementById('csrf_token')?.value || '';
        
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        fetch(formAction, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(data) {
            // Update header counters
            updateHeaderCounters();
            // Reload page to show updated state
            window.location.reload();
        })
        .catch(function(error) {
            console.log('Error marking as read:', error);
            // Fallback to normal form submission
            form.submit();
        });
    }

    /**
     * Handle mark all as read form submission
     */
    function handleMarkAllAsReadForm(e) {
        e.preventDefault();
        
        const form = e.target;
        const formAction = form.getAttribute('action');
        const formData = new FormData(form);
        const csrfToken = document.getElementById('csrf_token')?.value || '';
        
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        fetch(formAction, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(data) {
            // Update header counters
            updateHeaderCounters();
            // Reload page to show updated state
            window.location.reload();
        })
        .catch(function(error) {
            console.log('Error marking all as read:', error);
            // Fallback to normal form submission
            form.submit();
        });
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Intercept mark as read forms
        const markAsReadForms = document.querySelectorAll('form[action*="/mark-read"]');
        markAsReadForms.forEach(function(form) {
            form.addEventListener('submit', handleMarkAsReadForm);
        });

        // Intercept mark all as read forms
        const markAllAsReadForms = document.querySelectorAll('form[action*="/mark-all-read"]');
        markAllAsReadForms.forEach(function(form) {
            form.addEventListener('submit', handleMarkAllAsReadForm);
        });

        // Make updateHeaderCounters available globally for other scripts
        window.updateHeaderCounters = updateHeaderCounters;
    });
})();
