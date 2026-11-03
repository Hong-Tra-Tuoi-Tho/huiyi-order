<?php
require_once __DIR__.'/game_config.php';
/** Thống kê doanh thu mini-game theo từng CTKM trong hgr_game_config; hiển thị trong admin/game.php. */
function hgr_revenue_date(string $key, string $default): string
{
    $value = (string)($_GET[$key] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return $default;
    }
    return $value;
}
/** Gọi sau admin_header() vì cần has_label() trong admin_shell.php. */
function hgr_revenue_panel(): void
{
    $from = hgr_revenue_date('from', date('Y-m-01'));
    $to = hgr_revenue_date('to', date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    // Một dòng cho mỗi CTKM trong cấu hình game:
    // - giảm tiền > 0đ: theo mệnh giá
    // - giảm 0đ: "Chúc bạn MMLS", tính các coupon 52k nhận được nhờ chia sẻ
    // - tặng ly / topping: theo id phần quà
    $campaigns = [];
    foreach (hgr_config()['prizes'] as $prize) {
        if ($prize['kind'] === 'discount' && $prize['amount'] > 0) {
            $key = 'amount:'.(int)$prize['amount'];
            $label = number_format($prize['amount'] / 1000);
            $sort = (int)$prize['amount'];
        } elseif ($prize['kind'] === 'discount') {
            $key = 'share';
            $label = has_label('祝你下次好運', 'Chúc bạn MMLS', 'Better luck next time');
            $sort = 0;
        } else {
            $key = 'gift:'.$prize['id'];
            $count = count($prize['item_ids']) * $prize['gift_qty'];
            $label = $prize['kind'] === 'tea'
                ? has_label('贈 '.$count.' 杯', 'Tặng '.$count.' ly', $count.' free drinks')
                : has_label('贈 '.$count.' 份加料', 'Tặng '.$count.' topping', $count.' free toppings');
            $sort = PHP_INT_MAX;
        }
        $campaigns[$key] ??= [
            'label' => $label,
            'sort' => $sort,
            'orders' => [],
            'coupons' => 0,
            'discount' => 0.0,
        ];
    }
    uasort($campaigns, fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

    // Mọi coupon game đã dùng (một đơn có thể dùng nhiều coupon); bỏ đơn đã hủy.
    // Ly tặng có giá 0đ, phần giảm nằm ở order_items.discount_amount của dòng quà.
    $sql = "SELECT c.code,
                   c.amount,
                   d.reward_json,
                   o.id AS order_id,
                   o.subtotal_amount,
                   o.total_amount,
                   o.created_at,
                   (SELECT COALESCE(SUM(i.discount_amount), 0)
                    FROM order_items i
                    WHERE i.order_id = o.id AND i.game_reward_code = c.code) AS gift_discount
            FROM customer_coupons c
            JOIN game_coupon_details d ON d.coupon_id = c.id
            JOIN orders o ON o.id = c.used_order_id
            WHERE c.coupon_type = 'game_reward'
              AND o.status <> 'cancelled'
              AND o.created_at >= ?
              AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $st = db()->prepare($sql);
    $st->execute([$from, $to]);

    $orders = [];
    foreach ($st->fetchAll() as $row) {
        $reward = json_decode((string)$row['reward_json'], true);
        if (!is_array($reward)) {
            continue;
        }
        if (($reward['kind'] ?? '') !== 'discount') {
            $key = 'gift:'.($reward['id'] ?? '');
            $discount = (float)$row['gift_discount'];
        } elseif (!empty($reward['share_bonus'])) {
            $key = 'share';
            $discount = (float)$row['amount'];
        } else {
            $key = 'amount:'.(int)$row['amount'];
            $discount = (float)$row['amount'];
        }
        if (!isset($campaigns[$key])) {
            continue;
        }
        $orderId = (int)$row['order_id'];
        $orders[$orderId] ??= [
            'net' => (float)$row['total_amount'],
            'gross' => (float)$row['subtotal_amount'],
            'discount' => 0.0,
            'created_at' => (string)$row['created_at'],
        ];
        // Giảm giá của đơn = tổng phần giảm của các coupon CTKM trong đơn (dùng cho biểu đồ).
        $orders[$orderId]['discount'] += $discount;
        $campaigns[$key]['orders'][$orderId] = true;
        $campaigns[$key]['coupons']++;
        $campaigns[$key]['discount'] += $discount;
    }

    // Số lượng = số coupon đã dùng (một đơn có thể dùng nhiều coupon cùng CTKM).
    // Net/gross của mỗi CTKM = tổng các đơn có dùng CTKM đó; dòng Tổng tính mỗi đơn một lần.
    foreach ($campaigns as &$campaign) {
        $ids = array_keys($campaign['orders']);
        $campaign['net'] = array_sum(array_map(fn(int $id): float => $orders[$id]['net'], $ids));
        $campaign['gross'] = array_sum(array_map(fn(int $id): float => $orders[$id]['gross'], $ids));
    }
    unset($campaign);

    $total = [
        'coupons' => array_sum(array_column($campaigns, 'coupons')),
        'net' => array_sum(array_column($orders, 'net')),
        'gross' => array_sum(array_column($orders, 'gross')),
        'discount' => array_sum(array_column($campaigns, 'discount')),
    ];

    $money = fn(float $value): string => number_format($value).' đ';
    // % giảm giá = tổng giảm giá / doanh thu gross.
    $percent = fn(float $discount, float $gross): string => $gross > 0
        ? number_format($discount / $gross * 100, 1).'%'
        : '0%';
    ?>
<section class="panel" id="game-revenue">
  <style>
    #game-revenue tfoot th{font-size:14px;font-weight:800;color:var(--ui-accent,#bc1e2c)}
    #game-revenue tbody td:first-child{font-weight:700}
  </style>
  <h2><?=e(has_label('小遊戲營收統計', 'Thống kê doanh thu mini-game', 'Mini Game revenue report'))?></h2>
  <form method="get" action="#game-revenue" class="form-grid">
    <label><?=e(has_label('從', 'Từ ngày', 'From'))?><input type="date" name="from" value="<?=e($from)?>"></label>
    <label><?=e(has_label('至', 'Đến ngày', 'To'))?><input type="date" name="to" value="<?=e($to)?>"></label>
    <button class="btn primary small" type="submit" style="justify-self:end;width:auto;display:inline-flex;align-items:center;gap:6px">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/></svg>
      <?=e(has_label('篩選', 'Lọc', 'Filter'))?>
    </button>
  </form>
  <p class="field-hint"><?=e(has_label(
      '依小遊戲折抵獎項統計，不含已取消訂單。',
      'Thống kê theo CTKM giảm tiền của Mini Game, không tính đơn đã hủy.',
      'Grouped by Mini Game money-off prizes, cancelled orders excluded.'
  ))?></p>
  <?=hgr_revenue_chart($orders, $from, $to)?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?=e(has_label('活動', 'CTKM', 'Promotion'))?></th>
          <th><?=e(has_label('訂單數', 'Số lượng', 'Orders'))?></th>
          <th><?=e(has_label('淨營收', 'Doanh thu net', 'Net revenue'))?></th>
          <th><?=e(has_label('總營收', 'Doanh thu gross', 'Gross revenue'))?></th>
          <th><?=e(has_label('折扣總額', 'Tổng giảm giá', 'Total discount'))?></th>
          <th><?=e(has_label('折扣率', '% giảm giá', 'Discount %'))?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$campaigns): ?>
          <tr><td colspan="6"><?=e(t('no_data'))?></td></tr>
        <?php endif; ?>
        <?php foreach ($campaigns as $campaign): ?>
          <tr>
            <td><?=e($campaign['label'])?></td>
            <td><?=number_format($campaign['coupons'])?></td>
            <td><?=e($money($campaign['net']))?></td>
            <td><?=e($money($campaign['gross']))?></td>
            <td><?=e($money($campaign['discount']))?></td>
            <td><?=e($percent($campaign['discount'], $campaign['gross']))?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th><?=e(has_label('合計', 'Tổng', 'Total'))?></th>
          <th><?=number_format($total['coupons'])?></th>
          <th><?=e($money($total['net']))?></th>
          <th><?=e($money($total['gross']))?></th>
          <th><?=e($money($total['discount']))?></th>
          <th><?=e($percent($total['discount'], $total['gross']))?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</section>
<?php
}
/** Rút gọn số tiền cho trục và nhãn: 1.250.000 → 1,3tr; 500.000 → 500k. */
function hgr_revenue_short(float $value): string
{
    if ($value >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.').'tr';
    }
    if ($value >= 1000) {
        return number_format($value / 1000).'k';
    }
    return number_format($value);
}
/**
 * Biểu đồ cột doanh thu net / tổng giảm giá của các đơn có dùng CTKM mini-game.
 * Lọc một ngày thì trục ngang là giờ (0h–23h), nhiều ngày thì là từng ngày.
 */
function hgr_revenue_chart(array $orders, string $from, string $to): string
{
    $byHour = $from === $to;
    $buckets = [];
    if ($byHour) {
        for ($hour = 0; $hour < 24; $hour++) {
            $buckets[$hour] = ['label' => $hour.'h', 'net' => 0.0, 'discount' => 0.0, 'orders' => 0];
        }
    } else {
        $day = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($day <= $end) {
            $buckets[$day->format('Y-m-d')] = ['label' => $day->format('d/m'), 'net' => 0.0, 'discount' => 0.0, 'orders' => 0];
            $day = $day->modify('+1 day');
        }
    }
    foreach ($orders as $order) {
        $key = $byHour
            ? (int)substr($order['created_at'], 11, 2)
            : substr($order['created_at'], 0, 10);
        if (!isset($buckets[$key])) {
            continue;
        }
        $buckets[$key]['net'] += $order['net'];
        $buckets[$key]['discount'] += $order['discount'];
        $buckets[$key]['orders']++;
    }
    $buckets = array_values($buckets);

    // Trục dọc: làm tròn mức cao nhất lên số "đẹp" (1; 2; 2,5; 5 × 10^n), chia 4 vạch.
    $max = max(array_merge(array_column($buckets, 'net'), array_column($buckets, 'discount'), [0]));
    $step = 1.0;
    if ($max > 0) {
        $raw = $max / 4;
        $power = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $power >= $raw) {
                $step = $factor * $power;
                break;
            }
        }
    }
    $top = $step * 4;

    $width = 900;
    $height = 260;
    $left = 56;
    $right = 8;
    $topPad = 12;
    $bottom = 28;
    $plotW = $width - $left - $right;
    $plotH = $height - $topPad - $bottom;
    $count = count($buckets);
    $groupW = $plotW / $count;
    $barW = max(2, min(16, ($groupW - 6) / 2 - 1));
    // Tối đa 24 nhãn trục ngang để chữ không chồng nhau.
    $labelEvery = max(1, (int)ceil($count / 24));
    $y = fn(float $value): float => $topPad + $plotH - $value / $top * $plotH;
    // Cột bo tròn 4px ở đầu, chân cột vuông nằm trên trục.
    $bar = function (float $x, float $value) use ($y, $barW, $topPad, $plotH): string {
        $base = $topPad + $plotH;
        $yTop = $y($value);
        if ($base - $yTop < 0.5) {
            return '';
        }
        $r = min(4, $barW / 2, $base - $yTop);
        return sprintf(
            '<path d="M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ"/>',
            $x, $base, $yTop + $r, $x, $yTop, $x + $r, $yTop,
            $x + $barW - $r, $x + $barW, $yTop, $x + $barW, $yTop + $r, $base
        );
    };

    $netLabel = has_label('淨營收', 'Doanh thu net', 'Net revenue');
    $discountLabel = has_label('折扣總額', 'Tổng giảm giá', 'Total discount');
    $ordersLabel = has_label('訂單', 'đơn', 'orders');

    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $lineY = $y($step * $i);
        $grid .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>', $left, $width - $right, $lineY, $lineY);
        $grid .= sprintf('<text x="%d" y="%.1f" text-anchor="end">%s</text>', $left - 8, $lineY + 4, e(hgr_revenue_short($step * $i)));
    }
    $netBars = '';
    $discountBars = '';
    $labels = '';
    $hits = '';
    foreach ($buckets as $index => $bucket) {
        $groupX = $left + $index * $groupW;
        $center = $groupX + $groupW / 2;
        // Hai cột cạnh nhau, cách nhau 2px.
        $netBars .= $bar($center - $barW - 1, $bucket['net']);
        $discountBars .= $bar($center + 1, $bucket['discount']);
        if ($index % $labelEvery === 0) {
            $labels .= sprintf('<text x="%.1f" y="%d" text-anchor="middle">%s</text>', $center, $height - 8, e($bucket['label']));
        }
        $hits .= sprintf(
            '<rect x="%.1f" y="%d" width="%.1f" height="%d" data-label="%s" data-net="%s" data-discount="%s" data-orders="%d"/>',
            $groupX, $topPad, $groupW, $plotH,
            e($bucket['label']),
            e(number_format($bucket['net']).' đ'),
            e(number_format($bucket['discount']).' đ'),
            $bucket['orders']
        );
    }

    ob_start();
    ?>
<style>
  .hgr-chart{--hgr-net:var(--ui-accent,#bc1e2c);--hgr-discount:repeating-linear-gradient(-45deg,#000 0 1.5px,#fff 1.5px 4px);--hgr-muted:#898781;--hgr-grid:#e1e0d9;position:relative;margin:12px 0 16px}
  .hgr-chart svg{display:block;width:100%;height:auto}
  .hgr-chart-grid line{stroke:var(--hgr-grid);stroke-width:1}
  .hgr-chart text{fill:var(--hgr-muted);font-size:11px}
  .hgr-chart-net{fill:var(--hgr-net)}
  /* Cột giảm giá: nền trắng sọc chéo đen, viền đen; ô màu chú thích/tooltip dùng gradient tương ứng. */
  .hgr-chart-discount{fill:url(#hgr-discount-stripes);stroke:#000;stroke-width:1}
  .hgr-chart-hits rect{fill:transparent}
  .hgr-chart-hits rect.active{fill:rgba(0,0,0,.05)}
  .hgr-chart-legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;font-size:13px}
  .hgr-chart-legend span::before{content:"";display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px;background:var(--swatch)}
  .hgr-chart-tip{position:absolute;pointer-events:none;background:#fff;border:1px solid var(--hgr-grid);border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.12);padding:8px 10px;font-size:12px;line-height:1.6;white-space:nowrap}
  .hgr-chart-tip i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px}
</style>
<div class="hgr-chart" data-hgr-chart>
  <svg viewBox="0 0 <?=$width?> <?=$height?>" role="img" aria-label="<?=e($netLabel.' / '.$discountLabel)?>">
    <defs>
      <pattern id="hgr-discount-stripes" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
        <rect width="5" height="5" fill="#fff"/>
        <line x1="0" y1="0" x2="0" y2="5" stroke="#000" stroke-width="2"/>
      </pattern>
    </defs>
    <g class="hgr-chart-grid"><?=$grid?></g>
    <g class="hgr-chart-net"><?=$netBars?></g>
    <g class="hgr-chart-discount"><?=$discountBars?></g>
    <g><?=$labels?></g>
    <g class="hgr-chart-hits"><?=$hits?></g>
  </svg>
  <div class="hgr-chart-legend">
    <span style="--swatch:var(--hgr-net)"><?=e($netLabel)?></span>
    <span style="--swatch:var(--hgr-discount)"><?=e($discountLabel)?></span>
  </div>
  <div class="hgr-chart-tip" hidden></div>
</div>
<script>
(function () {
    const chart = document.currentScript.previousElementSibling;
    const tip = chart.querySelector('.hgr-chart-tip');
    const names = <?=json_encode(['net' => $netLabel, 'discount' => $discountLabel, 'orders' => $ordersLabel], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>;
    const hide = function () {
        tip.hidden = true;
        chart.querySelectorAll('.hgr-chart-hits rect.active').forEach(function (rect) {
            rect.classList.remove('active');
        });
    };
    // Rê chuột vào một cột (giờ/ngày) thì hiện số liệu net, tổng giảm giá và số đơn.
    chart.querySelectorAll('.hgr-chart-hits rect').forEach(function (rect) {
        rect.addEventListener('mouseenter', function () {
            hide();
            rect.classList.add('active');
            const data = rect.dataset;
            tip.textContent = '';
            const head = document.createElement('div');
            head.textContent = data.label + ' · ' + data.orders + ' ' + names.orders;
            tip.append(head);
            [['net', '--hgr-net'], ['discount', '--hgr-discount']].forEach(function ([key, color]) {
                const line = document.createElement('div');
                const swatch = document.createElement('i');
                swatch.style.background = 'var(' + color + ')';
                const value = document.createElement('b');
                value.textContent = data[key];
                line.append(swatch, value, ' ' + names[key]);
                tip.append(line);
            });
            tip.hidden = false;
            const box = rect.getBoundingClientRect();
            const host = chart.getBoundingClientRect();
            let x = box.left - host.left + box.width / 2 - tip.offsetWidth / 2;
            x = Math.max(0, Math.min(x, host.width - tip.offsetWidth));
            tip.style.left = x + 'px';
            tip.style.top = '8px';
        });
    });
    chart.querySelector('svg').addEventListener('mouseleave', hide);
})();
</script>
<?php
    return ob_get_clean();
}
