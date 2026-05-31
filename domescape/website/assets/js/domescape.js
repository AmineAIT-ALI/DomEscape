// DomEscape — JS v3

document.addEventListener('DOMContentLoaded', () => {

  // ─── Scroll reveal ────────────────────────────────────────────
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('revealed');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08 });
  document.querySelectorAll('[data-reveal]').forEach(el => revealObserver.observe(el));

  // ─── Typewriter ───────────────────────────────────────────────
  const typeEl = document.querySelector('[data-typewriter]');
  if (typeEl) {
    const words = JSON.parse(typeEl.dataset.typewriter);
    let wi = 0, ci = 0, deleting = false;
    const type = () => {
      const word = words[wi];
      typeEl.textContent = deleting ? word.slice(0, ci--) : word.slice(0, ci++);
      if (!deleting && ci > word.length) { deleting = true; setTimeout(type, 2000); return; }
      if (deleting && ci < 0) { deleting = false; wi = (wi + 1) % words.length; ci = 0; }
      setTimeout(type, deleting ? 45 : 85);
    };
    setTimeout(type, 800);
  }

  // ─── Nav shadow on scroll ─────────────────────────────────────
  const nav = document.querySelector('.nav');
  if (nav) {
    const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 24);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // ─── Active nav link ──────────────────────────────────────────
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a').forEach(link => {
    if (link.getAttribute('href').split('/').pop() === currentPage) link.classList.add('active');
  });

  // ─── Stacked cards ────────────────────────────────────────────
  initStackCards();

  // ─── Flow steps: highlight on scroll ─────────────────────────
  const flowItems = document.querySelectorAll('.flow-item');
  if (flowItems.length) {
    const flowObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => entry.target.classList.toggle('active', entry.isIntersecting));
    }, { threshold: 0.5 });
    flowItems.forEach(item => flowObserver.observe(item));
  }

  // ─── Smooth scroll anchors ────────────────────────────────────
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  });

  // ─── Page transitions ─────────────────────────────────────────
  document.querySelectorAll('a[href$=".html"]').forEach(link => {
    if (link.hostname === window.location.hostname) {
      link.addEventListener('click', function (e) {
        const href = this.href;
        e.preventDefault();
        document.body.classList.add('page-out');
        setTimeout(() => { window.location.href = href; }, 200);
      });
    }
  });

});

// ─── Stacked cards — navigation discrète ─────────────────────
function initStackCards() {
  const section      = document.getElementById('stack-section');
  if (!section) return;

  const cards        = Array.from(section.querySelectorAll('.stack-card'));
  const progressFill = document.getElementById('stackProgressFill');
  const counter      = document.getElementById('stackCounter');
  const prevBtn      = document.getElementById('stackPrev');
  const nextBtn      = document.getElementById('stackNext');
  const n            = cards.length;
  let current        = 0;
  let wheelLocked    = false;

  function applyState(instant) {
    cards.forEach((card, i) => {
      if (instant) {
        card.style.transition = 'none';
        requestAnimationFrame(() => card.style.transition = '');
      }

      let ty, scale, opacity;
      if (i < current) {
        const d = current - i;
        scale   = Math.max(0.88, 1 - 0.04 * d);
        opacity = Math.max(0.25, 0.45 - 0.10 * d);
        ty      = -d * 8;
      } else if (i === current) {
        scale = 1; opacity = 1; ty = 0;
      } else {
        scale = 0.96; opacity = 0;
        ty = 56 + (i - current - 1) * 12;
      }

      card.style.transform = `translateY(${ty}px) scale(${scale})`;
      card.style.opacity   = String(opacity);
      card.classList.toggle('is-active', i === current);
    });

    const pct = n > 1 ? (current / (n - 1)) * 100 : 0;
    if (progressFill) progressFill.style.width = `${pct.toFixed(1)}%`;
    if (counter)      counter.textContent = `0${current + 1} / 0${n}`;
    if (prevBtn)      prevBtn.disabled = current === 0;
    if (nextBtn)      nextBtn.disabled = current === n - 1;
  }

  function goTo(index) {
    current = Math.max(0, Math.min(n - 1, index));
    applyState(false);
  }

  // Init
  applyState(true);

  // Buttons
  if (prevBtn) prevBtn.addEventListener('click', () => goTo(current - 1));
  if (nextBtn) nextBtn.addEventListener('click', () => goTo(current + 1));

  // Keyboard (only when section is in viewport)
  document.addEventListener('keydown', e => {
    const r = section.getBoundingClientRect();
    if (r.top > window.innerHeight || r.bottom < 0) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  { e.preventDefault(); goTo(current + 1); }
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')    { e.preventDefault(); goTo(current - 1); }
  });

  // Wheel — capturé au niveau window pour intercepter le touchpad
  // On bloque le scroll de page uniquement quand la section est active
  // et qu'il reste des cartes à parcourir dans ce sens.
  window.addEventListener('wheel', function stackWheel(e) {
    const rect = section.getBoundingClientRect();

    // La section doit couvrir le viewport (tolérance ±40px)
    const pinned = rect.top > -40 && rect.top < 40
                && rect.bottom > window.innerHeight - 40;
    if (!pinned) return;

    const goingDown = e.deltaY > 0;

    // Aux extrémités : libérer le scroll naturel
    if (goingDown && current === n - 1) return;
    if (!goingDown && current === 0)    return;

    // Sinon : capturer l'événement
    e.preventDefault();
    e.stopPropagation();

    if (wheelLocked) return;
    wheelLocked = true;

    goTo(goingDown ? current + 1 : current - 1);

    // Délai adapté au touchpad (momentum)
    setTimeout(() => { wheelLocked = false; }, 820);

  }, { passive: false });
}
