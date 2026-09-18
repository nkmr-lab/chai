/* C:\Dropbox\Programs\Claude\chai\assets\js\todos.js
   -> /var/www/chai/assets/js/todos.js

   TODO (〆切つき)。
*/
import { api, post } from './api.js';
import { renderConvList } from './convs.js';
import { convTitle, viewChat } from './panes.js';
import { S } from './state.js';
import { $, el, toast } from './util.js';

// ── TODO（〆切付き・完了チェック） ───────────────────────
export const todoOf = (id) => S.todos.find((t) => t.id === id);

export const fmtDue = (due) => {
  if (!due) return { cls: '', short: '' };
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const d = new Date(due + 'T00:00:00');
  const days = Math.round((d - today) / 86400000);
  if (days < 0) return { cls: 'over', short: (-days) + '日超過' };
  if (days === 0) return { cls: 'soon', short: '今日' };
  if (days === 1) return { cls: 'soon', short: '明日' };
  if (days <= 3) return { cls: 'soon', short: 'あと' + days + '日' };
  return { cls: '', short: 'あと' + days + '日' };
};

export async function loadTodos() { try { const r = await api('todos'); S.todos = r.todos || []; S.todosDone = r.done || []; renderTodos(); } catch (e) {} }

export function renderTodos() {
  const box = $('#todos'); if (!box) return; box.innerHTML = '';
  const items = S.todos.filter((t) => S.convs.some((c) => c.id === t.id));
  const doneItems = S.todosDone.filter((t) => S.convs.some((c) => c.id === t.id));
  if (!items.length && !doneItems.length) return;
  items.sort((a, b) => (a.due || '9999').localeCompare(b.due || '9999'));   // 〆切が近い順、期日なしは末尾
  if (items.length) { const head = el('div', 'todo-head'); head.textContent = '✅ TODO'; box.appendChild(head); }
  for (const t of items) {
    const it = el('div', 'todo-item');
    it.onclick = () => viewChat(t.id);   // 行のどこをタップしても開く
    const ck = el('button', 'todo-ck'); ck.textContent = '☐'; ck.title = '完了にする';
    ck.onclick = (e) => { e.stopPropagation(); completeTodo(t.id); };
    const body = el('div', 'todo-body');
    const nm = el('div', 'todo-name'); nm.textContent = convTitle(t.id); nm.title = convTitle(t.id);
    const due = fmtDue(t.due);
    const row = el('div', 'todo-due-row');
    const dp = el('input', 'todo-date'); dp.type = 'date'; dp.value = t.due || ''; dp.title = '〆切を設定';
    dp.onclick = (e) => e.stopPropagation();   // 日付選択は開かない
    dp.onchange = () => setTodoDue(t.id, dp.value);
    row.appendChild(dp);
    if (t.due && due.short) {
      const badge = el('span', 'todo-due ' + due.cls);
      badge.textContent = due.short;
      row.appendChild(badge);
    }
    body.append(nm, row);
    const x = el('span', 'todo-x'); x.textContent = '×'; x.title = 'TODOから外す';
    x.onclick = (e) => { e.stopPropagation(); removeTodo(t.id); };
    it.append(ck, body, x); box.appendChild(it);
  }
  // 完了済み（折りたたみ）
  if (doneItems.length) {
    const open = localStorage.getItem('chai_todo_done_open') === '1';
    const tg = el('div', 'todo-done-toggle'); tg.textContent = (open ? '▾' : '▸') + ' ✔ 完了済み（' + doneItems.length + '）';
    tg.onclick = () => { localStorage.setItem('chai_todo_done_open', open ? '0' : '1'); renderTodos(); };
    box.appendChild(tg);
    if (open) {
      for (const t of doneItems) {
        const it = el('div', 'todo-item done');
        it.onclick = () => viewChat(t.id);
        const un = el('button', 'todo-ck'); un.textContent = '☑'; un.title = '未完に戻す';
        un.onclick = (e) => { e.stopPropagation(); uncompleteTodo(t.id); };
        const nm = el('div', 'todo-name done'); nm.textContent = convTitle(t.id); nm.title = convTitle(t.id);
        const x = el('span', 'todo-x'); x.textContent = '×'; x.title = 'TODOから削除';
        x.onclick = (e) => { e.stopPropagation(); removeTodo(t.id); };
        it.append(un, nm, x); box.appendChild(it);
      }
    }
  }
}

export async function toggleTodo(id) {
  if (!id) return;
  if (todoOf(id)) { removeTodo(id); return; }
  S.todos = [...S.todos, { id, due: null, done: 0 }];   // 即時反映
  renderTodos(); renderConvList();
  try { await post('todo_set', { conversation_id: id }); } catch (e) { loadTodos(); }
}

export async function setTodoDue(id, due) {
  const t = todoOf(id); if (t) t.due = due || null;
  renderTodos();
  try { await post('todo_set', { conversation_id: id, due: due || '' }); } catch (e) { loadTodos(); }
}

export async function completeTodo(id) {
  const t = todoOf(id); S.todos = S.todos.filter((x) => x.id !== id);   // 未完→完了へ移す
  if (t) S.todosDone = [{ id, due: t.due }, ...S.todosDone];
  renderTodos(); renderConvList(); toast('完了にしました');
  try { await post('todo_set', { conversation_id: id, done: 1 }); } catch (e) { loadTodos(); }
}

export async function uncompleteTodo(id) {
  const t = S.todosDone.find((x) => x.id === id); S.todosDone = S.todosDone.filter((x) => x.id !== id);
  S.todos = [...S.todos, { id, due: t ? t.due : null }];   // 完了→未完へ戻す
  renderTodos(); renderConvList();
  try { await post('todo_set', { conversation_id: id, done: 0 }); } catch (e) { loadTodos(); }
}

export async function removeTodo(id) {
  S.todos = S.todos.filter((t) => t.id !== id);
  S.todosDone = S.todosDone.filter((t) => t.id !== id);
  renderTodos(); renderConvList();
  try { await post('todo_remove', { conversation_id: id }); } catch (e) { loadTodos(); }
}
// ── ピンセット（名前付きのピン集合） ─────────────────────
