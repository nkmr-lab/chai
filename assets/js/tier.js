/* C:\Dropbox\Programs\Claude\chai\assets\js\tier.js
   -> /var/www/chai/assets/js/tier.js

   ティア表示とプラン (契約の案内)。
*/
import { api, post } from './api.js';
import { renderPanes } from './panes.js';
import { S } from './state.js';
import { $, esc } from './util.js';

// ── ティア表示 / プラン ──────────────────────────────────
export function renderTier() {
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

export async function refreshState() { try { const { tier } = await api('state'); S.tier = tier; renderTier(); } catch (e) {} }

export const planModal = $('#planModal');

export function openPlan(msg) { renderPlan(msg || ''); planModal.hidden = false; }

export function renderPlan(msg) {
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

export const planMsg = (m, err) => { const e = $('#planMsg'); if (e) { e.textContent = m; e.className = 'plan-msg' + (err ? ' err' : ''); } };

// ── ストック（切り抜き保存） ─────────────────────────────
