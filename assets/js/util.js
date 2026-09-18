/* C:\Dropbox\Programs\Claude\chai\assets\js\util.js
   -> /var/www/chai/assets/js/util.js

   小さな道具。 DOM の取り出し、 逃がし、 引用符の正規化、 ファイル読み。
*/
import { S } from './state.js';

export const $  = (s, r = document) => r.querySelector(s);
// chat.nkmr.io から iframe で並べられている時は embed=1 が付く。
// サイドバーを畳み、 開いている会話を親に知らせ、 ?q= があれば入力欄に入れておく。

export const el = (t, c) => { const e = document.createElement(t); if (c) e.className = c; return e; };

export const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
// モデルが出すカール/全角クォートを ASCII に正規化（コードや貼り付けで壊れないように）

// モデルが出すカール/全角クォートを ASCII に正規化（コードや貼り付けで壊れないように）
export const straightenQuotes = (s) => String(s)
  .replace(/[‘’‚‛′＇]/g, "'")
  .replace(/[“”„‟″＂]/g, '"');
// コピー/ストック用に内部マーカーを平文へ（IMGPROMPTは全文を「」で、CODEは中身のみ）

// コピー/ストック用に内部マーカーを平文へ（IMGPROMPTは全文を「」で、CODEは中身のみ）
export const plainRaw = (t) => straightenQuotes(String(t || '')
  .replace(/<<<IMGPROMPT>>>([\s\S]*?)<<<ENDIMGPROMPT>>>/g, (_, f) => '「' + f.trim() + '」')
  .replace(/<<<CODE>>>\n?/g, '').replace(/\n?<<<ENDCODE>>>/g, ''));

export const safeSrc = (u) => /^(\/media\/|https:\/\/|data:image\/)/.test(u) ? u : '';

export const readFile = (f) => new Promise((res) => { const r = new FileReader(); r.onload = () => res(r.result); r.readAsDataURL(f); });

// ── ストック（切り抜き保存） ─────────────────────────────
export function toast(msg) {
  const t = el('div', 'toast'); t.textContent = msg; document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 1600);
}
