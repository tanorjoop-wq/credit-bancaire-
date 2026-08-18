        </main>
        <footer class="text-center text-muted small py-3">
            Plateforme Crédit Bancaire — Projet Master CCA ESP Dakar — <?= date('Y') ?>
        </footer>
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/public/assets/js/validation.js"></script>
<script src="<?= BASE_URL ?>/public/assets/js/table-filter.js"></script>
<script>
(function () {
    var shell = document.getElementById('appShell');
    var KEY_COLLAPSED = 'sidebarCollapsed';
    if (localStorage.getItem(KEY_COLLAPSED) === '1') {
        shell.classList.add('sidebar-collapsed');
    }
    window.toggleSidebarCollapse = function () {
        shell.classList.toggle('sidebar-collapsed');
        localStorage.setItem(KEY_COLLAPSED, shell.classList.contains('sidebar-collapsed') ? '1' : '0');
    };

    document.querySelectorAll('.sidebar-group').forEach(function (groupe, idx) {
        var cle = 'sidebarGroup' + idx;
        if (localStorage.getItem(cle) === 'collapsed') {
            groupe.classList.add('collapsed');
        }
        var titre = groupe.querySelector('.sidebar-group-title');
        if (titre) {
            titre.addEventListener('click', function () {
                groupe.classList.toggle('collapsed');
                localStorage.setItem(cle, groupe.classList.contains('collapsed') ? 'collapsed' : 'open');
            });
        }
    });

    window.toggleSidebarMobile = function () {
        document.getElementById('sidebar').classList.toggle('mobile-open');
    };
})();
</script>
</body>
</html>
