/* chai.nkmr.io — フロント本体。Vanilla JS、外部依存なし。
   マルチペイン: ピンしたチャットを横に最大4つ / 狭い画面はタブ切替。各ペイン独立操作。 */
(() => {
  'use strict';
  const $  = (s, r = document) => r.querySelector(s);
  const el = (t, c) => { const e = document.createElement(t); if (c) e.className = c; return e; };
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // モデルが出すカール/全角クォートを ASCII に正規化（コードや貼り付けで壊れないように）
  const straightenQuotes = (s) => String(s)
    .replace(/[‘’‚‛′＇]/g, "'")
    .replace(/[“”„‟″＂]/g, '"');
  // コピー/ストック用に内部マーカーを平文へ（IMGPROMPTは全文を「」で、CODEは中身のみ）
  const plainRaw = (t) => straightenQuotes(String(t || '')
    .replace(/<<<IMGPROMPT>>>([\s\S]*?)<<<ENDIMGPROMPT>>>/g, (_, f) => '「' + f.trim() + '」')
    .replace(/<<<CODE>>>\n?/g, '').replace(/\n?<<<ENDCODE>>>/g, ''));
  function mathHTML(tex, display) {
    const t = tex.trim();
    if (window.katex) { try { return window.katex.renderToString(t, { displayMode: display, throwOnError: false, output: 'html' }); } catch (e) {} }
    return `<code class="tex-raw">${esc(t)}</code>`;
  }
  const safeSrc = (u) => /^(\/media\/|https:\/\/|data:image\/)/.test(u) ? u : '';
  const readFile = (f) => new Promise((res) => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(f); });

  const api = async (action, opts = {}) => {
    const r = await fetch(`/api.php?action=${action}`, { credentials: 'same-origin', ...opts });
    if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || r.status);
    return r.json();
  };
  const post = (action, body) => api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });

  // ── Markdown ─────────────────────────────────────────────
  function md(src) {
    const blocks = [];
    // 実行コードは折りたたみ（既定で閉じる）
    src = src.replace(/<<<CODE>>>\n?([\s\S]*?)\n?<<<ENDCODE>>>/g, (_, code) => {
      const i = blocks.length;
      blocks.push(`<details class="code-fold"><summary>🧮 実行したコード（クリックで表示）</summary><pre><button class="copy">コピー</button><code>${esc(straightenQuotes(code.replace(/\n$/, '')))}</code></pre></details>`);
      return `@@CB${i}@@`;
    });
    src = src.replace(/```(\w*)\n?([\s\S]*?)```/g, (_, lang, code) => {
      const i = blocks.length;
      blocks.push(`<pre><button class="copy">コピー</button><code>${esc(straightenQuotes(code.replace(/\n$/, '')))}</code></pre>`);
      return `@@CB${i}@@`;
    });
    // 画像生成プロンプト: 既定は小さな「🎨 プロンプト」トグルのみ。クリックで全文表示
    src = src.replace(/<<<IMGPROMPT>>>([\s\S]*?)<<<ENDIMGPROMPT>>>/g, (_, full) => {
      const i = blocks.length;
      const f = full.trim();
      blocks.push(`<span class="ip"><button type="button" class="ip-toggle" title="生成プロンプトを表示">🎨 プロンプト</button><span class="ip-full" hidden>${esc(f)}</span></span>`);
      return `@@CB${i}@@`;
    });
    // 数式（KaTeX）: コード同様に退避してから描画（esc/装飾の影響を受けない）
    src = src.replace(/\$\$([\s\S]+?)\$\$/g, (_, t) => { const i = blocks.length; blocks.push(mathHTML(t, true)); return `@@CB${i}@@`; });
    src = src.replace(/\\\[([\s\S]+?)\\\]/g, (_, t) => { const i = blocks.length; blocks.push(mathHTML(t, true)); return `@@CB${i}@@`; });
    src = src.replace(/\\\(([\s\S]+?)\\\)/g, (_, t) => { const i = blocks.length; blocks.push(mathHTML(t, false)); return `@@CB${i}@@`; });
    src = src.replace(/!?\[[^\]]*\]\(sandbox:[^)\s]*\)/g, '');   // 無効な sandbox リンク/画像を除去
    // 上で中身が消えて「- 」「1. 」だけ残った空の箇条書き行を除去（空アイテムが並ぶのを防ぐ）
    src = src.replace(/^[ \t]*(?:[-*]|\d+\.)[ \t]*$/gm, '');
    src = esc(src);
    src = src
      .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, alt, u) => { const s = safeSrc(u); return s ? `<img class="msg-img" alt="${alt}" src="${s}" loading="lazy">` : ''; })
      .replace(/`([^`\n]+)`/g, (_, c) => `<code>${straightenQuotes(c)}</code>`)
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/media\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    const out = []; let list = null;
    const closeList = () => { if (list) { out.push(list === 'ul' ? '</ul>' : '</ol>'); list = null; } };
    const cells = (l) => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim());
    const lines = src.split('\n');
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      let m;
      // 表（ヘッダ行 + 区切り行 + 本文行）
      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/.test(lines[i + 1])) {
        closeList();
        const head = cells(line); i++;
        const body = [];
        while (i + 1 < lines.length && /^\s*\|.*\|\s*$/.test(lines[i + 1])) { i++; body.push(cells(lines[i])); }
        let t = '<div class="table-wrap"><table><thead><tr>' + head.map((c) => `<th>${c}</th>`).join('') + '</tr></thead><tbody>';
        for (const r of body) t += '<tr>' + head.map((_, k) => `<td>${r[k] ?? ''}</td>`).join('') + '</tr>';
        out.push(t + '</tbody></table></div>');
        continue;
      }
      if ((m = line.match(/^###\s+(.*)/))) { closeList(); out.push(`<h3>${m[1]}</h3>`); }
      else if ((m = line.match(/^##\s+(.*)/))) { closeList(); out.push(`<h2>${m[1]}</h2>`); }
      else if ((m = line.match(/^#\s+(.*)/))) { closeList(); out.push(`<h1>${m[1]}</h1>`); }
      else if ((m = line.match(/^>\s?(.*)/))) { closeList(); out.push(`<blockquote>${m[1]}</blockquote>`); }
      else if ((m = line.match(/^\s*[-*]\s+(.*)/))) { if (list !== 'ul') { closeList(); list = 'ul'; out.push('<ul>'); } out.push(`<li>${m[1]}</li>`); }
      else if ((m = line.match(/^\s*\d+\.\s+(.*)/))) { if (list !== 'ol') { closeList(); list = 'ol'; out.push('<ol>'); } out.push(`<li>${m[1]}</li>`); }
      else if (line.trim() === '') { closeList(); }
      else { closeList(); out.push(`<p>${line}</p>`); }
    }
    closeList();
    return out.join('\n').replace(/@@CB(\d+)@@/g, (_, i) => blocks[+i] ?? '');
  }
  function bindCopy(root) {
    root.querySelectorAll('pre .copy').forEach((b) => b.onclick = () => {
      navigator.clipboard.writeText(b.nextElementSibling.textContent);
      b.textContent = 'コピー済'; setTimeout(() => (b.textContent = 'コピー'), 1200);
    });
  }
  // 画像生成プロンプトの「🎨 プロンプト」→ 全文の表示/非表示（委譲方式）
  document.addEventListener('click', (e) => {
    const b = e.target.closest && e.target.closest('.ip-toggle');
    if (!b) return;
    const full = b.parentElement.querySelector('.ip-full');
    if (full) full.hidden = !full.hidden;
  });

  // 画像の拡大表示（ライトボックス）＋ダウンロード
  function openLightbox(src) {
    const ov = el('div', 'lightbox');
    const bar = el('div', 'lb-bar');
    const dl = el('a', 'lb-dl'); dl.href = src; dl.setAttribute('download', ''); dl.textContent = '⬇ ダウンロード'; dl.onclick = (e) => e.stopPropagation();
    const cl = el('button', 'lb-close'); cl.textContent = '✕';
    bar.append(dl, cl);
    const img = el('img', 'lb-img'); img.src = src; img.onclick = (e) => e.stopPropagation();
    ov.append(bar, img);
    ov.onclick = () => ov.remove();
    const onKey = (e) => { if (e.key === 'Escape') { ov.remove(); document.removeEventListener('keydown', onKey); } };
    document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
  }
  document.addEventListener('click', (e) => {
    const im = e.target.closest && e.target.closest('.msg-img');
    if (im && im.src) { e.preventDefault(); openLightbox(im.src); }
  });

  // ── グローバル状態 ───────────────────────────────────────
  const S = {
    tier: window.CHAI.tier,
    user: window.CHAI.user,
    convs: [],
    pins: [],        // 明示的にピン留めした会話id（横並び表示。最大5）
    cur: 0,          // 現在表示中の会話id（0=新規）。ピンに無くても表示される
    activeTab: 0,    // アクティブなタブ(フォーカス/狭い画面の表示)
    pinsets: [],     // 保存済みピンセット
    bookmarks: [],   // 「あとで見る」会話id（横並び表示とは別の保存リスト）
    instant: localStorage.getItem('chai_instant') === '1',   // true=一気に表示 / false=徐々に(タイプライター)
  };
  let panes = [];    // 現在のペイン群
  const liveStreams = {};   // 生成中のストリーム（convId → {streamer, ac}）。別チャットへ移動→戻っても継続表示

  const app        = $('#app');
  const panesEl     = $('#panes');
  const convList   = $('#convList');
  const topbarTitle= $('#topbarTitle');

  // ── ペイン生成 ───────────────────────────────────────────
  function createPane(convId) {
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
    const refDock = el('div', 'ref-dock'); refDock.hidden = true;   // 参照ドック（上部固定表示）
    const threadEl = el('div', 'pane-thread');
    const chips = el('div', 'pane-chips');
    const comp = el('div', 'pane-composer');
    const attach = el('button', 'comp-btn'); attach.textContent = '📎'; attach.title = '画像・PDFを添付';
    const ta = el('textarea'); ta.rows = 1; ta.placeholder = 'メッセージを入力（Shift+Enterで改行）';
    const sendBtn = el('button', 'btn-send'); sendBtn.textContent = '➤'; sendBtn.title = '送信';
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
    function setStreaming(on) { P.streaming = on; sendBtn.classList.toggle('stopping', on); sendBtn.textContent = on ? '■' : '➤'; sendBtn.title = on ? '停止' : '送信'; }
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
            if (ev.type === 'meta') { P.convId = ev.conversation_id; root.dataset.cid = P.convId || ''; applyPerm(); if (P.convId) liveStreams[P.convId] = { streamer: stream, ac }; if (userMsgEl && ev.user_msg_id) addDelete(userMsgEl, ev.user_msg_id); }
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
      const text = ta.value.trim();
      if (!text && !P.attachments.length) return;
      if (S.tier.tier === 'free' && S.tier.message_cap > 0 && S.tier.remaining_window <= 0) { openPlan(`おためしは${S.tier.window_hours}時間あたり${S.tier.message_cap}通までです。メンバーになると無制限で使えます。`); return; }
      stick = true;   // 送信直後は下端に追従
      const atts = P.attachments.slice();
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
      P.attachments.forEach((a, i) => { const c = el('span', 'chip'); c.innerHTML = (a.kind === 'image' ? '🖼 ' : a.kind === 'data' ? '📊 ' : '📄 ') + esc(a.name); const x = el('span', 'chip-x'); x.textContent = '×'; x.onclick = () => { P.attachments.splice(i, 1); renderChips(); }; c.appendChild(x); chips.appendChild(c); });
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
      let added = 0, skipped = 0;
      for (const f of files) {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        const isPdf = f.type === 'application/pdf' || ext === 'pdf';
        const isData = /^(csv|xlsx|xls)$/.test(ext) || f.type === 'text/csv' || /spreadsheet|excel/i.test(f.type);
        // 画像は type だけでなく拡張子でも判定（iPhoneのHEIC等で type が空/非標準でも拾う）
        const isImg = f.type.startsWith('image/') || /^(png|jpe?g|gif|webp|heic|heif|bmp|tiff?|avif)$/.test(ext);
        if (!isPdf && !isImg && !isData) { skipped++; continue; }
        const kind = isData ? 'data' : (isPdf ? 'pdf' : 'image');
        try { P.attachments.push({ kind, name: f.name, dataUrl: kind === 'image' ? await imageToDataUrl(f) : await readFile(f) }); added++; }
        catch (e) { skipped++; }
      }
      renderChips();
      if (added) toast(added + '件を添付しました');
      if (skipped) toast(skipped + '件は対応していない形式でした');
    }
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; };
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); if (!P.streaming) doSend(); } });   // 生成中のEnterでは止めない（停止は■ボタンのみ）
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
        const last = inner().querySelector('.msg:last-child'); if (last && last.classList.contains('assistant')) addRegen(last.querySelector('.body'));
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

  const convTitle = (id) => (S.convs.find((c) => c.id === id) || {}).title || (id ? '（読み込み中）' : '新しいチャット');

  // ── ペイン描画（ピン=横並び / 狭い画面=タブ） ─────────────
  function paneIds() {
    // 表示 = ピン群 ＋（未ピンなら）現在表示中の会話。現在表示は必ず見えるようにする
    let ids = S.pins.filter((x) => x > 0);
    if (!ids.includes(S.cur)) { if (ids.length >= 5) ids = ids.slice(0, 4); ids.push(S.cur); }
    return ids.slice(0, 5);
  }
  function renderPanes() {
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
  }
  function setActive(i) {
    S.activeTab = i;
    panes.forEach((p, idx) => p.el.classList.toggle('active', idx === i));
    [...panesEl.querySelectorAll('.pane-tab')].forEach((b, idx) => b.classList.toggle('active', idx === i));
  }

  // ── サイドバー ───────────────────────────────────────────
  function convItem(c, shared) {
    const shown = isShown(c.id);
    const pinned = isPinned(c.id);
    const it = el('div', 'conv-item' + (shown ? ' active' : ''));
    const t = el('span', 't'); t.textContent = (shared ? '👥 ' : '') + (c.title || '無題');
    t.title = shared ? ('共有元: ' + (c.owner_email || '') + '（' + (c.perm === 'write' ? '書き込み可' : '閲覧のみ') + '）') : (c.title || '無題');
    const marked = S.bookmarks.includes(c.id);
    const bm = el('span', 'bm' + (marked ? ' on' : '')); bm.textContent = marked ? '🔖' : '🏷';
    bm.title = marked ? 'あとで見るから外す' : 'あとで見るに追加';
    bm.onclick = (e) => { e.stopPropagation(); toggleBookmark(c.id); };
    const pin = el('span', 'pin' + (pinned ? ' on' : '')); pin.textContent = '📌';
    pin.title = pinned ? 'ピンを外す' : 'ピン留めして横に並べる';
    pin.onclick = (e) => { e.stopPropagation(); togglePin(c.id); };
    it.append(t, bm, pin);
    it.onclick = () => viewChat(c.id);   // 選択＝表示の切り替え（ピンは増やさない）
    return it;
  }
  const isShown = (id) => paneIds().includes(id);
  const isPinned = (id) => S.pins.includes(id);
  function renderConvList() {
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
  function saveOpen() { localStorage.setItem('chai_pins', JSON.stringify(S.pins)); localStorage.setItem('chai_cur', String(S.cur)); }
  function syncActive(id) { const ids = paneIds(); S.activeTab = Math.max(0, ids.indexOf(id)); }
  // 選択＝表示切り替え。ピン集合は変えない（未ピンでも現在表示として必ず見える）
  function openChat(id) {
    S.cur = id;
    if (id) { try { history.replaceState(null, '', '#c=' + id); } catch (e) {} }
    syncActive(id); saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
  }
  const viewChat = openChat;
  // 📌＝明示ピンのオン/オフ（横に並べる集合の増減）
  function togglePin(id) {
    if (!id) return;
    if (S.pins.includes(id)) { S.pins = S.pins.filter((x) => x !== id); }
    else { if (S.pins.length >= 5) { alert('横に並べられるピンは最大5件です。'); return; } S.pins.push(id); }
    syncActive(S.cur); saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
  }
  function newConv() {
    S.cur = 0; syncActive(0);
    saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
  }
  // ペイン列を閉じる（ピンなら外す。現在表示ならほかの表示へ移す）
  function closeTab(id) {
    S.pins = S.pins.filter((x) => x !== id);
    if (S.cur === id) S.cur = S.pins.length ? S.pins[S.pins.length - 1] : 0;
    const ids = paneIds(); if (S.activeTab >= ids.length) S.activeTab = Math.max(0, ids.length - 1);
    saveOpen(); renderConvList(); renderPanes();
  }

  async function loadConvs() {
    const { conversations } = await api('conversations');
    const seen = new Set();
    S.convs = (conversations || []).filter((c) => !seen.has(c.id) && seen.add(c.id));   // 会話idの二重表示を防ぐ
    // 削除済みidをピン/現在表示から除去（0=新規は残す）
    const ids = new Set(S.convs.map((c) => c.id));
    S.pins = S.pins.filter((x) => ids.has(x));
    if (S.cur && !ids.has(S.cur)) S.cur = S.pins.length ? S.pins[0] : 0;
    renderConvList();
    renderBookmarks();   // タイトル最新化・削除済みを除外
    // ペインのタイトルを最新化（再描画はしない）
    panes.forEach((p) => { if (p.convId) p.setTitle(convTitle(p.convId)); });
    const pids = paneIds();
    [...panesEl.querySelectorAll('.pane-tab')].forEach((b, i) => { const id = pids[i]; b.textContent = id ? convTitle(id) : '新しいチャット'; });
  }
  // ── ブックマーク（あとで見る） ───────────────────────────
  async function loadBookmarks() { try { const { ids } = await api('bookmarks'); S.bookmarks = ids || []; renderBookmarks(); } catch (e) {} }
  function renderBookmarks() {
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
  async function toggleBookmark(id) {
    if (!id) return;
    const on = S.bookmarks.includes(id);
    S.bookmarks = on ? S.bookmarks.filter((x) => x !== id) : [id, ...S.bookmarks];   // 即時反映
    renderBookmarks(); renderConvList();
    try { await post(on ? 'bookmark_remove' : 'bookmark_add', { conversation_id: id }); }
    catch (e) { loadBookmarks(); }
  }
  // ── ピンセット（名前付きのピン集合） ─────────────────────
  async function loadPinsets() { try { const { pinsets } = await api('pinsets'); S.pinsets = pinsets || []; renderPinsets(); } catch (e) {} }
  function renderPinsets() {
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
  const pinsetModal = $('#pinsetModal');
  let editingPinsetId = null;
  function openPinsetEditor(id) { editingPinsetId = id; renderPinsetEditor(); pinsetModal.hidden = false; }
  async function savePinsetMembers(ps, ids) {
    ids = ids.filter((x) => x > 0);
    if (!ids.length) { alert('ピンセットを空にはできません。セットごと消す場合は × を使ってください。'); return false; }
    try { await post('pinset_save', { name: ps.name, chat_ids: ids }); await loadPinsets(); return true; }
    catch (e) { alert('保存に失敗しました'); return false; }
  }
  function renderPinsetEditor() {
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
  async function savePinset() {
    const ids = paneIds().filter((x) => x > 0);
    if (!ids.length) { alert('保存できるチャットがありません（表示中のチャットが空です）。'); return; }
    const name = prompt('ピンセット名を付けて保存', ''); if (name === null) return;
    const nm = name.trim(); if (!nm) return;
    try { await post('pinset_save', { name: nm, chat_ids: ids }); await loadPinsets(); } catch (e) { alert('保存に失敗しました'); }
  }
  function openSet(ids) {
    const list = (ids || []).filter((x) => Number.isInteger(x) && x > 0).slice(0, 5);
    S.pins = list; S.cur = list[0] || 0;
    S.activeTab = 0; saveOpen(); renderConvList(); renderPanes(); closeSidebarMobile();
  }
  async function deletePinset(id, name) {
    if (!confirm('ピンセット「' + name + '」を削除しますか？')) return;
    try { await post('pinset_delete', { id }); await loadPinsets(); } catch (e) {}
  }

  async function renameConv(c) {
    const nn = prompt('チャット名を変更', c.title || ''); if (nn === null) return;
    const title = nn.trim(); if (!title) return;
    await post('conversation_rename', { id: c.id, title }); await loadConvs();
  }
  async function delConv(id) {
    if (!confirm('この会話を削除しますか？')) return;
    await post('conversation_delete', { id });
    S.pins = S.pins.filter((x) => x !== id); if (S.cur === id) S.cur = S.pins.length ? S.pins[0] : 0;
    saveOpen(); await loadConvs(); renderPanes();
  }

  // ── ティア表示 / プラン ──────────────────────────────────
  function renderTier() {
    const t = S.tier, badge = $('#tierBadge');
    badge.className = 'tier-badge ' + t.tier; badge.textContent = t.tier === 'pro' ? '● メンバー' : 'おためし（LabPayで契約）';
    badge.style.cursor = 'pointer';
    badge.title = t.tier === 'pro' ? 'プランを見る' : 'クリックして LabPay でメンバー契約';
    badge.onclick = () => (t.tier === 'pro' ? openPlan('') : window.open(t.subscribe_url, '_blank', 'noopener'));
    $('#userName').textContent = S.user.name || S.user.email;
    const note = $('#tierNote');
    if (t.tier === 'free' && t.message_cap > 0) note.textContent = `あと ${t.remaining_window} 通（${t.window_hours}時間ごと・画像/ファイルは各1回）`;
    else if (t.tier === 'pro' && t.sub_status === 'graceful') note.textContent = `解約予約中・あと ${t.days_left} 日`;
    else note.textContent = '';
  }
  async function refreshState() { try { const { tier } = await api('state'); S.tier = tier; renderTier(); } catch (e) {} }

  const planModal = $('#planModal');
  function openPlan(msg) { renderPlan(msg || ''); planModal.hidden = false; }
  function renderPlan(msg) {
    const t = S.tier, body = $('#planBody');
    const expires = t.expires_at ? new Date(t.expires_at.replace(' ', 'T')).toLocaleString('ja-JP') : null;
    let sl = '';
    if (t.tier === 'pro') sl = t.sub_status === 'graceful' ? `<div class="plan-sub">解約予約中：${expires} まで（あと ${t.days_left} 日）</div>` : `<div class="plan-sub">✓ 契約中：${expires} まで（あと ${t.days_left} 日）</div>`;
    const needLogin = t.sub_status === 'unauth';
    body.innerHTML = `
      <div class="plan-card ${t.tier === 'pro' ? 'current' : ''}">
        <h3>メンバー <span class="plan-price">${t.price_points}pt<small style="font-size:12px;font-weight:400">/${t.period_days}日</small></span></h3>
        <ul><li>最新モデル（GPT-5.6 / o3）＋回数無制限・長い文脈</li><li>ファイル読込・Web検索・画像生成・データ分析(CSV/Excel)</li><li>複数チャットの比較ペイン</li></ul>
        ${t.tier === 'pro' ? sl + `<button class="plan-btn" id="manageBtn">LabPay で契約を管理</button>` : `<button class="plan-btn" id="subBtn">${needLogin ? 'LabPay にログインして契約する' : `LabPay で契約する（${t.price_points}pt/週）`}</button>`}
      </div>
      <div class="plan-card"><h3>おためし</h3><ul><li>最新モデルで会話（${t.window_hours}時間ごと${t.message_cap}通）</li><li>画像生成・ファイル読込は${t.window_hours}時間に各1回お試し／Web検索は不可</li></ul></div>
      <button class="plan-recheck" id="recheckBtn">契約したら再確認</button>
      <div class="plan-msg" id="planMsg">${esc(msg)}</div>
      <div class="plan-sub" style="text-align:center;margin-top:6px">契約・解約・自動更新は LabPay で行います</div>`;
    const open = () => window.open(t.subscribe_url, '_blank', 'noopener');
    const sub = $('#subBtn'); if (sub) sub.onclick = open;
    const man = $('#manageBtn'); if (man) man.onclick = open;
    $('#recheckBtn').onclick = async () => { planMsg('契約状況を確認しています…'); try { const { tier } = await post('recheck'); S.tier = tier; renderTier(); renderPlan(tier.active ? '✅ メンバーになりました。' : '契約は確認できませんでした。'); renderPanes(); } catch (e) { planMsg('確認に失敗しました', true); } };
  }
  const planMsg = (m, err) => { const e = $('#planMsg'); if (e) { e.textContent = m; e.className = 'plan-msg' + (err ? ' err' : ''); } };

  // ── ストック（切り抜き保存） ─────────────────────────────
  function toast(msg) {
    const t = el('div', 'toast'); t.textContent = msg; document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 1600);
  }
  async function saveStock(text, convId, mid) {
    try { await post('stock_save', { text, conversation_id: convId || null, source_msg_id: mid || null }); toast('⭐ ストックに保存しました'); return true; }
    catch (e) { toast('保存に失敗しました'); return false; }
  }
  const stockModal = $('#stockModal');
  async function openStock() { await loadStock(); stockModal.hidden = false; }
  async function loadStock() { try { const { stocks } = await api('stocks'); renderStock(stocks || []); } catch (e) {} }
  function renderStock(items) {
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
  let selBtn = null;
  const removeSelBtn = () => { if (selBtn) { selBtn.remove(); selBtn = null; } };
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
  const shareModal = $('#shareModal');
  let shareConvId = 0;
  let rosterCache = null; // [{user,name,kana,grade}]
  async function loadRoster() {
    if (rosterCache) return rosterCache;
    try {
      const r = await fetch('https://auth.nkmr.io/?action=roster', { credentials: 'include' });
      const j = await r.json();
      rosterCache = Array.isArray(j.users) ? j.users : [];
    } catch (e) { rosterCache = []; }
    return rosterCache;
  }
  async function openShare(convId) { if (!convId) return; shareConvId = convId; await loadRoster(); await loadShares(); shareModal.hidden = false; }
  async function loadShares() { try { const { shares } = await api('shares_list&id=' + shareConvId); renderShares(shares || []); } catch (e) { renderShares([]); } }
  function renderShares(shares) {
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
  async function addShare(everyone) {
    const perm = ($('#sharePerm') && $('#sharePerm').value) || 'read';
    const who = everyone ? '*' : (($('#shareWho') && $('#shareWho').value.trim()) || '');
    if (!who) return;
    try { await post('share_add', { conversation_id: shareConvId, grantee: who, permission: perm, everyone: who === '*' }); if ($('#shareWho')) $('#shareWho').value = ''; toast('共有しました'); loadShares(); await loadConvs(); }
    catch (e) { toast('共有に失敗しました'); }
  }

  // ── 要望・不具合 ─────────────────────────────────────────
  const fbModal = $('#fbModal');
  const FB_STATUS = { new: '受付', planned: '予定', doing: '対応中', done: '対応済', declined: '見送り' };
  async function openFeedback() { await loadFeedback(); fbModal.hidden = false; }
  async function loadFeedback() { try { const r = await api('feedback_list'); renderFeedback(r.feedback || [], !!r.is_admin); } catch (e) { renderFeedback([], false); } }
  function renderFeedback(items, isAdmin) {
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
  async function submitFeedback() {
    const text = ($('#fbText') && $('#fbText').value.trim()) || '';
    const kind = ($('#fbKind') && $('#fbKind').value) || 'request';
    if (!text) return;
    try { await post('feedback_submit', { text, kind }); toast('送信しました。ありがとうございます'); loadFeedback(); }
    catch (e) { toast('送信に失敗しました'); }
  }

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
  const closeSidebarMobile = () => app.classList.remove('side-open');
  // サイドバーを開いている時、外側（背景）タップで閉じる
  app.addEventListener('click', (e) => {
    if (app.classList.contains('side-open') && !e.target.closest('#sidebar') && !e.target.closest('#btnMenu')) closeSidebarMobile();
  });

  // 表示のしかた切替（一気に / 徐々に）
  const speedBtn = $('#btnSpeed');
  const renderSpeedBtn = () => { if (speedBtn) { speedBtn.textContent = S.instant ? '⚡ 一気に' : '✍️ 徐々に'; speedBtn.title = S.instant ? '今: 一気に表示（押すと徐々に）' : '今: 徐々に表示（押すと一気に）'; } };
  if (speedBtn) speedBtn.onclick = () => { S.instant = !S.instant; localStorage.setItem('chai_instant', S.instant ? '1' : '0'); renderSpeedBtn(); toast(S.instant ? '一気に表示にしました' : '徐々に表示にしました'); };
  renderSpeedBtn();

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
    app.setAttribute('aria-busy', 'false');
  })();
})();
