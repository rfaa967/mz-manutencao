// Service worker mínimo: permite instalar como app (PWA) e mostra uma página
// de "sem conexão" quando a rede cai. Não guarda páginas autenticadas em cache.
const OFFLINE = 'offline.html';
self.addEventListener('install', e => {
  e.waitUntil(caches.open('mzmanut-v1').then(c => c.add(OFFLINE)));
  self.skipWaiting();
});
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => caches.match(OFFLINE)));
});
