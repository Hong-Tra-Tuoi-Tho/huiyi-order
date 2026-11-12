<?php
/**
 * Combo ngoài trang chủ (index.php): slider "Khám phá combo mới nhất".
 * Dữ liệu lấy từ bảng combos / combo_groups do admin/combos.php quản lý.
 */

/** Các combo đang bán, kèm số nhóm món. Lỗi DB (chưa có bảng combo) => trả mảng rỗng để trang chủ vẫn chạy. */
function hcf_active(): array
{
    try {
        $rows = db()->query(
            'SELECT c.id, c.name_vi, c.name_en, c.name_zh, c.image, c.price, c.total_cups, c.all_products,
                    COUNT(g.id) AS group_count
             FROM combos c
             LEFT JOIN combo_groups g ON g.combo_id = c.id
             WHERE c.active = 1
             GROUP BY c.id
             ORDER BY c.sort_order, c.id DESC'
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('home combos: ' . $e->getMessage());

        return [];
    }

    return $rows;
}

/** Giá hiển thị trên thẻ, ví dụ "Chỉ từ 999đ". */
function hcf_price(array $combo): string
{
    return hcf_t('from_price', ['price' => money($combo['price']) . 'đ']);
}

/** Dòng phụ dưới tên combo, ví dụ "Tối đa 5 ly · 2 nhóm món". */
function hcf_meta(array $combo): string
{
    $parts = [];

    $cups = (int)$combo['total_cups'];
    if ($cups > 0) {
        $parts[] = hcf_t('max_cups', ['count' => $cups]);
    }

    $groups = (int)$combo['group_count'];
    if (!empty($combo['all_products'])) {
        $parts[] = hcf_t('all_products');
    } elseif ($groups > 0) {
        $parts[] = hcf_t('groups', ['count' => $groups]);
    }

    return implode(' · ', $parts);
}

/**
 * Slider combo. $room = phòng nhóm combo mà chủ nhóm đang chọn combo:
 * khi đó "Đặt ngay" là form thêm combo vào giỏ nhóm (group/action.php, action add_combo).
 * Không có $room (trang chủ bình thường): "Đặt ngay" thêm combo vào giỏ thường rồi vào giỏ hàng (actions/add_combo_to_cart.php).
 */
function hcf_render_section(array $combos, ?array $room = null): string
{
    if (!$combos) {
        return '';
    }

    ob_start();
    ?>
<<<<<<< HEAD
  <link rel="stylesheet" href="<?= e(base_url('assets/css/home-combos.css?v=8')) ?>">
=======
  <link rel="stylesheet" href="<?= e(base_url('assets/css/home-combos.css?v=5')) ?>">
>>>>>>> origin/main
  <section class="hcf-section" id="combos" data-home-combos data-dot-label="<?= e(hcf_t('go_to')) ?>"
    aria-roledescription="carousel" aria-label="<?= e(hcf_t('title')) ?>">
    <div class="section-title-row hcf-heading">
      <h2><?= e(hcf_t('title')) ?></h2>
    </div>
    <div class="hcf-viewport">
      <div class="hcf-track" data-combo-track>
        <?php foreach ($combos as $combo): ?>
          <?php
          $hasImage = !empty($combo['image']);
          $meta = hcf_meta($combo);
          ?>
          <article class="hcf-card<?= $hasImage ? '' : ' no-image' ?>" data-combo-card data-combo-id="<?= (int)$combo['id'] ?>">
            <?php if ($hasImage): ?>
              <img class="hcf-card-image" src="<?= e(base_url('uploads/' . $combo['image'])) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <div class="hcf-card-top">
              <h3 class="hcf-card-name"><?= e(localized_name($combo)) ?></h3>
              <strong class="hcf-card-price"><?= e(hcf_price($combo)) ?></strong>
            </div>
            <div class="hcf-card-bottom">
              <p class="hcf-card-meta"><?= e($meta) ?></p>
              <?php if ($room): ?>
                <form method="post" action="<?= e(base_url('group/action.php')) ?>" class="hcf-order-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="room_id" value="<?= e($room['public_id']) ?>">
                  <input type="hidden" name="action" value="add_combo">
                  <input type="hidden" name="combo_id" value="<?= (int)$combo['id'] ?>">
                  <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
                  <button type="submit" class="hcf-order"><?= e(hcf_t('order_now')) ?></button>
                </form>
              <?php else: ?>
                <?php /* Trang chủ: "Đặt ngay" mở trang tạo nhóm, combo này được đưa vào nhóm mới ngay khi tạo. */ ?>
                <form method="get" action="<?= e(base_url('group/index.php')) ?>" class="hcf-order-form">
                  <input type="hidden" name="mode" value="combo">
                  <input type="hidden" name="combo" value="<?= (int)$combo['id'] ?>">
                  <button type="submit" class="hcf-order"><?= e(hcf_t('order_now')) ?></button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <button class="hcf-arrow is-prev" type="button" data-combo-prev aria-label="<?= e(hcf_t('prev')) ?>">‹</button>
      <button class="hcf-arrow is-next" type="button" data-combo-next aria-label="<?= e(hcf_t('next')) ?>">›</button>
    </div>
    <div class="hcf-dots" data-combo-dots></div>
  </section>
<<<<<<< HEAD
  <script src="<?= e(base_url('assets/js/home-combos.js?v=7')) ?>" defer></script>
=======
  <script src="<?= e(base_url('assets/js/home-combos.js?v=5')) ?>" defer></script>
>>>>>>> origin/main
    <?php

    return (string)ob_get_clean();
}

function hcf_t(string $key, array $vars = []): string
{
    static $map = [
        'vi' => [
            'title' => 'Khám phá combo mới nhất',
            'eyebrow' => 'Combo',
            'order_now' => 'Đặt ngay',
            'from_price' => 'Chỉ từ {price}',
            'max_cups' => 'Tối đa {count} ly',
            'groups' => '{count} nhóm món',
            'all_products' => 'Chọn mọi món',
            'prev' => 'Combo trước',
            'next' => 'Combo tiếp theo',
            'go_to' => 'Xem combo {n}',
        ],
        'en' => [
            'title' => 'Discover our latest combos',
            'eyebrow' => 'Combo',
            'order_now' => 'Order now',
            'from_price' => 'From {price}',
            'max_cups' => 'Up to {count} cups',
            'groups' => '{count} drink groups',
            'all_products' => 'Any drink',
            'prev' => 'Previous combo',
            'next' => 'Next combo',
            'go_to' => 'Go to combo {n}',
        ],
        'zh' => [
            'title' => '探索最新套餐',
            'eyebrow' => '套餐',
            'order_now' => '立即訂購',
            'from_price' => '僅需 {price}',
            'max_cups' => '最多 {count} 杯',
            'groups' => '{count} 個飲品組',
            'all_products' => '全部飲品可選',
            'prev' => '上一個套餐',
            'next' => '下一個套餐',
            'go_to' => '查看套餐 {n}',
        ],
    ];

    $lang = current_lang();
    $text = $map[$lang][$key] ?? $map['vi'][$key] ?? $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }

    return $text;
}
