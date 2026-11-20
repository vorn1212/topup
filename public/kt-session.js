(function () {
  var KEY = 'kt_tab';
  var tag = document.querySelector('script[src*="kt-session.js"]');
  var signedIn = tag && tag.hasAttribute('data-signed-in');
  var seen = false;

  try { seen = sessionStorage.getItem(KEY) === '1'; } catch (e) { return; }

  if (!signedIn || seen) {
    try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
    return;
  }

  // Signed in, but this tab has no marker: a new tab or a restarted browser. Sign out.
  var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  var headers = { 'X-Requested-With': 'XMLHttpRequest' };
  if (m) headers['X-XSRF-TOKEN'] = decodeURIComponent(m[1]);

  fetch('/_session/end', { method: 'POST', credentials: 'same-origin', headers: headers })
    .finally(function () {
      try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
      location.reload();
    });
})();
