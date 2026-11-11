/**
 * Store partner order alerts (partner/*). Polls partner/api_new_orders.php while the page is open:
 *  - a new order for this store shows the toast, rings and (tab hidden) raises a system notification;
 *  - any change to the store's orders swaps in fresh tabs + table on the list page, without a reload.
 * Ringing keeps going until "Stop ring", the order is accepted, or the admin alert duration runs out.
 * Browsers only allow sound after a tap, so the ring stays silent until "Enable ring" was pressed once.
 */
(function () {
  const body = document.body, cfg = body.dataset;
  if (!cfg.pollUrl) return;
  const store = {
    get(k) { try { return localStorage.getItem('hp-' + k); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem('hp-' + k, v); } catch (_) { } }
  };
  const toast = document.getElementById('hp-alert'), soundBtn = document.getElementById('hp-sound'), notifyBtn = document.getElementById('hp-notify');
  const baseTitle = document.title.replace(/^\(\d+\) /, '');
  // Silenced ids, not a max id: an older order handed back and re-dispatched here must ring again.
  let signature = null, known = null, silenced = new Set(JSON.parse(store.get('silenced') || '[]'));
  let soundOn = store.get('sound') === '1', ctx = null, audio = null, ringTimer = null, ringUntil = 0, failures = 0;

  // ---------- sound ----------
  function unlockAudio() {
    const C = window.AudioContext || window.webkitAudioContext;
    if (!cfg.soundUrl && C && !ctx) ctx = new C();
    if (ctx && ctx.state === 'suspended') ctx.resume();
    if (cfg.soundUrl && !audio) { audio = new Audio(cfg.soundUrl); audio.loop = true; }
  }
  function beep() {
    if (!ctx) return;
    [0, 0.25].forEach(function (at) {
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
      g.gain.setValueAtTime(0.0001, ctx.currentTime + at); g.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + at + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.2);
      o.start(ctx.currentTime + at); o.stop(ctx.currentTime + at + 0.22);
    });
  }
  function startRing() {
    if (!soundOn || cfg.alertEnabled !== '1' || ringTimer) return;
    const seconds = Number(cfg.alertDuration || 20) * (cfg.alertMode === 'minutes' ? 60 : 1);
    ringUntil = cfg.alertMode === 'until_accepted' ? Infinity : Date.now() + seconds * 1000;
    unlockAudio();
    if (audio) { audio.currentTime = 0; audio.play().catch(function () { }); }
    ringTimer = setInterval(function () { if (Date.now() > ringUntil) return stopRing(); if (!audio) beep(); }, 1200);
    if (!audio) beep();
  }
  function stopRing() {
    clearInterval(ringTimer); ringTimer = null;
    if (audio) { audio.pause(); audio.currentTime = 0; }
  }
  // Icon and label are separate spans so narrow screens can show the icon only.
  function setLabel(btn, icon, text) {
    const i = document.createElement('span'), l = document.createElement('span');
    i.textContent = icon; l.textContent = text; l.className = 'hp-top-label'; btn.title = text; btn.replaceChildren(i, l);
  }
  function renderSoundBtn() { if (soundBtn) { soundBtn.classList.toggle('active', soundOn); setLabel(soundBtn, soundOn ? '🔔' : '🔕', soundOn ? cfg.tSoundOn : cfg.tSound); } }
  soundBtn && soundBtn.addEventListener('click', function () {
    soundOn = !soundOn; store.set('sound', soundOn ? '1' : '0'); renderSoundBtn();
    if (soundOn) { unlockAudio(); beep(); } else stopRing();
  });
  // Any tap counts as the gesture browsers require before audio may play.
  document.addEventListener('pointerdown', function () { if (soundOn) unlockAudio(); }, { once: true });

  // ---------- system notification (tab in background) ----------
  function renderNotifyBtn() {
    if (!notifyBtn) return;
    if (!('Notification' in window)) { notifyBtn.hidden = true; return; }
    const on = Notification.permission === 'granted';
    notifyBtn.classList.toggle('active', on); setLabel(notifyBtn, '📲', on ? cfg.tNotifyOn : cfg.tNotify);
  }
  notifyBtn && notifyBtn.addEventListener('click', function () {
    if (Notification.permission === 'default') Notification.requestPermission().then(renderNotifyBtn);
    else if (Notification.permission === 'denied') alert(cfg.tNotifyDenied);
  });
  function systemNotify(order) {
    if (document.visibilityState !== 'hidden' || !('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const n = new Notification(cfg.tNewOrder, { body: '#' + order.order_no + ' · ' + order.customer + ' · ' + order.total, tag: 'hp-order-' + order.id, icon: cfg.icon });
      n.onclick = function () { window.focus(); location.href = order.url; n.close(); };
    } catch (_) { }
  }

  // ---------- toast ----------
  function showToast(orders) {
    if (!toast) return;
    const latest = orders[0];
    toast.querySelector('[data-alert-title]').textContent = '#' + latest.order_no + ' · ' + latest.total;
    toast.querySelector('[data-alert-meta]').textContent = latest.customer + ' · ' + latest.time + (orders.length > 1 ? ' · +' + (orders.length - 1) : '');
    toast.querySelector('[data-alert-view]').href = latest.url;
    toast.hidden = false;
  }
  toast && toast.querySelector('[data-alert-stop]').addEventListener('click', function () {
    stopRing(); toast.hidden = true;
    (known || []).forEach(function (id) { silenced.add(id); });
    store.set('silenced', JSON.stringify(Array.from(silenced).slice(-200)));
  });

  // ---------- list refresh (list page only) ----------
  function refreshList() {
    const parts = document.querySelectorAll('[data-hp-live]');
    if (!parts.length) return;
    fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      if (!html) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      parts.forEach(function (el) { const fresh = doc.querySelector('[data-hp-live="' + el.dataset.hpLive + '"]'); if (fresh) el.replaceWith(fresh); });
    }).catch(function () { });
  }

  // ---------- polling ----------
  function setOffline(off) { const pill = document.getElementById('hp-offline'); if (pill) pill.hidden = !off; }
  function poll() {
    fetch(cfg.pollUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      if (r.status === 401) { location.href = cfg.loginUrl; return null; }
      return r.ok ? r.json() : Promise.reject(r.status);
    }).then(function (d) {
      if (!d || !d.ok) return;
      failures = 0; setOffline(false);
      const ids = d.new_orders.map(function (o) { return o.id; });
      // First answer only records the baseline; orders already waiting still ring unless silenced.
      const fresh = d.new_orders.filter(function (o) { return !silenced.has(o.id) && (known === null || known.indexOf(o.id) < 0); });
      known = ids;
      document.title = (ids.length ? '(' + ids.length + ') ' : '') + baseTitle;
      if (fresh.length) { showToast(fresh); startRing(); fresh.forEach(systemNotify); }
      // Nothing left waiting (accepted / handed back elsewhere): stop the ring and hide the toast.
      // A handed-back order may come back later: forget silences for orders no longer waiting here.
      silenced.forEach(function (id) { if (ids.indexOf(id) < 0) silenced.delete(id); });
      if (!ids.some(function (id) { return !silenced.has(id); })) { stopRing(); if (toast) toast.hidden = true; }
      if (signature !== null && signature !== d.signature) refreshList();
      signature = d.signature;
    }).catch(function () { if (++failures >= 2) setOffline(true); });
  }
  renderSoundBtn(); renderNotifyBtn();
  poll();
  setInterval(poll, Number(cfg.pollMs || 10000));
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') poll(); });
})();
