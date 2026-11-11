/* Shared carts v19.3.0: preserve open member cards and active forms while polling. */
(() => {
  'use strict';
  const element = document.getElementById('hyg-config');
  let config = {};
  try { config = element ? JSON.parse(element.textContent) : {}; } catch (_) { }
  const status = document.querySelector('[data-sync-status]');
  let busy = false, lastSignature = '', lastShare = null, pollTimer;
  const notice = (message, error = false) => {
    if (status) { status.textContent = message; status.classList.toggle('is-offline', error); }
    else { const line = document.createElement('div'); line.className = 'cart-toast'; line.role = 'status'; line.textContent = message; document.body.append(line); setTimeout(() => line.remove(), 3500); }
  };
  const copy = async (url) => {
    try {
      if (!navigator.clipboard || !window.isSecureContext) throw new Error('clipboard_unavailable');
      await navigator.clipboard.writeText(url); notice(config.copy || 'Copied');
    } catch (_) {
      const input = document.getElementById('hyg-invite');
      if (input) { input.focus(); input.select(); input.setSelectionRange(0, input.value.length); }
      notice(config.manual || 'Select the link to copy it.');
    }
  };
  document.addEventListener('click', async (event) => {
    // The QR component owns these controls, including expiry checks and file sharing.
    if (window.HuiYiGroupQR && event.target.closest('[data-group-share]')) return;
    const share = event.target.closest('[data-share-url]');
    const cp = event.target.closest('[data-copy-url]');
    if (share) {
      if (navigator.share) {
        try { await navigator.share({ title: share.dataset.shareTitle, text: share.dataset.shareTitle, url: share.dataset.shareUrl }); }
        catch (error) { if (error.name !== 'AbortError') await copy(share.dataset.shareUrl); }
      } else await copy(share.dataset.shareUrl);
    }
    if (cp) await copy(cp.dataset.copyUrl);
    if (event.target.closest('[data-group-refresh]')) { if (document.activeElement) document.activeElement.blur(); await refresh(true); }
  });
  // Popup xác nhận thay cho window.confirm của trình duyệt.
  // Promise trả về: 'ok' (nút xác nhận) | 'cancel' (nút còn lại) | 'dismiss' (Esc hoặc bấm nền).
  // Nội dung: **chữ** được in nổi bật. Tuỳ chọn qua data-attribute trên nút/form:
  //   data-confirm-title, data-confirm-ok, data-confirm-cancel, data-confirm-have + data-confirm-goal (thanh tiến độ).
  const confirmLabels = {
    vi: ['Đồng ý', 'Hủy'],
    en: ['OK', 'Cancel'],
    zh: ['確定', '取消'],
  };
  const confirmStyleId = 'hyg-confirm-style';
  const confirmCss = `
.hyg-confirm-backdrop{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(24,12,14,.58);backdrop-filter:blur(3px);animation:hygConfirmFade .18s ease-out}
.hyg-confirm-box{position:relative;width:100%;max-width:400px;padding:30px 24px 22px;border-radius:22px;background:#fffdf9;color:#2a1a1d;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden;animation:hygConfirmPop .22s cubic-bezier(.2,.9,.3,1.2)}
.hyg-confirm-box::before{content:"";position:absolute;inset:0 0 auto 0;height:6px;background:linear-gradient(90deg,var(--primary,#721c2b),#f5b301)}
.hyg-confirm-icon{width:62px;height:62px;margin:0 auto 12px;display:grid;place-items:center;border-radius:50%;background:#fff3cf;font-size:32px;line-height:1}
.hyg-confirm-title{margin:0 0 8px;font-size:20px;font-weight:800;line-height:1.3;color:var(--primary,#721c2b)}
.hyg-confirm-text{margin:0;font-size:16px;line-height:1.7;white-space:pre-line}
.hyg-confirm-text strong{display:inline-block;margin:0 2px;padding:1px 10px;border-radius:999px;background:linear-gradient(135deg,#ff5a4d,#c4161c 55%,#8f0f16);color:#fff;font-size:1.25em;font-weight:900;line-height:1.35;box-shadow:0 3px 8px rgba(196,22,28,.35);animation:hygConfirmPulse 1.6s ease-in-out infinite}
.hyg-confirm-actions{display:flex;gap:10px;margin-top:22px}
.hyg-confirm-actions button{flex:1;min-width:0;padding:13px 14px;border:2px solid transparent;border-radius:14px;font:inherit;font-size:16px;font-weight:800;cursor:pointer;transition:transform .12s,box-shadow .12s}
.hyg-confirm-actions button:active{transform:scale(.97)}
.hyg-confirm-actions button:focus-visible{outline:3px solid #f5b301;outline-offset:2px}
.hyg-confirm-cancel{background:#eef0f3;color:#374151}
.hyg-confirm-ok{background:var(--primary,#721c2b);color:#fff}
.hyg-confirm-box.is-upsell .hyg-confirm-cancel{background:linear-gradient(180deg,#ffc933,#f5a800);color:#4a1018;box-shadow:0 4px 0 #c78500}
.hyg-confirm-box.is-upsell .hyg-confirm-ok{background:linear-gradient(135deg,#ff5a4d,#c4161c 55%,#8f0f16);color:#fff;box-shadow:0 4px 0 #6f0b10}
@media (max-width:420px){.hyg-confirm-actions{flex-direction:column}}
@keyframes hygConfirmFade{from{opacity:0}to{opacity:1}}
@keyframes hygConfirmPop{from{opacity:0;transform:translateY(14px) scale(.94)}to{opacity:1;transform:none}}
@keyframes hygConfirmPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
@media (prefers-reduced-motion:reduce){.hyg-confirm-backdrop,.hyg-confirm-box,.hyg-confirm-text strong{animation:none}}
`;
  // Tách **chữ nổi bật** thành <strong>; dựng bằng DOM nên không chèn HTML thô.
  const fillRichText = (target, message) => {
    const parts = String(message).split(/\*\*(.+?)\*\*/g);
    parts.forEach((part, index) => {
      if (index % 2 === 1) {
        const strong = document.createElement('strong');
        strong.textContent = part;
        target.append(strong);
      } else if (part) {
        target.append(document.createTextNode(part));
      }
    });
  };
  const showConfirm = (message, options = {}) => new Promise((resolve) => {
    if (!document.getElementById(confirmStyleId)) {
      const style = document.createElement('style');
      style.id = confirmStyleId;
      style.textContent = confirmCss;
      document.head.append(style);
    }
    const lang = (document.documentElement.lang || 'vi').slice(0, 2).toLowerCase();
    const labels = confirmLabels[lang] || confirmLabels.vi;
    const upsell = Boolean(options.okLabel && options.cancelLabel);
    const backdrop = document.createElement('div');
    backdrop.className = 'hyg-confirm-backdrop';
    const box = document.createElement('div');
    box.className = 'hyg-confirm-box' + (upsell ? ' is-upsell' : '');
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    if (options.title) {
      const icon = document.createElement('div');
      icon.className = 'hyg-confirm-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '🎮';
      const title = document.createElement('h3');
      title.className = 'hyg-confirm-title';
      title.textContent = options.title;
      box.append(icon, title);
    }
    const text = document.createElement('p');
    text.className = 'hyg-confirm-text';
    fillRichText(text, message);
    box.append(text);
    const actions = document.createElement('div');
    actions.className = 'hyg-confirm-actions';
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'hyg-confirm-cancel';
    cancelButton.textContent = options.cancelLabel || labels[1];
    const okButton = document.createElement('button');
    okButton.type = 'button';
    okButton.className = 'hyg-confirm-ok';
    okButton.textContent = options.okLabel || labels[0];
    actions.append(cancelButton, okButton);
    box.append(actions);
    backdrop.append(box);
    const previousFocus = document.activeElement;
    const close = (result) => {
      document.removeEventListener('keydown', onKey, true);
      backdrop.remove();
      if (previousFocus && previousFocus.focus) previousFocus.focus();
      resolve(result);
    };
    const onKey = (keyEvent) => {
      if (keyEvent.key === 'Escape') {
        keyEvent.preventDefault();
        close('dismiss');
      }
    };
    cancelButton.addEventListener('click', () => close('cancel'));
    okButton.addEventListener('click', () => close('ok'));
    backdrop.addEventListener('click', (clickEvent) => {
      if (clickEvent.target === backdrop) close('dismiss');
    });
    document.addEventListener('keydown', onKey, true);
    document.body.append(backdrop);
    // Popup gợi ý mua thêm: nút "Mua thêm" được chọn sẵn; popup thường: nút xác nhận.
    (upsell ? cancelButton : okButton).focus();
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    // Lần gửi lại sau khi khách đã bấm xác nhận: bỏ qua bước hỏi.
    if (form.dataset.confirmed) {
      delete form.dataset.confirmed;
    } else {
      const submitter = event.submitter;
      const source = submitter?.dataset.confirm ? submitter.dataset : form.dataset;
      const confirmText = source.confirm;
      if (confirmText) {
        event.preventDefault();
        event.stopImmediatePropagation();
        showConfirm(confirmText, {
          title: source.confirmTitle,
          okLabel: source.confirmOk,
          cancelLabel: source.confirmCancel,
        }).then((result) => {
          if (result === 'ok') {
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(submitter || undefined);
            else form.submit();
            return;
          }
          // Bấm nút còn lại ("Mua thêm"/"Hủy"): nếu nút có data-cancel-url thì chuyển khách về trang chọn món để mua thêm.
          const cancelUrl = submitter?.dataset.cancelUrl;
          if (result === 'cancel' && cancelUrl) window.location.href = cancelUrl;
        });
        return;
      }
    }
    if (form.closest('.hyg-page') && form.dataset.submitting) { event.preventDefault(); return; }
    if (form.closest('.hyg-page')) form.dataset.submitting = '1';
  });
  // Step guide (2 → 3 once the group has a drink); server-rendered and escaped like the share card.
  function updateSteps(data) {
    const slot = document.getElementById('hyg-steps-slot');
    if (!slot || typeof data.steps_html !== 'string' || slot.innerHTML.trim() === data.steps_html.trim()) {
      return;
    }
    const template = document.createElement('template');
    template.innerHTML = data.steps_html;
    slot.replaceChildren(template.content);
  }

  function updateShare(data) {
    const slot = document.getElementById('hyg-share-slot');
    if (!slot || typeof data.share_html !== 'string') return;
    if (data.share_html !== lastShare) {
      const template = document.createElement('template');
      // Server-authenticated, escaped host view. Members never receive an invite token.
      template.innerHTML = data.share_html;
      const wasOpen = Boolean(slot.querySelector('details[open]'));
      slot.replaceChildren(template.content);
      if (wasOpen && slot.querySelector('details')) slot.querySelector('details').open = true;
      lastShare = data.share_html;
    }
    window.HuiYiGroupQR?.sync(data.server_time);
  }
  async function refresh(force = false) {
    if (!config.snapshot || busy || document.hidden) return;
    busy = true;
    try {
      const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 12000);
      let response;
      try { response = await fetch(config.snapshot, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { 'Accept': 'application/json' } }); }
      finally { clearTimeout(timeout); }
      const data = await response.json();
      if (!response.ok || !data.ok) {
        if (response.status === 401 || response.status === 403) window.HuiYiGroupQR?.suspend();
        throw new Error(data.message || config.offline);
      }
      // Do not defer revoked/rotated invitations even while the host is editing a quantity.
      updateShare(data);
      updateSteps(data);
      const live = document.getElementById('hyg-room-live');
      if (data.signature !== lastSignature) {
        const template = document.createElement('template'); template.innerHTML = data.html;
        const replacement = template.content.querySelector('#hyg-room-live');
        // Keep polling from replacing a focused control before it can be used.
        const interacting = live && live.contains(document.activeElement) && document.activeElement.closest('form, a, button, input, select, textarea');
        const stateChanged = live && replacement && live.dataset.state !== replacement.dataset.state;
        if (interacting && !force && !stateChanged) { notice(config.changed); return; }
        if (live && replacement) {
          // Keep the host's not-yet-submitted offer choice; "Chốt món" sends it.
          const picked = live.querySelector('[data-promotion-selector] input[name="promotion_mode"]:checked');
          const same = picked && replacement.querySelector(`[data-promotion-selector] input[name="promotion_mode"][value="${picked.value}"]:not(:disabled)`);
          if (same) same.checked = true;
          live.replaceWith(replacement); lastSignature = data.signature;
        }
        const shareBox = document.querySelector('.hyg-share');
        if (shareBox && replacement && replacement.dataset.state !== 'open') shareBox.hidden = true;
      }
      notice(config.sync);
    } catch (_) { notice(config.offline, true); }
    finally { busy = false; }
  }
  window.addEventListener('pageshow', () => document.querySelectorAll('form[data-submitting]').forEach(form => delete form.dataset.submitting));
  if (config.snapshot) {
    pollTimer = setInterval(() => refresh(), 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    window.addEventListener('online', () => refresh());
    window.addEventListener('offline', () => notice(config.offline, true));
    window.addEventListener('pagehide', () => clearInterval(pollTimer));
    window.addEventListener('pageshow', event => { if (event.persisted) { clearInterval(pollTimer); pollTimer = setInterval(() => refresh(), 3000); refresh(); } });
  }
})();
