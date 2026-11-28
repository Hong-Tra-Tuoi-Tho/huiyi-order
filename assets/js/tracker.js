/**
 * Tracking log: gom event vào hàng đợi localStorage, gửi lô định kỳ.
 * Server truyền qua data-config các quy tắc áp dụng cho trang này: view (xem trang),
 * clicks (selector của phần tử cần đếm lượt bấm), minigame (đếm lượt lật thẻ đầu tiên).
 * Đơn thành công do server ghi, web không gửi.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var config;
  try {
    config = JSON.parse(script.getAttribute('data-config') || '{}');
  } catch (error) {
    return;
  }
  if (!config.endpoint) return;

  var QUEUE_KEY = 'htkQueue';
  var DEVICE_KEY = 'htkDevice';
  var MAX_QUEUE = 200;
  var BATCH_SIZE = 50;
  var FLUSH_MS = 10000;
  var sending = false;

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    var bytes = new Uint8Array(16);
    if (window.crypto && crypto.getRandomValues) {
      crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function storageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
      return true;
    } catch (error) {
      return false;
    }
  }

  // device_id: sinh một lần, lưu localStorage, chép sang cookie để server ghi order_success đúng thiết bị.
  var device = storageGet(DEVICE_KEY);
  if (!/^[0-9a-f]{32}$/.test(device || '')) {
    device = uuid();
    storageSet(DEVICE_KEY, device);
  }
  document.cookie = 'htk_did=' + device + '; path=/; max-age=31536000; SameSite=Lax';

  function readQueue() {
    try {
      var list = JSON.parse(storageGet(QUEUE_KEY) || '[]');
      return Array.isArray(list) ? list : [];
    } catch (error) {
      return [];
    }
  }

  function writeQueue(list) {
    if (list.length > MAX_QUEUE) list = list.slice(list.length - MAX_QUEUE);
    storageSet(QUEUE_KEY, JSON.stringify(list));
  }

  function enqueue(event) {
    var list = readQueue();
    list.push(event);
    writeQueue(list);
  }

  // Trang thuộc một phòng đơn nhóm: gắn mã phòng để thống kê gom được hành trình của cả nhóm (nhiều thiết bị).
  function withGroup(event) {
    if (/^[a-f0-9]{32}$/.test(config.group || '')) event.group = config.group;
    return event;
  }

  function trackPage() {
    if (!config.page || !config.view) return;
    enqueue(withGroup({ key: uuid(), type: 1, page: config.page, action: null, at: Date.now() }));
  }

  function trackAction(action) {
    if (!config.page) return;
    enqueue(withGroup({ key: uuid(), type: 2, page: config.page, action: action, at: Date.now() }));
  }

  // Gửi một lô; event nào server báo đã xử lý thì xóa khỏi hàng đợi. Thất bại thì giữ nguyên event_key để gửi lại.
  function flush() {
    if (sending) return;
    var list = readQueue();
    if (!list.length) return;
    var batch = list.slice(0, BATCH_SIZE);
    sending = true;
    fetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ device: device, events: batch })
    }).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (result) {
      sending = false;
      if (!result || !Array.isArray(result.accepted)) return;
      var done = {};
      result.accepted.forEach(function (key) { done[key] = true; });
      writeQueue(readQueue().filter(function (event) { return !done[event.key]; }));
    }).catch(function () {
      sending = false;
    });
  }

  // Bấm vào phần tử khớp selector của một quy tắc → một lượt. Một lần bấm chỉ tính một lần cho mỗi quy tắc.
  var clicks = Array.isArray(config.clicks) ? config.clicks : [];
  if (clicks.length) {
    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      var counted = false;
      clicks.forEach(function (rule) {
        // Một quy tắc có thể gồm nhiều phần tử; bấm vào phần tử nào cũng tính 1 lượt.
        (rule.selectors || []).some(function (selector) {
          try {
            if (target.closest(selector)) {
              trackAction(rule.code);
              counted = true;
              return true;
            }
          } catch (error) { /* selector sai thì bỏ qua */ }
          return false;
        });
      });
      if (counted) flush();
    }, true);
  }

  // Mini-game: mỗi lần mở trang chỉ tính một lượt chơi, lúc người chơi lật thẻ đầu tiên.
  var played = false;
  if (window.fetch && config.minigame) {
    var originalFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var body = init && typeof init.body === 'string' ? init.body : '';
        if (!played && /actions\/minigame\.php/.test(url) && /["=:]\s*"?flip\b/.test(body)) {
          played = true;
          trackAction(config.minigame);
          flush();
        }
      } catch (error) { /* không ảnh hưởng game */ }
      return originalFetch.apply(this, arguments);
    };
  }

  trackPage();
  flush();
  setInterval(flush, FLUSH_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);
})();
