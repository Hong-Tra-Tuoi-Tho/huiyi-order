<?php
// test cicd
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/combo_front.php';
try {
  $groupContext = hyg_context();
} catch (Throwable $e) {
  hyg_error_page($e);
}
$cats = db()->query('SELECT c.*, COUNT(p.id) product_count FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.active=1 WHERE c.active=1 GROUP BY c.id HAVING product_count>0 ORDER BY c.sort_order,c.id')->fetchAll();
$allProducts = db()->query("SELECT p.*,COALESCE(s.sold_qty,0) sold_qty FROM products p LEFT JOIN (SELECT oi.product_id,SUM(oi.qty) sold_qty FROM order_items oi WHERE oi.product_id IS NOT NULL GROUP BY oi.product_id) s ON s.product_id=p.id WHERE p.active=1 ORDER BY p.sort_order,p.id")->fetchAll();
$productsByCat = [];
foreach ($allProducts as $p)
  $productsByCat[(int) $p['category_id']][] = $p;
$hotProducts = array_values(array_filter($allProducts, fn($p) => !empty($p['is_hot'])));
$standaloneToppings = db()->query('SELECT * FROM toppings WHERE active=1 AND sell_separately=1 ORDER BY sort_order,id')->fetchAll();
$featuredProducts = db()->query("SELECT p.*,COALESCE(s.sold_qty,0) sold_qty FROM products p LEFT JOIN (SELECT oi.product_id,SUM(oi.qty) sold_qty FROM order_items oi WHERE oi.product_id IS NOT NULL GROUP BY oi.product_id) s ON s.product_id=p.id WHERE p.active=1 AND (p.is_new=1 OR p.is_hot=1) ORDER BY p.is_new DESC,p.is_hot DESC,p.sort_order,p.id LIMIT 12")->fetchAll();
$featuredToppings = db()->query('SELECT * FROM toppings WHERE active=1 AND sell_separately=1 AND (is_new=1 OR is_hot=1) ORDER BY is_new DESC,is_hot DESC,sort_order,id LIMIT 12')->fetchAll();
// Home banner slider (admin/banners.php). Inside a group order, banner links keep the group context.
$banners = hbn_active();
// Combo đang bán (admin/combos.php) cho slider "Khám phá combo mới nhất".
$homeCombos = hcf_active();
if ($groupContext) {
  foreach ($banners as &$slide) {
    if ($slide['url'] !== '') {
      $slide['url'] .= (str_contains($slide['url'], '?') ? '&' : '?') . 'group=' . $groupContext['room']['public_id'];
    }
  }
  unset($slide);
}
$firstKey = $hotProducts ? 'hot' : ($cats ? 'cat-' . (int) $cats[0]['id'] : ($standaloneToppings ? 'standalone' : ''));
front_header(); ?>

<!-- banner causer slider -->
<?php if ($banners): ?>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/home-slider.css?v=2')) ?>">
  <!-- Auto-playing banner slider: assets/js/home-slider.js. A single banner shows as a still image. -->
  <section class="home-banner-wrap home-slider" data-home-slider data-interval="<?= hbn_interval() * 1000 ?>"
    aria-roledescription="carousel" aria-label="<?= e(t('homepage_banner')) ?>">
    <div class="home-slider-track" data-slider-track>
      <?php foreach ($banners as $index => $slide): ?>
        <?php
        $alt = t('homepage_banner') . ' ' . ($index + 1);
        $img = '<img src="' . e(base_url('uploads/' . $slide['image'])) . '" alt="' . e($alt) . '"'
          . ($index === 0 ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
        ?>
        <?php if ($slide['url'] !== ''): ?>
          <a class="home-banner home-slide" href="<?= e($slide['url']) ?>" data-slide><?= $img ?></a>
        <?php else: ?>
          <div class="home-banner home-slide" data-slide><?= $img ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if (count($banners) > 1): ?>
      <button class="home-slider-arrow is-prev" type="button" data-slider-prev aria-label="<?= e(hbn_t('prev')) ?>">‹</button>
      <button class="home-slider-arrow is-next" type="button" data-slider-next aria-label="<?= e(hbn_t('next')) ?>">›</button>
      <div class="home-slider-dots">
        <?php foreach ($banners as $index => $slide): ?>
          <button type="button" data-slider-dot="<?= $index ?>" aria-label="<?= e(hbn_t('go_to', ['n' => $index + 1])) ?>" <?= $index === 0 ? ' aria-current="true"' : '' ?>></button>
        <?php endforeach; ?>
      </div>
      <script src="<?= e(base_url('assets/js/home-slider.js?v=2')) ?>" defer></script>
    <?php endif; ?>
  </section>
<?php endif; ?>

<!-- Form đặt đơn nhóm -->
<?php if (!$groupContext): ?>
  <?php $inGroupOrder = hyg_has_live_group(); ?>
  <section class="hyg-entry hyg-entry-highlight<?= $inGroupOrder ? ' is-locked' : '' ?>">
    <div class="hyg-entry-text"><strong><?= e(gt('entry_title')) ?></strong></div>
    <div class="hyg-entry-actions">
      <?php if ($inGroupOrder): ?><button type="button" class="btn primary hyg-entry-cta" disabled aria-disabled="true"><?php else: ?><a class="btn primary hyg-entry-cta" href="<?= e(hym_login_gate_url('group')) ?>"><?php endif; ?>
          <!-- people icon -->
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="8" r="3" />
            <path d="M3 20v-1a6 6 0 0 1 12 0v1M16 5a3 3 0 0 1 0 6M21 20v-1a6 6 0 0 0-4-5.6" />
          </svg>
          <span><?= e(gt('entry_cta')) ?></span>
          <span class="hyg-entry-arrow" aria-hidden="true">→</span>
          <?php if ($inGroupOrder): ?></button><?php else: ?></a><?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<!-- Combo slider: lib/combo_front.php hcf_render_section(). Điện thoại 1 thẻ, iPad 2, màn lớn 3. -->
<?php /* Thiết bị đang ở trong một đơn nhóm (kể cả bấm "Quay lại menu" nên URL không còn ?group=) thì ẩn combo. */ ?>
<?php if ($groupContext): ?>
  <?php /* Đơn nhóm gộp: chỉ chủ nhóm thêm combo vào giỏ nhóm; "Đặt ngay" gửi add_combo (group/action.php). */ ?>
  <?php if (hyg_host($groupContext['room']) && $groupContext['room']['status'] === 'open'): ?><?= hcf_render_section($homeCombos, $groupContext['room']) ?><?php endif; ?>
<?php elseif (hyg_has_live_group()): ?>
  <?php /* Vào menu không có ?group=: chủ nhóm vẫn thấy combo của phòng đang mở. */ ?>
  <?php
    $liveComboRoom = null;
    try {
      $liveComboRoom = hyg_one("SELECT * FROM group_rooms WHERE owner_hash=? AND status='open' AND order_id IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1", [hyg_device()]);
    } catch (Throwable $e) {
      $liveComboRoom = null;
    }
  ?>
  <?php if ($liveComboRoom): ?><?= hcf_render_section($homeCombos, $liveComboRoom) ?><?php endif; ?>
<?php elseif (!hyg_in_active_group()): ?><?= hcf_render_section($homeCombos) ?><?php endif; ?>

<?php if ($featuredProducts || $featuredToppings): ?>
  <section class="featured-section" id="featured">
    <div class="section-title-row featured-title">
      <div><span class="eyebrow"><?= e(t('featured_eyebrow')) ?></span>
        <h2><?= e(t('new_hot_recommendations')) ?></h2>
      </div><span class="section-count"><?= count($featuredProducts) + count($featuredToppings) ?></span>
    </div>
    <div class="featured-scroller">
      <?php foreach ($featuredProducts as $p):
        $pn = localized_name($p); ?><a class="featured-card"
          href="<?= e(hyg_url('product.php?id=' . $p['id'])) ?>">
          <div class="featured-image"><?php if ($p['image']): ?><img src="<?= e(base_url('uploads/' . $p['image'])) ?>"
                alt="<?= e($pn) ?>" loading="lazy"><?php else: ?>
              <div class="placeholder">DRINK</div><?php endif; ?>
            <div class="badge-stack"><?php if (!empty($p['is_new'])): ?><span
                  class="product-badge new"><?= e(t('new_badge')) ?></span><?php endif; ?></div>
            <?php if (!empty($p['is_hot'])): ?><span class="hot-corner-badge" title="<?= e(t('hot_badge')) ?>"><img
                  class="hot-corner-thumb" src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>"
                  alt="<?= e(t('hot_badge')) ?>"></span><?php endif; ?>
          </div>
          <div class="featured-info">
            <h3><?= e($pn) ?></h3><strong class="product-price-text"><?php if (product_has_sizes($p)): ?><span
                  class="size-price-row"><span class="size-price-label">L</span><span
                    class="size-price-value"><?= e(currency()) ?><?= money($p['price_l'] ?? 0) ?></span></span><span
                  class="size-price-row"><span class="size-price-label">XL</span><span
                    class="size-price-value"><?= e(currency()) ?><?= money($p['price_xl'] ?? 0) ?></span></span><?php else: ?><?= e(currency()) ?><?= money($p['price'] ?? 0) ?><?php endif; ?></strong><?php if (show_product_sales_count()): ?><small
                class="sold-count"><?= e(t('sold_cups', ['qty' => number_format((int) $p['sold_qty'])])) ?></small><?php endif; ?>
          </div>
        </a><?php endforeach; ?>
      <?php foreach ($featuredToppings as $top):
        $tn = localized_name($top); ?><a class="featured-card"
          href="<?= e(hyg_url('topping.php?id=' . $top['id'])) ?>">
          <div class="featured-image"><?php if (!empty($top['image'])): ?><img
                src="<?= e(base_url('uploads/' . $top['image'])) ?>" alt="<?= e($tn) ?>" loading="lazy"><?php else: ?>
              <div class="placeholder">TOPPING</div><?php endif; ?>
            <div class="badge-stack"><?php if (!empty($top['is_new'])): ?><span
                  class="product-badge new"><?= e(t('new_badge')) ?></span><?php endif; ?></div>
            <?php if (!empty($top['is_hot'])): ?><span class="hot-corner-badge" title="<?= e(t('hot_badge')) ?>"><img
                  class="hot-corner-thumb" src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>"
                  alt="<?= e(t('hot_badge')) ?>"></span><?php endif; ?>
          </div>
          <div class="featured-info">
            <h3><?= e($tn) ?></h3><strong
              class="product-price-text"><?= e(currency()) ?><?= money($top['retail_price']) ?></strong>
          </div>
        </a><?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($firstKey !== ''): ?>
  <section class="catalog-shell" data-catalog>
    <aside class="catalog-sidebar">
      <div class="catalog-sidebar-head"><?= e(t('categories')) ?></div>
      <div class="catalog-category-list">
        <?php if ($hotProducts): ?><button type="button" class="catalog-category-btn <?= $firstKey === 'hot' ? 'active' : '' ?>"
            data-menu-category="hot"><span><?= e(t('hot_products')) ?></span><b><?= count($hotProducts) ?></b></button><?php endif; ?>
        <?php foreach ($cats as $cat):
          $key = 'cat-' . (int) $cat['id']; ?><button type="button"
            class="catalog-category-btn <?= $key === $firstKey ? 'active' : '' ?>"
            data-menu-category="<?= e($key) ?>"><span><?= e(localized_name($cat)) ?></span><b><?= count($productsByCat[(int) $cat['id']] ?? []) ?></b></button><?php endforeach; ?>
        <?php if ($standaloneToppings): ?><button type="button"
            class="catalog-category-btn <?= $firstKey === 'standalone' ? 'active' : '' ?>"
            data-menu-category="standalone"><span><?= e(t('catalog_topping_category')) ?></span><b><?= count($standaloneToppings) ?></b></button><?php endif; ?>
      </div>
    </aside>
    <div class="catalog-main">
      <?php if ($hotProducts): ?>
        <section class="catalog-products-panel <?= $firstKey === 'hot' ? 'active' : '' ?>" data-menu-panel="hot">
          <div class="catalog-panel-head">
            <h2><?= e(t('hot_products')) ?></h2><span><?= count($hotProducts) ?></span>
          </div>
          <div class="product-grid catalog-product-grid">
            <?php foreach ($hotProducts as $p):
              $pn = localized_name($p); ?><a class="product-card"
                href="<?= e(hyg_url('product.php?id=' . $p['id'])) ?>">
                <div class="product-img"><?php if ($p['image']): ?><img src="<?= e(base_url('uploads/' . $p['image'])) ?>"
                      alt="<?= e($pn) ?>" loading="lazy"><?php else: ?>
                    <div class="placeholder">DRINK</div><?php endif; ?>
                  <div class="badge-stack"><?php if (!empty($p['is_new'])): ?><span
                        class="product-badge new"><?= e(t('new_badge')) ?></span><?php endif; ?></div><span
                    class="hot-corner-badge" title="<?= e(t('hot_badge')) ?>"><img class="hot-corner-thumb"
                      src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>" alt="<?= e(t('hot_badge')) ?>"></span>
                </div>
                <div class="product-info">
                  <h3><?= e($pn) ?></h3>
                  <div class="product-meta"><strong class="product-price-text"><?php if (product_has_sizes($p)): ?><span
                          class="size-price-row"><span class="size-price-label">L</span><span
                            class="size-price-value"><?= e(currency()) ?><?= money($p['price_l'] ?? 0) ?></span></span><span
                          class="size-price-row"><span class="size-price-label">XL</span><span
                            class="size-price-value"><?= e(currency()) ?><?= money($p['price_xl'] ?? 0) ?></span></span><?php else: ?><?= e(currency()) ?><?= money($p['price'] ?? 0) ?><?php endif; ?></strong><span
                      class="product-arrow" aria-hidden="true">+</span></div><?php if (show_product_sales_count()): ?><small
                      class="sold-count"><?= e(t('sold_cups', ['qty' => number_format((int) $p['sold_qty'])])) ?></small><?php endif; ?>
                </div>
              </a><?php endforeach; ?>
          </div>
        </section><?php endif; ?>
      <?php foreach ($cats as $cat):
        $key = 'cat-' . (int) $cat['id'];
        $products = $productsByCat[(int) $cat['id']] ?? []; ?>
        <section class="catalog-products-panel <?= $key === $firstKey ? 'active' : '' ?>" data-menu-panel="<?= e($key) ?>">
          <div class="catalog-panel-head">
            <h2><?= e(localized_name($cat)) ?></h2><span><?= count($products) ?></span>
          </div>
          <div class="product-grid catalog-product-grid">
            <?php foreach ($products as $p):
              $pn = localized_name($p); ?><a class="product-card"
                href="<?= e(hyg_url('product.php?id=' . $p['id'])) ?>">
                <div class="product-img"><?php if ($p['image']): ?><img src="<?= e(base_url('uploads/' . $p['image'])) ?>"
                      alt="<?= e($pn) ?>" loading="lazy"><?php else: ?>
                    <div class="placeholder">DRINK</div><?php endif; ?>
                  <div class="badge-stack"><?php if (!empty($p['is_new'])): ?><span
                        class="product-badge new"><?= e(t('new_badge')) ?></span><?php endif; ?></div>
                  <?php if (!empty($p['is_hot'])): ?><span class="hot-corner-badge" title="<?= e(t('hot_badge')) ?>"><img
                        class="hot-corner-thumb" src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>"
                        alt="<?= e(t('hot_badge')) ?>"></span><?php endif; ?>
                </div>
                <div class="product-info">
                  <h3><?= e($pn) ?></h3>
                  <div class="product-meta"><strong class="product-price-text"><?php if (product_has_sizes($p)): ?><span
                          class="size-price-row"><span class="size-price-label">L</span><span
                            class="size-price-value"><?= e(currency()) ?><?= money($p['price_l'] ?? 0) ?></span></span><span
                          class="size-price-row"><span class="size-price-label">XL</span><span
                            class="size-price-value"><?= e(currency()) ?><?= money($p['price_xl'] ?? 0) ?></span></span><?php else: ?><?= e(currency()) ?><?= money($p['price'] ?? 0) ?><?php endif; ?></strong><span
                      class="product-arrow" aria-hidden="true">+</span></div><?php if (show_product_sales_count()): ?><small
                      class="sold-count"><?= e(t('sold_cups', ['qty' => number_format((int) $p['sold_qty'])])) ?></small><?php endif; ?>
                </div>
              </a><?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if ($standaloneToppings): ?>
        <section class="catalog-products-panel <?= $firstKey === 'standalone' ? 'active' : '' ?>" data-menu-panel="standalone">
          <div class="catalog-panel-head">
            <h2><?= e(t('catalog_topping_category')) ?></h2><span><?= count($standaloneToppings) ?></span>
          </div>
          <div class="product-grid catalog-product-grid">
            <?php foreach ($standaloneToppings as $top):
              $tn = localized_name($top); ?><a class="product-card"
                href="<?= e(hyg_url('topping.php?id=' . $top['id'])) ?>">
                <div class="product-img"><?php if (!empty($top['image'])): ?><img
                      src="<?= e(base_url('uploads/' . $top['image'])) ?>" alt="<?= e($tn) ?>" loading="lazy"><?php else: ?>
                    <div class="placeholder">TOPPING</div><?php endif; ?>
                  <div class="badge-stack"><?php if (!empty($top['is_new'])): ?><span
                        class="product-badge new"><?= e(t('new_badge')) ?></span><?php endif; ?></div>
                  <?php if (!empty($top['is_hot'])): ?><span class="hot-corner-badge" title="<?= e(t('hot_badge')) ?>"><img
                        class="hot-corner-thumb" src="<?= e(base_url('assets/icons/thumb-up.png?v=18.12')) ?>"
                        alt="<?= e(t('hot_badge')) ?>"></span><?php endif; ?>
                </div>
                <div class="product-info">
                  <h3><?= e($tn) ?></h3>
                  <div class="product-meta"><strong
                      class="product-price-text"><?= e(currency()) ?><?= money($top['retail_price']) ?></strong><span
                      class="product-arrow" aria-hidden="true">+</span></div>
                </div>
              </a><?php endforeach; ?></div>
        </section><?php endif; ?>
    </div>
  </section>
<?php endif; ?>
<?php front_footer(); ?>