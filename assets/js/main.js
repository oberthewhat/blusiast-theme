/**
 * Blusiast — main.js
 * Runs after DOM ready (loaded deferred in footer).
 */

(function () {
  'use strict';

  // ── 1. MOBILE NAV TOGGLE ──────────────────

  const toggle = document.getElementById('nav-toggle');
  const nav    = document.getElementById('site-nav');

  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      const isOpen = nav.classList.toggle('is-open');
      toggle.classList.toggle('active', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target) && !toggle.contains(e.target)) {
        nav.classList.remove('is-open');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) {
        nav.classList.remove('is-open');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        toggle.focus();
      }
    });
  }


  // ── 2. STICKY HEADER SCROLL EFFECT ───────

  const header = document.getElementById('site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      header.classList.toggle('scrolled', window.scrollY > 20);
    }, { passive: true });
  }


  // ── 3. SCROLL-TRIGGERED ANIMATIONS ───────
  // Adds .visible to .bl-animate when in viewport.

  const animEls = document.querySelectorAll('.bl-animate');
  if (animEls.length && 'IntersectionObserver' in window) {
    const obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    animEls.forEach(function (el) { obs.observe(el); });
  } else {
    // No IntersectionObserver support — just show everything
    animEls.forEach(function (el) { el.classList.add('visible'); });
  }


  // ── 4. SMOOTH SCROLL FOR ANCHOR LINKS ────

  document.addEventListener('click', function (e) {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const hash = link.getAttribute('href');
    if (hash === '#' || hash === '#0') return;
    const target = document.querySelector(hash);
    if (!target) return;

    e.preventDefault();
    const offset = (header ? header.offsetHeight : 64) + 16;
    const top = target.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top, behavior: 'smooth' });
    // Update URL without jumping
    history.pushState(null, '', hash);
  });


  // ── 5. COUNTER ANIMATION ─────────────────
  // Add data-target="100" data-suffix="+" to any element
  // with class .bl-count-up

  const counters = document.querySelectorAll('.bl-count-up');
  if (counters.length && 'IntersectionObserver' in window) {
    const cObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        const el       = entry.target;
        const target   = parseInt(el.dataset.target, 10) || 0;
        const suffix   = el.dataset.suffix || '';
        const duration = 1200;
        const start    = performance.now();

        (function tick(now) {
          const p = Math.min((now - start) / duration, 1);
          const v = Math.round((1 - Math.pow(1 - p, 3)) * target);
          el.textContent = v + suffix;
          if (p < 1) requestAnimationFrame(tick);
        })(start);

        cObs.unobserve(el);
      });
    }, { threshold: 0.5 });

    counters.forEach(function (el) { cObs.observe(el); });
  }


  // ── 6. EMAIL FORM BASIC VALIDATION ───────

  const emailForm = document.querySelector('.email-signup__form');
  if (emailForm) {
    emailForm.addEventListener('submit', function (e) {
      const input = emailForm.querySelector('.email-signup__input');
      if (!input || !input.value.includes('@')) {
        e.preventDefault();
        input.focus();
        input.style.borderColor = 'var(--red)';
        setTimeout(function () { input.style.borderColor = ''; }, 2000);
      }
    });
  }

})();
