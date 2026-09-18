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
php -S 127.0.0.1:$STUB "$ROOT/tests/stub_openai.php" >/tmp/chai_stub.log 2>&1 &
STUBPID=$!
CHAI_CONFIG="$ROOT/tests/config.test.php" php -S 127.0.0.1:$PORT -t "$ROOT" >/tmp/chai_app.log 2>&1 &
APPPID=$!
trap 'kill $STUBPID $APPPID 2>/dev/null' EXIT
for i in $(seq 1 40); do curl -s -o /dev/null "$BASE/api.php?action=state" && break; sleep 0.25; done
echo "  pid app=$APPPID stub=$STUBPID"

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
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/app.js")
check "app.js が配れる" "200" "$CODE"
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
contains "返事の中身が流れる" "$SSE" "こんにちは"

SAVED=$(curl -s -H "X-Chai-Test-Identity: $ID" "$BASE/api.php?action=conversation&id=$CID")
contains "やりとりが会話に残る" "$SAVED" "テストの返事です"

echo
echo "[php のエラーログが空か]"
ERRS=$(grep -iE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' /tmp/chai_app.log | grep -v 'Deprecated.*passing null' | head -5)
if [ -z "$ERRS" ]; then pass "PHP の警告が出ていない"; else fail "PHP の警告が出ていない" "$ERRS"; fi

echo
echo "----------------------------------------"
echo "ok $OK / ng $NG  (API のテストは上の行に別途)"
[ "$NG" -eq 0 ] && [ $API_RC -eq 0 ] || exit 1
echo "ぜんぶ通りました"
