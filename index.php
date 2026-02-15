<?php
/**
 * ============================================================
 *  REFER AND EARN BOT — SINGLE index.php (Webhook + Web Verify)
 *  Tech: PHP (Render) + Supabase Postgres
 *  Bot Name: "Refer And Earn Bot"
 * ============================================================
 *
 * ✅ Features:
 * - Reply Keyboard: 📊 Stats | 🔗 Referral Link | 💰 Withdraw (+ ⚙ Admin Panel for admins)
 * - 1 referral = 1 point (anti-self referral + only once per user)
 * - Withdraw: ₹5 / ₹10 (points configurable by admin)
 * - Admin Panel: Add Coupon (bulk), Stock, Change Withdraw Points, Redeems Log (last 10)
 * - Admin notification on every withdraw
 * - Force-join 4 channels/groups (supports private via -100... chat_id + invite link)
 * - Web verification page (good UI)
 *
 * ------------------------------------------------------------
 * SUPABASE SQL (Run once)
 * ------------------------------------------------------------
 * CREATE TABLE IF NOT EXISTS users (
 *   id SERIAL PRIMARY KEY,
 *   telegram_id BIGINT UNIQUE,
 *   username TEXT,
 *   points INT DEFAULT 0,
 *   total_referrals INT DEFAULT 0,
 *   referred_by BIGINT,
 *   is_verified BOOLEAN DEFAULT FALSE,
 *   verify_token TEXT,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 *
 * CREATE TABLE IF NOT EXISTS coupons (
 *   id SERIAL PRIMARY KEY,
 *   code TEXT UNIQUE,
 *   amount INT, -- 5 or 10
 *   is_used BOOLEAN DEFAULT FALSE,
 *   used_by BIGINT,
 *   used_at TIMESTAMP
 * );
 *
 * CREATE TABLE IF NOT EXISTS withdraw_settings (
 *   amount INT PRIMARY KEY,  -- 5 or 10
 *   required_points INT NOT NULL
 * );
 * INSERT INTO withdraw_settings (amount, required_points) VALUES (5,5),(10,10)
 * ON CONFLICT (amount) DO NOTHING;
 *
 * CREATE TABLE IF NOT EXISTS redeems (
 *   id SERIAL PRIMARY KEY,
 *   telegram_id BIGINT NOT NULL,
 *   coupon_code TEXT NOT NULL,
 *   amount INT NOT NULL,
 *   created_at TIMESTAMP DEFAULT NOW()
 * );
 *
 * CREATE TABLE IF NOT EXISTS bot_state (
 *   telegram_id BIGINT PRIMARY KEY,
 *   mode TEXT,
 *   payload TEXT,
 *   updated_at TIMESTAMP DEFAULT NOW()
 * );
 *
 * ------------------------------------------------------------
 * RENDER
 * ------------------------------------------------------------
 * Start command:
 *   php -S 0.0.0.0:$PORT
 *
 * Set webhook:
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=<YOUR_RENDER_URL>
 * ============================================================
 */


/* =========================
   CONFIG (EDIT THESE)
========================= */
$BOT_TOKEN    = "8381747776:AAFX_nQw-QtejY42u6hXNVQzj7gAeMf2_aA";
$BOT_USERNAME = "RedeemCodeRefer_bot";                    // without @
$BASE_URL     = "https://your-service.onrender.com";  // your Render URL (no trailing slash)

$ADMIN_IDS = [
  7515220054, // your admin Telegram numeric ID
  // 7515220054,
];

/**
 * FORCE JOIN list can contain:
 * - public channel/group usernames: "@publicChanel"
 * - private channel/group numeric chat_id: "-1001234567890"
 *
 * For private, you MUST also set an invite link in $FORCE_JOIN_LINKS for that ID.
 */
$FORCE_JOIN = [
  "@PROXY_LOOTERS",
  "@CoolBoy_Shein",
  "@REACTMEN_STOCKS",
];

/**
 * Join links for each FORCE_JOIN entry.
 * - For public: you can put https://t.me/<username> OR leave it blank (script auto builds).
 * - For private -100...: you MUST put invite link like https://t.me/+AbCdEfGhIjK
 */
$FORCE_JOIN_LINKS = [
  "@publicChannel1" => "https://t.me/PROXY_LOOTERS",
  "@publicChannel2" => "https://t.me/CoolBoy_Shein",
  "@publicChannel3" => "https://t.me/REACTMEN_STOCKS",
];

$DATABASE_URL = "postgresql://postgres.ecdeyhmpqrtodezcdoar:RadheyRadhe@aws-1-ap-southeast-2.pooler.supabase.com:5432/postgres"; // Supabase connection string


/* =========================
   BASIC HARDENING
========================= */
error_reporting(0);
header("X-Content-Type-Options: nosniff");

/* =========================
   DB CONNECT
========================= */
try {
  $db = new PDO($DATABASE_URL);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
  http_response_code(200);
  echo "DB_ERROR";
  exit;
}

/* =========================
   TELEGRAM API HELPER
========================= */
function tg($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 25);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  $res = curl_exec($ch);
  curl_close($ch);
  $json = json_decode($res, true);
  return is_array($json) ? $json : ["ok"=>false, "raw"=>$res];
}

function isAdmin($telegram_id) {
  global $ADMIN_IDS;
  return in_array((int)$telegram_id, array_map('intval', $ADMIN_IDS), true);
}

function safeUser($u) {
  return $u ? "@".$u : "(no username)";
}

/* =========================
   FORCE JOIN CHECK
========================= */
function checkForceJoin($user_id) {
  global $FORCE_JOIN;
  foreach ($FORCE_JOIN as $chat) {
    if (!$chat) continue;
    $res = tg("getChatMember", ["chat_id"=>$chat, "user_id"=>$user_id]);
    if (!$res["ok"]) return false;
    $status = $res["result"]["status"] ?? "left";
    if ($status === "left" || $status === "kicked") return false;
  }
  return true;
}

/**
 * Build join buttons that work for:
 * - public @channels (auto link if not provided)
 * - private -100... IDs (must provide invite link)
 */
function buildForceJoinInlineKeyboard($user_id) {
  global $FORCE_JOIN, $FORCE_JOIN_LINKS, $BASE_URL;

  $ik = [];
  foreach ($FORCE_JOIN as $g) {
    if (!$g) continue;

    $url = $FORCE_JOIN_LINKS[$g] ?? null;

    // fallback for public @channels if link not provided
    if (!$url && strpos($g, "@") === 0) {
      $url = "https://t.me/" . str_replace("@", "", $g);
    }

    // if still missing (private without invite link), skip button to avoid broken UI
    if (!$url) continue;

    $label = (strpos($g, "@") === 0) ? ("Join " . $g) : "Join Private Channel";
    $ik[] = [ ["text"=>$label, "url"=>$url] ];
  }

  // verification button always present
  $ik[] = [ ["text"=>"✅ Verify", "url"=>$BASE_URL."?verify=".$user_id] ];

  return ["inline_keyboard"=>$ik];
}

/* =========================
   MENUS
========================= */
function sendMainMenu($chat_id, $is_admin=false) {
  $rows = [
    [ ["text"=>"📊 Stats"], ["text"=>"🔗 Referral Link"] ],
    [ ["text"=>"💰 Withdraw"] ],
  ];
  if ($is_admin) $rows[] = [ ["text"=>"⚙ Admin Panel"] ];

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"🏠 *Main Menu*",
    "parse_mode"=>"Markdown",
    "reply_markup"=>json_encode([
      "keyboard"=>$rows,
      "resize_keyboard"=>true
    ], JSON_UNESCAPED_UNICODE)
  ]);
}

function sendAdminMenu($chat_id) {
  $rows = [
    [ ["text"=>"➕ Add Coupon"], ["text"=>"📦 Stock"] ],
    [ ["text"=>"⚙ Change Withdraw Points"], ["text"=>"📜 Redeems Log"] ],
    [ ["text"=>"⬅ Back"] ],
  ];
  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"👑 *Admin Panel*",
    "parse_mode"=>"Markdown",
    "reply_markup"=>json_encode([
      "keyboard"=>$rows,
      "resize_keyboard"=>true
    ], JSON_UNESCAPED_UNICODE)
  ]);
}

/* =========================
   STATE HELPERS
========================= */
function stateGet($telegram_id) {
  global $db;
  $q = $db->prepare("SELECT mode, payload FROM bot_state WHERE telegram_id=?");
  $q->execute([$telegram_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  return $row ?: ["mode"=>null,"payload"=>null];
}

function stateSet($telegram_id, $mode, $payload=null) {
  global $db;
  $q = $db->prepare("
    INSERT INTO bot_state (telegram_id, mode, payload, updated_at)
    VALUES (?, ?, ?, NOW())
    ON CONFLICT (telegram_id) DO UPDATE SET mode=EXCLUDED.mode, payload=EXCLUDED.payload, updated_at=NOW()
  ");
  $q->execute([$telegram_id, $mode, $payload]);
}

function stateClear($telegram_id) {
  global $db;
  $q = $db->prepare("DELETE FROM bot_state WHERE telegram_id=?");
  $q->execute([$telegram_id]);
}

/* =========================
   USER HELPERS
========================= */
function ensureUser($telegram_id, $username) {
  global $db;
  $q = $db->prepare("SELECT telegram_id FROM users WHERE telegram_id=?");
  $q->execute([$telegram_id]);
  if (!$q->fetchColumn()) {
    $ins = $db->prepare("INSERT INTO users (telegram_id, username) VALUES (?,?)");
    $ins->execute([$telegram_id, $username]);
  } else if ($username !== "") {
    $up = $db->prepare("UPDATE users SET username=? WHERE telegram_id=?");
    $up->execute([$username, $telegram_id]);
  }
}

function getUserRow($telegram_id) {
  global $db;
  $q = $db->prepare("SELECT * FROM users WHERE telegram_id=?");
  $q->execute([$telegram_id]);
  return $q->fetch(PDO::FETCH_ASSOC);
}

/* =========================
   WEB VERIFICATION PAGE
========================= */
if (isset($_GET["verify"])) {
  $uid = (int)$_GET["verify"];
  $token = bin2hex(random_bytes(16));

  ensureUser($uid, "");
  $q = $db->prepare("UPDATE users SET verify_token=? WHERE telegram_id=?");
  $q->execute([$token, $uid]);

  $html = <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Refer And Earn Bot — Verification</title>
  <style>
    body{
      margin:0;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: radial-gradient(circle at top, #6ee7ff 0%, #4f46e5 40%, #111827 100%);
      color:#111827;
      padding:24px;
    }
    .card{
      width:100%;
      max-width:440px;
      background: rgba(255,255,255,0.92);
      border:1px solid rgba(255,255,255,0.4);
      border-radius:18px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.35);
      overflow:hidden;
    }
    .top{
      padding:22px 20px;
      background: linear-gradient(135deg, rgba(79,70,229,0.10), rgba(110,231,255,0.20));
      border-bottom:1px solid rgba(17,24,39,0.08);
    }
    h1{ margin:0; font-size:20px; letter-spacing:0.2px; }
    p{ margin:10px 0 0; color:#374151; line-height:1.45; font-size:14px; }
    .body{ padding:18px 20px 22px; }
    .btn{
      width:100%;
      border:0;
      padding:14px 16px;
      border-radius:12px;
      font-weight:800;
      font-size:15px;
      cursor:pointer;
      color:white;
      background: linear-gradient(135deg, #4f46e5, #06b6d4);
      box-shadow: 0 10px 22px rgba(79,70,229,0.35);
    }
    .muted{ margin-top:12px; font-size:12px; color:#6b7280; text-align:center; }
  </style>
</head>
<body>
  <div class="card">
    <div class="top">
      <h1>✅ Verify — Refer And Earn Bot</h1>
      <p>Press the button to verify your account. After success, return to Telegram.</p>
    </div>
    <div class="body">
      <form method="POST">
        <input type="hidden" name="uid" value="{$uid}">
        <input type="hidden" name="token" value="{$token}">
        <button class="btn" type="submit" name="verify_btn">Verify Now</button>
      </form>
      <div class="muted">Safe verification • No passwords needed</div>
    </div>
  </div>
</body>
</html>
HTML;

  header("Content-Type: text/html; charset=utf-8");
  echo $html;
  exit;
}

if (isset($_POST["verify_btn"])) {
  $uid = (int)($_POST["uid"] ?? 0);
  $token = $_POST["token"] ?? "";

  $q = $db->prepare("SELECT verify_token FROM users WHERE telegram_id=?");
  $q->execute([$uid]);
  $dbToken = $q->fetchColumn();

  header("Content-Type: text/html; charset=utf-8");

  if ($uid > 0 && $token && hash_equals((string)$dbToken, (string)$token)) {
    $up = $db->prepare("UPDATE users SET is_verified=TRUE, verify_token=NULL WHERE telegram_id=?");
    $up->execute([$uid]);
    echo "<h2 style='font-family:system-ui'>✅ Verification Successful</h2><p>You can go back to Telegram now.</p>";
  } else {
    echo "<h2 style='font-family:system-ui'>❌ Verification Failed</h2><p>Open the verify link from the bot again.</p>";
  }
  exit;
}

/* =========================
   TELEGRAM WEBHOOK HANDLER
========================= */
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

/* ---------- CALLBACK QUERY ---------- */
if (isset($update["callback_query"])) {
  $cb = $update["callback_query"];
  $data = $cb["data"] ?? "";
  $chat_id = $cb["message"]["chat"]["id"] ?? null;
  $user_id = $cb["from"]["id"] ?? null;

  if (!$chat_id || !$user_id) { http_response_code(200); exit; }

  // Withdraw callbacks
  if ($data === "withdraw_5" || $data === "withdraw_10") {
    $amount = (int)explode("_", $data)[1];

    ensureUser($user_id, $cb["from"]["username"] ?? "");
    $user = getUserRow($user_id);

    if (!$user["is_verified"]) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Verify first.", "show_alert"=>true]);
      http_response_code(200); exit;
    }

    if (!checkForceJoin($user_id)) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Join all channels first.", "show_alert"=>true]);
      tg("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"⚠️ Join all channels/groups, then verify.",
        "reply_markup"=>json_encode(buildForceJoinInlineKeyboard($user_id), JSON_UNESCAPED_UNICODE)
      ]);
      http_response_code(200); exit;
    }

    // required points
    $rq = $db->prepare("SELECT required_points FROM withdraw_settings WHERE amount=?");
    $rq->execute([$amount]);
    $required = (int)$rq->fetchColumn();
    if ($required <= 0) $required = $amount;

    if ((int)$user["points"] < $required) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Not enough points.", "show_alert"=>true]);
      http_response_code(200); exit;
    }

    // get coupon
    $cq = $db->prepare("SELECT id, code FROM coupons WHERE amount=? AND is_used=FALSE LIMIT 1");
    $cq->execute([$amount]);
    $coupon = $cq->fetch(PDO::FETCH_ASSOC);
    if (!$coupon) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Out of stock.", "show_alert"=>true]);
      http_response_code(200); exit;
    }

    // transaction
    try {
      $db->beginTransaction();

      $up = $db->prepare("UPDATE users SET points = points - ? WHERE telegram_id=? AND points >= ?");
      $up->execute([$required, $user_id, $required]);
      if ($up->rowCount() !== 1) {
        $db->rollBack();
        tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Try again.", "show_alert"=>true]);
        http_response_code(200); exit;
      }

      $use = $db->prepare("UPDATE coupons SET is_used=TRUE, used_by=?, used_at=NOW() WHERE id=? AND is_used=FALSE");
      $use->execute([$user_id, $coupon["id"]]);
      if ($use->rowCount() !== 1) {
        $db->rollBack();
        tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Coupon conflict. Try again.", "show_alert"=>true]);
        http_response_code(200); exit;
      }

      $lg = $db->prepare("INSERT INTO redeems (telegram_id, coupon_code, amount) VALUES (?,?,?)");
      $lg->execute([$user_id, $coupon["code"], $amount]);

      $db->commit();
    } catch (Exception $e) {
      try { $db->rollBack(); } catch(Exception $x) {}
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Server error. Try later.", "show_alert"=>true]);
      http_response_code(200); exit;
    }

    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🎉 *Withdraw Successful!*\n\nAmount: ₹{$amount}\nCode: `{$coupon["code"]}`",
      "parse_mode"=>"Markdown"
    ]);

    $uname = $cb["from"]["username"] ?? "";
    foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
      $aid = (int)trim($aid);
      if (!$aid) continue;
      tg("sendMessage", [
        "chat_id"=>$aid,
        "text"=>"🚨 *New Withdraw*\n\nUser: ".safeUser($uname)."\nID: `{$user_id}`\nAmount: ₹{$amount}\nCode: `{$coupon["code"]}`",
        "parse_mode"=>"Markdown"
      ]);
    }

    tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"]]);
    http_response_code(200); exit;
  }

  // Admin: choose add coupon amount
  if ($data === "add_5" || $data === "add_10") {
    if (!isAdmin($user_id)) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Not admin.", "show_alert"=>true]);
      http_response_code(200); exit;
    }
    $amount = (int)explode("_", $data)[1];
    stateSet($user_id, "ADD_COUPONS", (string)$amount);

    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"➕ Send coupons *line-by-line* (paste many lines).\n\nSelected: ₹{$amount}\n\nExample:\nABC123\nXYZ456\n...",
      "parse_mode"=>"Markdown"
    ]);
    tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"]]);
    http_response_code(200); exit;
  }

  // Admin: choose change points amount
  if ($data === "chg_5" || $data === "chg_10") {
    if (!isAdmin($user_id)) {
      tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"], "text"=>"Not admin.", "show_alert"=>true]);
      http_response_code(200); exit;
    }
    $amount = (int)explode("_", $data)[1];
    stateSet($user_id, "CHANGE_POINTS", (string)$amount);

    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"⚙ Send new required points for ₹{$amount}.\n\nExample: `7`",
      "parse_mode"=>"Markdown"
    ]);
    tg("answerCallbackQuery", ["callback_query_id"=>$cb["id"]]);
    http_response_code(200); exit;
  }

  http_response_code(200); exit;
}

/* ---------- MESSAGE ---------- */
if (!isset($update["message"])) { http_response_code(200); exit; }

$msg = $update["message"];
$chat_id = $msg["chat"]["id"] ?? null;
$user_id = $msg["from"]["id"] ?? null;
$username = $msg["from"]["username"] ?? "";
$text = $msg["text"] ?? "";

if (!$chat_id || !$user_id) { http_response_code(200); exit; }
ensureUser($user_id, $username);

/* ---------- /start handling + referral ---------- */
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text, 2);
  $ref = isset($parts[1]) ? trim($parts[1]) : "";
  $ref_id = ($ref !== "" && ctype_digit($ref)) ? (int)$ref : 0;

  $u = getUserRow($user_id);

  // set referred_by only once, award once, block self-ref
  if ($u && empty($u["referred_by"]) && $ref_id > 0 && $ref_id !== (int)$user_id) {
    ensureUser($ref_id, "");
    $set = $db->prepare("UPDATE users SET referred_by=? WHERE telegram_id=? AND (referred_by IS NULL OR referred_by=0)");
    $set->execute([$ref_id, $user_id]);

    if ($set->rowCount() === 1) {
      $award = $db->prepare("UPDATE users SET points=points+1, total_referrals=total_referrals+1 WHERE telegram_id=?");
      $award->execute([$ref_id]);
    }
  }

  if (!checkForceJoin($user_id)) {
    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"⚠️ Join all channels/groups first, then verify.\n\nAfter joining, press ✅ Verify.",
      "reply_markup"=>json_encode(buildForceJoinInlineKeyboard($user_id), JSON_UNESCAPED_UNICODE)
    ]);
    http_response_code(200); exit;
  }

  sendMainMenu($chat_id, isAdmin($user_id));
  http_response_code(200); exit;
}

/* ---------- STATE ---------- */
$st = stateGet($user_id);
$mode = $st["mode"];
$payload = $st["payload"];

/* Admin bulk add coupons (state) */
if ($mode === "ADD_COUPONS" && isAdmin($user_id)) {
  $amount = (int)$payload;
  $lines = preg_split("/\R/u", $text);
  $added = 0;

  foreach ($lines as $line) {
    $code = trim($line);
    if ($code === "") continue;

    try {
      $ins = $db->prepare("INSERT INTO coupons (code, amount) VALUES (?, ?) ON CONFLICT (code) DO NOTHING");
      $ins->execute([$code, $amount]);
      if ($ins->rowCount() === 1) $added++;
    } catch (Exception $e) {}
  }

  stateClear($user_id);

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ Added *{$added}* coupons for ₹{$amount}.",
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

/* Admin change points (state) */
if ($mode === "CHANGE_POINTS" && isAdmin($user_id)) {
  $amount = (int)$payload;
  $newPts = trim($text);

  if (!ctype_digit($newPts) || (int)$newPts < 1 || (int)$newPts > 100000) {
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only a number like: 7"]);
    http_response_code(200); exit;
  }

  $up = $db->prepare("UPDATE withdraw_settings SET required_points=? WHERE amount=?");
  $up->execute([(int)$newPts, $amount]);

  stateClear($user_id);

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ Updated: ₹{$amount} now requires *{$newPts}* points.",
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

/* ---------- BUTTON HANDLERS ---------- */
if ($text === "⬅ Back") {
  sendMainMenu($chat_id, isAdmin($user_id));
  http_response_code(200); exit;
}

if ($text === "⚙ Admin Panel") {
  if (!isAdmin($user_id)) {
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ You are not admin."]);
    http_response_code(200); exit;
  }
  sendAdminMenu($chat_id);
  http_response_code(200); exit;
}

if ($text === "📊 Stats") {
  $u = getUserRow($user_id);
  $pts = (int)($u["points"] ?? 0);
  $refs = (int)($u["total_referrals"] ?? 0);
  $ver = ($u["is_verified"] ?? false) ? "✅ Verified" : "❌ Not verified";

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"📊 *Your Stats*\n\nUser: ".safeUser($username)."\nPoints: *{$pts}*\nReferrals: *{$refs}*\nStatus: {$ver}",
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

if ($text === "🔗 Referral Link") {
  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"🔗 *Your Referral Link*\n\nhttps://t.me/{$BOT_USERNAME}?start={$user_id}",
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

if ($text === "💰 Withdraw") {
  $u = getUserRow($user_id);

  if (!checkForceJoin($user_id)) {
    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"⚠️ Join all channels/groups first, then verify.",
      "reply_markup"=>json_encode(buildForceJoinInlineKeyboard($user_id), JSON_UNESCAPED_UNICODE)
    ]);
    http_response_code(200); exit;
  }

  if (!$u["is_verified"]) {
    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"❌ You must verify first.\n\nTap: ✅ Verify",
      "reply_markup"=>json_encode([
        "inline_keyboard"=>[
          [ ["text"=>"✅ Verify", "url"=>$BASE_URL."?verify=".$user_id] ]
        ]
      ], JSON_UNESCAPED_UNICODE)
    ]);
    http_response_code(200); exit;
  }

  $r5 = (int)$db->query("SELECT required_points FROM withdraw_settings WHERE amount=5")->fetchColumn();
  $r10 = (int)$db->query("SELECT required_points FROM withdraw_settings WHERE amount=10")->fetchColumn();
  if ($r5<=0) $r5=5;
  if ($r10<=0) $r10=10;

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"💰 *Withdraw*\n\nChoose gift card:\n• ₹5 requires *{$r5}* points\n• ₹10 requires *{$r10}* points",
    "parse_mode"=>"Markdown",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [ ["text"=>"🎁 ₹5 Gift Card", "callback_data"=>"withdraw_5"] ],
        [ ["text"=>"🎁 ₹10 Gift Card", "callback_data"=>"withdraw_10"] ],
      ]
    ], JSON_UNESCAPED_UNICODE)
  ]);
  http_response_code(200); exit;
}

/* ---------- ADMIN FUNCTIONS ---------- */
if ($text === "➕ Add Coupon") {
  if (!isAdmin($user_id)) { http_response_code(200); exit; }

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"➕ Choose coupon type to add:",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [ ["text"=>"₹5 Gift Card", "callback_data"=>"add_5"] ],
        [ ["text"=>"₹10 Gift Card", "callback_data"=>"add_10"] ],
      ]
    ], JSON_UNESCAPED_UNICODE)
  ]);
  http_response_code(200); exit;
}

if ($text === "📦 Stock") {
  if (!isAdmin($user_id)) { http_response_code(200); exit; }

  $s5  = (int)$db->query("SELECT COUNT(*) FROM coupons WHERE amount=5 AND is_used=FALSE")->fetchColumn();
  $s10 = (int)$db->query("SELECT COUNT(*) FROM coupons WHERE amount=10 AND is_used=FALSE")->fetchColumn();

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"📦 *Stock*\n\n₹5: *{$s5}*\n₹10: *{$s10}*",
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

if ($text === "📜 Redeems Log") {
  if (!isAdmin($user_id)) { http_response_code(200); exit; }

  $q = $db->query("SELECT telegram_id, coupon_code, amount, created_at FROM redeems ORDER BY created_at DESC LIMIT 10");
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $out = "📜 *Last 10 Redeems*\n\n";
  if (!$rows) {
    $out .= "No redeems yet.";
  } else {
    $i=1;
    foreach ($rows as $r) {
      $out .= $i.". ID: `".$r["telegram_id"]."` | ₹".$r["amount"]." | `".$r["coupon_code"]."`\n";
      $i++;
    }
  }

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>$out,
    "parse_mode"=>"Markdown"
  ]);
  http_response_code(200); exit;
}

if ($text === "⚙ Change Withdraw Points") {
  if (!isAdmin($user_id)) { http_response_code(200); exit; }

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"⚙ Choose which amount to change:",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [ ["text"=>"₹5", "callback_data"=>"chg_5"] ],
        [ ["text"=>"₹10", "callback_data"=>"chg_10"] ],
      ]
    ], JSON_UNESCAPED_UNICODE)
  ]);
  http_response_code(200); exit;
}

/* ---------- FALLBACK ---------- */
sendMainMenu($chat_id, isAdmin($user_id));
http_response_code(200);
echo "OK"; 
