/**
 * Service worker for the mobile PWA (scope: /m/).
 * - Cache-first for static assets (CSS, JS, images, fonts).
 * - Network-first for document/navigation requests.
 */
(function () {
    'use strict';

    var CACHE_STATIC = 'predio-mobile-static-v1';
    var CACHE_PAGES = 'predio-mobile-pages-v1';

    function baseUrl() {
        if (self.registration && self.registration.scope) {
            return self.registration.scope.replace(/\/m\/?$/, '/');
        }
        return self.location.origin + self.location.pathname.replace(/\/?sw-mobile\.js.*$/, '/');
    }

    function isStaticAsset(url) {
        var u = url.toLowerCase();
        return /\.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)(\?|$)/.test(u) ||
            u.indexOf('/assets/') !== -1 ||
            u.indexOf('cdn.jsdelivr.net') !== -1 ||
            u.indexOf('bootstrap-icons') !== -1;
    }

    self.addEventListener('install', function (event) {
        self.skipWaiting();
    });

    self.addEventListener('activate', function (event) {
        event.waitUntil(
            caches.keys().then(function (keys) {
                return Promise.all(keys.map(function (key) {
                    if (key !== CACHE_STATIC && key !== CACHE_PAGES) {
                        return caches.delete(key);
                    }
                }));
            }).then(function () { return self.clients.claim(); })
        );
    });

    /** True if the request URL is under the mobile scope (base + m/). */
    function isMobileScopeNavigation(requestUrl) {
        var base = baseUrl();
        var basePath = base.replace(self.location.origin, '').replace(/\/?$/, '') || '';
        var path = requestUrl.replace(self.location.origin, '').split('?')[0];
        if (basePath && path.indexOf(basePath) !== 0) return false;
        var afterBase = path.slice(basePath.length).replace(/^\//, '');
        return afterBase === 'm' || afterBase.indexOf('m/') === 0;
    }

    self.addEventListener('fetch', function (event) {
        var url = event.request.url;
        if (event.request.mode === 'navigate') {
            // Do NOT intercept navigations outside /m/ (e.g. condominiums/.../occurrences/create).
            // Let the browser handle them so session cookie is sent and user stays logged in.
            if (!isMobileScopeNavigation(url)) {
                return;
            }
            event.respondWith(
                fetch(event.request, { credentials: 'same-origin' })
                    .then(function (response) {
                        var clone = response.clone();
                        if (response.status === 200 && response.type === 'basic') {
                            caches.open(CACHE_PAGES).then(function (cache) {
                                cache.put(event.request, clone);
                            });
                        }
                        return response;
                    })
                    .catch(function () {
                        return caches.match(event.request).then(function (cached) {
                            return cached || caches.match(baseUrl() + 'm/dashboard');
                        });
                    })
            );
            return;
        }
        if (isStaticAsset(url)) {
            event.respondWith(
                caches.match(event.request).then(function (cached) {
                    if (cached) return cached;
                    return fetch(event.request).then(function (response) {
                        if (response.status === 200 && response.type === 'basic') {
                            var r = response.clone();
                            caches.open(CACHE_STATIC).then(function (cache) {
                                cache.put(event.request, r);
                            });
                        }
                        return response;
                    });
                })
            );
        }
    });
})();
