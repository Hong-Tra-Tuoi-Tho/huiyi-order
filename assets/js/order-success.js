// Popup chúc mừng đặt đơn thành công (lib/order_success_popup.php): mở popup và bắn pháo hoa phía sau thẻ.
(function() {
  const popup = document.getElementById('orderSuccessPopup');
  if (!popup) return;

  const COLORS = ['#ff4d6d', '#ffd166', '#06d6a0', '#4cc9f0', '#f72585', '#ff9f1c', '#ffffff'];
  const SHOW_MS = 8000;
  const LAUNCH_EVERY_MS = 380;
  const FRAME_MS = 1000 / 60;

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function between(min, max) {
    return min + Math.random() * (max - min);
  }

  function startFireworks(canvas) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return function() {};

    const rockets = [];
    const sparks = [];
    const startedAt = performance.now();
    let width = 0;
    let height = 0;
    let lastFrame = startedAt;
    let lastLaunch = 0;
    let frame = 0;

    function resize() {
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      width = canvas.clientWidth;
      height = canvas.clientHeight;
      canvas.width = Math.round(width * ratio);
      canvas.height = Math.round(height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function launch() {
      const targetY = height * between(0.1, 0.5);
      rockets.push({
        x: width * between(0.08, 0.92),
        y: height,
        targetY: targetY,
        speed: between(9, 13),
        drift: between(-1, 1),
        color: pick(COLORS)
      });
    }

    function explode(rocket) {
      const count = Math.round(between(55, 90));
      const secondColor = pick(COLORS);
      const power = between(3.5, 6.5);
      for (let i = 0; i < count; i++) {
        const angle = Math.PI * 2 * i / count;
        const speed = power * between(0.35, 1);
        sparks.push({
          x: rocket.x,
          y: rocket.y,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed,
          life: 1,
          fade: between(0.011, 0.02),
          size: between(1.2, 2.6),
          color: i % 3 === 0 ? secondColor : rocket.color
        });
      }
    }

    function dot(x, y, size, color, alpha) {
      ctx.globalAlpha = alpha;
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(x, y, size, 0, Math.PI * 2);
      ctx.fill();
    }

    function tick(now) {
      const step = Math.min((now - lastFrame) / FRAME_MS, 3);
      lastFrame = now;

      // Xoá dần khung cũ để giữ canvas trong suốt mà vẫn có vệt sáng.
      ctx.globalCompositeOperation = 'destination-out';
      ctx.globalAlpha = 1;
      ctx.fillStyle = 'rgba(0, 0, 0, .22)';
      ctx.fillRect(0, 0, width, height);
      ctx.globalCompositeOperation = 'lighter';

      if (now - startedAt < SHOW_MS && now - lastLaunch > LAUNCH_EVERY_MS) {
        lastLaunch = now;
        launch();
        if (Math.random() < 0.5) launch();
      }

      for (let i = rockets.length - 1; i >= 0; i--) {
        const rocket = rockets[i];
        rocket.y -= rocket.speed * step;
        rocket.x += rocket.drift * step;
        dot(rocket.x, rocket.y, 2.2, rocket.color, 1);
        if (rocket.y <= rocket.targetY) {
          explode(rocket);
          rockets.splice(i, 1);
        }
      }

      for (let i = sparks.length - 1; i >= 0; i--) {
        const spark = sparks[i];
        spark.vx *= Math.pow(0.975, step);
        spark.vy = spark.vy * Math.pow(0.975, step) + 0.05 * step;
        spark.x += spark.vx * step;
        spark.y += spark.vy * step;
        spark.life -= spark.fade * step;
        if (spark.life <= 0) {
          sparks.splice(i, 1);
          continue;
        }
        dot(spark.x, spark.y, spark.size, spark.color, spark.life);
      }

      const finished = now - startedAt >= SHOW_MS && !rockets.length && !sparks.length;
      if (finished) {
        stop();
        return;
      }
      frame = requestAnimationFrame(tick);
    }

    function stop() {
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', resize);
      ctx.clearRect(0, 0, width, height);
    }

    resize();
    window.addEventListener('resize', resize);
    // Loạt mở màn: ba quả nổ gần như cùng lúc ngay khi popup hiện.
    launch();
    launch();
    launch();
    frame = requestAnimationFrame(tick);
    return stop;
  }

  popup.querySelector('[data-order-popup-close]').addEventListener('click', function() {
    popup.close();
  });
  // Bấm ra ngoài thẻ là bấm vào chính dialog, vì canvas không nhận sự kiện chuột.
  popup.addEventListener('click', function(event) {
    if (event.target === popup) popup.close();
  });

  popup.showModal();

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const stopFireworks = startFireworks(popup.querySelector('[data-order-popup-fireworks]'));
  popup.addEventListener('close', stopFireworks);
})();
