/* C:\Dropbox\Programs\Claude\chai\assets\js\embed.js
   -> /var/www/chai/assets/js/embed.js

   chat.nkmr.io の面として並べられている時の振る舞い。
*/

// chat.nkmr.io から iframe で並べられている時は embed=1 が付く。
// サイドバーを畳み、 開いている会話を親に知らせ、 ?q= があれば入力欄に入れておく。
export const EMBED = new URLSearchParams(location.search).get('embed') === '1';

export const tellParent = (msg) => { if (EMBED) { try { parent.postMessage(msg, 'https://chat.nkmr.io'); } catch (e) {} } };
