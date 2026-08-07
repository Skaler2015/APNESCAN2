/* ApneScan Admin — service worker. Caches static CDN assets for fast repeat
   loads and offline resilience; live data always needs the network. */
const CACHE = 'apnescan-admin-v1';
const ASSETS = [
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
  'https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(ASSETS).catch(() => {})).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Cache-first for known static CDN assets (scripts + fonts).
  if (url.host.indexOf('jsdelivr.net') !== -1 || url.host.indexOf('fonts.g') !== -1) {
    e.respondWith(
      caches.match(req).then((r) => r || fetch(req).then((res) => {
        const cp = res.clone();
        caches.open(CACHE).then((c) => c.put(req, cp));
        return res;
      }).catch(() => r))
    );
    return;
  }

  // Network-first for navigations, with a minimal offline fallback.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(() => new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<div style="font-family:system-ui,sans-serif;max-width:420px;margin:16vh auto;text-align:center;color:#333">' +
        '<div style="font-size:44px">📡</div><h1 style="margin:8px 0">You\'re offline</h1>' +
        '<p style="color:#777">ApneScan Admin needs a connection for live analytics. Reconnect and try again.</p></div>',
        { headers: { 'Content-Type': 'text/html' } }
      ))
    );
  }
});
