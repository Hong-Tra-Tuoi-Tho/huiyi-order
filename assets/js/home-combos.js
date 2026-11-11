/* Slider combo trang chủ (index.php, assets/css/home-combos.css).
   Vuốt / cuộn do CSS scroll-snap lo; file này dựng chấm trang, nút ‹ › và tự chạy mỗi 4 giây. */
(function () {
    'use strict';

    function setup(root) {
        var track = root.querySelector('[data-combo-track]');
        var dotsBox = root.querySelector('[data-combo-dots]');
        var prev = root.querySelector('[data-combo-prev]');
        var next = root.querySelector('[data-combo-next]');
        var cards = track ? track.querySelectorAll('[data-combo-card]') : [];

        if (!track || !cards.length) {
            return;
        }

        var dotLabel = root.getAttribute('data-dot-label') || '{n}';
        var pageCount = 0;
        var current = -1;

        // Khoảng cách giữa 2 thẻ liền nhau = bề rộng thẻ + gap.
        function step() {
            if (cards.length > 1) {
                return cards[1].offsetLeft - cards[0].offsetLeft;
            }

            return cards[0].offsetWidth;
        }

        // Số thẻ thấy trọn trên màn hình: điện thoại 1, iPad 2, màn lớn 3.
        function perView() {
            var visible = Math.round((track.clientWidth + 1) / step());

            return Math.max(1, Math.min(cards.length, visible));
        }

        function indexNow() {
            var index = Math.round(track.scrollLeft / step());

            return Math.max(0, Math.min(pageCount - 1, index));
        }

        function goTo(index) {
            index = Math.max(0, Math.min(pageCount - 1, index));
            track.scrollTo({ left: index * step() });
        }

        function buildDots() {
            var pages = cards.length - perView() + 1;

            if (pages === pageCount) {
                return;
            }

            pageCount = pages;
            current = -1;
            dotsBox.innerHTML = '';
            dotsBox.hidden = pageCount < 2;

            for (var i = 0; i < pageCount; i++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'hcf-dot';
                dot.setAttribute('aria-label', dotLabel.replace('{n}', String(i + 1)));
                dot.addEventListener('click', goTo.bind(null, i));
                dotsBox.appendChild(dot);
            }
        }

        function sync() {
            var index = indexNow();

            if (index === current) {
                return;
            }

            current = index;

            var dots = dotsBox.children;
            for (var i = 0; i < dots.length; i++) {
                if (i === index) {
                    dots[i].setAttribute('aria-current', 'true');
                } else {
                    dots[i].removeAttribute('aria-current');
                }
            }

            if (prev) {
                prev.disabled = index <= 0;
            }
            if (next) {
                next.disabled = index >= pageCount - 1;
            }
        }

        function refresh() {
            buildDots();
            sync();
        }

        var ticking = false;
        track.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                ticking = false;
                sync();
            });
        }, { passive: true });

        if (prev) {
            prev.addEventListener('click', function () {
                goTo(indexNow() - 1);
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                goTo(indexNow() + 1);
            });
        }

        // Bấm vào ảnh / thẻ combo = bấm "Đặt ngay" (bỏ qua khi bấm vào nút hoặc liên kết khác).
        for (var c = 0; c < cards.length; c++) {
            cards[c].addEventListener('click', function (event) {
                if (event.target.closest('button, a, input')) {
                    return;
                }

                var form = this.querySelector('.hcf-order-form');

                if (form) {
                    form.requestSubmit();
                }
            });
        }

        // Tự chạy: 4 giây sang 1 trang, tới trang cuối thì quay về đầu.
        var AUTO_MS = 4000;
        var timer = null;

        function autoNext() {
            if (pageCount < 2 || document.hidden) {
                return;
            }

            if (indexNow() >= pageCount - 1) {
                goTo(0);
            } else {
                goTo(indexNow() + 1);
            }
        }

        function stopAuto() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function startAuto() {
            stopAuto();
            timer = window.setInterval(autoNext, AUTO_MS);
        }

        // Người dùng đang chạm / rê chuột / focus vào slider thì tạm dừng.
        root.addEventListener('mouseenter', stopAuto);
        root.addEventListener('mouseleave', startAuto);
        root.addEventListener('focusin', stopAuto);
        root.addEventListener('focusout', startAuto);
        root.addEventListener('touchstart', stopAuto, { passive: true });
        root.addEventListener('touchend', startAuto, { passive: true });

        window.addEventListener('resize', refresh);
        refresh();
        startAuto();
    }

    function init() {
        var roots = document.querySelectorAll('[data-home-combos]');

        for (var i = 0; i < roots.length; i++) {
            setup(roots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
