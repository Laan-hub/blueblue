/* GfT – kleine, abhängigkeitsfreie Interaktionen */
(() => {
  const root = document.documentElement;
  const header = document.querySelector('[data-header]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const isVisible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);

  /* ---------------------------------------------------------------------
     Toast-Meldungen (z. B. „Gemerkt“)
     --------------------------------------------------------------------- */
  let toastEl;
  const toast = (msg) => {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(() => toastEl.classList.remove('is-visible'), 3200);
  };

  /* ---------------------------------------------------------------------
     #3 Mobilmenü: Escape, Scroll-Sperre, Fokus-Management
     --------------------------------------------------------------------- */
  const toggle = document.querySelector('[data-nav-toggle]');
  const nav = document.querySelector('[data-nav]');
  const menuOpen = () => header?.classList.contains('nav-open');
  const setMenu = (open, returnFocus = true) => {
    if (!header || !toggle) return;
    header.classList.toggle('nav-open', open);
    root.classList.toggle('nav-locked', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (open) {
      header.classList.remove('is-hidden');
      const first = [...nav.querySelectorAll(focusable)].find(isVisible);
      first?.focus();
    } else if (returnFocus) {
      toggle.focus();
    }
  };
  toggle?.addEventListener('click', () => setMenu(!menuOpen()));
  // Fokus im offenen Menü halten (Toggle + Menü bilden den Fokusbereich)
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !menuOpen()) return;
    const items = [toggle, ...nav.querySelectorAll(focusable)].filter(isVisible);
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  // Beim Wechsel auf Desktop-Breite Menü-Zustand zurücksetzen
  window.matchMedia('(min-width: 1024px)').addEventListener('change', (m) => { if (m.matches && menuOpen()) setMenu(false, false); });

  /* Untermenüs (Klick + Escape + Klick außerhalb) */
  const subToggles = [...document.querySelectorAll('[data-sub-toggle]')];
  const closeSubs = (except) => subToggles.forEach((b) => {
    if (b === except) return;
    b.setAttribute('aria-expanded', 'false');
    b.parentElement.classList.remove('is-open');
  });
  subToggles.forEach((btn) => btn.addEventListener('click', () => {
    const open = btn.getAttribute('aria-expanded') !== 'true';
    closeSubs(btn);
    btn.setAttribute('aria-expanded', String(open));
    btn.parentElement.classList.toggle('is-open', open);
  }));
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.main-nav')) closeSubs();
    if (!e.target.closest('[data-saved]')) closeSaved();
  });

  /* Escape: zuerst Untermenü, dann Merkliste, dann Mobilmenü schließen */
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const openSub = subToggles.find((b) => b.getAttribute('aria-expanded') === 'true');
    if (openSub) { closeSubs(); openSub.focus(); return; }
    if (savedPanel && !savedPanel.hidden) { closeSaved(); savedToggle.focus(); return; }
    if (menuOpen()) setMenu(false);
  });

  /* ---------------------------------------------------------------------
     #9 Sticky-Header: blendet beim Runterscrollen aus, beim Hochscrollen ein
     --------------------------------------------------------------------- */
  if (header) {
    let lastY = window.scrollY;
    let ticking = false;
    const update = () => {
      const y = window.scrollY;
      const mainH = header.querySelector('.site-header__main')?.offsetHeight || 0;
      const past = y > header.offsetHeight + 80;
      const hide = past && y > lastY && !menuOpen();
      header.classList.toggle('is-stuck', y > 1);
      header.classList.toggle('is-hidden', hide);
      root.style.setProperty('--sticky-top', hide ? '0px' : `${mainH}px`);
      lastY = y;
      ticking = false;
    };
    window.addEventListener('scroll', () => { if (!ticking) { requestAnimationFrame(update); ticking = true; } }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  /* ---------------------------------------------------------------------
     #6 Hero-Slider: Pause/Play, Vor/Zurück, Pfeiltasten, Wischen
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-hero]').forEach((hero) => {
    const slides = [...hero.querySelectorAll('[data-slide]')];
    if (slides.length < 2) return;
    const dots = [...hero.querySelectorAll('[data-dot]')];
    const count = hero.querySelector('[data-count]');
    const pauseBtn = hero.querySelector('[data-hero-pause]');
    const pauseLabel = hero.querySelector('[data-pause-label]');
    let i = 0;
    let timer = null;
    let userPaused = reducedMotion; // bei reduzierter Bewegung kein Autoplay
    let hovering = false;
    let focusInside = false;
    const pad = (n) => String(n).padStart(2, '0');

    const show = (n, announce = false) => {
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => { s.hidden = k !== i; s.classList.toggle('is-active', k === i); });
      dots.forEach((d, k) => { d.classList.toggle('is-active', k === i); d.toggleAttribute('aria-current', k === i); });
      if (count) count.textContent = `${pad(i + 1)} / ${pad(slides.length)}`;
      hero.setAttribute('aria-live', announce ? 'polite' : 'off');
    };
    const running = () => !userPaused && !hovering && !focusInside;
    const schedule = () => {
      clearInterval(timer);
      timer = running() ? setInterval(() => show(i + 1), 7000) : null;
    };
    const syncPause = () => {
      pauseBtn?.setAttribute('aria-pressed', String(userPaused));
      pauseBtn?.classList.toggle('is-paused', userPaused);
      if (pauseLabel) pauseLabel.textContent = userPaused ? pauseBtn.dataset.labelPlay : pauseBtn.dataset.labelPause;
    };

    pauseBtn?.addEventListener('click', () => { userPaused = !userPaused; syncPause(); schedule(); });
    hero.querySelector('[data-hero-prev]')?.addEventListener('click', () => { show(i - 1, true); schedule(); });
    hero.querySelector('[data-hero-next]')?.addEventListener('click', () => { show(i + 1, true); schedule(); });
    dots.forEach((d, k) => d.addEventListener('click', () => { show(k, true); schedule(); }));

    hero.addEventListener('mouseenter', () => { hovering = true; schedule(); });
    hero.addEventListener('mouseleave', () => { hovering = false; schedule(); });
    hero.addEventListener('focusin', () => { focusInside = true; schedule(); });
    hero.addEventListener('focusout', (e) => { if (!hero.contains(e.relatedTarget)) { focusInside = false; schedule(); } });

    hero.addEventListener('keydown', (e) => {
      if (e.target.matches('input, textarea, select')) return;
      if (e.key === 'ArrowLeft') { show(i - 1, true); schedule(); }
      if (e.key === 'ArrowRight') { show(i + 1, true); schedule(); }
    });

    // Wischen (Touch / Stift)
    let startX = null;
    let startY = null;
    hero.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') { startX = e.clientX; startY = e.clientY; } });
    hero.addEventListener('pointerup', (e) => {
      if (startX === null) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { show(i + (dx < 0 ? 1 : -1), true); schedule(); }
      startX = null;
    });

    syncPause();
    show(0);
    schedule();
  });

  /* ---------------------------------------------------------------------
     #11 Länderauswahl: navigiert erst nach „Anzeigen“ / Enter
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-country-picker]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const sel = form.querySelector('select');
      e.preventDefault();
      if (sel.value) window.location.href = sel.value;
      else sel.focus();
    });
  });

  /* ---------------------------------------------------------------------
     #4 Statisches Konzept-Formular: Validierung mit Fehlermeldungen als Text
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-static-form]').forEach((form) => {
    const fields = [...form.querySelectorAll('[required]')];
    const summary = form.querySelector('[data-error-summary]');
    const status = form.querySelector('.form__status');
    const labelText = (f) => form.querySelector(`label[for="${f.id}"]`)?.textContent.replace('*', '').trim() || f.name;
    const message = (f) => {
      if (f.validity.valueMissing) return f.tagName === 'SELECT' ? form.dataset.msgSelect : form.dataset.msgRequired;
      if (f.validity.typeMismatch) return form.dataset.msgEmail;
      return '';
    };
    const check = (f) => {
      const err = document.getElementById(`${f.id}-error`);
      const msg = f.checkValidity() ? '' : message(f);
      f.setAttribute('aria-invalid', String(!!msg));
      if (err) { err.textContent = msg; err.hidden = !msg; }
      return !msg;
    };
    fields.forEach((f) => {
      f.addEventListener('blur', () => { if (f.value || f.getAttribute('aria-invalid') === 'true') check(f); });
      f.addEventListener('input', () => { if (f.getAttribute('aria-invalid') === 'true') check(f); });
      f.addEventListener('change', () => { if (f.getAttribute('aria-invalid') === 'true') check(f); });
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const invalid = fields.filter((f) => !check(f));
      status.hidden = true;
      if (invalid.length) {
        summary.innerHTML = '';
        const p = document.createElement('p');
        p.textContent = form.dataset.msgSummary;
        const ul = document.createElement('ul');
        invalid.forEach((f) => {
          const li = document.createElement('li');
          const a = document.createElement('a');
          a.href = `#${f.id}`;
          a.textContent = `${labelText(f)}: ${message(f)}`;
          a.addEventListener('click', (ev) => { ev.preventDefault(); f.focus(); });
          li.appendChild(a);
          ul.appendChild(li);
        });
        summary.append(p, ul);
        summary.hidden = false;
        summary.focus();
        return;
      }
      summary.hidden = true;
      status.hidden = false;
      status.focus();
    });
  });

  /* ---------------------------------------------------------------------
     #5 Cookie-Einstellungen
     --------------------------------------------------------------------- */
  const cookieDialog = document.querySelector('[data-cookie-dialog]');
  document.querySelectorAll('[data-cookie-settings]').forEach((btn) => btn.addEventListener('click', () => {
    if (cookieDialog?.showModal) cookieDialog.showModal();
  }));

  /* ---------------------------------------------------------------------
     Teilen
     --------------------------------------------------------------------- */
  document.querySelectorAll('[data-share]').forEach((btn) => btn.addEventListener('click', async () => {
    const data = { title: btn.dataset.title, url: btn.dataset.url };
    if (navigator.share) { try { await navigator.share(data); } catch (e) { /* abgebrochen */ } return; }
    try { await navigator.clipboard.writeText(data.url); toast(btn.dataset.copied || '✓'); } catch (e) { window.prompt('URL', data.url); }
  }));

  /* ---------------------------------------------------------------------
     #17 Merkliste (lokal im Browser) mit Anzeige im Header
     --------------------------------------------------------------------- */
  const KEY = 'gft-saved';
  const saved = document.querySelector('[data-saved]');
  const savedToggle = saved?.querySelector('[data-saved-toggle]');
  const savedPanel = saved?.querySelector('#saved-panel');
  function closeSaved() {
    if (!savedPanel || savedPanel.hidden) return;
    savedPanel.hidden = true;
    savedToggle.setAttribute('aria-expanded', 'false');
  }
  const read = () => {
    try {
      const list = JSON.parse(localStorage.getItem(KEY) || '[]');
      // ältere Einträge waren reine URLs
      return list.map((x) => (typeof x === 'string' ? { url: x, title: x } : x)).filter((x) => x && x.url);
    } catch (e) { return []; }
  };
  const write = (list) => { try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* ignorieren */ } };
  const here = location.pathname;

  const render = () => {
    if (!saved) return;
    const list = read();
    saved.hidden = list.length === 0;
    saved.querySelector('[data-saved-count]').textContent = list.length;
    const ul = saved.querySelector('[data-saved-list]');
    ul.innerHTML = '';
    list.forEach((item) => {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.href = item.url;
      a.textContent = item.title;
      const rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'saved__remove';
      rm.textContent = '×';
      rm.setAttribute('aria-label', `${saved.dataset.labelRemove}: ${item.title}`);
      rm.addEventListener('click', (e) => {
        e.stopPropagation();
        write(read().filter((x) => x.url !== item.url));
        render();
        syncSaveButtons();
        toast(saved.dataset.labelRemoved);
        if (!read().length) closeSaved(); else savedToggle.focus();
      });
      li.append(a, rm);
      ul.appendChild(li);
    });
  };
  savedToggle?.addEventListener('click', () => {
    const open = savedPanel.hidden;
    savedPanel.hidden = !open;
    savedToggle.setAttribute('aria-expanded', String(open));
  });

  const saveButtons = [...document.querySelectorAll('[data-save]')];
  const syncSaveButtons = () => {
    const isSaved = read().some((x) => x.url === here);
    saveButtons.forEach((b) => {
      b.setAttribute('aria-pressed', String(isSaved));
      const label = b.querySelector('span');
      if (label) label.textContent = isSaved ? (saved?.dataset.labelSaved || label.textContent) : b.dataset.label;
    });
  };
  saveButtons.forEach((btn) => {
    btn.dataset.label = btn.querySelector('span')?.textContent || '';
    btn.addEventListener('click', () => {
      const list = read();
      const exists = list.some((x) => x.url === here);
      const title = (btn.dataset.title || document.title).trim();
      write(exists ? list.filter((x) => x.url !== here) : [...list, { url: here, title }]);
      render();
      syncSaveButtons();
      toast(exists ? saved?.dataset.labelRemoved : saved?.dataset.labelAdded);
    });
  });
  render();
  syncSaveButtons();
  window.addEventListener('storage', (e) => { if (e.key === KEY) { render(); syncSaveButtons(); } });

  /* ---------------------------------------------------------------------
     #14 Kürzerer Such-Platzhalter auf schmalen Screens
     --------------------------------------------------------------------- */
  const narrow = window.matchMedia('(max-width: 479px)');
  const placeholders = [...document.querySelectorAll('input[data-placeholder-short]')];
  const syncPlaceholders = () => placeholders.forEach((inp) => {
    inp.dataset.placeholderLong = inp.dataset.placeholderLong || inp.placeholder;
    inp.placeholder = narrow.matches ? inp.dataset.placeholderShort : inp.dataset.placeholderLong;
  });
  narrow.addEventListener('change', syncPlaceholders);
  syncPlaceholders();

  /* ---------------------------------------------------------------------
     Seiten-Navigation: aktiven Abschnitt markieren
     --------------------------------------------------------------------- */
  const pageNav = document.querySelector('[data-page-nav]');
  if (pageNav && 'IntersectionObserver' in window) {
    const links = [...pageNav.querySelectorAll('a[href^="#"]')];
    const map = new Map(links.map((a) => [a.getAttribute('href').slice(1), a]));
    const io = new IntersectionObserver((entries) => entries.forEach((en) => {
      if (en.isIntersecting) { links.forEach((l) => l.classList.remove('is-active')); map.get(en.target.id)?.classList.add('is-active'); }
    }), { rootMargin: '-30% 0px -60% 0px' });
    map.forEach((_, id) => { const el = document.getElementById(id); if (el) io.observe(el); });
  }
})();
