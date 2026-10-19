import './bootstrap';

function closeNavigation(restoreFocus = false) {
    document.getElementById('navigation')?.classList.remove('open');
    const toggle = document.getElementById('navigation-toggle');
    toggle?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle?.focus();
}

document.addEventListener('click', event => {
    const passwordToggle = event.target.closest('[data-password-toggle]');
    if (passwordToggle) {
        const input = document.getElementById(passwordToggle.getAttribute('aria-controls'));
        if (input) {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            passwordToggle.textContent = visible ? 'Sembunyikan password' : 'Lihat password';
            passwordToggle.setAttribute('aria-pressed', String(visible));
        }
        return;
    }
    if (event.target.closest('[data-navigation-toggle]')) {
        const open = document.getElementById('navigation')?.classList.toggle('open');
        document.getElementById('navigation-toggle')?.setAttribute('aria-expanded', String(Boolean(open)));
        if (open) document.querySelector('[data-navigation-close]')?.focus();
    } else if (event.target.closest('[data-navigation-close]')) {
        closeNavigation(true);
    } else if (event.target.closest('#navigation a')) {
        closeNavigation();
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.getElementById('navigation')?.classList.contains('open')) {
        closeNavigation(true);
    }
});

document.addEventListener('livewire:navigated', () => closeNavigation());
