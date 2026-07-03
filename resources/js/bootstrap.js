import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// Use the XSRF-TOKEN cookie (axios sends X-XSRF-TOKEN automatically for same-origin requests).
// Avoid setting X-CSRF-TOKEN from the meta tag globally — a stale value prevents Laravel
// from falling back to the cookie and causes 419 Page Expired on POST.
