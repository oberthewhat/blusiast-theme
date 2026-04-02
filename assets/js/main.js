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


  // ── 7. EVENT SIGN-UP FORM (inline) ──────────

  (function () {
    var form    = document.getElementById('bl-signup-form');
    var success = document.getElementById('bl-signup-success');
    var errBox  = document.getElementById('bl-signup-error');
    var submit  = document.getElementById('bl-signup-submit');
    var section = document.querySelector('.event-signup-box');

    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      // Client-side validation
      var required = form.querySelectorAll('[required]');
      var valid = true;
      required.forEach(function (el) {
        el.style.borderColor = '';
        if (el.type === 'checkbox') {
          if (!el.checked) { el.style.outline = '2px solid var(--red)'; valid = false; }
          else { el.style.outline = ''; }
        } else {
          if (!el.value.trim()) { el.style.borderColor = 'var(--red)'; valid = false; }
        }
      });
      if (!valid) {
        showError('Please fill in all required fields and check the consent box.');
        return;
      }

      // Show spinner
      submit.disabled = true;
      var label   = submit.querySelector('.bl-btn__label');
      var spinner = submit.querySelector('.bl-btn__spinner');
      if (label)   label.hidden   = true;
      if (spinner) spinner.hidden = false;
      if (errBox)  errBox.hidden  = true;

      var data = new FormData(form);
      data.append('action',   'blusiast_event_signup');
      data.append('event_id', form.dataset.eventId);

      var ajaxUrl = (window.bluSite && bluSite.ajaxUrl)
        ? bluSite.ajaxUrl
        : '/wp-admin/admin-ajax.php';

      fetch(ajaxUrl, { method: 'POST', body: data })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json.success) {
            // Hide the whole form, show success
            form.hidden = true;
            if (success) {
              success.hidden = false;
              success.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
          } else {
            var msg = (json.data && json.data.message)
              ? json.data.message
              : 'Something went wrong. Please try again.';
            showError(msg);
            resetSubmit();
          }
        })
        .catch(function (err) {
          showError('Network error. Please try again.');
          resetSubmit();
        });
    });

    function showError(msg) {
      if (!errBox) return;
      errBox.textContent = msg;
      errBox.hidden = false;
      errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function resetSubmit() {
      submit.disabled = false;
      var label   = submit.querySelector('.bl-btn__label');
      var spinner = submit.querySelector('.bl-btn__spinner');
      if (label)   label.hidden   = false;
      if (spinner) spinner.hidden = true;
    }
  }());

