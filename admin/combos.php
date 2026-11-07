<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/combo_admin.php';
require_admin();
ensure_combo_schema();

$combos = db()->query('SELECT * FROM combos ORDER BY sort_order, id')->fetchAll();

// Gom nhóm + món của tất cả combo: combo_id => group_id => ['label' => 'Nhóm 1 (chọn 2)', 'items' => ['Trà đào — L', ...]]
$groupsByCombo = [];
$groupRows = db()->query('SELECT id, combo_id, name, min_pick, max_pick FROM combo_groups ORDER BY sort_order, id')->fetchAll();

foreach ($groupRows as $g) {
    $groupsByCombo[(int)$g['combo_id']][(int)$g['id']] = [
        'label' => combo_group_label((string)$g['name'], (int)$g['min_pick'], (int)$g['max_pick']),
        'items' => [],
    ];
}

$itemRows = db()->query(
    'SELECT ci.combo_id, ci.group_id, ci.cup_size, p.name, p.name_vi, p.name_en, p.name_zh, p.active
     FROM combo_items ci
     JOIN products p ON p.id = ci.product_id
     ORDER BY ci.id'
)->fetchAll();

foreach ($itemRows as $row) {
    $comboId = (int)$row['combo_id'];
    $groupId = (int)$row['group_id'];

    if (!isset($groupsByCombo[$comboId][$groupId])) {
        continue;
    }

    $label = combo_item_name($row, (string)$row['cup_size']);
    if (empty($row['active'])) {
        $label .= ' (' . cb('product_off') . ')';
    }
    $groupsByCombo[$comboId][$groupId]['items'][] = $label;
}

$flash = $_GET['msg'] ?? '';

admin_header(cb('title'));
?>
<?php if ($flash === 'saved'): ?>
    <div class="alert success"><?= e(cb('saved')) ?></div>
<?php elseif ($flash === 'saved_removed'): ?>
    <div class="alert info"><?= e(cb('saved_removed', ['groups' => (int)($_GET['groups'] ?? 0), 'items' => (int)($_GET['items'] ?? 0), 'rooms' => (int)($_GET['rooms'] ?? 0)])) ?></div>
<?php elseif ($flash === 'deleted' && (int)($_GET['rooms'] ?? 0) > 0): ?>
    <div class="alert info"><?= e(cb('deleted_rooms', ['rooms' => (int)$_GET['rooms']])) ?></div>
<?php elseif ($flash === 'deleted'): ?>
    <div class="alert success"><?= e(cb('deleted')) ?></div>
<?php endif; ?>

<div class="alert info"><?= e(cb('info')) ?></div>

<div class="toolbar">
    <a class="btn primary" href="combo_edit.php">＋ <?= e(cb('add')) ?></a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= e(cb('image')) ?></th>
                <th><?= e(cb('name')) ?></th>
                <th><?= e(cb('price')) ?></th>
                <th><?= e(cb('max_cups')) ?></th>
                <th><?= e(cb('items')) ?></th>
                <th><?= e(cb('sort')) ?></th>
                <th><?= e(t('status')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$combos): ?>
                <tr><td colspan="8"><?= e(cb('empty')) ?></td></tr>
            <?php endif; ?>

            <?php foreach ($combos as $c): ?>
                <?php $comboGroups = $groupsByCombo[(int)$c['id']] ?? []; ?>
                <tr>
                    <td>
                        <?php if (!empty($c['image'])): ?>
                            <img class="thumb" src="<?= e(base_url('uploads/' . $c['image'])) ?>" alt="">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td><?= e(currency()) ?><?= money($c['price']) ?></td>
                    <td><?= number_format((int)$c['total_cups']) ?></td>
                    <td>
                        <?php if (!empty($c['all_products'])): ?>
                            <strong><?= e(cb('all_products_label')) ?></strong>
                        <?php endif; ?>
                        <?php foreach ($comboGroups as $group): ?>
                            <div>
                                <strong><?= e($group['label']) ?></strong>
                                <div class="table-note"><?= e(implode(' · ', $group['items'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td><?= number_format((int)$c['sort_order']) ?></td>
                    <td><?= $c['active'] ? e(cb('active')) : e(cb('inactive')) ?></td>
                    <td><a class="btn small" href="combo_edit.php?id=<?= (int)$c['id'] ?>"><?= e(t('edit')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php admin_footer(); ?>
