(() => {
  'use strict';
  const head = document.querySelector('#site-header');
  const menuButton = document.querySelector('.menu-toggle');
  const menu = document.querySelector('#mobile-menu');
  const closeMenu = () => {
    menu?.classList.remove('is-open');
    menuButton?.setAttribute('aria-expanded', 'false');
    menuButton?.setAttribute('aria-label', 'Открыть меню');
  };
  menuButton?.addEventListener('click', () => {
    const open = menu?.classList.toggle('is-open') ?? false;
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
  });
  menu?.addEventListener('click', e => { if (e.target.closest('a')) closeMenu(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
  document.addEventListener('click', e => {
    if (menu?.classList.contains('is-open') && !e.target.closest('.header')) closeMenu();
  });
  const updateHeader = () => head?.classList.toggle('is-scrolled', window.scrollY > 10);
  addEventListener('scroll', updateHeader, { passive: true });
  updateHeader();
  const els = document.querySelectorAll('[data-reveal]');
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
    els.forEach(el => el.classList.add('is-visible'));
  } else {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
      });
    }, { rootMargin: '0px 0px 70px 0px', threshold: 0.08 });
    els.forEach(el => observer.observe(el));
    // Keep first-fold content visible if JS observers fail to run quickly.
    setTimeout(() => document.querySelectorAll('.hero [data-reveal]').forEach(el => el.classList.add('is-visible')), 500);
  }
  document.querySelectorAll('.request-form').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault(); // Never transmit or persist data from this demo.
      if (!form.reportValidity()) return;
      form.querySelector('.form-response')?.remove();
      const result = document.createElement('div');
      result.className = 'form-response';
      result.setAttribute('role', 'status');
      result.textContent = 'Это демонстрационная форма. Поля заполнены корректно, но заявка не отправлена. Для связи используйте телефон или электронную почту.';
      form.append(result);
      form.reset();
    });
    form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = false; });
  });
})();
