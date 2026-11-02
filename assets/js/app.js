// After a Facebook share the customer can land on another page: return once to the page that shared (game.js).
(function () {
  try {
    const saved = JSON.parse(sessionStorage.getItem('kyucPendingShare') || 'null');
    if (!saved?.back || saved.back === location.href || Date.now() - saved.at > 600000) return;
    const back = saved.back;
    delete saved.back;
    sessionStorage.setItem('kyucPendingShare', JSON.stringify(saved));
    location.replace(back);
  } catch (_) {}
})();

// Keep the root service worker fresh even when this script is loaded from /admin/.
if ('serviceWorker' in navigator && !document.body.classList.contains('admin-body')) {
  window.addEventListener('load', () => {
    const script = document.currentScript || [...document.scripts].find(s => s.src.includes('/assets/js/app.js'));
    const swUrl = script ? new URL('../../service-worker.js?v=19.15.0', script.src).href : '/service-worker.js?v=19.15.0';
    navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then(reg => reg.update()).catch(() => {});
  });
}

// Product page: update displayed price when L / XL changes.
document.querySelectorAll('input[name="cup_size"][data-price]').forEach(input => {
  input.addEventListener('change', () => {
    const price = document.querySelector('[data-dynamic-price]');
    if (!price || !input.checked) return;
    const currency = price.dataset.currency || '';
    const amount = Number(input.dataset.price || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
    price.textContent = `${input.value} · ${currency}${amount}`;
  });
});

// Admin product form: switch between single price and L / XL prices.
const sizeForm = document.querySelector('[data-size-price-form]');
if (sizeForm) {
  const toggle = sizeForm.querySelector('[data-size-toggle]');
  const single = sizeForm.querySelector('[data-single-price]');
  const sized = sizeForm.querySelector('[data-size-prices]');
  const singleInput = sizeForm.querySelector('input[name="price"]');
  const lInput = sizeForm.querySelector('input[name="price_l"]');
  const xlInput = sizeForm.querySelector('input[name="price_xl"]');
  const sync = () => {
    const enabled = !!toggle?.checked;
    if (single) single.hidden = enabled;
    if (sized) sized.hidden = !enabled;
    if (singleInput) singleInput.required = !enabled;
    if (lInput) lInput.required = enabled;
    if (xlInput) xlInput.required = enabled;
  };
  toggle?.addEventListener('change', sync);
  sync();
}



// Admin topping form: show the standalone retail price only when standalone sale is enabled.
const toppingPriceForm = document.querySelector('[data-topping-price-form]');
if (toppingPriceForm) {
  const toggle = toppingPriceForm.querySelector('[data-topping-retail-toggle]');
  const priceWrap = toppingPriceForm.querySelector('[data-topping-retail-price]');
  const priceInput = toppingPriceForm.querySelector('input[name="retail_price"]');
  const sync = () => {
    const enabled = !!toggle?.checked;
    if (priceWrap) priceWrap.hidden = !enabled;
    if (priceInput) priceInput.required = enabled;
  };
  toggle?.addEventListener('change', sync);
  sync();
}

// Checkout: only show and require company invoice fields when invoice issuance is selected.
const checkoutForm = document.querySelector('[data-checkout-form]');
if (checkoutForm) {
  const invoiceToggles = checkoutForm.querySelectorAll('[data-invoice-toggle]');
  const invoiceFields = checkoutForm.querySelector('[data-invoice-fields]');
  const requiredFields = invoiceFields?.querySelectorAll('[data-invoice-required]') || [];
  const syncInvoiceFields = () => {
    const selected = checkoutForm.querySelector('input[name="invoice_required"]:checked');
    const enabled = selected?.value === '1';
    if (invoiceFields) invoiceFields.hidden = !enabled;
    requiredFields.forEach(field => { field.required = enabled; });
  };
  invoiceToggles.forEach(input => input.addEventListener('change', syncInvoiceFields));
  syncInvoiceFields();

  const paymentToggles = checkoutForm.querySelectorAll('[data-payment-toggle]');
  const transferInfo = checkoutForm.querySelector('[data-transfer-info]');
  const syncPaymentInfo = () => {
    const selected = checkoutForm.querySelector('input[name="payment_method"]:checked')
      || checkoutForm.querySelector('input[type="hidden"][name="payment_method"]');
    if (transferInfo) transferInfo.hidden = selected?.value !== 'transfer';
  };
  paymentToggles.forEach(input => input.addEventListener('change', syncPaymentInfo));
  syncPaymentInfo();
}

// v1.7.0 storefront catalog: left category rail, right product panel.
const catalog = document.querySelector('[data-catalog]');
if (catalog) {
  const buttons = [...catalog.querySelectorAll('[data-menu-category]')];
  const panels = [...catalog.querySelectorAll('[data-menu-panel]')];
  buttons.forEach(button => button.addEventListener('click', () => {
    const key = button.dataset.menuCategory;
    buttons.forEach(b => b.classList.toggle('active', b === button));
    panels.forEach(p => p.classList.toggle('active', p.dataset.menuPanel === key));
    try { sessionStorage.setItem('drinkMenuCategory', key); } catch (_) {}
  }));
  try {
    const saved = sessionStorage.getItem('drinkMenuCategory');
    const target = buttons.find(b => b.dataset.menuCategory === saved);
    if (target) target.click();
  } catch (_) {}
}

// v1.7.0 flip-card Mini Game.
const memoryGame = document.querySelector('[data-memory-game]');
if (memoryGame) {
  let first = null;
  let lock = false;
  let matched = 0;
  const cards = [...memoryGame.querySelectorAll('.memory-card')];
  const completeForm = document.querySelector('[data-game-complete-form]');
  cards.forEach(card => card.addEventListener('click', () => {
    if (lock || card.classList.contains('matched') || card === first) return;
    card.classList.add('flipped');
    if (!first) { first = card; return; }
    if (first.dataset.symbol === card.dataset.symbol) {
      first.classList.add('matched'); card.classList.add('matched');
      matched += 2; first = null;
      if (matched === cards.length && completeForm) {
        completeForm.hidden = false;
        setTimeout(() => completeForm.requestSubmit ? completeForm.requestSubmit() : completeForm.submit(), 800);
      }
      return;
    }
    lock = true;
    const prev = first; first = null;
    setTimeout(() => { prev.classList.remove('flipped'); card.classList.remove('flipped'); lock = false; }, 650);
  }));
}

// v19.1: admin alerts and push are handled by order-notifications.js.

// v19.15.0: authenticate fresh payment details after returning from a banking app.
(() => {
  if (!document.querySelector('[data-payment-panel]')) return;
  const busy = new WeakSet();
  let qrDialog = null;
  const sameOrigin = value => { try { const url = new URL(value, location.href); return url.origin === location.origin ? url.href : ''; } catch (_) { return ''; } };
  const feedback = (panel, value, warning = false) => {
    const line = panel.querySelector('[data-payment-feedback]');
    if (line) { line.textContent = value; line.classList.toggle('is-warning', warning); }
  };
  async function refresh(panel, force = false) {
    if (!panel.isConnected || busy.has(panel) || document.hidden || panel.dataset.paymentStopped) return;
    if (!force && Date.now() < Number(panel.dataset.paymentNext || 0)) return;
    const endpoint = sameOrigin(panel.dataset.paymentStateUrl);
    if (!endpoint) return;
    busy.add(panel);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(endpoint, {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{Accept:'application/json'}});
      const data = await response.json();
      if (response.status === 401 || response.status === 403) {
        const note = document.createElement('p'); note.role = 'alert'; note.textContent = panel.dataset.paymentLost;
        const link = document.createElement('a'); link.className = 'btn primary'; link.textContent = panel.dataset.paymentLoginLabel; link.href = sameOrigin(panel.dataset.paymentLogin);
        if (qrDialog?.open) qrDialog.close();
        panel.replaceChildren(note, link); panel.dataset.paymentStopped = '1'; return;
      }
      if (!response.ok || !data.ok || typeof data.html !== 'string' || !/^[a-f0-9]{64}$/.test(data.signature || '')) throw new Error('payment_unavailable');
      panel.dataset.paymentNext = String(Date.now() + 20000); panel.dataset.paymentFailures = '0';
      if (data.signature === panel.dataset.paymentVersion) { if (force) feedback(panel, data.message || ''); return; }
      // Do not remove a button during a click or replace text being copied.
      if (panel.contains(document.activeElement) || qrDialog?.open || panel.dataset.paymentSubmitting) {
        feedback(panel, panel.dataset.paymentChanged, true); return;
      }
      const template = document.createElement('template'); template.innerHTML = data.html;
      const replacement = template.content.querySelector('[data-payment-panel]');
      if (!replacement || replacement.dataset.paymentVersion !== data.signature) throw new Error('invalid_payment_state');
      const changeMessage = panel.dataset.paymentChanged;
      panel.replaceWith(replacement); replacement.dataset.paymentNext = String(Date.now() + 20000);
      feedback(replacement, changeMessage, true);
    } catch (_) {
      const failures = Math.min(3, Number(panel.dataset.paymentFailures || 0) + 1);
      panel.dataset.paymentFailures = String(failures); panel.dataset.paymentNext = String(Date.now() + Math.min(120000, 20000 * 2 ** failures));
      feedback(panel, panel.dataset.paymentOffline, true);
    } finally { clearTimeout(timeout); busy.delete(panel); }
  }
  const refreshAll = force => document.querySelectorAll('[data-payment-panel]').forEach(panel => refresh(panel, force));
  document.addEventListener('click', async event => {
    const copy = event.target.closest('[data-payment-copy]');
    if (copy) {
      const panel = copy.closest('[data-payment-panel]'); if (!panel) return;
      try {
        if (!navigator.clipboard || !window.isSecureContext) throw new Error('copy_unavailable');
        await navigator.clipboard.writeText(copy.dataset.paymentCopy); feedback(panel, panel.dataset.paymentCopied);
      } catch (_) {
        panel.querySelector('.payment-manual-copy')?.remove();
        const input = document.createElement('input'); input.className = 'payment-manual-copy'; input.readOnly = true; input.value = copy.dataset.paymentCopy; input.setAttribute('aria-label', panel.dataset.paymentCopyManual);
        copy.closest('.payment-copy').after(input); input.focus(); input.select(); feedback(panel, panel.dataset.paymentCopyManual);
      }
      return;
    }
    const reload = event.target.closest('[data-payment-refresh]');
    if (reload) { event.preventDefault(); if (document.activeElement === reload) reload.blur(); await refresh(reload.closest('[data-payment-panel]'), true); return; }
    const qr = event.target.closest('[data-payment-qr]');
    if (qr) {
      const panel = qr.closest('[data-payment-panel]'); const source = sameOrigin(qr.href);
      if (!panel || !source || typeof HTMLDialogElement === 'undefined') return;
      const dialog = document.createElement('dialog');
      if (typeof dialog.showModal !== 'function') return;
      event.preventDefault(); if (qrDialog) { qrDialog.close(); qrDialog.remove(); }
      dialog.className = 'payment-qr-dialog';
      const close = document.createElement('button'); close.type = 'button'; close.className = 'btn'; close.textContent = panel.dataset.paymentClose; close.addEventListener('click', () => dialog.close());
      const img = document.createElement('img'); img.src = source; img.alt = qr.dataset.qrTitle || '';
      const hint = panel.querySelector('.payment-reference')?.cloneNode(true);
      dialog.append(close, img); if (hint) { hint.querySelectorAll('button').forEach(button => button.remove()); dialog.append(hint); }
      document.body.append(dialog); dialog.addEventListener('close', () => { dialog.remove(); qrDialog = null; qr.focus(); });
      qrDialog = dialog; dialog.showModal();
    }
  });
  window.addEventListener('submit', event => {
    const form = event.target; if (!form.matches('[data-payment-report]')) return;
    const panel = form.closest('[data-payment-panel]');
    if (form.dataset.paymentSubmitting) { event.preventDefault(); return; }
    // Bubbling reaches window after the document's shared confirmation handler.
    if (event.defaultPrevented) return;
    form.dataset.paymentSubmitting = '1'; if (panel) panel.dataset.paymentSubmitting = '1';
  });
  const resume = () => { document.querySelectorAll('[data-payment-panel]').forEach(panel => { delete panel.dataset.paymentSubmitting; panel.querySelectorAll('[data-payment-report]').forEach(form => delete form.dataset.paymentSubmitting); }); refreshAll(true); };
  window.addEventListener('pageshow', resume);
  window.addEventListener('online', () => refreshAll(true));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAll(true); });
  setInterval(() => refreshAll(false), 20000);
})();

// A rendered checkout request is reused on retry; its server-side outcome prevents duplicate orders.
if (checkoutForm) {
  checkoutForm.addEventListener('submit', event => {
    if (checkoutForm.dataset.checkoutSubmitting) { event.preventDefault(); return; }
    checkoutForm.dataset.checkoutSubmitting = '1'; checkoutForm.setAttribute('aria-busy','true');
    const button = checkoutForm.querySelector('.checkout-submit');
    if (button) { button.dataset.originalLabel = button.textContent; button.textContent = checkoutForm.dataset.submittingLabel || button.textContent; }
  });
  window.addEventListener('pageshow', () => {
    delete checkoutForm.dataset.checkoutSubmitting; checkoutForm.removeAttribute('aria-busy');
    const button = checkoutForm.querySelector('.checkout-submit'); if (button?.dataset.originalLabel) button.textContent = button.dataset.originalLabel;
  });
  document.querySelector('[data-checkout-errors]')?.focus();
}

// Product page: multi-check dropdown shows chosen toppings and closes on outside click.
document.querySelectorAll('[data-multi-check]').forEach(box => {
  const summary = box.querySelector('summary');
  const inputs = box.querySelectorAll('input[type="checkbox"]');
  const sync = () => {
    const names = [...inputs].filter(i => i.checked).map(i => i.dataset.label);
    summary.textContent = names.length ? names.join(', ') : summary.dataset.placeholder;
  };
  inputs.forEach(i => i.addEventListener('change', sync));
  document.addEventListener('click', e => { if (!box.contains(e.target)) box.open = false; });
  sync();
});

// Member login/register: eye button shows or hides the password.
document.querySelectorAll('[data-pass-toggle]').forEach(btn => {
  const input = document.getElementById(btn.getAttribute('aria-controls'));
  if (!input) return;
  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? btn.dataset.hideLabel : btn.dataset.showLabel);
  });
});

// Add to cart (product/topping): the quantity flies into the cart icon, then the form submits as usual.
document.addEventListener('submit', event => {
  const form = event.target;
  if (!form.matches('form.option-form') || event.defaultPrevented || form.dataset.cartFlying) {
    return;
  }
  // Editing an existing group item ("Lưu món") does not add anything new.
  const editing = Number(form.querySelector('input[name="group_item_id"]')?.value || 0) > 0;
  if (editing) {
    return;
  }
  const target = document.querySelector('.floating-cart') || document.querySelector('.topbar .cart-link');
  const button = event.submitter || form.querySelector('[type="submit"]');
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!target || !button || reduce || typeof Element.prototype.animate !== 'function') {
    return;
  }
  event.preventDefault();
  form.dataset.cartFlying = '1';

  const qty = Math.max(1, parseInt(form.querySelector('input[name="qty"]')?.value, 10) || 1);
  const from = button.getBoundingClientRect();
  const to = target.getBoundingClientRect();
  const bubble = document.createElement('span');
  bubble.className = 'cart-fly';
  bubble.textContent = '+' + qty;
  bubble.setAttribute('aria-hidden', 'true');
  document.body.append(bubble);

  const startX = from.left + from.width / 2 - bubble.offsetWidth / 2;
  const startY = from.top + from.height / 2 - bubble.offsetHeight / 2;
  const endX = to.left + to.width / 2 - bubble.offsetWidth / 2;
  const endY = to.top + to.height / 2 - bubble.offsetHeight / 2;
  // Arc: rise above the higher of the two points, then drop into the cart.
  const midX = (startX + endX) / 2;
  const midY = Math.min(startY, endY) - 90;

  const submit = () => HTMLFormElement.prototype.submit.call(form);
  const flight = bubble.animate([
    { transform: `translate(${startX}px, ${startY}px) scale(1)`, opacity: 1 },
    { transform: `translate(${midX}px, ${midY}px) scale(1.15)`, opacity: 1, offset: .45 },
    { transform: `translate(${endX}px, ${endY}px) scale(.45)`, opacity: .2 }
  ], { duration: 700, easing: 'cubic-bezier(.45, 0, .55, 1)', fill: 'forwards' });

  flight.onfinish = () => {
    bubble.remove();
    const count = target.querySelector('.floating-cart-count, .cart-count');
    if (count && /^\d+$/.test(count.textContent.trim())) {
      count.textContent = String(parseInt(count.textContent, 10) + qty);
    }
    target.classList.remove('cart-bump');
    void target.offsetWidth;
    target.classList.add('cart-bump');
    setTimeout(submit, 250);
  };
  // Never block adding to cart if the animation is interrupted.
  setTimeout(() => {
    if (document.body.contains(bubble)) {
      bubble.remove();
      submit();
    }
  }, 1500);
});

window.addEventListener('pageshow', () => {
  document.querySelectorAll('form.option-form[data-cart-flying]').forEach(form => delete form.dataset.cartFlying);
});
