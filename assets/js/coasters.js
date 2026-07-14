/**
 * Blusiast — coasters.js
 * Handles:
 *  - Rating picker (number buttons → hidden input)
 *  - Park typeahead picker (search existing, add new)
 *  - Inline review form toggle per coaster card
 *  - Quick-add form (sidebar)
 *  - AJAX review submission (shared handler)
 */

(function () {
  'use strict';

  /* ── Rating Picker ─────────────────────────────── */

  function initRatingPicker(container) {
    const btns    = container.querySelectorAll('.cif-rating-btn');
    const hidden  = container.querySelector('.cif-rating-val');
    let   current = parseInt(hidden?.value, 10) || 7;

    function setRating(val) {
      current = val;
      if (hidden) hidden.value = val;
      btns.forEach(b => b.classList.toggle('is-active', parseInt(b.dataset.val, 10) === val));
    }

    setRating(current);
    btns.forEach(btn => {
      btn.addEventListener('click', () => setRating(parseInt(btn.dataset.val, 10)));
    });
  }

  /* ── Park Typeahead ────────────────────────────── */

  function initParkPicker(container) {
    const searchInput = container.querySelector('.bl-park-search-input');
    const dropdown    = container.querySelector('.bl-park-dropdown');
    const hiddenInput = container.querySelector('.bl-park-value');
    const hint        = container.querySelector('.bl-park-hint');
    // Support both id-based and class-based add links (sidebar vs top form)
    const addLink     = container.querySelector('.bl-park-add-link') || container.querySelector('[id$="park-add-link"]');

    if (!searchInput || !dropdown || !hiddenInput) return;

    let debounceTimer = null;
    let lastQuery     = '';
    let hasResults    = false;
    let isSelecting   = false; // suppress blur side-effects during a pick
    let focusedIdx    = -1;

    // ── Render dropdown ─────────────────────────────
    function renderDropdown(parks) {
      dropdown.innerHTML = '';

      if (!parks.length) {
        dropdown.hidden = true;
        hasResults = false;
        return;
      }

      hasResults = true;
      focusedIdx = -1;

      parks.forEach(park => {
        const li = document.createElement('li');
        li.className = 'bl-park-option';
        li.textContent = park.name + (park.location ? ' — ' + park.location : '');
        li.dataset.name = park.name;

        const pick = e => {
          e.preventDefault();
          isSelecting = true;
          selectPark(park.name);
          setTimeout(() => { isSelecting = false; }, 400);
        };
        li.addEventListener('mousedown', pick);
        li.addEventListener('touchstart', pick, { passive: false });
        dropdown.appendChild(li);
      });

      dropdown.hidden = false;
    }

    // ── Select a confirmed park ─────────────────────
    function selectPark(name) {
      searchInput.value = name;
      hiddenInput.value = name;
      dropdown.hidden   = true;
      hideHint();
    }

    // ── "Add new" hint — ONLY when search returns 0 ─
    function showAddHint(query) {
      if (!hint || !addLink) return;
      // The link contains a <span> for the park name, keeping "Add" static
      const span = addLink.querySelector('span');
      if (span) {
        span.textContent = '"' + query + '"';
      } else {
        addLink.textContent = '"' + query + '"';
      }
      addLink.dataset.name = query;
      hint.hidden = false;
    }

    function hideHint() {
      if (hint) hint.hidden = true;
    }

    function clearSelection() {
      hiddenInput.value = '';
    }

    // ── Keyboard navigation in dropdown ────────────
    function moveFocus(dir) {
      const items = Array.from(dropdown.querySelectorAll('.bl-park-option'));
      if (!items.length) return;
      focusedIdx = Math.max(0, Math.min(items.length - 1, focusedIdx + dir));
      items.forEach((el, i) => el.classList.toggle('is-focused', i === focusedIdx));
      items[focusedIdx]?.scrollIntoView({ block: 'nearest' });
    }

    function confirmFocused() {
      const items = Array.from(dropdown.querySelectorAll('.bl-park-option'));
      if (focusedIdx >= 0 && items[focusedIdx]) {
        isSelecting = true;
        selectPark(items[focusedIdx].dataset.name);
        setTimeout(() => { isSelecting = false; }, 400);
        return true;
      }
      return false;
    }

    // ── Fetch suggestions from REST ─────────────────
    function fetchParks(q) {
      lastQuery = q;
      const base = (window.blusiast_ajax && window.blusiast_ajax.rest_url)
        ? window.blusiast_ajax.rest_url
        : '/wp-json/blusiast/v1/';
      const url = base + 'parks' + (q ? '?q=' + encodeURIComponent(q) : '');

      fetch(url)
        .then(r => r.json())
        .then(parks => {
          if (q !== lastQuery) return; // stale — discard
          renderDropdown(parks);
          // Only offer "add" when there are genuinely no matches
          if (q && !parks.length) {
            showAddHint(q);
          } else {
            hideHint();
          }
        })
        .catch(() => { dropdown.hidden = true; });
    }

    // ── POST a new park ─────────────────────────────
    function addPark(name) {
      if (!name) return;
      const base = (window.blusiast_ajax && window.blusiast_ajax.rest_url)
        ? window.blusiast_ajax.rest_url
        : '/wp-json/blusiast/v1/';
      const url = base + 'parks';
      const nonce = (window.blusiast_ajax && window.blusiast_ajax.nonce)
        ? window.blusiast_ajax.nonce : '';

      // Optimistic: fill immediately so the form isn't blocked
      selectPark(name);

      fetch(url, {
        method:  'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce':   nonce,
        },
        body: JSON.stringify({ name }),
      })
        .then(r => r.json())
        .then(res => {
          if (res.name) {
            // Use server's canonical (title-cased) version
            selectPark(res.name);
            if (!res.existed) {
              const flash = document.createElement('p');
              flash.style.cssText = 'font-size:12px;color:#4caf50;margin:6px 0 0;';
              flash.textContent = '✓ "' + res.name + '" added to the park list.';
              container.appendChild(flash);
              setTimeout(() => flash.remove(), 3000);
            }
          }
        })
        .catch(() => { /* already set optimistically — leave it */ });
    }

    // ── Events ──────────────────────────────────────

    // On focus: show full list if nothing selected yet
    searchInput.addEventListener('focus', () => {
      focusedIdx = -1;
      if (!hiddenInput.value) fetchParks('');
    });

    // On type: search as they go
    searchInput.addEventListener('input', () => {
      clearSelection();
      hideHint();
      focusedIdx  = -1;
      const q = searchInput.value.trim();
      clearTimeout(debounceTimer);

      if (!q) {
        dropdown.hidden = true;
        fetchParks(''); // show full list again
        return;
      }

      debounceTimer = setTimeout(() => fetchParks(q), 220);
    });

    // Keyboard: arrows + enter + escape
    searchInput.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (dropdown.hidden) fetchParks(searchInput.value.trim());
        moveFocus(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveFocus(-1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        // Prefer highlighted suggestion
        if (!confirmFocused()) {
          // Only allow adding if search genuinely returned no matches
          if (!hiddenInput.value && searchInput.value.trim() && !hasResults) {
            addPark(searchInput.value.trim());
          }
        }
      } else if (e.key === 'Escape') {
        dropdown.hidden = true;
        hideHint();
      }
    });

    // Blur: 300ms window for touch/mouse events to fire first
    searchInput.addEventListener('blur', () => {
      setTimeout(() => {
        if (isSelecting) return;
        dropdown.hidden = true;
        // Only show the add hint if nothing selected AND no results exist
        if (!hiddenInput.value && searchInput.value.trim() && !hasResults) {
          showAddHint(searchInput.value.trim());
        }
      }, 300);
    });

    // "Add" link — click and touch
    if (addLink) {
      const doAdd = e => {
        e.preventDefault();
        isSelecting = true;
        addPark(addLink.dataset.name || searchInput.value.trim());
        setTimeout(() => { isSelecting = false; }, 400);
      };
      addLink.addEventListener('click', doAdd);
      addLink.addEventListener('touchstart', doAdd, { passive: false });
    }
  }

  /* ── Submit handler ────────────────────────────── */

  function submitReview(form, msgEl, onSuccess) {
    const data = new FormData(form);
    data.set('action', 'blusiast_submit_review');

    const coaster = data.get('coaster_name')?.trim();
    const park    = data.get('park_name')?.trim();
    const text    = data.get('review_text')?.trim();
    if (!coaster || !park) { showMsg(msgEl, 'error', 'Coaster and park are required.'); return; }
    if (!text)              { showMsg(msgEl, 'error', 'Please write a review.');        return; }

    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }

    const doReviewFetch = (postData) => fetch(window.blusiast_ajax?.url || '/wp-admin/admin-ajax.php', {
      method:  'POST',
      body:    postData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(r => r.json()).then(res => {
        if (res.success) {
          showMsg(msgEl, 'success', res.data?.message || 'Review submitted! Thanks for sharing with the crew.');
          // Fire GA4 event
          var coasterVal = form.querySelector('[name="coaster_name"]')?.value || '';
          var parkVal    = form.querySelector('[name="park_name"]')?.value    || form.querySelector('.bl-park-value')?.value || '';
          document.dispatchEvent(new CustomEvent('blusiast:review_submitted', { detail: { coaster: coasterVal, park: parkVal } }));
          form.reset();
          // Clear park picker state on reset
          const picker = form.closest('.coasters-quick-form, .coaster-inline-form__inner, .bl-review-modal__body');
          if (picker) {
            const searchInput = picker.querySelector('.bl-park-search-input');
            const hiddenInput = picker.querySelector('.bl-park-value');
            const hint        = picker.querySelector('.bl-park-hint');
            if (searchInput) searchInput.value = '';
            if (hiddenInput) hiddenInput.value = '';
            if (hint)        hint.hidden = true;
          }
          const ratingContainer = form.querySelector('.cif-rating-picker') ? form : form.closest('form');
          if (ratingContainer) initRatingPicker(ratingContainer);
          if (onSuccess) onSuccess();
          else setTimeout(() => location.reload(), 2000);
        } else {
          showMsg(msgEl, 'error', res.data?.message || 'Something went wrong. Try again.');
        }
    }).catch(() => showMsg(msgEl, 'error', 'Network error. Check your connection.'))
      .finally(() => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Review'; }
      });

    // reCAPTCHA v3 — inject token if available, then do fetch
    if (window.blusiast_get_recaptcha_token) {
      window.blusiast_get_recaptcha_token('review').then(token => {
        data.append('recaptcha_token', token);
        doReviewFetch(data);
      });
    } else {
      doReviewFetch(data);
    }
  }

  function showMsg(el, type, text) {
    if (!el) return;
    el.hidden = false;
    el.className = 'cif-msg is-' + type;
    el.textContent = text;
  }

  /* ── Inline card review forms ──────────────────── */

  function initInlineForms() {
    document.querySelectorAll('.coaster-card__review-btn').forEach(btn => {
      const card    = btn.closest('.coaster-card__body');
      const wrapper = card?.querySelector('.coaster-inline-form');
      if (!wrapper) return;

      initRatingPicker(wrapper);

      btn.addEventListener('click', () => {
        const open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!open));
        btn.textContent = open ? '+ Write a Review' : '✕ Cancel';
        wrapper.hidden  = open;
      });

      const cancelBtn = wrapper.querySelector('.cif-cancel');
      cancelBtn?.addEventListener('click', () => {
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = '+ Write a Review';
        wrapper.hidden  = true;
      });

      const form  = wrapper.querySelector('form');
      const msgEl = wrapper.querySelector('.cif-msg');
      form?.addEventListener('submit', e => {
        e.preventDefault();
        submitReview(form, msgEl);
      });
    });
  }

  /* ── Quick-add sidebar form ────────────────────── */

  function initQuickAdd() {
    const toggleBtn = document.getElementById('quick-add-toggle');
    const cancelBtn = document.getElementById('quick-add-cancel');
    const form      = document.getElementById('quick-add-form');
    if (!toggleBtn || !form) return;

    initRatingPicker(form);

    // Init park picker inside the quick-add form
    const parkPicker = form.querySelector('.bl-park-picker');
    if (parkPicker) initParkPicker(parkPicker);

    toggleBtn.addEventListener('click', () => {
      const open = toggleBtn.getAttribute('aria-expanded') === 'true';
      toggleBtn.setAttribute('aria-expanded', String(!open));
      form.hidden = open;
      toggleBtn.textContent = open ? '+ New Review' : '✕ Cancel';
    });

    cancelBtn?.addEventListener('click', () => {
      form.hidden = true;
      toggleBtn.setAttribute('aria-expanded', 'false');
      toggleBtn.textContent = '+ New Review';
    });

    const msgEl = form.querySelector('.cif-msg');
    form.addEventListener('submit', e => {
      e.preventDefault();
      submitReview(form, msgEl);
    });
  }

  /* ── Write Drawer park picker + rating init ────── */

  function initWriteDrawer() {
    const form = document.getElementById('bl-write-drawer-form');
    if (!form) return;
    initRatingPicker(form);
    const parkPicker = form.querySelector('.bl-park-picker');
    if (parkPicker) initParkPicker(parkPicker);
  }

  /* ── Bar animation on scroll ───────────────────── */

  function initBarAnimations() {
    if (!('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });
    document.querySelectorAll('.coaster-card__bar-wrap').forEach(el => observer.observe(el));
  }

  /* ── Boot ──────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  function boot() {
    initInlineForms();
    initWriteDrawer();
    initBarAnimations();
  }

})();
