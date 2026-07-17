<?php
namespace AdorationScheduler\Services;

if (!defined('ABSPATH')) exit;

/**
 * ToastService
 *
 * One-shot toast notifications for BOTH:
 * - Admin: ?as_toast=... on adoration_scheduler* pages -> cookie -> clean redirect -> render toast
 * - Frontend: ?as_toast=... anywhere on same host -> cookie -> clean redirect -> render toast
 *
 * Other services should redirect with:
 *   as_toast, as_toast_type, as_toast_sticky
 *
 * Types: success | error | warning | info
 */
class ToastService
{
    private const COOKIE_NAME = 'adoration_scheduler_toast';
    private const COOKIE_TTL_SECONDS = 30;
    private const MAX_MSG_LEN = 300;

    /** @return string[] */
    private static function allowed_types(): array
    {
        return ['success','error','warning','info'];
    }

    public static function register(): void
    {
        // Convert querystring toast -> cookie + redirect clean (admin + frontend)
        add_action('admin_init', [__CLASS__, 'maybe_redirect_toast'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_toast'], 1);

        // Render/enqueue toast JS/CSS (admin + frontend)
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_toast_assets'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_toast_assets'], 20);
    }

    /**
     * If URL has ?as_toast=... set a short-lived cookie and redirect to clean URL.
     * This prevents refresh/back from replaying the toast.
     */
    public static function maybe_redirect_toast(): void
    {
        if (!isset($_GET['as_toast']) || !is_string($_GET['as_toast'])) {
            return;
        }

        // Admin: limit to our plugin pages only (avoid hijacking other admin screens).
        if (is_admin()) {
            $page = isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '';
            if ($page === '' || strpos($page, 'adoration_scheduler') !== 0) {
                return;
            }
        }

        $payload = self::payload_from_query();
        if (!$payload) return;

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') return;

        $cookie_value = base64_encode($json);
        if (!is_string($cookie_value) || $cookie_value === '') return;

        $expire = time() + self::COOKIE_TTL_SECONDS;

        $secure = is_ssl();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        // Prefer WP cookie paths if available
        $path = '/';
        if (is_admin() && defined('ADMIN_COOKIE_PATH') && ADMIN_COOKIE_PATH) {
            $path = ADMIN_COOKIE_PATH;
        } elseif (defined('COOKIEPATH') && COOKIEPATH) {
            $path = COOKIEPATH;
        }

        // JS must read it (HttpOnly=false), but we still set SameSite=Lax.
        self::set_cookie(self::COOKIE_NAME, $cookie_value, $expire, $path, $domain, $secure, false);

        // Redirect to same URL but without toast params (and optionally older legacy flags)
        $url = self::current_url();
        $url = remove_query_arg(
            [
                'as_toast','as_toast_type','as_toast_sticky',
                // If you truly want "new only", you can remove these next ones.
                'adoration_msg','adoration_text','cancelled','cancel_error'
            ],
            $url
        );

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Enqueue inline CSS/JS and, if cookie payload exists, show the toast once.
     */
    public static function enqueue_toast_assets(): void
    {
        $toast = self::get_toast_from_cookie();

        $style_handle  = is_admin() ? 'adoration-scheduler-admin-toasts' : 'adoration-scheduler-frontend-toasts';
        $script_handle = is_admin() ? 'adoration-scheduler-admin-toasts' : 'adoration-scheduler-frontend-toasts';

        wp_register_style($style_handle, false, [], null);
        wp_enqueue_style($style_handle);

        $css = <<<CSS
#adoration-scheduler-toast-root{
    position: fixed;
    left: 50%;
    top: calc(var(--wp-admin--admin-bar--height, 0px) + 12px);
    transform: translateX(-50%);
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: min(720px, calc(100vw - 24px));
    pointer-events: none;
}
.adoration-toast{
    pointer-events: auto;
    background: #fff;
    border-left: 6px solid #2271b1;
    box-shadow: 0 10px 30px rgba(0,0,0,.18);
    border-radius: 12px;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    align-items: start;
    opacity: 0;
    transform: translateY(-10px);
    transition: opacity .18s ease-out, transform .18s ease-out;
}
.adoration-toast.is-visible{ opacity: 1; transform: translateY(0); }
.adoration-toast.is-hiding{ opacity: 0; transform: translateY(-10px); }
.adoration-toast__msg{
    font-size: 14px;
    line-height: 1.45;
    color: #1d2327;
    word-break: break-word;
}
.adoration-toast__btn{
    background: transparent;
    border: 0;
    padding: 2px 6px;
    cursor: pointer;
    color: #646970;
    font-size: 18px;
    line-height: 1;
}
.adoration-toast__btn:hover{ color:#1d2327; }
.adoration-toast--success{ border-left-color: #00a32a; }
.adoration-toast--error{ border-left-color: #d63638; }
.adoration-toast--warning{ border-left-color: #dba617; }
.adoration-toast--info{ border-left-color: #2271b1; }

@media (max-width: 782px){
    #adoration-scheduler-toast-root{ width: calc(100vw - 20px); }
}
CSS;

        wp_add_inline_style($style_handle, $css);

        wp_register_script($script_handle, '', [], null, true);
        wp_enqueue_script($script_handle);

        $payload_json = $toast ? wp_json_encode($toast) : 'null';

        $js = <<<JS
(function(){
    var EXIT_MS = 190;
    var ALLOWED = { success:1, error:1, warning:1, info:1 };

    function ensureRoot(){
        var root = document.getElementById('adoration-scheduler-toast-root');
        if (root) return root;
        root = document.createElement('div');
        root.id = 'adoration-scheduler-toast-root';
        document.body.appendChild(root);
        return root;
    }

    function removeWithAnimation(el){
        if (!el) return;
        if (el.classList && el.classList.contains('is-hiding')) return;
        el.classList.add('is-hiding');
        window.setTimeout(function(){
            if (el && el.parentNode) el.parentNode.removeChild(el);
        }, EXIT_MS);
    }

    function normalizeType(t){
        t = (t || 'info').toString().trim().toLowerCase();
        return ALLOWED[t] ? t : 'info';
    }

    function showToast(opts){
        opts = opts || {};
        var msg = (opts.message || '').toString().trim();
        if (!msg) return;

        var type = normalizeType(opts.type);
        var sticky = !!opts.sticky;

        var root = ensureRoot();

        var el = document.createElement('div');
        el.className = 'adoration-toast adoration-toast--' + type;

        var msgEl = document.createElement('div');
        msgEl.className = 'adoration-toast__msg';
        msgEl.textContent = msg;

        var btn = document.createElement('button');
        btn.className = 'adoration-toast__btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Dismiss notification');
        btn.innerHTML = '&times;';
        btn.addEventListener('click', function(){ removeWithAnimation(el); });

        el.appendChild(msgEl);
        el.appendChild(btn);
        root.appendChild(el);

        window.setTimeout(function(){ el.classList.add('is-visible'); }, 20);

        if (!sticky){
            window.setTimeout(function(){ removeWithAnimation(el); }, 5200);
        }
    }

    window.AdorationScheduler = window.AdorationScheduler || {};
    window.AdorationScheduler.toast = showToast;

    var initial = $payload_json;
    if (initial && initial.message){
        if (document.readyState === 'loading'){
            document.addEventListener('DOMContentLoaded', function(){ showToast(initial); });
        } else {
            showToast(initial);
        }
    }
})();
JS;

        wp_add_inline_script($script_handle, $js);
    }

    private static function payload_from_query(): ?array
    {
        $raw = $_GET['as_toast'] ?? '';
        if (!is_string($raw) || trim($raw) === '') return null;

        $msg = self::sanitize_toast_text($raw);
        if ($msg === '') return null;

        $type = isset($_GET['as_toast_type']) ? sanitize_key((string)$_GET['as_toast_type']) : 'success';
        $allowed = self::allowed_types();
        if (!in_array($type, $allowed, true)) $type = 'success';

        $sticky = isset($_GET['as_toast_sticky']) && (string)$_GET['as_toast_sticky'] === '1';

        return [
            'message' => $msg,
            'type'    => $type,
            'sticky'  => $sticky,
        ];
    }

    /**
     * Accepts either:
     * - raw text (recommended; add_query_arg will encode), OR
     * - already-encoded text (some callers might rawurlencode)
     *
     * We decode only if it *looks* encoded to avoid mangling '+' or '%' unexpectedly.
     */
    private static function sanitize_toast_text(string $raw): string
    {
        $raw = wp_unslash($raw);

        // Decode only if it looks like URL encoding.
        // Note: $_GET is already urldecoded by PHP, but some callers may double-encode.
        if (strpos($raw, '%') !== false) {
            $raw = rawurldecode($raw);
        }

        $raw = sanitize_text_field($raw);
        $raw = trim($raw);

        if (strlen($raw) > self::MAX_MSG_LEN) {
            $raw = substr($raw, 0, self::MAX_MSG_LEN);
        }

        return $raw;
    }

    private static function get_toast_from_cookie(): ?array
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) return null;

        $raw = $_COOKIE[self::COOKIE_NAME];
        if (!is_string($raw) || trim($raw) === '') return null;

        $secure = is_ssl();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        $path = '/';
        if (is_admin() && defined('ADMIN_COOKIE_PATH') && ADMIN_COOKIE_PATH) {
            $path = ADMIN_COOKIE_PATH;
        } elseif (defined('COOKIEPATH') && COOKIEPATH) {
            $path = COOKIEPATH;
        }

        // Clear immediately (one-shot)
        self::set_cookie(self::COOKIE_NAME, '', time() - 3600, $path, $domain, $secure, false);

        $json = base64_decode($raw, true);
        if (!is_string($json) || $json === '') return null;

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return null;

        $msg = isset($decoded['message']) ? sanitize_text_field((string)$decoded['message']) : '';
        $msg = trim($msg);
        if ($msg === '') return null;

        if (strlen($msg) > self::MAX_MSG_LEN) {
            $msg = substr($msg, 0, self::MAX_MSG_LEN);
        }

        $type = isset($decoded['type']) ? sanitize_key((string)$decoded['type']) : 'success';
        $allowed = self::allowed_types();
        if (!in_array($type, $allowed, true)) $type = 'success';

        $sticky = !empty($decoded['sticky']);

        return [
            'message' => $msg,
            'type'    => $type,
            'sticky'  => $sticky,
        ];
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $host   = is_string($host) ? $host : '';
        $uri    = is_string($uri) ? $uri : '';
        $url    = $scheme . '://' . $host . $uri;
        return esc_url_raw($url);
    }

    /**
     * Wrapper to set cookies with SameSite=Lax when available.
     */
    private static function set_cookie(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httponly): void
    {
        if (PHP_VERSION_ID >= 70300) {
            @setcookie($name, $value, [
                'expires'  => $expires,
                'path'     => $path ?: '/',
                'domain'   => $domain ?: '',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ]);
        } else {
            // Best-effort SameSite for older PHP
            $p = ($path ?: '/') . '; samesite=Lax';
            @setcookie($name, $value, $expires, $p, $domain ?: '', $secure, $httponly);
        }
    }
}
