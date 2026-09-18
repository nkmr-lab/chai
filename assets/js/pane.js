/* C:\Dropbox\Programs\Claude\chai\assets\js\pane.js
   -> /var/www/chai/assets/js/pane.js

   1 つの面 (会話 1 つぶん) を組み立てる。 送信・受信・道具はこの中。
*/
import { api, post } from './api.js';
import { delConv, loadConvs } from './convs.js';
import { app } from './dom.js';
import { EMBED, tellParent } from './embed.js';
import { bindCopy, md } from './md.js';
import { closeTab, convTitle, saveOpen } from './panes.js';
import { openShare } from './share.js';
import { S, liveStreams } from './state.js';
import { saveStock } from './stocks.js';
import { openPlan, refreshState } from './tier.js';
import { $, el, esc, plainRaw, readFile, toast } from './util.js';

// ── ペイン生成 ───────────────────────────────────────────
export function createPane(convId) {
  const root = el('div', 'pane');
  const head = el('div', 'pane-head');
  const title = el('span', 'pane-title'); title.textContent = convTitle(convId);
  const edit = el('button', 'pane-edit'); edit.textContent = '✎'; edit.title = '名前を変更';
  const share = el('button', 'pane-share'); share.textContent = '👥'; share.title = '共有'; share.style.display = 'none';
  const link = el('button', 'pane-link'); link.textContent = '🔗'; link.title = 'このチャットのリンクをコピー'; link.style.display = 'none';
  const del = el('button', 'pane-del'); del.textContent = '🗑'; del.title = 'このチャットを削除';
  const modelSel = el('select', 'pane-model'); modelSel.title = 'モデル';
  const pin = el('button', 'pane-pin'); pin.textContent = '📌'; pin.title = 'ピンを外す（閉じる）';
  head.append(title, edit, share, link, del, modelSel, pin);
  if (EMBED) {
    // 親 (chat.nkmr.io) の面と見た目を揃えるため、 ヘッダはここ 1 段だけにする。
    // 親側の「🍵 chai」の段は隠してあるので、 そこにあった操作をここに持ってくる。
    const mk = (txt, tip, fn) => { const b = el('button', 'embed-act'); b.textContent = txt; b.title = tip; b.onclick = fn; return b; };
    head.append(
      mk('☰', '会話一覧 / ピン', () => app.classList.toggle('side-open')),
      mk('＋', '新しいチャット', () => $('#btnNew').click()),
      mk('↗', '別のタブで開く', () => window.open('https://chai.nkmr.io/' + (P.convId ? '#c=' + P.convId : ''), '_blank', 'noopener')),
      mk('✕', 'この面を閉じる', () => tellParent({ type: 'chai:close' })),
    );
  }
  const refDock = el('div', 'ref-dock'); refDock.hidden = true;   // 参照ドック（上部固定表示）
  const threadEl = el('div', 'pane-thread');
  const chips = el('div', 'pane-chips');
  const comp = el('div', 'pane-composer');
  const attach = el('button', 'comp-btn'); attach.textContent = '📎'; attach.title = '画像・PDFを添付';
  const ta = el('textarea'); ta.rows = 1; ta.placeholder = 'メッセージを入力（Shift+Enterで改行）';
  const sendBtn = el('button', 'btn-send'); sendBtn.textContent = EMBED ? '送信' : '➤'; sendBtn.title = '送信';
  const fileIn = el('input'); fileIn.type = 'file'; fileIn.accept = 'image/*,application/pdf,.csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; fileIn.multiple = true; fileIn.hidden = true;
  comp.append(attach, ta, sendBtn);
  const cwrap = el('div', 'pane-composer-wrap'); cwrap.append(chips, comp, fileIn);
  root.append(head, refDock, threadEl, cwrap);

  const P = { el: root, convId, title, threadEl, streaming: false, abort: null, model: null, attachments: [], perm: 'owner' };
  root.dataset.cid = convId || '';

  // ── 参照ドック：ある発言を上部に固定表示したまま、下で会話を続ける ──
  let refs = [];
  const refKey = () => 'chai_ref_' + (P.convId || 0);
  function loadRefs() { try { const s = JSON.parse(localStorage.getItem(refKey()) || '[]'); refs = Array.isArray(s) ? s : []; } catch (e) { refs = []; } renderRefs(); }
  function saveRefs() { try { localStorage.setItem(refKey(), JSON.stringify(refs)); } catch (e) {} }
  function renderRefs() {
    refDock.innerHTML = '';
    if (!refs.length) { refDock.hidden = true; return; }
    refDock.hidden = false;
    const hd = el('div', 'ref-dock-head');
    hd.appendChild(Object.assign(el('span'), { textContent: `🔖 参照中（${refs.length}）` }));
    const clr = el('button', 'ref-clear'); clr.textContent = 'すべて外す';
    clr.onclick = () => { refs = []; saveRefs(); renderRefs(); };
    hd.appendChild(clr); refDock.appendChild(hd);
    refs.forEach((r, i) => {
      const it = el('div', 'ref-item');
      const x = el('button', 'ref-x'); x.textContent = '✕'; x.title = '参照から外す';
      x.onclick = () => { refs.splice(i, 1); saveRefs(); renderRefs(); };
      const c = el('div', 'ref-content ' + (r.md ? 'md' : 'plain'));
      if (r.md) { c.innerHTML = md(r.raw); bindCopy(c); } else { c.textContent = r.raw; }
      it.append(x, c); refDock.appendChild(it);
    });
  }
  function addRef(role, raw) {
    raw = (raw || '').trim(); if (!raw) return;
    if (refs.some((r) => r.raw === raw)) { toast('すでに参照に入っています'); return; }
    refs.push({ role, raw, md: role === 'assistant' }); saveRefs(); renderRefs();
    toast('上部に固定しました');
  }
  function addRefBtn(m, role) {
    if (!m || m.querySelector('.msg-ref')) return;
    const b = el('button', 'msg-ref'); b.textContent = '🔖'; b.title = 'この発言を上部に固定して見ながら続ける';
    b.onclick = (e) => { e.stopPropagation(); const body = m.querySelector('.body'); addRef(role, body ? (body._raw != null ? body._raw : body.textContent) : ''); };
    m.appendChild(b);
  }
  // 権限に応じてUI（所有者=共有可, 閲覧のみ=入力不可）
  function applyPerm() {
    share.style.display = (P.perm === 'owner' && P.convId) ? '' : 'none';
    link.style.display = P.convId ? '' : 'none';
    del.style.display = (P.perm === 'owner' || P.perm === 'write') ? '' : 'none';
    edit.style.display = (P.perm === 'owner') ? '' : 'none';
    cwrap.style.display = (P.perm === 'read') ? 'none' : '';
    let ro = head.parentElement.querySelector('.readonly-note');
    if (P.perm === 'read' && !ro) { ro = el('div', 'readonly-note'); ro.textContent = '🔒 閲覧のみ（書き込み権限がありません）'; root.appendChild(ro); }
    else if (P.perm !== 'read' && ro) ro.remove();
  }
  share.onclick = () => openShare(P.convId);
  link.onclick = async () => {
    if (!P.convId) return;
    const url = location.origin + '/#c=' + P.convId;
    try { await navigator.clipboard.writeText(url); toast('リンクをコピーしました'); }
    catch (e) { prompt('このチャットのリンク（コピーしてください）', url); }
  };

  // モデル選択
  (function populate() {
    const models = S.tier.models || [];
    const saved = localStorage.getItem('chai_model');
    const ids = models.map((m) => m.id);
    P.model = ids.includes(saved) ? saved : (S.tier.default_model || ids[0]);
    modelSel.innerHTML = '';
    for (const m of models) { const o = el('option'); o.value = m.id; o.textContent = m.label; if (m.id === P.model) o.selected = true; modelSel.appendChild(o); }
    modelSel.style.display = models.length ? '' : 'none';
  })();
  modelSel.onchange = () => { P.model = modelSel.value; localStorage.setItem('chai_model', P.model); };

  const inner = () => { let n = threadEl.querySelector('.thread-inner'); if (!n) { threadEl.innerHTML = ''; n = el('div', 'thread-inner'); threadEl.appendChild(n); } return n; };
  const scroll = () => { threadEl.scrollTop = threadEl.scrollHeight; };
  // 生成中は「下端付近にいる時だけ」追従（上を読んでいる時は飛ばさない）
  let stick = true;
  const nearBottom = () => (threadEl.scrollHeight - threadEl.scrollTop - threadEl.clientHeight) < 90;
  threadEl.addEventListener('scroll', () => { stick = nearBottom(); }, { passive: true });
  const scrollStick = () => { if (stick) scroll(); };
  function showEmpty() { inner().innerHTML = `<div class="empty"><div class="empty-logo">🍵</div><p>話しかけてください</p></div>`; }
  function addDelete(m, id) {
    if (!id || !m || m.querySelector('.msg-del')) return;
    const b = el('button', 'msg-del'); b.textContent = '🗑'; b.title = 'このメッセージを削除（履歴・アップロードも消す）';
    b.onclick = async (e) => {
      e.stopPropagation();
      if (!confirm('このメッセージを削除しますか？\n会話履歴から消え、このメッセージで上げたファイルも削除されます。')) return;
      try { await post('message_delete', { id }); m.remove(); } catch (err) { alert('削除に失敗しました'); }
    };
    m.appendChild(b);
  }
  function addMsg(role, content, atts, id, author) {
    const m = el('div', `msg ${role}`);
    const nm = (role === 'user' && author) ? author : '';
    const av = el('div', 'avatar'); av.textContent = role === 'user' ? ((nm || S.user.name || 'あ')[0]) : '🍵';
    const body = el('div', 'body');
    body.dataset.who = role === 'user' ? (nm || S.user.name || '') : 'chai';   // 埋め込み時に名前として出す
    if (role === 'assistant') { body.innerHTML = content ? md(content) : '<span class="cursor"></span>'; body._raw = content || ''; }
    else {
      if (nm && nm !== S.user.name) body.appendChild(Object.assign(el('div', 'author-name'), { textContent: nm }));
      if (content) {
        const c = el('div', 'user-text'); c.textContent = content; body.appendChild(c);
        // 長い入力は折り畳んでスクロール量を減らす（Gemini風）
        if (content.length > 500 || (content.match(/\n/g) || []).length > 10) {
          c.classList.add('clamped');
          const tg = el('button', 'user-more'); tg.textContent = '続きを表示 ▼';
          tg.onclick = () => { const on = c.classList.toggle('clamped'); tg.textContent = on ? '続きを表示 ▼' : '折りたたむ ▲'; };
          body.appendChild(tg);
        }
      }
      if (atts && atts.length) {
        const row = el('div', 'msg-attach');
        for (const a of atts) { if (a.kind === 'image') { const im = el('img', 'msg-img'); im.src = a.dataUrl; row.appendChild(im); } else { const c = el('span', 'file-chip'); c.textContent = (a.kind === 'data' ? '📊 ' : '📄 ') + a.name; row.appendChild(c); } }
        body.appendChild(row);
      }
      body._raw = content || '';
    }
    m.append(av, body); addRefBtn(m, role); if (id) { m.dataset.mid = id; addDelete(m, id); } inner().appendChild(m); scroll(); return body;
  }
  function makeStreamer(body) {
    let target = body;   // 描画先（別ペインへ付け替え可能）
    let pending = '', shown = '', status = '', timer = null, ended = false, finished = false, resolveEnd = null;
    let doneCbs = [];
    const fireDone = () => { finished = true; const cbs = doneCbs; doneCbs = []; cbs.forEach((f) => { try { f(); } catch (e) {} }); };
    const render = (cursor) => { let h = md(shown); if (status) h += `<span class="gen-wait">${esc(status)}</span>`; else if (cursor) h += '<span class="cursor"></span>'; target.innerHTML = h; target._raw = shown + pending; scrollStick(); };
    const tick = () => {
      if (pending.length) { const n = Math.max(2, Math.ceil(pending.length / 12)); shown += pending.slice(0, n); pending = pending.slice(n); render(true); }
      if (!pending.length && ended) { clearInterval(timer); timer = null; render(false); if (shown.trim()) bindCopy(target); else target.innerHTML = '<em>（応答なし）</em>'; if (resolveEnd) resolveEnd(); }
    };
    const ensure = () => { if (!timer) timer = setInterval(tick, 22); };
    return {
      push(t) { pending += t; status = ''; if (S.instant) { shown += pending; pending = ''; render(true); } else ensure(); },
      showStatus(t) { status = t; render(false); },
      image(mi) { shown += pending + mi; pending = ''; status = ''; render(true); },
      end() { ended = true; ensure(); return new Promise((r) => (resolveEnd = r)); },
      finalize() { if (timer) { clearInterval(timer); timer = null; } shown += pending; pending = ''; render(false); if (shown.trim()) bindCopy(target); if (resolveEnd) resolveEnd(); },
      attach(newBody) { target = newBody; render(!finished); },   // 戻ってきたペインへ描画を移す
      onDone(cb) { if (finished) cb(); else doneCbs.push(cb); },
      markDone() { fireDone(); },
      get text() { return shown + pending; },
      get el() { return target; },
    };
  }
  function setStreaming(on) { P.streaming = on; sendBtn.classList.toggle('stopping', on); sendBtn.textContent = on ? (EMBED ? '停止' : '■') : (EMBED ? '送信' : '➤'); sendBtn.title = on ? '停止' : '送信'; }
  function controlsOf(body) { let c = body.querySelector('.msg-controls'); if (!c) { c = el('div', 'msg-controls'); body.appendChild(c); } return c; }
  function addCopyBtn(body) {
    if (!body || body.querySelector('.copy-msg')) return;
    const b = el('button', 'ctrl-btn copy-msg'); b.textContent = '📋 コピー';
    b.onclick = () => { navigator.clipboard.writeText(plainRaw(body._raw) || body.textContent || ''); b.textContent = '📋 コピー済'; setTimeout(() => (b.textContent = '📋 コピー'), 1200); };
    controlsOf(body).appendChild(b);
  }
  function addRegen(body) {
    threadEl.querySelectorAll('.regen-btn').forEach((n) => n.remove());
    if (!body) return;
    const b = el('button', 'ctrl-btn regen-btn'); b.textContent = '🔄 やり直す'; b.onclick = regenerate;
    controlsOf(body).appendChild(b);
  }
  // 回答が無いまま終わった発話（中断/エラー等）向け：ワンタップで生成する
  function addResume() {
    inner().querySelectorAll('.resume-row').forEach((n) => n.remove());
    if (P.perm === 'read') return;
    const row = el('div', 'resume-row');
    const note = el('span', 'resume-note'); note.textContent = 'この発話への回答がありません';
    const b = el('button', 'resume-btn'); b.textContent = '▶ 回答を生成';
    b.onclick = () => { row.remove(); regenerate(); };
    row.append(note, b); inner().appendChild(row);
  }
  function addStockBtn(body) {
    if (!body || body.querySelector('.stock-btn')) return;
    const b = el('button', 'ctrl-btn stock-btn'); b.textContent = '⭐ ストック';
    b.onclick = async () => {
      const text = plainRaw(body._raw) || body.textContent || ''; if (!text.trim()) return;
      const mid = body.parentElement && body.parentElement.dataset.mid ? +body.parentElement.dataset.mid : null;
      if (await saveStock(text, P.convId || null, mid)) { b.textContent = '⭐ 保存済'; setTimeout(() => (b.textContent = '⭐ ストック'), 1200); }
    };
    controlsOf(body).appendChild(b);
  }
  async function runStream(payload, userMsgEl) {
    const body = addMsg('assistant', '');
    body.innerHTML = '<span class="gen-wait">🧠 考えています…</span>';   // 即時フィードバック
    const stream = makeStreamer(body);
    setStreaming(true);
    const ac = new AbortController(); P.abort = ac;
    if (P.convId) liveStreams[P.convId] = { streamer: stream, ac };   // 既存会話なら即登録
    try {
      const r = await fetch('/chat.php', { method: 'POST', credentials: 'same-origin', signal: ac.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      if (!r.ok || !r.body) throw new Error('network');
      const reader = r.body.getReader(); const dec = new TextDecoder(); let buf = '';
      for (;;) {
        const { value, done } = await reader.read(); if (done) break;
        buf += dec.decode(value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n\n')) !== -1) {
          const frame = buf.slice(0, idx); buf = buf.slice(idx + 2);
          const line = frame.split('\n').find((l) => l.startsWith('data:')); if (!line) continue;
          const ev = JSON.parse(line.slice(5).trim());
          if (ev.type === 'meta') {
            P.convId = ev.conversation_id; root.dataset.cid = P.convId || ''; applyPerm();
            if (P.convId) {
              liveStreams[P.convId] = { streamer: stream, ac };
              // 新規会話は「できた瞬間」に一覧へ出す（生成完了を待たない）＝移動しても戻れる・ピン/リロードで消えない
              if (!S.convs.some((c) => c.id === P.convId)) {
                if (S.cur === 0) { S.cur = P.convId; try { history.replaceState(null, '', '#c=' + P.convId); } catch (e) {} tellParent({ type: 'chai:conv', id: P.convId }); }
                saveOpen(); loadConvs();
              }
            }
            if (userMsgEl && ev.user_msg_id) addDelete(userMsgEl, ev.user_msg_id);
          }
          else if (ev.type === 'delta') { stream.push(ev.text); }
          else if (ev.type === 'image') { stream.image(ev.markdown); }
        else if (ev.type === 'code') { stream.image(ev.markdown); }
          else if (ev.type === 'status') { stream.showStatus(ev.text); }
          else if (ev.type === 'reasoning') { stream.showStatus('🧠 ' + ev.text); }
          else if (ev.type === 'done') { P.convId = ev.conversation_id; title.textContent = ev.title; if (ev.msg_id) addDelete(body.parentElement, ev.msg_id); }
          else if (ev.type === 'error') { throw new Error(ev.message); }
        }
      }
      await stream.end();
    } catch (e) {
      if (e.name === 'AbortError') stream.finalize();
      else { stream.finalize(); if (!stream.text.trim()) body.innerHTML = `<p style="color:var(--danger)">⚠ ${esc(e.message || '通信に失敗しました')}</p>`; }
    } finally {
      P.abort = null; setStreaming(false);
      const t = stream.el; t._raw = stream.text; addCopyBtn(t); addStockBtn(t); addRegen(t);
      if (P.convId && liveStreams[P.convId] && liveStreams[P.convId].streamer === stream) delete liveStreams[P.convId];
      stream.markDone();
    }
  }
  async function doSend() {
    if (P.streaming) { if (P.abort) P.abort.abort(); return; }
    // 添付ファイルの読み込みが終わるまで送信しない（未完で送ると本文が届かない）
    if (P.attachments.some((a) => a.reading)) { toast('📎 ファイルを読み込み中です。完了後に送信してください'); return; }
    const text = ta.value.trim();
    if (!text && !P.attachments.length) return;
    if (S.tier.tier === 'free' && S.tier.message_cap > 0 && S.tier.remaining_window <= 0) { openPlan(`おためしは${S.tier.window_hours}時間あたり${S.tier.message_cap}通までです。メンバーになると無制限で使えます。`); return; }
    stick = true;   // 送信直後は下端に追従
    const atts = P.attachments.filter((a) => a.dataUrl && !a.error);   // 読み込み済みのみ
    const images = atts.filter((a) => a.kind === 'image').map((a) => a.dataUrl);
    const pdfs = atts.filter((a) => a.kind === 'pdf').map((a) => ({ name: a.name, data: a.dataUrl }));
    const datafiles = atts.filter((a) => a.kind === 'data').map((a) => ({ name: a.name, data: a.dataUrl }));
    ta.value = ''; grow(); P.attachments = []; renderChips();
    if (threadEl.querySelector('.empty')) inner().innerHTML = '';
    const wasNew = !P.convId;
    const ubody = addMsg('user', text, atts);
    P.lastPayload = { content: text, images, pdfs, datafiles };   // 中断後のやり直し用
    await runStream({ conversation_id: P.convId || 0, content: text, model: P.model, images, pdfs, datafiles }, ubody.parentElement);
    if (wasNew && P.convId) { if (S.cur === 0) S.cur = P.convId; saveOpen(); try { await post('classify', { id: P.convId }); } catch (e) {} }
    await refreshState(); await loadConvs();
  }
  async function regenerate() {
    if (P.streaming) return;
    const last = inner().querySelector('.msg:last-child'); if (last && last.classList.contains('assistant')) last.remove();
    if (P.convId) {
      await runStream({ conversation_id: P.convId, model: P.model, regenerate: true });
    } else if (P.lastPayload) {
      // convId未確定（中断が早かった等）でも、直前の送信内容で作り直す
      await runStream({ conversation_id: 0, model: P.model, ...P.lastPayload });
    } else { return; }
    await refreshState(); await loadConvs();
  }
  // 添付
  function renderChips() {
    chips.innerHTML = '';
    P.attachments.forEach((a, i) => {
      const c = el('span', 'chip' + (a.reading ? ' reading' : '') + (a.error ? ' error' : ''));
      const icon = a.reading ? '⏳ ' : (a.error ? '⚠ ' : (a.kind === 'image' ? '🖼 ' : a.kind === 'data' ? '📊 ' : '📄 '));
      c.innerHTML = icon + esc(a.name) + (a.reading ? ' <span class="chip-prog">読み込み中…</span>' : (a.error ? ' <span class="chip-prog">失敗</span>' : ''));
      const x = el('span', 'chip-x'); x.textContent = '×'; x.onclick = () => { P.attachments.splice(i, 1); renderChips(); };
      c.appendChild(x); chips.appendChild(c);
    });
  }
  // 画像は長辺2048pxに縮小してJPEG化（巨大写真でも軽く・確実に送れる）。デコード不可(HEIC等)は原本のまま
  async function imageToDataUrl(f) {
    const raw = await readFile(f);
    try {
      const img = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = raw; });
      let w = img.naturalWidth, h = img.naturalHeight; if (!w || !h) return raw;
      const MAX = 2048; if (Math.max(w, h) > MAX) { const s = MAX / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
      const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
      cv.getContext('2d').drawImage(img, 0, 0, w, h);
      return cv.toDataURL('image/jpeg', 0.85);
    } catch (e) { return raw; }
  }
  async function onFiles(files) {
    const jobs = [];
    for (const f of files) {
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      const isPdf = f.type === 'application/pdf' || ext === 'pdf';
      const isData = /^(csv|xlsx|xls)$/.test(ext) || f.type === 'text/csv' || /spreadsheet|excel/i.test(f.type);
      // 画像は type だけでなく拡張子でも判定（iPhoneのHEIC等で type が空/非標準でも拾う）
      const isImg = f.type.startsWith('image/') || /^(png|jpe?g|gif|webp|heic|heif|bmp|tiff?|avif)$/.test(ext);
      if (!isPdf && !isImg && !isData) { toast('「' + f.name + '」は対応していない形式です'); continue; }
      const kind = isData ? 'data' : (isPdf ? 'pdf' : 'image');
      const att = { kind, name: f.name, dataUrl: '', reading: true };   // まず「読み込み中」で表示
      P.attachments.push(att);
      jobs.push((async () => {
        try { att.dataUrl = kind === 'image' ? await imageToDataUrl(f) : await readFile(f); att.reading = false; }
        catch (e) { att.reading = false; att.error = true; }
        renderChips();
      })());
    }
    renderChips();
    await Promise.all(jobs);
    renderChips();
  }
  const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; };
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.shiftKey || e.isComposing || e.keyCode === 229) return;
    if (window.matchMedia('(max-width:760px)').matches) return;   // スマホはEnter=改行（送信は➤ボタンのみ）
    e.preventDefault(); if (!P.streaming) doSend();               // PCはEnterで送信（生成中は止めない）
  });
  sendBtn.onclick = doSend;
  attach.onclick = () => fileIn.click();
  attach.style.display = S.tier.can_attach ? '' : 'none';   // 非会員も1日お試し枠があれば表示
  fileIn.onchange = () => { onFiles([...fileIn.files]); fileIn.value = ''; };
  // ドラッグ&ドロップで添付
  root.addEventListener('dragover', (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); root.classList.add('dragover'); } });
  root.addEventListener('dragleave', (e) => { if (e.target === root) root.classList.remove('dragover'); });
  root.addEventListener('drop', (e) => {
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    e.preventDefault(); root.classList.remove('dragover');
    if (P.perm === 'read') { toast('閲覧のみのチャットには添付できません'); return; }
    onFiles([...e.dataTransfer.files]);
  });
  pin.onclick = () => closeTab(P.convId || 0);
  del.onclick = () => { if (P.convId) delConv(P.convId); };
  edit.onclick = async () => {
    if (!P.convId) return;
    const nn = prompt('チャット名を変更', title.textContent); if (nn === null) return;
    const nt = nn.trim(); if (!nt) return;
    await post('conversation_rename', { id: P.convId, title: nt });
    title.textContent = nt;
    await loadConvs();
  };

  P.stop = () => { if (P.abort) P.abort.abort(); };
  P.setTitle = (t) => { title.textContent = t; };
  P.load = async () => {
    if (!P.convId) { P.perm = 'owner'; applyPerm(); showEmpty(); loadRefs(); return; }
    try {
      const { conversation, messages, perm } = await api(`conversation&id=${P.convId}`);
      P.perm = perm || 'owner'; applyPerm();
      title.textContent = conversation.title;
      threadEl.innerHTML = ''; inner();
      for (const m of messages) {
        const atts = [
          ...(m.images || []).map((u) => ({ kind: 'image', name: 'image', dataUrl: u })),
          ...(m.files || []).map((f) => ({ kind: f.kind, name: f.name })),
        ];
        addMsg(m.role, m.content, atts, m.id, m.author_name);
      }
      root.dataset.cid = P.convId || '';
      inner().querySelectorAll('.msg.assistant .body').forEach((b) => { bindCopy(b); addCopyBtn(b); addStockBtn(b); });
      const last = inner().querySelector('.msg:last-child');
      if (last && last.classList.contains('assistant')) addRegen(last.querySelector('.body'));
      else if (last && last.classList.contains('user') && !liveStreams[P.convId]) addResume();   // 回答が無い＝中断等 → 生成ボタン
      if (!messages.length) showEmpty();
      // まだ生成中のストリームがあれば、末尾に受け皿を作って接続（別チャットから戻った時のライブ反映）
      const live = liveStreams[P.convId];
      if (live && live.streamer) {
        const abody = addMsg('assistant', '');
        live.streamer.attach(abody);
        P.abort = live.ac; setStreaming(true);
        live.streamer.onDone(() => setStreaming(false));
      }
      loadRefs();
    } catch (e) { showEmpty(); }
  };
  P.focus = () => ta.focus();
  applyPerm();
  return P;
}
