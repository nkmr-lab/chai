/* C:\Dropbox\Programs\Claude\chai\assets\js\convs.js
   -> /var/www/chai/assets/js/convs.js

   会話の一覧 (並び / 名前変更 / 削除)。
*/
import { api, post } from './api.js';
import { renderBookmarks, toggleBookmark } from './bookmarks.js';
import { convList, panesEl } from './dom.js';
import { convTitle, paneIds, panes, renderPanes, saveOpen, togglePin, viewChat } from './panes.js';
import { S } from './state.js';
import { renderTodos, todoOf, toggleTodo } from './todos.js';
import { el } from './util.js';

// ── サイドバー ───────────────────────────────────────────
export function convItem(c, shared) {
  const shown = isShown(c.id);
  const pinned = isPinned(c.id);
  const it = el('div', 'conv-item' + (shown ? ' active' : ''));
  const t = el('span', 't'); t.textContent = (shared ? '👥 ' : '') + (c.title || '無題');
  t.title = shared ? ('共有元: ' + (c.owner_email || '') + '（' + (c.perm === 'write' ? '書き込み可' : '閲覧のみ') + '）') : (c.title || '無題');
  const isTodo = !!todoOf(c.id);
  const td = el('span', 'td' + (isTodo ? ' on' : '')); td.textContent = '🗓';
  td.title = isTodo ? 'TODOから外す' : 'TODOに追加（〆切を設定）';
  td.onclick = (e) => { e.stopPropagation(); toggleTodo(c.id); };
  const marked = S.bookmarks.includes(c.id);
  const bm = el('span', 'bm' + (marked ? ' on' : '')); bm.textContent = marked ? '🔖' : '🏷';
  bm.title = marked ? 'あとで見るから外す' : 'あとで見るに追加';
  bm.onclick = (e) => { e.stopPropagation(); toggleBookmark(c.id); };
  const pin = el('span', 'pin' + (pinned ? ' on' : '')); pin.textContent = '📌';
  pin.title = pinned ? 'ピンを外す' : 'ピン留めして横に並べる';
  pin.onclick = (e) => { e.stopPropagation(); togglePin(c.id); };
  it.append(t, td, bm, pin);
  it.onclick = () => viewChat(c.id);   // 選択＝表示の切り替え（ピンは増やさない）
  return it;
}

export const isShown = (id) => paneIds().includes(id);

export const isPinned = (id) => S.pins.includes(id);

export function renderConvList() {
  convList.innerHTML = '';
  const owned = S.convs.filter((c) => +c.owned === 1);
  const shared = S.convs.filter((c) => +c.owned !== 1);
  const groups = new Map();
  for (const c of owned) { const k = c.category || '未分類'; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(c); }
  for (const [cat, items] of groups) { const h = el('div', 'conv-group'); h.textContent = cat; convList.appendChild(h); for (const c of items) convList.appendChild(convItem(c)); }
  if (shared.length) {
    const h = el('div', 'conv-group'); h.textContent = '👥 共有された会話'; convList.appendChild(h);
    for (const c of shared) convList.appendChild(convItem(c, true));
  }
}

export async function loadConvs() {
  const { conversations } = await api('conversations');
  const seen = new Set();
  S.convs = (conversations || []).filter((c) => !seen.has(c.id) && seen.add(c.id));   // 会話idの二重表示を防ぐ
  // 削除済みidをピン/現在表示から除去（0=新規は残す）
  const ids = new Set(S.convs.map((c) => c.id));
  S.pins = S.pins.filter((x) => ids.has(x));
  if (S.cur && !ids.has(S.cur)) S.cur = S.pins.length ? S.pins[0] : 0;
  renderConvList();
  renderBookmarks();   // タイトル最新化・削除済みを除外
  renderTodos();
  // ペインのタイトルを最新化（再描画はしない）
  panes.forEach((p) => { if (p.convId) p.setTitle(convTitle(p.convId)); });
  const pids = paneIds();
  [...panesEl.querySelectorAll('.pane-tab')].forEach((b, i) => { const id = pids[i]; b.textContent = id ? convTitle(id) : '新しいチャット'; });
}
// ── ブックマーク（あとで見る） ───────────────────────────

export async function renameConv(c) {
  const nn = prompt('チャット名を変更', c.title || ''); if (nn === null) return;
  const title = nn.trim(); if (!title) return;
  await post('conversation_rename', { id: c.id, title }); await loadConvs();
}

export async function delConv(id) {
  if (!confirm('この会話を削除しますか？')) return;
  await post('conversation_delete', { id });
  S.pins = S.pins.filter((x) => x !== id); if (S.cur === id) S.cur = S.pins.length ? S.pins[0] : 0;
  saveOpen(); await loadConvs(); renderPanes();
}

// ── ティア表示 / プラン ──────────────────────────────────
