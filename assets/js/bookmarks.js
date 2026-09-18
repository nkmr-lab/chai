/* C:\Dropbox\Programs\Claude\chai\assets\js\bookmarks.js
   -> /var/www/chai/assets/js/bookmarks.js

   あとで見る。
*/
import { api, post } from './api.js';
import { renderConvList } from './convs.js';
import { convTitle, viewChat } from './panes.js';
import { S } from './state.js';
import { $, el } from './util.js';

// ── ブックマーク（あとで見る） ───────────────────────────
export async function loadBookmarks() { try { const { ids } = await api('bookmarks'); S.bookmarks = ids || []; renderBookmarks(); } catch (e) {} }

export function renderBookmarks() {
  const box = $('#bookmarks'); if (!box) return; box.innerHTML = '';
  const items = S.bookmarks.filter((id) => S.convs.some((c) => c.id === id));
  if (!items.length) return;
  const head = el('div', 'bm-head'); head.textContent = '🔖 あとで見る'; box.appendChild(head);
  for (const id of items) {
    const it = el('div', 'bm-item');
    const t = el('span', 'bm-name'); t.textContent = convTitle(id); t.title = convTitle(id);
    const x = el('span', 'bm-x'); x.textContent = '×'; x.title = 'あとで見るから外す';
    x.onclick = (e) => { e.stopPropagation(); toggleBookmark(id); };
    it.append(t, x); it.onclick = () => viewChat(id);
    box.appendChild(it);
  }
}

export async function toggleBookmark(id) {
  if (!id) return;
  const on = S.bookmarks.includes(id);
  S.bookmarks = on ? S.bookmarks.filter((x) => x !== id) : [id, ...S.bookmarks];   // 即時反映
  renderBookmarks(); renderConvList();
  try { await post(on ? 'bookmark_remove' : 'bookmark_add', { conversation_id: id }); }
  catch (e) { loadBookmarks(); }
}
// ── TODO（〆切付き・完了チェック） ───────────────────────
