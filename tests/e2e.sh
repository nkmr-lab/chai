#!/bin/bash
# C:\Dropbox\Programs\Claude\chai\tests\e2e.sh
#   → /var/www/chai/tests/e2e.sh
#
# chai のテスト一式。 php -S で「chai 本体」と「偽 OpenAI」を立てて、
# API を一通り叩き、 画面が出ることと SSE が流れることまで見る。
#   bash /var/www/chai/tests/e2e.sh
#
# 触るのは chai_test DB と偽 OpenAI だけ。 本番 DB と本物の OpenAI には繋がない。
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8897
STUB=8898
BASE="http://127.0.0.1:$PORT"
ID='{"email":"alice@test.local","name":"有栖川あゆみ","user":"alice"}'
OK=0; NG=0

pass() { OK=$((OK+1)); echo "  ok   $1"; }
fail() { NG=$((NG+1)); echo "  FAIL $1  <- $2"; }
check()    { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected=$2 actual=$3"; fi; }
contains() { if printf '%s' "$2" | grep -q -- "$3"; then pass "$1"; else fail "$1" "「$3」が出てこない"; fi; }

echo "[本番設定の抜け道が閉じているか]"
BACKDOOR=$(CHAI_CONFIG="$ROOT/config.local.php" php -r '$c=require getenv("CHAI_CONFIG"); echo trim((string)($c["auth"]["test_identity_header"] ?? ""));')
check "本番の config.local.php は test_identity_header が空" "" "$BACKDOOR"
REAL=$(CHAI_CONFIG="$ROOT/config.local.php" php -r '$c=require getenv("CHAI_CONFIG"); echo $c["openai"]["base_url"];')
contains "本番は本物の OpenAI を向いている" "$REAL" "api.openai.com"

echo
echo "[偽 OpenAI と chai を立てる]"
# 前回の残骸が港を塞いでいると、 偽サーバが上がらないまま「繋がらない」結果になる。
# それを素通りさせないよう、 先に掃除して、 立ったことを確かめてから進む。
for P in $STUB $PORT; do
  PIDS=$(ss -lptn "sport = :$P" 2>/dev/null | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
  [ -n "$PIDS" ] && kill $PIDS 2>/dev/null && sleep 0.5
done
php -S 127.0.0.1:$STUB "$ROOT/tests/stub_openai.php" >/tmp/chai_stub.log 2>&1 &
STUBPID=$!
CHAI_CONFIG="$ROOT/tests/config.test.php" php -S 127.0.0.1:$PORT -t "$ROOT" >/tmp/chai_app.log 2>&1 &
APPPID=$!
trap 'kill $STUBPID $APPPID 2>/dev/null' EXIT
for i in $(seq 1 40); do curl -s -o /dev/null "$BASE/api.php?action=state" && break; sleep 0.25; done
echo "  pid app=$APPPID stub=$STUBPID"
# 偽 OpenAI が本当に応えるか。 ここで落としておかないと、 繋がらないだけなのに
# 「返事が生成されませんでした」で素通りしてしまう。
STUBOK=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json'   -d '{"model":"x","input":[],"stream":false}' "http://127.0.0.1:$STUB/v1/responses")
check "偽 OpenAI が応える" "200" "$STUBOK"
if [ "$STUBOK" != "200" ]; then
  echo "  (港 $STUB が塞がっている可能性。 /tmp/chai_stub.log を見る)"
  cat /tmp/chai_stub.log
  exit 1
fi

echo
echo "[API 一式]"
CHAI_CONFIG="$ROOT/tests/config.test.php" CHAI_TEST_BASE="$BASE" php "$ROOT/tests/run_tests.php"
API_RC=$?
if [ $API_RC -eq 0 ]; then pass "API のテストが全部通った"; else fail "API のテスト" "run_tests.php が失敗 (上を見る)"; fi

echo
echo "[画面]"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
check "身元なしで / を開くと auth へ飛ばす" "302" "$CODE"
HTML=$(curl -s -H "X-Chai-Test-Identity: $ID" "$BASE/")
contains "ログイン済みなら画面が出る" "$HTML" "window.CHAI"
contains "自分の名前が埋まっている" "$HTML" "有栖川あゆみ"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/js/app.js")
check "app.js が配れる" "200" "$CODE"
# import される部品も配れること (1 つ欠けると画面が真っ白になる)
MODNG=0
for m in util embed api md state dom pane panes convs bookmarks todos pinsets tier stocks share feedback; do
  C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/js/$m.js")
  [ "$C" = "200" ] || { fail "$m.js が配れる" "http=$C"; MODNG=1; }
done
[ "$MODNG" = "0" ] && pass "16 個の部品が全部配れる"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/styles.css")
check "styles.css が配れる" "200" "$CODE"
HTML=$(curl -s -H "X-Chai-Test-Identity: $ID" "$BASE/?embed=1")
contains "埋め込みモードの印が付く" "$HTML" 'class="embed"'

echo
echo "[SSE (chat.php) が流れるか]"
CID=$(curl -s -X POST -H "X-Chai-Test-Identity: $ID" -H 'Content-Type: application/json' \
  -d '{"title":"SSE のテスト"}' "$BASE/api.php?action=conversation_create" | grep -oE '"id":[0-9]+' | grep -oE '[0-9]+')
SSE=$(curl -s -X POST -H "X-Chai-Test-Identity: $ID" -H 'Content-Type: application/json' \
  -d "{\"conversation_id\":$CID,\"content\":\"こんにちは\"}" "$BASE/chat.php")
# chai の SSE は event: 行を使わず、 data: の JSON の type で区別している
contains "meta が来る"  "$SSE" '"type":"meta"'
contains "delta が来る" "$SSE" '"type":"delta"'
contains "done が来る"  "$SSE" '"type":"done"'
contains "会話 id が返る" "$SSE" '"conversation_id"'
# meta のタイトルにも「こんにちは」が入るので、 delta の中身であることまで見る
contains "返事の中身が流れる" "$SSE" '"type":"delta","text":"こんにちは"'

SAVED=$(curl -s -H "X-Chai-Test-Identity: $ID" "$BASE/api.php?action=conversation&id=$CID")
contains "やりとりが会話に残る" "$SAVED" "テストの返事です"

# やり直し (regenerate): 直前の返事を捨てて作り直す
REGEN=$(curl -s -X POST -H "X-Chai-Test-Identity: $ID" -H 'Content-Type: application/json'   -d "{\"conversation_id\":$CID,\"content\":\"\",\"regenerate\":true}" "$BASE/chat.php")
contains "やり直しも流れる" "$REGEN" '"type":"done"'
NASST=$(curl -s -H "X-Chai-Test-Identity: $ID" "$BASE/api.php?action=conversation&id=$CID" | grep -o '"role":"assistant"' | wc -l)
check "やり直しても返事は 1 つのまま" "1" "$NASST"

# 画像生成 (道具呼び出し → media/ 保存 → markdown)
IMG=$(curl -s -X POST -H "X-Chai-Test-Identity: $ID" -H 'Content-Type: application/json'   -d "{\"conversation_id\":$CID,\"content\":\"画像を作って\"}" "$BASE/chat.php")
contains "画像を作っている途中経過が出る" "$IMG" '🎨'
contains "画像が markdown で返る" "$IMG" '](/media/'
GENURL=$(printf '%s' "$IMG" | grep -oE '/media/gen_[a-z0-9]+\.png' | head -1)
if [ -n "$GENURL" ] && [ -f "$ROOT$GENURL" ]; then
  pass "生成した画像が docroot の media/ に置かれる"
  rm -f "$ROOT$GENURL"
else
  fail "生成した画像が docroot の media/ に置かれる" "url=$GENURL (置き場所がずれていないか)"
fi

echo
echo "[php のエラーログが空か]"
ERRS=$(grep -iE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' /tmp/chai_app.log | grep -v 'Deprecated.*passing null' | head -5)
if [ -z "$ERRS" ]; then pass "PHP の警告が出ていない"; else fail "PHP の警告が出ていない" "$ERRS"; fi

echo
echo "----------------------------------------"
echo "ok $OK / ng $NG  (API のテストは上の行に別途)"
[ "$NG" -eq 0 ] && [ $API_RC -eq 0 ] || exit 1
echo "ぜんぶ通りました"
