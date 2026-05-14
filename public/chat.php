<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\chat.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/chat.php';
require_once __DIR__ . '/../src/media.php';

exigirLogin();

$conn = (new Database())->connect();
$uid  = (int)($_SESSION['user_id'] ?? 0);
$user = $_SESSION['user'] ?? [];
$role = (string)($user['role'] ?? 'usuario');

$pageTitle  = 'Chat';
$activeMenu = 'chat';

// Get conversations
$conversations = chatListConversations($conn, $uid, $role);

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

include __DIR__ . '/../views/partials/header.php';
include __DIR__ . '/../views/partials/user_layout_start.php';
?>

<style>
.chat-page { display: flex; height: calc(100vh - 180px); min-height: 500px; border-radius: 16px; overflow: hidden; border: 1px solid rgba(255,255,255,0.06); background: #0a0a0a; }
.chat-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; background: rgba(255,255,255,0.01); }
.chat-sidebar-header { padding: 16px; border-bottom: 1px solid rgba(255,255,255,0.06); }
.chat-sidebar-header h3 { font-size: 16px; font-weight: 700; color: #e5e5e5; display: flex; align-items: center; gap: 8px; }
.chat-sidebar-list { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.08) transparent; }
.chat-sidebar-item { display: flex; align-items: center; gap: 12px; padding: 14px 16px; cursor: pointer; transition: background 0.15s; border-bottom: 1px solid rgba(255,255,255,0.03); text-decoration: none; color: inherit; }
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

/* ── System / Auto Messages ── */
.chat-msg.system-msg { max-width: 85%; background: linear-gradient(135deg, rgba(136,0,228,0.08), rgba(136,0,228,0.03)) !important; border: 1px solid rgba(136,0,228,0.2) !important; border-radius: 16px !important; padding: 14px 16px !important; color: #e5e5e5 !important; }
.chat-msg.system-msg .sys-header { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.06); }
.chat-msg.system-msg.type-instructions .sys-header { color: #8800E4; }
.chat-msg.system-msg.type-delivery .sys-header { color: #8800E4; }
.chat-msg.system-msg.type-delivery_code .sys-header { color: #f59e0b; }
.chat-msg.system-msg.type-system .sys-header { color: #8b5cf6; }
.chat-msg.system-msg.type-delivery { background: linear-gradient(135deg, rgba(136,0,228,0.08), rgba(136,0,228,0.03)) !important; border-color: rgba(136,0,228,0.2) !important; }
.chat-msg.system-msg.type-delivery_code { background: linear-gradient(135deg, rgba(245,158,11,0.08), rgba(245,158,11,0.03)) !important; border-color: rgba(245,158,11,0.2) !important; }
.chat-msg.system-msg .sys-content { white-space: pre-wrap; font-size: 13px; line-height: 1.6; }
.chat-msg.system-msg .sys-delivery-box { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 12px; margin: 8px 0; font-family: 'Courier New', monospace; font-size: 12px; color: #d1d5db; white-space: pre-wrap; word-break: break-all; position: relative; }
.chat-msg.system-msg .sys-copy-btn { position: absolute; top: 6px; right: 6px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 4px 8px; font-size: 10px; color: #a3a3a3; cursor: pointer; transition: all 0.2s; }
.chat-msg.system-msg .sys-copy-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
.chat-msg.system-msg .sys-code { display: inline-flex; gap: 3px; margin: 8px 0; }
.chat-msg.system-msg .sys-code span { width: 32px; height: 38px; border-radius: 6px; background: rgba(0,0,0,0.4); border: 1px solid rgba(245,158,11,0.3); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; font-family: monospace; color: #f59e0b; }
</style>

<div class="chat-page">
    <!-- Sidebar: conversations list -->
    <div class="chat-sidebar">
        <div class="chat-sidebar-header">
            <h3>
                <i data-lucide="message-circle" class="w-5 h-5 text-greenx"></i>
                Minhas conversas
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
                <p class="text-xs text-zinc-700 mt-1">Inicie uma conversa pelo chat na página de um produto</p>
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $c):
                $cid     = (int)$c['id'];
                $cActive = $cid === $activeConvId;
                $cMyRole = (string)($c['my_role'] ?? 'comprador');
                // Quando sou vendedor naquela conversa, o "outro" é o comprador (pessoa).
                // Quando sou comprador, mostro o nome da loja (ou nome do vendedor como fallback).
                $cName   = $cMyRole === 'vendedor'
                            ? ($c['other_name'] ?? '')
                            : (($c['store_name'] ?? '') ?: ($c['other_name'] ?? ''));
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
                    <span style="font-size:16px;font-weight:700;color:var(--t-accent)"><?= strtoupper(mb_substr($cName, 0, 1)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="sb-info">
                    <div class="sb-name"><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($c['product_name'])): ?>
                    <div class="sb-preview" style="color:var(--t-accent);font-size:11px"><?= htmlspecialchars(mb_substr($c['product_name'], 0, 30), ENT_QUOTES, 'UTF-8') ?></div>
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
                // Resolve the other user's avatar
                $isCurrentBuyer = ((int)($activeConv['buyer_id'] ?? 0) === $uid);
                $otherAvatar = $isCurrentBuyer ? ($activeConv['vendor_avatar'] ?? '') : ($activeConv['buyer_avatar'] ?? '');
                $otherAvatarUrl = '';
                if ($otherAvatar) {
                    if (str_starts_with($otherAvatar, 'media:')) {
                        $otherAvatarUrl = BASE_PATH . '/api/media?id=' . substr($otherAvatar, 6);
                    } else {
                        $otherAvatarUrl = BASE_PATH . '/' . ltrim(str_replace('\\', '/', $otherAvatar), '/');
                    }
                }
                $otherName = $activeConv['store_name'] ?: ($isCurrentBuyer ? $activeConv['vendor_name'] : $activeConv['buyer_name']);
            ?>
            <div class="mh-avatar" style="overflow:hidden">
                <?php if ($otherAvatarUrl): ?>
                <img src="<?= htmlspecialchars($otherAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                <span style="font-size:16px;font-weight:700;color:var(--t-accent)"><?= strtoupper(mb_substr($otherName, 0, 1)) ?></span>
                <?php endif; ?>
            </div>
            <div class="mh-info">
                <div class="mh-name"><?= htmlspecialchars($otherName, ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($activeConv['product_name'])): ?>
                <div class="mh-product">Sobre: <?= htmlspecialchars($activeConv['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <div class="chat-main-messages" id="buyerChatMsgs">
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
            <?php
                // Detect system message type
                $msgText = (string)$m['message'];
                $sysType = '';
                $sysContent = $msgText;
                if (preg_match('/^\[(INSTRUCOES_VENDA|ENTREGA_AUTO|CODIGO_ENTREGA|SISTEMA)\]\n/', $msgText, $sysMatch)) {
                    $sysType = match ($sysMatch[1]) {
                        'INSTRUCOES_VENDA' => 'instructions',
                        'ENTREGA_AUTO'     => 'delivery',
                        'CODIGO_ENTREGA'   => 'delivery_code',
                        default            => 'system',
                    };
                    $sysContent = substr($msgText, strlen($sysMatch[0]));
                }
            ?>
            <?php if ($sysType !== ''): ?>
            <div class="chat-msg theirs system-msg type-<?= $sysType ?>" data-msg-id="<?= (int)$m['id'] ?>">
                <div class="sys-header">
                    <?php if ($sysType === 'instructions'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    Instruções da compra
                    <?php elseif ($sysType === 'delivery'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4m4-5l5 5 5-5m-5 5V3"/></svg>
                    Produto entregue
                    <?php elseif ($sysType === 'delivery_code'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    Código de entrega
                    <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Sistema
                    <?php endif; ?>
                </div>
                <?php if ($sysType === 'delivery'): ?>
                <?php
                    // Extract the delivery box content between ━━ lines
                    if (preg_match('/━+\n(.*?)\n━+/s', $sysContent, $boxMatch)) {
                        $beforeBox = trim(substr($sysContent, 0, strpos($sysContent, '━')));
                        $boxContent = trim($boxMatch[1]);
                        $afterBox = trim(substr($sysContent, strrpos($sysContent, '━') + strlen('━')));
                ?>
                <div class="sys-content"><?= nl2br(htmlspecialchars($beforeBox, ENT_QUOTES, 'UTF-8')) ?></div>
                <div class="sys-delivery-box">
                    <button class="sys-copy-btn" onclick="navigator.clipboard.writeText(this.parentElement.querySelector('.sys-box-text').textContent.trim());this.textContent='✓ Copiado';setTimeout(()=>this.textContent='Copiar',2000)">Copiar</button>
                    <span class="sys-box-text"><?= nl2br(htmlspecialchars($boxContent, ENT_QUOTES, 'UTF-8')) ?></span>
                </div>
                <?php if ($afterBox): ?>
                <div class="sys-content"><?= nl2br(htmlspecialchars($afterBox, ENT_QUOTES, 'UTF-8')) ?></div>
                <?php endif; ?>
                <?php } else { ?>
                <div class="sys-content"><?= nl2br(htmlspecialchars($sysContent, ENT_QUOTES, 'UTF-8')) ?></div>
                <?php } ?>
                <?php elseif ($sysType === 'delivery_code'): ?>
                <?php
                    // Extract 6-char delivery code
                    $codeDisplay = '';
                    if (preg_match('/Código de entrega:\s*([A-Z0-9]{4,8})/i', $sysContent, $codeMatch)) {
                        $codeDisplay = strtoupper($codeMatch[1]);
                    }
                ?>
                <div class="sys-content"><?= nl2br(htmlspecialchars(preg_replace('/🔑\s*Código de entrega:\s*[A-Z0-9]+/i', '', $sysContent), ENT_QUOTES, 'UTF-8')) ?></div>
                <?php if ($codeDisplay): ?>
                <div class="sys-code">
                    <?php for ($ci = 0; $ci < strlen($codeDisplay); $ci++): ?>
                    <span><?= $codeDisplay[$ci] ?></span>
                    <?php endfor; ?>
                </div>
                <button class="sys-copy-btn" style="position:relative;display:inline-block;margin-top:4px" onclick="navigator.clipboard.writeText('<?= $codeDisplay ?>');this.textContent='✓ Copiado';setTimeout(()=>this.textContent='Copiar código',2000)">Copiar código</button>
                <?php endif; ?>
                <?php else: ?>
                <div class="sys-content"><?= nl2br(htmlspecialchars($sysContent, ENT_QUOTES, 'UTF-8')) ?></div>
                <?php endif; ?>
                <div class="msg-time"><?= (new DateTime($m['criado_em']))->format('H:i') ?></div>
            </div>
            <?php else: ?>
            <div class="chat-msg <?= (int)$m['sender_id'] === $uid ? 'mine' : 'theirs' ?>" data-msg-id="<?= (int)$m['id'] ?>">
                <div><?= htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="msg-time"><?= (new DateTime($m['criado_em']))->format('H:i') ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Input -->
        <div class="chat-main-input">
            <textarea id="buyerChatInput" rows="1" placeholder="Digite uma mensagem..." maxlength="2000"></textarea>
            <button class="send-btn" id="buyerChatSend" title="Enviar">
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
            const input    = document.getElementById('buyerChatInput');
            const sendBtn  = document.getElementById('buyerChatSend');
            const msgArea  = document.getElementById('buyerChatMsgs');
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
                el.dataset.msgId = m.id;
                const msg = m.message || '';

                // Detect system message types
                const sysMatch = msg.match(/^\[(INSTRUCOES_VENDA|ENTREGA_AUTO|CODIGO_ENTREGA|SISTEMA)\]\n/);
                if (sysMatch) {
                    const typeMap = { 'INSTRUCOES_VENDA': 'instructions', 'ENTREGA_AUTO': 'delivery', 'CODIGO_ENTREGA': 'delivery_code', 'SISTEMA': 'system' };
                    const sysType = typeMap[sysMatch[1]] || 'system';
                    const sysContent = msg.substring(sysMatch[0].length);
                    el.className = 'chat-msg theirs system-msg type-' + sysType;

                    // Header
                    const hdr = document.createElement('div');
                    hdr.className = 'sys-header';
                    const icons = {
                        'instructions': '📋 Instruções da compra',
                        'delivery': '📦 Produto entregue',
                        'delivery_code': '🔑 Código de entrega',
                        'system': 'ℹ️ Sistema'
                    };
                    hdr.textContent = icons[sysType] || 'Sistema';
                    el.appendChild(hdr);

                    // Content
                    if (sysType === 'delivery') {
                        const boxMatch = sysContent.match(/━+\n([\s\S]*?)\n━+/);
                        if (boxMatch) {
                            const before = sysContent.substring(0, sysContent.indexOf('━')).trim();
                            const boxText = boxMatch[1].trim();
                            const after = sysContent.substring(sysContent.lastIndexOf('━') + 1).trim();
                            if (before) { const bt = document.createElement('div'); bt.className = 'sys-content'; bt.textContent = before; el.appendChild(bt); }
                            const box = document.createElement('div');
                            box.className = 'sys-delivery-box';
                            box.innerHTML = '<button class="sys-copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.textContent.trim());this.textContent=\'✓ Copiado\';setTimeout(()=>this.textContent=\'Copiar\',2000)">Copiar</button><span class="sys-box-text">' + escHtml(boxText) + '</span>';
                            el.appendChild(box);
                            if (after) { const at = document.createElement('div'); at.className = 'sys-content'; at.textContent = after; el.appendChild(at); }
                        } else {
                            const ct = document.createElement('div'); ct.className = 'sys-content'; ct.textContent = sysContent; el.appendChild(ct);
                        }
                    } else if (sysType === 'delivery_code') {
                        const codeMatch = sysContent.match(/Código de entrega:\s*([A-Z0-9]{4,8})/i);
                        const ct = document.createElement('div'); ct.className = 'sys-content'; ct.textContent = sysContent.replace(/🔑\s*Código de entrega:\s*[A-Z0-9]+/i, '').trim(); el.appendChild(ct);
                        if (codeMatch) {
                            const code = codeMatch[1].toUpperCase();
                            const codeDiv = document.createElement('div'); codeDiv.className = 'sys-code';
                            for (let i = 0; i < code.length; i++) { const sp = document.createElement('span'); sp.textContent = code[i]; codeDiv.appendChild(sp); }
                            el.appendChild(codeDiv);
                            const copyBtn = document.createElement('button');
                            copyBtn.className = 'sys-copy-btn'; copyBtn.style.cssText = 'position:relative;display:inline-block;margin-top:4px';
                            copyBtn.textContent = 'Copiar código';
                            copyBtn.onclick = function() { navigator.clipboard.writeText(code); this.textContent = '✓ Copiado'; setTimeout(() => this.textContent = 'Copiar código', 2000); };
                            el.appendChild(copyBtn);
                        }
                    } else {
                        const ct = document.createElement('div'); ct.className = 'sys-content'; ct.textContent = sysContent; el.appendChild(ct);
                    }
                } else {
                    el.className = 'chat-msg ' + (m.is_mine ? 'mine' : 'theirs');
                    const t = document.createElement('div');
                    t.textContent = msg;
                    el.appendChild(t);
                }
                const tm = document.createElement('div');
                tm.className = 'msg-time';
                tm.textContent = formatTime(m.criado_em);
                el.appendChild(tm);
                msgArea.appendChild(el);
                if (m.id > lastMsgId) lastMsgId = m.id;
                msgArea.scrollTop = msgArea.scrollHeight;
            }

            function escHtml(s) {
                return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<?php
include __DIR__ . '/../views/partials/user_layout_end.php';
include __DIR__ . '/../views/partials/footer.php';
