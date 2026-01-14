/**
 * Mobile Hamburger Menu
 */
(function() {
    'use strict';
    
    const MOBILE_BREAKPOINT = 767;
    
    document.readyState === 'loading' 
        ? document.addEventListener('DOMContentLoaded', init)
        : init();
    
    function init() {
        const hamburger = document.querySelector('.navbar-toggler');
        const sidebar = document.querySelector('.sidebar');
        
        if (!hamburger || !sidebar) return;
        
        const overlay = createOverlay();
        const body = document.body;
        
        hamburger.addEventListener('click', (e) => {
            e.preventDefault();
            toggleMenu();
        });
        
        overlay.addEventListener('click', closeMenu);
        
        sidebar.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= MOBILE_BREAKPOINT) closeMenu();
            });
        });
        
        window.addEventListener('resize', () => {
            if (window.innerWidth > MOBILE_BREAKPOINT) closeMenu();
        });
        
        function createOverlay() {
            let el = document.querySelector('.sidebar-overlay');
            if (!el) {
                el = document.createElement('div');
                el.className = 'sidebar-overlay';
                body.appendChild(el);
            }
            return el;
        }
        
        function toggleMenu() {
            sidebar.classList.contains('show') ? closeMenu() : openMenu();
        }
        
        function openMenu() {
            sidebar.classList.add('show');
            overlay.classList.add('show');
            body.style.overflow = 'hidden';
        }
        
        function closeMenu() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            body.style.overflow = '';
        }
    }
})();
