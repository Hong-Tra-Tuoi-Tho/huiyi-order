<?php

/**
 * Dashboard tracking: số lượt, số khách (theo thiết bị / tài khoản), phễu chuyển đổi,
 * tra cứu thao tác cuối của một thiết bị hoặc một tài khoản, và sửa cấu hình tracking_config.
 * Ngày hiển thị theo giờ Việt Nam; log thô chỉ có trong thời hạn giữ (retention_days).
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';
require_admin();
header('Cache-Control: private, no-store');

$message = '';
$error = '';

// Nút "Chọn trên web": chuyển sang trang thật của website kèm ?tracking_picker=1.
if (isset($_GET['open_picker'])) {
    $pickerUrl = htk_picker_target((string)$_GET['open_picker']);
    if ($pickerUrl === '') {
        $error = 'Không tìm thấy trang nào ứng với URL này trên website.';
    } else {
        redirect($pickerUrl);
    }
}

// ---------------------------------------------------------------------------
// Lưu cấu hình
// ---------------------------------------------------------------------------
$formConfig = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'save_config') {
    verify_csrf();
    $submitted = htk_config_from_form($_POST);
    $problem = htk_validate_config(json_encode($submitted));
    if ($problem === '') {
        set_setting('tracking_config', json_encode($submitted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $message = 'Đã lưu cấu hình tracking.';
    } else {
        $error = $problem;
        $formConfig = $submitted; // giữ lại những gì admin vừa nhập để sửa tiếp
    }
}

// Nhập cấu hình từ file JSON: ghi đè toàn bộ cấu hình đang lưu trong DB.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'import_config') {
    verify_csrf();
    $upload = $_FILES['config_file'] ?? null;
    $raw = '';
    $problem = '';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        $problem = 'Chưa chọn file JSON hoặc tải file lên thất bại.';
    } elseif ((int)$upload['size'] > 1048576) {
        $problem = 'File JSON quá lớn (tối đa 1MB).';
    } else {
        $raw = (string)file_get_contents((string)$upload['tmp_name']);
        $raw = preg_replace('/^ï»¿/', '', $raw);
        $imported = json_decode($raw, true);
        if (!is_array($imported)) {
            $problem = 'File không phải JSON hợp lệ.';
        } else {
            $problem = htk_validate_config($raw);
        }
    }
    if ($problem === '') {
        $default = htk_default_config();
        $clean = array_merge($default, array_intersect_key($imported, $default));
        $clean['enabled'] = !empty($clean['enabled']);
        $clean['rules'] = htk_clean_rules($clean['rules']);
        $clean['funnels'] = htk_clean_funnels(is_array($clean['funnels']) ? $clean['funnels'] : [], array_column($clean['rules'], 'code'));
        set_setting('tracking_config', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $message = 'Đã nhập cấu hình từ file JSON và ghi đè cấu hình cũ (' . count($clean['rules']) . ' thao tác).';
    } else {
        $error = 'Nhập cấu hình thất bại: ' . $problem;
    }
}

$funnelActions = ['save_funnel', 'add_funnel', 'delete_funnel'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['do'] ?? '', $funnelActions, true)) {
    verify_csrf();
    $action = (string)$_POST['do'];
    $current = json_decode(setting('tracking_config'), true);
    $current = is_array($current) ? $current : htk_default_config();
    $known = array_column(htk_clean_rules($current['rules'] ?? []), 'code');
    // Dùng danh sách phễu đã chuẩn hóa (kể cả cấu hình cũ chỉ có một phễu)
    $funnels = htk_clean_funnels(htk_config()['funnels'], $known);
    $targetId = (string)($_POST['fid'] ?? '');
    $openId = '';
    if ($action === 'add_funnel') {
        if (count($funnels) < 20) {
            $openId = 'f' . bin2hex(random_bytes(5));
            $funnels[] = ['id' => $openId, 'name' => 'Phễu mới', 'steps' => []];
        }
        $note = 2;
    } elseif ($action === 'delete_funnel') {
        $funnels = array_values(array_filter($funnels, function ($item) use ($targetId) {
            return $item['id'] !== $targetId;
        }));
        $note = 3;
    } else {
        foreach ($funnels as $index => $item) {
            if ($item['id'] === $targetId) {
                $funnels[$index]['name'] = (string)($_POST['name'] ?? '');
                $funnels[$index]['steps'] = is_array($_POST['steps'] ?? null) ? array_values($_POST['steps']) : [];
            }
        }
        $note = 1;
    }
    $current['funnels'] = htk_clean_funnels($funnels, $known);
    unset($current['funnel']);
    $problem = htk_validate_config(json_encode($current));
    if ($problem === '') {
        set_setting('tracking_config', json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $keep = array_intersect_key($_GET, ['from' => 1, 'to' => 1]);
        $query = $keep + ['funnel_saved' => $note] + ($openId !== '' ? ['open' => $openId] : []);
        redirect(base_url('admin/dashboard.php') . '?' . http_build_query($query) . '#trk-funnels');
    }
    $error = $problem;
}
if (!empty($_GET['funnel_saved'])) {
    $notes = [1 => 'Đã lưu phễu.', 2 => 'Đã thêm phễu mới, chọn các bước rồi bấm Lưu phễu.', 3 => 'Đã xóa phễu.'];
    $message = $notes[(int)$_GET['funnel_saved']] ?? 'Đã lưu phễu.';
}

try {
    htk_cleanup_daily();
} catch (Throwable $cleanupError) {
    // Dọn log lỗi không được chặn trang.
}

$config = htk_config();

// ---------------------------------------------------------------------------
// Khoảng ngày (mặc định 7 ngày gần nhất theo giờ Việt Nam)
// ---------------------------------------------------------------------------
$today = gmdate('Y-m-d', time() + HTK_VN_OFFSET);
function dash_date($value, string $default): string
{
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $default;
}
$to = dash_date($_GET['to'] ?? null, $today);
$from = dash_date($_GET['from'] ?? null, gmdate('Y-m-d', strtotime($to . ' UTC') - 6 * 86400));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if ((strtotime($to . ' UTC') - strtotime($from . ' UTC')) > 365 * 86400) {
    $from = gmdate('Y-m-d', strtotime($to . ' UTC') - 365 * 86400);
}

$lookupDevice = strtolower(trim((string)($_GET['device'] ?? '')));
$lookupDevice = htk_normalize_uuid($lookupDevice);
$lookupMember = max(0, (int)($_GET['member'] ?? 0));

$totals = [];
$unique = ['devices' => 0, 'members' => 0];
$series = [];
$funnel = [];
$lookup = [];
$dataError = '';
try {
    $totals = htk_range_totals($from, $to);
    $unique = htk_range_unique($from, $to);
    $series = htk_daily_series($from, $to);
    $funnel = htk_funnel($from, $to);
    $lookup = htk_lookup($lookupDevice, $lookupMember);
} catch (Throwable $dataException) {
    $dataError = 'Chưa đọc được dữ liệu tracking.';
}

// Xuất Excel bảng "Chi tiết theo trang / chức năng" theo khoảng ngày đang xem.
if (($_GET['export'] ?? '') === 'detail' && $dataError === '') {
    require_once __DIR__ . '/../lib/xlsx_export.php';
    $exportRows = [];
    foreach (dash_detail_rows($totals) as $row) {
        $exportRows[] = [(int)$row['event_type'] === 1 ? 'Xem trang' : 'Bấm nút', dash_event_label($row), (int)$row['total']];
    }
    hxl_send(
        'chi-tiet-theo-trang-' . $from . '-' . $to . '.xlsx',
        ['Loại', 'Trang / chức năng', 'Lượt'],
        $exportRows,
        [2],
        ['Chi tiết theo trang / chức năng', 'Từ ngày ' . $from . ' đến ngày ' . $to . ' (giờ Việt Nam)', 'Xuất lúc ' . gmdate('Y-m-d H:i', time() + HTK_VN_OFFSET) . ' · {count} dòng']
    );
}

// Xuất Excel danh sách thao tác ghi log đang lưu trong cấu hình.
if (($_GET['export'] ?? '') === 'config' && ($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="cau-hinh-ghi-log-' . gmdate('Ymd-His', time() + HTK_VN_OFFSET) . '.json"');
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
if (($_GET['export'] ?? '') === 'config') {
    require_once __DIR__ . '/../lib/xlsx_export.php';
    $kindNames = ['view' => 'Truy cập trang', 'click' => 'Nhấn phần tử', 'minigame' => 'Sự kiện nghiệp vụ (chơi mini-game)', 'order' => 'Sự kiện nghiệp vụ (đặt hàng thành công)'];
    $exportRows = [];
    foreach ($config['rules'] as $rule) {
        $own = $rule['dedupe_seconds'];
        $exportRows[] = [
            $rule['label'],
            $rule['url'],
            $kindNames[$rule['kind']] ?? $rule['kind'],
            str_replace("\n", ' | ', $rule['selector']),
            $own === null ? 'Mặc định (' . $config['dedupe_seconds'] . ' giây)' : ($own === 0 ? 'Không chống trùng' : $own . ' giây'),
            $rule['enabled'] ? 'Đang bật' : 'Đã tắt',
        ];
    }
    hxl_send(
        'cau-hinh-ghi-log-' . gmdate('Ymd-His', time() + HTK_VN_OFFSET) . '.xlsx',
        ['Hành vi', 'URL', 'Loại sự kiện', 'Phần tử', 'Chống trùng', 'Trạng thái'],
        $exportRows,
        [],
        ['Cấu hình ghi log', 'Xuất lúc ' . gmdate('Y-m-d H:i', time() + HTK_VN_OFFSET) . ' (giờ Việt Nam) · {count} thao tác', 'Ghi log ' . ($config['enabled'] ? 'đang bật' : 'đang tắt') . ' · giữ log thô ' . $config['retention_days'] . ' ngày · chống trùng chung ' . $config['dedupe_seconds'] . ' giây']
    );
}

$pageViews = 0;
$clicks = 0;
foreach ($totals as $row) {
    if ((int)$row['event_type'] === 1) {
        $pageViews += (int)$row['total'];
    } else {
        $clicks += (int)$row['total'];
    }
}

function dash_event_label(array $row): string
{
    return htk_event_label($row);
}

/**
 * Bảng chi tiết: liệt kê MỌI thao tác đã khai báo (chưa có lượt thì 0), sau đó tới các dòng thống kê cũ
 * không còn thao tác tương ứng (thao tác đã bị xóa khỏi cấu hình). Sắp xếp theo số lượt giảm dần.
 */
function dash_detail_rows(array $totals): array
{
    $byKey = [];
    foreach ($totals as $row) {
        $byKey[(int)$row['event_type'] . '|' . $row['page_code'] . '|' . (string)$row['action_code']] = $row;
    }
    $rows = [];
    foreach (htk_rules() as $rule) {
        $type = htk_rule_type($rule);
        $key = $type . '|' . $rule['url'] . '|' . ($type === 1 ? '' : $rule['code']);
        $row = $byKey[$key] ?? ['event_type' => $type, 'page_code' => $rule['url'], 'action_code' => $type === 1 ? '' : $rule['code'], 'total' => 0];
        unset($byKey[$key]);
        $rows[] = $row;
    }
    foreach ($byKey as $row) {
        $rows[] = $row;
    }
    usort($rows, function (array $a, array $b): int {
        return (int)$b['total'] <=> (int)$a['total'];
    });
    return $rows;
}

/** Trục số "đẹp": trả về [bước, đỉnh] sao cho 5 khoảng chia phủ hết giá trị lớn nhất. */
function dash_nice_axis(int $max): array
{
    for ($power = 1; $power <= 100000000; $power *= 10) {
        foreach ([1, 2, 5] as $base) {
            if ($base * $power * 5 >= $max) {
                return [$base * $power, $base * $power * 5];
            }
        }
    }
    return [1, 5];
}

/** Đường cong trơn qua các điểm (tọa độ 0-100), giữa hai điểm có dạng chữ S như biểu đồ miền. */
function dash_curve(array $points): string
{
    $path = '';
    foreach ($points as $i => [$x, $y]) {
        if ($i === 0) {
            $path = 'M' . round($x, 2) . ' ' . round($y, 2);
            continue;
        }
        [$px, $py] = $points[$i - 1];
        $mid = ($px + $x) / 2;
        $path .= ' C' . round($mid, 2) . ' ' . round($py, 2) . ' ' . round($mid, 2) . ' ' . round($y, 2) . ' ' . round($x, 2) . ' ' . round($y, 2);
    }
    return $path;
}

/**
 * Biểu đồ phễu kết hợp: cột = lượt xem / thao tác (trục trái), miền + đường = số thiết bị (trục phải),
 * bên dưới mỗi bước là tên bước và tỷ lệ thoát (đỏ khi từ ngưỡng cảnh báo).
 */
function dash_funnel_chart(array $steps, int $warnPercent): string
{
    $count = count($steps);
    if ($count === 0) {
        return '';
    }
    $maxEvents = 0;
    $maxDevices = 0;
    foreach ($steps as $i => $step) {
        $steps[$i]['bar'] = (int)($step['raw'] ?? $step['events']); // cột = số lần bấm / lượt xem thật
        $maxEvents = max($maxEvents, $steps[$i]['bar']);
        $maxDevices = max($maxDevices, (int)$step['devices']);
    }
    [$leftStep, $leftTop] = dash_nice_axis($maxEvents);
    if ($maxDevices <= 2) {
        $rightTop = 2;
        $rightTicks = [0, 1, 2];
    } else {
        [$rightStep, $rightTop] = dash_nice_axis($maxDevices);
        $rightTicks = [];
        for ($i = 0; $i <= 5; $i++) {
            $rightTicks[] = $i * $rightStep;
        }
    }
    $pct = function ($value, $top): float {
        return $top > 0 ? max(0, min(100, $value * 100 / $top)) : 0;
    };
    $points = [];
    foreach ($steps as $i => $step) {
        $points[] = [($i + 0.5) * 100 / $count, 100 - $pct($step['devices'], $rightTop)];
    }
    $line = dash_curve($points);
    $area = $line . ' L' . round($points[$count - 1][0], 2) . ' 100 L' . round($points[0][0], 2) . ' 100 Z';
    $uid = 'g' . substr(md5($line . $count), 0, 6);

    ob_start(); ?>
    <div class="fc">
        <div class="fc-legend">
            <span><i class="fc-key bar"></i>Số lần bấm / lượt xem</span>
            <span><i class="fc-key line"></i>Số thiết bị</span>
        </div>
        <div class="fc-scroll">
            <div class="fc-wrap" style="min-width:<?= max(320, $count * 92) ?>px">
                <div class="fc-plot">
                    <div class="fc-axis left">
                        <?php for ($i = 0; $i <= 5; $i++): ?><span style="bottom:<?= $i * 20 ?>%"><?= number_format($i * $leftStep, 0, ',', '.') ?></span><?php endfor; ?>
                    </div>
                    <div class="fc-area">
                        <?php for ($i = 0; $i <= 5; $i++): ?><i class="fc-grid" style="bottom:<?= $i * 20 ?>%"></i><?php endfor; ?>
                        <svg class="fc-svg under" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <defs>
                                <linearGradient id="<?= $uid ?>" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#b9a283" stop-opacity=".38" />
                                    <stop offset="1" stop-color="#b9a283" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <path d="<?= e($area) ?>" fill="url(#<?= $uid ?>)" />
                        </svg>
                        <?php foreach ($steps as $i => $step): ?>
                            <?php
                            $barPct = $pct($step['bar'], $leftTop);
                            $inside = $barPct >= 14;
                            $unit = ['view' => 'lượt xem', 'click' => 'lần bấm'][$step['kind'] ?? ''] ?? 'lượt';
                            $title = $step['label'] . ': ' . number_format($step['bar'], 0, ',', '.') . ' ' . $unit . ', ' . number_format($step['events'], 0, ',', '.') . ' đơn đi qua bước, ' . number_format($step['devices'], 0, ',', '.') . ' thiết bị';
                            ?>
                            <div class="fc-col" style="left:<?= round($i * 100 / $count, 3) ?>%;width:<?= round(100 / $count, 3) ?>%" title="<?= e($title) ?>">
                                <?php if ((int)$step['bar'] > 0): ?>
                                    <div class="fc-bar" style="height:<?= round($barPct, 2) ?>%"></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <svg class="fc-svg over" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <path d="<?= e($line) ?>" fill="none" stroke="#b9a283" stroke-width="2" vector-effect="non-scaling-stroke" />
                        </svg>
                        <?php foreach ($steps as $i => $step): ?>
                            <?php $devicePct = $pct($step['devices'], $rightTop); ?>
                            <span class="fc-dot" style="left:<?= round($points[$i][0], 3) ?>%;bottom:<?= round($devicePct, 2) ?>%"></span>
                            <?php if ((int)$step['devices'] > 0): // bước không có thiết bị: đã có số 0 của cột, không ghi thêm nhãn để khỏi đè nhau ?>
                                <span class="fc-dlabel" style="left:<?= round($points[$i][0], 3) ?>%;bottom:calc(<?= round($devicePct, 2) ?>% + 12px)"><?= number_format($step['devices'], 0, ',', '.') ?> thiết bị</span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="fc-axis right">
                        <?php foreach ($rightTicks as $tick): ?><span style="bottom:<?= round($pct($tick, $rightTop), 2) ?>%"><?= number_format($tick, 0, ',', '.') ?></span><?php endforeach; ?>
                    </div>
                </div>
                <div class="fc-steps">
                    <?php foreach ($steps as $i => $step): ?>
                        <?php $exit = $step['exit_rate'] ?? null; ?>
                        <div class="fc-step" style="width:<?= round(100 / $count, 3) ?>%">
                            <strong><?= e($step['label']) ?></strong>
                            <span class="fc-raw"><?= number_format((int)$step['bar'], 0, ',', '.') ?> lần thao tác</span>
                            <small>Tỷ lệ thoát (theo đơn)</small>
                            <?php if ($exit === null): ?>
                                <em>—</em>
                            <?php else: ?>
                                <em class="<?= ($warnPercent > 0 && $exit >= $warnPercent) ? 'warn' : '' ?>"><?= e(dash_percent((float)$exit)) ?></em>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Số kiểu Google Analytics: 765, 1,2 N, 3,4 Tr */
function dash_short(int $value): string
{
    if ($value >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1, ',', ''), '0'), ',') . ' Tr';
    }
    if ($value >= 1000) {
        return rtrim(rtrim(number_format($value / 1000, 1, ',', ''), '0'), ',') . ' N';
    }
    return (string)$value;
}

function dash_percent(?float $value): string
{
    return $value === null ? '—' : number_format($value, 1, ',', '') . '%';
}

admin_header('Thống kê truy cập');
?>
<style>
    .trk-wrap {
        display: grid;
        gap: 16px
    }

    .trk-card {
        background: #fff;
        border: 1px solid #e6e6e6;
        border-radius: 12px;
        padding: 16px
    }

    .trk-card h2 {
        margin: 0 0 12px;
        font-size: 1.05rem
    }

    .trk-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px
    }

    .trk-stat strong {
        display: block;
        font-size: 1.6rem
    }

    .trk-stat span {
        color: #666;
        font-size: .85rem
    }

    .trk-table {
        width: 100%;
        border-collapse: collapse
    }

    .trk-table th,
    .trk-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #eee;
        text-align: left
    }

    .trk-table td.num,
    .trk-table th.num {
        text-align: right
    }

    .trk-scroll {
        overflow-x: auto
    }

    .trk-filter {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: end
    }

    .trk-filter label {
        display: grid;
        gap: 4px;
        font-size: .85rem
    }

    .trk-open-config {
        margin-left: auto
    }

    .trk-zero td {
        color: #9aa3b0
    }

    .trk-live {
        font-size: .85rem;
        color: #1c7a3d;
        white-space: nowrap
    }

    .trk-live small {
        color: #777
    }

    .trk-live.paused {
        color: #8a94a6
    }

    .trk-live.fail {
        color: #b3261e
    }

    .trk-state {
        display: inline-block;
        margin-left: 6px;
        padding: 1px 8px;
        border-radius: 999px;
        font-size: .75rem
    }

    .trk-state.on {
        background: #e3f4e8;
        color: #1c7a3d
    }

    .trk-state.off {
        background: #fde8e6;
        color: #b3261e
    }

    .trk-dialog {
        width: min(1000px, 96vw);
        max-height: 92vh;
        padding: 20px;
        border: 0;
        border-radius: 14px;
        box-shadow: 0 12px 48px rgba(0, 0, 0, .3);
        overflow: auto
    }

    .trk-dialog::backdrop {
        background: rgba(0, 0, 0, .45)
    }

    /* Thanh nút luôn nổi ở đáy popup, cuộn danh sách thao tác vẫn thấy nút Lưu */
    .trk-savebar {
        position: sticky;
        bottom: -20px;
        z-index: 2;
        display: flex;
        gap: 8px;
        margin: 8px -20px -20px;
        padding: 12px 20px;
        background: #fff;
        border-top: 1px solid #e6e6e6;
        box-shadow: 0 -6px 14px rgba(0, 0, 0, .08)
    }

    .tc-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px
    }

    .tc-head h2 {
        margin: 0;
        font-size: 1.35rem
    }

    .tc-head p {
        margin: 4px 0 0;
        color: #666;
        font-size: .9rem
    }

    .tc-status {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        white-space: nowrap;
        font-size: .9rem
    }

    .tc-status i {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: #2e8b57
    }

    .tc-status.off i {
        background: #b3261e
    }

    .tc-tabs {
        display: flex;
        gap: 22px;
        margin: 14px 0 0;
        border-bottom: 1px solid #e3e3e3
    }

    .tc-tab {
        border: 0;
        background: none;
        padding: 8px 0 11px;
        font: inherit;
        font-size: 1.05rem;
        color: #666;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px
    }

    .tc-tab.active {
        color: var(--shell-accent, #bc1e2c);
        font-weight: 600;
        border-bottom-color: var(--shell-accent, #bc1e2c)
    }

    .tc-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        padding: 18px 0;
        border-bottom: 1px solid #ececec
    }

    .tc-stats span {
        display: block;
        color: #555;
        font-size: .9rem
    }

    .tc-stats b {
        display: block;
        margin-top: 4px;
        font-size: 1.8rem;
        font-weight: 600
    }

    .tc-toolbar {
        display: grid;
        gap: 8px;
        margin: 16px 0
    }

    .tc-toolbar input[type="search"],
    .tc-toolbar select {
        width: 100%;
        box-sizing: border-box;
        padding: 11px 14px;
        border: 1px solid #d9d9d9;
        border-radius: 10px;
        font: inherit;
        background: #fff
    }

    .tc-toolbar-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .tc-black {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 0;
        border-radius: 999px;
        padding: 11px 20px;
        background: #111;
        color: #fff;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none
    }

    .tc-outline {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #cfcfcf;
        border-radius: 999px;
        padding: 10px 18px;
        background: #fff;
        color: #222;
        font: inherit;
        cursor: pointer;
        text-decoration: none
    }

    .tc-export {
        position: relative
    }

    .tc-export summary {
        list-style: none
    }

    .tc-export summary::-webkit-details-marker {
        display: none
    }

    .tc-export-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        z-index: 20;
        min-width: 160px;
        padding: 6px;
        background: #fff;
        border: 1px solid #d9d9d9;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .12)
    }

    .tc-export-menu a {
        display: block;
        padding: 9px 12px;
        border-radius: 8px;
        color: #222;
        text-decoration: none
    }

    .tc-export-menu a:hover {
        background: #f3f3f3
    }

    .tc-list-head,
    .tc-main {
        display: grid;
        grid-template-columns: minmax(150px, 2fr) minmax(130px, 1.3fr) minmax(110px, 1.2fr) 56px 44px;
        gap: 10px;
        align-items: center
    }

    .tc-list-head {
        padding: 10px 0;
        color: #666;
        font-size: .82rem
    }

    .tc-list-head span:nth-child(4) {
        text-align: center
    }

    .tc-row {
        border-top: 1px solid #ececec;
        padding: 12px 0
    }

    .tc-row[hidden] {
        display: none
    }

    .tc-name strong {
        display: block;
        font-size: .95rem
    }

    .tc-name small {
        color: #666;
        font-size: .85rem
    }

    .tc-kind {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: .8rem;
        color: #333
    }

    .tc-kind svg {
        width: 15px;
        height: 15px;
        flex: none
    }

    .tc-dedupe {
        font-size: .78rem;
        color: #333
    }

    .tc-toggle {
        justify-self: center;
        width: 30px;
        height: 30px;
        padding: 0;
        border-radius: 50%;
        border: 2px solid #111;
        background: #111;
        color: #fff;
        cursor: pointer;
        font-size: .95rem;
        line-height: 1
    }

    .tc-row[data-off="1"] .tc-toggle {
        background: #fff;
        color: transparent;
        border-color: #bbb
    }

    .tc-row[data-off="1"] .tc-name,
    .tc-row[data-off="1"] .tc-kind,
    .tc-row[data-off="1"] .tc-dedupe {
        opacity: .5
    }

    .tc-edit {
        border: 0;
        background: none;
        padding: 6px;
        cursor: pointer;
        color: #444;
        line-height: 0
    }

    .tc-edit svg {
        width: 20px;
        height: 20px
    }

    .tc-empty {
        padding: 18px 0;
        color: #777;
        text-align: center
    }

    .tc-panel[hidden] {
        display: none
    }

    .tc-edit-dialog {
        width: min(560px, 94vw);
        padding: 20px;
        border: 0;
        border-radius: 14px;
        box-shadow: 0 12px 48px rgba(0, 0, 0, .35)
    }

    .tc-edit-dialog::backdrop {
        background: rgba(0, 0, 0, .4)
    }

    .tc-edit-dialog h3 {
        margin: 0 0 12px
    }

    .tc-field {
        display: grid;
        gap: 4px;
        margin-bottom: 12px;
        font-size: .85rem;
        color: #555
    }

    .tc-field input,
    .tc-field select,
    .tc-field textarea {
        width: 100%;
        box-sizing: border-box;
        padding: 9px 11px;
        border: 1px solid #d5d5d5;
        border-radius: 8px;
        font: inherit;
        color: #222
    }

    .tc-row:not([data-kind="click"]) .tc-selcell {
        display: none
    }

    .tc-pickline {
        display: flex;
        gap: 8px;
        align-items: flex-start
    }

    .tc-pickline textarea {
        flex: 1
    }

    .tc-pick-note {
        font-size: .8rem;
        color: #2e7d4f
    }

    .tc-dedupe-sec[hidden] {
        display: none
    }

    .tc-edit-actions {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        margin-top: 6px
    }

    .tc-row.trk-flash .tc-main {
        background: #eef8f1
    }

    @media (max-width: 640px) {

        .tc-list-head {
            display: none
        }

        .tc-main {
            grid-template-columns: 1fr 44px 44px
        }

        .tc-kind,
        .tc-dedupe {
            grid-column: 1 / -1
        }
    }

    .trk-dialog-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px
    }

    .trk-dialog-head h2 {
        margin: 0;
        font-size: 1.1rem
    }

    .trk-bar {
        background: #eef3ee;
        border-radius: 6px;
        height: 10px;
        min-width: 80px
    }

    .trk-bar i {
        display: block;
        height: 100%;
        border-radius: 6px;
        background: #3b8a52
    }

    .trk-meter {
        min-width: 180px;
        width: 40%
    }

    .trk-meter strong {
        display: block;
        text-align: right;
        font-size: 1.1rem;
        margin-bottom: 4px
    }

    .trk-meter-bar {
        background: #e8f0fe;
        border-radius: 3px;
        height: 8px
    }

    .trk-meter-bar i {
        display: block;
        height: 100%;
        min-width: 2px;
        border-radius: 3px;
        background: #1a73e8
    }

    .trk-funnel-row select {
        min-width: 160px
    }

    .trk-funnel-actions {
        white-space: nowrap
    }

    .ga-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        padding: 20px 0
    }

    .ga-kpi {
        padding: 0 24px;
        border-left: 1px solid #e0e0e0;
        min-width: 0
    }

    .ga-kpi:first-child {
        border-left: 0
    }

    .ga-kpi span {
        display: inline-block;
        max-width: 100%;
        color: #444;
        font-size: .95rem;
        border-bottom: 1px dashed #9aa0a6;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .ga-kpi strong {
        display: block;
        margin-top: 10px;
        font-size: 2.3rem;
        font-weight: 400;
        line-height: 1.1;
        color: #202124
    }

    .ga-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px
    }

    .ga-head h2 {
        margin: 0;
        font-size: .95rem;
        font-weight: 500;
        color: #202124
    }

    .fc-legend {
        display: flex;
        gap: 18px;
        flex-wrap: wrap;
        margin: 4px 0 6px;
        font-size: .9rem;
        color: #4a4a4a
    }

    .fc-legend span {
        display: inline-flex;
        align-items: center;
        gap: 6px
    }

    .fc-key {
        width: 12px;
        height: 12px;
        border-radius: 50%
    }

    .fc-key.bar {
        background: #8c2a3b
    }

    .fc-key.line {
        background: #b9a283
    }

    .fc-scroll {
        overflow-x: auto
    }

    .fc-plot {
        display: grid;
        grid-template-columns: 34px 1fr 34px;
        padding-top: 26px
    }

    .fc-axis {
        position: relative;
        height: 270px;
        font-size: .72rem;
        color: #8a8fa0
    }

    .fc-axis span {
        position: absolute;
        left: 0;
        right: 0;
        transform: translateY(50%);
        line-height: 1
    }

    .fc-axis.left span {
        text-align: left
    }

    .fc-axis.right span {
        text-align: right
    }

    .fc-area {
        position: relative;
        height: 270px;
        border-bottom: 1px solid #d9dce3
    }

    .fc-grid {
        position: absolute;
        left: 0;
        right: 0;
        border-top: 1px dashed #e3e5ea
    }

    .fc-svg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        overflow: visible;
        pointer-events: none
    }

    .fc-svg.under {
        z-index: 1
    }

    .fc-svg.over {
        z-index: 3
    }

    .fc-col {
        position: absolute;
        top: 0;
        bottom: 0;
        z-index: 2;
        display: flex;
        justify-content: center;
        align-items: flex-end
    }

    .fc-bar {
        width: 46%;
        max-width: 64px;
        min-height: 3px;
        background: #8c2a3b;
        border-radius: 7px 7px 0 0;
        display: flex;
        justify-content: center;
        padding-top: 7px;
        box-sizing: border-box
    }

    .fc-bar b {
        color: #fff;
        font-size: 1.15rem
    }

    .fc-over {
        position: absolute;
        left: 0;
        right: 0;
        text-align: center;
        font-weight: 700;
        color: #8c2a3b
    }

    .fc-zero {
        position: absolute;
        bottom: 20px;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 1.15rem;
        font-weight: 700;
        color: #8a8fa0
    }

    .fc-dot {
        position: absolute;
        z-index: 4;
        width: 11px;
        height: 11px;
        margin: 0 0 -5.5px -5.5px;
        border-radius: 50%;
        background: #b09a7b;
        box-shadow: 0 0 0 2px #fff
    }

    .fc-dlabel {
        position: absolute;
        z-index: 4;
        transform: translateX(-50%);
        white-space: nowrap;
        font-size: .75rem;
        font-weight: 700;
        color: #a08a6a
    }

    .fc-steps {
        display: flex;
        margin: 10px 34px 0
    }

    .fc-step {
        box-sizing: border-box;
        padding: 0 4px;
        text-align: center;
        display: flex;
        flex-direction: column;
        gap: 4px
    }

    .fc-step strong {
        font-size: .85rem;
        line-height: 1.3
    }

    .fc-raw {
        font-size: .78rem;
        color: #8c2a3b;
        font-weight: 600
    }

    .fc-step small {
        margin-top: 8px;
        font-size: .7rem;
        color: #8a8fa0
    }

    .fc-step em {
        font-style: normal;
        font-weight: 700;
        font-size: 1.1rem;
        color: #1f2933
    }

    .fc-step em.warn {
        color: #d9482b
    }

    @media (max-width: 760px) {
        .ga-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            row-gap: 18px
        }

        .ga-kpi:nth-child(3) {
            border-left: 0
        }

        .ga-kpi {
            padding: 0 14px
        }

        .ga-kpi strong {
            font-size: 1.8rem
        }
    }

    /* Ô lưới mặc định co theo nội dung rộng nhất (bảng 640px, nhiều slide) và đẩy vỡ cả trang: cho phép co lại */
    .trk-wrap>* {
        min-width: 0;
        max-width: 100%
    }

    .trk-slider {
        overflow: hidden
    }

    #trk-funnels {
        min-width: 0
    }

    .trk-track {
        width: 100%
    }

    .trk-slide .trk-scroll {
        max-width: 100%
    }

    .trk-slider-nav {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px
    }

    .trk-slider-tabs {
        flex: 1;
        display: flex;
        gap: 6px;
        overflow-x: auto;
        scrollbar-width: none
    }

    .trk-slider-tabs::-webkit-scrollbar {
        display: none
    }

    .trk-tab {
        border: 1px solid #d5dbe5;
        background: #fff;
        color: #2d3748;
        border-radius: 999px;
        padding: 6px 14px;
        cursor: pointer;
        white-space: nowrap;
        font: inherit;
        font-size: .85rem
    }

    .trk-tab:hover {
        border-color: var(--shell-accent, #bc1e2c);
        color: var(--shell-accent, #bc1e2c)
    }

    .trk-tab.active {
        background: var(--shell-accent, #bc1e2c);
        border-color: var(--shell-accent, #bc1e2c);
        color: #fff
    }

    /* Hai nút mũi tên hai bên: viền vàng cam để nổi bật (selector dài hơn để không bị .btn của admin ghi đè) */
    .trk-wrap .trk-slider-nav>.btn {
        flex: none;
        width: 40px;
        height: 40px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.7rem;
        font-weight: 700;
        line-height: 1;
        background: #f59e0b;
        color: #fff;
        border: 2px solid #f59e0b;
        border-radius: 10px
    }

    .trk-wrap .trk-slider-nav>.btn:hover {
        background: #e8890c;
        border-color: #e8890c;
        color: #fff
    }

    .trk-slider-nav>.btn:focus-visible {
        outline: 3px solid #fcd9a0;
        outline-offset: 2px
    }

    .trk-tab:focus-visible {
        outline: 3px solid #f0b3bb;
        outline-offset: 2px
    }

    .trk-track {
        display: flex;
        align-items: flex-start;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        scroll-behavior: smooth;
        scrollbar-width: none
    }

    .trk-track::-webkit-scrollbar {
        display: none
    }

    .trk-slide {
        flex: 0 0 100%;
        min-width: 0;
        box-sizing: border-box;
        scroll-snap-align: start
    }

    .trk-slider-foot {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid #eee
    }

    .trk-slider-foot .field-hint {
        flex: 1;
        min-width: 220px
    }

    .trk-fedit {
        margin-top: 12px;
        border-top: 1px solid #eee;
        padding-top: 10px
    }

    .trk-fedit summary {
        cursor: pointer;
        display: inline-block
    }

    .trk-fedit-name {
        display: grid;
        gap: 4px;
        margin: 10px 0
    }

    .trk-mono {
        font-family: ui-monospace, Consolas, monospace;
        font-size: .8rem
    }

    .trk-group {
        border-top: 1px solid #eee;
        padding: 12px 0;
        display: grid;
        gap: 8px
    }

    .trk-group h3 {
        margin: 0;
        font-size: .95rem
    }

    .trk-two {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px
    }

    .trk-two label {
        display: grid;
        gap: 4px;
        font-size: .85rem
    }

    .trk-rows {
        display: grid;
        gap: 10px
    }

    .trk-rule {
        display: grid;
        grid-template-columns: minmax(140px, 1.2fr) minmax(110px, .8fr) minmax(120px, .8fr) minmax(200px, 2fr) auto;
        gap: 8px;
        align-items: start;
        padding: 10px;
        border: 1px solid #eee;
        border-radius: 10px
    }

    .trk-rule label {
        display: grid;
        gap: 3px;
        font-size: .75rem;
        color: #666
    }

    .trk-rule .trk-fixed {
        align-self: end;
        font-size: .85rem;
        color: #555
    }

    .trk-pick {
        display: flex;
        gap: 6px;
        flex-wrap: wrap
    }

    .trk-pick input,
    .trk-pick textarea {
        flex: 1 1 140px;
        min-width: 0
    }

    .trk-pick-note {
        font-size: .75rem;
        color: #2e7d4f
    }

    .trk-rule:not([data-kind="click"]) .trk-selcell {
        visibility: hidden
    }

    .trk-rule.trk-flash {
        background: #eef8f1
    }

    .trk-row {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 8px;
        align-items: center
    }

    @media (max-width: 800px) {
        .trk-rule {
            grid-template-columns: 1fr 1fr
        }

        .trk-selcell {
            grid-column: 1 / span 2
        }
    }
</style>
<div class="trk-wrap">
    <?php if ($message !== ''): ?><div class="alert" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($dataError !== ''): ?><div class="alert danger" role="alert"><?= e($dataError) ?></div><?php endif; ?>
    <?php if (!$config['enabled']): ?><div class="alert" role="status">Tracking đang TẮT (enabled = false trong cấu hình). Web không ghi thêm log.</div><?php endif; ?>

    <form class="trk-card trk-filter" method="get">
        <label>Từ ngày<input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>Đến ngày<input type="date" name="to" value="<?= e($to) ?>"></label>
        <button class="btn primary" type="submit">Xem</button>
        <small class="field-hint">Giờ Việt Nam. Log thô giữ <?= (int)$config['retention_days'] ?> ngày.</small>
        <span class="trk-live" id="trk-live" <?= ($to === $today && $config['live_refresh_seconds'] > 0) ? 'data-live-on="1" data-live-seconds="' . (int)$config['live_refresh_seconds'] . '"' : 'hidden' ?>>● Trực tiếp <small id="trk-live-time"></small></span>
        <button class="btn trk-open-config" type="button" id="trk-open-config">⚙ Cấu hình ghi log <span class="trk-state <?= $config['enabled'] ? 'on' : 'off' ?>"><?= $config['enabled'] ? 'Đang bật' : 'Đang tắt' ?></span></button>
    </form>

    <section class="trk-card ga-kpis" data-live="kpis">
        <div class="ga-kpi"><span>Số lượt xem trang</span><strong><?= dash_short($pageViews) ?></strong></div>
        <div class="ga-kpi"><span>Số lượt bấm nút</span><strong><?= dash_short($clicks) ?></strong></div>
        <div class="ga-kpi"><span>Số thiết bị khác nhau</span><strong><?= dash_short((int)$unique['devices']) ?></strong></div>
        <div class="ga-kpi"><span>Số tài khoản đăng nhập</span><strong><?= dash_short((int)$unique['members']) ?></strong></div>
    </section>

    <?php
    $funnelOptions = [];
    foreach ($config['rules'] as $item) {
        $funnelOptions[$item['code']] = $item['label'];
    }
    $renderFunnelRow = function (string $code) use ($funnelOptions): string {
        ob_start(); ?>
        <tr class="trk-funnel-row">
            <td>
                <select name="steps[]">
                    <?php foreach ($funnelOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= $value === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </td>
            <td class="trk-funnel-actions"><button type="button" class="btn small" data-up aria-label="Lên">↑</button> <button type="button" class="btn small" data-remove aria-label="Xóa bước">Xóa</button></td>
        </tr>
    <?php return (string)ob_get_clean();
    };
    $openFunnel = (string)($_GET['open'] ?? '');
    ?>
    <div id="trk-funnels">
        <section class="trk-card trk-slider" id="trk-slider" data-open="<?= e($openFunnel) ?>">
            <div class="trk-slider-nav">
                <button type="button" class="btn small" data-slide-prev aria-label="Phễu trước">‹</button>
                <div class="trk-slider-tabs">
                    <?php foreach ($config['funnels'] as $tabIndex => $tabFunnel): ?><button type="button" class="trk-tab<?= $tabIndex === 0 ? ' active' : '' ?>" data-slide-to="<?= $tabIndex ?>" data-fid="<?= e($tabFunnel['id']) ?>"><?= e($tabFunnel['name']) ?></button><?php endforeach; ?>
                </div>
                <button type="button" class="btn small" data-slide-next aria-label="Phễu sau">›</button>
            </div>
            <div class="trk-track" id="trk-track">
                <?php foreach ($config['funnels'] as $funnelConfig): ?>
                    <?php
                    $steps = [];
                    foreach ($funnel as $computed) {
                        if ($computed['id'] === $funnelConfig['id']) {
                            $steps = $computed['steps'];
                        }
                    }
                    ?>
                    <?php
                    // Tỷ lệ thoát của một bước: phần thiết bị ở bước này mà không sang bước kế tiếp.
                    $maxEvents = 0;
                    $maxDevices = 0;
                    $maxExit = 0.0;
                    foreach ($steps as $index => $step) {
                        $next = $steps[$index + 1] ?? null;
                        $steps[$index]['exit_rate'] = ($next !== null && $step['events'] > 0) ? max(0, round(($step['events'] - $next['events']) * 100 / $step['events'], 1)) : null;
                        $maxEvents = max($maxEvents, $step['events']);
                        $maxDevices = max($maxDevices, $step['devices']);
                        $maxExit = max($maxExit, (float)$steps[$index]['exit_rate']);
                    }
                    ?>
                    <div class="trk-slide trk-funnel">
                        <div class="ga-head">
                            <h2><?= e($funnelConfig['name']) ?></h2>
                            <button type="button" class="btn small" data-edit-funnel>Sửa phễu</button>
                        </div>
                        <?php if (!$steps): ?><p>Phễu này chưa có bước nào. Bấm "Sửa phễu" để chọn các bước.</p><?php else: ?>
                            <div class="trk-scroll" data-live="funnel-<?= e($funnelConfig['id']) ?>">
                                <?= dash_funnel_chart($steps, (int)$config['exit_warn_percent']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="trk-fedit" <?= $openFunnel === $funnelConfig['id'] ? '' : 'hidden' ?>>
                            <form method="post" class="trk-funnel-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="save_funnel">
                                <input type="hidden" name="fid" value="<?= e($funnelConfig['id']) ?>">
                                <label class="trk-fedit-name">Tên phễu
                                    <input type="text" name="name" maxlength="60" value="<?= e($funnelConfig['name']) ?>">
                                </label>
                                <div class="trk-scroll">
                                    <table class="trk-table">
                                        <thead>
                                            <tr>
                                                <th>Bước (chọn thao tác, theo thứ tự khách đi qua)</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody class="trk-funnel-body">
                                            <?php foreach ($funnelConfig['steps'] as $code): ?><?= $renderFunnelRow($code) ?><?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p>
                                    <button type="button" class="btn small" data-add-step>+ Thêm bước</button>
                                    <button type="submit" class="btn primary">Lưu phễu</button>
                                </p>
                            </form>
                            <form method="post" onsubmit="return confirm('Xóa phễu này?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="delete_funnel">
                                <input type="hidden" name="fid" value="<?= e($funnelConfig['id']) ?>">
                                <button type="submit" class="btn small">Xóa phễu</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="trk-slider-foot">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="add_funnel">
                    <button type="submit" class="btn primary">+ Thêm phễu</button>
                </form>
                <small class="field-hint">Mỗi lượt là một hành trình đi đúng thứ tự các bước (bấm lặp lại cùng một bước không tính thêm; nếu khách nhảy cóc tới bước sau thì các bước ở giữa vẫn tính là đã đi qua), nên số lượt luôn giảm dần qua các bước; tỷ lệ thoát tính theo số lượt này. Đường là số thiết bị khác nhau đã đi tới bước. Thao tác mới thêm ở phần cấu hình bên dưới cần lưu trước thì mới chọn được ở đây.</small>
            </div>
        </section>
        <table style="display:none">
            <template id="trk-tpl-funnel"><?= $renderFunnelRow('') ?></template>
        </table>
    </div>
    <script>
        (function() {
            var root = document.getElementById('trk-funnels');
            if (!root) return;

            // Slider các phễu: mũi tên, nhãn tên phễu, vuốt ngang; phễu vừa tạo tự được mở.
            var track = document.getElementById('trk-track');
            var tabs = Array.prototype.slice.call(root.querySelectorAll('.trk-tab'));
            var current = 0;
            var goTo = function(index, instant) {
                if (!track || !tabs.length) return;
                index = Math.max(0, Math.min(tabs.length - 1, index));
                track.style.scrollBehavior = instant ? 'auto' : '';
                track.scrollTo({
                    left: index * track.clientWidth
                });
                setActive(index);
            };
            var setActive = function(index) {
                current = index;
                tabs.forEach(function(tab, i) {
                    tab.classList.toggle('active', i === index);
                });
                if (tabs[index] && tabs[index].scrollIntoView) tabs[index].scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest'
                });
            };
            if (track && tabs.length) {
                track.addEventListener('scroll', function() {
                    var index = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
                    if (index !== current) setActive(index);
                });
                window.addEventListener('resize', function() {
                    goTo(current, true);
                });
                var openId = document.getElementById('trk-slider').getAttribute('data-open');
                tabs.forEach(function(tab, i) {
                    if (openId && tab.getAttribute('data-fid') === openId) goTo(i, true);
                });
            }

            root.addEventListener('click', function(event) {
                var target = event.target;
                if (!target.closest) return;
                if (target.closest('[data-slide-prev]')) {
                    goTo(current - 1);
                    return;
                }
                if (target.closest('[data-slide-next]')) {
                    goTo(current + 1);
                    return;
                }
                var tab = target.closest('[data-slide-to]');
                if (tab) {
                    goTo(parseInt(tab.getAttribute('data-slide-to'), 10));
                    return;
                }
                if (target.closest('[data-edit-funnel]')) {
                    var panel = target.closest('.trk-funnel').querySelector('.trk-fedit');
                    if (panel) panel.hidden = !panel.hidden;
                    return;
                }
                if (target.closest('[data-add-step]')) {
                    var form = target.closest('form');
                    var body = form && form.querySelector('.trk-funnel-body');
                    var template = document.getElementById('trk-tpl-funnel');
                    if (body && template) body.appendChild(template.content.cloneNode(true));
                    return;
                }
                var row = target.closest('.trk-funnel-row');
                if (!row) return;
                if (target.closest('[data-remove]')) {
                    row.remove();
                } else if (target.closest('[data-up]') && row.previousElementSibling) {
                    row.parentNode.insertBefore(row, row.previousElementSibling);
                }
            });
        })();
    </script>

    <section class="trk-card">
        <div class="ga-head">
            <h2 style="margin:0">Chi tiết theo trang / chức năng</h2>
            <a class="btn small" href="<?= e('?' . http_build_query(['export' => 'detail', 'from' => $from, 'to' => $to])) ?>">Xuất Excel</a>
        </div>
        <div class="trk-scroll" data-live="detail">
            <table class="trk-table">
                <thead>
                    <tr>
                        <th>Loại</th>
                        <th>Trang / chức năng</th>
                        <th class="num">Lượt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (dash_detail_rows($totals) as $row): ?>
                        <tr<?= (int)$row['total'] === 0 ? ' class="trk-zero"' : '' ?>>
                            <td><?= (int)$row['event_type'] === 1 ? 'Xem trang' : 'Bấm nút' ?></td>
                            <td><?= e(dash_event_label($row)) ?></td>
                            <td class="num"><?= number_format((int)$row['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$config['rules']): ?><tr>
                                <td colspan="3">Chưa khai báo thao tác nào.</td>
                            </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="trk-card">
        <h2>Theo từng ngày</h2>
        <div class="trk-scroll" data-live="daily">
            <table class="trk-table">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th class="num">Lượt xem trang</th>
                        <th class="num">Lượt bấm nút</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($series as $row): ?>
                        <tr>
                            <td><?= e(date('d/m/Y', strtotime($row['stat_date']))) ?></td>
                            <td class="num"><?= number_format((int)$row['page_views']) ?></td>
                            <td class="num"><?= number_format((int)$row['clicks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$series): ?><tr>
                            <td colspan="3">Chưa có dữ liệu.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script>
        // Cập nhật số liệu gần thời gian thực: cứ 10 giây tải lại số liệu và thay đúng các khối số liệu,
        // không động tới popup cấu hình hay phần Sửa phễu đang mở. Chỉ chạy khi đang xem tới hôm nay.
        (function() {
            var badge = document.getElementById('trk-live');
            if (!badge || !badge.hasAttribute('data-live-on') || !window.fetch || !window.DOMParser) return;
            var stamp = document.getElementById('trk-live-time');
            var busy = false;
            var INTERVAL = (parseInt(badge.getAttribute('data-live-seconds'), 10) || 10) * 1000;

            var sortState = function(box) {
                var head = box.querySelector('th[data-dir]');
                return head ? {
                    key: head.getAttribute('data-sort'),
                    dir: head.getAttribute('data-dir')
                } : null;
            };

            var restoreSort = function(box, state) {
                if (!state) return;
                var head = box.querySelector('th[data-sort="' + state.key + '"]');
                if (!head) return;
                head.click(); // tăng dần
                if (state.dir === 'desc') head.click(); // giảm dần
            };

            var refresh = function() {
                if (busy || document.hidden) return;
                busy = true;
                fetch(window.location.href, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'text/html'
                        }
                    })
                    .then(function(response) {
                        if (!response.ok) throw new Error('http ' + response.status);
                        return response.text();
                    })
                    .then(function(text) {
                        var next = new DOMParser().parseFromString(text, 'text/html');
                        document.querySelectorAll('[data-live]').forEach(function(box) {
                            var fresh = next.querySelector('[data-live="' + box.getAttribute('data-live') + '"]');
                            if (!fresh || fresh.innerHTML === box.innerHTML) return;
                            var state = sortState(box);
                            box.innerHTML = fresh.innerHTML;
                            restoreSort(box, state);
                        });
                        var now = new Date();
                        stamp.textContent = 'cập nhật ' + now.toLocaleTimeString('vi-VN');
                        badge.className = 'trk-live';
                    })
                    .catch(function() {
                        badge.className = 'trk-live fail';
                        stamp.textContent = 'mất kết nối, đang thử lại';
                    })
                    .then(function() {
                        busy = false;
                    });
            };

            setInterval(refresh, INTERVAL);
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    badge.className = 'trk-live paused';
                    stamp.textContent = 'tạm dừng (tab đang ẩn)';
                } else {
                    refresh();
                }
            });
        })();
    </script>

    <!-- <section class="trk-card">
        <h2>Tra cứu thao tác cuối</h2>
        <form class="trk-filter" method="get">
            <input type="hidden" name="from" value="<?= e($from) ?>">
            <input type="hidden" name="to" value="<?= e($to) ?>">
            <label>Mã thiết bị (32 ký tự)<input type="text" name="device" value="<?= e($lookupDevice) ?>" maxlength="36" size="36" class="trk-mono"></label>
            <label>hoặc ID tài khoản<input type="number" name="member" value="<?= $lookupMember > 0 ? $lookupMember : '' ?>" min="1"></label>
            <button class="btn" type="submit">Tra cứu</button>
        </form>
        <?php if (($lookupDevice !== '' || $lookupMember > 0)): ?>
            <div class="trk-scroll" style="margin-top:12px">
                <table class="trk-table">
                    <thead>
                        <tr>
                            <th>Thời điểm</th>
                            <th>Thao tác</th>
                            <th>Tài khoản</th>
                            <th>Thiết bị</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lookup as $row): ?>
                            <tr>
                                <td><?= e(htk_vn_time((string)$row['occurred_at'])) ?></td>
                                <td><?= e(dash_event_label($row)) ?></td>
                                <td><?= $row['member_id'] !== null ? (int)$row['member_id'] : '—' ?></td>
                                <td class="trk-mono"><a href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'device' => strtolower((string)$row['device_hex'])])) ?>"><?= e(strtolower(substr((string)$row['device_hex'], 0, 8))) ?>…</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$lookup): ?><tr>
                                <td colspan="4">Không có log (hoặc đã quá thời hạn giữ).</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <small class="field-hint">Dòng đầu tiên là thao tác cuối. Hiển thị tối đa 50 thao tác gần nhất.</small>
        <?php endif; ?>
    </section> -->

    <?php
    $formConfig = $formConfig ?? $config;
    $icons = [
        'doc' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M3.5 1.5h6l3 3v10h-9z"/><path d="M5.5 8h5M5.5 10.5h5"/></svg>',
        'pointer' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M5 2v8l2-1.7 1.6 3.4 1.5-.7-1.5-3.3H11z"/></svg>',
        'bolt' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M9 1.5 3.5 9H8l-1 5.5L12.5 7H8z"/></svg>',
    ];
    $kindMeta = [
        'view' => ['Truy cập trang', 'doc', 'view'],
        'click' => ['Nhấn phần tử', 'pointer', 'click'],
        'minigame' => ['Sự kiện nghiệp vụ', 'bolt', 'biz'],
        'order' => ['Sự kiện nghiệp vụ', 'bolt', 'biz'],
    ];
    $globalDedupe = (int)($formConfig['dedupe_seconds'] ?? 60);
    $renderRule = function (array $item) use ($icons, $kindMeta, $globalDedupe): string {
        $item += ['enabled' => true, 'dedupe_seconds' => null];
        $meta = $kindMeta[$item['kind']] ?? $kindMeta['view'];
        $mode = $item['dedupe_seconds'] === null ? 'default' : ((int)$item['dedupe_seconds'] === 0 ? 'off' : 'custom');
        $dedupeText = $mode === 'default' ? ($globalDedupe > 0 ? 'Mặc định (' . $globalDedupe . ' giây)' : 'Mặc định (không chống trùng)') : ($mode === 'off' ? 'Không chống trùng' : (int)$item['dedupe_seconds'] . ' giây');
        ob_start(); ?>
        <div class="tc-row" data-kind="<?= e($item['kind']) ?>" data-group="<?= e($meta[2]) ?>" data-off="<?= $item['enabled'] ? '0' : '1' ?>">
            <input type="hidden" name="rules_code[]" value="<?= e($item['code']) ?>">
            <input type="hidden" name="rules_enabled[]" value="<?= $item['enabled'] ? '1' : '0' ?>" data-f="enabled">
            <div class="tc-main">
                <div class="tc-name"><strong data-s="label"><?= e($item['label'] !== '' ? $item['label'] : 'Chưa đặt tên') ?></strong><small class="trk-mono" data-s="url"><?= e($item['url']) ?></small></div>
                <div class="tc-kind"><span data-s="icon"><?= $icons[$meta[1]] ?></span><span data-s="kind"><?= e($meta[0]) ?></span></div>
                <div class="tc-dedupe" data-s="dedupe"><?= e($dedupeText) ?></div>
                <button type="button" class="tc-toggle" data-toggle aria-label="Bật / tắt thao tác">✓</button>
                <button type="button" class="tc-edit" data-edit aria-label="Sửa thao tác"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M3 17l1-4L14.5 2.5l2.5 2.5L6.5 15.5z"/><path d="M12.5 4.5l3 3"/></svg></button>
            </div>
            <dialog class="tc-edit-dialog">
                <h3>Sửa thao tác</h3>
                <label class="tc-field">Mô tả (hành vi)
                    <input type="text" name="rules_label[]" data-f="label" value="<?= e($item['label']) ?>" placeholder="Thêm nước vào giỏ" maxlength="100">
                </label>
                <label class="tc-field">URL
                    <input type="text" name="rules_url[]" data-f="url" value="<?= e($item['url']) ?>" placeholder="/product" maxlength="200" class="trk-mono">
                </label>
                <label class="tc-field">Loại sự kiện
                    <select name="rules_kind[]" data-f="kind">
                        <option value="view" <?= $item['kind'] === 'view' ? 'selected' : '' ?>>Truy cập trang</option>
                        <option value="click" <?= $item['kind'] === 'click' ? 'selected' : '' ?>>Nhấn vào phần tử</option>
                        <option value="minigame" <?= $item['kind'] === 'minigame' ? 'selected' : '' ?>>Chơi mini-game (tự đếm lượt lật thẻ đầu)</option>
                        <option value="order" <?= $item['kind'] === 'order' ? 'selected' : '' ?>>Đặt hàng thành công (server ghi)</option>
                    </select>
                </label>
                <div class="tc-field tc-selcell">
                    <span>Phần tử cần theo dõi (mỗi dòng một phần tử, bấm vào phần tử nào cũng tính 1 lượt)</span>
                    <div class="tc-pickline">
                        <textarea name="rules_selector[]" data-f="selector" rows="3" placeholder="Chưa chọn phần tử" class="trk-mono"><?= e($item['selector']) ?></textarea>
                        <button type="button" class="btn small" data-pick>Chọn trên web</button>
                    </div>
                    <span class="tc-pick-note" data-pick-note></span>
                </div>
                <label class="tc-field">Chống trùng
                    <select name="rules_dedupe_mode[]" data-f="mode">
                        <option value="default" <?= $mode === 'default' ? 'selected' : '' ?>>Theo cài đặt chung</option>
                        <option value="off" <?= $mode === 'off' ? 'selected' : '' ?>>Không chống trùng (ghi mọi lần)</option>
                        <option value="custom" <?= $mode === 'custom' ? 'selected' : '' ?>>Theo số giây riêng</option>
                    </select>
                </label>
                <label class="tc-field tc-dedupe-sec" <?= $mode === 'custom' ? '' : 'hidden' ?>>Số giây chống trùng
                    <input type="number" name="rules_dedupe_sec[]" data-f="sec" min="1" max="86400" value="<?= $mode === 'custom' ? (int)$item['dedupe_seconds'] : '' ?>">
                </label>
                <div class="tc-edit-actions">
                    <button type="button" class="btn" data-remove>Xóa thao tác</button>
                    <button type="button" class="btn primary" data-done>Xong</button>
                </div>
            </dialog>
        </div>
    <?php return (string)ob_get_clean();
    };
    $enabledCount = count(array_filter($formConfig['rules'], function ($item) {
        return !array_key_exists('enabled', $item) || !empty($item['enabled']);
    }));
    ?>
    <dialog id="trk-config-dialog" class="trk-dialog">
        <div class="tc-head">
            <div>
                <h2>Cấu hình ghi log</h2>
                <p>Quản lý hành vi và dữ liệu khách hàng</p>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="tc-status <?= !empty($formConfig['enabled']) ? '' : 'off' ?>" id="tc-status"><i></i><span><?= !empty($formConfig['enabled']) ? 'Đang hoạt động' : 'Đang tắt' ?></span></span>
                <button type="button" class="btn small" id="trk-close-config" aria-label="Đóng">✕</button>
            </div>
        </div>
        <?php if ($error !== '' && $formConfig !== null): ?><div class="alert danger" role="alert" style="margin-top:10px"><?= e($error) ?></div><?php endif; ?>
        <form method="post" id="trk-config-form" data-picker-url="<?= e(base_url('admin/dashboard.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save_config">

            <div class="tc-tabs" role="tablist">
                <button type="button" class="tc-tab active" data-tab="rules" role="tab">Thao tác ghi log</button>
                <button type="button" class="tc-tab" data-tab="general" role="tab">Cấu hình chung</button>
            </div>

            <div class="tc-panel" data-panel="rules">
                <div class="tc-stats">
                    <div><span>Tổng cấu hình</span><b id="tc-total"><?= count($formConfig['rules']) ?></b></div>
                    <div><span>Đang kích hoạt</span><b id="tc-on"><?= $enabledCount ?></b></div>
                    <div><span>Đã tắt</span><b id="tc-off"><?= count($formConfig['rules']) - $enabledCount ?></b></div>
                </div>
                <div class="tc-toolbar">
                    <input type="search" id="tc-search" placeholder="Tìm theo tên hoặc URL...">
                    <select id="tc-filter" aria-label="Lọc theo loại sự kiện">
                        <option value="all">Tất cả sự kiện</option>
                        <option value="view">Truy cập trang</option>
                        <option value="click">Nhấn phần tử</option>
                        <option value="biz">Sự kiện nghiệp vụ</option>
                    </select>
                    <div class="tc-toolbar-actions">
                        <button type="button" class="tc-black" data-add>＋ Thêm thao tác</button>
                        <label class="tc-outline" for="tc-import-file">Nhập JSON</label>
                        <details class="tc-export">
                            <summary class="tc-outline">Xuất cấu hình ▾</summary>
                            <div class="tc-export-menu">
                                <a href="?export=config&amp;format=xlsx">Excel (.xlsx)</a>
                                <a href="?export=config&amp;format=json">JSON (.json)</a>
                            </div>
                        </details>
                    </div>
                </div>
                <div class="tc-list-head"><span>Hành vi / URL</span><span>Loại sự kiện</span><span>Chống trùng</span><span>Bật</span><span></span></div>
                <div id="tc-rows">
                    <?php foreach ($formConfig['rules'] as $item): ?><?= $renderRule($item) ?><?php endforeach; ?>
                </div>
                <div class="tc-empty" id="tc-empty" hidden>Không có thao tác phù hợp.</div>
            </div>

            <div class="tc-panel" data-panel="general" hidden>
                <div class="trk-group">
                    <label class="toggle-row"><input type="checkbox" name="enabled" value="1" id="tc-enabled" <?= !empty($formConfig['enabled']) ? 'checked' : '' ?>> Bật ghi log</label>
                    <div class="trk-two">
                        <label>Giữ log thô (ngày)<input type="number" name="retention_days" min="1" max="3650" value="<?= (int)$formConfig['retention_days'] ?>"></label>
                        <label>Tối đa event mỗi thiết bị mỗi phút<input type="number" name="max_events_per_device_per_min" min="1" max="10000" value="<?= (int)$formConfig['max_events_per_device_per_min'] ?>"></label>
                        <label>Thời gian chống trùng chung (giây, 0 = tắt)<input type="number" name="dedupe_seconds" id="tc-global-dedupe" min="0" max="86400" value="<?= $globalDedupe ?>"></label>
                        <label>Cảnh báo tỷ lệ thoát từ (%, 0 = tắt)<input type="number" name="exit_warn_percent" min="0" max="100" value="<?= (int)($formConfig['exit_warn_percent'] ?? 30) ?>"></label>
                        <label>Tự cập nhật dashboard mỗi (giây, 0 = tắt)<input type="number" name="live_refresh_seconds" min="0" max="3600" value="<?= (int)($formConfig['live_refresh_seconds'] ?? 10) ?>"></label>
                    </div>
                </div>
            </div>

            <div class="trk-savebar">
                <button class="btn primary" type="submit">Lưu cấu hình</button>
                <button class="btn" type="button" id="trk-cancel-config">Đóng</button>
            </div>
        </form>

        <form method="post" enctype="multipart/form-data" id="tc-import-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="import_config">
            <input type="file" name="config_file" id="tc-import-file" accept=".json,application/json">
        </form>

        <template id="tc-tpl"><?= $renderRule(['code' => '', 'label' => '', 'url' => '', 'kind' => 'view', 'selector' => '', 'enabled' => true, 'dedupe_seconds' => null]) ?></template>
    </dialog>
    <script>
        (function() {
            var form = document.getElementById('trk-config-form');
            if (!form) return;
            var rowsBox = document.getElementById('tc-rows');
            var activeRow = null;
            var KIND = {
                view: ['Truy cập trang', 'doc', 'view'],
                click: ['Nhấn phần tử', 'pointer', 'click'],
                minigame: ['Sự kiện nghiệp vụ', 'bolt', 'biz'],
                order: ['Sự kiện nghiệp vụ', 'bolt', 'biz']
            };
            var ICON = <?= json_encode($icons, JSON_UNESCAPED_SLASHES) ?>;

            // ---- Popup cấu hình: mở bằng nút ở thanh trên, đóng bằng ✕ / Đóng / Esc.
            var dialog = document.getElementById('trk-config-dialog');
            var openButton = document.getElementById('trk-open-config');
            var openDialog = function() {
                if (dialog && !dialog.open) {
                    if (dialog.showModal) dialog.showModal();
                    else dialog.setAttribute('open', '');
                }
            };
            var closeDialog = function(box) {
                if (box.close) box.close();
                else box.removeAttribute('open');
            };
            if (openButton) openButton.addEventListener('click', openDialog);
            ['trk-close-config', 'trk-cancel-config'].forEach(function(id) {
                var button = document.getElementById(id);
                if (button) button.addEventListener('click', function() {
                    closeDialog(dialog);
                });
            });
            <?php if ($error !== '' && $formConfig !== null): ?>openDialog(); // lưu lỗi: mở lại để admin sửa tiếp
            <?php endif; ?>

            // ---- Nhập cấu hình từ JSON (ghi đè DB).
            var importFile = document.getElementById('tc-import-file');
            if (importFile) {
                importFile.addEventListener('change', function() {
                    if (!importFile.files.length) return;
                    if (confirm('Nhập file "' + importFile.files[0].name + '" sẽ GHI ĐÈ toàn bộ cấu hình ghi log hiện tại (thao tác, phễu, cài đặt chung). Tiếp tục?')) {
                        window.removeEventListener('beforeunload', warnLeave);
                        importFile.form.submit();
                    } else {
                        importFile.value = '';
                    }
                });
            }

            // ---- Thay đổi chưa lưu sẽ mất nếu rời trang: hỏi lại.
            var dirty = false;
            var markDirty = function() {
                dirty = true;
            };
            form.addEventListener('input', markDirty);
            form.addEventListener('change', markDirty);
            form.addEventListener('submit', function() {
                dirty = false;
            });
            var warnLeave = function(event) {
                if (!dirty) return;
                event.preventDefault();
                event.returnValue = '';
            };
            window.addEventListener('beforeunload', warnLeave);

            // ---- Hiển thị dòng tóm tắt theo giá trị trong popup sửa.
            var field = function(row, name) {
                return row.querySelector('[data-f="' + name + '"]');
            };
            var globalDedupe = function() {
                var input = document.getElementById('tc-global-dedupe');
                return input ? parseInt(input.value, 10) || 0 : 0;
            };
            var refreshRow = function(row) {
                var label = field(row, 'label').value.trim();
                var kind = field(row, 'kind').value;
                var meta = KIND[kind] || KIND.view;
                var mode = field(row, 'mode').value;
                var seconds = parseInt(field(row, 'sec').value, 10) || 0;
                var text;
                if (mode === 'off') text = 'Không chống trùng';
                else if (mode === 'custom') text = seconds + ' giây';
                else text = globalDedupe() > 0 ? 'Mặc định (' + globalDedupe() + ' giây)' : 'Mặc định (không chống trùng)';
                row.querySelector('[data-s="label"]').textContent = label !== '' ? label : 'Chưa đặt tên';
                row.querySelector('[data-s="url"]').textContent = field(row, 'url').value.trim();
                row.querySelector('[data-s="kind"]').textContent = meta[0];
                row.querySelector('[data-s="icon"]').innerHTML = ICON[meta[1]];
                row.querySelector('[data-s="dedupe"]').textContent = text;
                row.setAttribute('data-kind', kind);
                row.setAttribute('data-group', meta[2]);
                row.setAttribute('data-off', field(row, 'enabled').value === '1' ? '0' : '1');
                row.querySelector('.tc-dedupe-sec').hidden = mode !== 'custom';
            };
            var refreshAll = function() {
                rowsBox.querySelectorAll('.tc-row').forEach(refreshRow);
                applyFilter();
            };

            // ---- Thống kê + tìm kiếm + lọc.
            var updateStats = function() {
                var rows = rowsBox.querySelectorAll('.tc-row');
                var off = rowsBox.querySelectorAll('.tc-row[data-off="1"]').length;
                document.getElementById('tc-total').textContent = rows.length;
                document.getElementById('tc-on').textContent = rows.length - off;
                document.getElementById('tc-off').textContent = off;
            };
            var applyFilter = function() {
                var query = document.getElementById('tc-search').value.trim().toLowerCase();
                var group = document.getElementById('tc-filter').value;
                var shown = 0;
                rowsBox.querySelectorAll('.tc-row').forEach(function(row) {
                    var hay = (row.querySelector('[data-s="label"]').textContent + ' ' + row.querySelector('[data-s="url"]').textContent).toLowerCase();
                    var ok = (query === '' || hay.indexOf(query) !== -1) && (group === 'all' || row.getAttribute('data-group') === group);
                    row.hidden = !ok;
                    if (ok) shown++;
                });
                document.getElementById('tc-empty').hidden = shown !== 0;
                updateStats();
            };
            document.getElementById('tc-search').addEventListener('input', applyFilter);
            document.getElementById('tc-filter').addEventListener('change', applyFilter);
            // Ô Enter trong ô tìm kiếm không được gửi form.
            document.getElementById('tc-search').addEventListener('keydown', function(event) {
                if (event.key === 'Enter') event.preventDefault();
            });

            // ---- Tab.
            form.querySelectorAll('.tc-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    form.querySelectorAll('.tc-tab').forEach(function(other) {
                        other.classList.toggle('active', other === tab);
                    });
                    form.querySelectorAll('.tc-panel').forEach(function(panel) {
                        panel.hidden = panel.getAttribute('data-panel') !== tab.getAttribute('data-tab');
                    });
                });
            });

            // ---- Trạng thái chung (chấm xanh/đỏ) + chữ "Mặc định (N giây)" theo cài đặt chung.
            var enabledBox = document.getElementById('tc-enabled');
            enabledBox.addEventListener('change', function() {
                var status = document.getElementById('tc-status');
                status.classList.toggle('off', !enabledBox.checked);
                status.querySelector('span').textContent = enabledBox.checked ? 'Đang hoạt động' : 'Đang tắt';
            });
            document.getElementById('tc-global-dedupe').addEventListener('input', refreshAll);

            // ---- Click trong danh sách.
            form.addEventListener('click', function(event) {
                var target = event.target;
                if (!target.closest) return;
                if (target.closest('[data-add]')) {
                    var template = document.getElementById('tc-tpl');
                    rowsBox.appendChild(template.content.cloneNode(true));
                    var created = rowsBox.lastElementChild;
                    refreshRow(created);
                    applyFilter();
                    markDirty();
                    var box = created.querySelector('dialog');
                    if (box.showModal) box.showModal();
                    return;
                }
                var row = target.closest('.tc-row');
                if (!row) return;
                if (target.closest('[data-toggle]')) {
                    var flag = field(row, 'enabled');
                    flag.value = flag.value === '1' ? '0' : '1';
                    refreshRow(row);
                    updateStats();
                    markDirty();
                } else if (target.closest('[data-edit]')) {
                    var editor = row.querySelector('dialog');
                    if (editor.showModal) editor.showModal();
                    else editor.setAttribute('open', '');
                } else if (target.closest('[data-done]')) {
                    refreshRow(row);
                    applyFilter();
                    closeDialog(row.querySelector('dialog'));
                } else if (target.closest('[data-remove]')) {
                    if (!confirm('Xóa thao tác này?')) return;
                    closeDialog(row.querySelector('dialog'));
                    row.remove();
                    applyFilter();
                    markDirty();
                } else if (target.closest('[data-pick]')) {
                    var url = field(row, 'url').value.trim();
                    if (url === '') {
                        alert('Hãy nhập URL của trang trước.');
                        return;
                    }
                    activeRow = row;
                    window.open(form.getAttribute('data-picker-url') + '?open_picker=' + encodeURIComponent(url), 'htk-picker');
                }
            });

            form.addEventListener('change', function(event) {
                var row = event.target.closest && event.target.closest('.tc-row');
                if (row) {
                    refreshRow(row);
                    applyFilter();
                }
            });

            // Đóng popup sửa bằng Esc/✕ cũng cập nhật dòng tóm tắt.
            form.addEventListener('close', function(event) {
                var row = event.target.closest && event.target.closest('.tc-row');
                if (row) {
                    refreshRow(row);
                    applyFilter();
                }
            }, true);

            refreshAll();

            // ---- Tab công cụ chọn phần tử gửi selector về đây (cùng domain).
            window.addEventListener('message', function(event) {
                var data = event.data;
                if (event.origin !== window.location.origin || !data || data.type !== 'htk-pick' || !activeRow) return;
                var input = field(activeRow, 'selector');
                if (!input) return;
                // Một thao tác có thể gồm nhiều phần tử: mỗi lần chọn thêm một dòng (không trùng).
                var picked = String(data.selector || '').trim();
                var lines = input.value.split(/\r?\n/).map(function(line) {
                    return line.trim();
                }).filter(Boolean);
                if (picked !== '' && lines.indexOf(picked) === -1) lines.push(picked);
                input.value = lines.join('\n');
                markDirty();
                var note = activeRow.querySelector('[data-pick-note]');
                if (note) note.textContent = 'Đã thêm' + (data.text ? ' "' + data.text + '"' : ' phần tử') + ' (tổng ' + lines.length + ' phần tử, bấm vào phần tử nào cũng tính 1 lượt).';
                var label = field(activeRow, 'label');
                if (label && label.value.trim() === '' && data.text) label.value = 'Bấm ' + data.text;
                refreshRow(activeRow);
                activeRow.classList.add('trk-flash');
                setTimeout(function(row) {
                    row.classList.remove('trk-flash');
                }, 1500, activeRow);
                window.focus();
            });
        })();
    </script>
</div>
<?php admin_footer(); ?>