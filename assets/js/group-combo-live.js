/*
 * Trang chọn nước trong combo (group/combo.php): tự cập nhật mỗi 3 giây.
 * Tải ngầm lại chính trang (live=1), so data-signature; có thay đổi thì cập nhật
 * tiến độ, ly đã chọn, nhóm đã đủ. Form món đang mở dở được giữ nguyên.
 */
(() => {
  'use strict';

  const INTERVAL = 3000;
  const live = document.querySelector('.hgc-live[data-live-url]');
  if (!live) {
    return;
  }

  let busy = false;
  let timer = null;

  // Dropdown topping (giống assets/js/app.js) cho các nút mới chèn vào trang.
  function initMultiCheck(root) {
    root.querySelectorAll('[data-multi-check]').forEach(box => {
      const summary = box.querySelector('summary');
      const inputs = box.querySelectorAll('input[type="checkbox"]');
      const sync = () => {
        const names = [...inputs].filter(input => input.checked).map(input => input.dataset.label);
        summary.textContent = names.length ? names.join(', ') : summary.dataset.placeholder;
      };
      inputs.forEach(input => input.addEventListener('change', sync));
      document.addEventListener('click', event => {
        if (box.isConnected && !box.contains(event.target)) {
          box.open = false;
        }
      });
      sync();
    });
  }

  // Khung combo / nhóm món / món: khác nhau thì thay cả vùng (chủ nhóm thêm hoặc xoá combo).
  function structureKey(root) {
    return [...root.querySelectorAll('[data-combo]')].map(section => {
      const groups = [...section.querySelectorAll('[data-group]')].map(group => {
        const drinks = [...group.querySelectorAll('[data-drink]')].map(drink => drink.dataset.drink);
        return group.dataset.group + ':' + drinks.join(',');
      });
      return section.dataset.combo + '{' + groups.join('|') + '}';
    }).join(';');
  }

  // Chỉ thay khi HTML thực sự khác: tránh vẽ lại ảnh / nhấp nháy các phần không đổi.
  function swap(oldNode, newNode) {
    if (oldNode.outerHTML !== newNode.outerHTML) {
      oldNode.replaceWith(newNode);
    }
  }

  function replaceNode(oldNode, newNode) {
    oldNode.replaceWith(newNode);
    initMultiCheck(newNode);
  }

  // Một món: đang mở form mà vẫn chọn được thì giữ form, chỉ chỉnh số ly tối đa.
  function updateDrink(oldDrink, newDrink) {
    const oldForm = oldDrink.querySelector('form');
    const newForm = newDrink.querySelector('form');
    if (oldDrink.open && oldForm && newForm) {
      const oldQty = oldForm.querySelector('input[name="qty"]');
      const newQty = newForm.querySelector('input[name="qty"]');
      if (oldQty && newQty) {
        oldQty.max = newQty.max;
        if (Number(oldQty.value) > Number(newQty.max)) {
          oldQty.value = newQty.max;
        }
      }
      return;
    }
    replaceNode(oldDrink, newDrink);
  }

  function updateGroup(oldGroup, newGroup) {
    oldGroup.className = newGroup.className;

    const oldHead = oldGroup.querySelector('.hgc-group-head');
    const newHead = newGroup.querySelector('.hgc-group-head');
    if (oldHead && newHead) {
      swap(oldHead, newHead);
    }

    // Danh sách ly đã chọn: thêm, bỏ hoặc thay mới ngay dưới tên nhóm.
    const oldPicked = oldGroup.querySelector('.hgc-picked');
    const newPicked = newGroup.querySelector('.hgc-picked');
    if (oldPicked && newPicked) {
      swap(oldPicked, newPicked);
    } else if (oldPicked) {
      oldPicked.remove();
    } else if (newPicked) {
      oldGroup.querySelector('.hgc-group-head')?.after(newPicked);
    }

    newGroup.querySelectorAll('[data-drink]').forEach(newDrink => {
      const oldDrink = oldGroup.querySelector(`[data-drink="${newDrink.dataset.drink}"]`);
      if (oldDrink) {
        updateDrink(oldDrink, newDrink);
      }
    });
  }

  function updateCombo(oldSection, newSection) {
    oldSection.className = newSection.className;

    const oldHead = oldSection.querySelector('.hgc-combo-head');
    const newHead = newSection.querySelector('.hgc-combo-head');
    if (oldHead && newHead) {
      swap(oldHead, newHead);
    }

    // Nút xoá combo mang item_version mới nhất, thay luôn cho khỏi báo "đã thay đổi".
    const oldRemove = oldSection.querySelector('.hgc-remove-form');
    const newRemove = newSection.querySelector('.hgc-remove-form');
    if (oldRemove && newRemove) {
      swap(oldRemove, newRemove);
    }

    newSection.querySelectorAll('[data-group]').forEach(newGroup => {
      const oldGroup = oldSection.querySelector(`[data-group="${newGroup.dataset.group}"]`);
      if (oldGroup) {
        updateGroup(oldGroup, newGroup);
      }
    });
  }

  // Giữ nguyên vị trí đang xem: nhóm món đang nhìn (hoặc nhóm chứa form vừa gửi) phải nằm đúng chỗ cũ trên màn hình,
  // dù nội dung phía trên/dưới thay đổi chiều cao (tránh bị nhảy xuống footer).
  function findAnchor(preferred) {
    const group = preferred?.closest('[data-group]')
      || [...live.querySelectorAll('[data-group]')].find(el => el.getBoundingClientRect().bottom > 0);
    const section = group?.closest('[data-combo]');
    if (!group || !section) {
      return null;
    }
    return { combo: section.dataset.combo, group: group.dataset.group, top: group.getBoundingClientRect().top, scrollY: window.scrollY };
  }

  function restoreAnchor(anchor) {
    if (!anchor) {
      return;
    }
    const el = live.querySelector(`[data-combo="${anchor.combo}"] [data-group="${anchor.group}"]`);
    if (el) {
      window.scrollBy(0, el.getBoundingClientRect().top - anchor.top);
    } else {
      window.scrollTo(0, anchor.scrollY);
    }
  }

  function apply(fresh, preferred) {
    const anchor = findAnchor(preferred);
    applyFresh(fresh);
    restoreAnchor(anchor);
  }

  // Thanh hành động dưới đáy: thay khi nội dung đổi (đủ / còn thiếu combo).
  function updateBar(fresh) {
    const oldBar = live.querySelector('.hgc-bar');
    const newBar = fresh.querySelector('.hgc-bar');
    if (oldBar && newBar) {
      swap(oldBar, newBar);
    } else if (oldBar) {
      oldBar.remove();
    } else if (newBar) {
      live.append(newBar);
    }
  }

  function applyFresh(fresh) {
    if (structureKey(live) !== structureKey(fresh)) {
      live.replaceChildren(...fresh.childNodes);
      initMultiCheck(live);
    } else {
      fresh.querySelectorAll('[data-combo]').forEach(newSection => {
        const oldSection = live.querySelector(`[data-combo="${newSection.dataset.combo}"]`);
        if (oldSection) {
          updateCombo(oldSection, newSection);
        }
      });
      updateBar(fresh);
    }
    live.dataset.signature = fresh.dataset.signature;
  }

  async function refresh() {
    // Đang gửi form (thêm/bỏ ly) thì để trang tự tải lại sau khi gửi.
    if (busy || document.hidden || document.querySelector('.hgc-page form[data-submitting]')) {
      return;
    }
    busy = true;
    try {
      const response = await fetch(live.dataset.liveUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'text/html' },
      });
      // Nhóm đã chốt / hết hạn: server chuyển về trang giỏ nhóm, đi theo luôn.
      if (response.redirected && !response.url.includes('/group/combo.php')) {
        window.location.href = response.url;
        return;
      }
      if (!response.ok) {
        return;
      }
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const fresh = doc.querySelector('.hgc-live[data-live-url]');
      if (!fresh || fresh.dataset.signature === live.dataset.signature) {
        return;
      }
      apply(document.importNode(fresh, true));
    } catch (_) {
      // Mất mạng tạm thời: bỏ qua, lần sau thử lại.
    } finally {
      busy = false;
    }
  }

  // Thông báo sau thao tác: nổi ở đáy màn hình (không chèn vào trang nên không đẩy nội dung xuống gây giật).
  let noticeTimer = null;
  function showNotice(text) {
    if (!text) {
      return;
    }
    let box = document.querySelector('.hgc-toast');
    if (!box) {
      box = document.createElement('div');
      box.className = 'hgc-toast';
      box.setAttribute('role', 'status');
      document.body.append(box);
    }
    box.textContent = text;
    clearTimeout(noticeTimer);

    noticeTimer = setTimeout(() => box.remove(), 3500);
  }

  // Gửi form thêm / bỏ ly, xoá combo bằng fetch: không tải lại trang. Server vẫn trả redirect như form thường,
  // fetch tự đi theo về trang combo nên lấy luôn HTML mới + thông báo. Lỗi bất ngờ thì gửi form theo cách thường.
  document.addEventListener('submit', async event => {
    const form = event.target;
    // defaultPrevented: group-order.js đã chặn (người dùng huỷ xác nhận, hoặc form đang gửi).
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !live.contains(form) || form.hasAttribute('data-native')) {
      return;
    }
    // Không dùng form.action / form.method: form có ô ẩn tên "action" nên các thuộc tính đó trả về ô input.
    const url = form.getAttribute('action') || '';
    if (!/group\/action\.php/.test(url) || (form.getAttribute('method') || '').toLowerCase() !== 'post') {
      return;
    }
    event.preventDefault();

    // Lấy dữ liệu form trước khi khoá nút (nút bị khoá sẽ không được gửi kèm).
    const body = new FormData(form, event.submitter);
    const buttons = [...form.querySelectorAll('button[type="submit"]')];
    const release = () => {
      delete form.dataset.submitting;
      buttons.forEach(button => { button.disabled = false; });
    };
    buttons.forEach(button => { button.disabled = true; });
    form.dataset.submitting = '1';

    let response;
    let html;
    try {
      response = await fetch(new URL(url, document.baseURI).href, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'fetch' },
      });
      html = await response.text();
    } catch (_) {
      // Mất mạng: gửi form theo cách thường (request_key chặn thêm trùng).
      release();
      HTMLFormElement.prototype.submit.call(form);
      return;
    }

    // Về trang khác (nhóm hết hạn / đã chốt): đi theo luôn.
    if (!response.url.includes('/group/combo.php')) {
      window.location.href = response.url;
      return;
    }

    try {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const fresh = doc.querySelector('.hgc-live[data-live-url]');
      if (!fresh) {
        throw new Error('missing .hgc-live in response');
      }

      const changed = fresh.dataset.signature !== live.dataset.signature;
      const drink = form.closest('details.hgc-drink');
      // Chụp vị trí nhóm trước khi đóng ô chọn món (đóng cũng làm đổi chiều cao).
      const anchor = findAnchor(form);
      if (changed && drink) {
        // Thêm ly thành công: đóng ô chọn món và trả form về mặc định.
        drink.open = false;
        form.reset();
      }

      applyFresh(document.importNode(fresh, true));

      if (changed && drink) {
        // Thêm ly xong: đưa nhóm món vừa chọn lên đầu màn hình để thấy ly vừa chọn.
        // (Không giữ nguyên chỗ cũ được: ô chọn món đóng làm trang ngắn lại, trình duyệt sẽ tự kéo xuống footer.)
        const group = live.querySelector(`[data-combo="${anchor?.combo}"] [data-group="${anchor?.group}"]`);
        if (group) {
          group.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
      } else {
        restoreAnchor(anchor);
      }
      const notice = doc.querySelector('.hgc-page .alert');
      showNotice(notice?.textContent.trim() || '');
    } catch (error) {
      // Đã gửi xong, chỉ lỗi lúc vẽ lại giao diện: không gửi lại form, để vòng cập nhật tự động vẽ lại.
      console.error('group-combo-live:', error);
      live.dataset.signature = '';
    }

    release();
  });

  // Nút "Chọn tiếp combo …": cuộn mượt tới combo còn thiếu (không đổi địa chỉ trang).
  document.addEventListener('click', event => {
    const link = event.target.closest('[data-scroll-to]');
    if (!link || !live.contains(link)) {
      return;
    }
    const target = document.getElementById(link.dataset.scrollTo);
    if (target) {
      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  function start() {
    clearInterval(timer);
    timer = setInterval(refresh, INTERVAL);
  }

  start();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      refresh();
    }
  });
  window.addEventListener('online', refresh);
  window.addEventListener('pagehide', () => clearInterval(timer));
  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      start();
      refresh();
    }
  });
})();
