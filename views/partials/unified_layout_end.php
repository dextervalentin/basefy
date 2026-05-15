<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\unified_layout_end.php
declare(strict_types=1);
?>
      </main>
    </div>
  </div>
</div>

<?php
// Floating chat widget on all pages except the full chat page
$currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($currentPage !== 'chat.php') {
    $userRole = (string)($_SESSION['user']['role'] ?? 'usuario');
    // Vendor gets vendor widget (with delivery code input), buyer gets user widget
    if ($userRole === 'vendedor') {
        $widgetPath = __DIR__ . '/chat_widget_vendor.php';
    } else {
        $widgetPath = __DIR__ . '/chat_widget_user.php';
    }
    if (is_file($widgetPath)) {
        include $widgetPath;
    }
}
?>

<script>
(function () {
  const sidebar = document.getElementById('uniSidebar');
  const overlay = document.getElementById('uniSidebarOverlay');
  const openBtn = document.getElementById('btnUniOpenSidebar');
  const closeBtn = document.getElementById('btnUniCloseSidebar');

  function openSidebar() {
    sidebar?.classList.remove('translate-x-full');
    overlay?.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar?.classList.add('translate-x-full');
    overlay?.classList.add('hidden');
    document.body.style.overflow = '';
  }

  openBtn?.addEventListener('click', openSidebar);
  closeBtn?.addEventListener('click', closeSidebar);
  overlay?.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function () {
    if (window.innerWidth >= 768) closeSidebar();
  });
})();
</script>

<script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script>window.lucide?.createIcons();</script>

<!-- Push Notifications -->
<script>window.__BASE_PATH = '<?= defined("BASE_PATH") ? BASE_PATH : "" ?>';</script>
<script src="<?= defined('BASE_PATH') ? BASE_PATH : '' ?>/assets/js/push-notifications.js"></script>
