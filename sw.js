// chai service worker — インストール可能化のための最小構成。
// 資産は一切キャッシュしない（＝「直したのに変わらない」を避ける）。
// fetch はネットワークにそのまま通す（respondWith しない＝ブラウザ既定）。
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => { /* network passthrough, no caching */ });
