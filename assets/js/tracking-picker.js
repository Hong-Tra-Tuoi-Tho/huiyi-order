/**
 * Công cụ chọn phần tử cho admin (chỉ được nạp khi admin đã đăng nhập mở trang với ?tracking_picker=1).
 * Rê chuột → viền xanh; bấm → sinh selector, báo số phần tử khớp; xác nhận → gửi về tab admin đã mở trang này.
 * Trang ở chế độ này không ghi log tracking.
 */
(function () {
  'use strict';

  var BLUE = '#1a73e8';
  var GREEN = '#2e9f5b';
  var hoverBox = null;
  var matchBoxes = [];
  var bar = null;
  var chosen = null;
  var locked = false;
  var paused = false;

  function make(tag, css, text) {
    var node = document.createElement(tag);
    node.style.cssText = css;
    if (text) node.textContent = text;
    return node;
  }

  function isOwn(node) {
    return !!(bar && node && (node === bar || bar.contains(node)));
  }

  function esc(value) {
    return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/([^\w-])/g, '\\$1');
  }

  function count(selector) {
    try {
      return document.querySelectorAll(selector).length;
    } catch (error) {
      return 0;
    }
  }

  function stableClass(name) {
    return /^[A-Za-z][\w-]*$/.test(name) &&
      !/\d{3,}/.test(name) &&
      !/^(active|open|show|shown|hover|focus|selected|disabled|current|is-|has-|js-)/.test(name);
  }

  function segment(el) {
    var tag = el.tagName.toLowerCase();
    var classes = Array.prototype.filter.call(el.classList, stableClass).slice(0, 2).map(esc);
    var part = tag + (classes.length ? '.' + classes.join('.') : '');
    var parent = el.parentElement;
    if (parent) {
      var same = Array.prototype.filter.call(parent.children, function (child) { return child.tagName === el.tagName; });
      if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(el) + 1) + ')';
    }
    return part;
  }

  // Ưu tiên: data-track → id → name → class ổn định → đường đi trong cây HTML.
  function buildSelector(el) {
    var track = el.getAttribute('data-track');
    if (track) return '[data-track="' + track.replace(/"/g, '\\"') + '"]';
    if (el.id && !/\d{4,}/.test(el.id) && count('#' + esc(el.id)) === 1) return '#' + esc(el.id);
    var tag = el.tagName.toLowerCase();
    var candidates = [];
    var name = el.getAttribute('name');
    var value = el.getAttribute('value');
    if (name) {
      candidates.push(tag + '[name="' + name.replace(/"/g, '\\"') + '"]' + (value ? '[value="' + value.replace(/"/g, '\\"') + '"]' : ''));
      candidates.push(tag + '[name="' + name.replace(/"/g, '\\"') + '"]');
    }
    var classes = Array.prototype.filter.call(el.classList, stableClass).slice(0, 3).map(esc);
    if (classes.length) {
      candidates.push(tag + '.' + classes.join('.'));
      candidates.push('.' + classes.join('.'));
    }
    for (var i = 0; i < candidates.length; i++) {
      try {
        if (el.matches(candidates[i]) && count(candidates[i]) <= 30) return candidates[i];
      } catch (error) { /* thử cách khác */ }
    }
    var parts = [];
    var node = el;
    for (var depth = 0; node && node.nodeType === 1 && node !== document.body && depth < 6; depth++) {
      parts.unshift(segment(node));
      var path = parts.join(' > ');
      if (count(path) === 1) return path;
      node = node.parentElement;
    }
    return parts.join(' > ');
  }

  function targetOf(node) {
    if (!node || !node.closest) return null;
    return node.closest('a,button,input[type="submit"],input[type="button"],[role="button"],label,[data-track]') || node;
  }

  function place(box, el, color) {
    var rect = el.getBoundingClientRect();
    box.style.left = rect.left + 'px';
    box.style.top = rect.top + 'px';
    box.style.width = rect.width + 'px';
    box.style.height = rect.height + 'px';
    box.style.borderColor = color;
    box.style.display = 'block';
  }

  function overlay(color) {
    var box = make('div', 'position:fixed;z-index:2147483646;pointer-events:none;border:2px solid ' + color + ';background:rgba(26,115,232,.12);border-radius:4px;display:none');
    document.body.appendChild(box);
    return box;
  }

  function clearMatches() {
    matchBoxes.forEach(function (box) { box.remove(); });
    matchBoxes = [];
  }

  function showMatches(selector) {
    clearMatches();
    var list;
    try {
      list = document.querySelectorAll(selector);
    } catch (error) {
      return;
    }
    Array.prototype.slice.call(list, 0, 60).forEach(function (el) {
      var box = overlay(GREEN);
      box.style.background = 'rgba(46,159,91,.15)';
      place(box, el, GREEN);
      matchBoxes.push(box);
    });
  }

  function setBar(message, selector, buttons) {
    bar.textContent = '';
    bar.appendChild(make('div', 'font-weight:600;margin-bottom:4px', message));
    if (selector) bar.appendChild(make('div', 'font:12px ui-monospace,Consolas,monospace;word-break:break-all;opacity:.9;margin-bottom:8px', selector));
    var row = make('div', 'display:flex;gap:8px;flex-wrap:wrap');
    buttons.forEach(function (item) {
      var button = make('button', 'border:0;border-radius:8px;padding:8px 14px;font:inherit;cursor:pointer;background:' + (item.primary ? '#fff' : 'rgba(255,255,255,.18)') + ';color:' + (item.primary ? '#111' : '#fff'), item.label);
      button.type = 'button';
      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        item.run();
      });
      row.appendChild(button);
    });
    if (buttons.length) bar.appendChild(row);
  }

  function intro() {
    locked = false;
    paused = false;
    chosen = null;
    clearMatches();
    setBar('Chế độ chọn phần tử: rê chuột vào nút cần theo dõi rồi bấm vào nút đó.', '', [
      { label: 'Tạm dừng để mở popup', primary: false, run: pause }
    ]);
  }

  // Tạm dừng: trang hoạt động bình thường để admin mở popup/menu, rồi "Tiếp tục chọn" để chọn nút nằm trong đó.
  function pause() {
    paused = true;
    locked = false;
    hoverBox.style.display = 'none';
    clearMatches();
    setBar('Đang tạm dừng: bạn thao tác như bình thường (thao tác là thật, ví dụ bấm Hủy nhóm sẽ hủy thật). Mở popup xong, bấm Tiếp tục chọn.', '', [
      { label: 'Tiếp tục chọn', primary: true, run: intro }
    ]);
  }

  function pick(el) {
    var selector = buildSelector(el);
    var total = count(selector);
    chosen = { selector: selector, text: (el.innerText || el.value || '').replace(/\s+/g, ' ').trim().slice(0, 60) };
    locked = true;
    hoverBox.style.display = 'none';
    showMatches(selector);
    var note = total === 1 ? 'Khớp 1 phần tử.' : 'Khớp ' + total + ' phần tử (các nút cùng loại đều được đếm).';
    setBar(note + (chosen.text ? ' Nút: "' + chosen.text + '".' : ''), selector, [
      { label: 'Dùng phần tử này', primary: true, run: function () { send(false); } },
      { label: 'Dùng và chọn thêm', primary: false, run: function () { send(true); } },
      { label: 'Chọn lại', primary: false, run: intro },
      { label: 'Tạm dừng để mở popup', primary: false, run: pause }
    ]);
  }

  function send(keepOpen) {
    if (!chosen) return;
    var ok = false;
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ type: 'htk-pick', selector: chosen.selector, text: chosen.text }, window.location.origin);
        ok = true;
      }
    } catch (error) { /* tab admin đã đóng */ }
    clearMatches();
    if (ok && keepOpen === true) {
      intro();
      setBar('Đã thêm vào thao tác. Rê chuột và bấm vào phần tử tiếp theo, hoặc quay lại tab admin để lưu.', '', []);
    } else if (ok) {
      setBar('Đã gửi về trang admin. Bạn có thể đóng tab này.', chosen.selector, []);
      window.setTimeout(function () { try { window.close(); } catch (error) { /* trình duyệt chặn */ } }, 600);
    } else {
      setBar('Không tìm thấy tab admin đã mở trang này. Hãy mở lại từ trang cấu hình.', chosen.selector, []);
    }
  }

  function start() {
    bar = make('div', 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:2147483647;max-width:min(560px,calc(100% - 24px));background:#111;color:#fff;padding:12px 16px;border-radius:12px;font:14px/1.4 system-ui,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.35)');
    document.body.appendChild(bar);
    hoverBox = overlay(BLUE);
    intro();

    document.addEventListener('mousemove', function (event) {
      if (locked || paused) return;
      var el = event.target;
      if (isOwn(el)) {
        hoverBox.style.display = 'none';
        return;
      }
      el = targetOf(el);
      if (el) place(hoverBox, el, BLUE);
    }, true);

    document.addEventListener('click', function (event) {
      if (isOwn(event.target) || paused) return;
      event.preventDefault();
      event.stopPropagation();
      if (locked) return;
      var el = targetOf(event.target);
      if (el) pick(el);
    }, true);

    document.addEventListener('submit', function (event) {
      if (paused) return;
      event.preventDefault();
      event.stopPropagation();
    }, true);
  }

  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();
