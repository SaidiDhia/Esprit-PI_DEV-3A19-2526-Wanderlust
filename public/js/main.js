// WanderLust — Main JS

document.addEventListener('DOMContentLoaded', () => {

    // ── Avatar dropdown on click (mobile support) ──
    const avatarMenu = document.querySelector('.avatar-menu');
    if (avatarMenu) {
        avatarMenu.addEventListener('click', (e) => {
            const dropdown = avatarMenu.querySelector('.dropdown-menu-custom');
            if (dropdown) {
                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            }
            e.stopPropagation();
        });
        document.addEventListener('click', () => {
            const dropdown = avatarMenu.querySelector('.dropdown-menu-custom');
            if (dropdown) dropdown.style.display = 'none';
        });
    }

    // ── Active nav highlight (already done in Twig via _route, but double-check) ──
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-btn').forEach(btn => {
        if (btn.getAttribute('href') !== '/' && currentPath.startsWith(btn.getAttribute('href'))) {
            btn.classList.add('active');
        }
    });

    // ── Marketplace filter buttons ──
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ── Chat list item highlighting ──
    document.querySelectorAll('.chat-item').forEach(item => {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ── Chat send button (demo) ──
    const sendBtn = document.querySelector('.chat-input-bar .btn');
    const chatInput = document.querySelector('.chat-input');
    const chatMessages = document.querySelector('.chat-messages');

    if (sendBtn && chatInput && chatMessages) {
        const doSend = () => {
            const text = chatInput.value.trim();
            if (!text) return;
            const msg = document.createElement('div');
            msg.className = 'msg msg-sent';
            msg.textContent = text;
            chatMessages.appendChild(msg);
            chatInput.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;
        };
        sendBtn.addEventListener('click', doSend);
        chatInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') doSend(); });
    }

    // ── Settings sidebar nav ──
    document.querySelectorAll('.settings-nav-item').forEach(item => {
        item.addEventListener('click', function (e) {
            if (this.href && this.href !== '#' && !this.href.endsWith('#')) return;
            e.preventDefault();
            document.querySelectorAll('.settings-nav-item').forEach(i => i.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ── Entrance animations ──
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.08 });

    document.querySelectorAll('.module-card, .card, .blog-card').forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(28px)';
        el.style.transition = `opacity 0.45s ease ${i * 0.07}s, transform 0.45s ease ${i * 0.07}s`;
        observer.observe(el);
    });

});
