'use strict';
(() => {
  const root = document.querySelector('.kyuc-game');
  if (!root) return;
  const language = ['vi', 'en', 'zh'].includes(root.dataset.lang) ? root.dataset.lang : 'vi';
  const words = {
    vi: {
      loading: 'Đang mở ký ức…', ready: 'Lật hai thẻ để tìm một cặp hình giống nhau.',
      first: 'Tìm người bạn của thẻ vừa mở nhé!', match: 'Một cặp ký ức đã tìm thấy nhau!',
      mismatch: 'Chưa giống nhau. Ghi nhớ hai hình rồi thử lại nhé.',
      complete: 'Đủ 6 cặp rồi! Đang lưu phần thưởng của bạn…', saved: 'Hoàn thành! Phần thưởng đã được lưu vào ví quà của bạn.',
      no_chances: 'Bạn chưa có lượt chơi. Chọn ưu đãi Mua 10 nhận lượt rút thưởng và hoàn tất đơn đủ 10 ly để mở trò chơi.',
      disabled: 'Chương trình trò chơi đang tạm dừng.', unavailable: 'Phần thưởng đang được chuẩn bị. Lượt chơi của bạn được giữ lại.', rate_limit: 'Bạn lật thẻ quá nhanh. Chờ khoảng một phút, rồi tải lại tiến độ để tiếp tục.', error: 'Chưa thể kết nối. Tải lại tiến độ để tiếp tục.',
      incomplete: 'Hãy ghép đủ 6 cặp trước khi nhận phần thưởng.', changed: 'Lượt chơi đã thay đổi. Tải lại tiến độ để tiếp tục.',
      sync: 'Tiến độ đã được đồng bộ. Bạn có thể tiếp tục.', retry: 'Tải lại tiến độ', saving: 'Đang lưu phần thưởng…',
      copy: 'Sao chép mã quà', copied: 'Đã sao chép mã quà.', card: 'Thẻ úp số ', found: 'Đã ghép: ',
      faces: ['Máy bay giấy', 'Con quay', 'Viên bi', 'Kẹo tuổi thơ', 'Ly trà thân quen', 'Đôi bạn nhỏ']
    },
    zh: {
      loading: '正在開啟童年回憶……', ready: '翻開兩張卡，找出相同的童年圖案。',
      first: '找出剛剛那張卡片的好朋友吧！', match: '一組童年回憶配對成功！',
      mismatch: '圖案不同，記住它們的位置再試一次。', complete: '六組回憶都找到了！正在保存獎勵……',
      saved: '配對完成！獎勵已保存到你的優惠券錢包。',
      no_chances: '目前沒有遊戲機會。選擇「買10抽獎」優惠，完成滿10杯飲品的訂單即可解鎖。',
      disabled: '小遊戲活動目前暫停。', unavailable: '獎項正在準備中，你的遊戲機會會保留。', rate_limit: '翻牌速度太快，請稍候約一分鐘，再重新載入遊戲進度。', error: '暫時無法連線，請重新載入遊戲進度。',
      incomplete: '請先完成全部六組配對，再領取獎勵。', changed: '遊戲機會已變更，請重新載入遊戲進度。',
      sync: '遊戲進度已同步，可以繼續配對。', retry: '重新載入遊戲進度', saving: '正在保存獎勵……',
      copy: '複製獎項序號', copied: '獎項序號已複製。', card: '蓋牌第 ', found: '已配對：',
      faces: ['紙飛機', '陀螺', '彈珠', '童年糖果', '熟悉的茶香', '童年好朋友']
    },
    en: {
      loading: 'Opening your memories…', ready: 'Turn two cards and find the matching pictures.',
      first: 'Find the friend of the card you just turned!', match: 'A pair of memories has found each other!',
      mismatch: 'Different pictures. Remember their positions and try again.', complete: 'All 6 pairs found! Saving your reward…',
      saved: 'Complete! Your reward has been saved to your wallet.',
      no_chances: 'No game chances yet. Choose Buy 10 for a prize draw and complete an order with at least 10 drinks to unlock this game.',
      disabled: 'The game campaign is paused.', unavailable: 'Rewards are being prepared. Your game chance has been kept.', rate_limit: 'Cards were turned too quickly. Wait about a minute, then reload your progress to continue.', error: 'Unable to connect. Reload your progress to continue.',
      incomplete: 'Find all 6 pairs before claiming your reward.', changed: 'Your game has changed. Reload your progress to continue.',
      sync: 'Your progress is synced. You can continue.', retry: 'Reload progress', saving: 'Saving your reward…',
      copy: 'Copy reward code', copied: 'Reward code copied.', card: 'Face-down card ', found: 'Matched: ',
      faces: ['Paper plane', 'Spinning top', 'Marbles', 'Childhood candy', 'A familiar cup of tea', 'Childhood friends']
    }
  };
  const t = words[language];
  // Checkout dialog: prizes apply to the order being placed, not to a later one.
  const inCheckout = root.dataset.checkout === '1';
  const appliedNow = { vi: 'Quà đã được áp dụng trực tiếp vào đơn hàng hiện tại.', zh: '獎品已直接套用於目前這筆訂單。', en: 'This prize has been applied to your current order.' }[language];
  // A 0đ prize offers one replay for sharing the website on Facebook; that replay always wins 52.000đ.
  Object.assign(t, {
    vi: { zero: 'Tiếc quá, lượt này trúng 0đ!', share_offer: 'Rất tiếc bạn đã không may mắn, xin hãy chia sẻ chúng tôi lên Facebook để nhận được một lượt chơi miễn phí với tỉ lệ trúng 100%.', share_button: 'Chia sẻ & nhận lượt chơi', share_bonus: 'Cảm ơn bạn đã chia sẻ! Bạn nhận được 1 lượt chơi miễn phí, lượt này chắc chắn trúng quà.' },
    zh: { zero: '可惜，這次抽到 0đ！', share_offer: '很遺憾這次沒有中獎，請把我們分享到 Facebook，即可獲得 1 次免費遊戲機會，中獎率 100%。', share_button: '分享並領取機會', share_bonus: '感謝分享！您獲得 1 次免費遊戲機會，這次保證中獎。' },
    en: { zero: 'So close — this play won 0đ!', share_offer: 'Sorry, you were not lucky this time. Share us on Facebook to get 1 free play with a 100% chance to win.', share_button: 'Share & get a play', share_bonus: 'Thanks for sharing! You got 1 free play, and it is guaranteed to win a prize.' }
  }[language]);
  if (inCheckout) t.saved = { vi: 'Hoàn thành! Quà đã được áp dụng vào đơn hàng này.', zh: '配對完成！獎品已套用於這筆訂單。', en: 'Complete! Your prize has been applied to this order.' }[language];
  const paths = [
    '<path d="m5 29 38-22-12 38-9-13-17-3Z"/><path d="M22 32 43 7M22 32l-2 10 7-5"/>',
    '<path d="M26 5v8M9 21c0-6 34-6 34 0S9 27 9 21Z"/><path d="M9 21c3 13 17 22 17 26 0-4 14-13 17-26M13 30c9 4 17 4 26 0M19 38h14"/>',
    '<circle cx="19" cy="31" r="12"/><circle cx="35" cy="17" r="12"/><path d="M13 32c7 0 11-3 11-8M31 13c0 5 3 8 8 8M14 23l2-1M30 8l2-1"/>',
    '<path d="m13 22-8-9 13-2 4 7m16 12 8 9-13 2-4-7"/><rect x="15" y="16" width="22" height="22" rx="8" transform="rotate(-32 26 27)"/><path d="m22 20 11 9M17 26l11 9"/>',
    '<path d="m15 19 3 28h21l3-28M12 18h33v-5H12zM31 13l4-10h5l-4 10M18 26h21"/><circle cx="24" cy="35" r="2"/><circle cx="32" cy="40" r="2"/><circle cx="35" cy="32" r="2"/><circle cx="23" cy="43" r="1"/>',
    '<circle cx="17" cy="19" r="8"/><circle cx="37" cy="20" r="7"/><path d="M3 43v-4c0-14 28-14 28 0v4M32 30c13-2 16 6 16 13M10 16c3-4 8-5 14-1M33 16c3-3 5-3 8 0"/>'
  ];
  const grid = root.querySelector('[data-game-grid]');
  const cards = [...root.querySelectorAll('[data-position]')];
  const notice = root.querySelector('[data-game-notice]');
  const retry = root.querySelector('[data-game-retry]');
  const next = root.querySelector('[data-game-next]');
  const rewardElement = root.querySelector('[data-game-reward]');
  const shareBox = root.querySelector('[data-game-share-bonus]');
  // Checkout dialog: the reward is shown in a popup; the standalone page keeps it inline.
  const popup = root.querySelector('[data-game-reward-popup]');
  const reopen = root.querySelector('[data-game-reward-reopen]');
  const outsideNext = root.querySelector('[data-game-next-outside]');
  const outsideShare = root.querySelector('[data-game-share-outside]');
  const popupText = {
    vi: { win: 'Chúc mừng bạn!', lose: 'Tiếc quá!', luck: 'Chúc bạn may mắn lần sau!', close: 'Đóng', view: 'Xem phần thưởng', next: 'Chơi tiếp' },
    zh: { win: '恭喜你！', lose: '可惜！', luck: '祝你下次好運！', close: '關閉', view: '查看獎品', next: '繼續遊戲' },
    en: { win: 'Congratulations!', lose: 'So close!', luck: 'Better luck next time!', close: 'Close', view: 'View prize', next: 'Play next' }
  }[language];
  // The popup opens once per prize; after "Đóng" the reopen button brings it back.
  let popupShownFor = '';
  const popupHost = popup?.parentElement?.closest('dialog') || null;
  let board = null, reward = null, busy = false, blocked = true, balance = 0, shareBonus = false;
  let appearance = { faces: [] }, appearanceKey = '';
  let elapsedAt = Date.now(), waitUntil = 0, retryAt = 0, hideTimer = null, finishTimer = null, freshTimer = null, rateTimer = null;
  let freshMatches = [], errorNotice = false;
  const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const formatTime = seconds => `${Math.floor(seconds / 60).toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;

  function message(text, error = false) {
    if (!notice) return;
    notice.textContent = text;
    // The "0đ" result notice is not shown at all (the reward popup already tells the customer).
    notice.hidden = text === t.zero;
    errorNotice = error;
    notice.classList.toggle('kyuc-error', error);
  }

  function imageUrl(value) {
    if (!value || typeof value !== 'string') return '';
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && !url.search && !url.hash && /\/uploads\/minigame\/[a-f0-9]{32}\.(jpg|png|webp)$/.test(url.pathname) ? url.href : '';
    } catch { return ''; }
  }

  function faceLabel(face) {
    return appearance.faces?.[face] ? ({ zh: '圖案 ', vi: 'Hình ', en: 'Picture ' }[language]) + (face + 1) : t.faces[face];
  }

  function faceIcon(face) {
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    for (const [key, value] of Object.entries({ viewBox: '0 0 52 52', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) icon.setAttribute(key, value);
    icon.innerHTML = paths[face];
    return icon;
  }

  function showSettings(response) {
    const settings = response.appearance || {};
    const key = JSON.stringify(settings);
    if (key !== appearanceKey) {
      appearanceKey = key;
      appearance = { faces: Array.from({ length: 6 }, (_, i) => imageUrl(settings.faces?.[i])) };
      root.dataset.theme = ['memory', 'garden', 'night'].includes(settings.theme) ? settings.theme : 'memory';
      const backUrl = imageUrl(settings.back_image);
      cards.forEach(card => {
        const back = card.querySelector('.kyuc-card-back');
        const img = card.querySelector('[data-game-back-image]');
        back?.classList.toggle('kyuc-custom-back', !!backUrl);
        if (!img) return;
        img.hidden = !backUrl;
        if (backUrl) {
          img.onerror = () => { img.hidden = true; back?.classList.remove('kyuc-custom-back'); };
          img.src = backUrl;
        } else img.removeAttribute('src');
      });
    }
    const prizes = Array.isArray(response.prizes) ? response.prizes : [];
    const name = prize => prize.name?.[language] || prize.name?.vi || '';
    const odds = root.querySelector('[data-game-odds]');
    if (odds) {
      odds.hidden = prizes.length === 0;
      // Same layout as hgr_odds_html(): money prizes on one line (largest highlighted, 0đ hidden), each gift on its own line.
      odds.replaceChildren();
      const label = (prize, into) => name(prize).split(/(\d[\d.,]*\s?(?:đ|₫))/u).forEach((part, j) => {
        if (!part) return;
        if (j % 2) { const b = document.createElement('b'); b.className = 'kyuc-money'; b.textContent = part; into.append(b); }
        else into.append(part);
      });
      const line = (cls) => { const span = document.createElement('span'); span.className = 'kyuc-odds-line ' + cls; odds.append(span); return span; };
      const amount = prize => Number(prize.amount) || 0;
      const money = prizes.filter(prize => prize.kind === 'discount' && amount(prize) > 0);
      const gifts = prizes.filter(prize => prize.kind !== 'discount');
      const top = Math.max(0, ...money.map(amount));
      if (money.length) {
        // One heading, then the amounts only (no "Giảm" before each one).
        const row = line('kyuc-odds-money');
        if (odds.dataset.moneyLabel) row.append(odds.dataset.moneyLabel + ' ');
        money.forEach((prize, i) => {
          if (i) row.append(' · ');
          const b = document.createElement('b');
          b.className = 'kyuc-money';
          b.textContent = Math.round(amount(prize)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, language === 'vi' ? '.' : ',') + 'đ';
          if (amount(prize) === top) { const hi = document.createElement('span'); hi.className = 'kyuc-odds-top'; hi.append(b); row.append(hi); }
          else row.append(b);
        });
      }
      gifts.forEach(prize => label(prize, line('kyuc-odds-gift')));
      odds.hidden = !money.length && !gifts.length;
    }
    // 0đ prizes win nothing, so the prize list hides them.
    const listed = prizes.filter(prize => !(prize.kind === 'discount' && !(Number(prize.amount) > 0)));
    const details = root.querySelector('[data-game-prize-details]');
    if (details) details.hidden = listed.length === 0;
    const list = root.querySelector('[data-game-prizes]');
    if (list) {
      list.replaceChildren();
      listed.forEach(prize => {
        const li = document.createElement('li'), title = document.createElement('strong'), terms = document.createElement('span');
        title.textContent = name(prize);
        terms.textContent = (prize.terms?.[language] || prize.terms?.vi || '') + ' ' + ({ zh: `有效 ${prize.valid_days} 天`, vi: `Có hiệu lực ${prize.valid_days} ngày`, en: `Valid for ${prize.valid_days} days` }[language]);
        li.append(title, terms); list.append(li);
      });
    }
  }

  function render() {
    const waiting = Date.now() < waitUntil;
    root.classList.toggle('kyuc-is-busy', busy);
    if (grid) grid.setAttribute('aria-busy', String(busy));
    cards.forEach(card => {
      const position = Number(card.dataset.position);
      const matched = !!board?.matched.includes(position);
      const fresh = freshMatches.includes(position);
      const open = !!board?.open.includes(position) || fresh;
      const face = board?.faces?.[position];
      const front = card.querySelector('.kyuc-card-face');
      if (front) {
        front.replaceChildren();
        if (Number.isInteger(face) && face >= 0 && face < paths.length && (open || matched)) {
          let icon = faceIcon(face);
          const custom = appearance.faces?.[face];
          if (custom) {
            icon = document.createElement('img'); icon.className = 'kyuc-face-image'; icon.alt = ''; icon.src = custom;
            icon.onerror = () => { if (icon.parentNode === front) icon.replaceWith(faceIcon(face)); };
          }
          const label = document.createElement('strong');
          label.textContent = faceLabel(face);
          front.append(icon, label);
        }
      }
      card.classList.toggle('kyuc-flipped', open);
      card.classList.toggle('kyuc-matched', matched && !fresh);
      card.classList.toggle('kyuc-found', fresh);
      card.disabled = blocked || busy || waiting || !board || board.complete || open || matched;
      card.setAttribute('aria-pressed', String(open));
      const known = Number.isInteger(face) && face >= 0 && face < paths.length;
      card.setAttribute('aria-label', matched && known ? t.found + faceLabel(face) : open && known ? faceLabel(face) : t.card + (position + 1));
      if (matched && !fresh) {
        card.setAttribute('aria-hidden', 'true');
        card.tabIndex = -1;
      } else {
        card.removeAttribute('aria-hidden');
        card.removeAttribute('tabindex');
      }
    });
    root.querySelectorAll('[data-game-balance]').forEach(el => { el.textContent = String(balance); });
    const pairs = root.querySelector('[data-game-pairs]');
    const moves = root.querySelector('[data-game-moves]');
    const progress = root.querySelector('[data-game-progress]');
    if (pairs) pairs.textContent = String(board?.pairs || 0);
    if (moves) moves.textContent = String(board?.moves || 0);
    if (progress) progress.value = board?.pairs || 0;
    updateClock();
    if (retry) retry.disabled = busy || Date.now() < retryAt;
    if (next) { next.hidden = !reward || balance < 1; next.disabled = busy; }
    if (rewardElement) rewardElement.hidden = !reward;
    // A 0đ prize offers a free replay for sharing on Facebook, even while chances remain.
    if (shareBox) { shareBox.hidden = !reward || !shareBonus; shareBox.querySelector('button').disabled = busy; }
    syncPopup();
    root.querySelector('[data-game-cleared]')?.toggleAttribute('hidden', !board?.complete || freshMatches.length > 0);
  }

  function rewardKey() {
    return reward ? String(reward.coupon_code || '') + '|' + String(board?.id || '') : '';
  }

  function syncPopup() {
    if (!popup) {
      return;
    }
    const key = rewardKey();
    // A modal popup inside the closed minigame dialog is invisible but still blocks the whole page.
    const hostOpen = !popupHost || popupHost.open;
    if (!reward || !hostOpen) {
      if (!reward) popupShownFor = '';
      if (popup.open) popup.close();
    } else if (key !== popupShownFor && !popup.open && typeof popup.showModal === 'function') {
      popupShownFor = key;
      popup.showModal();
      popup.querySelector('[data-game-next]:not([hidden]), .kyuc-fb-btn, [data-game-reward-close]')?.focus({ preventScroll: true });
    }
    if (reopen) reopen.hidden = !reward || popup.open;
    // Same actions outside the popup, so they stay reachable after "Đóng".
    if (outsideNext) {
      outsideNext.hidden = !reward || balance < 1;
      outsideNext.disabled = busy;
      outsideNext.textContent = '▶ ' + popupText.next + ' (' + ({ vi: 'còn ', zh: '剩 ', en: '' }[language] || '') + balance + ({ vi: ' lượt', zh: ' 次', en: ' left' }[language] || '') + ')';
    }
    if (outsideShare) {
      outsideShare.hidden = !reward || !shareBonus;
      outsideShare.disabled = busy;
    }
  }

  function updateClock() {
    const element = root.querySelector('[data-game-time]');
    if (!element) return;
    const additional = board?.started && !board.complete ? Math.floor((Date.now() - elapsedAt) / 1000) : 0;
    element.textContent = formatTime((board?.elapsed_seconds || 0) + Math.max(0, additional));
  }

  // Gift prizes are named "Tặng 1 ly mỗi món: A + B"; the popup lists each gifted item on its own row with its quantity.
  function parseGift(text) {
    const match = /^Tặng\s+(\d+)\s+(?:ly|topping)(?:\s+mỗi món)?:\s*(.+)$/u.exec(text);
    if (!match) return null;
    return { qty: match[1], items: match[2].split(' + ').map(item => item.trim()).filter(Boolean) };
  }

  function renderGift(target, gift) {
    const intro = document.createElement('span');
    intro.className = 'kyuc-gift-intro';
    intro.textContent = 'Nhận được';
    const list = document.createElement('span');
    list.className = 'kyuc-gift-list';
    gift.items.forEach(item => {
      const row = document.createElement('span');
      row.className = 'kyuc-gift-item';
      const label = document.createElement('span');
      label.className = 'kyuc-gift-name';
      label.textContent = item;
      const qty = document.createElement('b');
      qty.className = 'kyuc-gift-qty';
      qty.textContent = '×' + gift.qty;
      row.append(label, qty);
      list.append(row);
    });
    target.replaceChildren(intro, list);
  }

  function showReward() {
    if (!reward) return;
    const code = root.querySelector('[data-game-reward-code]');
    const amount = root.querySelector('[data-game-reward-amount]');
    const copy = root.querySelector('[data-game-reward-copy]');
    const name = root.querySelector('[data-game-reward-name]');
    const terms = root.querySelector('[data-game-reward-terms]');
    const expiry = root.querySelector('[data-game-reward-expiry]');
    // A 0đ prize wins nothing: show a better-luck message instead of "Giảm 0đ".
    const zero = reward.kind === 'discount' && !(Number(reward.amount) > 0);
    rewardElement?.classList.toggle('is-zero', zero);
    const head = root.querySelector('[data-game-reward-head]');
    if (head && popup) head.textContent = zero ? popupText.lose : popupText.win;
    if (name) name.textContent = zero
      ? (popup ? popupText.luck : { vi: 'Rất tiếc bạn đã không may mắn, xin hãy chia sẻ chúng tôi lên Facebook để nhận được một lượt chơi miễn phí với tỉ lệ trúng 100%.', zh: '很遺憾這次沒有中獎，請把我們分享到 Facebook，即可獲得 1 次免費遊戲機會，中獎率 100%。', en: 'Sorry, you were not lucky this time. Share us on Facebook to get 1 free play with a 100% chance to win.' }[language])
      : reward.name?.[language] || reward.name?.vi || '';
    const gift = !zero && popup && language === 'vi' ? parseGift(reward.name?.vi || '') : null;
    if (name && gift) renderGift(name, gift);
    if (terms) { terms.textContent = zero ? '' : inCheckout ? appliedNow : reward.terms?.[language] || reward.terms?.vi || ''; terms.hidden = zero; }
    if (expiry) {
      const date = new Date(reward.expires_at || '');
      expiry.hidden = inCheckout || Number.isNaN(date.getTime());
      if (!expiry.hidden) expiry.textContent = ({ vi: 'Hạn dùng: ', zh: '有效至：', en: 'Valid until: ' }[language]) + new Intl.DateTimeFormat({ vi: 'vi-VN', zh: 'zh-TW', en: 'en-GB' }[language], {
        timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'
      }).format(date) + ' (UTC+7)';
    }
    // At checkout the prize is applied straight to the order, so its code and amount are not shown.
    if (code) { code.textContent = reward.coupon_code || ''; code.hidden = inCheckout || !reward.coupon_code; }
    if (amount) {
      const locale = { vi: 'vi-VN', zh: 'zh-TW', en: 'en-US' }[language];
      const formatted = new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(Number(reward.coupon_amount) || 0);
      amount.textContent = `${root.dataset.currency || ''}${formatted}`;
      amount.hidden = inCheckout || !reward.coupon_code || reward.kind !== 'discount';
    }
    if (copy) { copy.hidden = !reward.coupon_code; copy.setAttribute('aria-label', t.copy); }
  }

  // Checkout dialog only: one code per play, listed for the whole order session.
  function showSessionRewards(session) {
    const box = root.querySelector('[data-game-session-rewards]');
    if (!box || session === undefined) return;
    const rewards = Array.isArray(session?.rewards) ? session.rewards : [];
    box.hidden = rewards.length === 0;
    const heading = box.querySelector('[data-game-session-heading]');
    if (heading) heading.textContent = ({ vi: 'Mã quà của đơn này', zh: '本訂單的獎品代碼', en: 'Reward codes for this order' }[language])
      + ` (${rewards.length}/${Number(session?.granted) || rewards.length})`;
    const cash = box.querySelector('[data-game-session-cash]');
    if (cash) {
      const total = Number(session?.cash_total) || 0;
      cash.hidden = total <= 0;
      cash.textContent = ({ vi: 'Tổng tiền giảm cho đơn này: ', zh: '本訂單可折抵總額：', en: 'Total discount for this order: ' }[language])
        + (root.dataset.currency || '') + new Intl.NumberFormat({ vi: 'vi-VN', zh: 'zh-TW', en: 'en-US' }[language], { maximumFractionDigits: 2 }).format(total);
    }
    const list = box.querySelector('[data-game-session-list]');
    if (!list) return;
    list.replaceChildren(...rewards.map((item, index) => {
      const li = document.createElement('li');
      const label = document.createElement('span');
      label.textContent = `${({ vi: 'Lượt', zh: '第', en: 'Play' }[language])} ${index + 1}: `;
      const prize = document.createElement('strong');
      prize.className = 'kyuc-session-prize';
      prize.textContent = item.name?.[language] || item.name?.vi || '';
      label.append(prize);
      const code = document.createElement('code');
      code.textContent = item.coupon_code || '';
      li.append(label, code);
      return li;
    }));
  }

  function applyResponse(response, animate = false) {
    showSettings(response);
    showSessionRewards(response.checkout_session);
    // Checkout: keep the unplayed-chance count current and remember that a prize now applies to the order.
    const checkout = document.querySelector('[data-checkout-form]');
    const session = response.checkout_session;
    if (checkout && session) checkout.dataset.gameRemaining = String(Math.max(0, (Number(session.granted) || 0) - (Number(session.played) || 0)));
    // Keep the "you still have X plays" line on the checkout page current after every play or bonus chance.
    const remainingLine = document.querySelector('[data-game-remaining-text]');
    if (remainingLine && session) {
      const left = Math.max(0, (Number(session.granted) || 0) - (Number(session.played) || 0));
      remainingLine.querySelector('strong').textContent = remainingLine.dataset.gameRemainingText.replace('{n}', String(left));
    }
    if (session && typeof window.hcgsSetClock === 'function') window.hcgsSetClock(session.remaining);
    if (response.event === 'awarded') root.dataset.awarded = '1';
    const oldMatched = board?.matched || [];
    board = response.board || null;
    balance = Math.max(0, Number(response.balance) || 0);
    reward = response.reward || null;
    shareBonus = !!response.share_bonus;
    elapsedAt = Date.now();
    waitUntil = Date.now() + Math.max(0, Number(board?.wait_ms) || 0);
    blocked = !board || (!reward && balance < 1);
    retryAt = 0;
    clearTimeout(rateTimer);
    clearTimeout(hideTimer);
    clearTimeout(freshTimer);
    freshMatches = animate && board ? board.matched.filter(position => !oldMatched.includes(position)) : [];
    if (retry) retry.hidden = true;
    if (reward) { showReward(); message(shareBonus ? t.zero : t.saved); }
    else if (board?.complete) message(t.complete);
    else if (!board) message(balance > 0 ? t.loading : t.no_chances);
    else message(t[response.event] || (board.open.length === 1 ? t.first : board.wait_ms > 0 ? t.mismatch : t.ready));
    render();
    if (freshMatches.length) {
      freshTimer = window.setTimeout(() => {
        const focused = document.activeElement;
        const focusWasMatched = cards.includes(focused) && board?.matched.includes(Number(focused.dataset.position));
        freshMatches = [];
        render();
        if (focusWasMatched && !board?.complete) cards.find(card => !card.disabled)?.focus({ preventScroll: true });
      }, reducedMotion() ? 0 : 600);
    }
    if (board && board.wait_ms > 0 && !reward) {
      hideTimer = window.setTimeout(() => {
        if (!busy) run('state', false);
      }, board.wait_ms + 80);
    }
  }

  async function request(action, extra = {}) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    const body = new URLSearchParams({ csrf: root.dataset.csrf || '', action, ...extra });
    try {
      const response = await fetch(root.dataset.api, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', body,
        headers: { 'Accept': 'application/json' }, signal: controller.signal
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        const error = new Error(result.error || 'connection');
        error.code = result.error || 'connection';
        throw error;
      }
      return result;
    } finally { clearTimeout(timeout); }
  }

  function errorText(code) {
    return ({ no_chances: t.no_chances, game_disabled: t.disabled, disabled: t.disabled,
      campaign_disabled: t.disabled, rewards_unavailable: t.unavailable, rate_limit: t.rate_limit,
      matching_incomplete: t.incomplete, board_changed: t.changed })[code] || t.error;
  }

  async function run(action, autoStart = true, extra = {}) {
    if (busy || Date.now() < retryAt) return;
    busy = true;
    blocked = true;
    message(action === 'finish' ? t.saving : action === 'flip' ? notice?.textContent || t.ready : t.loading);
    render();
    clearTimeout(finishTimer);
    let synced = false;
    try {
      const response = await request(action, extra);
      applyResponse(response, action === 'flip');
      if (autoStart && !board && !reward && balance > 0) applyResponse(await request('start'));
    } catch (error) {
      // A lost response may already have changed the server board. Never repeat a flip.
      const unavailable = ['no_chances', 'game_disabled', 'disabled', 'campaign_disabled', 'rewards_unavailable'].includes(error.code);
      const limited = error.code === 'rate_limit';
      if (limited) {
        clearTimeout(hideTimer);
        retryAt = Date.now() + 60000;
        rateTimer = window.setTimeout(render, 60010);
      }
      if (!unavailable && !limited) {
        try {
          applyResponse(await request('state'));
          synced = true;
        } catch (_) { /* Keep the retry control visible when progress cannot be fetched. */ }
      }
      if (!synced) {
        blocked = true;
        message(errorText(error.code), true);
        if (retry) retry.hidden = unavailable;
      } else if (action === 'finish' && !reward) {
        blocked = true;
        message(t.error, true);
        if (retry) retry.hidden = false;
      } else if (!reward && board) message(t.sync);
      else if (!reward && balance > 0) { message(t.error, true); if (retry) retry.hidden = false; }
    } finally {
      busy = false;
      render();
    }
    // Completion is also retried safely after reload; the server returns the same reward.
    if (board?.complete && !reward && !errorNotice) {
      finishTimer = window.setTimeout(() => run('finish', false, { board_id: board.id }), freshMatches.length && !reducedMotion() ? 650 : 0);
    }
  }

  cards.forEach(card => card.addEventListener('click', () => {
    if (blocked || busy || !board || board.complete || Date.now() < waitUntil || card.disabled) return;
    run('flip', false, { board_id: board.id, seq: String(board.seq), position: card.dataset.position });
  }));
  retry?.addEventListener('click', () => run('state'));
  // A new play starts from a clean board: the previous prize, completion banner and stats are cleared at once.
  function resetRound() {
    reward = null; board = null; freshMatches = []; shareBonus = false;
    ['name', 'terms', 'code', 'amount', 'expiry'].forEach(key => {
      const element = root.querySelector(`[data-game-reward-${key}]`);
      if (element) element.textContent = '';
    });
    clearTimeout(hideTimer); clearTimeout(freshTimer); clearTimeout(finishTimer);
    message(t.loading);
    render();
    (root.querySelector('.kyuc-board') || root).scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
  }
  next?.addEventListener('click', () => {
    if (busy || !reward || balance < 1 || !board) return;
    const id = board.id;
    resetRound();
    run('next', false, { board_id: id });
  });
  if (shareBox) {
    shareBox.querySelector('p').textContent = t.share_offer;
    const shareLabel = shareBox.querySelector('button span') || shareBox.querySelector('button');
    shareLabel.textContent = t.share_button;
    shareBox.querySelector('button').addEventListener('click', () => {
      if (busy || !reward || !shareBonus || !board) return;
      // Open the Facebook share inside this click so the pop-up is allowed, then claim the 52.000đ replay.
      document.querySelector('[data-fb-share-open]')?.click();
      const id = board.id;
      resetRound();
      run('share_bonus', false, { board_id: id });
    });
  }
  if (popup) {
    root.querySelectorAll('[data-game-reward-close]').forEach(button => {
      if (button.classList.contains('kyuc-reward-done')) button.textContent = popupText.close;
      button.addEventListener('click', () => {
        popup.close();
        // Out of plays at checkout: close the game too, so the customer lands back on the checkout page.
        if (inCheckout && balance < 1) {
          if (typeof window.closeMinigameModal === 'function') window.closeMinigameModal();
          else if (root.dataset.exitUrl) location.href = root.dataset.exitUrl;
        }
      });
    });
    // Forward to the popup buttons so the claim logic (and the Facebook pop-up permission) stays in one place.
    outsideNext?.addEventListener('click', () => next?.click());
    if (outsideShare) {
      outsideShare.querySelector('span').textContent = t.share_button;
      outsideShare.addEventListener('click', () => shareBox?.querySelector('button')?.click());
    }
    if (reopen) {
      reopen.textContent = '🎁 ' + popupText.view;
      reopen.addEventListener('click', () => {
        if (reward && !popup.open) popup.showModal();
        syncPopup();
      });
    }
    // Clicks inside the popup must not reach the outer minigame dialog, which closes on outside clicks.
    popup.addEventListener('click', event => {
      event.stopPropagation();
      if (event.target === popup) popup.close();
    });
    popup.addEventListener('close', () => {
      if (reopen) reopen.hidden = !reward;
    });
    // Show the pending prize once the minigame dialog is opened; close the popup with it.
    if (popupHost) {
      new MutationObserver(syncPopup).observe(popupHost, { attributes: true, attributeFilter: ['open'] });
    }
  }
  root.querySelector('[data-game-reward-copy]')?.addEventListener('click', async () => {
    if (!reward?.coupon_code) return;
    try { await navigator.clipboard.writeText(reward.coupon_code); message(t.copied); }
    catch (_) { root.querySelector('[data-game-reward-code]')?.focus(); }
  });
  if (notice) { notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite'); }
  if (retry) retry.textContent = t.retry;
  window.setInterval(updateClock, 1000);
  window.addEventListener('pageshow', event => { if (event.persisted && !busy) run('state'); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !busy) run('state'); });
  render();
  run('state');
})();
