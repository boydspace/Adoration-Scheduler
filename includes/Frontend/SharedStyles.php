<?php
namespace AdorationScheduler\Frontend;

if ( ! defined('ABSPATH') ) exit;

/**
 * Shared CSS for the modular "My Adoration" front-end shortcodes
 * (MyScheduleShortcode, NeededReplacementsShortcode, ProfileCardShortcode,
 * AccountStatusShortcode, MyReplacementRequestsShortcode,
 * NextAdorationHourShortcode). Extracted from the original monolithic
 * MyAdorationShortcode so a page combining several of these widgets doesn't
 * get the same <style> block repeated once per shortcode — mirrors
 * UikitLoader's print-once pattern for exactly the same reason.
 */
class SharedStyles
{
    private static bool $printed = false;

    public static function print_once(): string
    {
        if (self::$printed) return '';
        self::$printed = true;

        ob_start();
        ?>
        <style>
        .adoration-widget { width: 100% !important; max-width: none !important; }

        /* Fallback buttons (if UIkit isn't present) */
        .adoration-btn {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #2271b1;
            background: #2271b1;
            color: #fff;
            cursor: pointer;
            font-size: 13px;
            line-height: 1.4;
            text-decoration: none;
            /* ✅ Accessibility (2026-07-18): keep tap targets close to the
               44px guideline even at this compact font size. */
            min-height: 40px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .adoration-btn[disabled], .adoration-btn.is-disabled { opacity: .55; cursor: not-allowed; }
        .adoration-btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #dcdcde;
            background: #f6f7f7;
            color: #1d2327;
            cursor: pointer;
            font-size: 13px;
            line-height: 1.4;
            text-decoration: none;
            min-height: 40px;
            box-sizing: border-box;
        }
        .adoration-btn-secondary:hover { background: #f0f0f1; }
        .adoration-btn-danger { border-color: #d63638; background: #d63638; }

        /* Make tables behave nicely even without UIkit */
        table.adoration-table {
            width: 100% !important;
            max-width: none !important;
            border-collapse: collapse;
            table-layout: auto;
            margin: 0 0 10px 0;
        }
        table.adoration-table th,
        table.adoration-table td {
            border: 1px solid #dcdcde;
            padding: 10px 12px;
            vertical-align: top;
        }
        table.adoration-table th {
            background: #f6f7f7;
            text-align: left;
            font-weight: 600;
        }

        /* Small helper text */
        .as-muted { opacity: .85; }

        /* Fallback (non-UIkit JS) modal container styles */
        .as-modal { display: none; }
        .as-modal.is-open { display: block; }
        .as-modal__backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 9998;
        }
        .as-modal__panel {
            position: fixed;
            left: 50%;
            top: 10%;
            transform: translateX(-50%);
            width: min(680px, calc(100% - 32px));
            background: #fff;
            border-radius: 10px;
            z-index: 9999;
            box-shadow: 0 10px 30px rgba(0,0,0,.25);
            padding: 0;
        }
        .as-modal__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid #eee;
        }
        .as-modal__title { margin: 0; font-size: 18px; }
        .as-modal__close {
            background: none;
            border: 0;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            /* ✅ Accessibility (2026-07-18): the glyph itself is small, but
               the clickable area shouldn't be — pad it out toward the 44px
               tap-target guideline instead of relying on the font size. */
            min-width: 40px;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .as-modal__body { padding: 16px; }
        .as-modal__actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 14px;
        }
        </style>
        <?php
        return (string) ob_get_clean();
    }
}
