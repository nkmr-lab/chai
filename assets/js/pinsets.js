/* C:\Dropbox\Programs\Claude\chai\assets\js\pinsets.js
   -> /var/www/chai/assets/js/pinsets.js

   ピンセット (よく一緒に見る会話の組)。
*/
import { api, post } from './api.js';
import { renderConvList } from './convs.js';
import { closeSidebarMobile, convTitle, paneIds, renderPanes, saveOpen } from './panes.js';
import { S } from './state.js';
import { $, el } from './util.js';

// ── ピンセットの中身を編集（各チャットの並び替え・追加・削除） ──
export const pinsetModal = $('#pinsetModal');

export let editingPinsetId = null;

// ── ピンセット（名前付きのピン集合） ─────────────────────
export async function loadPinsets() { try { const { pinsets } = await api('pinsets'); S.pinsets = pinsets || []; renderPinsets(); } catch (e) {} }

export function renderPinsets() {
  const box = $('#pinsets'); box.innerHTML = '';
  const head = el('div', 'pinset-head');
  const lbl = el('span'); lbl.textContent = '📌 ピンセット'; head.appendChild(lbl);
  const save = el('button', 'pinset-save'); save.textContent = '＋保存'; save.title = '今ピン中のチャットをセットとして保存';
  save.onclick = savePinset; head.appendChild(save);
  box.appendChild(head);
  for (const ps of S.pinsets) {
    const it = el('div', 'pinset-item');
    const t = el('span', 'ps-name'); t.textContent = ps.name; t.title = (ps.chat_ids || []).length + '件をまとめて開く';
    const ed = el('span', 'ps-edit'); ed.textContent = '✎'; ed.title = '中身を編集（並び替え・追加・削除）';
    ed.onclick = (e) => { e.stopPropagation(); openPinsetEditor(ps.id); };
    const x = el('span', 'ps-x'); x.textContent = '×'; x.title = 'このセットを削除';
    x.onclick = (e) => { e.stopPropagation(); deletePinset(ps.id, ps.name); };
    it.append(t, ed, x);
    it.onclick = () => openSet(ps.chat_ids);
    box.appendChild(it);
  }
}
// ── ピンセットの中身を編集（各チャットの並び替え・追加・削除） ──

export function openPinsetEditor(id) { editingPinsetId = id; renderPinsetEditor(); pinsetModal.hidden = false; }

export async function savePinsetMembers(ps, ids) {
  ids = ids.filter((x) => x > 0);
  if (!ids.length) { alert('ピンセットを空にはできません。セットごと消す場合は × を使ってください。'); return false; }
  try { await post('pinset_save', { name: ps.name, chat_ids: ids }); await loadPinsets(); return true; }
  catch (e) { alert('保存に失敗しました'); return false; }
}

export function renderPinsetEditor() {
  const ps = S.pinsets.find((p) => p.id === editingPinsetId);
  if (!ps) { pinsetModal.hidden = true; return; }
  $('#pinsetTitle').textContent = '📌 ' + ps.name + '（中身を編集）';
  const body = $('#pinsetBody'); body.innerHTML = '';
  const ids = (ps.chat_ids || []).slice();
  const list = el('div', 'pe-list');
  if (!ids.length) list.innerHTML = '<div class="pe-empty">チャットがありません。</div>';
  ids.forEach((cid, i) => {
    const row = el('div', 'pe-item');
    const t = el('span', 'pe-name'); t.textContent = convTitle(cid) || ('#' + cid); t.title = t.textContent;
    const up = el('button', 'pe-btn'); up.textContent = '▲'; up.title = '上へ'; up.disabled = i === 0;
    up.onclick = () => { const a = ids.slice(); [a[i - 1], a[i]] = [a[i], a[i - 1]]; savePinsetMembers(ps, a).then((ok) => ok && renderPinsetEditor()); };
    const dn = el('button', 'pe-btn'); dn.textContent = '▼'; dn.title = '下へ'; dn.disabled = i === ids.length - 1;
    dn.onclick = () => { const a = ids.slice(); [a[i + 1], a[i]] = [a[i], a[i + 1]]; savePinsetMembers(ps, a).then((ok) => ok && renderPinsetEditor()); };
    const x = el('button', 'pe-btn del'); x.textContent = '✕'; x.title = 'このセットから外す';
    x.onclick = () => { const a = ids.filter((_, j) => j !== i); savePinsetMembers(ps, a).then((ok) => ok && renderPinsetEditor()); };
    row.append(t, up, dn, x); list.appendChild(row);
  });
  body.appendChild(list);
  // 追加
  const add = el('div', 'pe-add');
  const sel = el('select'); sel.appendChild(Object.assign(el('option'), { value: '', textContent: 'チャットを追加…' }));
  S.convs.filter((c) => !ids.includes(c.id)).forEach((c) => sel.appendChild(Object.assign(el('option'), { value: c.id, textContent: (c.title || '無題') })));
  const addBtn = el('button', 'pe-addbtn'); addBtn.textContent = '追加';
  addBtn.onclick = () => { const cid = +sel.value; if (!cid) return; if (ids.length >= 5) { alert('1つのセットは最大5件までです。'); return; } savePinsetMembers(ps, [...ids, cid]).then((ok) => ok && renderPinsetEditor()); };
  add.append(sel, addBtn); body.appendChild(add);
  // このセットを開く
  const openBtn = el('button', 'pe-open'); openBtn.textContent = '▶ このセットを開く';
  openBtn.onclick = () => { pinsetModal.hidden = true; openSet(ps.chat_ids); };
  body.appendChild(openBtn);
}

export async function savePinset() {
  const ids = paneIds().filter((x) => x > 0);
  if (!ids.length) { alert('保存できるチャットがありません（表示中のチャットが空です）。'); return; }
  const name = prompt('ピンセット名を付けて保存', ''); if (name === null) return;
  const nm = name.trim(); if (!nm) return;
  try { await post('pinset_save', { name: nm, chat_ids: ids }); await loadPinsets(); } catch (e) { alert('保存に失敗しました'); }
}

export function openSet(ids) {
  const list = (ids || []).filter((x) => Number.isInteger(x) && x > 0).slice(0, 5);
  S.pins = list; S.cur = list[0] || 0;
  S.activeTab = 0; saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
}

export async function deletePinset(id, name) {
  if (!confirm('ピンセット「' + name + '」を削除しますか？')) return;
  try { await post('pinset_delete', { id }); await loadPinsets(); } catch (e) {}
}
