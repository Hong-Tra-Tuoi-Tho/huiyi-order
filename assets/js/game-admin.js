'use strict';
(() => {
  const form = document.querySelector('[data-game-admin]');
  if (!form) return;
  const list = form.querySelector('[data-prize-list]');
  const template = form.querySelector('[data-prize-template]');
  const add = form.querySelector('[data-prize-add]');
  const totalElement = form.querySelector('[data-prize-total-number]');
  const errorElement = form.querySelector('[data-prize-error]');
  let nextIndex = Math.max(-1, ...[...list.querySelectorAll('[name]')].map(el => Number(el.name.match(/^prizes\[(\d+)\]/)?.[1] ?? -1))) + 1;
  const rows = () => [...list.querySelectorAll('[data-prize]')];
  const percent = value => {
    const match = value.match(/^(\d{1,3})(?:\.(\d{1,2}))?$/);
    if (!match) return null;
    const valueInBasisPoints = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
    return valueInBasisPoints <= 10000 ? valueInBasisPoints : null;
  };

  function updateTotal() {
    let total = 0;
    rows().forEach((row, index) => {
      row.querySelector('[data-prize-number]').textContent = String(index + 1);
      const enabled = row.querySelector('[data-prize-enabled]').checked;
      const weight = row.querySelector('[data-prize-weight]');
      const basisPoints = percent(weight.value);
      row.classList.toggle('hgra-inactive', !enabled);
      weight.setCustomValidity(basisPoints === null ? form.dataset.weightError : '');
      if (enabled && basisPoints !== null) total += basisPoints;
      row.querySelector('[data-prize-delete]').disabled = rows().length < 2;
    });
    totalElement.textContent = `${(total / 100).toFixed(2)}%`;
    totalElement.parentElement.classList.toggle('hgra-invalid-total', total !== 10000);
    totalElement.parentElement.setAttribute('aria-live', 'polite');
    add.disabled = rows().length >= 12;
    if (total === 10000) errorElement.hidden = true;
    return total;
  }

  // A dropdown checklist in front of the native multi-select, which stays in the form for posting and validity.
  function checklist(select, extra = () => null) {
    const wrap = document.createElement('div');
    const toggle = document.createElement('button');
    const panel = document.createElement('div');
    wrap.className = 'hgra-multi';
    toggle.type = 'button'; toggle.className = 'hgra-multi-toggle';
    toggle.setAttribute('aria-haspopup', 'true'); toggle.setAttribute('aria-expanded', 'false');
    panel.className = 'hgra-multi-panel'; panel.hidden = true;
    select.classList.add('hgra-multi-native'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
    select.before(wrap); wrap.append(toggle, panel, select);
    const open = show => { panel.hidden = !show; toggle.setAttribute('aria-expanded', String(show)); };
    function sync() {
      const chosen = [...select.selectedOptions].map(option => option.textContent.trim());
      toggle.textContent = !chosen.length ? form.dataset.select : chosen.length <= 2 ? chosen.join(', ') : form.dataset.chosen.replace('{n}', chosen.length);
      toggle.title = chosen.join(', ');
      toggle.classList.toggle('hgra-multi-empty', !chosen.length);
    }
    function render() {
      panel.replaceChildren(...[...select.options].map(option => {
        const line = document.createElement('div');
        const label = document.createElement('label');
        const box = document.createElement('input');
        const text = document.createElement('span');
        line.className = 'hgra-multi-option';
        box.type = 'checkbox'; box.checked = option.selected;
        text.textContent = option.textContent.trim();
        const side = extra(option);
        box.addEventListener('change', () => {
          option.selected = box.checked;
          if (side) side.disabled = !box.checked;
          sync(); select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        label.append(box, text);
        line.append(label);
        if (side) { side.disabled = !box.checked; line.append(side); }
        return line;
      }));
      sync();
    }
    toggle.addEventListener('click', () => open(panel.hidden));
    wrap.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { open(false); toggle.focus(); } });
    document.addEventListener('click', event => { if (!wrap.contains(event.target)) open(false); });
    return { render };
  }

  function bindPrize(row) {
    const kind = row.querySelector('[data-prize-kind]');
    const item = row.querySelector('[data-prize-item]');
    const amount = row.querySelector('[name$="[amount]"]');
    const itemOptions = [...item.options].filter(option => option.dataset.kind).map(option => ({
      kind: option.dataset.kind, value: option.value, text: option.textContent, sizes: option.dataset.sizes
    }));
    let discountAmount = amount.value || '5000';
    const sizesOf = option => { try { return JSON.parse(option.dataset.sizes || '[""]'); } catch (_) { return ['']; } };
    let itemSizes = {};
    try { itemSizes = JSON.parse(item.dataset.itemSizes || '{}') || {}; } catch (_) { itemSizes = {}; }
    const sizeName = item.name.replace(/\[item_ids\]\[\]$/, '[item_sizes]');
    // Every gift drink gets its own cup size, chosen next to it in the list.
    const picker = checklist(item, option => {
      if (kind.value !== 'tea') return null;
      const sizes = sizesOf(option);
      const choice = document.createElement('select');
      choice.name = `${sizeName}[${option.value}]`;
      choice.className = 'hgra-multi-size';
      choice.setAttribute('aria-label', `${form.dataset.sizeLabel}: ${option.textContent.trim()}`);
      sizes.forEach(value => choice.add(new Option(value || form.dataset.single, value)));
      choice.value = sizes.includes(itemSizes[option.value]) ? itemSizes[option.value] : sizes[0];
      choice.hidden = sizes.length === 1;
      choice.addEventListener('change', () => { itemSizes[option.value] = choice.value; });
      return choice;
    });
    function validateItem() {
      item.setCustomValidity(item.required && item.selectedOptions.length === 0 ? form.dataset.select : '');
    }
    function updateKind(initial = false) {
      let selected = [];
      if (initial) { try { selected = JSON.parse(item.dataset.selected || '[]').map(String); } catch (_) { selected = []; } }
      item.replaceChildren();
      itemOptions.filter(option => option.kind === kind.value).forEach(record => {
        const option = new Option(record.text, record.value);
        option.dataset.sizes = record.sizes;
        option.selected = selected.includes(record.value);
        item.add(option);
      });
      picker.render();
      // 'luck' = "Chúc bạn may mắn lần sau": không món, không tiền, không điều kiện; chỉ còn tỷ lệ và tên.
      const gift = kind.value === 'topping' || kind.value === 'tea';
      row.querySelector('[data-prize-item-field]').hidden = !gift;
      row.querySelector('[data-prize-qty-field]').hidden = !gift;
      row.querySelector('[data-prize-amount-field]').hidden = kind.value !== 'discount';
      row.querySelectorAll('[data-prize-cond]').forEach(field => { field.hidden = kind.value === 'luck'; });
      item.required = gift && row.querySelector('[data-prize-enabled]').checked;
      if (kind.value !== 'discount') { if (Number(amount.value) > 0) discountAmount = amount.value; amount.value = '0'; }
      else { amount.value = discountAmount; [...item.options].forEach(option => { option.selected = false; }); picker.render(); }
      validateItem();
    }
    kind.addEventListener('change', () => updateKind());
    item.addEventListener('change', validateItem);
    amount.addEventListener('input', () => { if (kind.value === 'discount') discountAmount = amount.value; });
    row.querySelector('[data-prize-weight]').addEventListener('input', updateTotal);
    row.querySelector('[data-prize-enabled]').addEventListener('change', () => {
      item.required = (kind.value === 'topping' || kind.value === 'tea') && row.querySelector('[data-prize-enabled]').checked;
      validateItem();
      updateTotal();
    });
    row.querySelector('[data-prize-delete]').addEventListener('click', () => { if (rows().length > 1) { row.remove(); updateTotal(); } });
    updateKind(true);
  }

  rows().forEach(bindPrize);

  // Quà khi khách chia sẻ Facebook: nhiều mức giá + tỷ lệ (tổng 100%) hoặc để trống dùng mặc định.
  const shareList = form.querySelector('[data-share-list]');
  const shareTemplate = form.querySelector('[data-share-template]');
  const shareAdd = form.querySelector('[data-share-add]');
  const shareTotal = form.querySelector('[data-share-total-number]');
  const shareError = form.querySelector('[data-share-error]');
  const shareRows = () => [...shareList.querySelectorAll('[data-share-row]')];
  let shareIndex = shareRows().length;
  function updateShareTotal() {
    let total = 0;
    shareRows().forEach(row => {
      const basis = percent(row.querySelector('[data-share-weight]').value);
      if (basis !== null) total += basis;
    });
    shareTotal.textContent = `${(total / 100).toFixed(2)}%`;
    const bad = shareRows().length > 0 && total !== 10000;
    shareTotal.parentElement.classList.toggle('hgra-invalid-total', bad);
    shareError.hidden = !bad;
    return !bad;
  }
  function bindShareRow(row) {
    row.querySelector('[data-share-weight]').addEventListener('input', updateShareTotal);
    row.querySelector('[data-share-delete]').addEventListener('click', () => { row.remove(); updateShareTotal(); });
  }
  shareRows().forEach(bindShareRow);
  shareAdd.addEventListener('click', () => {
    if (shareRows().length >= 10) return;
    const fragment = shareTemplate.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(element => { element.name = element.name.replace('__SINDEX__', String(shareIndex)); });
    const row = fragment.querySelector('[data-share-row]');
    shareList.append(fragment); shareIndex++;
    bindShareRow(row); updateShareTotal();
    row.querySelector('input').focus();
  });
  add.addEventListener('click', () => {
    if (rows().length >= 12) { errorElement.textContent = form.dataset.limitError; errorElement.hidden = false; return; }
    const fragment = template.content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach(element => { element.name = element.name.replace('__INDEX__', String(nextIndex)); });
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    const id = 'prize_' + [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
    fragment.querySelector('[name$="[id]"]').value = id;
    const row = fragment.querySelector('[data-prize]');
    list.append(fragment); nextIndex++;
    bindPrize(row); updateTotal();
    row.querySelector('[data-prize-kind]').focus();
  });

  form.querySelectorAll('[data-media-field]').forEach(field => {
    const upload = field.querySelector('[data-media-upload]');
    const remove = field.querySelector('[data-media-remove]');
    const image = field.querySelector('[data-media-preview]');
    const fallback = field.querySelector('[data-media-default]');
    const error = field.querySelector('[data-media-error]');
    const original = image.getAttribute('src') || '';
    let objectUrl = '';
    const clearBlob = () => { if (objectUrl) URL.revokeObjectURL(objectUrl); objectUrl = ''; };
    const showOriginal = () => {
      const show = !!original && !remove.checked;
      if (show) image.src = original;
      else image.removeAttribute('src');
      image.hidden = !show; fallback.hidden = show;
    };
    upload.addEventListener('change', () => {
      clearBlob(); error.textContent = '';
      const file = upload.files[0];
      if (!file) { showOriginal(); return; }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size < 1 || file.size > 2 * 1024 * 1024) {
        upload.value = ''; error.textContent = form.dataset.fileError; showOriginal(); return;
      }
      objectUrl = URL.createObjectURL(file);
      const current = objectUrl;
      const check = new Image();
      check.onload = () => {
        if (current !== objectUrl) return;
        if (check.naturalWidth < 1 || check.naturalHeight < 1 || check.naturalWidth > 4096 || check.naturalHeight > 4096) {
          clearBlob(); upload.value = ''; error.textContent = form.dataset.fileError; showOriginal(); return;
        }
        remove.checked = false; image.src = objectUrl; image.hidden = false; fallback.hidden = true;
      };
      check.onerror = () => { if (current === objectUrl) { clearBlob(); upload.value = ''; error.textContent = form.dataset.fileError; showOriginal(); } };
      check.src = objectUrl;
    });
    remove.addEventListener('change', () => { clearBlob(); upload.value = ''; error.textContent = ''; showOriginal(); });
    window.addEventListener('pagehide', clearBlob);
  });
  function updateTheme() {
    const selected = form.querySelector('[name="theme"]:checked');
    form.dataset.theme = selected?.value || 'memory';
    form.querySelectorAll('.hgra-theme').forEach(label => label.classList.toggle('hgra-selected', label.querySelector('input').checked));
  }
  form.querySelectorAll('[name="theme"]').forEach(input => input.addEventListener('change', updateTheme));
  form.addEventListener('submit', event => {
    if (!updateShareTotal()) { event.preventDefault(); shareError.scrollIntoView({ block: 'center' }); return; }
    if (updateTotal() !== 10000) { event.preventDefault(); errorElement.textContent = form.dataset.totalError || form.dataset.weightError; errorElement.hidden = false; errorElement.scrollIntoView({ block:'center', behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); }
  });
  updateTheme(); updateTotal(); updateShareTotal();
})();
