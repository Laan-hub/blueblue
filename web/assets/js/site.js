/* GfT – kleine, abhängigkeitsfreie Interaktionen */
(() => {
  const header = document.querySelector('[data-header]');

  // Mobilmenü
  const toggle = document.querySelector('[data-nav-toggle]');
  toggle?.addEventListener('click', () => {
    const open = header.classList.toggle('nav-open');
    toggle.setAttribute('aria-expanded', String(open));
  });

  // Untermenüs (Klick + Escape + Klick außerhalb)
  const subToggles = [...document.querySelectorAll('[data-sub-toggle]')];
  const closeAll = (except) => subToggles.forEach((b) => {
    if (b === except) return;
    b.setAttribute('aria-expanded', 'false');
    b.parentElement.classList.remove('is-open');
  });
  subToggles.forEach((btn) => btn.addEventListener('click', () => {
    const open = btn.getAttribute('aria-expanded') !== 'true';
    closeAll(btn);
    btn.setAttribute('aria-expanded', String(open));
    btn.parentElement.classList.toggle('is-open', open);
  }));
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = subToggles.find((b) => b.getAttribute('aria-expanded') === 'true');
    closeAll();
    open?.focus();
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.main-nav')) closeAll(); });

  // Hero-Slider
  document.querySelectorAll('[data-hero]').forEach((hero) => {
    const slides = [...hero.querySelectorAll('[data-slide]')];
    const dots = [...hero.querySelectorAll('[data-dot]')];
    const count = hero.querySelector('[data-count]');
    if (slides.length < 2) return;
    let i = 0;
    let timer;
    const pad = (n) => String(n).padStart(2, '0');
    const show = (n) => {
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => { s.hidden = k !== i; s.classList.toggle('is-active', k === i); });
      dots.forEach((d, k) => { d.classList.toggle('is-active', k === i); d.toggleAttribute('aria-current', k === i); });
      if (count) count.textContent = `${pad(i + 1)} / ${pad(slides.length)}`;
    };
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const start = () => { if (!reduced) timer = setInterval(() => show(i + 1), 7000); };
    const stop = () => clearInterval(timer);
    dots.forEach((d, k) => d.addEventListener('click', () => { stop(); show(k); }));
    hero.addEventListener('mouseenter', stop);
    hero.addEventListener('focusin', stop);
    start();
  });

  // Länder-Auswahl → Navigation
  document.querySelectorAll('[data-nav-select]').forEach((sel) => {
    sel.addEventListener('change', () => { if (sel.value) window.location.href = sel.value; });
  });

  // Statisches Konzept-Formular: validieren, aber nichts senden
  document.querySelectorAll('[data-static-form]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      let ok = true;
      form.querySelectorAll('[required]').forEach((f) => {
        const valid = f.checkValidity();
        f.setAttribute('aria-invalid', String(!valid));
        if (!valid && ok) { f.focus(); ok = false; }
      });
      if (ok) form.querySelector('.form__status').hidden = false;
    });
  });

  // Teilen
  document.querySelectorAll('[data-share]').forEach((btn) => btn.addEventListener('click', async () => {
    const data = { title: btn.dataset.title, url: btn.dataset.url };
    if (navigator.share) { try { await navigator.share(data); } catch (e) { /* abgebrochen */ } return; }
    try { await navigator.clipboard.writeText(data.url); btn.querySelector('span').textContent = '✓'; } catch (e) { window.prompt('URL', data.url); }
  }));

  // Merken (lokal im Browser)
  document.querySelectorAll('[data-save]').forEach((btn) => {
    const key = 'gft-saved';
    const read = () => { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { return []; } };
    const url = location.pathname;
    const sync = () => btn.setAttribute('aria-pressed', String(read().includes(url)));
    sync();
    btn.addEventListener('click', () => {
      const list = read();
      const next = list.includes(url) ? list.filter((u) => u !== url) : [...list, url];
      try { localStorage.setItem(key, JSON.stringify(next)); } catch (e) { /* ignorieren */ }
      sync();
    });
  });

  // Seiten-Navigation: aktiven Abschnitt markieren
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
