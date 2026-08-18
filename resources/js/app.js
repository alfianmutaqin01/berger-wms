/**
 * Berger WMS — Main JavaScript Entry Point
 * Bootstrap 5 + Laravel Echo + Notification Sound
 */

// Import Bootstrap JS (all components)
import * as bootstrap from 'bootstrap';

// Import Axios for AJAX
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// CSRF Token setup
const token = document.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.getAttribute('content');
}

/**
 * Laravel Echo — Real-time Notifications via WebSocket
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
    wsHost: import.meta.env.VITE_PUSHER_HOST ?? `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER}.pusher.com`,
    wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
    wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

/**
 * Notification Sound Player
 */
window.playNotificationSound = function () {
    try {
        const audio = new Audio('/sounds/notification.mp3');
        audio.volume = 0.5;
        audio.play().catch(() => {
            // Browser may block autoplay — silent fallback
        });
    } catch (e) {
        // Audio not supported — silent fallback
    }
};

/**
 * Toast Notification Helper
 */
window.showToast = function (title, message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const icons = {
        success: 'bi-check-circle-fill text-success',
        warning: 'bi-exclamation-triangle-fill text-warning',
        danger: 'bi-x-circle-fill text-danger',
        info: 'bi-bell-fill text-primary',
    };

    const toastHTML = `
        <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <i class="bi ${icons[type] || icons.info} me-2"></i>
                <strong class="me-auto">${title}</strong>
                <small>Baru saja</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">${message}</div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', toastHTML);

    // Auto-remove after 8 seconds
    const toastEl = container.lastElementChild;
    setTimeout(() => {
        toastEl.classList.remove('show');
        setTimeout(() => toastEl.remove(), 300);
    }, 8000);
};

/**
 * Notification Badge Counter
 */
window.updateNotificationBadge = function (count) {
    const badge = document.getElementById('notif-count');
    if (badge) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = count > 0 ? 'inline' : 'none';
    }
};

/**
 * Confirm Modal Helper
 */
window.confirmAction = function (message, callback) {
    const modal = document.getElementById('confirmModal');
    if (!modal) return callback();

    const body = modal.querySelector('.modal-body p');
    if (body) body.textContent = message;

    const bsModal = new bootstrap.Modal(modal);
    const confirmBtn = modal.querySelector('.btn-confirm');

    const handler = () => {
        confirmBtn.removeEventListener('click', handler);
        bsModal.hide();
        callback();
    };

    confirmBtn.addEventListener('click', handler);
    bsModal.show();
};

/**
 * Auto-logout on idle (client-side complement to server session timeout)
 */
let idleTimer;
function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
        window.location.href = '/logout';
    }, 60 * 60 * 1000); // 1 hour
}
['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(event => {
    document.addEventListener(event, resetIdleTimer, { passive: true });
});
resetIdleTimer();
