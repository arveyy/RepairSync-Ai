// Appearance: Light or Dark only (no "Auto"). Loaded in <head> so the saved
// theme is applied before the page paints — no flash of the wrong theme.
(function () {
  var KEY = 'repairsync-theme';
  var LEGACY_KEY = 'rs_theme';

  function read() {
    try {
      var v = localStorage.getItem(KEY);
      if (v === 'dark') return 'dark';
      if (v === 'light') return 'light';
      // Migrate an old choice once; "auto" is gone, so fall back to light.
      var old = localStorage.getItem(LEGACY_KEY);
      if (old === 'dark') { localStorage.setItem(KEY, 'dark'); return 'dark'; }
    } catch (e) {}
    return 'light';
  }

  function apply(v) {
    var r = document.documentElement;
    if (v === 'dark') r.setAttribute('data-theme', 'dark');
    else r.removeAttribute('data-theme');
  }

  apply(read());

  window.rsTheme = {
    get: read,
    set: function (v) {
      var value = v === 'dark' ? 'dark' : 'light';
      try {
        localStorage.setItem(KEY, value);
        localStorage.removeItem(LEGACY_KEY);
      } catch (e) {}
      apply(value);
      return value;
    },
    toggle: function () { return this.set(read() === 'dark' ? 'light' : 'dark'); }
  };
})();
