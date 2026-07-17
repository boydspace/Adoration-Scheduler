<?php
/**
 * Tabs nav partial for EditSchedulePage
 *
 * Expected variables in scope (from EditSchedulePage.php):
 * - $tab (string)
 * - $base_args (array)
 * - $is_perpetual (bool)
 * - $is_monthly (bool)
 */

if ( ! defined('ABSPATH') ) exit;

$is_perpetual = isset($is_perpetual) && $is_perpetual;
$is_monthly   = isset($is_monthly) && $is_monthly;

$tabs = [
    'overview' => __('Overview', 'adoration-scheduler'),
    'basic'    => __('Basic Info', 'adoration-scheduler'),
];

if ($is_perpetual) {
    $tabs['weekly_hours'] = __('Weekly Hours', 'adoration-scheduler');
    $tabs['commitments']  = __('Standing Commitments', 'adoration-scheduler');
    $tabs['coverage']     = __('Coverage Calendar', 'adoration-scheduler');
} elseif ($is_monthly) {
    $tabs['monthly_occurrence'] = __('Monthly Occurrence', 'adoration-scheduler');
} else {
    $tabs['dates'] = __('Dates & Hours', 'adoration-scheduler');
}

$tabs['slots']   = __('Slots', 'adoration-scheduler');
$tabs['signups'] = __('Signups', 'adoration-scheduler');
$tabs['email']   = __('Email', 'adoration-scheduler');
?>

<h2 class="nav-tab-wrapper">
    <?php foreach ($tabs as $key => $label): ?>
        <?php
        $url = add_query_arg(array_merge($base_args, ['tab' => $key]), admin_url('admin.php'));
        $cls = 'nav-tab' . ($tab === $key ? ' nav-tab-active' : '');
        ?>
        <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($cls); ?>">
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</h2>
