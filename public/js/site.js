// Progressive enhancement only: every page works without JavaScript.
(function () {
  var btn = document.querySelector('[data-menu-toggle]');
  var nav = document.getElementById('mobile-nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  // Doctor filter selects submit on change.
  document.querySelectorAll('form[data-autosubmit] select, form[data-autosubmit] input[type=date]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
})();

// Hero slider: rotates when there is more than one slide (every 7s, paused on hover/focus and for reduced motion).
(function () {
  var root = document.querySelector('[data-slider]');
  if (!root) return;
  var slides = root.querySelectorAll('.slide');
  if (slides.length < 2) return;
  var i = 0, timer = null;
  var dots = document.createElement('div');
  dots.className = 'slider-dots';
  slides.forEach(function (_, n) {
    var b = document.createElement('button');
    b.type = 'button'; b.setAttribute('aria-label', 'Show slide ' + (n + 1));
    b.addEventListener('click', function () { show(n); });
    dots.appendChild(b);
  });
  root.style.position = 'relative';
  root.appendChild(dots);
  function show(n) {
    i = (n + slides.length) % slides.length;
    slides.forEach(function (s, k) { s.classList.toggle('is-active', k === i); });
    dots.querySelectorAll('button').forEach(function (b, k) { b.setAttribute('aria-current', k === i ? 'true' : 'false'); });
  }
  function start() { if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(function () { show(i + 1); }, 7000); }
  function stop() { clearInterval(timer); }
  root.addEventListener('mouseenter', stop); root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop); root.addEventListener('focusout', start);
  show(0); start();
})();
