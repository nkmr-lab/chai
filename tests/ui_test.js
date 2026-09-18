/* C:\Dropbox\Programs\Claude\chai\tests\ui_test.js
   → 手元で回す画面のテスト (サーバには置かない)

   e2e.sh は「API と SSE が流れるか」までしか見ない。 押したら何が起きるかはこれで確かめる。
   jsdom にブラウザの世界を作り、 本番と同じ assets/ の JS をそのまま読み込み、
   通信だけ偽物に差し替える (/api.php と /chat.php の両方)。

   骨組み (HTML) は index.php から読む。 テストに書き写すと本物とずれるので。

   使い方:
     NODE_PATH=<jsdom を入れた場所>/node_modules node tests/ui_test.js
*/
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { pathToFileURL } = require('url');

const ROOT = path.resolve(__dirname, '..');

let OK = 0, NG = 0;
const fails = [];
const ok = (cond, what, detail) => {
  if (cond) { OK++; console.log('  ok   ' + what); }
  else { NG++; fails.push(what); console.log('  FAIL ' + what + (detail ? '  <- ' + detail : '')); }
};
const eq = (a, b, what) => ok(JSON.stringify(a) === JSON.stringify(b), what,
  'expected=' + JSON.stringify(a) + ' actual=' + JSON.stringify(b));
const section = (s) => console.log('\n[' + s + ']');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

// ─── 骨組みは本物 (index.php) から ───────────────────────
function shellHtml() {
  let s = fs.readFileSync(path.join(ROOT, 'index.php'), 'utf8');
  s = s.replace(/<\?php[\s\S]*?\?>/g, '');
  s = s.replace(/<\?=\s*\$ver\s*\?>/g, '99');
  s = s.replace(/<\?=[\s\S]*?\?>/g, '');
  s = s.replace(/<script[\s\S]*?<\/script>/g, '');   // JS はこちらで読む
  s = s.replace(/<link[^>]*>/g, '');                 // CSS と外部フォントは要らない
  return s;
}

// ─── 偽サーバ ────────────────────────────────────────────
function makeServer() {
  const state = {
    calls: [],
    convs: [
      { id: 1, title: '最初の会話', category: '研究', model: null, updated_at: '2026-09-18 10:00:00', owned: 1, perm: 'owner' },
      { id: 2, title: '共有された会話', category: null, model: null, updated_at: '2026-09-17 10:00:00', owned: 0, owner_email: 'bob@test.local', perm: 'read' },
    ],
    messages: {
      1: [
        { id: 11, role: 'user', content: 'これは質問', author_email: 'alice@test.local', author_name: '有栖川あゆみ', created_at: '2026-09-18 10:00:00', images: [], files: [] },
        { id: 12, role: 'assistant', content: '**太字**の返事と `コード`', created_at: '2026-09-18 10:00:01', images: [], files: [] },
      ],
      2: [],
    },
    pinsets: [{ id: 5, name: '朝の定位置', chat_ids: [1] }],
    bookmarks: [1],
    todos: { todos: [{ id: 1, due: '2026-10-01' }], done: [] },
    stocks: [],
  };

  const json = (b, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => b });

  const fetchImpl = async (url, opt = {}) => {
    const u = String(url);
    const body = opt && opt.body ? JSON.parse(opt.body) : null;
    state.calls.push({ url: u, method: (opt.method || 'GET').toUpperCase(), body });

    // 返事の生成 (SSE)。 本物と同じく body を少しずつ読ませる。
    if (u.startsWith('/chat.php')) {
      const frames = [
        { type: 'meta', conversation_id: body.conversation_id || 3, title: '新しいチャット', tier: 'free', model: 'gpt-5.6-luna', user_msg_id: 21 },
        { type: 'status', text: '🧠 考えています…' },
        { type: 'delta', text: 'こんにちは' },
        { type: 'delta', text: '。テストの返事です。' },
        { type: 'done', conversation_id: body.conversation_id || 3, title: 'テスト', model: 'gpt-5.6-luna', msg_id: 22 },
      ];
      const text = frames.map(f => 'data: ' + JSON.stringify(f) + '\n\n').join('');
      const bytes = new TextEncoder().encode(text);
      let sent = false;
      return {
        ok: true, status: 200,
        body: { getReader: () => ({ read: async () => sent ? { done: true } : (sent = true, { done: false, value: bytes }) }) },
      };
    }

    const m = u.match(/action=([a-z_]+)/);
    const action = m ? m[1] : '';
    switch (action) {
      case 'state':        return json({ user: { email: 'alice@test.local', name: '有栖川あゆみ', user: 'alice' },
                                        tier: { tier: 'free', label: 'おためし', active: false }, is_admin: false });
      case 'conversations':return json({ conversations: JSON.parse(JSON.stringify(state.convs)) });
      case 'conversation': {
        const id = Number((u.match(/[?&]id=(\d+)/) || [])[1] || 0);
        const conv = state.convs.find(c => c.id === id);
        if (!conv) return json({ error: 'not_found' }, 404);
        return json({ conversation: conv, perm: conv.perm, messages: state.messages[id] || [] });
      }
      case 'conversation_create': {
        const id = 3; state.convs.unshift({ id, title: body.title || '新しいチャット', owned: 1, perm: 'owner', updated_at: '2026-09-18 11:00:00' });
        state.messages[id] = [];
        return json({ id, title: body.title || '新しいチャット' });
      }
      case 'pinsets':      return json({ pinsets: JSON.parse(JSON.stringify(state.pinsets)) });
      case 'pinset_save':  state.pinsets.push({ id: 6, name: body.name, chat_ids: body.chat_ids }); return json({ ok: true });
      case 'pinset_delete':state.pinsets = state.pinsets.filter(p => p.id !== body.id); return json({ ok: true });
      case 'bookmarks':    return json({ ids: state.bookmarks.slice() });
      case 'bookmark_add': state.bookmarks.push(body.conversation_id); return json({ ok: true });
      case 'bookmark_remove': state.bookmarks = state.bookmarks.filter(x => x !== body.conversation_id); return json({ ok: true });
      case 'todos':        return json(JSON.parse(JSON.stringify(state.todos)));
      case 'stocks':       return json({ stocks: state.stocks.slice() });
      case 'feedback_list':return json({ is_admin: false, feedback: [] });
      default:             return json({ ok: true });
    }
  };
  return { state, fetchImpl };
}

async function boot() {
  const { state, fetchImpl } = makeServer();
  const dom = new JSDOM(shellHtml(), { url: 'https://chai.nkmr.io/', pretendToBeVisual: true, runScripts: 'outside-only' });
  const w = dom.window;
  w.CHAI = {
    user: { email: 'alice@test.local', name: '有栖川あゆみ', user: 'alice' },
    tier: { tier: 'free', label: 'おためし', active: false, models: [] },
    version: 99,
  };
  w.fetch = fetchImpl;
  w.confirm = () => true;
  w.alert = () => {};
  w.scrollTo = () => {};
  if (!w.TextEncoder) w.TextEncoder = TextEncoder;
  if (!w.TextDecoder) w.TextDecoder = TextDecoder;
  Object.defineProperty(w.HTMLElement.prototype, 'scrollIntoView', { value() {}, configurable: true });

  // モジュールは素の window / document を使うので、 node の global に置く
  for (const k of ['window', 'document', 'navigator', 'location', 'localStorage',
                   'fetch', 'requestAnimationFrame', 'cancelAnimationFrame', 'Event', 'CustomEvent',
                   'matchMedia', 'confirm', 'alert', 'prompt', 'history', 'getComputedStyle',
                   'HTMLElement', 'Node', 'FileReader', 'Blob', 'URL', 'scrollTo', 'katex']) {
    const v = k === 'window' ? w : w[k];
    try { globalThis[k] = v; }
    catch (_) { Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true }); }
  }
  await import(pathToFileURL(path.join(ROOT, 'assets/js/app.js')).href);
  await sleep(250);
  return { w, doc: w.document, state };
}

(async () => {
  section('起動');
  const { w, doc, state } = await boot();
  ok(doc.querySelectorAll('#convList .conv-item').length >= 2, '会話の一覧が出る',
     'count=' + doc.querySelectorAll('#convList .conv-item').length);
  ok(!!doc.querySelector('#panes .pane'), '面ができている');
  ok(!!doc.querySelector('#panes .pane-composer textarea'), '入力欄がある');
  ok(state.calls.some(c => /action=conversations/.test(c.url)), '会話一覧を取りに行っている');

  section('会話を開く');
  const item = doc.querySelector('#convList .conv-item');
  item.dispatchEvent(new w.Event('click', { bubbles: true }));
  await sleep(150);
  const thread = doc.querySelector('#panes .pane-thread');
  ok(/これは質問/.test(thread.textContent), '前のやりとりが出る');
  ok(!!thread.querySelector('strong'), 'markdown の太字が効く');
  ok(!!thread.querySelector('code'), 'markdown のコードが効く');

  section('送信');
  const ta = doc.querySelector('#panes .pane-composer textarea');
  ta.value = 'テストの質問';
  ta.dispatchEvent(new w.Event('input', { bubbles: true }));
  doc.querySelector('#panes .btn-send').dispatchEvent(new w.Event('click', { bubbles: true }));
  await sleep(300);
  ok(state.calls.some(c => c.url.startsWith('/chat.php')), 'chat.php に投げている');
  const sent = state.calls.find(c => c.url.startsWith('/chat.php'));
  ok(sent && sent.body.content === 'テストの質問', '入力した文が渡る', JSON.stringify(sent && sent.body));
  ok(/こんにちは。テストの返事です。/.test(doc.querySelector('#panes .pane-thread').textContent),
     '流れてきた返事が画面に出る');
  eq('', ta.value, '送ったら入力欄が空になる');

  section('新しいチャット');
  doc.querySelector('#btnNew').dispatchEvent(new w.Event('click', { bubbles: true }));
  await sleep(150);
  ok(!!doc.querySelector('#panes .pane'), '新しい面が出る');

  section('ピン留めとピンセット');
  ok(doc.querySelectorAll('#pinsets .pinset-item').length >= 1, 'ピンセットが出る');
  const pin = doc.querySelector('#convList .conv-item .pin');
  ok(!!pin, '会話の行に 📌 がある');
  pin.dispatchEvent(new w.Event('click', { bubbles: true }));
  await sleep(150);
  ok(doc.querySelectorAll('#panes .pane').length >= 1, '📌 で面が並ぶ',
     'panes=' + doc.querySelectorAll('#panes .pane').length);

  section('あとで見る / TODO');
  ok(!!doc.querySelector('#bookmarks'), 'あとで見るの欄がある');
  ok(/最初の会話/.test(doc.querySelector('#bookmarks').textContent + doc.querySelector('#todos').textContent),
     'ブックマークか TODO に会話名が出る');

  console.log('\n' + '─'.repeat(50));
  console.log(`ok ${OK} / ng ${NG}`);
  if (NG) { console.log('落ちたもの:\n  - ' + fails.join('\n  - ')); process.exit(1); }
  console.log('ぜんぶ通りました');
  process.exit(0);
})().catch(e => { console.error('テストが落ちました:', e); process.exit(1); });
