/**
 * Home page banner slider (index.php, [data-home-slider]).
 * - Autoplay every data-interval ms (admin/banners.php), always in the same direction: the next banner
 *   comes in from the right, and after the last one the first comes in from the right too (no rewind).
 *   This uses a copy of the last slide before the first and a copy of the first after the last; landing on a
 *   copy jumps instantly to the real slide it mirrors.
 * - Pauses while hovered / keyboard-focused, while the tab is hidden, briefly after the visitor swipes or
 *   clicks, and never autoplays when the device asks for reduced motion.
 * - ‹ › buttons, dots and swipe (CSS scroll-snap) all move the same scrollable track.
 */
(function () {
  document.querySelectorAll('[data-home-slider]').forEach(function (slider) {
    const track = slider.querySelector('[data-slider-track]');
    const slides = Array.from(slider.querySelectorAll('[data-slide]'));
    const dots = Array.from(slider.querySelectorAll('[data-slider-dot]'));
    if (!track || slides.length < 2) {
      return;
    }

    const count = slides.length;
    const interval = Math.max(3000, Number(slider.dataset.interval) || 5000);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- Edge copies for the seamless loop ----------
    function makeCopy(slide) {
      const copy = slide.cloneNode(true);
      copy.removeAttribute('data-slide');
      copy.setAttribute('aria-hidden', 'true');
      copy.setAttribute('tabindex', '-1');
      copy.querySelectorAll('img').forEach(function (img) {
        img.removeAttribute('loading');
        img.removeAttribute('fetchpriority');
      });
      return copy;
    }
    track.prepend(makeCopy(slides[count - 1]));
    track.append(makeCopy(slides[0]));
    const panels = Array.from(track.children); // 0 = copy of last, 1..count = real slides, count+1 = copy of first

    let current = 0;      // real slide index 0..count-1
    let hovered = false;
    let resumeAt = 0;     // autoplay waits until this time after a manual move

    function offsetOf(position) {
      return panels[position].offsetLeft - track.offsetLeft;
    }

    // Instant move with smooth scrolling switched off, used to swap a copy for its real slide.
    function jump(position) {
      track.style.scrollBehavior = 'auto';
      track.scrollLeft = offsetOf(position);
      void track.offsetWidth;
      track.style.scrollBehavior = '';
    }

    function markDot() {
      dots.forEach(function (dot, i) {
        if (i === current) {
          dot.setAttribute('aria-current', 'true');
        } else {
          dot.removeAttribute('aria-current');
        }
      });
    }

    // index may be -1 (copy of last) or count (copy of first); the scroll-end handler fixes the position.
    function goTo(index) {
      track.scrollTo({ left: offsetOf(index + 1), behavior: reducedMotion ? 'auto' : 'smooth' });
      current = (index + count) % count;
      markDot();
    }

    // After any scroll (autoplay, buttons, swipe) settles: find the panel in view, swap copies for real slides.
    let scrollEnd = null;
    track.addEventListener('scroll', function () {
      clearTimeout(scrollEnd);
      scrollEnd = setTimeout(function () {
        let position = 0;
        panels.forEach(function (panel, i) {
          if (Math.abs(offsetOf(i) - track.scrollLeft) < Math.abs(offsetOf(position) - track.scrollLeft)) {
            position = i;
          }
        });
        if (position === 0) {
          position = count;
          jump(position);
        } else if (position === count + 1) {
          position = 1;
          jump(position);
        }
        current = position - 1;
        markDot();
      }, 140);
    }, { passive: true });

    // ---------- Controls ----------
    function manual(index) {
      resumeAt = Date.now() + interval * 2;
      goTo(index);
    }

    slider.querySelector('[data-slider-prev]')?.addEventListener('click', function () { manual(current - 1); });
    slider.querySelector('[data-slider-next]')?.addEventListener('click', function () { manual(current + 1); });
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () { manual(Number(dot.dataset.sliderDot)); });
    });
    track.addEventListener('pointerdown', function () { resumeAt = Date.now() + interval * 2; }, { passive: true });

    slider.addEventListener('mouseenter', function () { hovered = true; });
    slider.addEventListener('mouseleave', function () { hovered = false; });

    // ---------- Autoplay ----------
    // Keyboard focus inside the slider pauses it; a mouse click on ‹ › only pauses until resumeAt.
    function keyboardFocused() {
      const active = document.activeElement;
      return Boolean(active && slider.contains(active) && active.matches(':focus-visible'));
    }

    function tick() {
      if (hovered || keyboardFocused() || document.hidden || Date.now() < resumeAt) {
        return;
      }
      goTo(current + 1); // from the last slide this lands on the copy of the first, still moving right
    }

    // Start on the real first slide (panel 1), not on the copy of the last.
    jump(1);
    window.addEventListener('load', function () { jump(current + 1); });
    window.addEventListener('resize', function () { jump(current + 1); });

    if (!reducedMotion) {
      setInterval(tick, interval);
    }
  });
})();
