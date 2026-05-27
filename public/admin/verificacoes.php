<?php
// filepath: c:\xampp\htdocs\mercado_admin\public\admin\verificacoes.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/media.php';
exigirAdmin();

$db   = new Database();
$conn = $db->connect();

function verAdminMediaUrl(?string $raw, int $userId = 0): ?string
{
  $raw = trim(str_replace('\\', '/', (string)$raw));
  if ($raw === '') return null;
  if (str_starts_with($raw, 'media:')) {
    $mediaId = (int)substr($raw, 6);
    if ($mediaId <= 0) return null;

    $meta = mediaGetMeta($mediaId);
    if (!$meta) return null;

    $entityType = strtolower((string)($meta['entity_type'] ?? ''));
    $entityId = (int)($meta['entity_id'] ?? 0);
    $originalName = strtolower(trim((string)($meta['original_name'] ?? '')));
    if ($entityType !== 'verification' || ($userId > 0 && $entityId !== $userId)) {
      return null;
    }
    if (in_array($originalName, ['bra.png', 'brasil.png', 'brazil.png'], true)) {
      return null;
    }

    return BASE_PATH . '/api/media?id=' . urlencode((string)$mediaId);
  }
  if (preg_match('~^https?://~i', $raw)) return $raw;

  $normalized = ltrim($raw, '/');
  foreach (['storage/', 'uploads/', 'public/uploads/'] as $allowedPrefix) {
    if (str_starts_with($normalized, $allowedPrefix)) {
      return BASE_PATH . '/' . $normalized;
    }
  }

  return null;
}

function verAdminUserDetails($conn, int $uid): array
{
  $st = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
  $st->bind_param('i', $uid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc() ?: [];
  $st->close();
  return $row;
}

function verAdminDocByType($conn, int $uid, string $tipo): array
{
  $st = $conn->prepare("SELECT status, arquivo, observacao, criado_em FROM user_verification_docs WHERE user_id = ? AND tipo_doc = ? ORDER BY id DESC LIMIT 1");
  $st->bind_param('is', $uid, $tipo);
  $st->execute();
  $row = $st->get_result()->fetch_assoc() ?: [];
  $st->close();
  return $row;
}

/* ═══ POST: Aprovar / Rejeitar verificação (aplica a dados + documentos) ═══ */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction  = (string)($_POST['ver_action'] ?? '');
    $postUserId  = (int)($_POST['user_id'] ?? 0);
    $postObs     = trim((string)($_POST['observacao'] ?? ''));
    $redirectTab = 'pendente';

    if ($postUserId < 1) {
        $_SESSION['verif_flash'] = ['type' => 'error', 'msg' => 'Parâmetros inválidos.'];
    } elseif ($postAction === 'aprovar') {
        try {
        $stDocCount = $conn->prepare("SELECT COUNT(DISTINCT tipo_doc) AS qtd FROM user_verification_docs WHERE user_id = ? AND COALESCE(TRIM(arquivo), '') <> '' AND LOWER(COALESCE(status, '')) <> 'rejeitado'");
        $stDocCount->bind_param('i', $postUserId);
        $stDocCount->execute();
        $docCount = (int)($stDocCount->get_result()->fetch_assoc()['qtd'] ?? 0);
        $stDocCount->close();

        if ($docCount < 3) {
          $obsSemDocs = 'Documento inválido. Reenvie o documento.';
          $stRejectMissing = $conn->prepare("UPDATE user_verifications SET status = 'rejeitado', observacao = ?, atualizado = CURRENT_TIMESTAMP WHERE user_id = ? AND tipo IN ('dados', 'documentos') AND status = 'pendente'");
          $stRejectMissing->bind_param('si', $obsSemDocs, $postUserId);
          $stRejectMissing->execute();
          $stRejectMissing->close();

          $_SESSION['verif_flash'] = ['type' => 'error', 'msg' => "KYC do usuário #{$postUserId} rejeitado: documentos ausentes."];
          $redirectTab = 'rejeitado';
          throw new RuntimeException('__redirect_only__');
        }

            $st = $conn->prepare("UPDATE user_verifications SET status = 'verificado', observacao = ?, atualizado = CURRENT_TIMESTAMP WHERE user_id = ? AND tipo IN ('dados', 'documentos') AND status = 'pendente'");
            $st->bind_param('si', $postObs, $postUserId);
            $st->execute();
            $st->close();

            $stDocs = $conn->prepare("UPDATE user_verification_docs SET status = 'aprovado' WHERE user_id = ? AND status = 'pendente'");
            if ($stDocs) {
                $stDocs->bind_param('i', $postUserId);
                $stDocs->execute();
                $stDocs->close();
            }
            $_SESSION['verif_flash'] = ['type' => 'ok', 'msg' => "KYC do usuário #{$postUserId} aprovado com sucesso."];
            $redirectTab = 'verificado';
        } catch (\Throwable $e) {
          if ($e->getMessage() !== '__redirect_only__') {
            $_SESSION['verif_flash'] = ['type' => 'error', 'msg' => 'Erro ao aprovar: ' . $e->getMessage()];
          }
        }
    } elseif ($postAction === 'rejeitar') {
        try {
            $obsRej = $postObs ?: 'Documento inválido. Reenvie o documento.';
            $st = $conn->prepare("UPDATE user_verifications SET status = 'rejeitado', observacao = ?, atualizado = CURRENT_TIMESTAMP WHERE user_id = ? AND tipo IN ('dados', 'documentos') AND status = 'pendente'");
            $st->bind_param('si', $obsRej, $postUserId);
            $st->execute();
            $st->close();

            $stDocs = $conn->prepare("UPDATE user_verification_docs SET status = 'rejeitado', observacao = ? WHERE user_id = ? AND status = 'pendente'");
            if ($stDocs) {
                $stDocs->bind_param('si', $obsRej, $postUserId);
                $stDocs->execute();
                $stDocs->close();
            }
            $_SESSION['verif_flash'] = ['type' => 'ok', 'msg' => "KYC do usuário #{$postUserId} rejeitado."];
            $redirectTab = 'rejeitado';
        } catch (\Throwable $e) {
            $_SESSION['verif_flash'] = ['type' => 'error', 'msg' => 'Erro ao rejeitar: ' . $e->getMessage()];
        }
    }

    // PRG: redirect to the appropriate tab so the result is visible
    $qParam = trim((string)($_GET['q'] ?? ''));
    $rUrl = 'verificacoes?status=' . urlencode($redirectTab);
    if ($qParam !== '') $rUrl .= '&q=' . urlencode($qParam);
    header('Location: ' . $rUrl);
    exit;
}

// Flash messages from PRG redirect
$msgOk = '';
$msgErr = '';
if (isset($_SESSION['verif_flash'])) {
    $flash = $_SESSION['verif_flash'];
    unset($_SESSION['verif_flash']);
    if (($flash['type'] ?? '') === 'ok') $msgOk = (string)($flash['msg'] ?? '');
    else $msgErr = (string)($flash['msg'] ?? '');
}

/* ═══ Filters & Query — one lead per user, including incomplete/rejected flows ═══ */
$filtroStatus = (string)($_GET['status'] ?? 'pendente');
$q = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$pp = in_array((int)($_GET['pp'] ?? 10), [5, 10, 20], true) ? (int)($_GET['pp'] ?? 10) : 10;
$offset = ($pagina - 1) * $pp;

$stDados = "COALESCE(vd.status, 'nao_enviado')";
$stEmail = "COALESCE(vemail.status, 'nao_enviado')";
$stDocs  = "COALESCE(vdoc.status, 'nao_enviado')";

// Base: any user that touched KYC (verification rows or uploaded docs)
$baseFrom = "FROM (
               SELECT DISTINCT user_id FROM user_verifications WHERE tipo IN ('dados', 'email', 'documentos')
               UNION
               SELECT DISTINCT user_id FROM user_verification_docs
             ) vk
             JOIN users u ON u.id = vk.user_id
             LEFT JOIN user_verifications vd ON vd.user_id = vk.user_id AND vd.tipo = 'dados'
             LEFT JOIN user_verifications vemail ON vemail.user_id = vk.user_id AND vemail.tipo = 'email'
             LEFT JOIN user_verifications vdoc ON vdoc.user_id = vk.user_id AND vdoc.tipo = 'documentos'
             WHERE 1=1";

$searchWhere = '';
$searchParams = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $searchWhere = " AND (LOWER(COALESCE(u.nome, '')) LIKE LOWER(?) OR LOWER(COALESCE(u.email, '')) LIKE LOWER(?))";
    $searchParams = [$like, $like];
}

// Status conditions for combined status
$statusConditions = [
  'pendente'   => " AND (($stDados IN ('pendente', 'nao_enviado')) OR ($stEmail IN ('pendente', 'nao_enviado')) OR ($stDocs IN ('pendente', 'nao_enviado'))) AND $stDados <> 'rejeitado' AND $stEmail <> 'rejeitado' AND $stDocs <> 'rejeitado'",
    'verificado' => " AND $stDados = 'verificado' AND $stEmail = 'verificado' AND $stDocs = 'verificado'",
  'rejeitado'  => " AND ($stDados = 'rejeitado' OR $stEmail = 'rejeitado' OR $stDocs = 'rejeitado')",
];

// Get counts for tabs
$counts = ['todos' => 0, 'pendente' => 0, 'verificado' => 0, 'rejeitado' => 0];
foreach (['pendente', 'verificado', 'rejeitado'] as $sts) {
  $cSql = "SELECT COUNT(DISTINCT vk.user_id) AS c " . $baseFrom . $searchWhere . $statusConditions[$sts];
    $cSt = $conn->prepare($cSql);
    $cSt->execute($searchParams ?: []);
    $counts[$sts] = (int)($cSt->get_result()->fetch_assoc()['c'] ?? 0);
    $cSt->close();
}
$counts['todos'] = $counts['pendente'] + $counts['verificado'] + $counts['rejeitado'];

// Main list
$statusFilter = $statusConditions[$filtroStatus] ?? '';
if ($filtroStatus === 'todos') $statusFilter = '';

$countSql = "SELECT COUNT(DISTINCT vk.user_id) AS total " . $baseFrom . $searchWhere . $statusFilter;
$stC = $conn->prepare($countSql);
$stC->execute($searchParams ?: []);
$total = (int)($stC->get_result()->fetch_assoc()['total'] ?? 0);
$stC->close();
$totalPaginas = max(1, (int)ceil($total / $pp));

$listSql = "SELECT vk.user_id, u.nome, u.email, u.role,
         $stDados AS dados_status, vd.dados AS dados_dados, vd.observacao AS dados_obs, vd.atualizado AS dados_atualizado,
         $stEmail AS email_status, vemail.observacao AS email_obs, vemail.atualizado AS email_atualizado,
         $stDocs AS docs_status, vdoc.dados AS docs_dados, vdoc.observacao AS docs_obs, vdoc.atualizado AS docs_atualizado,
         GREATEST(COALESCE(vd.atualizado, '1970-01-01 00:00:00'), COALESCE(vemail.atualizado, '1970-01-01 00:00:00'), COALESCE(vdoc.atualizado, '1970-01-01 00:00:00')) AS last_update
            " . $baseFrom . $searchWhere . $statusFilter . "
       ORDER BY GREATEST(COALESCE(vd.atualizado, '1970-01-01 00:00:00'), COALESCE(vemail.atualizado, '1970-01-01 00:00:00'), COALESCE(vdoc.atualizado, '1970-01-01 00:00:00')) DESC
            LIMIT ? OFFSET ?";
$stL = $conn->prepare($listSql);
$stL->execute(array_merge($searchParams, [$pp, $offset]));
$itens = $stL->get_result()->fetch_all(MYSQLI_ASSOC);

// Build detail data for each row
$detailData = [];
foreach ($itens as $idx => $row) {
    $uidRow = (int)$row['user_id'];
    $uDet = verAdminUserDetails($conn, $uidRow);
    $dadosPayload = json_decode((string)($row['dados_dados'] ?? ''), true);
    if (!is_array($dadosPayload)) $dadosPayload = [];
    $docMap = [
        'identidade' => verAdminDocByType($conn, $uidRow, 'identidade'),
        'selfie' => verAdminDocByType($conn, $uidRow, 'selfie'),
        'comprovante_residencia' => verAdminDocByType($conn, $uidRow, 'comprovante_residencia'),
    ];
    $detailData[$idx] = ['user' => $uDet, 'dados' => $dadosPayload, 'docs' => $docMap];
}

$pageTitle   = 'KYC';
$activeMenu  = 'verificacoes';
$subnavItems = [];

include __DIR__ . '/../../views/partials/header.php';
include __DIR__ . '/../../views/partials/admin_layout_start.php';
?>

<div class="space-y-4">
  <?php if ($msgOk): ?>
    <div class="rounded-2xl border border-greenx/30 bg-greenx/[0.08] px-5 py-3.5 text-sm text-greenx flex items-center gap-3">
      <i data-lucide="check-circle-2" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($msgOk) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($msgErr): ?>
    <div class="rounded-2xl border border-red-500/30 bg-red-600/[0.08] px-5 py-3.5 text-sm text-red-300 flex items-center gap-3">
      <i data-lucide="alert-triangle" class="w-5 h-5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($msgErr) ?></span>
    </div>
  <?php endif; ?>

  <div class="bg-blackx2 border border-blackx3 rounded-2xl p-5">
    <!-- Tab filters -->
    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-4">
      <div class="flex gap-2 flex-wrap flex-1">
        <?php
          $tabs = [
              'pendente'   => ['label' => 'Pendentes',  'icon' => 'clock',        'color' => 'orange'],
              'verificado' => ['label' => 'Aprovados',  'icon' => 'check-circle-2','color' => 'green'],
              'rejeitado'  => ['label' => 'Rejeitados', 'icon' => 'x-circle',     'color' => 'red'],
              'todos'      => ['label' => 'Todos',      'icon' => 'list',         'color' => 'zinc'],
          ];
          foreach ($tabs as $tabKey => $tab):
            $isActive = ($filtroStatus === $tabKey);
            $cnt = $counts[$tabKey] ?? 0;
            $qParam = $q !== '' ? '&q=' . urlencode($q) : '';
        ?>
          <a href="?status=<?= $tabKey ?><?= $qParam ?>"
             class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold transition-all <?= $isActive
               ? ($tab['color'] === 'orange' ? 'bg-orange-500/20 border border-orange-400/40 text-orange-300'
                 : ($tab['color'] === 'green' ? 'bg-greenx/20 border border-greenx/40 text-greenx'
                   : ($tab['color'] === 'red' ? 'bg-red-500/20 border border-red-500/40 text-red-300'
                     : 'bg-greenx/20 border border-greenx/40 text-purple-300')))
               : 'bg-blackx border border-blackx3 text-zinc-400 hover:text-white hover:border-zinc-500' ?>">
            <i data-lucide="<?= $tab['icon'] ?>" class="w-3.5 h-3.5"></i>
            <?= $tab['label'] ?>
            <span class="ml-0.5 px-1.5 py-0.5 rounded-full text-[10px] <?= $isActive ? 'bg-white/10' : 'bg-blackx3' ?>"><?= $cnt ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <form method="get" class="flex gap-2">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filtroStatus) ?>">
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar nome ou e-mail..." class="w-48 bg-blackx border border-blackx3 rounded-xl px-3 py-2 text-xs outline-none focus:border-greenx">
        <button type="submit" class="rounded-xl bg-blackx border border-blackx3 hover:border-greenx px-3 py-2 text-xs text-zinc-300 hover:text-white transition">
          <i data-lucide="search" class="w-3.5 h-3.5"></i>
        </button>
      </form>
    </div>

    <!-- List -->
    <?php if (!$itens): ?>
      <div class="text-center py-12 text-zinc-500">
        <i data-lucide="shield-check" class="w-10 h-10 mx-auto mb-3 opacity-40"></i>
        <p class="text-sm">Nenhum KYC encontrado.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-zinc-400 border-b border-blackx3">
              <th class="text-left py-3 pr-3">Usuário</th>
              <th class="text-left py-3 pr-3">Geral</th>
              <th class="text-left py-3 pr-3">Dados</th>
              <th class="text-left py-3 pr-3">Documentos</th>
              <th class="text-left py-3 pr-3">Atualizado</th>
              <th class="text-left py-3">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($itens as $idx => $row):
            $dSt = mb_strtolower((string)$row['dados_status']);
            $eSt = mb_strtolower((string)$row['email_status']);
            $docSt = mb_strtolower((string)$row['docs_status']);
            $combinedSt = 'pendente';
            if ($dSt === 'verificado' && $eSt === 'verificado' && $docSt === 'verificado') {
                $combinedSt = 'verificado';
            } elseif ($dSt === 'rejeitado' || $eSt === 'rejeitado' || $docSt === 'rejeitado') {
                $combinedSt = 'rejeitado';
            }
            $combinedClass = match($combinedSt) {
                'verificado' => 'bg-greenx/20 border-greenx/40 text-greenx',
                'rejeitado'  => 'bg-red-600/20 border-red-500/40 text-red-300',
                default      => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
            };
            $dClass = match($dSt) {
                'verificado' => 'bg-greenx/20 border-greenx/40 text-greenx',
                'rejeitado'  => 'bg-red-600/20 border-red-500/40 text-red-300',
                'pendente'   => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
                default      => 'bg-zinc-500/20 border-zinc-400/40 text-zinc-300',
            };
            $docClass = match($docSt) {
                'verificado' => 'bg-greenx/20 border-greenx/40 text-greenx',
                'rejeitado'  => 'bg-red-600/20 border-red-500/40 text-red-300',
                'pendente'   => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
                default      => 'bg-zinc-500/20 border-zinc-400/40 text-zinc-300',
            };
          ?>
            <tr class="border-b border-blackx3/50 hover:bg-blackx/40 transition">
              <td class="py-3 pr-3">
                <div>
                  <p class="font-medium"><?= htmlspecialchars((string)$row['nome']) ?></p>
                  <p class="text-[11px] text-zinc-500"><?= htmlspecialchars((string)$row['email']) ?> · #<?= (int)$row['user_id'] ?></p>
                </div>
              </td>
              <td class="py-3 pr-3">
                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium <?= $combinedClass ?>"><?= ucfirst($combinedSt) ?></span>
              </td>
              <td class="py-3 pr-3">
                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium <?= $dClass ?>"><?= ucfirst($dSt) ?></span>
              </td>
              <td class="py-3 pr-3">
                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium <?= $docClass ?>"><?= ucfirst($docSt) ?></span>
              </td>
              <td class="py-3 pr-3 text-zinc-400 text-xs"><?= date('d/m/Y H:i', strtotime((string)$row['last_update'])) ?></td>
              <td class="py-3">
                <div class="flex items-center gap-2">
                  <button type="button" onclick="openVerifModal(<?= $idx ?>)" class="inline-flex items-center gap-1 rounded-lg bg-blackx border border-blackx3 hover:border-greenx px-2.5 py-1.5 text-xs text-zinc-300 hover:text-white transition">
                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> Detalhes
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php
        $paginaAtual  = $pagina;
        include __DIR__ . '/../../views/partials/pagination.php';
      ?>
    <?php endif; ?>
  </div>
</div>

<!-- Details Modal -->
<div id="verifModal" class="hidden" style="position:fixed;inset:0;z-index:9999">
  <div style="position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px)" onclick="closeVerifModal()"></div>
  <div style="position:fixed;inset:0;overflow-y:auto;display:flex;justify-content:center;padding:1rem" onclick="closeVerifModal()">
    <div style="position:relative;width:100%;max-width:64rem;margin:auto 0;flex-shrink:0" onclick="event.stopPropagation()">
      <div class="bg-blackx2 border border-blackx3 rounded-2xl shadow-2xl">
        <div class="flex items-center justify-between px-5 py-4 border-b border-blackx3">
          <h2 class="text-lg font-bold flex items-center gap-2"><i data-lucide="shield-check" class="w-5 h-5 text-purple-400"></i> Detalhes do KYC</h2>
          <button onclick="closeVerifModal()" class="w-8 h-8 rounded-lg border border-blackx3 flex items-center justify-center text-zinc-400 hover:text-white hover:border-red-400 transition">
            <i data-lucide="x" class="w-4 h-4"></i>
          </button>
        </div>
        <div id="verifModalBody" class="p-4 md:p-6 space-y-5"></div>
      </div>
    </div>
  </div>
</div>

<script>
var verifDetails = <?= json_encode(array_map(function($idx) use ($itens, $detailData) {
    $row = $itens[$idx];
    $d = $detailData[$idx];
    $uDet = $d['user'];
    $dadosPayload = $d['dados'];
    $dSt = mb_strtolower((string)$row['dados_status']);
    $eSt = mb_strtolower((string)$row['email_status']);
    $docSt = mb_strtolower((string)$row['docs_status']);

    // Combined status (all 3 must be verified)
    $combinedSt = 'pendente';
    if ($dSt === 'verificado' && $eSt === 'verificado' && $docSt === 'verificado') $combinedSt = 'verificado';
    elseif ($dSt === 'rejeitado' || $eSt === 'rejeitado' || $docSt === 'rejeitado') $combinedSt = 'rejeitado';

    $statusClass = match($combinedSt) {
        'verificado' => 'bg-greenx/20 border-greenx/40 text-greenx',
        'rejeitado' => 'bg-red-600/20 border-red-500/40 text-red-300',
        default => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
    };

    $mkCls = fn($s) => match($s) {
        'verificado' => 'bg-greenx/20 border-greenx/40 text-greenx',
        'rejeitado'  => 'bg-red-600/20 border-red-500/40 text-red-300',
        'pendente'   => 'bg-orange-500/20 border-orange-400/40 text-orange-300',
        default      => 'bg-zinc-500/20 border-zinc-400/40 text-zinc-300',
    };
    $dStatusClass = $mkCls($dSt);
    $eStatusClass = $mkCls($eSt);
    $docStatusClass = $mkCls($docSt);

    $docs = [];
    foreach (['identidade' => 'Identidade', 'selfie' => 'Selfie', 'comprovante_residencia' => 'Comprovante'] as $dk => $dl) {
        $doc = $d['docs'][$dk] ?? [];
        $dStatus = mb_strtolower((string)($doc['status'] ?? ''));
      $docRaw = (string)($doc['arquivo'] ?? '');
      $dUrl = verAdminMediaUrl($docRaw, (int)$row['user_id']);
        $docs[] = [
            'label' => $dl,
            'status' => $dStatus ?: 'sem envio',
            'url' => $dUrl,
        'invalid_ref' => $docRaw !== '' && $dUrl === null,
            'obs' => (string)($doc['observacao'] ?? ''),
        ];
    }

    return [
        'user_id' => (int)$row['user_id'],
        'nome' => (string)($uDet['nome'] ?? $row['nome']),
        'email' => (string)($uDet['email'] ?? $row['email']),
        'telefone' => (string)(($uDet['telefone'] ?? '') ?: ($dadosPayload['telefone'] ?? '')),
        'cpf' => (string)(($uDet['cpf'] ?? '') ?: ($uDet['documento'] ?? '') ?: ($dadosPayload['cpf'] ?? '')),
        'combined_status' => ucfirst($combinedSt),
        'combined_raw' => $combinedSt,
        'combinedClass' => $statusClass,
        'dados_status' => ucfirst($dSt),
        'dados_raw' => $dSt,
        'dStatusClass' => $dStatusClass,
        'email_status' => ucfirst($eSt),
        'email_raw' => $eSt,
        'eStatusClass' => $eStatusClass,
        'docs_status' => ucfirst($docSt),
        'docs_raw' => $docSt,
        'docStatusClass' => $docStatusClass,
        'dados_obs' => (string)($row['dados_obs'] ?? ''),
        'email_obs' => (string)($row['email_obs'] ?? ''),
        'docs_obs' => (string)($row['docs_obs'] ?? ''),
        'atualizado' => date('d/m/Y H:i', strtotime((string)$row['last_update'])),
        'docs' => $docs,
    ];
}, array_keys($itens)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function closeVerifModal() {
    document.getElementById('verifModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function openVerifModal(idx) {
    var d = verifDetails[idx];
    if (!d) return;
    var html = '';

    // User info
    html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">';
    html += infoCard('Nome', d.nome);
    html += infoCard('E-mail', d.email);
    html += infoCard('Telefone', d.telefone || '—');
    html += infoCard('CPF/Documento', d.cpf || '—');
    html += '</div>';

    // Status cards
    html += '<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-1">';
    html += '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3"><p class="text-[11px] text-zinc-500 mb-1">Status Geral</p><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ' + d.combinedClass + '">' + d.combined_status + '</span></div>';
    html += '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3"><p class="text-[11px] text-zinc-500 mb-1">Dados</p><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ' + d.dStatusClass + '">' + d.dados_status + '</span></div>';
    html += '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3"><p class="text-[11px] text-zinc-500 mb-1">E-mail</p><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ' + d.eStatusClass + '">' + d.email_status + '</span></div>';
    html += '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3"><p class="text-[11px] text-zinc-500 mb-1">Documentos</p><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium ' + d.docStatusClass + '">' + d.docs_status + '</span></div>';
    html += '</div>';

    if (d.dados_obs) {
        html += '<div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-3 text-sm text-orange-300"><strong>Obs dados:</strong> ' + escHtml(d.dados_obs) + '</div>';
    }
    if (d.email_obs) {
        html += '<div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-3 text-sm text-orange-300"><strong>Obs e-mail:</strong> ' + escHtml(d.email_obs) + '</div>';
    }
    if (d.docs_obs) {
        html += '<div class="rounded-xl border border-orange-500/20 bg-orange-500/[0.06] px-4 py-3 text-sm text-orange-300"><strong>Obs docs:</strong> ' + escHtml(d.docs_obs) + '</div>';
    }

    // Documents
    html += '<div><p class="text-xs text-zinc-500 font-medium mb-2">Documentos enviados</p><div class="grid grid-cols-1 sm:grid-cols-3 gap-3">';
    d.docs.forEach(function(doc) {
        var dStatusCls = doc.status === 'aprovado' ? 'text-greenx' : (doc.status === 'rejeitado' ? 'text-red-300' : (doc.status === 'pendente' ? 'text-orange-300' : 'text-zinc-500'));
        html += '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3">';
        html += '<p class="text-xs text-zinc-400 font-semibold mb-1">' + doc.label + '</p>';
        html += '<p class="text-xs ' + dStatusCls + '">Status: ' + (doc.status || 'sem envio') + '</p>';
        if (doc.url) {
          html += '<a href="' + escAttr(doc.url) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 mt-2 text-xs text-purple-300 hover:text-purple-200 transition"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg> Abrir arquivo</a>';
        } else if (doc.invalid_ref) {
          html += '<p class="text-[11px] text-red-300 mt-1">Documento inválido. Reenvie o documento.</p>';
        } else {
            html += '<p class="text-[11px] text-zinc-600 mt-1">Sem arquivo</p>';
        }
        if (doc.obs) html += '<p class="text-[11px] text-red-300 mt-1">Obs: ' + escHtml(doc.obs) + '</p>';
        html += '</div>';
    });
    html += '</div></div>';

    // Action buttons (only for pending)
    if (d.combined_raw === 'pendente') {
        html += '<div class="border-t border-blackx3 pt-4">';
        html += '<form method="post" id="verifForm_' + d.user_id + '" class="space-y-3">';
        html += '<input type="hidden" name="user_id" value="' + d.user_id + '">';
        html += '<input type="hidden" name="ver_action" id="verifAction_' + d.user_id + '" value="">';
        html += '<input name="observacao" placeholder="Observação (opcional)" class="w-full bg-blackx border border-blackx3 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-greenx">';
        html += '<div class="flex gap-3">';
        html += '<button type="button" onclick="submitVerif(' + d.user_id + ', \'aprovar\')" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-greenx/15 border border-greenx/30 px-4 py-2.5 text-sm font-semibold text-greenx hover:bg-greenx/25 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Aprovar</button>';
        html += '<button type="button" onclick="submitVerif(' + d.user_id + ', \'rejeitar\')" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-500/15 border border-red-500/30 px-4 py-2.5 text-sm font-semibold text-red-300 hover:bg-red-500/25 transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg> Rejeitar</button>';
        html += '</div></form></div>';
    }

    document.getElementById('verifModalBody').innerHTML = html;
    var modal = document.getElementById('verifModal');
    modal.classList.remove('hidden');
    modal.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}

function infoCard(label, value) {
    return '<div class="rounded-xl border border-blackx3 bg-blackx/60 px-4 py-3"><p class="text-[11px] text-zinc-500 mb-1">' + escHtml(label) + '</p><p class="text-sm text-zinc-200">' + escHtml(value || '—') + '</p></div>';
}

function submitVerif(userId, action) {
    document.getElementById('verifAction_' + userId).value = action;
    document.getElementById('verifForm_' + userId).submit();
}

function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escAttr(s) { return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeVerifModal(); });

// Move modal to body root so no parent overflow:hidden can clip it
document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('verifModal');
    if (m) document.body.appendChild(m);
});
</script>

<?php include __DIR__ . '/../../views/partials/admin_layout_end.php'; include __DIR__ . '/../../views/partials/footer.php'; ?>
