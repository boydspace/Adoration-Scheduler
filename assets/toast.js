(function () {
  function getCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function deleteCookie(name) {
    document.cookie = name + '=; Max-Age=0; path=/; samesite=lax';
  }

  function tryParse(json) {
    try { return JSON.parse(json); } catch (e) { return null; }
  }

  const raw = getCookie('as_toast');
  if (!raw) return;

  // one-shot: delete immediately so refresh doesn’t repeat
  deleteCookie('as_toast');

  const payload = tryParse(raw);
  if (!payload || !payload.text) return;

  const text = String(payload.text || '').trim();
  const type = String(payload.type || 'info');
  const sticky = payload.sticky === 1;

  // If UIkit exists, use it
  if (window.UIkit && typeof window.UIkit.notification === 'function') {
    window.UIkit.notification({
      message: text,
      status: type,     // UIkit: success|danger|warning|primary
      pos: 'top-right',
      timeout: sticky ? 0 : 4000
    });
    return;
  }

  // Fallback toast (no UIkit)
  const wrap = document.createElement('div');
  wrap.className = 'as-toast as-toast-' + type;
  wrap.innerHTML = '<div class="as-toast-inner"></div><button class="as-toast-x" aria-label="Close">×</button>';
  wrap.querySelector('.as-toast-inner').textContent = text;

  const close = function () {
    if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
  };

  wrap.querySelector('.as-toast-x').addEventListener('click', close);
  document.body.appendChild(wrap);

  if (!sticky) {
    setTimeout(close, 4000);
  }
})();
