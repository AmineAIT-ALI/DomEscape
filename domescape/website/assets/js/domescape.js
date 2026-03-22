// DomEscape — Shared JS

document.addEventListener('DOMContentLoaded', () => {

  // ─── Active nav link detection ───────────────────────────────
  const path = window.location.pathname.split('/').pop() || 'index.html';
  const pageMap = {
    'index.html':            'index.html',
    '':                      'index.html',
    'demo.html':             'demo.html',
    'platform.html':         'platform.html',
    'architecture.html':     'architecture.html',
    'control-center.html':   'control-center.html',
    'data-observability.html': 'data-observability.html',
    'docs.html':             'docs.html',
    'vision.html':           'vision.html',
  };
  const activePage = pageMap[path] || path;
  document.querySelectorAll('.nav-links a').forEach(a => {
    const href = a.getAttribute('href');
    if (href === activePage || href === path) {
      a.classList.add('active');
    }
  });

  // ─── Intersection observer for fade-up animations ─────────────
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.style.animationPlayState = 'running';
        observer.unobserve(e.target);
      }
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.animate-fade-up').forEach(el => {
    el.style.animationPlayState = 'paused';
    observer.observe(el);
  });

  // ─── Typewriter effect (index.html hero only) ─────────────────
  const typeEl = document.querySelector('[data-typewriter]');
  if (typeEl) {
    const words = JSON.parse(typeEl.dataset.typewriter);
    let wi = 0, ci = 0, deleting = false;
    const type = () => {
      const word = words[wi];
      typeEl.textContent = deleting ? word.slice(0, ci--) : word.slice(0, ci++);
      if (!deleting && ci > word.length) {
        deleting = true;
        setTimeout(type, 1800);
        return;
      }
      if (deleting && ci < 0) {
        deleting = false;
        wi = (wi + 1) % words.length;
        ci = 0;
      }
      setTimeout(type, deleting ? 50 : 90);
    };
    type();
  }

  // ─── Smooth scroll for anchor links ──────────────────────────
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // ─── Nav scroll shadow ────────────────────────────────────────
  const nav = document.querySelector('.nav');
  if (nav) {
    const onScroll = () => {
      if (window.scrollY > 20) {
        nav.classList.add('scrolled');
      } else {
        nav.classList.remove('scrolled');
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll(); // run once on load in case page loads mid-scroll
  }

});
