/* C:\Dropbox\Programs\Claude\chai\assets\js\md.js
   -> /var/www/chai/assets/js/md.js

   返事の見せ方 (markdown / 数式 / コードの複写 / 画像の拡大)。
*/
import { S } from './state.js';
import { $, el, esc, safeSrc, straightenQuotes } from './util.js';

export function mathHTML(tex, display) {
  const t = tex.trim();
  if (window.katex) { try { return window.katex.renderToString(t, { displayMode: display, throwOnError: false, output: 'html' }); } catch (e) {} }
  return `<code class="tex-raw">${esc(t)}</code>`;
}

// ── Markdown ─────────────────────────────────────────────
export function md(src) {
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

export function bindCopy(root) {
  root.querySelectorAll('pre .copy').forEach((b) => b.onclick = () => {
    navigator.clipboard.writeText(b.nextElementSibling.textContent);
    b.textContent = 'コピー済'; setTimeout(() => (b.textContent = 'コピー'), 1200);
  });
}
// 画像生成プロンプトの「🎨 プロンプト」→ 全文の表示/非表示（委譲方式）

// 画像の拡大表示（ライトボックス）＋ダウンロード
export function openLightbox(src) {
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

// 画像生成プロンプトの「🎨 プロンプト」→ 全文の表示/非表示（委譲方式）
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('.ip-toggle');
  if (!b) return;
  const full = b.parentElement.querySelector('.ip-full');
  if (full) full.hidden = !full.hidden;
});

// 画像の拡大表示（ライトボックス）＋ダウンロード

document.addEventListener('click', (e) => {
  const im = e.target.closest && e.target.closest('.msg-img');
  if (im && im.src) { e.preventDefault(); openLightbox(im.src); }
});

// ── グローバル状態 ───────────────────────────────────────
