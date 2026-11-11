<?php
/** Home page banner slider: add / edit / hide / reorder / delete banners and set the slide timing (lib/home_banners.php). */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_admin();
header('Cache-Control: private, no-store');

// ---------- Actions (POST → redirect with a flash message) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ($action) {
            case 'create':
                hbn_create($_POST);
                $_SESSION['banner_flash'] = ['ok', 'saved'];
                break;
            case 'update':
                hbn_update($id, $_POST);
                $_SESSION['banner_flash'] = ['ok', 'saved'];
                break;
            case 'delete':
                hbn_delete($id);
                $_SESSION['banner_flash'] = ['ok', 'deleted'];
                break;
            case 'up':
            case 'down':
                hbn_move($id, $action === 'up' ? -1 : 1);
                break;
            case 'interval':
                hbn_set_interval($_POST['interval'] ?? null);
                $_SESSION['banner_flash'] = ['ok', 'interval_saved'];
                break;
            default:
                hbn_fail('storage');
        }
    } catch (HomeBannerError $error) {
        $_SESSION['banner_flash'] = ['error', $error->getMessage()];
    } catch (Throwable $error) {
        error_log('[banners] ' . $error->getMessage());
        $_SESSION['banner_flash'] = ['error', 'storage'];
    }
    redirect(base_url('admin/banners.php'));
}

// ---------- Data ----------
$flash = $_SESSION['banner_flash'] ?? null;
unset($_SESSION['banner_flash']);

$banners = hbn_all();
$shown = count(array_filter($banners, fn($b) => (int) $b['active'] === 1));
$products = db()->query('SELECT * FROM products ORDER BY active DESC, sort_order, id')->fetchAll();
$toppings = db()->query('SELECT * FROM toppings WHERE sell_separately = 1 ORDER BY active DESC, sort_order, id')->fetchAll();

/** Link fields shared by the "add" form and every "edit" form. */
function banner_link_fields(array $banner, array $products, array $toppings, string $prefix): void
{
    $type = (string) ($banner['link_type'] ?? 'none');
    $target = (int) ($banner['target_id'] ?? 0);
    ?>
    <label><?= e(hbn_t('link')) ?>
        <select name="link_type" data-banner-link-type>
            <?php foreach (['none', 'product', 'topping', 'url'] as $option): ?>
                <option value="<?= $option ?>"<?= $type === $option ? ' selected' : '' ?>><?= e(hbn_t('link_' . $option)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label data-banner-link-field="product"><?= e(hbn_t('product')) ?>
        <select name="product_id">
            <option value="0">—</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $type === 'product' && $target === (int) $p['id'] ? ' selected' : '' ?>>
                    <?= e(localized_name($p)) ?><?= (int) $p['active'] === 1 ? '' : ' (off)' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label data-banner-link-field="topping"><?= e(hbn_t('topping')) ?>
        <select name="topping_id">
            <option value="0">—</option>
            <?php foreach ($toppings as $tp): ?>
                <option value="<?= (int) $tp['id'] ?>"<?= $type === 'topping' && $target === (int) $tp['id'] ? ' selected' : '' ?>>
                    <?= e(localized_name($tp)) ?><?= (int) $tp['active'] === 1 ? '' : ' (off)' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label data-banner-link-field="url"><?= e(hbn_t('link_url_label')) ?>
        <input name="link_url" maxlength="500" placeholder="group/" value="<?= e((string) ($banner['link_url'] ?? '')) ?>" id="<?= e($prefix) ?>-url">
        <small class="field-hint"><?= e(hbn_t('link_url_help')) ?></small>
    </label>
    <?php
}

/** Short human description of where a banner leads. */
function banner_link_summary(array $banner, array $products, array $toppings): string
{
    $id = (int) ($banner['target_id'] ?? 0);
    $find = function (array $rows) use ($id): string {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return localized_name($row);
            }
        }
        return '#' . $id;
    };
    switch ($banner['link_type']) {
        case 'product':
            return hbn_t('product') . ': ' . $find($products);
        case 'topping':
            return hbn_t('topping') . ': ' . $find($toppings);
        case 'url':
            return '/' . $banner['link_url'];
        default:
            return hbn_t('link_none');
    }
}

admin_header(hbn_t('title'));
?>
<style>
    .banner-list { display: grid; gap: 14px; }
    .banner-row { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 16px; padding: 14px; border: 1px solid #e4e7ec; border-radius: 14px; background: #fff; }
    .banner-row.is-off { opacity: .6; }
    .banner-row img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 10px; background: #f2f4f7; }
    .banner-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; }
    .banner-badge { padding: 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 750; background: #ecfdf3; color: #067647; }
    .banner-row.is-off .banner-badge { background: #f2f4f7; color: #475467; }
    .banner-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .banner-actions form { margin: 0; }
    .banner-row details { margin-top: 10px; }
    .banner-row details summary { cursor: pointer; font-weight: 700; }
    @media (max-width: 760px) { .banner-row { grid-template-columns: 1fr; } }
</style>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'success' : 'danger' ?>" role="status"><?= e(hbn_t($flash[1])) ?></div>
<?php endif; ?>

<div class="alert info"><?= e(hbn_t('intro')) ?></div>

<!-- Slide timing -->
<section class="panel">
    <h2><?= e(hbn_t('interval')) ?></h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="interval">
        <label><?= e(hbn_t('interval')) ?>
            <input type="number" name="interval" min="3" max="30" step="1" required value="<?= hbn_interval() ?>">
            <small class="field-hint"><?= e(hbn_t('interval_help')) ?></small>
        </label>
        <button class="btn primary" type="submit"><?= e(hbn_t('save_interval')) ?></button>
    </form>
</section>

<!-- Add -->
<section class="panel">
    <h2><?= e(hbn_t('add')) ?></h2>
    <form method="post" enctype="multipart/form-data" class="form-grid" data-banner-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label><?= e(hbn_t('image')) ?>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
        </label>
        <?php banner_link_fields([], $products, $toppings, 'new'); ?>
        <label class="toggle-row"><input type="checkbox" name="active" value="1" checked> <span><strong><?= e(hbn_t('active')) ?></strong></span></label>
        <button class="btn primary" type="submit"><?= e(hbn_t('add')) ?></button>
    </form>
</section>

<!-- List -->
<section class="panel">
    <h2><?= e(hbn_t('title')) ?> · <?= e(hbn_t('count', ['shown' => $shown, 'total' => count($banners)])) ?></h2>
    <?php if (!$banners): ?>
        <p class="muted"><?= e(hbn_t('empty')) ?></p>
    <?php else: ?>
        <div class="banner-list">
            <?php foreach ($banners as $index => $b): ?>
                <article class="banner-row<?= (int) $b['active'] === 1 ? '' : ' is-off' ?>">
                    <img src="<?= e(base_url('uploads/' . $b['image'])) ?>" alt="" loading="lazy">
                    <div>
                        <div class="banner-meta">
                            <strong>#<?= $index + 1 ?></strong>
                            <span class="banner-badge"><?= e(hbn_t((int) $b['active'] === 1 ? 'on' : 'off')) ?></span>
                            <span><?= e(banner_link_summary($b, $products, $toppings)) ?></span>
                        </div>

                        <div class="banner-actions">
                            <?php foreach (['up' => '↑', 'down' => '↓'] as $move => $arrow): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= $move ?>">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <button class="btn small" type="submit" title="<?= e(hbn_t($move)) ?>"
                                        <?= ($move === 'up' && $index === 0) || ($move === 'down' && $index === count($banners) - 1) ? 'disabled' : '' ?>><?= $arrow ?> <?= e(hbn_t($move)) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <form method="post" data-confirm="<?= e(hbn_t('delete_confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button class="btn danger small" type="submit"><?= e(hbn_t('delete')) ?></button>
                            </form>
                        </div>

                        <details>
                            <summary><?= e(hbn_t('edit')) ?></summary>
                            <form method="post" enctype="multipart/form-data" class="form-grid" data-banner-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <label><?= e(hbn_t('image_replace')) ?>
                                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                                </label>
                                <?php banner_link_fields($b, $products, $toppings, 'b' . (int) $b['id']); ?>
                                <label class="toggle-row"><input type="checkbox" name="active" value="1"<?= (int) $b['active'] === 1 ? ' checked' : '' ?>> <span><strong><?= e(hbn_t('active')) ?></strong></span></label>
                                <button class="btn primary" type="submit"><?= e(hbn_t('save')) ?></button>
                            </form>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
    // Show only the link field that matches the chosen link type; confirm deletes.
    document.querySelectorAll('[data-banner-form]').forEach(function (form) {
        const type = form.querySelector('[data-banner-link-type]');
        function sync() {
            form.querySelectorAll('[data-banner-link-field]').forEach(function (field) {
                field.hidden = field.dataset.bannerLinkField !== type.value;
            });
        }
        type.addEventListener('change', sync);
        sync();
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
</script>
<?php admin_footer(); ?>
