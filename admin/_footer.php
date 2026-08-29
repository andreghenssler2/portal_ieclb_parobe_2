            <div class="text-secondary small mt-5 pt-3 border-top">Portal IECLB Parobé · v<?= e(defined('APP_VERSION') ? (string)APP_VERSION : '0.43.0') ?></div>
        </div>
    </main>
</div>
<script>
(function () {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar) return;

    sidebar.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth >= 992) return;
            const instance = bootstrap.Offcanvas.getInstance(sidebar);
            if (instance) instance.hide();
        });
    });
})();
</script>
</body>
</html>
