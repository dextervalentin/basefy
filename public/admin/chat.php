<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\chat.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/chat.php';

exigirAdmin();

$db   = new Database();
$conn = $db->connect();

chatEnsureTables($conn);

$q = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array(($_pp = (int)($_GET['pp'] ?? 10)), [5,10,20]) ? $_pp : 10;

$totalConversations = chatCountAllConversations($conn, $q);
$totalPaginas = max(1, (int)ceil($totalConversations / $pp));
$pagina = min($pagina, $totalPaginas);
$conversations = chatListAllConversations($conn, $pagina, $pp, $q);

// Stats (unfiltered)
$totalConvsAll = $q !== '' ? chatCountAllConversations($conn) : $totalConversations;
try {
    $stTM = $conn->prepare("SELECT COUNT(*) AS cnt FROM chat_messages");
    $stTM->execute(); $totalMsgs = (int)($stTM->get_result()->fetch_assoc()['cnt'] ?? 0); $stTM->close();
} catch (Throwable $e) { $totalMsgs = 0; }
try {
    $stAT = $conn->prepare("SELECT COUNT(DISTINCT conversation_id) AS cnt FROM chat_messages WHERE criado_em::date = CURRENT_DATE");
    $stAT->execute(); $activeToday = (int)($stAT->get_result()->fetch_assoc()['cnt'] ?? 0); $stAT->close();
} catch (Throwable $e) { $activeToday = 0; }

$pageTitle  = 'Suporte';
$activeMenu = 'suporte';
$adminSupportTab = 'chat';

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<style>
.chat-monitor-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.chat-stat-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    padding: 20px;
    text-align: center;
}
.chat-stat-card .stat-value {
    font-size: 28px;
    font-weight: 800;
    color: var(--t-accent);
}
.chat-stat-card .stat-label {
    font-size: 12px;
    color: #888;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Conversations table */
.chat-table-wrap {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.chat-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.chat-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.02);
}
.chat-table td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    color: #d4d4d4;
    vertical-align: middle;
}
.chat-table tr:hover td {
    background: rgba(255,255,255,0.03);
}
.chat-table .conv-name {
    font-weight: 600;
    color: #e5e5e5;
}
.chat-table .conv-email {
    font-size: 11px;
    color: #666;
}
.chat-table .conv-preview {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #888;
}
.chat-table .msg-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 24px;
    padding: 0 8px;
    background: rgba(var(--t-accent-rgb),0.15);
    color: var(--t-accent);
    border-radius: 999px;
    font-weight: 700;
    font-size: 12px;
}
.chat-table .view-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 14px;
    border-radius: 10px;
    background: rgba(var(--t-accent-rgb),0.1);
    border: 1px solid rgba(var(--t-accent-rgb),0.25);
    color: var(--t-accent);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.chat-table .view-btn:hover {
    background: rgba(var(--t-accent-rgb),0.2);
    border-color: rgba(var(--t-accent-rgb),0.5);
}

/* Thread modal */
.chat-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 2000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.chat-modal-overlay.open { display: flex; }
.chat-modal {
    background: #111;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    width: 100%;
    max-width: 600px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
@media (max-width: 640px) {
  .chat-modal {
    max-width: calc(100vw - 24px);
    max-height: 90vh;
    border-radius: 16px;
  }
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
}
.chat-modal-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.chat-modal-header .modal-info { flex: 1; }
.chat-modal-header .modal-title {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
}
.chat-modal-header .modal-subtitle {
    font-size: 12px;
    color: #888;
    margin-top: 2px;
}
.chat-modal-close {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #999;
    transition: all 0.2s;
}
.chat-modal-close:hover { background: rgba(255,255,255,0.1); color: #fff; }
.chat-modal-close svg { width: 16px; height: 16px; }

.chat-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}

.admin-msg .admin-attachment {
    display: block;
    overflow: hidden;
    border-radius: 12px;
    margin-bottom: 8px;
    background: rgba(0,0,0,0.18);
}
.admin-msg .admin-attachment img {
    display: block;
    width: 100%;
    max-width: 280px;
    max-height: 320px;
    object-fit: cover;
}
.admin-msg .admin-attachment-caption {
    white-space: pre-wrap;
    word-break: break-word;
}

.admin-msg {
    max-width: 82%;
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 13px;
    line-height: 1.5;
    word-wrap: break-word;
}
.admin-msg.buyer {
    align-self: flex-start;
    background: rgba(136,0,228,0.12);
    color: #93c5fd;
    border: 1px solid rgba(136,0,228,0.2);
    border-bottom-left-radius: 6px;
}
.admin-msg.vendor {
    align-self: flex-end;
    background: rgba(var(--t-accent-rgb),0.12);
    color: #86efac;
    border: 1px solid rgba(var(--t-accent-rgb),0.2);
    border-bottom-right-radius: 6px;
}
.admin-msg .admin-msg-sender {
    font-size: 10px;
    font-weight: 700;
    margin-bottom: 2px;
    opacity: 0.7;
}
.admin-msg.buyer .admin-msg-sender { color: #A855F7; }
.admin-msg.vendor .admin-msg-sender { color: #A855F7; }
.admin-msg.admin {
    align-self: center;
    max-width: 90%;
    background: linear-gradient(135deg, rgba(168,85,247,0.16), rgba(136,0,228,0.08));
    color: #f3e8ff;
    border: 1px solid rgba(168,85,247,0.28);
    box-shadow: 0 12px 32px rgba(88,28,135,0.18);
}
.admin-msg.admin .admin-msg-sender { color: #d8b4fe; }
.admin-msg .admin-msg-time {
    font-size: 10px;
    margin-top: 4px;
    opacity: 0.5;
    color: inherit;
}
.admin-msg-date {
    text-align: center;
    font-size: 11px;
    color: #555;
    padding: 8px 0;
    font-weight: 500;
}

/* Loading state */
.chat-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: #555;
    font-size: 13px;
}
.chat-loading svg {
    width: 20px;
    height: 20px;
    animation: spin 1s linear infinite;
    margin-right: 8px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Empty state */
.chat-no-data {
    text-align: center;
    padding: 60px 20px;
    color: #555;
}
.chat-no-data svg { width: 48px; height: 48px; color: #333; margin: 0 auto 12px; }
.chat-no-data p { font-size: 14px; }

.chat-modal-compose-wrap {
    padding: 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
    background: linear-gradient(180deg, rgba(168,85,247,0.04), rgba(0,0,0,0.2));
}
.chat-modal-compose {
    display: flex;
    align-items: flex-end;
    gap: 10px;
}
.chat-modal-attach {
    width: 42px;
    height: 42px;
    border-radius: 13px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #999;
    transition: all 0.2s;
}
.chat-modal-attach:hover { border-color: rgba(168,85,247,0.45); color: #fff; }
.chat-modal-attach.is-ready { border-color: rgba(16,185,129,0.45); background: rgba(16,185,129,0.12); color: #6ee7b7; }
.chat-modal-compose textarea {
    flex: 1;
    min-height: 42px;
    max-height: 120px;
    resize: vertical;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.05);
    color: #fff;
    outline: none;
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.4;
}
.chat-modal-compose textarea:focus { border-color: rgba(168,85,247,0.45); }
.chat-modal-send {
    width: 42px;
    height: 42px;
    border-radius: 13px;
    background: linear-gradient(135deg, var(--t-accent), var(--t-accent-hover));
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .18s, filter .18s, opacity .18s;
}
.chat-modal-send:hover { filter: brightness(1.08); transform: translateY(-1px); }
.chat-modal-send:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.chat-modal-attachment-bar {
    display: none;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
    padding: 9px 12px;
    border-radius: 12px;
    border: 1px solid rgba(16,185,129,0.2);
    background: rgba(16,185,129,0.08);
    color: #d1fae5;
    font-size: 12px;
}
.chat-modal-attachment-bar .attachment-name {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-modal-attachment-bar .attachment-clear {
    border: none;
    background: transparent;
    color: #fca5a5;
    font-size: 12px;
    cursor: pointer;
}
</style>

<!-- Stats cards -->
<?php include __DIR__ . '/../../views/partials/admin_suporte_tabs.php'; ?>

<div class="chat-monitor-stats">
    <div class="chat-stat-card">
        <div class="stat-value"><?= $totalConvsAll ?></div>
        <div class="stat-label">Conversas</div>
    </div>
    <div class="chat-stat-card">
        <div class="stat-value"><?= $totalMsgs ?></div>
        <div class="stat-label">Mensagens</div>
    </div>
    <div class="chat-stat-card">
        <div class="stat-value"><?= $activeToday ?></div>
        <div class="stat-label">Ativas hoje</div>
    </div>
</div>

<!-- Premium Filter -->
<form method="get" class="mb-4 rounded-2xl border border-blackx3 bg-blackx/50 p-3 md:p-4">
  <div class="flex flex-col md:flex-row md:items-end md:flex-nowrap gap-3">
    <div class="md:flex-1">
      <label class="block text-xs text-zinc-500 mb-1">Busca</label>
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por comprador, vendedor, loja ou produto" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 outline-none focus:border-greenx">
    </div>
    <div class="hidden md:flex md:w-auto md:ml-auto items-center gap-2">
      <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 whitespace-nowrap transition-all">
        <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar
      </button>
      <a href="chat" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
        <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
      </a>
    </div>
  </div>
  <div class="mt-3 md:hidden flex items-center gap-2">
    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-greenx to-greenxd hover:from-greenx2 hover:to-greenxd text-white font-semibold px-4 py-2.5 transition-all">
      <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> Aplicar filtros
    </button>
    <a href="chat" title="Limpar filtros" class="inline-flex items-center justify-center rounded-xl border border-blackx3 w-10 h-10 text-zinc-300 hover:border-greenx hover:text-white transition-all">
      <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
    </a>
  </div>
</form>

<!-- Conversations table -->
<div class="chat-table-wrap">
    <table class="chat-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Comprador</th>
                <th>Vendedor / Loja</th>
                <th>Produto</th>
                <th>Msgs</th>
                <th>Última mensagem</th>
                <th>Data</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="chatConvBody">
            <?php if (empty($conversations)): ?>
            <tr><td colspan="8" class="chat-no-data">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p>Nenhuma conversa encontrada</p>
            </td></tr>
            <?php else: ?>
            <?php foreach ($conversations as $c):
                $cLastMsgTime = $c['last_msg_time'] ?? '';
                $cTimeStr = '—';
                if ($cLastMsgTime) {
                    try {
                        $dt = new DateTime($cLastMsgTime);
                        $now = new DateTime();
                        if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
                            $cTimeStr = 'Hoje ' . $dt->format('H:i');
                        } elseif ($dt->format('Y-m-d') === (clone $now)->modify('-1 day')->format('Y-m-d')) {
                            $cTimeStr = 'Ontem ' . $dt->format('H:i');
                        } else {
                            $cTimeStr = $dt->format('d/m/y') . ' ' . $dt->format('H:i');
                        }
                    } catch (Throwable $e) {}
                }
                $buyerLs = '';
                if (!empty($c['buyer_last_seen'])) {
                    try {
                        $lsDt = new DateTime($c['buyer_last_seen']);
                        $lsDiff = (new DateTime())->getTimestamp() - $lsDt->getTimestamp();
                        if ($lsDiff < 300) $buyerLs = 'Online';
                        elseif ($lsDiff < 3600) $buyerLs = 'há ' . intdiv($lsDiff, 60) . ' min';
                        elseif ($lsDiff < 86400) $buyerLs = 'há ' . intdiv($lsDiff, 3600) . 'h';
                        elseif ($lsDiff < 172800) $buyerLs = 'ontem';
                        else $buyerLs = $lsDt->format('d/m');
                    } catch (Throwable $e) {}
                }
                $vendorLs = '';
                if (!empty($c['vendor_last_seen'])) {
                    try {
                        $lsDt = new DateTime($c['vendor_last_seen']);
                        $lsDiff = (new DateTime())->getTimestamp() - $lsDt->getTimestamp();
                        if ($lsDiff < 300) $vendorLs = 'Online';
                        elseif ($lsDiff < 3600) $vendorLs = 'há ' . intdiv($lsDiff, 60) . ' min';
                        elseif ($lsDiff < 86400) $vendorLs = 'há ' . intdiv($lsDiff, 3600) . 'h';
                        elseif ($lsDiff < 172800) $vendorLs = 'ontem';
                        else $vendorLs = $lsDt->format('d/m');
                    } catch (Throwable $e) {}
                }
            ?>
            <tr>
                <td style="color:#555">#<?= (int)$c['id'] ?></td>
                <td>
                    <div class="conv-name"><?= htmlspecialchars($c['buyer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="conv-email"><?= htmlspecialchars($c['buyer_email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($buyerLs): ?><div style="font-size:10px;color:<?= $buyerLs === 'Online' ? 'var(--t-accent)' : '#555' ?>">● <?= $buyerLs ?></div><?php endif; ?>
                </td>
                <td>
                    <div class="conv-name"><?= htmlspecialchars($c['store_name'] ?: $c['vendor_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="conv-email"><?= htmlspecialchars($c['vendor_email'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($vendorLs): ?><div style="font-size:10px;color:<?= $vendorLs === 'Online' ? 'var(--t-accent)' : '#555' ?>">● <?= $vendorLs ?></div><?php endif; ?>
                </td>
                <td><?= $c['product_name'] ? htmlspecialchars($c['product_name'], ENT_QUOTES, 'UTF-8') : '<span style="color:#444">—</span>' ?></td>
                <td><span class="msg-count-badge"><?= (int)($c['total_messages'] ?? 0) ?></span></td>
                <td class="conv-preview"><?= htmlspecialchars(mb_substr($c['last_message'] ?? '—', 0, 50), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="white-space:nowrap;color:#666"><?= $cTimeStr ?></td>
                <td><button class="view-btn" onclick="viewThread(<?= (int)$c['id'] ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Ver
                </button></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
    $paginaAtual  = $pagina;
    include __DIR__ . '/../../views/partials/pagination.php';
?>

<!-- Thread modal -->
<div class="chat-modal-overlay" id="chatModalOverlay">
    <div class="chat-modal">
        <div class="chat-modal-header">
            <div class="modal-info">
                <div class="modal-title" id="modalTitle">Conversa</div>
                <div class="modal-subtitle" id="modalSubtitle"></div>
            </div>
            <button class="chat-modal-close" id="chatModalClose" title="Fechar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="chat-modal-body" id="modalBody">
            <div class="chat-loading"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Carregando…</div>
        </div>
        <div class="chat-modal-compose-wrap">
        <div class="chat-modal-attachment-bar" id="chatAdminAttachmentBar">
            <span class="attachment-name" id="chatAdminAttachmentName"></span>
            <button type="button" class="attachment-clear" id="chatAdminAttachmentClear">Remover</button>
        </div>
        <form class="chat-modal-compose" id="chatAdminReplyForm" data-no-transition>
            <input type="file" id="chatAdminAttachment" accept="image/*" hidden>
            <label class="chat-modal-attach" id="chatAdminAttachBtn" for="chatAdminAttachment" title="Enviar print">
                <i data-lucide="paperclip" class="w-4 h-4"></i>
            </label>
            <textarea id="chatAdminReply" rows="1" maxlength="2000" placeholder="Responder como Equipe Basefy..."></textarea>
            <button type="submit" class="chat-modal-send" id="chatAdminReplyBtn" title="Enviar resposta">
                <i data-lucide="send" class="w-4 h-4"></i>
            </button>
        </form>
        </div>
    </div>
</div>

<script>
(function(){
    const API = '<?= BASE_PATH ?>/api/chat';
    const APP_BASE = '<?= BASE_PATH ?>';
    const modal = document.getElementById('chatModalOverlay');
    const modalClose = document.getElementById('chatModalClose');
    const modalTitle = document.getElementById('modalTitle');
    const modalSubtitle = document.getElementById('modalSubtitle');
    const modalBody = document.getElementById('modalBody');
    const replyForm = document.getElementById('chatAdminReplyForm');
    const replyInput = document.getElementById('chatAdminReply');
    const replyBtn = document.getElementById('chatAdminReplyBtn');
    const attachInput = document.getElementById('chatAdminAttachment');
    const attachBtn = document.getElementById('chatAdminAttachBtn');
    const attachBar = document.getElementById('chatAdminAttachmentBar');
    const attachName = document.getElementById('chatAdminAttachmentName');
    const attachClear = document.getElementById('chatAdminAttachmentClear');
    const autoOpenConversation = <?= (int)($_GET['open'] ?? 0) ?>;
    let activeConvId = 0;

    function selectedAttachment() {
        return attachInput && attachInput.files && attachInput.files[0] ? attachInput.files[0] : null;
    }

    function syncAttachmentBar() {
        const file = selectedAttachment();
        if (!attachBar || !attachBtn || !attachName) return;
        if (file) {
            attachBar.style.display = 'flex';
            attachName.textContent = file.name;
            attachBtn.classList.add('is-ready');
            attachBtn.title = 'Imagem pronta para envio: ' + file.name;
        } else {
            attachBar.style.display = 'none';
            attachName.textContent = '';
            attachBtn.classList.remove('is-ready');
            attachBtn.title = 'Enviar print';
        }
    }

    function resolveAttachmentUrl(raw) {
        if (!raw) return '';
        if (/^https?:\/\//i.test(raw)) return raw;
        if (raw.indexOf('media:') === 0) return APP_BASE + '/api/media?id=' + raw.slice(6);
        return raw.charAt(0) === '/' ? raw : (APP_BASE + '/' + raw.replace(/^\/+/, ''));
    }

    function parseAttachmentMessage(raw) {
        const text = String(raw || '').replace(/\r\n?/g, '\n').trim();
        const prefix = '[ANEXO_IMAGEM]\n';
        if (text.indexOf(prefix) !== 0) return null;
        const payload = text.slice(prefix.length);
        const separator = payload.indexOf('\n');
        const mediaRef = (separator === -1 ? payload : payload.slice(0, separator)).trim();
        if (!mediaRef) return null;
        const caption = separator === -1 ? '' : payload.slice(separator + 1).trim();
        return { mediaRef, caption, url: resolveAttachmentUrl(mediaRef) };
    }

    function appendAttachmentContent(container, attachment) {
        if (attachment.url) {
            const link = document.createElement('a');
            link.className = 'admin-attachment';
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            const img = document.createElement('img');
            img.src = attachment.url;
            img.alt = attachment.caption || 'Imagem enviada no chat';
            img.loading = 'lazy';
            link.appendChild(img);
            container.appendChild(link);
        }
        if (attachment.caption) {
            const caption = document.createElement('div');
            caption.className = 'admin-attachment-caption';
            caption.textContent = attachment.caption;
            container.appendChild(caption);
        }
    }

    if (attachInput) {
        attachInput.addEventListener('change', () => {
            const file = selectedAttachment();
            if (file && (!file.type || file.type.indexOf('image/') !== 0)) {
                alert('Envie apenas imagens no chat.');
                attachInput.value = '';
            }
            if (file && file.size > 5 * 1024 * 1024) {
                alert('A imagem deve ter no máximo 5MB.');
                attachInput.value = '';
            }
            syncAttachmentBar();
        });
    }

    if (attachClear) {
        attachClear.addEventListener('click', () => {
            if (attachInput) attachInput.value = '';
            syncAttachmentBar();
            replyInput.focus();
        });
    }

    // View thread
    window.viewThread = async function(convId) {
        activeConvId = convId;
        modal.classList.add('open');
        modalTitle.textContent = 'Conversa #' + convId;
        modalSubtitle.textContent = 'Carregando…';
        modalBody.innerHTML = '<div class="chat-loading"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Carregando…</div>';
        if (replyInput) replyInput.value = '';
        if (attachInput) attachInput.value = '';
        syncAttachmentBar();

        try {
            const r = await fetch(API + '?action=admin_messages&conversation_id=' + convId);
            const j = await r.json();

            if (!j.ok) {
                modalBody.innerHTML = '<div class="chat-no-data"><p>' + escHtml(j.msg) + '</p></div>';
                return;
            }

            const conv = j.conversation;
            modalTitle.textContent = (conv.store_name || conv.vendor_name) + ' ↔ ' + conv.buyer_name;
            modalSubtitle.textContent = conv.product_name ? '📦 ' + conv.product_name : 'Conversa geral';

            if (j.messages.length === 0) {
                modalBody.innerHTML = '<div class="chat-no-data"><p>Nenhuma mensagem nesta conversa</p></div>';
                return;
            }

            modalBody.innerHTML = '';
            let lastDate = '';

            j.messages.forEach(m => {
                const d = formatDateFull(m.criado_em);
                if (d !== lastDate) {
                    lastDate = d;
                    const dateEl = document.createElement('div');
                    dateEl.className = 'admin-msg-date';
                    dateEl.textContent = d;
                    modalBody.appendChild(dateEl);
                }

                appendThreadMessage(m);
            });

            requestAnimationFrame(() => { modalBody.scrollTop = modalBody.scrollHeight; });

        } catch(e) {
            console.error('Load admin thread error', e);
            modalBody.innerHTML = '<div class="chat-no-data"><p>Erro ao carregar mensagens</p></div>';
        }
    };

    if (replyForm) {
        replyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const text = (replyInput.value || '').trim();
            const file = selectedAttachment();
            if (!activeConvId || (!text && !file)) return;
            replyBtn.disabled = true;
            if (attachBtn) attachBtn.style.pointerEvents = 'none';
            try {
                const fd = new FormData();
                fd.append('conversation_id', activeConvId);
                fd.append('message', text);
                if (file) fd.append('attachment', file);
                const r = await fetch(API + '?action=admin_send', { method: 'POST', body: fd });
                const j = await r.json();
                if (!j.ok) {
                    alert(j.msg || 'Erro ao enviar mensagem.');
                    return;
                }
                appendThreadMessage(j.msg);
                replyInput.value = '';
                if (attachInput) attachInput.value = '';
                syncAttachmentBar();
                requestAnimationFrame(() => { modalBody.scrollTop = modalBody.scrollHeight; });
            } catch(e) {
                console.error('Admin chat send error', e);
                alert('Erro ao enviar mensagem.');
            } finally {
                replyBtn.disabled = false;
                if (attachBtn) attachBtn.style.pointerEvents = '';
                replyInput.focus();
            }
        });
    }

    // Close modal
    modalClose.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('open');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') modal.classList.remove('open');
    });

    // Helpers
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function appendThreadMessage(m) {
        const el = document.createElement('div');
        el.className = 'admin-msg ' + (m.is_admin ? 'admin' : (m.is_buyer ? 'buyer' : 'vendor'));

        const sender = document.createElement('div');
        sender.className = 'admin-msg-sender';
        sender.textContent = m.is_admin ? 'Equipe Basefy' : (m.is_buyer ? 'Comprador: ' + m.sender_name : 'Vendedor: ' + m.sender_name);
        el.appendChild(sender);

        const rawMessage = String(m.message || '');
        const visibleMessage = m.is_admin ? rawMessage.replace(/^Equipe Basefy:\s*\n?/, '') : rawMessage;
        const attachment = parseAttachmentMessage(visibleMessage);
        if (attachment) {
            appendAttachmentContent(el, attachment);
        } else {
            const text = document.createElement('div');
            text.textContent = visibleMessage;
            el.appendChild(text);
        }

        const time = document.createElement('div');
        time.className = 'admin-msg-time';
        time.textContent = formatTime(m.criado_em) + (m.is_read ? '' : ' · não lida');
        el.appendChild(time);

        modalBody.appendChild(el);
    }

    function formatDateFull(dt) {
        if (!dt) return '';
        const d = new Date(dt.replace(' ', 'T'));
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Hoje';
        if (d.toDateString() === yesterday.toDateString()) return 'Ontem';
        return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });
    }

    function formatTime(dt) {
        if (!dt) return '';
        const d = new Date(dt.replace(' ', 'T'));
        return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    syncAttachmentBar();
    if (autoOpenConversation > 0) {
        window.requestAnimationFrame(() => viewThread(autoOpenConversation));
    }
})();
</script>

<?php
include __DIR__ . '/../../views/partials/admin_layout_end.php';
include __DIR__ . '/../../views/partials/footer.php';
?>
