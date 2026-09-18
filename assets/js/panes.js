/* C:\Dropbox\Programs\Claude\chai\assets\js\panes.js
   -> /var/www/chai/assets/js/panes.js

   面の並べ方 (ピン留め / タブ / 開く・閉じる)。
*/
import { renderConvList } from './convs.js';
import { app, panesEl, topbarTitle } from './dom.js';
import { tellParent } from './embed.js';
import { createPane } from './pane.js';
import { S } from './state.js';
import { $, el, toast } from './util.js';

export let panes = [];    // 現在のペイン群

export const convTitle = (id) => (S.convs.find((c) => c.id === id) || {}).title || (id ? '（読み込み中）' : '新しいチャット');

// ── ペイン描画（ピン=横並び / 狭い画面=タブ） ─────────────

// ── ペイン描画（ピン=横並び / 狭い画面=タブ） ─────────────
export function paneIds() {
  // 表示 = ピン群 ＋（未ピンなら）現在表示中の会話。現在表示は必ず見えるようにする
  let ids = S.pins.filter((x) => x > 0);
  if (!ids.includes(S.cur)) { if (ids.length >= 5) ids = ids.slice(0, 4); ids.push(S.cur); }
  return ids.slice(0, 5);
}

export function renderPanes() {
  // 生成中のペインは中断しない（表示を切り替えてもサーバ側で完了・保存される）
  panes.forEach((p) => { if (!p.streaming) p.stop && p.stop(); });
  panes = [];
  panesEl.innerHTML = '';
  const ids = paneIds();
  const multi = ids.length > 1;
  panesEl.classList.toggle('multi', multi);
  panesEl.style.setProperty('--n', ids.length);
  if (multi) {
    const tabs = el('div', 'pane-tabs');
    ids.forEach((id, i) => { const b = el('button', 'pane-tab'); b.textContent = id ? convTitle(id) : '新しいチャット'; b.onclick = () => setActive(i); tabs.appendChild(b); });
    panesEl.appendChild(tabs);
  }
  ids.forEach((id) => { const p = createPane(id); panes.push(p); panesEl.appendChild(p.el); p.load(); });
  if (S.activeTab >= ids.length) S.activeTab = 0;
  setActive(S.activeTab);
  topbarTitle.textContent = '🍵 chai';   // 上部は固定（会話タイトルはペイン側に表示）
  if (panes[S.activeTab]) panes[S.activeTab].focus();
  tellParent({ type: 'chai:conv', id: S.cur });   // 並べている親に「いまこの会話」と伝える
}

export function setActive(i) {
  S.activeTab = i;
  panes.forEach((p, idx) => p.el.classList.toggle('active', idx === i));
  [...panesEl.querySelectorAll('.pane-tab')].forEach((b, idx) => b.classList.toggle('active', idx === i));
}

// ── サイドバー ───────────────────────────────────────────

export function saveOpen() { localStorage.setItem('chai_pins', JSON.stringify(S.pins)); localStorage.setItem('chai_cur', String(S.cur)); }

export function syncActive(id) { const ids = paneIds(); S.activeTab = Math.max(0, ids.indexOf(id)); }
// 選択＝表示切り替え。ピン集合は変えない（未ピンでも現在表示として必ず見える）

// 選択＝表示切り替え。ピン集合は変えない（未ピンでも現在表示として必ず見える）
export function openChat(id) {
  S.cur = id;
  if (id) { try { history.replaceState(null, '', '#c=' + id); } catch (e) {} }
  syncActive(id); saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
}

export const viewChat = openChat;
// 📌＝明示ピンのオン/オフ（横に並べる集合の増減）

// 📌＝明示ピンのオン/オフ（横に並べる集合の増減）
export function togglePin(id) {
  if (!id) return;
  if (S.pins.includes(id)) {
    S.pins = S.pins.filter((x) => x !== id);
    if (S.cur === id) S.cur = S.pins.length ? S.pins[S.pins.length - 1] : 0;
  } else {
    if (S.pins.length >= 5) { alert('横に並べられるピンは最大5件です。'); return; }
    S.pins.push(id);
    S.cur = id;   // ピンしたチャットを表示（空の新規ペインが横に出ないように）
  }
  syncActive(S.cur); saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
}

export function newConv() {
  S.cur = 0; syncActive(0);
  saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
}
// ペイン列を閉じる（ピンなら外す。現在表示ならほかの表示へ移す）

// ペイン列を閉じる（ピンなら外す。現在表示ならほかの表示へ移す）
export function closeTab(id) {
  S.pins = S.pins.filter((x) => x !== id);
  if (S.cur === id) S.cur = S.pins.length ? S.pins[S.pins.length - 1] : 0;
  const ids = paneIds(); if (S.activeTab >= ids.length) S.activeTab = Math.max(0, ids.length - 1);
  saveOpen(); renderConvList(); renderPanes();
}

export const closeSidebarMobile = () => app.classList.remove('side-open');
// サイドバーを開いている時、外側（背景）タップで閉じる
app.addEventListener('click', (e) => {
  if (app.classList.contains('side-open') && !e.target.closest('#sidebar') && !e.target.closest('#btnMenu')) closeSidebarMobile();
});

// スマホ: 左端から右スワイプで開く / 左スワイプで閉じる（指に追従、Claude風）
(function edgeSwipe() {
  const sb = $('#sidebar'); if (!sb) return;
  let x0 = 0, y0 = 0, w = 264, drag = false, openStart = false, decided = false;
  const isMobile = () => window.matchMedia('(max-width:760px)').matches;
  document.addEventListener('touchstart', (e) => {
    if (!isMobile() || e.touches.length !== 1) { drag = false; return; }
    const t = e.touches[0]; x0 = t.clientX; y0 = t.clientY;
    openStart = app.classList.contains('side-open');
    drag = openStart ? true : (x0 <= 24);   // 閉時は左端24px以内から / 開時はどこでも
    decided = false;
  }, { passive: true });
  document.addEventListener('touchmove', (e) => {
    if (!drag) return;
    const t = e.touches[0]; const dx = t.clientX - x0, dy = t.clientY - y0;
    if (!decided) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
      if (Math.abs(dy) > Math.abs(dx)) { drag = false; return; }   // 縦方向は無視（スクロール優先）
      decided = true; w = sb.offsetWidth || 264;
    }
    e.preventDefault();
    let tx = openStart ? Math.min(0, dx) : Math.min(0, -w + Math.max(0, dx));
    tx = Math.max(-w, Math.min(0, tx));
    sb.style.transition = 'none'; sb.style.transform = 'translateX(' + tx + 'px)';
  }, { passive: false });
  document.addEventListener('touchend', () => {
    if (!drag) return; drag = false;
    if (!decided) return;   // ドラッグしていない（タップ）なら何もしない
    let tx = -w;
    try { tx = new DOMMatrix(getComputedStyle(sb).transform).m41; } catch (e) {}
    sb.style.transition = ''; sb.style.transform = '';
    app.classList.toggle('side-open', tx > -w * 0.5);   // 半分以上出ていれば開く
  }, { passive: true });
})();

// 表示のしかた切替（一気に / 徐々に）

// 表示のしかた切替（一気に / 徐々に）
export const speedBtn = $('#btnSpeed');

export const renderSpeedBtn = () => { if (speedBtn) { speedBtn.textContent = S.instant ? '⚡ 一気に' : '✍️ 徐々に'; speedBtn.title = S.instant ? '今: 一気に表示（押すと徐々に）' : '今: 徐々に表示（押すと一気に）'; } };
if (speedBtn) speedBtn.onclick = () => { S.instant = !S.instant; localStorage.setItem('chai_instant', S.instant ? '1' : '0'); renderSpeedBtn(); toast(S.instant ? '一気に表示にしました' : '徐々に表示にしました'); };
renderSpeedBtn();

// ── 起動 ─────────────────────────────────────────────────
