/* C:\Dropbox\Programs\Claude\chai\assets\js\share.js
   -> /var/www/chai/assets/js/share.js

   会話の共有 (誰に / 読むだけか書けるか)。
*/
import { api, post } from './api.js';
import { loadConvs } from './convs.js';
import { $, el, esc, toast } from './util.js';

// ── 共有 ─────────────────────────────────────────────────
export const shareModal = $('#shareModal');

export let shareConvId = 0;

export let rosterCache = null; // [{user,name,kana,grade}]

export async function loadRoster() {
  if (rosterCache) return rosterCache;
  try {
    const r = await fetch('https://auth.nkmr.io/?action=roster', { credentials: 'include' });
    const j = await r.json();
    rosterCache = Array.isArray(j.users) ? j.users : [];
  } catch (e) { rosterCache = []; }
  return rosterCache;
}

export async function openShare(convId) { if (!convId) return; shareConvId = convId; await loadRoster(); await loadShares(); shareModal.hidden = false; }

export async function loadShares() { try { const { shares } = await api('shares_list&id=' + shareConvId); renderShares(shares || []); } catch (e) { renderShares([]); } }

export function renderShares(shares) {
  const box = $('#shareBody');
  const granted = new Set((shares || []).map(s => String(s.grantee).toLowerCase()));
  const roster = (rosterCache || []).filter(m => m.user && !granted.has(String(m.user).toLowerCase()));
  let picker;
  if (roster.length) {
    const byGrade = {};
    for (const m of roster) (byGrade[m.grade || 'その他'] ||= []).push(m);
    const groups = Object.keys(byGrade).map(g =>
      `<optgroup label="${esc(g)}">` +
      byGrade[g].map(m => `<option value="${esc(m.user)}">${esc(m.name || m.user)}</option>`).join('') +
      `</optgroup>`).join('');
    picker = `<select id="shareWho"><option value="">研究室メンバーを選ぶ…</option>${groups}</select>`;
  } else {
    picker = `<input id="shareWho" type="text" placeholder="相手のメール または ユーザー名">`;
  }
  box.innerHTML = `
    <div class="share-add">
      ${picker}
      <select id="sharePerm"><option value="read">閲覧のみ</option><option value="write">書き込み可</option></select>
      <button id="shareAddBtn">追加</button>
    </div>
    <label class="share-all"><input type="checkbox" id="shareEveryone"> 全員に共有（nkmrログインユーザー全員）</label>
    <div class="share-list" id="shareList"></div>
    <div class="plan-sub" style="margin-top:8px">相手の書き込み量は、その人自身のサブスク状態で決まります。</div>`;
  $('#shareAddBtn').onclick = () => addShare(false);
  $('#shareEveryone').onchange = (e) => { if (e.target.checked) addShare(true); };
  const list = $('#shareList');
  if (!shares.length) { list.innerHTML = '<div class="share-empty">まだ誰にも共有していません。</div>'; return; }
  for (const s of shares) {
    const it = el('div', 'share-item');
    const who = el('span', 'share-who');
    if (s.grantee === '*') { who.textContent = '🌐 全員'; }
    else { const m = (rosterCache || []).find(x => String(x.user).toLowerCase() === String(s.grantee).toLowerCase()); who.textContent = m ? m.name : s.grantee; }
    // 権限はプルダウンで一発変更（閲覧⇄編集。削除→付け直し不要）
    const perm = el('select', 'share-perm');
    perm.innerHTML = '<option value="read">閲覧のみ</option><option value="write">書き込み可</option>';
    perm.value = s.permission === 'write' ? 'write' : 'read';
    perm.onchange = async () => { try { await post('share_add', { conversation_id: shareConvId, grantee: s.grantee, permission: perm.value, everyone: s.grantee === '*' }); toast('権限を変更しました'); await loadConvs(); } catch (e) { toast('変更に失敗しました'); loadShares(); } };
    const x = el('button', 'share-x'); x.textContent = '×'; x.title = '解除';
    x.onclick = async () => { try { await post('share_remove', { conversation_id: shareConvId, id: s.id }); loadShares(); await loadConvs(); } catch (e) {} };
    it.append(who, perm, x); list.appendChild(it);
  }
}

export async function addShare(everyone) {
  const perm = ($('#sharePerm') && $('#sharePerm').value) || 'read';
  const who = everyone ? '*' : (($('#shareWho') && $('#shareWho').value.trim()) || '');
  if (!who) return;
  try { await post('share_add', { conversation_id: shareConvId, grantee: who, permission: perm, everyone: who === '*' }); if ($('#shareWho')) $('#shareWho').value = ''; toast('共有しました'); loadShares(); await loadConvs(); }
  catch (e) { toast('共有に失敗しました'); }
}

// ── 要望・不具合 ─────────────────────────────────────────
