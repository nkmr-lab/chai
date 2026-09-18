/* C:\Dropbox\Programs\Claude\chai\assets\js\app.js
   -> /var/www/chai/assets/js/app.js

   入口。 起動して、 ボタンをつなぐ。 index.php はこれ 1 本だけ読む。
*/
import { loadBookmarks } from './bookmarks.js';
import { loadConvs, renderConvList } from './convs.js';
import { app } from './dom.js';
import { EMBED, tellParent } from './embed.js';
import { fbModal, openFeedback } from './feedback.js';
import { newConv, panes, renderPanes, saveOpen } from './panes.js';
import { loadPinsets, pinsetModal } from './pinsets.js';
import { shareModal } from './share.js';
import { S } from './state.js';
import { openStock, stockModal } from './stocks.js';
import { openPlan, planModal, renderTier } from './tier.js';
import { loadTodos } from './todos.js';
import { $, toast } from './util.js';

// ── 配線 ─────────────────────────────────────────────────
$('#btnNew').onclick = newConv;

$('#btnStock').onclick = openStock;

$('#stockClose').onclick = () => (stockModal.hidden = true);
stockModal.onclick = (e) => { if (e.target === stockModal) stockModal.hidden = true; };

$('#shareClose').onclick = () => (shareModal.hidden = true);
shareModal.onclick = (e) => { if (e.target === shareModal) shareModal.hidden = true; };

$('#pinsetClose').onclick = () => (pinsetModal.hidden = true);
pinsetModal.onclick = (e) => { if (e.target === pinsetModal) pinsetModal.hidden = true; };

$('#btnFeedback').onclick = openFeedback;

$('#fbClose').onclick = () => (fbModal.hidden = true);
fbModal.onclick = (e) => { if (e.target === fbModal) fbModal.hidden = true; };

$('#btnPlan').onclick = () => openPlan('');

$('#planClose').onclick = () => (planModal.hidden = true);
planModal.onclick = (e) => { if (e.target === planModal) planModal.hidden = true; };

$('#btnMenu').onclick = (e) => { e.stopPropagation(); app.classList.toggle('side-open'); };

// ── 起動 ─────────────────────────────────────────────────
(async () => {
  renderTier();
  // ピンは復元するが、現在表示は「新規チャット」で開始（新しいタブは新規画面に）
  try { const s = JSON.parse(localStorage.getItem('chai_pins') || '[]'); if (Array.isArray(s)) S.pins = s.filter((x) => Number.isInteger(x) && x > 0); } catch (e) {}
  S.cur = 0;
  await loadConvs();   // S.convs 取得＆存在しないidを除去
  // URL で特定チャットが指定されていれば、それを表示（#c=<id>）
  const hm = location.hash.match(/(?:^#|[#&])c=(\d+)/);
  if (hm) {
    const cid = +hm[1];
    if (S.convs.some((c) => c.id === cid)) S.cur = cid;
    else toast('このチャットは開けません（共有されていない可能性があります）');
  }
  S.activeTab = 0; saveOpen();
  renderConvList();
  renderPanes();
  loadPinsets();
  loadBookmarks();
  loadTodos();
  app.setAttribute('aria-busy', 'false');

  if (EMBED) {
    // 上部バーは畳んである。 「＋新しいチャット」は親のヘッダから頼まれる。
    window.addEventListener('message', (ev) => {
      if (ev.origin !== 'https://chat.nkmr.io') return;
      const d = ev.data || {};
      if (d.type === 'chai:new')  $('#btnNew').click();
      if (d.type === 'chai:menu') app.classList.toggle('side-open');   // 会話一覧 / ピンを出し入れ
      if (d.type === 'chai:close-menu') app.classList.remove('side-open');
    });
    tellParent({ type: 'chai:conv', id: S.cur });
  }

  // ?q=… が付いていたら入力欄に入れておく (送信まではしない。 本人が直してから送る)
  const q = new URLSearchParams(location.search).get('q');
  if (q) {
    const ta = $('#panes textarea');
    if (ta) {
      ta.value = q;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.focus();
      try { ta.setSelectionRange(0, 0); ta.scrollTop = 0; } catch (e) {}
    }
  }
})();
