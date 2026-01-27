/**
 * Dashboard Notifications Handler
 * Marks notifications as read when clicked
 */

/**
 * Dashboard Notifications Handler
 * Marks notifications as read when clicked
 */

document.addEventListener('DOMContentLoaded', function() {
    const notificationLinks = document.querySelectorAll('.notification-link-dashboard');
    const baseUrl = document.getElementById('base_url')?.value || window.BASE_URL || '';
    const csrfToken = document.getElementById('csrf_token')?.value || '';

    notificationLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const notificationId = this.getAttribute('data-notification-id');
            const isRead = this.getAttribute('data-is-read') === '1';

            // Only mark as read if not already read
            if (!isRead && notificationId) {
                // Prevent default navigation temporarily
                e.preventDefault();
                const targetUrl = this.getAttribute('href');

                // Mark as read via AJAX
                const formData = new URLSearchParams();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                fetch(baseUrl + 'notifications/' + notificationId + '/mark-read', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString(),
                    credentials: 'same-origin'
                }).then(function(response) {
                    return response.json();
                }).then(function(data) {
                    // Update header counters
                    if (typeof updateHeaderCounters === 'function') {
                        updateHeaderCounters();
                    } else {
                        // Fallback: fetch counts directly
                        fetch(baseUrl + 'notifications/counts', {
                            method: 'GET',
                            credentials: 'same-origin'
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(countData) {
                            // Update messages counter
                            const messagesCounter = document.getElementById('header-messages-count');
                            if (messagesCounter) {
                                if (countData.unread_messages_count > 0) {
                                    messagesCounter.textContent = countData.unread_messages_count;
                                    messagesCounter.style.display = '';
                                } else {
                                    messagesCounter.style.display = 'none';
                                }
                            }

                            // Update notifications counter
                            const notificationsCounter = document.getElementById('header-notifications-count');
                            if (notificationsCounter) {
                                if (countData.unread_notifications_count > 0) {
                                    notificationsCounter.textContent = countData.unread_notifications_count;
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
                    
                    // Update visual state immediately
                    const card = link.querySelector('.card');
                    if (card) {
                        // Remove border-left (unread indicator)
                        card.classList.remove('border-start', 'border-2');
                        // Remove bold from title
                        const title = card.querySelector('h6');
                        if (title) {
                            title.classList.remove('fw-bold');
                        }
                        // Remove unread icon
                        const unreadIcon = card.querySelector('.bi-envelope-open');
                        if (unreadIcon) {
                            unreadIcon.remove();
                        }
                        // Update data attribute
                        link.setAttribute('data-is-read', '1');
                    }
                    // Navigate to the link
                    window.location.href = targetUrl;
                }).catch(function(error) {
                    // If error, still navigate to the link
                    console.log('Error marking notification as read:', error);
                    window.location.href = targetUrl;
                });
            }
        });
    });
});
