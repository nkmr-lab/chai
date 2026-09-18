/* C:\Dropbox\Programs\Claude\chai\assets\js\stocks.js
   -> /var/www/chai/assets/js/stocks.js

   ストック (切り抜き保存) と、 選択して保存するボタン。
*/
import { api, post } from './api.js';
import { openChat } from './panes.js';
import { $, el, toast } from './util.js';

// 本文を選択したら「⭐ ストックへ」ポップアップ
export let selBtn = null;

export const stockModal = $('#stockModal');

export async function saveStock(text, convId, mid) {
  try { await post('stock_save', { text, conversation_id: convId || null, source_msg_id: mid || null }); toast('⭐ ストックに保存しました'); return true; }
  catch (e) { toast('保存に失敗しました'); return false; }
}

export async function openStock() { await loadStock(); stockModal.hidden = false; }

export async function loadStock() { try { const { stocks } = await api('stocks'); renderStock(stocks || []); } catch (e) {} }

export function renderStock(items) {
  const box = $('#stockBody');
  if (!items.length) { box.innerHTML = '<div class="stock-empty">まだストックがありません。<br>返信の「⭐ ストック」ボタン、または本文を選択して保存できます。</div>'; return; }
  box.innerHTML = '';
  const top = el('div', 'stock-top');
  top.appendChild(Object.assign(el('span', 'stock-count'), { textContent: items.length + '件' }));
  const clr = el('button', 'stock-clear'); clr.textContent = '🗑 すべて削除';
  clr.onclick = async () => { if (!confirm('ストックをすべて削除しますか？（元に戻せません）')) return; try { await post('stock_clear'); loadStock(); } catch (e) { alert('削除に失敗しました'); } };
  top.appendChild(clr); box.appendChild(top);
  for (const s of items) {
    const it = el('div', 'stock-item');
    const tx = el('div', 'stock-text'); tx.textContent = s.text;
    const bar = el('div', 'stock-bar');
    const when = el('span', 'stock-when'); when.textContent = String(s.created_at || '').slice(0, 16);
    const cp = el('button', 'stock-act'); cp.textContent = '📋'; cp.title = 'コピー';
    cp.onclick = () => { navigator.clipboard.writeText(s.text); cp.textContent = '✓'; setTimeout(() => (cp.textContent = '📋'), 1000); };
    bar.append(when, cp);
    if (s.conversation_id) { const go = el('button', 'stock-act'); go.textContent = '↗ 会話'; go.title = '元の会話を開く'; go.onclick = () => { stockModal.hidden = true; openChat(+s.conversation_id); }; bar.appendChild(go); }
    const del = el('button', 'stock-act del'); del.textContent = '🗑'; del.title = '削除';
    del.onclick = async () => { if (!confirm('このストックを削除しますか？')) return; try { await post('stock_delete', { id: s.id }); loadStock(); } catch (e) {} };
    bar.appendChild(del);
    it.append(tx, bar); box.appendChild(it);
  }
}
// 本文を選択したら「⭐ ストックへ」ポップアップ

export const removeSelBtn = () => { if (selBtn) { selBtn.remove(); selBtn = null; } };

document.addEventListener('mouseup', (e) => {
  if (selBtn && selBtn.contains(e.target)) return;
  setTimeout(() => {
    const sel = window.getSelection();
    const text = sel ? sel.toString().trim() : '';
    removeSelBtn();
    if (!text || text.length < 2) return;
    let node = sel.anchorNode; node = node && (node.nodeType === 1 ? node : node.parentElement);
    const bodyEl = node && node.closest ? node.closest('.msg .body') : null;
    if (!bodyEl) return;
    const r = sel.getRangeAt(0).getBoundingClientRect();
    selBtn = el('button', 'sel-stock'); selBtn.textContent = '⭐ ストックへ';
    selBtn.style.top = Math.max(4, r.top - 36) + 'px';
    selBtn.style.left = Math.max(4, r.left) + 'px';
    selBtn.onmousedown = (ev) => ev.preventDefault();
    selBtn.onclick = async () => {
      const paneEl = bodyEl.closest('.pane'); const cid = paneEl && paneEl.dataset.cid ? +paneEl.dataset.cid : null;
      const msgEl = bodyEl.closest('.msg'); const mid = msgEl && msgEl.dataset.mid ? +msgEl.dataset.mid : null;
      await saveStock(text, cid, mid); removeSelBtn(); const s2 = window.getSelection(); if (s2) s2.removeAllRanges();
    };
    document.body.appendChild(selBtn);
  }, 10);
});

document.addEventListener('scroll', removeSelBtn, true);

// ── 共有 ─────────────────────────────────────────────────
