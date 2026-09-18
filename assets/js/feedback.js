/* C:\Dropbox\Programs\Claude\chai\assets\js\feedback.js
   -> /var/www/chai/assets/js/feedback.js

   要望・不具合。
*/
import { api, post } from './api.js';
import { $, el, toast } from './util.js';

// ── 要望・不具合 ─────────────────────────────────────────
export const fbModal = $('#fbModal');

export const FB_STATUS = { new: '受付', planned: '予定', doing: '対応中', done: '対応済', declined: '見送り' };

export async function openFeedback() { await loadFeedback(); fbModal.hidden = false; }

export async function loadFeedback() { try { const r = await api('feedback_list'); renderFeedback(r.feedback || [], !!r.is_admin); } catch (e) { renderFeedback([], false); } }

export function renderFeedback(items, isAdmin) {
  $('#fbTitle').textContent = isAdmin ? '💬 要望・不具合（管理）' : '💬 要望・不具合';
  const box = $('#fbBody');
  box.innerHTML = `
    <div class="fb-form">
      <select id="fbKind"><option value="request">要望</option><option value="bug">不具合</option><option value="other">その他</option></select>
      <textarea id="fbText" rows="3" placeholder="改善要望や不具合を書いてください"></textarea>
      <button id="fbSend">送信</button>
    </div>
    <div class="fb-hint">送っていただいた内容は中村が確認します（対応するかは中村の判断です）。</div>
    <div class="fb-list" id="fbList"></div>`;
  $('#fbSend').onclick = submitFeedback;
  const list = $('#fbList');
  if (!items.length) { list.innerHTML = '<div class="fb-empty">まだありません。</div>'; return; }
  for (const f of items) {
    const it = el('div', 'fb-item');
    const head = el('div', 'fb-head');
    const kind = el('span', 'fb-kind'); kind.textContent = f.kind === 'bug' ? '🐛 不具合' : f.kind === 'other' ? 'その他' : '要望';
    const st = el('span', 'fb-status s-' + f.status); st.textContent = FB_STATUS[f.status] || f.status;
    head.append(kind, st);
    if (isAdmin && f.name) { const who = el('span', 'fb-who'); who.textContent = f.name; head.appendChild(who); }
    const tx = el('div', 'fb-text'); tx.textContent = f.text;
    it.append(head, tx);
    if (f.admin_note) { const an = el('div', 'fb-note'); an.textContent = '↳ ' + f.admin_note; it.appendChild(an); }
    if (isAdmin) {
      const bar = el('div', 'fb-admin');
      const sel = el('select', 'fb-setstatus');
      for (const k of Object.keys(FB_STATUS)) { const o = el('option'); o.value = k; o.textContent = FB_STATUS[k]; if (k === f.status) o.selected = true; sel.appendChild(o); }
      const note = el('input', 'fb-setnote'); note.placeholder = 'メモ（任意）'; note.value = f.admin_note || '';
      const save = el('button', 'fb-save'); save.textContent = '更新';
      save.onclick = async () => { try { await post('feedback_update', { id: f.id, status: sel.value, admin_note: note.value }); toast('更新しました'); loadFeedback(); } catch (e) {} };
      bar.append(sel, note, save); it.appendChild(bar);
    }
    list.appendChild(it);
  }
}

export async function submitFeedback() {
  const text = ($('#fbText') && $('#fbText').value.trim()) || '';
  const kind = ($('#fbKind') && $('#fbKind').value) || 'request';
  if (!text) return;
  try { await post('feedback_submit', { text, kind }); toast('送信しました。ありがとうございます'); loadFeedback(); }
  catch (e) { toast('送信に失敗しました'); }
}

// ── 配線 ─────────────────────────────────────────────────
