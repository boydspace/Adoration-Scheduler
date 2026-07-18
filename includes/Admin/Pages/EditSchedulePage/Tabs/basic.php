<?php
/**
 * Tab: Basic Info
 *
 * Expected variables in scope:
 * - $schedule (array)
 * - $chapels_active (array)   // from EditSchedulePage controller
 * - $default_chapel_id (int)  // from EditSchedulePage controller
 */

if ( ! defined('ABSPATH') ) exit;

// Sensible display defaults if schedule doesn’t have these yet
$default_slot_length = (int)($schedule['default_slot_length'] ?? 60);
if ($default_slot_length <= 0) $default_slot_length = 60;

$default_min_adorers = (int)($schedule['default_min_adorers'] ?? 1);
if ($default_min_adorers < 0) $default_min_adorers = 0;

// Allow blank => unlimited (NULL)
$default_max_raw = $schedule['default_max_adorers'] ?? '';
$default_max_adorers = ($default_max_raw === null || $default_max_raw === '') ? '' : (string)(int)$default_max_raw;

// Step 1: Overnight flag (option-backed, merged into $schedule by controller)
$is_overnight = !empty($schedule['is_overnight']) ? 1 : 0;

// Chapel UI helpers (chapels repo list_active + default)
$chapels_active = isset($chapels_active) && is_array($chapels_active) ? $chapels_active : [];
$default_chapel_id = isset($default_chapel_id) ? (int)$default_chapel_id : 0;

$current_chapel_id = (int)($schedule['chapel_id'] ?? 0);
if ($current_chapel_id <= 0) $current_chapel_id = $default_chapel_id;

// Determine if current chapel exists in active list
$chapel_is_valid = false;
foreach ($chapels_active as $c) {
    if ((int)($c['id'] ?? 0) === $current_chapel_id) {
        $chapel_is_valid = true;
        break;
    }
}
if (!$chapel_is_valid && $default_chapel_id > 0) {
    $current_chapel_id = $default_chapel_id;
}

$chapels_count = count($chapels_active);

// ✅ Perpetual adoration: type is set at creation and locked afterward.
$schedule_type = (string)($schedule['type'] ?? 'event');
if ($schedule_type === '') $schedule_type = 'event';
$is_perpetual = ($schedule_type === 'perpetual');
$is_monthly   = ($schedule_type === 'monthly');

$rolling_window_days = (int)($schedule['rolling_window_days'] ?? 60);
if ($rolling_window_days <= 0) $rolling_window_days = 60;
?>

<h2><?php esc_html_e('Basic Info', 'adoration-scheduler'); ?></h2>

<form method="post" style="max-width: 820px;">
    <?php wp_nonce_field('adoration_save_basic_info'); ?>

    <table class="form-table" role="presentation">

        <!-- ✅ Type (locked after creation) -->
        <tr>
            <th scope="row"><?php esc_html_e('Type', 'adoration-scheduler'); ?></th>
            <td>
                <strong>
                    <?php
                    if ($is_perpetual) {
                        echo esc_html__('Perpetual (recurring weekly hours)', 'adoration-scheduler');
                    } elseif ($is_monthly) {
                        echo esc_html__('Monthly (recurring nth-weekday-of-month)', 'adoration-scheduler');
                    } else {
                        echo esc_html__('Event (specific dates)', 'adoration-scheduler');
                    }
                    ?>
                </strong>
                <p class="description">
                    <?php esc_html_e('Set when the schedule was created and cannot be changed. Create a new schedule if you need a different type.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <?php if ($is_perpetual || $is_monthly): ?>
        <!-- ✅ Perpetual/Monthly: rolling window -->
        <tr>
            <th scope="row">
                <label for="rolling_window_days"><?php esc_html_e('Rolling Window (days ahead)', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="rolling_window_days"
                    id="rolling_window_days"
                    type="number"
                    min="1"
                    max="366"
                    step="1"
                    class="small-text"
                    value="<?php echo esc_attr((string)$rolling_window_days); ?>"
                >
                <p class="description">
                    <?php if ($is_monthly): ?>
                        <?php esc_html_e('How many days ahead the daily background job keeps dated occurrences generated for this schedule. Since occurrences are monthly, a larger window (e.g. 180 = ~6 months) shows further ahead than the perpetual default of 60.', 'adoration-scheduler'); ?>
                    <?php else: ?>
                        <?php esc_html_e('How many days ahead the daily background job keeps dated slots generated for this schedule (e.g. 60 = always have the next 60 days ready to sign up for).', 'adoration-scheduler'); ?>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <?php endif; ?>

        <!-- ✅ NEW: Chapel / Location -->
        <tr>
            <th scope="row">
                <label for="chapel_id"><?php esc_html_e('Chapel / Location', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <?php if ($chapels_count <= 1): ?>
                    <?php
                    // If there is only one chapel, keep UI simple but still submit the field.
                    $only_id = $default_chapel_id;
                    if ($chapels_count === 1) {
                        $only_id = (int)($chapels_active[0]['id'] ?? $default_chapel_id);
                    }
                    ?>
                    <input type="hidden" name="chapel_id" value="<?php echo esc_attr((string)$only_id); ?>">
                    <span>
                        <?php
                        $label = '';
                        if ($chapels_count === 1) {
                            $label = (string)($chapels_active[0]['name'] ?? '');
                        }
                        if ($label === '') $label = __('Main Chapel', 'adoration-scheduler');
                        echo esc_html($label);
                        ?>
                    </span>
                    <p class="description">
                        <?php esc_html_e('Only one active chapel is available.', 'adoration-scheduler'); ?>
                    </p>
                <?php else: ?>
                    <select name="chapel_id" id="chapel_id">
                        <?php foreach ($chapels_active as $c): ?>
                            <?php
                            $cid = (int)($c['id'] ?? 0);
                            $cname = (string)($c['name'] ?? '');
                            if ($cid <= 0) continue;
                            ?>
                            <option value="<?php echo esc_attr((string)$cid); ?>" <?php selected($current_chapel_id, $cid); ?>>
                                <?php echo esc_html($cname !== '' ? $cname : ('Chapel #' . $cid)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Choose where this schedule takes place.', 'adoration-scheduler'); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="name"><?php esc_html_e('Name', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="name"
                    id="name"
                    type="text"
                    class="regular-text"
                    value="<?php echo esc_attr((string)($schedule['name'] ?? '')); ?>"
                    required
                >
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="slug"><?php esc_html_e('Slug', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="slug"
                    id="slug"
                    type="text"
                    class="regular-text"
                    value="<?php echo esc_attr((string)($schedule['slug'] ?? '')); ?>"
                >
                <p class="description">
                    <?php esc_html_e('Used in the shortcode. Leave blank to auto-generate from name.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
            <td>
                <select name="status">
                    <option value="draft" <?php selected((string)($schedule['status'] ?? ''), 'draft'); ?>>
                        <?php esc_html_e('Draft', 'adoration-scheduler'); ?>
                    </option>
                    <option value="active" <?php selected((string)($schedule['status'] ?? ''), 'active'); ?>>
                        <?php esc_html_e('Active', 'adoration-scheduler'); ?>
                    </option>
                </select>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e('Privacy', 'adoration-scheduler'); ?></th>
            <td>
                <select name="privacy_mode">
                    <option value="counts_only" <?php selected((string)($schedule['privacy_mode'] ?? ''), 'counts_only'); ?>>
                        <?php esc_html_e('Counts only', 'adoration-scheduler'); ?>
                    </option>
                    <option value="first_name_only" <?php selected((string)($schedule['privacy_mode'] ?? ''), 'first_name_only'); ?>>
                        <?php esc_html_e('First name only', 'adoration-scheduler'); ?>
                    </option>
                    <option value="first_last_initial" <?php selected((string)($schedule['privacy_mode'] ?? ''), 'first_last_initial'); ?>>
                        <?php esc_html_e('First name + last initial', 'adoration-scheduler'); ?>
                    </option>
                    <option value="names" <?php selected((string)($schedule['privacy_mode'] ?? ''), 'names'); ?>>
                        <?php esc_html_e('Full names', 'adoration-scheduler'); ?>
                    </option>
                </select>
                <p class="description">
                    <?php esc_html_e('Controls what the public schedule page shows next to each hour: nothing but open/full counts, first name only, first name + last initial, or full names.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="start_date"><?php esc_html_e('Start Date', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="start_date"
                    id="start_date"
                    type="date"
                    value="<?php echo esc_attr((string)($schedule['start_date'] ?? '')); ?>"
                >
                <p class="description">
                    <?php esc_html_e('Optional. Used for event schedules (specific dates).', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="end_date"><?php esc_html_e('End Date', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="end_date"
                    id="end_date"
                    type="date"
                    value="<?php echo esc_attr((string)($schedule['end_date'] ?? '')); ?>"
                >
            </td>
        </tr>

        <!-- ✅ Step 1: Overnight UI + saved flag -->
        <tr>
            <th scope="row"><?php esc_html_e('Overnight', 'adoration-scheduler'); ?></th>
            <td>
                <label>
                    <input
                        type="checkbox"
                        name="is_overnight"
                        value="1"
                        <?php checked($is_overnight, 1); ?>
                    >
                    <?php esc_html_e('This schedule runs overnight into the next day.', 'adoration-scheduler'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Enable this for schedules like “Friday 8:00 AM → Saturday 8:00 AM.”', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="default_slot_length"><?php esc_html_e('Default Slot Length (minutes)', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="default_slot_length"
                    id="default_slot_length"
                    type="number"
                    min="5"
                    step="5"
                    class="small-text"
                    value="<?php echo esc_attr((string)$default_slot_length); ?>"
                >
                <p class="description">
                    <?php esc_html_e('Used when a segment does not specify its own slot length.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="default_min_adorers"><?php esc_html_e('Default Minimum Adorers', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="default_min_adorers"
                    id="default_min_adorers"
                    type="number"
                    min="0"
                    step="1"
                    class="small-text"
                    value="<?php echo esc_attr((string)$default_min_adorers); ?>"
                >
                <p class="description">
                    <?php esc_html_e('Applied to newly generated slots unless a segment overrides it.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="default_max_adorers"><?php esc_html_e('Default Maximum Adorers', 'adoration-scheduler'); ?></label>
            </th>
            <td>
                <input
                    name="default_max_adorers"
                    id="default_max_adorers"
                    type="number"
                    min="0"
                    step="1"
                    class="small-text"
                    value="<?php echo esc_attr($default_max_adorers); ?>"
                    placeholder=""
                >
                <p class="description">
                    <?php esc_html_e('How many people can sign up for the same hour. Leave this blank for unlimited signups per hour.', 'adoration-scheduler'); ?>
                </p>
            </td>
        </tr>
    </table>

    <p>
        <button type="submit" name="adoration_save_basic_info" class="button button-primary">
            <?php esc_html_e('Save Basic Info', 'adoration-scheduler'); ?>
        </button>
    </p>
</form>
