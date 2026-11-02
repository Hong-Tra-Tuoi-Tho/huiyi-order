/* v19.2.2 — local group-invitation QR preview / PNG / native share.
 * No invitation is uploaded to a QR service. Only the host receives this panel.
 */
(() => {
  'use strict';
  let config = {};
  try { config = JSON.parse(document.getElementById('hyg-config')?.textContent || '{}'); } catch (_) {}
  const previews = new WeakMap();
  let serverOffset = Number.isFinite(Number(config.serverTime)) ? Number(config.serverTime) * 1000 - Date.now() : 0;
  let expiryTimer;
  const now = () => Date.now() + serverOffset;
  const feedback = (section, text) => {
    const node = section?.querySelector('[data-share-feedback]');
    if (node) node.textContent = text;
  };
  function active(section) {
    if (!section || !section.isConnected) return false;
    const expiry = Number(section.dataset.expires) * 1000;
    if (!Number.isFinite(expiry) || expiry <= now()) {
      section.hidden = true;
      previews.delete(section);
      const link = section.querySelector('[data-qr-download]');
      if (link) { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); }
      const image = section.querySelector('[data-qr-image]');
      if (image) { image.removeAttribute('src'); image.hidden = true; }
      return false;
    }
    return !section.hidden;
  }
  function updateClock(seconds) {
    const value = Number(seconds);
    if (Number.isFinite(value) && value > 0) serverOffset = value * 1000 - Date.now();
  }
  function render(section) {
    section.hidden = false;
    if (!active(section)) return;
    const input = section.querySelector('#hyg-invite');
    const url = input?.value || '';
    if (previews.get(section)?.url === url) return;
    const image = section.querySelector('[data-qr-image]');
    const status = section.querySelector('[data-qr-status]');
    const download = section.querySelector('[data-qr-download]');
    const hint = section.querySelector('[data-qr-save-hint]');
    try {
      const parsed = new URL(url);
      if (!/^https?:$/.test(parsed.protocol) || !/^[a-f0-9]{64}$/.test(parsed.searchParams.get('invite') || '')) throw new Error('invalid_invitation');
      if (!window.HuiYiQR || !image || !download) throw new Error('qr_not_ready');
      const qr = window.HuiYiQR.encode(url);
      const border = 4, modules = qr.size + border * 2;
      // Whole pixels per module and a four-module quiet zone, including in PNG exports.
      const scale = Math.max(4, Math.ceil(960 / modules));
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = modules * scale;
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('canvas_unavailable');
      ctx.imageSmoothingEnabled = false;
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#000000';
      for (let y = 0; y < qr.size; y++) {
        for (let x = 0; x < qr.size; x++) {
          if (qr.isDark(x, y)) ctx.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
        }
      }
      const dataURL = canvas.toDataURL('image/png');
      if (!dataURL.startsWith('data:image/png;base64,')) throw new Error('png_unavailable');
      const filename = download.dataset.filename || 'huiyitea-group-qr.png';
      image.src = dataURL; image.hidden = false;
      if (status) status.hidden = true;
      if (hint) hint.hidden = false;
      download.href = dataURL; download.download = filename;
      download.removeAttribute('aria-disabled'); download.removeAttribute('tabindex');
      let file = null;
      // Prepare synchronously before the click; navigator.share needs user activation.
      if (typeof File === 'function') {
        const raw = atob(dataURL.slice(dataURL.indexOf(',') + 1));
        const bytes = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        file = new File([bytes], filename, {type:'image/png'});
      }
      previews.set(section, {url, file});
    } catch (_) {
      previews.delete(section);
      if (image) { image.hidden = true; image.removeAttribute('src'); }
      if (status) { status.textContent = config.qrError || 'QR Code unavailable. Please copy the link.'; status.hidden = false; }
      if (download) { download.removeAttribute('href'); download.setAttribute('aria-disabled','true'); download.tabIndex = -1; }
      if (hint) hint.hidden = true;
    }
  }
  function sync(seconds) {
    if (seconds !== undefined) updateClock(seconds);
    document.querySelectorAll('[data-group-share]').forEach(render);
  }
  // Floating message so the result is visible even when the inline feedback line is off-screen.
  function toast(text) {
    document.querySelectorAll('.hyg-share-toast').forEach(node => node.remove());
    const line = document.createElement('div');
    line.className = 'cart-toast hyg-share-toast';
    line.setAttribute('role', 'status');
    line.textContent = text;
    document.body.append(line);
    setTimeout(() => line.remove(), 3500);
  }

  // Legacy copy for plain-HTTP pages and in-app browsers without the Clipboard API.
  function legacyCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '0';
    area.style.left = '0';
    area.style.opacity = '0';
    document.body.append(area);
    area.focus();
    area.select();
    area.setSelectionRange(0, text.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) {}
    area.remove();
    return ok;
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (_) {}
    }
    return legacyCopy(text);
  }

  async function copyLink(section, url, focusTarget, copiedMessage) {
    if (await copyText(url)) {
      const message = copiedMessage || config.copy || 'Copied';
      feedback(section, message);
      toast(message);
      return;
    }
    const input = focusTarget || section.querySelector('#hyg-invite');
    if (input) {
      input.focus();
      input.select();
      input.setSelectionRange(0, input.value.length);
    }
    feedback(section, config.manual || 'Select the link to copy it.');
    toast(config.manual || 'Select the link to copy it.');
  }

  // Own share panel: the OS share sheet lists different apps per device, so Zalo/Messenger/Telegram are offered here on every device.
  let sheet = null;
  function closeSheet() {
    if (!sheet) return;
    sheet.remove();
    sheet = null;
    document.removeEventListener('keydown', onSheetKey);
  }
  function onSheetKey(event) {
    if (event.key === 'Escape') closeSheet();
  }
  // Browsers cannot list installed apps: if zalo:// took no focus from the page, assume Zalo PC is missing and open Zalo Web.
  function openZaloWebIfNoApp() {
    let appOpened = false;
    const markOpened = () => {
      appOpened = true;
    };
    window.addEventListener('blur', markOpened, {once:true});
    document.addEventListener('visibilitychange', markOpened, {once:true});
    setTimeout(() => {
      window.removeEventListener('blur', markOpened);
      document.removeEventListener('visibilitychange', markOpened);
      if (!appOpened) window.open('https://chat.zalo.me/', '_blank', 'noopener');
    }, 1500);
  }
  function openSheet(section, url, title) {
    closeSheet();
    const mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const encoded = encodeURIComponent(url);
    sheet = document.createElement('div');
    sheet.className = 'hyg-sheet';
    sheet.innerHTML =
      '<div class="hyg-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="hyg-sheet-title">' +
        '<h3 id="hyg-sheet-title"></h3>' +
        '<p class="hyg-sheet-hint"></p>' +
        '<input class="hyg-sheet-link" readonly autocomplete="off" spellcheck="false">' +
        '<div class="hyg-sheet-actions">' +
          '<a class="btn" data-sheet-zalo></a>' +
          '<a class="btn" data-sheet-messenger></a>' +
          '<a class="btn" data-sheet-telegram target="_blank" rel="noopener"></a>' +
          '<button class="btn primary" type="button" data-sheet-copy></button>' +
          (navigator.share ? '<button class="btn" type="button" data-sheet-more></button>' : '') +
        '</div>' +
        '<button class="hyg-sheet-close" type="button" data-sheet-close></button>' +
      '</div>';
    sheet.querySelector('h3').textContent = config.sheetTitle || 'Share';
    sheet.querySelector('.hyg-sheet-hint').textContent = config.sheetHint || '';
    const input = sheet.querySelector('.hyg-sheet-link');
    input.value = url;
    input.setAttribute('aria-label', title || url);
    sheet.querySelector('[data-sheet-copy]').textContent = config.copyLabel || 'Copy link';
    // Zalo has no share URL without an OA ID: copy the link, then open Zalo so the customer can paste it.
    const zalo = sheet.querySelector('[data-sheet-zalo]');
    zalo.textContent = config.zalo || 'Zalo';
    zalo.href = 'zalo://';
    const messenger = sheet.querySelector('[data-sheet-messenger]');
    messenger.textContent = config.messenger || 'Messenger';
    messenger.href = mobile
      ? 'fb-messenger://share/?link=' + encoded
      : 'https://www.facebook.com/sharer/sharer.php?u=' + encoded;
    if (!mobile) {
      messenger.target = '_blank';
      messenger.rel = 'noopener';
    }
    const telegram = sheet.querySelector('[data-sheet-telegram]');
    telegram.textContent = config.telegram || 'Telegram';
    telegram.href = 'https://t.me/share/url?url=' + encoded + '&text=' + encodeURIComponent(title);
    const more = sheet.querySelector('[data-sheet-more]');
    if (more) more.textContent = config.more || 'More...';
    sheet.querySelector('[data-sheet-close]').textContent = config.close || 'Close';
    sheet.addEventListener('click', async event => {
      if (event.target === sheet || event.target.closest('[data-sheet-close]')) {
        closeSheet();
        return;
      }
      if (event.target.closest('[data-sheet-copy]')) {
        await copyLink(section, url, input);
        return;
      }
      // No preventDefault: the link still opens Zalo while the copy runs inside the same click.
      if (event.target.closest('[data-sheet-zalo]')) {
        // Already inside Zalo: zalo:// goes nowhere, but the phone's share panel lists Zalo chats.
        if (/Zalo/i.test(navigator.userAgent) && navigator.share) {
          event.preventDefault();
          closeSheet();
          await nativeShare(section, url, title);
          return;
        }
        if (!mobile) openZaloWebIfNoApp();
        await copyLink(section, url, input, config.zaloCopied);
        return;
      }
      if (event.target.closest('[data-sheet-more]')) {
        closeSheet();
        await nativeShare(section, url, title);
      }
    });
    document.addEventListener('keydown', onSheetKey);
    document.body.append(sheet);
    input.focus();
    input.select();
  }

  async function nativeShare(section, url, title) {
    let payload = {title, text:title, url};
    const prepared = previews.get(section);
    // The QR image is optional; the link always travels with it because some apps drop the text when a file is attached.
    try {
      const withFile = {title, text:title + '\n' + url, url, files:[prepared?.file]};
      if (prepared?.file && navigator.canShare && navigator.canShare(withFile)) payload = withFile;
    } catch (_) {}
    try {
      await navigator.share(payload);
    } catch (error) {
      if (error.name === 'AbortError') return;
      // Retry once with the plain link (file sharing can be refused), then fall back to the panel.
      try {
        if (payload.files) await navigator.share({title, text:title, url});
        else throw error;
      } catch (retryError) {
        if (retryError.name !== 'AbortError') openSheet(section, url, title);
      }
    }
  }

  document.addEventListener('click', async event => {
    const control = event.target.closest('[data-group-share-button], [data-qr-download], [data-group-share] [data-copy-url]');
    if (!control) return;
    const section = control.closest('[data-group-share]');
    if (!active(section)) { event.preventDefault(); return; }
    if (control.matches('[data-qr-download]')) {
      if (control.getAttribute('aria-disabled') === 'true' || !control.href) event.preventDefault();
      return;
    }
    event.preventDefault();
    const url = section.querySelector('#hyg-invite')?.value || '';
    if (control.matches('[data-copy-url]')) { await copyLink(section, url); return; }
    openSheet(section, url, control.dataset.shareTitle || '');
  });
  function checkExpiry() { document.querySelectorAll('[data-group-share]').forEach(active); }
  function startTimer() { clearInterval(expiryTimer); expiryTimer = setInterval(checkExpiry, 1000); }
  window.HuiYiGroupQR = Object.freeze({
    sync,
    suspend: () => document.querySelectorAll('[data-group-share]').forEach(section => { section.hidden = true; }),
    expire: checkExpiry
  });
  sync(); startTimer();
  window.addEventListener('pagehide', () => clearInterval(expiryTimer));
  window.addEventListener('pageshow', event => {
    if (event.persisted) { window.HuiYiGroupQR.suspend(); startTimer(); }
    checkExpiry();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) checkExpiry(); });
})();
