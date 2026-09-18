/* C:\Dropbox\Programs\Claude\chai\assets\js\api.js
   -> /var/www/chai/assets/js/api.js

   サーバとのやりとり。
*/

export const api = async (action, opts = {}) => {
  const r = await fetch(`/api.php?action=${action}`, { credentials: 'same-origin', ...opts });
  if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || r.status);
  return r.json();
};

export const post = (action, body) => api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });

// ── Markdown ─────────────────────────────────────────────
