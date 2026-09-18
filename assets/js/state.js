/* C:\Dropbox\Programs\Claude\chai\assets\js\state.js
   -> /var/www/chai/assets/js/state.js

   いまの画面の状態。 どのモジュールもここを見る。
*/

// ── グローバル状態 ───────────────────────────────────────
export const S = {
  tier: window.CHAI.tier,
  user: window.CHAI.user,
  convs: [],
  pins: [],        // 明示的にピン留めした会話id（横並び表示。最大5）
  cur: 0,          // 現在表示中の会話id（0=新規）。ピンに無くても表示される
  activeTab: 0,    // アクティブなタブ(フォーカス/狭い画面の表示)
  pinsets: [],     // 保存済みピンセット
  bookmarks: [],   // 「あとで見る」会話id（横並び表示とは別の保存リスト）
  todos: [],       // 未完TODO [{id, due}]（〆切付き）
  todosDone: [],   // 完了TODO [{id, due}]
  instant: localStorage.getItem('chai_instant') === '1',   // true=一気に表示 / false=徐々に(タイプライター)
};

export const liveStreams = {};   // 生成中のストリーム（convId → {streamer, ac}）。別チャットへ移動→戻っても継続表示
