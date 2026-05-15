<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\vendedor\chat.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/src/auth.php';
require_once $ROOT . '/src/db.php';
require_once $ROOT . '/src/chat.php';
require_once $ROOT . '/src/media.php';

if (function_exists('exigirVendedor')) {
    exigirVendedor();
} else {
    exigirUsuario();
}

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);
$user = $_SESSION['user'] ?? [];

$pageTitle  = 'Comunicação';
$activeMenu = 'comunicacao';
$comTab     = 'chat';

// Get conversations
$conversations = chatListConversations($conn, $uid, 'vendedor');

// Active conversation
$activeConvId = (int)($_GET['conv'] ?? 0);
$activeConv   = null;
$messages     = [];

if ($activeConvId > 0) {
    $activeConv = chatGetConversation($conn, $activeConvId, $uid);
    if ($activeConv) {
        chatMarkRead($conn, $activeConvId, $uid);
        $messages = chatGetMessages($conn, $activeConvId, 1, 50);
    }
}

include $ROOT . '/views/partials/header.php';
include $ROOT . '/views/partials/vendor_layout_start.php';
?>

<style>
.chat-page { display: flex; height: calc(100vh - 180px); min-height: 500px; border-radius: 16px; overflow: hidden; border: 1px solid rgba(255,255,255,0.06); background: #0a0a0a; }
.chat-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; background: rgba(255,255,255,0.01); }
@media (min-width: 769px) and (max-width: 1024px) { .chat-sidebar { width: 260px; } }
.chat-sidebar-header { padding: 16px; border-bottom: 1px solid rgba(255,255,255,0.06); }
.chat-sidebar-header h3 { font-size: 16px; font-weight: 700; color: #e5e5e5; display: flex; align-items: center; gap: 8px; }
.chat-sidebar-list { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.08) transparent; }
.chat-sidebar-item { display: flex; align-items: center; gap: 12px; padding: 14px 16px; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid rgba(255,255,255,0.03); text-decoration: none; }
.chat-sidebar-item:hover { background: rgba(255,255,255,0.04); }
.chat-sidebar-item.active { background: rgba(var(--t-accent-rgb),0.08); border-left: 3px solid var(--t-accent); }
.chat-sidebar-item .sb-avatar { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #1a1a2e, #16213e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
.chat-sidebar-item .sb-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chat-sidebar-item .sb-info { flex: 1; min-width: 0; }
.chat-sidebar-item .sb-name { font-size: 14px; font-weight: 600; color: #e5e5e5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-sidebar-item .sb-preview { font-size: 12px; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.chat-sidebar-item .sb-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.chat-sidebar-item .sb-time { font-size: 11px; color: #555; }
.chat-sidebar-item .sb-unread { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--t-accent); color: var(--t-text-on-accent); font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }

.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-main-header { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02); flex-shrink: 0; }
.chat-main-header .mh-avatar { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--t-accent), var(--t-accent-hover)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.chat-main-header .mh-info { flex: 1; min-width: 0; }
.chat-main-header .mh-name { font-size: 15px; font-weight: 700; color: #fff; }
.chat-main-header .mh-product { font-size: 12px; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.chat-main-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 6px; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.08) transparent; }
.chat-main-messages .chat-msg { max-width: 65%; padding: 10px 14px; border-radius: 16px; font-size: 13px; line-height: 1.5; word-wrap: break-word; animation: cmsgIn 0.25s ease-out; }
.chat-main-messages .chat-msg.mine { align-self: flex-end; background: linear-gradient(135deg, var(--t-accent), var(--t-accent-hover)); color: var(--t-text-on-accent); border-bottom-right-radius: 6px; }
.chat-main-messages .chat-msg.theirs { align-self: flex-start; background: rgba(255,255,255,0.06); color: #e5e5e5; border-bottom-left-radius: 6px; border: 1px solid rgba(255,255,255,0.06); }
.chat-main-messages .chat-msg .msg-time { font-size: 10px; margin-top: 4px; opacity: 0.6; }
.chat-main-messages .chat-msg.mine .msg-time { text-align: right; color: rgba(0,0,0,0.5); }
.chat-main-messages .chat-msg.theirs .msg-time { color: #555; }
.chat-main-messages .msg-date { text-align: center; font-size: 11px; color: #555; padding: 8px 0; font-weight: 500; }
@keyframes cmsgIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

.chat-main-input { display: flex; align-items: flex-end; gap: 8px; padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.06); background: rgba(0,0,0,0.3); flex-shrink: 0; }
.chat-main-input textarea { flex: 1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 10px 14px; color: #fff; font-size: 13px; line-height: 1.4; resize: none; outline: none; max-height: 100px; min-height: 42px; font-family: inherit; transition: border-color 0.2s; }
.chat-main-input textarea:focus { border-color: rgba(var(--t-accent-rgb),0.4); }
.chat-main-input textarea::placeholder { color: #555; }
.chat-main-input .send-btn { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, var(--t-accent), var(--t-accent-hover)); border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; flex-shrink: 0; }
.chat-main-input .send-btn:hover { filter: brightness(1.1); transform: scale(1.05); }
.chat-main-input .send-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.chat-main-input .send-btn svg { width: 18px; height: 18px; color: #fff; }

.chat-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 32px; }
.chat-empty-state svg { width: 64px; height: 64px; color: #222; margin-bottom: 16px; }
.chat-empty-state h4 { font-size: 16px; color: #555; font-weight: 600; margin-bottom: 4px; }
.chat-empty-state p { font-size: 13px; color: #444; }

@media (max-width: 768px) {
  .chat-sidebar { width: 100%; display: <?= $activeConvId > 0 ? 'none' : 'flex' ?>; }
  .chat-main { display: <?= $activeConvId > 0 ? 'flex' : 'none' ?>; }
  .chat-page { height: calc(100vh - 160px); }
}
.vdc-input { width: 28px; height: 32px; border-radius: 6px; background: rgba(0,0,0,0.4); border: 1px solid rgba(245,158,11,0.35); color: #f59e0b; text-align: center; font-size: 14px; font-weight: 700; font-family: monospace; text-transform: uppercase; outline: none; }
.vdc-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245,158,11,0.25); }
.vdc-input.filled { background: rgba(245,158,11,0.15); }
#vendorDeliveryBtn:hover { filter: brightness(1.1); transform: translateY(-1px); transition: all .15s; }
#vendorDeliveryBtn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
#vendorDeliveryMsg.ok { color: #22c55e; }
#vendorDeliveryMsg.err { color: #ef4444; }
</style>

<?php include $ROOT . '/views/partials/comunicacao_tabs_vendor.php'; ?>

<div class="chat-page">
    <!-- Sidebar: conversations list -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-header">
            <h3>
                <i data-lucide="message-circle" class="w-5 h-5 text-greenx"></i>
                Mensagens
                <?php $totalUnread = chatUnreadCount($conn, $uid); ?>
                <?php if ($totalUnread > 0): ?>
                <span class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $totalUnread ?></span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="chat-sidebar-list">
            <?php if (empty($conversations)): ?>
            <div class="p-6 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-zinc-700 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="text-xs text-zinc-600">Nenhuma conversa ainda</p>
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $c):
                $cid     = (int)$c['id'];
                $cActive = $cid === $activeConvId;
                $cName   = $c['other_name'] ?: 'Comprador';
                $cAvatar = $c['other_avatar'] ?? '';
                $cPreview = $c['last_message'] ?? 'Sem mensagens';
                $cUnread = (int)($c['unread_count'] ?? 0);
                $cTime   = '';
                if (!empty($c['last_msg_time'])) {
                    try {
                        $dt = new DateTime((string)$c['last_msg_time']);
                        $now = new DateTime();
                        $diff = $now->diff($dt);
                        if ($diff->days === 0) $cTime = $dt->format('H:i');
                        elseif ($diff->days === 1) $cTime = 'Ontem';
                        else $cTime = $dt->format('d/m');
                    } catch (Throwable $e) {}
                }
                $avatarUrl = '';
                if ($cAvatar) {
                    if (str_starts_with($cAvatar, 'media:')) {
                        $avatarUrl = BASE_PATH . '/api/media?id=' . substr($cAvatar, 6);
                    } else {
                        $avatarUrl = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $cAvatar), '/');
                    }
                }
            ?>
            <a href="?conv=<?= $cid ?>" class="chat-sidebar-item <?= $cActive ? 'active' : '' ?>">
                <div class="sb-avatar">
                    <?php if ($avatarUrl): ?>
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#555" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <?php endif; ?>
                </div>
                <div class="sb-info">
                    <div class="sb-name"><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php
                    $cLastSeen = $c['other_last_seen'] ?? null;
                    $cLsText = '';
                    if ($cLastSeen) {
                        try {
                            $lsDt = new DateTime($cLastSeen);
                            $lsDiff = (new DateTime())->getTimestamp() - $lsDt->getTimestamp();
                            if ($lsDiff < 300) $cLsText = 'Online';
                            elseif ($lsDiff < 3600) $cLsText = 'há ' . intdiv($lsDiff, 60) . ' min';
                            elseif ($lsDiff < 86400) $cLsText = 'há ' . intdiv($lsDiff, 3600) . 'h';
                            elseif ($lsDiff < 172800) $cLsText = 'ontem';
                            else $cLsText = $lsDt->format('d/m');
                        } catch (Throwable $e) {}
                    }
                    ?>
                    <?php if ($cLsText): ?>
                    <div style="font-size:10px;color:<?= $cLsText === 'Online' ? 'var(--t-accent)' : '#555' ?>">● <?= $cLsText ?></div>
                    <?php endif; ?>
                    <div class="sb-preview"><?= htmlspecialchars(mb_substr($cPreview, 0, 40), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($cPreview) > 40 ? '...' : '' ?></div>
                </div>
                <div class="sb-meta">
                    <?php if ($cTime): ?><span class="sb-time"><?= $cTime ?></span><?php endif; ?>
                    <?php if ($cUnread > 0): ?><span class="sb-unread"><?= $cUnread ?></span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main: conversation messages -->
    <div class="chat-main">
        <?php if (!$activeConv): ?>
        <div class="chat-empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="0.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>
            <h4>Selecione uma conversa</h4>
            <p>Escolha uma conversa ao lado para ver as mensagens</p>
        </div>
        <?php else: ?>
        <!-- Header -->
        <div class="chat-main-header">
            <?php if (isset($_GET['conv'])): ?>
            <a href="?" class="md:hidden flex items-center justify-center w-8 h-8 rounded-lg bg-white/5 border border-white/10 mr-1">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
            </a>
            <?php endif; ?>
            <?php
            $mhAvatar = $activeConv['buyer_avatar'] ?? '';
            $mhAvatarUrl = '';
            if ($mhAvatar) {
                if (str_starts_with($mhAvatar, 'media:')) {
                    $mhAvatarUrl = BASE_PATH . '/api/media?id=' . substr($mhAvatar, 6);
                } elseif (preg_match('~^https?://~i', $mhAvatar)) {
                    $mhAvatarUrl = $mhAvatar;
                } else {
                    $mhAvatarUrl = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $mhAvatar), '/');
                }
            }
            ?>
            <div class="mh-avatar" style="overflow:hidden">
                <?php if ($mhAvatarUrl): ?>
                <img src="<?= htmlspecialchars($mhAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px">
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <?php endif; ?>
            </div>
            <div class="mh-info">
                <div class="mh-name"><?= htmlspecialchars($activeConv['buyer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php
                $buyerLastSeen = $activeConv['buyer_last_seen'] ?? null;
                $lsText = '';
                if ($buyerLastSeen) {
                    $lsDt = new DateTime($buyerLastSeen);
                    $lsDiff = (new DateTime())->getTimestamp() - $lsDt->getTimestamp();
                    if ($lsDiff < 300) $lsText = 'Online';
                    elseif ($lsDiff < 3600) $lsText = 'visto há ' . intdiv($lsDiff, 60) . ' min';
                    elseif ($lsDiff < 86400) $lsText = 'visto há ' . intdiv($lsDiff, 3600) . 'h';
                    elseif ($lsDiff < 172800) $lsText = 'visto ontem';
                    else $lsText = 'visto ' . $lsDt->format('d/m');
                }
                ?>
                <?php if ($lsText): ?>
                <div class="mh-product" style="color:<?= $lsText === 'Online' ? 'var(--t-accent)' : '#666' ?>"><?= $lsText ?></div>
                <?php elseif (!empty($activeConv['product_name'])): ?>
                <div class="mh-product">Sobre: <?= htmlspecialchars($activeConv['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <div class="chat-main-messages" id="vendorChatMsgs">
            <?php
            $lastDate = '';
            foreach ($messages as $m):
                $d = (new DateTime($m['criado_em']))->format('d/m/Y');
                $today = (new DateTime())->format('d/m/Y');
                $yesterday = (new DateTime('yesterday'))->format('d/m/Y');
                $dateLabel = $d === $today ? 'Hoje' : ($d === $yesterday ? 'Ontem' : $d);
                if ($d !== $lastDate):
                    $lastDate = $d;
            ?>
            <div class="msg-date"><?= $dateLabel ?></div>
            <?php endif; ?>
            <div class="chat-msg <?= (int)$m['sender_id'] === $uid ? 'mine' : 'theirs' ?>" data-msg-id="<?= (int)$m['id'] ?>">
                <div><?= htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="msg-time"><?= (new DateTime($m['criado_em']))->format('H:i') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Delivery code confirmation strip (vendor side) -->
        <div id="vendorDeliveryStrip" style="padding:12px 16px;border-top:1px solid rgba(245,158,11,0.18);background:linear-gradient(180deg,rgba(245,158,11,0.06),rgba(245,158,11,0.02));">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                    <i data-lucide="key-round" style="width:16px;height:16px;color:#f59e0b;flex-shrink:0;"></i>
                    <span style="font-size:12px;font-weight:600;color:#f59e0b;white-space:nowrap;">Confirmar entrega:</span>
                    <div id="vendorDeliveryBoxes" style="display:flex;gap:4px;">
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                        <input class="vdc-input" maxlength="1" inputmode="text" autocomplete="off" />
                    </div>
                </div>
                <button id="vendorDeliveryBtn" type="button" style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:8px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <i data-lucide="check-circle" style="width:14px;height:14px;"></i>
                    Confirmar entrega
                </button>
            </div>
            <div id="vendorDeliveryMsg" style="display:none;font-size:11px;margin-top:6px;"></div>
        </div>
        <div id="vendorDeliveryDone" style="display:none;padding:12px 16px;border-top:1px solid rgba(34,197,94,0.18);background:rgba(34,197,94,0.06);text-align:center;font-size:12px;color:#22c55e;font-weight:600;">
            <i data-lucide="check-circle" style="width:14px;height:14px;display:inline;vertical-align:-2px;"></i>
            Entrega confirmada — pagamento liberado
        </div>

        <!-- Input -->
        <div class="chat-main-input">
            <textarea id="vendorChatInput" rows="1" placeholder="Digite uma mensagem..." maxlength="2000"></textarea>
            <button class="send-btn" id="vendorChatSend" title="Enviar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
            </button>
        </div>

        <script>
        (function(){
            const CHAT_API = '<?= BASE_PATH ?>/api/chat';
            const CONV_ID  = <?= $activeConvId ?>;
            const USER_ID  = <?= $uid ?>;
            const input    = document.getElementById('vendorChatInput');
            const sendBtn  = document.getElementById('vendorChatSend');
            const msgArea  = document.getElementById('vendorChatMsgs');
            let lastMsgId  = 0;
            let sending    = false;

            // Find last message ID
            const allMsgs = msgArea.querySelectorAll('[data-msg-id]');
            if (allMsgs.length > 0) lastMsgId = parseInt(allMsgs[allMsgs.length - 1].dataset.msgId) || 0;

            // Scroll to bottom on load
            msgArea.scrollTop = msgArea.scrollHeight;

            // Auto-resize textarea
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 100) + 'px';
            });

            // Send message
            async function send() {
                if (sending) return;
                const text = input.value.trim();
                if (!text) return;
                sending = true;
                sendBtn.disabled = true;

                try {
                    const fd = new FormData();
                    fd.append('conversation_id', CONV_ID);
                    fd.append('message', text);
                    const r = await fetch(CHAT_API + '?action=send', { method: 'POST', body: fd });
                    const j = await r.json();
                    if (j.ok && j.msg) {
                        appendMsg(j.msg);
                        input.value = '';
                        input.style.height = 'auto';
                    }
                } catch(e) { console.error(e); }
                sending = false;
                sendBtn.disabled = false;
                input.focus();
            }

            sendBtn.addEventListener('click', send);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
            });

            function appendMsg(m) {
                const el = document.createElement('div');
                el.className = 'chat-msg ' + (m.is_mine ? 'mine' : 'theirs');
                el.dataset.msgId = m.id;
                const t = document.createElement('div');
                t.textContent = m.message;
                el.appendChild(t);
                const tm = document.createElement('div');
                tm.className = 'msg-time';
                tm.textContent = formatTime(m.criado_em);
                el.appendChild(tm);
                msgArea.appendChild(el);
                if (m.id > lastMsgId) lastMsgId = m.id;
                msgArea.scrollTop = msgArea.scrollHeight;
            }

            // Polling
            async function poll() {
                try {
                    const r = await fetch(CHAT_API + '?action=poll&conversation_id=' + CONV_ID + '&after_id=' + lastMsgId);
                    const j = await r.json();
                    if (j.ok && j.messages.length > 0) {
                        j.messages.forEach(m => appendMsg(m));
                    }
                } catch(e) {}
            }
            setInterval(poll, 3000);

            function formatTime(dt) {
                if (!dt) return '';
                const d = new Date(dt.replace(' ', 'T'));
                return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            }

            // ── Vendor delivery code confirmation ──
            const vdcStrip  = document.getElementById('vendorDeliveryStrip');
            const vdcDone   = document.getElementById('vendorDeliveryDone');
            const vdcInputs = document.querySelectorAll('#vendorDeliveryBoxes .vdc-input');
            const vdcBtn    = document.getElementById('vendorDeliveryBtn');
            const vdcMsg    = document.getElementById('vendorDeliveryMsg');

            vdcInputs.forEach((inp, i) => {
                inp.addEventListener('input', (e) => {
                    inp.value = inp.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    inp.classList.toggle('filled', !!inp.value);
                    if (inp.value && i < vdcInputs.length - 1) vdcInputs[i + 1].focus();
                });
                inp.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !inp.value && i > 0) {
                        vdcInputs[i - 1].focus();
                        vdcInputs[i - 1].value = '';
                        vdcInputs[i - 1].classList.remove('filled');
                    }
                    if (e.key === 'Enter') { e.preventDefault(); vdcBtn.click(); }
                });
                inp.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pasted = (e.clipboardData.getData('text') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    for (let j = 0; j < 6; j++) {
                        vdcInputs[j].value = pasted[j] || '';
                        vdcInputs[j].classList.toggle('filled', !!pasted[j]);
                    }
                    vdcInputs[Math.min(pasted.length, 5)].focus();
                });
            });

            vdcBtn.addEventListener('click', async () => {
                const code = Array.from(vdcInputs).map(b => b.value.toUpperCase()).join('');
                vdcMsg.style.display = 'none';
                if (code.length !== 6) {
                    vdcMsg.textContent = 'O código deve ter 6 caracteres.';
                    vdcMsg.className = 'err';
                    vdcMsg.style.display = 'block';
                    return;
                }
                vdcBtn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('conversation_id', CONV_ID);
                    fd.append('delivery_code', code);
                    const r = await fetch(CHAT_API + '?action=confirm_delivery', { method: 'POST', body: fd });
                    const j = await r.json();
                    vdcMsg.textContent = j.msg || (j.ok ? 'Entrega confirmada!' : 'Erro');
                    vdcMsg.className = j.ok ? 'ok' : 'err';
                    vdcMsg.style.display = 'block';
                    if (j.ok) {
                        vdcInputs.forEach(b => { b.value = ''; b.classList.remove('filled'); });
                        vdcStrip.style.display = 'none';
                        vdcDone.style.display = 'block';
                        if (window.lucide) window.lucide.createIcons();
                        setTimeout(() => poll(), 800);
                    }
                } catch(e) {
                    vdcMsg.textContent = 'Erro de conexão.';
                    vdcMsg.className = 'err';
                    vdcMsg.style.display = 'block';
                }
                vdcBtn.disabled = false;
            });

            // Hide strip if delivery already confirmed
            (async function checkDelivered(){
                try {
                    const r = await fetch(CHAT_API + '?action=get_delivery_status&conversation_id=' + CONV_ID);
                    const j = await r.json();
                    if (j.ok && j.delivered) {
                        vdcStrip.style.display = 'none';
                        vdcDone.style.display = 'block';
                        if (window.lucide) window.lucide.createIcons();
                    }
                } catch(e) {}
            })();
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<?php
include $ROOT . '/views/partials/vendor_layout_end.php';
include $ROOT . '/views/partials/footer.php';
