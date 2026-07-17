<?php
namespace AdorationScheduler\Admin\Pages;

if ( ! defined('ABSPATH') ) exit;

use AdorationScheduler\Domain\Repositories\ChapelsRepository;

class AddNewSchedulePage {

    public function render(): void {

        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Sorry, you are not allowed to access this page.'), 403 );
        }

        $notice = '';

        // Where to go back
        $back_url = add_query_arg(['page' => 'adoration_scheduler_schedules'], admin_url('admin.php'));

        // Optional notices from redirect
        $created = sanitize_text_field($_GET['created'] ?? '');
        if ($created === '0') {
            $notice = '<div class="notice notice-error"><p>Failed to create schedule. Please check required fields and try again.</p></div>';
        }

        // Load chapels for dropdown
        $chapels_repo = new ChapelsRepository();
        $default_chapel_id = (int) $chapels_repo->ensure_default_chapel_exists();
        $chapels = (array) $chapels_repo->list_active();

        // If somehow none are active, fall back to “all” so you can recover in UI
        if (empty($chapels)) {
            $chapels = (array) $chapels_repo->list_all();
        }

        // Sensible defaults (match what Basic Info expects)
        $default_slot_length = 60;
        $default_min_adorers = 1;
        $default_max_adorers = ''; // blank => NULL
        $default_is_overnight = 0;

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Add New Schedule', 'adoration-scheduler'); ?></h1>

            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <p>
                <a class="button" href="<?php echo esc_url($back_url); ?>">← Back to Schedules</a>
            </p>

            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="max-width: 720px; background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:6px;"
            >
                <input type="hidden" name="action" value="adoration_create_schedule">
                <?php wp_nonce_field('adoration_create_schedule'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="name"><?php esc_html_e('Name', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" name="name" id="name" required>
                        </td>
                    </tr>

                    <!-- ✅ NEW: Chapel -->
                    <tr>
                        <th scope="row">
                            <label for="chapel_id"><?php esc_html_e('Chapel', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <select name="chapel_id" id="chapel_id" required>
                                <?php if (empty($chapels)) : ?>
                                    <option value="<?php echo esc_attr((string)$default_chapel_id); ?>">
                                        <?php echo esc_html__('Main Chapel', 'adoration-scheduler'); ?>
                                    </option>
                                <?php else : ?>
                                    <?php foreach ($chapels as $c) :
                                        $cid  = (int)($c['id'] ?? 0);
                                        $cname = (string)($c['name'] ?? '');
                                        if ($cid <= 0 || $cname === '') continue;
                                    ?>
                                        <option value="<?php echo esc_attr((string)$cid); ?>" <?php selected($cid, $default_chapel_id); ?>>
                                            <?php echo esc_html($cname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('This schedule will be associated with this chapel/location.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Type', 'adoration-scheduler'); ?></th>
                        <td>
                            <select name="type" id="type">
                                <option value="event"><?php esc_html_e('Event (specific dates)', 'adoration-scheduler'); ?></option>
                                <option value="perpetual"><?php esc_html_e('Perpetual (recurring weekly hours, no end date)', 'adoration-scheduler'); ?></option>
                                <option value="monthly"><?php esc_html_e('Monthly (e.g. "First Friday of the month")', 'adoration-scheduler'); ?></option>
                            </select>
                            <p class="description" id="type_note_perpetual">
                                <?php esc_html_e('Perpetual schedules repeat every week indefinitely. After creating it, set your weekly hours on the "Weekly Hours" tab and assign standing adorers on the "Standing Commitments" tab — a background job keeps future dates generated automatically.', 'adoration-scheduler'); ?>
                            </p>
                            <p class="description" id="type_note_monthly">
                                <?php esc_html_e('Monthly schedules repeat on a specific weekday of the month (e.g. the 1st Friday, or the last Sunday) indefinitely. After creating it, set the pattern and hours on the "Monthly Occurrence" tab. Unlike Perpetual, each occurrence is its own one-time signup — nobody is auto-enrolled every month.', 'adoration-scheduler'); ?>
                            </p>
                            <p class="description" id="type_note_event">
                                <?php esc_html_e('Event schedules use specific calendar dates (below).', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Start/End Dates (event schedules only) -->
                    <tr class="row-event-only">
                        <th scope="row">
                            <label for="start_date"><?php esc_html_e('Start Date', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="date" class="regular-text" name="start_date" id="start_date">
                            <p class="description">
                                <?php esc_html_e('Optional. Used as the schedule date range on the schedule record, and to fill in Event Dates below.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr class="row-event-only">
                        <th scope="row">
                            <label for="end_date"><?php esc_html_e('End Date', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input type="date" class="regular-text" name="end_date" id="end_date">
                            <p class="description">
                                <?php esc_html_e('Optional. Leave blank for a single-day event.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Event dates (event schedules only) -->
                    <tr class="row-event-only" id="row_event_dates">
                        <th scope="row">
                            <label for="event_dates"><?php esc_html_e('Event Dates', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <p>
                                <button type="button" class="button" id="fill_event_dates_btn">
                                    <?php esc_html_e('Fill in every date from Start Date → End Date', 'adoration-scheduler'); ?>
                                </button>
                            </p>
                            <textarea
                                name="event_dates"
                                id="event_dates"
                                rows="5"
                                class="large-text code"
                                placeholder="<?php echo esc_attr("2026-01-04\n2026-01-05\n2026-01-06"); ?>"
                            ></textarea>
                            <p class="description">
                                <?php esc_html_e('One date per line (YYYY-MM-DD). These will be added as schedule dates after creation. Use the button above to auto-fill from the Start/End Date fields, then delete any dates you don’t need (e.g. skip a particular day).', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Overnight (event + monthly schedules — perpetual schedules pick this up
                         automatically via Quick Setup on the Weekly Hours tab) -->
                    <tr class="row-hide-for-perpetual">
                        <th scope="row">
                            <label for="is_overnight"><?php esc_html_e('Overnight', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="is_overnight"
                                    id="is_overnight"
                                    value="1"
                                    <?php checked(1, $default_is_overnight); ?>
                                >
                                <?php esc_html_e('This schedule runs overnight into the next day.', 'adoration-scheduler'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Enable this for schedules like “Friday 8:00 AM → Saturday 8:00 AM”.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'adoration-scheduler'); ?></th>
                        <td>
                            <select name="status">
                                <option value="draft"><?php esc_html_e('Draft', 'adoration-scheduler'); ?></option>
                                <option value="active"><?php esc_html_e('Active', 'adoration-scheduler'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Privacy', 'adoration-scheduler'); ?></th>
                        <td>
                            <select name="privacy_mode">
                                <option value="counts_only"><?php esc_html_e('Counts only', 'adoration-scheduler'); ?></option>
                                <option value="first_name_only"><?php esc_html_e('First name only', 'adoration-scheduler'); ?></option>
                                <option value="first_last_initial"><?php esc_html_e('First name + last initial', 'adoration-scheduler'); ?></option>
                                <option value="names"><?php esc_html_e('Names (admin only for now)', 'adoration-scheduler'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <!-- Defaults -->
                    <tr>
                        <th scope="row">
                            <label for="default_slot_length"><?php esc_html_e('Default Slot Length (minutes)', 'adoration-scheduler'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                class="small-text"
                                name="default_slot_length"
                                id="default_slot_length"
                                min="1"
                                step="1"
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
                                type="number"
                                class="small-text"
                                name="default_min_adorers"
                                id="default_min_adorers"
                                min="0"
                                step="1"
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
                                type="number"
                                class="small-text"
                                name="default_max_adorers"
                                id="default_max_adorers"
                                min="0"
                                step="1"
                                value="<?php echo esc_attr((string)$default_max_adorers); ?>"
                                placeholder="<?php echo esc_attr__('Leave blank for unlimited', 'adoration-scheduler'); ?>"
                            >
                            <p class="description">
                                <?php esc_html_e('How many people can sign up for the same hour. Leave this blank for unlimited signups per hour.', 'adoration-scheduler'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Create Schedule', 'adoration-scheduler'); ?>
                    </button>
                </p>

                <script>
                (function(){
                    const maxEl = document.getElementById('default_max_adorers');
                    if (maxEl) {
                        maxEl.addEventListener('input', function(){
                            if (maxEl.value === '') return;
                            const n = parseInt(maxEl.value, 10);
                            if (!isNaN(n) && n < 0) maxEl.value = '0';
                        });
                    }

                    const typeEl = document.getElementById('type');
                    const eventOnlyRows = document.querySelectorAll('.row-event-only');
                    const hideForPerpetualRows = document.querySelectorAll('.row-hide-for-perpetual');
                    const eventNote = document.getElementById('type_note_event');
                    const perpetualNote = document.getElementById('type_note_perpetual');
                    const monthlyNote = document.getElementById('type_note_monthly');

                    function toggleForType(){
                        if (!typeEl) return;
                        const isPerpetual = (typeEl.value === 'perpetual');
                        const isMonthly   = (typeEl.value === 'monthly');
                        const isRecurring = (isPerpetual || isMonthly);

                        // Start/End Date + Event Dates: only event schedules use specific calendar dates.
                        eventOnlyRows.forEach(function(row){
                            row.style.display = isRecurring ? 'none' : '';
                        });
                        // Overnight: event + monthly need it settable manually; perpetual picks it up
                        // automatically via Weekly Hours Quick Setup.
                        hideForPerpetualRows.forEach(function(row){
                            row.style.display = isPerpetual ? 'none' : '';
                        });

                        if (eventNote) eventNote.style.display = (typeEl.value === 'event') ? '' : 'none';
                        if (perpetualNote) perpetualNote.style.display = isPerpetual ? '' : 'none';
                        if (monthlyNote) monthlyNote.style.display = isMonthly ? '' : 'none';
                    }

                    if (typeEl) {
                        typeEl.addEventListener('change', toggleForType);
                        toggleForType();
                    }

                    // "Fill in every date from Start Date → End Date"
                    const fillBtn = document.getElementById('fill_event_dates_btn');
                    const startEl = document.getElementById('start_date');
                    const endEl = document.getElementById('end_date');
                    const datesEl = document.getElementById('event_dates');
                    const MAX_FILL_DAYS = 366;

                    if (fillBtn && startEl && endEl && datesEl) {
                        fillBtn.addEventListener('click', function(){
                            if (!startEl.value || !endEl.value) {
                                alert('Please set both a Start Date and an End Date first.');
                                return;
                            }

                            const start = new Date(startEl.value + 'T00:00:00');
                            const end = new Date(endEl.value + 'T00:00:00');

                            if (isNaN(start.getTime()) || isNaN(end.getTime())) {
                                alert('Please enter valid dates.');
                                return;
                            }
                            if (end < start) {
                                alert('End Date must be on or after Start Date.');
                                return;
                            }

                            const dayMs = 24 * 60 * 60 * 1000;
                            const dayCount = Math.round((end - start) / dayMs) + 1;

                            if (dayCount > MAX_FILL_DAYS) {
                                alert('That range is ' + dayCount + ' days — please fill in dates manually for ranges over ' + MAX_FILL_DAYS + ' days.');
                                return;
                            }

                            if (datesEl.value.trim() !== '') {
                                if (!confirm('This will replace the dates currently listed below. Continue?')) return;
                            }

                            const lines = [];
                            const cursor = new Date(start.getTime());
                            for (let i = 0; i < dayCount; i++) {
                                const y = cursor.getFullYear();
                                const m = String(cursor.getMonth() + 1).padStart(2, '0');
                                const d = String(cursor.getDate()).padStart(2, '0');
                                lines.push(y + '-' + m + '-' + d);
                                cursor.setDate(cursor.getDate() + 1);
                            }

                            datesEl.value = lines.join('\n');
                        });
                    }
                })();
                </script>
            </form>
        </div>
        <?php
    }
}
