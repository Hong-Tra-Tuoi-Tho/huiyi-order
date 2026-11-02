<?php

// -- Chia sẽ trang web lên fb
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
// Facebook's share dialog only takes a link, so the post text is copied first for the customer to paste.
$shareUrl = 'https://order.huiyitea.vn/';
$shareText = [
    'vi' => [
        'title' => 'Chia sẻ để bạn bè cùng nhận quà',
        'hint' => 'Bấm nút bên dưới: Facebook mở sẵn bài viết kèm link và nội dung ưu đãi. Nếu ô viết bài còn trống, bạn chỉ cần dán (Ctrl+V hoặc giữ để dán) — nội dung đã được sao chép sẵn — rồi bấm Đăng.',
        'button' => 'Chia sẻ lên Facebook',
        'copy' => 'Sao chép nội dung',
        'copied' => '✓ Đã sao chép nội dung — hãy dán vào bài viết Facebook.',
        'post' => "🧋 HỒNG TRÀ TUỔI THƠ – ĐẶT NƯỚC ONLINE SIÊU TIỆN 🧋\n\n🎁 Ưu đãi cực hấp dẫn đang chờ bạn:\n✅ MUA 4 TẶNG 1 – rủ cả hội cùng uống, càng đông càng lời!\n🎮 Mua 10 ly nhận ngay 1 lượt chơi Mini Game – cơ hội trúng giải thưởng lên đến 145K!\n👥 Đặt đơn nhóm cùng bạn bè, đồng nghiệp – mỗi người tự chọn món, trưởng nhóm gửi một lần.\n🛵 Đặt nhanh, giao tận nơi, thơm ngon chuẩn vị tuổi thơ.\n\n👉 Đặt ngay tại: {url}\n\n#HongTraTuoiTho #HuiyiTea #Mua4Tang1 #MiniGame #TraSua",
    ],
    'zh' => [
        'title' => '分享給朋友一起拿好禮',
        'hint' => '點擊下方按鈕：Facebook 會開啟附連結與優惠內容的貼文。若輸入框是空的，只要貼上（Ctrl+V 或長按貼上，內容已複製）後按「發佈」即可。',
        'button' => '分享到 Facebook',
        'copy' => '複製內容',
        'copied' => '✓ 已複製內容，請貼到 Facebook 貼文中。',
        'post' => "🧋 回憶紅茶 – 線上點飲料超方便 🧋\n\n🎁 超值優惠等你來：\n✅ 買 4 送 1 – 揪團一起喝，越多越划算！\n🎮 買 10 杯送 1 次小遊戲機會 – 最高可抽中 145K 好禮！\n👥 團購點單：朋友、同事各自選，團主一次送出。\n🛵 快速下單，外送到府。\n\n👉 立即點單：{url}\n\n#回憶紅茶 #HuiyiTea #買4送1 #小遊戲",
    ],
    'en' => [
        'title' => 'Share so your friends get rewards too',
        'hint' => 'Tap the button below: Facebook opens a post with the link and offer. If the text box is empty, just paste (Ctrl+V or long-press) — the text is already copied — and tap Post.',
        'button' => 'Share on Facebook',
        'copy' => 'Copy text',
        'copied' => '✓ Text copied — paste it into your Facebook post.',
        'post' => "🧋 HONG TRA TUOI THO – ORDER DRINKS ONLINE 🧋\n\n🎁 Great deals waiting for you:\n✅ BUY 4 GET 1 FREE – bring your friends!\n🎮 Buy 10 drinks and get a Mini Game chance – win prizes up to 145K!\n👥 Group orders: everyone picks their own drink, the host submits once.\n🛵 Quick ordering, delivered to your door.\n\n👉 Order now: {url}\n\n#HongTraTuoiTho #HuiyiTea #Buy4Get1 #MiniGame",
    ],
];
$share = $shareText[current_lang()] ?? $shareText['vi'];
$sharePost = str_replace('{url}', $shareUrl, $share['post']);
?>
<section class="kyuc-share" data-fb-share data-url="<?= e($shareUrl) ?>">
    <h4>📣 <?= e($share['title']) ?></h4>
    <p><?= e($share['hint']) ?></p>
    <textarea readonly rows="7" data-fb-share-text><?= e($sharePost) ?></textarea>
    <div class="kyuc-share-actions">
        <button type="button" class="kyuc-share-fb" data-fb-share-open><?= e($share['button']) ?></button>
        <button type="button" class="kyuc-share-copy" data-fb-share-copy><?= e($share['copy']) ?></button>
    </div>
    <p class="kyuc-share-status" data-fb-share-status role="status" aria-live="polite" hidden><?= e($share['copied']) ?></p>
</section>
<style>
    .kyuc-share {
        margin: 16px 0 0;
        padding: 16px;
        border: 1px solid #c9d8f5;
        border-radius: 15px;
        background: #f4f8ff;
        text-align: left
    }

    .kyuc-share h4 {
        margin: 0 0 6px;
        font-size: 15px;
        color: #1b4fb5
    }

    .kyuc-share p {
        margin: 0 0 8px;
        font-size: 12px;
        line-height: 1.6;
        color: #4a5a78
    }

    .kyuc-share textarea {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #c9d8f5;
        border-radius: 10px;
        padding: 10px;
        font: inherit;
        font-size: 12px;
        line-height: 1.6;
        resize: vertical;
        background: #fff
    }

    .kyuc-share-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px
    }

    .kyuc-share-actions button {
        border-radius: 10px;
        min-height: 40px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer
    }

    .kyuc-share-fb {
        background: #1877f2;
        border: 1px solid #1877f2;
        color: #fff
    }

    .kyuc-share-copy {
        background: #fff;
        border: 1px solid #c9d8f5;
        color: #1b4fb5
    }

    .kyuc-share-status {
        color: #1d7a3a !important;
        font-weight: 700
    }
</style>
<script>
    (function() {
        const box = document.currentScript.previousElementSibling.previousElementSibling;
        if (!box || !box.matches('[data-fb-share]')) return;
        const text = box.querySelector('[data-fb-share-text]');
        const status = box.querySelector('[data-fb-share-status]');
        const copy = async function() {
            try {
                await navigator.clipboard.writeText(text.value);
            } catch (e) {
                text.focus();
                text.select();
                try {
                    document.execCommand('copy');
                } catch (ignored) {}
            }
            status.hidden = false;
        };
        box.querySelector('[data-fb-share-copy]').addEventListener('click', copy);
        box.querySelector('[data-fb-share-open]').addEventListener('click', function() {
            // Copy and open inside the click so the browser allows both; a blocked pop-up falls back to this tab.
            copy();
            // Facebook fills the hashtag; the quote is filled where Facebook still allows it, otherwise the copied text is pasted.
            const link = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(box.dataset.url)
                + '&quote=' + encodeURIComponent(text.value) + '&hashtag=' + encodeURIComponent('#HongTraTuoiTho');
            const win = window.open(link, 'fbshare', 'width=640,height=600');
            if (!win) setTimeout(function() {
                location.href = link;
            }, 300);
        });
    })();
</script>