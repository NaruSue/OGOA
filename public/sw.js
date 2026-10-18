const APP_VERSION = new URL(self.location.href).searchParams.get('v') || '0.0.1';
const SHELL_CACHE = `1g1a-app-${APP_VERSION}`;
const QR_CACHE = '1g1a-offline-qrs-v1';
const APP_SHELL = [
  `/assets/app.css?v=${APP_VERSION}`,
  `/assets/app.js?v=${APP_VERSION}`,
  '/assets/icon.svg',
  '/manifest.webmanifest'
];
const QR_PATH = /^\/share-events\/\d+\/qr$/;
const QR_EXPIRY_HEADER = 'X-1G1A-QR-Expires-At';
const QR_TITLE_HEADER = 'X-1G1A-QR-Title';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(APP_SHELL)).catch(() => undefined)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== SHELL_CACHE && key !== QR_CACHE).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

function isUnexpired(response) {
  const expiry = Number(response.headers.get(QR_EXPIRY_HEADER) || 0);
  return expiry === 0 || expiry * 1000 >= Date.now();
}

async function cacheQrPage(request, response) {
  if (!response.ok || new URL(response.url).pathname !== new URL(request.url).pathname) return;
  const html = await response.clone().text();
  const expiry = html.match(/<meta\s+name=["']1g1a-qr-expires-at["']\s+content=["'](\d+)["']/i)?.[1];
  const title = html.match(/<meta\s+name=["']1g1a-qr-title["']\s+content=["']([^"']*)["']/i)?.[1] || '共有QR';
  if (expiry === undefined) return;
  const headers = new Headers(response.headers);
  headers.delete('content-length');
  headers.delete('content-encoding');
  headers.set(QR_EXPIRY_HEADER, expiry);
  headers.set(QR_TITLE_HEADER, encodeURIComponent(title));
  await (await caches.open(QR_CACHE)).put(request, new Response(html, {
    status: response.status,
    statusText: response.statusText,
    headers
  }));
}

function escapeHtml(value) {
  return value.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}

async function offlineQrPage() {
  const cache = await caches.open(QR_CACHE);
  const keys = await cache.keys();
  const entries = [];
  for (const request of keys) {
    const response = await cache.match(request);
    if (!response || !isUnexpired(response)) {
      await cache.delete(request);
      continue;
    }
    let title = '共有QR';
    try { title = decodeURIComponent(response.headers.get(QR_TITLE_HEADER) || ''); } catch (_) {}
    entries.push({ url: new URL(request.url).pathname, title });
  }
  const rows = entries.length
    ? entries.map((entry) => `<li><a href="${escapeHtml(entry.url)}">${escapeHtml(entry.title)} のQRを表示</a></li>`).join('')
    : '<li>表示できる保存済みQRはありません。</li>';
  return new Response(`<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0f5b66"><title>オフラインのQR</title><body style="font:16px/1.7 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;color:#203033"><h1>オフライン</h1><p>QRの新規作成やプロフィール設定にはオンライン接続が必要です。期限内の保存済みQRを表示できます。</p><ul>${rows}</ul></body></html>`, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
  });
}

async function networkQrPage(request) {
  try {
    const response = await fetch(request);
    await cacheQrPage(request, response);
    return response;
  } catch (_) {
    const cache = await caches.open(QR_CACHE);
    const cached = await cache.match(request);
    if (cached && isUnexpired(cached)) return cached;
    if (cached) await cache.delete(request);
    return offlineQrPage();
  }
}

async function networkImage(request, clientId) {
  const cache = await caches.open(QR_CACHE);
  try {
    const response = await fetch(request);
    if (response.ok || response.type === 'opaque') await cache.put(request, response.clone());
    return response;
  } catch (_) {
    const client = clientId ? await self.clients.get(clientId) : null;
    if (!client || !QR_PATH.test(new URL(client.url).pathname)) return Response.error();
    const qrPage = await cache.match(client.url);
    if (!qrPage || !isUnexpired(qrPage)) return Response.error();
    return (await cache.match(request)) || Response.error();
  }
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);

  if (url.pathname.startsWith('/media/') || url.pathname.startsWith('/assets/default-avatar-') || url.hostname === 'api.qrserver.com' || url.hostname.endsWith('googleusercontent.com')) {
    event.respondWith(networkImage(request, event.clientId));
    return;
  }

  if (url.origin !== location.origin) return;
  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest') {
    event.respondWith(caches.match(request, { ignoreSearch: true }).then((cached) => cached || fetch(request)));
    return;
  }

  if (QR_PATH.test(url.pathname)) {
    event.respondWith(networkQrPage(request));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => offlineQrPage()));
  }
});
