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
      oldHead.replaceWith(newHead);
    }

    // Danh sách ly đã chọn: thêm, bỏ hoặc thay mới ngay dưới tên nhóm.
    const oldPicked = oldGroup.querySelector('.hgc-picked');
    const newPicked = newGroup.querySelector('.hgc-picked');
    if (oldPicked && newPicked) {
      oldPicked.replaceWith(newPicked);
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
      oldHead.replaceWith(newHead);
    }

    // Nút xoá combo mang item_version mới nhất, thay luôn cho khỏi báo "đã thay đổi".
    const oldRemove = oldSection.querySelector('.hgc-remove-form');
    const newRemove = newSection.querySelector('.hgc-remove-form');
    if (oldRemove && newRemove) {
      oldRemove.replaceWith(newRemove);
    }

    newSection.querySelectorAll('[data-group]').forEach(newGroup => {
      const oldGroup = oldSection.querySelector(`[data-group="${newGroup.dataset.group}"]`);
      if (oldGroup) {
        updateGroup(oldGroup, newGroup);
      }
    });
  }

  function apply(fresh) {
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
