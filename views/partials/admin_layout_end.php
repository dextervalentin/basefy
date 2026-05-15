<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\admin_layout_end.php
declare(strict_types=1);
?>
      </main>
    </div>
  </div>
</div>

<?php
// Floating chat widget for admin pages
$currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($currentPage !== 'chat.php') {
    include __DIR__ . '/chat_widget_user.php';
}
?>

<script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script>window.lucide?.createIcons();</script>
<script>
(() => {
  const sidebar = document.getElementById('adminSidebar');
  const overlay = document.getElementById('adminSidebarOverlay');
  const btnOpen = document.getElementById('btnAdminOpenSidebar');
  const btnClose = document.getElementById('btnAdminCloseSidebar');
  if (!sidebar || !overlay) return;

  const open = () => {
    sidebar.classList.remove('translate-x-full');
    overlay.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  };
  const close = () => {
    sidebar.classList.add('translate-x-full');
    overlay.classList.add('hidden');
    document.body.style.overflow = '';
  };

  if (btnOpen) btnOpen.addEventListener('click', open);
  if (btnClose) btnClose.addEventListener('click', close);
  overlay.addEventListener('click', close);

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) close();
  });
})();
</script>