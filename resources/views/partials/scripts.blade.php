    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 Standardized Version -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const toggleMobile = document.getElementById('sidebarToggle');
            const toggleDesktop = document.getElementById('sidebarToggleDesktop');
            const closeBtn = document.getElementById('sidebarClose');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleMobileSidebar() {
                if (sidebar) sidebar.classList.toggle('show');
                if (overlay) overlay.classList.toggle('show');
            }

            function toggleDesktopSidebar() {
                document.body.classList.toggle('sidebar-collapsed');
            }

            if (toggleMobile) toggleMobile.addEventListener('click', toggleMobileSidebar);
            if (closeBtn) closeBtn.addEventListener('click', toggleMobileSidebar);
            if (overlay) overlay.addEventListener('click', toggleMobileSidebar);
            
            if (toggleDesktop) toggleDesktop.addEventListener('click', toggleDesktopSidebar);
        });
    </script>
    @stack('modals')
    @stack('scripts')