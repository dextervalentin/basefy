<?php
// filepath: c:\xampp\htdocs\mercado_admin\views\partials\footer.php
declare(strict_types=1);
?>
  <!-- Page Transition Loader -->
  <div id="page-loader"><div class="bar"></div></div>
  <script>
  (function(){
    var loader=document.getElementById('page-loader');
    if(!loader)return;
    var bar=loader.querySelector('.bar');
    function show(){bar.style.width='0';loader.classList.add('active');setTimeout(function(){bar.style.width='70%'},10);}
    function finish(){bar.style.width='100%';setTimeout(function(){loader.classList.remove('active');bar.style.width='0'},350);}
    document.addEventListener('click',function(e){
      var a=e.target.closest('a[href]');
      if(!a)return;
      var h=a.getAttribute('href');
      if(!h||h==='#'||h.startsWith('javascript:')||h.startsWith('mailto:')||h.startsWith('tel:'))return;
      if(a.hasAttribute('download')||a.target==='_blank')return;
      if(e.ctrlKey||e.metaKey||e.shiftKey)return;
      show();
    });
    document.querySelectorAll('form').forEach(function(f){
      f.addEventListener('submit',function(){
        if(f.hasAttribute('data-no-transition')||f.dataset.variants)return;
        show();
      });
    });
    window.addEventListener('beforeunload',function(){show()});
    window.addEventListener('pageshow',function(){finish()});
  })();
  </script>
  <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
  <script>window.__BASE_PATH = '<?= defined("BASE_PATH") ? BASE_PATH : "" ?>'; window.__BASEFY_PUSH_ONLY = true;</script>
  <script src="<?= defined('BASE_PATH') ? BASE_PATH : '' ?>/assets/js/push-notifications.js"></script>
  <script>
    window.lucide && window.lucide.createIcons();
    // Prevent browser "resubmit form?" dialog after POST submissions (PRG fallback)
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.href);
    }
    <?php if (!empty($_SESSION['_fav_toast'])): ?>
    // Show fav toast from login redirect
    document.addEventListener('DOMContentLoaded', function() {
      var box = document.createElement('div');
      box.className = 'fixed top-5 right-5 z-[9999] flex items-center gap-2 rounded-xl border border-red-400/40 bg-red-500/15 backdrop-blur-sm text-red-400 px-4 py-2.5 text-sm font-medium shadow-lg transition-all duration-300';
      box.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg> <?= htmlspecialchars($_SESSION['_fav_toast']) ?>';
      document.body.appendChild(box);
      setTimeout(function(){ box.style.opacity='0'; box.style.transform='translateY(-10px)'; }, 3000);
      setTimeout(function(){ box.remove(); }, 3500);
    });
    <?php unset($_SESSION['_fav_toast']); endif; ?>
  </script>
  <script>
  /* Linhas/cards clicáveis — delegação global. Use class="row-link" data-href="/url".
     Cliques em <a>, <button>, <input>, <select>, <textarea>, [data-stop] não disparam navegação.
     Alternativa para listas com modal: class="row-link" data-click-selector=".js-sale-detail"
     → ao clicar na linha, dispara click() no primeiro elemento que casar o seletor dentro dela. */
  (function(){
    document.addEventListener('click', function(e){
      var row = e.target.closest('.row-link');
      if (!row) return;
      // Ignora cliques em elementos interativos dentro da linha
      if (e.target.closest('a, button, input, select, textarea, label, [data-stop]')) return;
      var sel = row.getAttribute('data-click-selector');
      if (sel) {
        var inner = row.querySelector(sel);
        if (inner) { inner.click(); return; }
      }
      var href = row.getAttribute('data-href');
      if (!href) return;
      // Suporta ctrl/cmd-click → nova aba
      if (e.ctrlKey || e.metaKey || e.button === 1) {
        window.open(href, '_blank', 'noopener');
      } else {
        window.location.href = href;
      }
    }, false);
    // Atalho de teclado: Enter abre o item focado
    document.addEventListener('keydown', function(e){
      if (e.key !== 'Enter') return;
      var row = document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('row-link') ? document.activeElement : null;
      if (!row) return;
      var sel = row.getAttribute('data-click-selector');
      if (sel) { var inner = row.querySelector(sel); if (inner) { inner.click(); return; } }
      var href = row.getAttribute('data-href');
      if (href) window.location.href = href;
    });
  })();
  </script>
  <script>
  /* ── Global Favorites System ── */
  (function(){
    var BASE = (typeof BASE_PATH !== 'undefined' ? BASE_PATH : '');

    function showFavToast(msg) {
      var box = document.getElementById('fav-toast');
      if (!box) {
        box = document.createElement('div');
        box.id = 'fav-toast';
        box.className = 'fixed top-5 right-5 z-[9999] flex items-center gap-2 rounded-xl border border-red-400/40 bg-red-500/15 backdrop-blur-sm text-red-400 px-4 py-2.5 text-sm font-medium shadow-lg transition-all duration-300';
        box.style.opacity = '0';
        box.style.transform = 'translateY(-10px)';
        document.body.appendChild(box);
      }
      box.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg> ' + msg;
      box.style.opacity = '1';
      box.style.transform = 'translateY(0)';
      clearTimeout(window.__favToastTimer);
      window.__favToastTimer = setTimeout(function() { box.style.opacity = '0'; box.style.transform = 'translateY(-10px)'; }, 2500);
    }

    function setFavState(btn, isFav) {
      var svg = btn.querySelector('[data-lucide="heart"]') || btn.querySelector('svg');
      if (isFav) {
        btn.classList.add('fav-active');
        btn.classList.remove('text-zinc-400', 'border-white/10', 'hover:text-red-400', 'hover:border-red-400/40');
        btn.classList.add('text-red-500', 'border-red-400/60', 'scale-110');
        btn.style.background = 'rgba(239,68,68,0.25)';
        if (svg) { svg.style.fill = 'currentColor'; }
        var label = btn.querySelector('.fav-label');
        if (label) label.textContent = 'Favoritado';
      } else {
        btn.classList.remove('fav-active', 'text-red-500', 'border-red-400/60', 'scale-110');
        btn.classList.add('text-zinc-400');
        btn.style.background = '';
        if (svg) { svg.style.fill = 'none'; }
        var label = btn.querySelector('.fav-label');
        if (label) label.textContent = 'Favoritar';
      }
    }

    async function toggleFav(btn) {
      var pid = btn.dataset.productId;
      if (!pid) return;
      try {
        var fd = new FormData();
        fd.append('action', 'toggle');
        fd.append('product_id', pid);
        var r = await fetch(BASE + '/api/favorites.php?action=toggle', { method: 'POST', body: fd });
        var j = await r.json();
        if (j.login) {
          // Save fav product to add after login, redirect back to current page
          var returnUrl = window.location.pathname + window.location.search;
          window.location.href = BASE + '/login?return_to=' + encodeURIComponent(returnUrl) + '&fav_product=' + pid;
          return;
        }
        if (j.ok) {
          setFavState(btn, j.favorited);
          // Show toast notification
          showFavToast(j.favorited ? 'Produto adicionado aos favoritos!' : 'Produto removido dos favoritos.');
          // Sync all buttons for same product
          document.querySelectorAll('[data-product-id="'+pid+'"]').forEach(function(b){ if(b!==btn) setFavState(b, j.favorited); });
        }
      } catch(e) {}
    }

    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.fav-btn, .fav-btn-detail');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      toggleFav(btn);
    });

    // On page load, check favorites state for all visible fav buttons
    window.addEventListener('DOMContentLoaded', async function(){
      var btns = document.querySelectorAll('.fav-btn, .fav-btn-detail');
      if (!btns.length) return;
      var ids = [];
      btns.forEach(function(b){ var pid = parseInt(b.dataset.productId); if(pid && ids.indexOf(pid)===-1) ids.push(pid); });
      if (!ids.length) return;
      try {
        var r = await fetch(BASE + '/api/favorites.php?action=check_bulk&ids=' + encodeURIComponent(JSON.stringify(ids)));
        var j = await r.json();
        if (j.ok && j.favorited_ids) {
          var favSet = j.favorited_ids.map(function(x){return parseInt(x)});
          btns.forEach(function(b){
            var pid = parseInt(b.dataset.productId);
            if (favSet.indexOf(pid) !== -1) setFavState(b, true);
          });
        }
      } catch(e) {}
    });
  })();
  </script>
  <script src="<?= BASE_PATH ?>/assets/js/guard-submit.js"></script>

  <!-- ── Variant Picker Modal ── -->
  <div id="variantPickerModal" class="vp-overlay" style="display:none">
    <div class="vp-card" id="variantPickerInner">
      <div class="vp-header">
        <div class="vp-icon-wrap" id="vpIconWrap">
          <i data-lucide="layers" class="w-5 h-5"></i>
        </div>
        <div class="vp-title-wrap">
          <span class="vp-title" id="vpTitle">Escolha a variante</span>
          <span class="vp-sub">Selecione uma opção para continuar</span>
        </div>
        <button onclick="closeVariantPicker()" class="vp-close">&times;</button>
      </div>
      <div id="vpOptions" class="vp-options"></div>
    </div>
  </div>
  <style>
  .vp-overlay{position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:1000002;background:rgba(0,0,0,.6);backdrop-filter:blur(8px);align-items:center;justify-content:center;animation:vpFadeIn .2s ease-out}
  @keyframes vpFadeIn{from{opacity:0}to{opacity:1}}
  .vp-card{background:var(--t-bg-card,#0f0f0f);border:1px solid rgba(255,255,255,.08);border-radius:24px;max-width:380px;width:calc(100vw - 32px);padding:0;box-shadow:0 25px 80px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.04);overflow:hidden;animation:vpSlideUp .25s ease-out}
  @keyframes vpSlideUp{from{opacity:0;transform:translateY(20px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
  .vp-header{display:flex;align-items:center;gap:12px;padding:20px 20px 16px;background:linear-gradient(180deg,rgba(136,0,228,.08) 0%,transparent 100%);border-bottom:1px solid rgba(255,255,255,.06)}
  .vp-icon-wrap{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--t-accent,#8800E4),var(--t-accent-hover,#7200C0));display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;overflow:hidden}
  .vp-icon-wrap img{width:100%;height:100%;object-fit:cover}
  .vp-title-wrap{flex:1;min-width:0}
  .vp-title{display:block;font-size:15px;font-weight:700;color:var(--t-text-primary,#fff);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .vp-sub{display:block;font-size:11px;color:var(--t-text-muted,#666);margin-top:2px}
  .vp-close{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;cursor:pointer;color:#888;font-size:18px;transition:all .2s;flex-shrink:0;line-height:1}
  .vp-close:hover{background:rgba(255,255,255,.12);color:#fff}
  .vp-options{padding:12px 16px 16px;display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.1) transparent}
  .vp-opt{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:14px 16px;border-radius:14px;border:1.5px solid rgba(255,255,255,.15);background:rgba(255,255,255,.03);cursor:pointer;transition:all .2s;text-align:left;box-shadow:0 1px 3px rgba(0,0,0,.12)}
  .vp-opt:hover:not(:disabled){border-color:rgba(136,0,228,.5);background:rgba(136,0,228,.08);transform:translateY(-1px);box-shadow:0 4px 16px rgba(136,0,228,.15)}
  .vp-opt:active:not(:disabled){transform:translateY(0)}
  .vp-opt:disabled{opacity:.35;cursor:not-allowed}
  .vp-opt .vp-nome{font-size:14px;font-weight:600;color:var(--t-text-primary,#e5e5e5)}
  .vp-opt .vp-preco{font-size:14px;font-weight:700;color:var(--t-accent,#8800E4);white-space:nowrap}
  .vp-opt .vp-esgotado{font-size:10px;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
  </style>
  <script>
  /* ── Variant Picker Logic ── */
  (function(){
    var modal = document.getElementById('variantPickerModal');
    var optionsDiv = document.getElementById('vpOptions');
    var vpTitle = document.getElementById('vpTitle');
    var vpIconWrap = document.getElementById('vpIconWrap');
    var pendingForm = null;

    window.closeVariantPicker = function(){
      // Restore button state before clearing pendingForm
      if (pendingForm) {
        var btn = pendingForm.querySelector('button[type="submit"], button:not([type])');
        if (btn && btn.dataset.originalText) {
          btn.disabled = false;
          btn.innerHTML = btn.dataset.originalText;
          btn.classList.remove('opacity-70', 'pointer-events-none');
        }
        pendingForm.dataset.submitting = '0';
      }
      modal.style.display='none';
      pendingForm=null;
    };
    modal.addEventListener('click',function(e){ if(e.target===modal) window.closeVariantPicker(); });

    function formatBRL(v){ return 'R$\u00A0' + Number(v).toFixed(2).replace('.',','); }

    window.openVariantPicker = function(form, variants, productName, productImage){
      pendingForm = form;
      vpTitle.textContent = productName || 'Escolha a variante';
      // Set product image or fallback to icon
      if (productImage) {
        vpIconWrap.innerHTML = '<img src="' + productImage.replace(/"/g,'&quot;') + '" alt="">';
      } else {
        vpIconWrap.innerHTML = '<i data-lucide="layers" class="w-5 h-5"></i>';
      }
      optionsDiv.innerHTML = '';
      variants.forEach(function(v){
        var qty = parseInt(v.quantidade||0,10);
        var disabled = qty <= 0;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.disabled = disabled;
        btn.className = 'vp-opt';
        var left = '<span class="vp-nome">' + v.nome + '</span>';
        var right = disabled
          ? '<span class="vp-esgotado">Esgotado</span>'
          : '<span class="vp-preco">' + formatBRL(v.preco) + '</span>';
        btn.innerHTML = left + right;
        if (!disabled) {
          btn.onclick = function(){
            var theForm = pendingForm;
            if (!theForm) return;
            // Set variant input
            var inp = theForm.querySelector('input[name="variante"]');
            if (!inp){ inp=document.createElement('input'); inp.type='hidden'; inp.name='variante'; theForm.appendChild(inp); }
            inp.value = v.nome;
            // Reset guard-submit lock
            theForm.dataset.submitting = '0';
            // Close modal (sets pendingForm = null)
            modal.style.display = 'none';
            pendingForm = null;
            // Submit form
            theForm.submit();
          };
        }
        optionsDiv.appendChild(btn);
      });
      modal.style.display = 'flex';
      if (window.lucide) lucide.createIcons();
    };

    // Intercept all add-to-cart forms for dynamic products
    document.addEventListener('submit', function(e){
      var form = e.target;
      if (!form || form.tagName !== 'FORM') return;
      var actionInp = form.querySelector('input[name="action"]');
      if (!actionInp || actionInp.value !== 'add_cart') return;
      var varData = form.dataset.variants;
      if (!varData) return; // regular product
      e.preventDefault();
      var overlay = document.getElementById('page-exit-overlay');
      if (overlay) overlay.classList.remove('fading');
      var loader = document.getElementById('page-loader');
      if (loader) loader.classList.remove('active');
      // Reset guard-submit lock so picker can re-submit
      form.dataset.submitting = '0';
      try {
        var variants = JSON.parse(varData);
        var pName = form.dataset.productName || '';
        var pImage = form.dataset.productImage || '';
        window.openVariantPicker(form, variants, pName, pImage);
      } catch(ex){ form.submit(); }
    });
  })();
  </script>
  <script>
  /* ── Notification System ── */
  (function(){
    var BASE = (typeof BASE_PATH !== 'undefined' ? BASE_PATH : '');
    var notifSound = null;
    var notifSoundEnabled = localStorage.getItem('notif_sound') !== 'off';
    var notifFilter = 'all';
    var notifItems = [];
    var lastNotifCount = 0;

    // Init sound toggle UI
    function updateSoundUI() {
      var onIcons = document.querySelectorAll('.notif-sound-on');
      var offIcons = document.querySelectorAll('.notif-sound-off');
      onIcons.forEach(function(el){ el.style.display = notifSoundEnabled ? 'block' : 'none'; });
      offIcons.forEach(function(el){ el.style.display = notifSoundEnabled ? 'none' : 'block'; });
    }

    window.toggleNotifSound = function() {
      notifSoundEnabled = !notifSoundEnabled;
      localStorage.setItem('notif_sound', notifSoundEnabled ? 'on' : 'off');
      updateSoundUI();
    };

    function playNotifSound() {
      if (!notifSoundEnabled) return;
      try {
        if (!notifSound) {
          var ctx = new (window.AudioContext || window.webkitAudioContext)();
          var osc = ctx.createOscillator();
          var gain = ctx.createGain();
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.frequency.setValueAtTime(880, ctx.currentTime);
          gain.gain.setValueAtTime(0.15, ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
          osc.start(ctx.currentTime);
          osc.stop(ctx.currentTime + 0.3);
        }
      } catch(e){}
    }

    function notifTypeIcon(tipo) {
      var icons = { anuncio: 'megaphone', venda: 'shopping-bag', chat: 'message-circle', ticket: 'flag' };
      return icons[tipo] || 'bell';
    }
    function notifTypeColor(tipo) {
      var colors = { anuncio: 'text-purple-400', venda: 'text-greenx', chat: 'text-purple-400', ticket: 'text-orange-400' };
      return colors[tipo] || 'text-zinc-400';
    }
    function timeAgo(dateStr) {
      var d = new Date(dateStr);
      var now = new Date();
      var diff = Math.floor((now - d) / 1000);
      if (diff < 60) return 'agora';
      if (diff < 3600) return Math.floor(diff/60) + 'min';
      if (diff < 86400) return Math.floor(diff/3600) + 'h';
      return Math.floor(diff/86400) + 'd';
    }

    function htmlEsc(value) {
      return String(value || '').replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
      });
    }

    function renderNotifList() {
      var container = document.getElementById('notifList');
      if (!container) return;
      var filtered = notifFilter === 'all' ? notifItems : notifItems.filter(function(n){ return n.tipo === notifFilter; });
      if (!filtered.length) {
        container.innerHTML = '<div class="p-6 text-center text-zinc-500 text-sm"><i data-lucide="bell-off" class="w-8 h-8 mx-auto mb-2 text-zinc-600"></i>Nenhuma notificação</div>';
        if (window.lucide) lucide.createIcons({nodes: [container]});
        return;
      }
      var html = '';
      filtered.forEach(function(n){
        var readClass = n.lida ? 'opacity-50' : '';
        html += '<div class="flex items-start gap-3 px-3 py-3 hover:bg-white/[0.02] transition-colors cursor-pointer '+readClass+'" onclick="notifClick('+n.id+',\''+escape(n.link||'')+'\')">';
        html += '<div class="w-8 h-8 rounded-lg bg-white/[0.04] border border-white/[0.06] flex items-center justify-center shrink-0 '+notifTypeColor(n.tipo)+'">';
        html += '<i data-lucide="'+notifTypeIcon(n.tipo)+'" class="w-4 h-4"></i></div>';
        html += '<div class="flex-1 min-w-0">';
        html += '<p class="text-sm font-medium truncate">'+htmlEsc(n.titulo)+'</p>';
        if (n.mensagem) html += '<p class="text-xs text-zinc-500 truncate">'+htmlEsc(n.mensagem)+'</p>';
        html += '<span class="text-[10px] text-zinc-600">'+timeAgo(n.criado_em)+'</span>';
        html += '</div>';
        if (!n.lida) html += '<span class="w-2 h-2 rounded-full bg-greenx shrink-0 mt-2"></span>';
        html += '</div>';
      });
      container.innerHTML = html;
      if (window.lucide) lucide.createIcons({nodes: [container]});
    }

    window.filterNotif = function(tab) {
      notifFilter = tab;
      document.querySelectorAll('.notif-tab').forEach(function(t){
        var isActive = t.dataset.tab === tab;
        t.classList.toggle('border-greenx', isActive);
        t.classList.toggle('text-greenx', isActive);
        t.classList.toggle('border-transparent', !isActive);
      });
      renderNotifList();
    };

    window.notifClick = function(id, link) {
      var fd = new FormData();
      fd.append('action','read');
      fd.append('id', id);
      fetch(BASE + '/api/notifications.php?action=read', { method:'POST', body: fd });
      notifItems.forEach(function(n){ if(n.id === id) n.lida = true; });
      renderNotifList();
      updateBadge();
      if (link) {
        try { link = unescape(link); } catch(e){}
        if (link && link !== '' && link !== 'undefined') window.location.href = link;
      }
    };

    window.markAllNotifRead = function() {
      var fd = new FormData();
      fd.append('action','read_all');
      fetch(BASE + '/api/notifications.php?action=read_all', { method:'POST', body: fd });
      notifItems.forEach(function(n){ n.lida = true; });
      renderNotifList();
      updateBadge();
    };

    function updateBadge() {
      var badge = document.getElementById('notifBadge');
      var btn = document.getElementById('notifBellBtn');
      var unread = notifItems.filter(function(n){ return !n.lida; }).length;
      if (badge) {
        badge.textContent = unread > 99 ? '99+' : unread;
        badge.classList.toggle('hidden', unread === 0);
      }
      if (btn) {
        btn.classList.toggle('border-yellow-400/30', unread > 0);
        btn.classList.toggle('bg-yellow-500/[0.06]', unread > 0);
        btn.classList.toggle('text-yellow-400', unread > 0);
        btn.classList.toggle('border-white/[0.08]', unread === 0);
        btn.classList.toggle('text-zinc-400', unread === 0);
      }
    }

    async function loadNotifications() {
      try {
        var r = await fetch(BASE + '/api/notifications.php?action=list&limit=30');
        var j = await r.json();
        if (j.ok) {
          notifItems = j.items || [];
          renderNotifList();
          var newCount = j.unread || 0;
          if (newCount > lastNotifCount && lastNotifCount > 0) {
            playNotifSound();
          }
          lastNotifCount = newCount;
          updateBadge();
        }
      } catch(e){}
    }

    // Load on dropdown open
    document.addEventListener('notif-open', loadNotifications);
    // Initial load + polling every 30s
    window.addEventListener('DOMContentLoaded', function(){
      updateSoundUI();
      // Set first tab active
      var firstTab = document.querySelector('.notif-tab[data-tab="all"]');
      if (firstTab) { firstTab.classList.add('border-greenx','text-greenx'); firstTab.classList.remove('border-transparent'); }
      // Poll for new notifications
      if (document.getElementById('notifBellBtn')) {
        loadNotifications();
        setInterval(function(){ loadNotifications(); }, 30000);
      }
    });
  })();
  </script>
</body>
</html>