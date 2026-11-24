// RepairSync AI – single-page web client (no framework, no build step).
// All dynamic text is inserted with textContent (never innerHTML) to prevent XSS.

const $ = (s, r = document) => r.querySelector(s);
// Base URL of the site, so the app also works when it lives in a sub-folder (e.g. XAMPP: /repairsync/public/).
const BASE = new URL('../', import.meta.url).pathname;
const url = (p) => BASE + String(p).replace(/^\//, '');
// Leaflet's local build exposes either L or leaflet depending on the bundle.
const L = window.L || window.leaflet;
const STATUS_ORDER = ['booked', 'received', 'diagnosing', 'awaiting_approval', 'repairing', 'quality_check', 'completed', 'released'];
const STATUS_LABEL = { booked: 'Booked', received: 'Received', diagnosing: 'Diagnosing', awaiting_approval: 'Awaiting approval', repairing: 'Under repair', quality_check: 'Quality check', completed: 'Ready for pick-up', released: 'Released', cancelled: 'Cancelled' };
const ROLE_LABEL = { customer: 'Customer', frontdesk: 'Front desk', technician: 'Technician', admin: 'Administrator', owner: 'Shop owner', platform: 'Platform administrator' };
const DEFAULT_ACCENT = '#A7CF96';
const ACCENTS = [
  ['#A7CF96', 'Signature green'], ['#7FB2E5', 'Ocean blue'], ['#B49BE8', 'Lavender'], ['#E69BB8', 'Blush pink'],
  ['#E8A96A', 'Amber'], ['#E08080', 'Coral'], ['#6FC7B8', 'Teal'], ['#DCC46B', 'Gold']
];
const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (s) => (s ? new Date(String(s).replace(' ', 'T') + (String(s).includes('T') ? '' : 'Z')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '');
const ago = (s) => {
  const d = (Date.now() - new Date(String(s).replace(' ', 'T') + 'Z').getTime()) / 3600000;
  return d < 1 ? 'just now' : d < 24 ? `${Math.floor(d)}h ago` : `${Math.floor(d / 24)}d ago`;
};

// ---------- per-shop accent colour maths ----------
function hexToRgb(hex) {
  const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(String(hex || ''));
  return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [167, 207, 150];
}
function rgbToHex(rgb) {
  return '#' + rgb.map((n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0')).join('');
}
function rgbToHsl([r, g, b]) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
  let h = 0, s = 0;
  const l = (max + min) / 2;
  if (d) {
    s = l > .5 ? d / (2 - max - min) : d / (max + min);
    if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
    else if (max === g) h = ((b - r) / d + 2) / 6;
    else h = ((r - g) / d + 4) / 6;
  }
  return [h, s, l];
}
function hslToRgb(h, s, l) {
  const hue = (p, q, t) => {
    let v = t;
    if (v < 0) v += 1;
    if (v > 1) v -= 1;
    if (v < 1 / 6) return p + (q - p) * 6 * v;
    if (v < 1 / 2) return q;
    if (v < 2 / 3) return p + (q - p) * (2 / 3 - v) * 6;
    return p;
  };
  if (!s) return [l * 255, l * 255, l * 255];
  const q = l < .5 ? l * (1 + s) : l + s - l * s;
  const p = 2 * l - q;
  return [hue(p, q, h + 1 / 3), hue(p, q, h), hue(p, q, h - 1 / 3)].map((v) => v * 255);
}
/** Derive every shade from the stored base colour so both light and dark pages stay legible. */
function accentShades(hex) {
  const base = hexToRgb(hex);
  const [h, s, l] = rgbToHsl(base);
  const sat = Math.max(.3, Math.min(s || .4, .7));
  const soft = Math.min(sat, .45), darkSat = Math.min(sat, .32);
  // Keep the untouched default exactly as it was; a custom accent gets matching light/dark shades.
  if (!hex || String(hex).toUpperCase() === DEFAULT_ACCENT) {
    return {
      '--brand-accent': '#A7CF96',
      '--brand-accent-strong': '#8BBF78',
      '--brand-accent-hover': '#7BA96A',
      '--brand-accent-bright': '#8BBF78',
      '--brand-accent-bright-hover': '#7BA96A',
      '--brand-accent-soft': '#EAF4E4',
      '--brand-accent-softer': '#F4FAF1',
      '--brand-accent-dark-soft': '#1c2b18',
      '--brand-accent-dark-softer': '#13200f',
      '--brand-accent-tint': 'rgba(167,207,150,.16)',
      '--brand-accent-line': 'rgba(167,207,150,.35)'
    };
  }
  const strongL = Math.max(.30, Math.min(l - .30, .42));
  const brightL = Math.max(.62, Math.min(l + .04, .80));
  return {
    '--brand-accent': rgbToHex(base),
    '--brand-accent-strong': rgbToHex(hslToRgb(h, sat, strongL)),
    '--brand-accent-hover': rgbToHex(hslToRgb(h, sat, Math.max(.24, strongL - .06))),
    '--brand-accent-bright': rgbToHex(hslToRgb(h, Math.min(sat, .55), brightL)),
    '--brand-accent-bright-hover': rgbToHex(hslToRgb(h, Math.min(sat, .55), Math.max(.55, brightL - .06))),
    '--brand-accent-soft': rgbToHex(hslToRgb(h, soft, .91)),
    '--brand-accent-softer': rgbToHex(hslToRgb(h, Math.min(sat, .35), .96)),
    '--brand-accent-dark-soft': rgbToHex(hslToRgb(h, darkSat, .16)),
    '--brand-accent-dark-softer': rgbToHex(hslToRgb(h, Math.min(darkSat, .28), .10)),
    '--brand-accent-tint': `rgba(${base[0]},${base[1]},${base[2]},.16)`,
    '--brand-accent-line': `rgba(${base[0]},${base[1]},${base[2]},.35)`
  };
}
function applyBranding() {
  const shades = accentShades(me && me.shop ? me.shop.accent_color : null);
  Object.entries(shades).forEach(([key, value]) => document.body.style.setProperty(key, value));
}

// ---------- tiny DOM helper ----------
function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  if (attrs) for (const [k, v] of Object.entries(attrs)) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'value') el.value = v;
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, v);
  }
  for (const kid of kids.flat(Infinity)) {
    if (kid == null || kid === false) continue;
    el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  }
  return el;
}
const mount = (...nodes) => { const m = $('#main'); m.replaceChildren(...nodes.flat().filter(Boolean)); m.focus({ preventScroll: true }); window.scrollTo(0, 0); };

function toast(msg, err = false) {
  const t = h('div', { class: 'toast' + (err ? ' err' : ''), role: err ? 'alert' : 'status' }, msg);
  $('#toasts').append(t);
  setTimeout(() => t.remove(), err ? 6000 : 3500);
}

// ---------- API ----------
async function api(method, path, body) {
  const res = await fetch(url(path), {
    method, credentials: 'same-origin',
    headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(method !== 'GET' ? { 'X-Requested-With': 'RepairSync' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = {};
  try { data = await res.json(); } catch { /* empty */ }
  if (!res.ok) {
    const e = new Error(data.error || `Request failed (${res.status})`);
    e.status = res.status;
    throw e;
  }
  return data;
}
const guarded = (fn) => async (...a) => { try { return await fn(...a); } catch (e) { if (e.message !== 'Cancelled') toast(e.message, true); } };

// ---------- state ----------
let me = null;
let branches = [];
let health = {};
const isStaff = () => me && !['customer', 'platform'].includes(me.role);
const roleIn = (...r) => me && r.includes(me.role);
const canBrand = () => roleIn('admin', 'owner');
const shopLogo = (path) => path ? url('/uploads/' + path) : url('/Images/Logo.png');

async function boot() {
  health = await api('GET', '/api/health').catch(() => ({}));
  me = (await api('GET', '/api/auth/me').catch(() => ({ user: null }))).user;
  window.addEventListener('hashchange', route);
  // Lift the floating navbar slightly once the page scrolls.
  const onScroll = () => $('#topbar')?.classList.toggle('is-scrolled', window.scrollY > 12);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    closeAuth();
    closeSidebar();
    document.querySelectorAll('.accent-popover.is-open').forEach((p) => p.classList.remove('is-open'));
    document.querySelectorAll('.side-accent-btn[aria-expanded="true"]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.accent-wrap')) {
      document.querySelectorAll('.accent-popover.is-open').forEach((p) => p.classList.remove('is-open'));
      document.querySelectorAll('.side-accent-btn[aria-expanded="true"]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    }
  });
  $('#sidebarScrim')?.addEventListener('click', closeSidebar);
  route();

  // Gentle reveal for content as it comes into view (no-JS stays fully visible).
  if ('IntersectionObserver' in window) {
    document.documentElement.classList.add('js-anim');
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) { en.target.classList.add('in-view'); io.unobserve(en.target); }
      });
    }, { threshold: 0.1 });
    const observe = () => document.querySelectorAll('.feature-inner, .show-row, .door, .goal, .vm').forEach((el) => {
      if (el.classList.contains('in-view') || el.classList.contains('reveal')) return;
      el.classList.add('reveal');
      io.observe(el);
    });
    window.addEventListener('hashchange', () => setTimeout(observe, 160));
    setTimeout(observe, 160);
  }
}

// ---------- chrome ----------
// Each signed-in nav item carries one of the icons supplied in /Images.
const navItems = () => {
  const items = [];
  if (me.role === 'platform') {
    items.push(['#/platform', 'Shop registrations', 'centralizedicon.png', 'Platform']);
  } else if (me.role === 'customer') {
    items.push(['#/app', 'My repairs', 'trackicon.png', 'Workspace']);
    items.push(['#/customer', 'AI check & book', 'diagnosticicon.png', 'Workspace']);
    items.push(['#/track', 'Track a repair', 'statusicon.png', 'Workspace']);
  } else {
    items.push(['#/app', 'Workflow board', 'statusicon.png', 'Workspace']);
    if (roleIn('frontdesk', 'admin')) items.push(['#/new', 'New intake', 'bookingicon.png', 'Workspace']);
    items.push(['#/kb', 'Knowledge base', 'recordsicon.png', 'Workspace']);
    if (roleIn('admin', 'owner')) items.push(
      ['#/reports', 'Reports', 'monitoricon.png', 'Insights'],
      ['#/audit', 'Audit log', 'centralizedicon.png', 'Insights'],
      ['#/users', 'Users', 'everyroleicon.png', 'Manage'],
      ['#/settings', 'Shop settings', 'manageicon.png', 'Manage']);
    if (roleIn('admin')) items.push(['#/import', 'Import data', 'unifyicon.png', 'Manage']);
  }
  return items;
};

const navIsActive = (href, active) =>
  href === active || (href === '#/app' && active.startsWith('#/repair/'));

function icon(file, cls) {
  return h('img', { class: cls, src: url('/Images/' + file), alt: '' });
}

function svgIcon(paths, size = 16) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', size); svg.setAttribute('height', size);
  svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.8'); svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
  paths.forEach((d) => {
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', d);
    svg.append(p);
  });
  return svg;
}
const cameraIcon = () => svgIcon(['M4 8h3l1.6-2h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z', 'M12 16.3a3.3 3.3 0 1 0 0-6.6 3.3 3.3 0 0 0 0 6.6Z']);
const pencilIcon = () => svgIcon(['M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3Z', 'M14.5 6.5l3 3']);
const paletteIcon = () => svgIcon(['M12 3a9 9 0 1 0 0 18h1.4a2 2 0 0 0 1.4-3.4 2 2 0 0 1 1.4-3.4H18a3 3 0 0 0 3-3A9 9 0 0 0 12 3Z', 'M7.5 11h.01', 'M10 7.5h.01', 'M14.5 7.5h.01', 'M17 11h.01']);

const inlineError = (host, msg) => {
  let el = host.querySelector(':scope > .brand-error');
  if (!el) { el = h('div', { class: 'brand-error', role: 'alert' }); host.append(el); }
  el.textContent = msg;
};
const clearError = (host) => host.querySelector(':scope > .brand-error')?.remove();

const readFile = (file) => new Promise((resolve, reject) => {
  const fr = new FileReader();
  fr.onload = () => resolve(fr.result);
  fr.onerror = () => reject(new Error('That file could not be read. Please try again.'));
  fr.readAsDataURL(file);
});
const loadImage = (src) => new Promise((resolve, reject) => {
  const im = new Image();
  im.onload = () => resolve(im);
  im.onerror = () => reject(new Error('That image could not be opened.'));
  im.src = src;
});
async function cropSquare(src, size = 256) {
  const im = await loadImage(src);
  const side = Math.min(im.naturalWidth, im.naturalHeight);
  if (!side) throw new Error('That image has no usable pixels.');
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(im, (im.naturalWidth - side) / 2, (im.naturalHeight - side) / 2, side, side, 0, 0, size, size);
  return canvas.toDataURL('image/png');
}

async function openLogoDialog(src, host) {
  let cropped;
  try { cropped = await cropSquare(src); }
  catch { inlineError(host, 'That image could not be cropped. Try a PNG or JPG instead.'); return; }
  const dlg = $('#dlg');
  const error = h('div', { class: 'brand-error', role: 'alert' });
  const form = h('form', { class: 'brand-dialog' },
    h('h2', null, 'Update shop logo'),
    h('p', { class: 'muted small' }, 'Centered square crop · saved privately for your shop only.'),
    h('div', { class: 'brand-preview-wrap' }, h('img', { class: 'brand-preview', src: cropped, alt: 'Logo preview' })),
    error,
    h('div', { class: 'row', style: 'justify-content:flex-end;margin-top:16px' },
      h('button', { class: 'btn', type: 'button', onclick: () => dlg.close() }, 'Cancel'),
      h('button', { class: 'btn primary', type: 'submit' }, 'Save logo')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.textContent = '';
    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true;
    try {
      const r = await api('PUT', '/api/shop/logo', { image: cropped });
      me.shop.logo_path = r.logo_path;
      syncShopLogo(r.logo_path);
      dlg.close();
      toast('Shop logo updated.');
    } catch (err) {
      error.textContent = err.message;
      btn.disabled = false;
    }
  });
  dlg.replaceChildren(form);
  dlg.showModal();
}

async function handleLogoFile(input, host) {
  const file = input.files && input.files[0];
  input.value = '';
  if (!file) return;
  clearError(host);
  const allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
  if (!allowed.includes(file.type)) { inlineError(host, 'Use a PNG, JPG, SVG or WEBP image.'); return; }
  if (file.size > 2 * 1024 * 1024) { inlineError(host, 'That file is larger than 2 MB. Choose a smaller image.'); return; }
  try { await openLogoDialog(await readFile(file), host); }
  catch (err) { inlineError(host, err.message); }
}

function syncShopLogo(path) {
  document.querySelectorAll('[data-shop-logo]').forEach((el) => { el.src = shopLogo(path); });
}
function syncShopName(name) {
  if (me && me.shop) me.shop.name = name;
  document.querySelectorAll('[data-shop-name]').forEach((el) => { el.textContent = name; });
  document.querySelectorAll('[data-shop-name-input]').forEach((el) => { el.value = name; });
}

function beginNameEdit(brand) {
  if (!canBrand() || !me || !me.shop) return;
  const label = brand.querySelector('[data-shop-name]');
  if (!label) return;
  const original = label.textContent;
  const input = h('input', { class: 'side-name-input', value: original, maxlength: 80, 'aria-label': 'Shop name' });
  const error = h('div', { class: 'brand-error', role: 'alert' });
  const holder = h('span', { class: 'side-name-edit' }, input, error);
  label.replaceWith(holder);
  input.focus(); input.select();
  let done = false;
  const cancel = () => { if (done) return; done = true; holder.replaceWith(label); };
  const save = async () => {
    if (done) return;
    const value = input.value.trim();
    if (!value) { error.textContent = 'Shop name is required.'; input.focus(); return; }
    done = true;
    try {
      const r = await api('PATCH', '/api/shop/name', { name: value });
      label.textContent = r.name;
      holder.replaceWith(label);
      syncShopName(r.name);
      toast('Shop name updated.');
    } catch (err) {
      done = false;
      error.textContent = err.message;
      input.focus();
    }
  };
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); save(); }
    else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
  });
  input.addEventListener('blur', () => { if (!done) save(); });
}

function accentSwatch(host) {
  const current = (me && me.shop && me.shop.accent_color) || DEFAULT_ACCENT;
  const wrap = h('div', { class: 'accent-wrap' });
  const btn = h('button', {
    class: 'side-accent-btn', type: 'button', title: 'Appearance — accent color', 'aria-label': 'Appearance — accent color', 'aria-expanded': 'false',
    onclick: (e) => {
      e.stopPropagation();
      const open = pop.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', String(open));
      if (open) error.textContent = '';
    }
  }, paletteIcon(), h('i', { class: 'accent-dot', style: `background:${current}` }));
  const pop = h('div', { class: 'accent-popover', role: 'group', 'aria-label': 'Accent color' });
  const error = h('div', { class: 'brand-error', role: 'alert' });
  pop.append(error);
  ACCENTS.forEach(([hex, name]) => {
    const swatch = h('button', {
      class: 'accent-swatch' + (hex.toLowerCase() === current.toLowerCase() ? ' on' : ''),
      type: 'button', title: name, 'aria-label': name, style: `--swatch:${hex}`,
      onclick: async (e) => {
        e.stopPropagation();
        error.textContent = '';
        try {
          const r = await api('PATCH', '/api/shop/theme', { accent: hex });
          me.shop.accent_color = r.accent;
          applyBranding();
          pop.querySelectorAll('.accent-swatch').forEach((s) => s.classList.remove('on'));
          swatch.classList.add('on');
          btn.querySelector('.accent-dot').style.background = r.accent;
          pop.classList.remove('is-open');
          btn.setAttribute('aria-expanded', 'false');
          toast(`${name} accent applied.`);
        } catch (err) { error.textContent = err.message; }
      }
    }, h('i', { class: 'swatch-dot' }));
    pop.append(swatch);
  });
  wrap.append(btn, pop);
  host.append(wrap);
}

// One image button — Light / Dark only (the "Auto" option is gone).
function themeToggle() {
  const isDark = window.rsTheme && window.rsTheme.get() === 'dark';
  const btn = h('button', {
    class: 'theme-toggle', type: 'button',
    'aria-label': isDark ? 'Switch to light mode' : 'Switch to dark mode',
    onclick: () => {
      const next = window.rsTheme ? window.rsTheme.toggle() : 'light';
      btn.setAttribute('aria-label', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
  },
    h('img', { class: 'theme-icon-light', src: url('/Images/lightmodelogo.png'), alt: '' }),
    h('img', { class: 'theme-icon-dark', src: url('/Images/darkmodelogo.png'), alt: '' }));
  return btn;
}

function closeSidebar() {
  $('#sidebar')?.classList.remove('is-open');
  $('#sidebarScrim')?.classList.remove('is-open');
  document.body.classList.remove('side-open');
  $('#mobileMenu')?.setAttribute('aria-expanded', 'false');
}
function openSidebar() {
  if (!document.body.classList.contains('app-shell')) return;
  $('#sidebar')?.classList.add('is-open');
  $('#sidebarScrim')?.classList.add('is-open');
  document.body.classList.add('side-open');
  $('#mobileMenu')?.setAttribute('aria-expanded', 'true');
}

function sidebar(active) {
  const items = navItems();
  const body = h('nav', { class: 'side-body', 'aria-label': 'Main navigation' });
  let section = null;
  for (const [href, label, glyph, group] of items) {
    if (group !== section) {
      section = group;
      body.append(h('div', { class: 'side-label' }, group));
    }
    const on = navIsActive(href, active);
    body.append(h('a', {
      class: 'side-link' + (on ? ' on' : ''), href,
      'aria-current': on ? 'page' : null,
      onclick: closeSidebar
    }, icon(glyph, 'side-icon'), h('span', { class: 'side-link-text' }, label),
       h('span', { class: 'side-link-arrow', 'aria-hidden': 'true' }, '›')));
  }

  const initials = String(me.name || '?').trim().split(/\s+/).map((s) => s[0]).slice(0, 2).join('').toUpperCase();
  const home = me.role === 'platform' ? '#/platform' : '#/app';
  let brand;
  const fileInput = h('input', {
    type: 'file', accept: 'image/png,image/jpeg,image/webp,image/svg+xml',
    class: 'sr-file', style: 'display:none',
    onchange: (e) => handleLogoFile(e.target, brand)
  });
  const user = h('div', { class: 'side-user' },
    h('span', { class: 'side-avatar' }, initials),
    h('span', { class: 'side-user-meta' },
      h('strong', null, me.name),
      h('small', null, ROLE_LABEL[me.role], me.shop ? ' · ' : null,
        me.shop ? h('span', { 'data-shop-name': '1' }, me.shop.name) : null)),
    h('button', {
      class: 'side-signout', type: 'button', title: 'Sign out', 'aria-label': 'Sign out',
      onclick: guarded(async () => {
        await api('POST', '/api/auth/logout');
        me = null; closeAuth(); closeSidebar(); location.hash = '#/'; route();
      })
    }, h('span', { class: 'signout-glyph' }, '↪')));

  const footRow = h('div', { class: 'side-foot-row' },
    themeToggle(), h('span', { class: 'side-version' }, 'RepairSync · v1.0'));
  if (canBrand()) accentSwatch(footRow);
  const foot = h('div', { class: 'side-foot' },
    h('div', { class: 'side-online' }, h('i', null), ' Workspace ready'),
    user, footRow);

  brand = h('div', { class: 'side-brand' },
    h('div', { class: 'side-logo-wrap' },
      h('span', { class: 'side-logo' }, h('img', {
        'data-shop-logo': '1', src: shopLogo(me.shop ? me.shop.logo_path : null), alt: ''
      })),
      canBrand() ? h('button', {
        class: 'side-logo-edit', type: 'button', title: 'Change shop logo', 'aria-label': 'Change shop logo',
        onclick: () => fileInput.click()
      }, cameraIcon()) : null,
      canBrand() ? fileInput : null),
    h('a', { class: 'side-brand-text', href: home, onclick: closeSidebar },
      h('strong', null, 'RepairSync'),
      h('span', { class: 'side-name-row' },
        h('small', {
          'data-shop-name': '1',
          title: canBrand() ? 'Click to edit shop name' : null,
          onclick: canBrand() ? (e) => { e.preventDefault(); e.stopPropagation(); beginNameEdit(brand); } : null
        }, me.shop ? me.shop.name : 'Control center'),
        canBrand() ? h('button', {
          class: 'side-edit-name', type: 'button', title: 'Edit shop name', 'aria-label': 'Edit shop name',
          onclick: (e) => { e.preventDefault(); e.stopPropagation(); beginNameEdit(brand); }
        }, pencilIcon()) : null)));

  return h('div', { class: 'side-inner' }, brand, body, foot);
}

function mobilebar(active) {
  const current = navItems().find(([href]) => navIsActive(href, active));
  return h('div', { class: 'mobilebar-inner' },
    h('button', {
      id: 'mobileMenu', class: 'mobile-menu', type: 'button',
      'aria-label': 'Open navigation', 'aria-expanded': 'false',
      onclick: () => (document.body.classList.contains('side-open') ? closeSidebar() : openSidebar())
    }, h('span', null)),
    h('a', { class: 'mobile-brand', href: me.role === 'platform' ? '#/platform' : '#/app' },
      h('img', { 'data-shop-logo': '1', src: shopLogo(me.shop ? me.shop.logo_path : null), alt: '' }), h('span', null, 'RepairSync')),
    h('span', { class: 'mobile-page' }, current ? current[1] : 'Workspace'));
}

function publicNav(active) {
  const links = [];
  if (!me) links.push(['#/business', 'For businesses'], ['#/customer', 'For clients'], ['#/track', 'Track a repair'], ['#/about', 'About']);
  const nav = h('ul', { class: 'nav-links', id: 'navLinks', 'aria-label': 'Main' },
    links.map(([href, t]) => h('li', null, h('a', {
      href, class: active === href ? 'on' : '',
      onclick: () => $('#navLinks')?.classList.remove('nav-links--open')
    }, t))));

  const navToggle = h('button', {
    class: 'nav-toggle', 'aria-label': 'Toggle menu', 'aria-expanded': 'false',
    onclick: () => {
      const open = nav.classList.toggle('nav-links--open');
      navToggle.setAttribute('aria-expanded', String(open));
    }
  }, h('span'));

  return h('div', { class: 'nav-inner' },
    h('a', { class: 'brand', href: '#/' },
      h('span', { class: 'brand-mark' }, h('img', { src: url('/Images/Logo.png'), alt: '' })),
      h('span', { class: 'brand-name sr-only' }, 'RepairSync')),
    nav,
    h('div', { class: 'nav-actions' }, themeToggle(),
      h('a', { class: 'btn btn-outline', href: '#/login', onclick: (e) => { e.preventDefault(); openAuth('signin'); } }, 'Sign In'),
      h('a', { class: 'btn btn-dark', href: '#/business', onclick: (e) => { e.preventDefault(); openAuth('shop'); } }, 'Register Shop'),
      navToggle));
}

function renderTop(active) {
  const signedIn = !!me;
  document.body.classList.toggle('app-shell', signedIn);
  applyBranding();
  if (!signedIn) {
    closeSidebar();
    $('#sidebar')?.replaceChildren();
    $('#mobilebar')?.replaceChildren();
    $('#topbar').className = 'topbar';
    $('#topbar').replaceChildren(publicNav(active));
    return;
  }
  $('#topbar').className = 'topbar is-hidden';
  $('#topbar').replaceChildren();
  $('#sidebar').replaceChildren(sidebar(active));
  $('#mobilebar').replaceChildren(mobilebar(active));
}

// ---------- floating sign in / sign up / register shop panels ----------
function eyeIcon() {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(ns, 'path');
  path.setAttribute('d', 'M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12z');
  path.setAttribute('stroke', '#7C8A90'); path.setAttribute('stroke-width', '1.5'); path.setAttribute('stroke-linejoin', 'round');
  const dot = document.createElementNS(ns, 'circle');
  dot.setAttribute('cx', '12'); dot.setAttribute('cy', '12'); dot.setAttribute('r', '3');
  dot.setAttribute('stroke', '#7C8A90'); dot.setAttribute('stroke-width', '1.5');
  svg.append(path, dot);
  return svg;
}
const authField = (id, label, input, required = false) =>
  h('div', null, h('label', { for: id }, label, required ? h('span', { class: 'required' }, ' *') : null), input);
const authInput = (id, type, placeholder, autocomplete, extra = {}) =>
  h('input', { id, name: id, type, placeholder, autocomplete, required: true, ...extra });
function authPassword(id, placeholder, autocomplete) {
  const input = authInput(id, 'password', placeholder, autocomplete, { minlength: 8 });
  const toggle = h('button', { type: 'button', class: 'password-toggle', 'aria-label': 'Show password', onclick: () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  } }, eyeIcon());
  return h('div', { class: 'password-field' }, input, toggle);
}

function closeAuth() {
  const layer = $('#authLayer');
  if (!layer) return;
  layer.querySelectorAll('.auth-backdrop, .auth-panel').forEach((n) => n.classList.remove('active'));
  document.body.style.overflow = '';
  setTimeout(() => { if (!layer.querySelector('.auth-panel.active')) layer.replaceChildren(); }, 320);
}

function openAuth(kind = 'signin') {
  const layer = $('#authLayer');
  if (!layer) return;
  closeAuth();

  const titleMap = { signin: 'Sign in to your account to continue', register: 'Create your client account', shop: 'Register your repair shop' };
  const subMap = {
    signin: 'Welcome back. Pick up where your repairs left off.',
    register: 'Book, follow and approve your repairs in one place.',
    shop: 'Your own private shop space with a first branch and administrator account.'
  };
  const form = h('form', { class: 'auth-form', novalidate: false });
  const errorBox = h('p', { class: 'auth-error', role: 'alert' });
  form.append(errorBox);

  const submit = (endpoint, payload, done) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBox.textContent = '';
      const btn = form.querySelector('[type="submit"]');
      if (btn) btn.disabled = true;
      try {
        const data = Object.fromEntries(new FormData(form).entries());
        const user = await api('POST', endpoint, payload(data));
        done(user);
      } catch (err) {
        errorBox.textContent = err.message || 'Unable to continue. Please try again.';
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  };
  const signedIn = (user) => { me = user; closeAuth(); location.hash = '#/app'; route(); };

  if (kind === 'signin') {
    form.append(
      authField('authSigninEmail', 'Email', authInput('authSigninEmail', 'email', 'username@gmail.com', 'username')),
      authField('authSigninPassword', 'Password', authPassword('authSigninPassword', 'Password', 'current-password')),
      h('a', { class: 'forgot-link', href: '#/track', onclick: () => closeAuth() }, 'Forgot Password?'),
      h('button', { class: 'btn-submit', type: 'submit' }, 'Sign in'),
      h('label', { class: 'remember-me' }, h('input', { id: 'authRemember', name: 'remember', type: 'checkbox' }), h('span', null, 'Remember me')),
      h('p', { class: 'auth-prompt' }, 'Don’t have an account yet? ', h('a', { href: '#/register', onclick: (e) => { e.preventDefault(); openAuth('register'); } }, 'Sign Up')),
      h('div', { class: 'auth-links' }, h('a', { href: '#/business', onclick: (e) => { e.preventDefault(); openAuth('shop'); } }, 'Register your shop'),
        health.demo ? h('span', { class: 'muted small' }, 'Demo: owner@repairsync.test / RepairSync#2026') : null));
    submit('/api/auth/login', (d) => ({ email: d.authSigninEmail, password: d.authSigninPassword }), signedIn);
  } else if (kind === 'register') {
    form.append(
      h('div', { class: 'name-row' },
        authField('authFirst', 'First name', authInput('authFirst', 'text', 'John', 'given-name'), true),
        authField('authLast', 'Last name', authInput('authLast', 'text', 'Doe', 'family-name'), true)),
      authField('authRegEmail', 'Email', authInput('authRegEmail', 'email', 'you@example.com', 'email'), true),
      authField('authRegPhone', 'Mobile number (optional)', authInput('authRegPhone', 'tel', '09XX XXX XXXX', 'tel'), false),
      authField('authRegPassword', 'Password', authPassword('authRegPassword', 'At least 8 characters', 'new-password'), true),
      h('label', { class: 'terms-check' }, h('input', { id: 'authConsent', name: 'consent', type: 'checkbox', required: true }),
        h('span', null, 'I accept the ', h('a', { href: '#/about', onclick: () => closeAuth() }, 'Data Privacy notice'), ' (RA 10173).')),
      h('button', { class: 'btn-submit', type: 'submit' }, 'Create account'),
      h('p', { class: 'auth-prompt' }, 'Already have an account? ', h('a', { href: '#/signin', onclick: (e) => { e.preventDefault(); openAuth('signin'); } }, 'Sign in')),
      h('div', { class: 'auth-links' }, h('a', { href: '#/business', onclick: (e) => { e.preventDefault(); openAuth('shop'); } }, 'Register your shop')));
    form.querySelector('#authRegPhone').removeAttribute('required');
    submit('/api/auth/register', (d) => ({ name: `${d.authFirst} ${d.authLast}`.trim(), email: d.authRegEmail, phone: d.authRegPhone, password: d.authRegPassword, consent: true }), signedIn);
  } else {
    form.append(
      h('div', { class: 'name-row' },
        authField('authBizName', 'Business name', authInput('authBizName', 'text', 'e.g. Kuya Gadget Fix', 'organization'), true),
        authField('authCity', 'City / town', authInput('authCity', 'text', 'Cebu City', 'address-level2'), true)),
      h('div', { class: 'name-row' },
        authField('authOwner', 'Your name', authInput('authOwner', 'text', 'Juan Dela Cruz', 'name'), true),
        authField('authBizPhone', 'Mobile number', authInput('authBizPhone', 'tel', '09XX XXX XXXX', 'tel'), true)),
      h('div', { class: 'name-row' },
        authField('authBizEmail', 'Email (your sign-in)', authInput('authBizEmail', 'email', 'you@example.com', 'username'), true),
        authField('authBizPassword', 'Password', authPassword('authBizPassword', 'At least 8 characters', 'new-password'), true)),
      h('label', { class: 'terms-check' }, h('input', { id: 'authAgree', name: 'agree', type: 'checkbox', required: true }),
        h('span', null, 'I agree to the terms of this prototype service and will handle my customers’ personal data in line with the Data Privacy Act of 2012 (RA 10173).')),
      h('button', { class: 'btn-submit', type: 'submit' }, 'Register my shop'),
      h('p', { class: 'auth-prompt' }, 'Already registered? ', h('a', { href: '#/login', onclick: (e) => { e.preventDefault(); openAuth('signin'); } }, 'Sign in')),
      h('div', { class: 'auth-links' }, h('a', { href: '#/business', onclick: () => closeAuth() }, 'See full business details')));
    submit('/api/business/signup', (d) => ({
      businessName: d.authBizName, city: d.authCity, ownerName: d.authOwner, phone: d.authBizPhone,
      email: d.authBizEmail, password: d.authBizPassword, agree: true
    }), (user) => {
      me = user; closeAuth(); toast('Registration received. Your shop is waiting for approval. Meanwhile, add your staff.');
      location.hash = '#/users'; route();
    });
  }

  const backdrop = h('div', { class: 'auth-backdrop', onclick: closeAuth });
  const panel = h('div', { class: 'auth-panel' + (kind === 'shop' ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': titleMap[kind] },
    h('button', { class: 'auth-close', type: 'button', 'aria-label': 'Close', onclick: closeAuth }, '×'),
    h('div', { class: 'auth-logo' }, h('img', { src: url('/Images/Logo.png'), alt: 'RepairSync logo' })),
    h('h3', null, titleMap[kind]),
    h('p', { class: 'auth-subtext' }, subMap[kind]), form);
  layer.replaceChildren(backdrop, panel);
  document.body.style.overflow = 'hidden';
  requestAnimationFrame(() => { backdrop.classList.add('active'); panel.classList.add('active'); });
  setTimeout(() => panel.querySelector('input:not([type="checkbox"])')?.focus(), 240);
}

// ---------- components ----------
const tag = (s) => h('span', { class: `tag s-${s}` }, STATUS_LABEL[s] || s);
const field = (label, input, hint) => h('div', null, h('label', null, label), input, hint && h('div', { class: 'muted small' }, hint));
const input = (name, o = {}) => {
  const { ph, max, ac, ...attrs } = o;
  return h('input', { ...attrs, name, type: o.type || 'text', placeholder: ph, required: o.required, maxlength: max || 120, autocomplete: ac, value: o.value, min: o.min, step: o.step });
};
const select = (name, opts, value) => h('select', { name }, opts.map(([v, t]) => h('option', { value: v, selected: v === value }, t)));
const formData = (form) => Object.fromEntries(new FormData(form).entries());

function stepper(current) {
  const idx = STATUS_ORDER.indexOf(current);
  return h('div', { class: 'steps', role: 'list', 'aria-label': 'Repair progress' }, STATUS_ORDER.map((s, i) =>
    h('div', { class: 'step' + (i < idx ? ' done' : i === idx ? ' now' : ''), role: 'listitem', 'aria-current': i === idx ? 'step' : null }, h('i'), STATUS_LABEL[s])));
}

function ask(title, fields, confirmLabel = 'Confirm') {
  const dlg = $('#dlg');
  return new Promise((resolve) => {
    const form = h('form', { method: 'dialog' },
      h('h2', null, title), ...fields,
      h('div', { class: 'row', style: 'margin-top:14px;justify-content:flex-end' },
        h('button', { type: 'button', onclick: () => { dlg.close(); resolve(null); } }, 'Cancel'),
        h('button', { class: 'primary', type: 'submit' }, confirmLabel)));
    form.addEventListener('submit', (e) => { e.preventDefault(); const d = formData(form); dlg.close(); resolve(d); });
    dlg.replaceChildren(form);
    dlg.addEventListener('close', () => resolve(null), { once: true });
    dlg.showModal();
    form.querySelector('textarea,input,select')?.focus();
  });
}

// ---------- router ----------
async function route() {
  cleanupCustomerFinder();
  cleanupSettingsMap();
  const [path, qs] = (location.hash.slice(1) || '/').split('?');
  const q = Object.fromEntries(new URLSearchParams(qs || ''));
  me = (await api('GET', '/api/auth/me').catch(() => ({ user: null }))).user;
  if (isStaff()) branches = await api('GET', '/api/branches').catch(() => []);
  renderTop('#' + path);
  const guard = (ok) => { if (!me) { location.hash = '#/login'; return false; } if (!ok) { mount(h('div', { class: 'card' }, h('h2', null, 'Not allowed'), h('p', null, 'Your role does not have access to this page.'))); return false; } return true; };
  try {
    let m;
    if (path === '/') return pageLanding();
    if (path === '/about') return pageAbout();
    if (path === '/business') return pageBusiness();
    if (path === '/customer') return pageCustomer();
    if (path === '/find') return pageFinder();
    if (path === '/book') return pageBook(q);
    if (path === '/track') return pageTrack(q);
    if (path === '/login') return pageLogin();
    if (path === '/register') return pageRegister();
    if ((m = path.match(/^\/shop\/([a-z0-9-]+)$/))) return pageShop(m[1]);
    if ((m = path.match(/^\/shop\/([a-z0-9-]+)\/book$/))) return pageBook(q, m[1]);
    if (path === '/platform') return guard(roleIn('platform')) && pagePlatform();
    if (path === '/app') return guard(true) && (me.role === 'platform' ? pagePlatform() : isStaff() ? pageBoard() : pageMyRepairs());
    if (path === '/new') return guard(roleIn('frontdesk', 'admin')) && pageNew();
    if ((m = path.match(/^\/repair\/(\d+)$/))) return guard(true) && pageRepair(Number(m[1]));
    if (path === '/kb') return guard(isStaff()) && pageKB();
    if (path === '/users') return guard(roleIn('admin', 'owner')) && pageUsers();
    if (path === '/reports') return guard(roleIn('admin', 'owner')) && pageReports();
    if (path === '/import') return guard(roleIn('admin')) && pageImport();
    if (path === '/settings') return guard(roleIn('admin', 'owner')) && pageSettings();
    if (path === '/audit') return guard(roleIn('admin', 'owner')) && pageAudit();
    mount(h('div', { class: 'card' }, h('h2', null, 'Page not found'), h('a', { href: '#/' }, 'Go home')));
  } catch (e) {
    if (e.status === 401) { me = null; location.hash = '#/login'; return; }
    mount(h('div', { class: 'card' }, h('h2', null, 'Something went wrong'), h('p', null, e.message)));
  }
}

// ---------- public: landing (two doors) ----------
const bullets = (items) => h('ul', { class: 'clean door-list' }, items.map((t) => h('li', null, t)));

// ---------- landing: feature sections (our own content, upgraded layout) ----------
function featureStrip() {
  const item = (icon, title, text) => h('li', null,
    h('span', { class: 'feature-icon' }, h('img', { src: url('/Images/' + icon), alt: '', loading: 'lazy' })),
    h('div', null, h('strong', null, title), h('span', null, text)));
  const panel = (title, text, items, art, rev = false) => h('section', { class: 'feature' + (rev ? ' reverse' : '') },
    h('div', { class: 'feature-inner' },
      h('div', { class: 'feature-copy' }, h('h2', null, title), h('p', null, text),
        h('ul', { class: 'feature-list' }, items)),
      h('div', { class: 'feature-art' }, h('img', { src: url('/Images/' + art), alt: '', loading: 'lazy', decoding: 'async' }))));

  return h('div', { class: 'features' },
    panel('From intake to release, all in one place',
      'RepairSync AI replaces handwritten logbooks and scattered messages with one system for every branch, technician and customer.',
      [
        item('trackicon.png', 'Track every repair', 'Each job moves from Booked to Released, with who did what and when.'),
        item('bookingicon.png', 'Accept bookings online', 'Clients submit device details and symptoms from their phone.'),
        item('diagnosticicon.png', 'AI-assisted diagnosis', 'Technicians see ranked likely causes with the similar past cases behind each one.'),
        item('manageicon.png', 'Manage your workflow', 'Assign technicians, monitor progress and keep repair records together.')
      ], 'heroimage.png'),
    panel('Keep every client in the loop',
      'Clients get a clear way to see what may be wrong and where their repair stands, without calling the shop.',
      [
        item('statusicon.png', 'Live repair status', 'Follow every step with a repair code and approve the quote online.'),
        item('recordsicon.png', 'Organized records', 'Customer details, device information, findings and repair history in one place.'),
        item('monitoricon.png', 'Clear oversight', 'Owner and admin reports on turnaround, workload and AI feedback.')
      ], 'heroimage2.png', true),
    panel('One system for every role',
      'Front desk, technicians, administrators and owners work from the same repair information, while each shop’s data stays private.',
      [
        item('everyroleicon.png', 'Built for every role', 'Role-based access gives each person only the tools and details they need.'),
        item('unifyicon.png', 'Everyone connected', 'Authorized staff work with the same records across every branch.'),
        item('centralizedicon.png', 'A knowledge base that learns', 'Verified repairs become new cases after an administrator approves them.')
      ], 'heroimage3.png'));
}

// ---------- landing: "see it in action" feature rows with real screenshots ----------
function showcase() {
  const frame = (src, alt, label) => h('figure', { class: 'shot' },
    h('div', { class: 'shot-bar', 'aria-hidden': 'true' }, h('i'), h('i'), h('i'), h('span', null, label)),
    h('img', { src: url('/img/shots/' + src), alt, loading: 'lazy', decoding: 'async' }));
  const phone = (src, alt) => h('figure', { class: 'phone' }, h('img', { src: url('/img/shots/' + src), alt, loading: 'lazy', decoding: 'async' }));
  const row = (tag, title, text, points, media, opts = {}) => h('div', { class: 'show-row' + (opts.rev ? ' rev' : '') },
    h('div', { class: 'show-text' },
      h('span', { class: 'door-tag' }, tag), h('h3', null, title), h('p', null, text),
      h('ul', { class: 'clean' }, points.map((t) => h('li', null, t))),
      opts.cta && h('a', { class: 'btn', href: opts.cta[0] }, opts.cta[1])),
    h('div', { class: 'show-media' + (opts.phones ? ' phones' : '') }, media));
  return h('section', { class: 'showcase', id: 'inaction' },
    h('h2', null, 'See it in action'),
    h('p', { class: 'lead' }, 'These are screens from the actual system, using sample data from TIM’SOLUTIONS’ branches.'),
    row('Workflow board', 'Every repair on one board',
      'No more notebooks and chat threads. Each job moves from Booked to Released, and anything late is flagged.',
      ['Priorities, overdue flags and who is on each repair', 'Filter by branch or search by code, name or device', 'Walk-ins and online bookings land in the same place'],
      frame('board.jpg', 'The workflow board with repairs in columns from Booked to Under repair', 'Workflow board')),
    row('AI diagnostic assistant', 'Suggestions backed by real past repairs',
      'The technician sees ranked likely causes, how confident the match is, and the similar cases behind each suggestion.',
      ['Typical cost and time from similar repairs', 'Suggested checks to do at the bench', 'The technician confirms or rejects, and verified repairs teach the system'],
      frame('ai.jpg', 'A repair page showing the AI preliminary diagnosis with ranked causes', 'Repair details · AI diagnosis'), { rev: true }),
    row('For clients', 'Answers without calling the shop',
      'Describe the problem in English, Tagalog or Cebuano and get a preliminary idea, then book and follow your repair with its code.',
      ['Free AI check, no account needed', 'Book with a shop using its own link', 'See every step, approve the quote online'],
      [phone('check.jpg', 'Phone screen showing the free AI check results'), phone('track.jpg', 'Phone screen tracking a repair with its quote and timeline')],
      { phones: true, cta: ['#/customer', 'Check my device'] }),
    row('Reports', 'Know how the shop is doing',
      'Sales and repair reports by day, month or year, with the change from the previous period and a CSV download.',
      ['Daily sales and number of payments', 'Comparison with the previous month', 'Turnaround, workload and AI feedback'],
      frame('reports.jpg', 'Sales report with monthly totals and a bar chart of daily sales', 'Reports · Sales'), { rev: true }),
    row('Knowledge base', 'A memory that grows with every repair',
      'Each verified repair can become a new case after an administrator approves it, so new technicians learn from what the shop has already fixed.',
      ['Searchable cases with symptoms, diagnosis and usual repair', 'Import your own past repairs', 'Each shop’s AI uses only its own cases'],
      frame('kb.jpg', 'The knowledge base table of verified repair cases', 'Knowledge base')));
}

function pageLanding() {
  mount(
    h('section', { class: 'hero' },
      h('div', null,
        h('h1', null, 'Repair shops run smoother. Clients know what’s wrong sooner.'),
        h('p', null, 'RepairSync AI is a cloud service that automates the whole gadget repair business, with an AI diagnostic assistant for technicians and a free AI check for clients. Choose where you’re coming from:'))),
    featureStrip(),
    h('div', { class: 'grid g2 doors' },
      h('a', { class: 'door door-biz', href: '#/business' },
        h('img', { class: 'door-img', src: url('/img/shots/board.jpg'), alt: '', loading: 'lazy' }),
        h('span', { class: 'door-tag' }, 'For businesses'),
        h('h2', null, 'I run a repair shop'),
        h('p', null, 'Move from paper logbooks and chat threads to one system for every branch, technician and customer.'),
        bullets(['Workflow board from intake to release', 'AI-assisted diagnosis for technicians, with evidence', 'Customer tracking, quotes and approvals', 'Staff roles, reports and Data Privacy tools'])),
      h('a', { class: 'door door-cli', href: '#/customer' },
        h('img', { class: 'door-img door-img-phone', src: url('/img/shots/check.jpg'), alt: '', loading: 'lazy' }),
        h('span', { class: 'door-tag' }, 'For clients'),
        h('h2', null, 'I need my gadget repaired'),
        h('p', null, 'Describe the problem in your own words. Get a quick AI check, find a shop and follow your repair.'),
        bullets(['Free AI check, no account needed', 'Works in English, Tagalog and Cebuano', 'Book with a repair shop near you', 'Track every step with your repair code']),
        h('span', { class: 'btn primary' }, 'Check my device →'))),
    h('p', { class: 'muted small', style: 'text-align:center;margin-top:18px' }, 'Already have a repair code? ', h('a', { href: '#/track' }, 'Track it')),
    showcase(),
    aboutSections());
}

// ---------- about the site: purpose, vision, mission, goals, FAQ (shown on the home page and at #/about) ----------
function aboutSections() {
  const goal = (n, t, d) => h('div', { class: 'card goal' }, h('div', { class: 'goal-n' }, n), h('h3', null, t), h('p', { class: 'muted small' }, d));
  const faq = (q, a) => h('details', { class: 'faq' }, h('summary', null, q), h('p', null, a));
  return h('div', { class: 'about' },
    h('section', { class: 'about-block', id: 'purpose' },
      h('h2', null, 'What is RepairSync AI for?'),
      h('p', { class: 'lead' }, 'Many gadget repair shops still run on notebooks, chat threads and memory. Jobs get lost, new technicians cannot recall what similar problems turned out to be, and clients keep calling to ask if their device is ready.'),
      h('p', { class: 'lead' }, 'RepairSync AI puts the whole repair process in one simple system: intake, diagnosis, quotes, repair, quality check, pick-up and reports. It gives technicians AI suggestions backed by real past repairs, and gives clients a clear way to see what may be wrong and where their repair stands.')),
    h('div', { class: 'grid g2 about-block' },
      h('div', { class: 'card vm' }, h('span', { class: 'door-tag' }, 'Our vision'), h('p', null, 'Every gadget repair shop, big or small, runs on clear records and evidence-based diagnosis, and every client always knows what is happening with their device.')),
      h('div', { class: 'card vm' }, h('span', { class: 'door-tag' }, 'Our mission'), h('p', null, 'To give repair shops an affordable, easy-to-use system that organizes the repair process and supports technicians with AI suggestions drawn from real repair cases, while the technician stays in control and customer data stays protected.'))),
    h('section', { class: 'about-block', id: 'goals' },
      h('h2', null, 'What we want to achieve'),
      h('div', { class: 'grid g3' },
        goal('1', 'Faster, better-informed diagnosis', 'An AI assistant finds similar verified past repairs and suggests likely causes, checks and costs for the technician to confirm.'),
        goal('2', 'One clear repair workflow', 'Every job moves through the same steps, from intake to release, with who did what and when.'),
        goal('3', 'Real-time repair status', 'Technicians and staff see progress at a glance, and clients follow it with a repair code.'),
        goal('4', 'Secure, role-based access', 'Front desk, technicians, admins and owners only see what their role needs. Each shop’s data stays private.'),
        goal('5', 'Better client communication', 'Clients get updates, quotes to approve online, and an honest preliminary idea of the problem.'),
        goal('6', 'A structured, measurable process', 'Reports on sales and repairs, and a knowledge base that grows with every verified repair.'))),
    h('section', { class: 'about-block', id: 'how' },
      h('h2', null, 'How it works'),
      h('div', { class: 'grid g4' }, [
        ['1', 'Describe', 'The client describes the problem in English, Tagalog or Cebuano.'],
        ['2', 'Match', 'The system searches past verified repairs for the closest cases.'],
        ['3', 'Suggest', 'It ranks possible diagnoses with a confidence level and the evidence behind them.'],
        ['4', 'Confirm', 'A technician inspects, confirms or rejects the suggestion, and the verified result is added to the knowledge base.'],
      ].map(([n, t, d]) => h('div', { class: 'card goal' }, h('div', { class: 'goal-n' }, n), h('h3', null, t), h('p', { class: 'muted small' }, d))))),
    h('section', { class: 'about-block' },
      h('h2', null, 'Built to be trusted'),
      h('div', { class: 'grid g3' },
        h('div', { class: 'card' }, h('h3', { class: 'trust-title' }, h('img', { class: 'trust-title-icon', src: url('/Images/manageicon.png'), alt: '', loading: 'lazy' }), h('span', null, 'The technician decides')), h('p', { class: 'muted small' }, 'The AI only suggests. A qualified technician must verify every diagnosis before any repair, and clients are told the AI check is preliminary.')),
        h('div', { class: 'card' }, h('h3', { class: 'trust-title' }, h('img', { class: 'trust-title-icon', src: url('/Images/recordsicon.png'), alt: '', loading: 'lazy' }), h('span', null, 'It shows its evidence')), h('p', { class: 'muted small' }, 'Suggestions come from retrieving similar past cases, not from guessing. You can see which cases support each suggestion.')),
        h('div', { class: 'card' }, h('h3', { class: 'trust-title' }, h('img', { class: 'trust-title-icon', src: url('/Images/monitoricon.png'), alt: '', loading: 'lazy' }), h('span', null, 'Your data stays private')), h('p', { class: 'muted small' }, 'Each shop sees only its own records. Staff access follows roles, and personal data is handled in line with the Data Privacy Act of 2012 (RA 10173).')))),
    h('section', { class: 'about-block', id: 'faq' },
      h('h2', null, 'Frequently asked questions'),
      h('div', { class: 'card' },
        faq('Is the AI check free? Do I need an account?', 'Yes, it is free and needs no account. We do not store what you type in the free check.'),
        faq('Does the AI replace the technician?', 'No. It gives a preliminary guess to help. A technician always inspects your device, confirms the real problem and sends a quote before any repair.'),
        faq('How do I book a repair?', 'Run the free AI check, then choose “Book an inspection”, or open your repair shop’s own booking link. You get a repair code and a tracking link.'),
        faq('How do I follow my repair?', 'Use “Track a repair” with your repair code and the last 4 digits of your mobile number. You can also approve a quote there.'),
        faq('I own a repair shop. How do I join?', 'Register your shop from “For businesses”. New shops are reviewed by the RepairSync AI administrator first. Once approved, your shop is listed and gets its own booking link.'),
        faq('Is this a finished product?', 'It is a capstone prototype. Sample data is used for demonstration, and text messages and payments are simulated. Real accuracy depends on each shop building its own history of verified repairs.'))),
    h('section', { class: 'about-block about-credit' },
      h('p', { class: 'muted small' }, 'RepairSync AI is a capstone project of the College of Computer Studies, University of Cebu – Main Campus. Developed by Alber E. Torrepalma, Arvy Sabalande, Klein Ople and Jal Piolo Rivera.')));
}
function pageAbout() {
  mount(h('section', { class: 'hero' }, h('div', null, h('span', { class: 'door-tag' }, 'About'), h('h1', null, 'About RepairSync AI'), h('p', null, 'What the system is for, where it is headed, and how it helps repair shops and their clients.'))), aboutSections());
}

// ---------- business side: pitch + shop sign-up ----------
function pageBusiness() {
  const feat = (icon, t, d) => h('div', { class: 'card feat' },
    h('div', { class: 'feat-head' },
      h('span', { class: 'feat-icon' }, h('img', { src: url('/Images/' + icon), alt: '', loading: 'lazy' })),
      h('h3', null, t)),
    h('p', { class: 'muted small' }, d));
  mount(
    h('section', { class: 'hero' },
      h('div', null,
        h('span', { class: 'door-tag' }, 'For businesses'),
        h('h1', null, 'Automation for gadget repair shops'),
        h('p', null, 'RepairSync AI replaces handwritten logbooks and scattered messages with one system for intake, diagnosis, quotes, repairs, pick-up and reports. Technicians get AI suggestions backed by real repair cases.'))),
    h('h2', { style: 'margin-top:28px' }, 'What your shop gets'),
    h('div', { class: 'grid g3' },
      feat('trackicon.png', 'Workflow board', 'Every repair on one board from Booked to Released, with priorities, overdue flags and technician workload.'),
      feat('diagnosticicon.png', 'AI diagnostic assistant', 'Technicians see ranked likely causes with the similar past cases behind each one, and decide what is true.'),
      feat('recordsicon.png', 'A knowledge base that learns', 'Verified repairs become new cases after an administrator approves them, and only improve your own shop’s AI.'),
      feat('statusicon.png', 'Customer tracking and quotes', 'Clients follow progress with a repair code, and approve or decline your quote online.'),
      feat('manageicon.png', 'Staff roles and branches', 'Owner, administrator, front desk and technician roles; technicians do not see customers’ contact details.'),
      feat('monitoricon.png', 'Reports and privacy tools', 'Turnaround, workload and AI feedback reports, an audit log, and customer data erasure on request.')),
    h('div', { class: 'grid g2', style: 'margin-top:16px' },
      h('div', { class: 'card' }, h('h3', null, 'Getting started'), h('ol', { class: 'clean' }, ['Register your shop (about a minute).', 'Add your staff and any extra branches’ technicians.', 'Take walk-ins or let clients book online from the client page.', 'Verify each AI suggestion at the bench; approved cases make your AI better.'].map((t) => h('li', null, t)))),
      h('div', { class: 'card' }, h('h3', null, 'Your data stays yours'), h('ul', { class: 'clean' }, ['Each shop is a separate space; other shops cannot see your repairs, staff, customers or cases.', 'Passwords are hashed, access is role-based, and actions are logged.', 'Prototype limits: SMS/email notifications and payments are simulated records, not live gateways.'].map((t) => h('li', null, t))))));
}

// ---------- client side: free AI check, shop finder, tracking ----------
// Free AI check card (shared by the client page and each shop's own page). slug = use that shop's knowledge base.
function aiCheck({ slug = null, bookPath = '#/book', extra = null } = {}) {
  const out = h('div');
  const check = h('form', { class: 'card ai' },
    h('h2', { class: 'ai-title' },
      h('img', { class: 'ai-title-icon', src: url('/Images/diagnosticicon.png'), alt: '', loading: 'lazy' }),
      h('span', null, 'Free AI check')),
    h('p', { class: 'muted small' }, 'Tell us what’s wrong, in English, Tagalog or Cebuano. No sign-up, and we don’t store what you type.'),
    h('div', { class: 'grid g3' },
      field('Device type', select('deviceType', [['smartphone', 'Smartphone'], ['laptop', 'Laptop'], ['tablet', 'Tablet'], ['other', 'Other gadget']])),
      field('Brand (optional)', input('brand', { ph: 'e.g. Samsung' })), field('Model (optional)', input('model', { ph: 'e.g. Galaxy A52' }))),
    field('What is the problem?', h('textarea', { name: 'symptoms', required: true, minlength: 8, maxlength: 1000, placeholder: 'e.g. Nahulog ang phone ko at may green lines sa screen, or: ayaw mag-charge ang laptop ko…' })),
    h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { class: 'primary', type: 'submit' }, 'Check my device')));
  check.addEventListener('submit', guarded(async (e) => {
    e.preventDefault();
    const d = formData(check);
    const r = await api('POST', '/api/public/diagnose', slug ? { ...d, shop: slug } : d);
    const qs = new URLSearchParams({ device: d.deviceType, brand: d.brand || '', model: d.model || '', symptoms: d.symptoms });
    out.replaceChildren(h('div', { class: 'card' },
      r.found ? [
        h('h2', null, 'What it could be'),
        r.areas.map((a, i) => h('div', { class: 'rec' + (i === 0 ? ' first' : '') }, h('div', { class: 'row between' }, h('b', null, a.name), h('span', { class: `conf ${a.confidence}` }, a.confidence + ' match')), a.why && h('div', { class: 'small muted', style: 'margin-top:4px' }, a.why))),
        r.safety.length ? h('div', { class: 'notice warn-box', style: 'margin-top:10px' }, h('b', null, 'Do this now'), h('ul', { class: 'clean' }, r.safety.map((t) => h('li', null, t)))) : null,
        h('p', { style: 'margin-top:10px' }, r.next_step),
      ] : [h('h2', null, 'We need a little more detail'), h('p', null, r.message)],
      h('div', { class: 'disclaimer' }, r.disclaimer),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('a', { class: 'btn primary', href: `${bookPath}?${qs}` }, 'Book an inspection'), extra && extra())));
    out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }));

  return { check, out };
}

// ---------- public map finder helpers (Leaflet + OSM/OSRM/Nominatim, no keys) ----------
const OPEN_DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const DAY_LABELS = { mon: 'Monday', tue: 'Tuesday', wed: 'Wednesday', thu: 'Thursday', fri: 'Friday', sat: 'Saturday', sun: 'Sunday' };
const DEFAULT_MAP_CENTER = [10.3157, 123.8854]; // Cebu City fallback before location is granted

function hasPin(s) {
  return s && s.lat !== null && s.lng !== null && s.lat !== undefined && s.lng !== undefined &&
    Number.isFinite(Number(s.lat)) && Number.isFinite(Number(s.lng));
}
function fmtClock(value) {
  const [hh, mm] = String(value).split(':').map(Number);
  return new Date(2000, 0, 1, hh, mm).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}
function minutesOf(value) { const [h, m] = String(value).split(':').map(Number); return h * 60 + m; }
function shopOpenStatus(shop, now = new Date()) {
  const hours = shop && shop.opening_hours;
  if (!hours || !Object.keys(hours).length) return { open: null, label: 'Hours not set' };
  const today = now.getDay(), current = now.getHours() * 60 + now.getMinutes();
  const ranges = (hours[OPEN_DAYS[today]] || []).filter((r) => Array.isArray(r) && r.length === 2);
  if (ranges.some(([a, b]) => current >= minutesOf(a) && current < minutesOf(b))) return { open: true, label: 'Open now' };
  for (let offset = 0; offset <= 7; offset++) {
    const day = OPEN_DAYS[(today + offset) % 7];
    const starts = (hours[day] || []).map((r) => minutesOf(r[0])).filter((m) => offset > 0 || m > current).sort((a, b) => a - b);
    if (!starts.length) continue;
    const when = new Date(now); when.setDate(now.getDate() + offset);
    when.setHours(Math.floor(starts[0] / 60), starts[0] % 60, 0, 0);
    const original = (hours[day] || []).find((r) => minutesOf(r[0]) === starts[0]);
    const dayText = offset === 0 ? '' : offset === 1 ? ' tomorrow' : ' ' + when.toLocaleDateString('en-PH', { weekday: 'short' });
    return { open: false, label: `Closed · Opens ${fmtClock(original[0])}${dayText}` };
  }
  return { open: false, label: 'Closed' };
}
function openStatusNode(status) {
  const kind = status.open === true ? 'open' : status.open === false ? 'closed' : 'unknown';
  return h('span', { class: `open-pill ${kind}` }, status.label);
}
function haversineKm(a, b) {
  const toRad = (n) => n * Math.PI / 180, R = 6371;
  const dLat = toRad(b.lat - a.lat), dLng = toRad(b.lng - a.lng);
  const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(s));
}
function fmtDistance(m) { return m < 1000 ? `${Math.round(m)} m` : `${(m / 1000).toFixed(1)} km`; }
function fmtDuration(sec) {
  const mins = Math.max(1, Math.round(sec / 60));
  return mins < 60 ? `${mins} min` : `${Math.floor(mins / 60)} h ${mins % 60} min`;
}
function mapTileLayer() {
  return L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap contributors'
  });
}
function shopAvatarNode(shop, cls = 'shop-finder-avatar') {
  if (shop.logo_path || shop.logo_url) {
    const img = h('img', { class: cls, src: shop.logo_path ? shopLogo(shop.logo_path) : url(shop.logo_url), alt: '' });
    return img;
  }
  return h('span', { class: cls + ' letter' }, String(shop.name || '?').trim().charAt(0).toUpperCase());
}
let shopPinSeq = 0;
function shopPinIcon(shop, active = false) {
  const ns = 'http://www.w3.org/2000/svg';
  const svgNode = (tag, attrs = {}) => {
    const node = document.createElementNS(ns, tag);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    return node;
  };
  const uid = `shop-pin-${++shopPinSeq}`;
  const pinPath = 'M21 1C10.5 1 2 9.5 2 21c0 12.5 19 30 19 30s19-17.5 19-30C40 9.5 31.5 1 21 1Z';
  const faceClip = `url(#${uid}-face)`;

  const svg = svgNode('svg', {
    class: 'shop-pin-svg' + (active ? ' active' : ''),
    viewBox: '0 0 42 52', width: 42, height: 52, 'aria-hidden': 'true'
  });
  const defs = svgNode('defs');
  const clip = svgNode('clipPath', { id: `${uid}-face` });
  clip.append(svgNode('circle', { cx: 21, cy: 21, r: 18 }));
  defs.append(clip);
  svg.append(
    defs,
    svgNode('path', { class: 'shop-pin-shell', d: pinPath }),
    svgNode('circle', { class: 'shop-pin-face', cx: 21, cy: 21, r: 18 }),
    svgNode('path', { class: 'shop-pin-face', d: 'M9 27 21 48 33 27Z' })
  );

  const logo = shop.logo_path || shop.logo_url;
  if (logo) {
    const src = shop.logo_path ? shopLogo(shop.logo_path) : url(shop.logo_url);
    const image = svgNode('image', {
      href: src, x: 3, y: 3, width: 36, height: 36,
      preserveAspectRatio: 'xMidYMid slice', 'clip-path': faceClip
    });
    image.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', src);
    svg.append(image);
  } else {
    const initial = svgNode('text', {
      class: 'shop-pin-initial', x: 21, y: 22,
      'text-anchor': 'middle', 'dominant-baseline': 'middle'
    });
    initial.textContent = String(shop.name || '?').trim().charAt(0).toUpperCase();
    svg.append(initial);
  }
  return L.divIcon({ html: svg, className: 'shop-pin-wrap', iconSize: [42, 52], iconAnchor: [21, 51], popupAnchor: [0, -48] });
}
async function fetchRoute(from, to) {
  const endpoint = `https://router.project-osrm.org/route/v1/driving/${from.lng},${from.lat};${to.lng},${to.lat}`
    + '?overview=simplified&geometries=geojson&steps=true';
  const res = await timedFetch(endpoint);
  if (!res.ok) throw new Error('The routing service is unavailable right now.');
  const data = await res.json();
  const route = data.code === 'Ok' && data.routes && data.routes[0];
  if (!route || !route.geometry || !route.geometry.coordinates) throw new Error('No route could be found to that shop.');
  const coords = route.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
  const cumulative = [0];
  for (let i = 1; i < coords.length; i++) {
    cumulative[i] = cumulative[i - 1] + haversineKm({ lat: coords[i - 1][0], lng: coords[i - 1][1] }, { lat: coords[i][0], lng: coords[i][1] }) * 1000;
  }
  return { coords, cumulative, distance: route.distance, duration: route.duration, steps: (route.legs && route.legs[0] && route.legs[0].steps) || [] };
}
function nearestOnRoute(pos, route) {
  let best = Infinity, index = 0;
  for (let i = 0; i < route.coords.length; i++) {
    const d = haversineKm(pos, { lat: route.coords[i][0], lng: route.coords[i][1] }) * 1000;
    if (d < best) { best = d; index = i; }
  }
  return { distanceM: best, traveledM: route.cumulative[index] || 0 };
}
function nextTurn(route, traveledM) {
  let passed = 0;
  for (const step of route.steps) {
    passed += step.distance || 0;
    if (passed > traveledM) return { instruction: (step.maneuver && step.maneuver.instruction) || 'Continue along the route', distanceM: passed - traveledM };
  }
  return { instruction: 'Arrive at the shop', distanceM: 0 };
}
async function timedFetch(input, timeoutMs = 12000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try { return await fetch(input, { signal: controller.signal }); }
  catch (err) {
    if (err.name === 'AbortError') throw new Error('The request timed out. Please try again.');
    throw err;
  }
  finally { clearTimeout(timer); }
}
async function geocodeAddress(query) {
  const u = new URL('https://nominatim.openstreetmap.org/search');
  u.searchParams.set('q', query);
  u.searchParams.set('format', 'jsonv2');
  u.searchParams.set('limit', '5');
  const res = await timedFetch(u.toString());
  if (!res.ok) throw new Error('Address search is unavailable right now.');
  const rows = await res.json();
  return (Array.isArray(rows) ? rows : []).map((r) => ({ lat: Number(r.lat), lng: Number(r.lon), label: r.display_name }));
}
function locationProblem(err) {
  if (err && err.code === 1) return new Error('Location permission was denied. Enable it in your browser to sort shops by distance and use directions.');
  if (err && err.code === 2) return new Error('Your location could not be determined. You can still browse shops on the map.');
  if (err && err.code === 3) return new Error('The location request timed out. You can still browse shops on the map.');
  return new Error(err?.message || 'Location is unavailable on this device.');
}
function browserPosition(pos) {
  return { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy };
}
function getBrowserLocation(highAccuracy = false) {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('This browser does not support location. You can still browse shops on the map.')); return; }
    navigator.geolocation.getCurrentPosition((p) => resolve(browserPosition(p)), reject, {
      enableHighAccuracy: highAccuracy, timeout: highAccuracy ? 15000 : 12000, maximumAge: highAccuracy ? 1000 : 300000,
    });
  });
}
const mapAccent = () => getComputedStyle(document.documentElement).getPropertyValue('--green-d').trim() || '#8BBF78';

// One finder map at a time; these globals let the router stop live navigation when the page changes.
let customerMap = null;
let customerMapMarkers = new Map();
let customerRows = new Map();
let customerUserMarker = null;
let customerRouteLine = null;
let customerRoute = null;
let customerNavTarget = null;
let customerNavWatch = null;
let customerNavToken = 0;

function stopCustomerNavigation() {
  if (customerNavWatch !== null && navigator.geolocation) navigator.geolocation.clearWatch(customerNavWatch);
  customerNavWatch = null;
  customerNavToken++;
  customerRoute = null;
  customerNavTarget = null;
}
function cleanupCustomerFinder() {
  stopCustomerNavigation();
  if (customerMap) { try { customerMap.remove(); } catch { /* already gone */ } customerMap = null; }
  customerMapMarkers = new Map();
  customerRows = new Map();
  customerUserMarker = null;
  customerRouteLine = null;
}

let settingsMap = null;
let settingsPin = null;
function cleanupSettingsMap() {
  if (settingsMap) { try { settingsMap.remove(); } catch { /* already gone */ } settingsMap = null; }
  settingsPin = null;
}

function hoursRangeRow(day, range, onChange, onRemove) {
  const from = h('input', { type: 'time', class: 'hours-time', 'data-part': 'start', value: range?.[0] || '09:00', 'aria-label': `${DAY_LABELS[day]} opening time` });
  const to = h('input', { type: 'time', class: 'hours-time', 'data-part': 'end', value: range?.[1] || '18:00', 'aria-label': `${DAY_LABELS[day]} closing time` });
  [from, to].forEach((el) => el.addEventListener('change', onChange));
  return h('div', { class: 'row hours-range', 'data-hours-day': day },
    from, h('span', { class: 'muted' }, '–'), to,
    h('button', { class: 'small', type: 'button', 'aria-label': `Remove ${DAY_LABELS[day]} time range`, onclick: () => onRemove() }, 'Remove'));
}
function openingHoursEditor(initial, onChange) {
  const wrap = h('div', { class: 'hours-editor' });
  const addRange = (day, host, range) => {
    host.querySelector('[data-empty]')?.remove();
    const row = hoursRangeRow(day, range, onChange, () => {
      row.remove();
      if (!host.querySelector('.hours-range')) host.append(h('span', { class: 'muted small', 'data-empty': '1' }, 'Closed'));
      onChange();
    });
    host.append(row);
  };
  Object.keys(DAY_LABELS).forEach((day) => {
    const ranges = Array.isArray(initial?.[day]) ? initial[day] : [];
    const host = h('div', { class: 'hours-day-ranges' });
    ranges.forEach((r) => { if (Array.isArray(r) && r.length === 2) addRange(day, host, r); });
    if (!host.querySelector('.hours-range')) host.append(h('span', { class: 'muted small', 'data-empty': '1' }, 'Closed'));
    wrap.append(h('div', { class: 'hours-day' },
      h('div', { class: 'row between' },
        h('b', { class: 'small' }, DAY_LABELS[day]),
        h('button', { class: 'small', type: 'button', onclick: () => addRange(day, host) }, '+ Add hours')),
      host));
  });
  return {
    node: wrap,
    collect() {
      const out = {};
      wrap.querySelectorAll('.hours-day').forEach((dayEl) => {
        const day = dayEl.querySelector('.hours-range')?.dataset.hoursDay;
        if (!day) return;
        out[day] = [...dayEl.querySelectorAll('.hours-range')].map((row) => {
          const start = row.querySelector('[data-part="start"]').value;
          const end = row.querySelector('[data-part="end"]').value;
          if (start && end && start >= end) throw new Error(`End time must be after start time on ${DAY_LABELS[day]}.`);
          return start && end ? [start, end] : null;
        }).filter(Boolean);
      });
      Object.keys(DAY_LABELS).forEach((day) => { if (!out[day]) out[day] = []; });
      return Object.values(out).every((r) => !r.length) ? null : out;
    },
  };
}

// ---------- shared public nearby-shops finder (embedded box + full route) ----------
function createFinder({ full = false } = {}) {
  let shops = [];
  let loaded = false;
  let userPos = null;
  let mapOpen = full;
  let selectedSlug = null;
  let navigating = false;
  let lastRecalc = 0;
  let openOnly = false;

  const search = h('input', { type: 'search', placeholder: 'Search by shop name or city…', 'aria-label': 'Search shops', autocomplete: 'off' });
  const note = h('p', { class: 'finder-note muted small', role: 'status' }, 'Requesting your location to show the nearest shops…');
  const list = h('div', { class: 'shop-finder-list' });
  const mapHost = h('div', { class: 'finder-map' + (full ? ' full' : ''), hidden: !full, 'aria-label': 'Nearby repair shops map' });
  const navPanel = h('div', { class: 'finder-nav-panel', hidden: true, 'aria-live': 'polite' });
  const mapBtn = h('button', { class: 'small', type: 'button', 'aria-expanded': 'false' }, 'View on map');

  // Full-route-only controls. The embedded box keeps its original, simpler header.
  const openChipCount = h('span', null, '0');
  const openChip = h('button', {
    class: 'finder-chip', type: 'button', 'aria-pressed': 'false',
    title: 'Show only shops that are open right now',
    onclick: () => {
      openOnly = !openOnly;
      openChip.setAttribute('aria-pressed', String(openOnly));
      renderList();
      if (mapOpen) renderMap();
    },
  }, 'Open Now (', openChipCount, ')');
  const shopsChip = h('button', {
    class: 'finder-chip static', type: 'button', disabled: true,
    title: 'All listed results are repair shops; no other types are available',
  }, 'Shops');
  const filtersChip = h('button', {
    class: 'finder-chip static', type: 'button', disabled: true,
    title: 'Filters are not available yet',
  }, 'Filters ', h('small', null, 'soon'));
  const resultCount = h('span', null, '0');
  const resultLabel = h('span', null, 'shops');
  const resultSearch = h('button', {
    class: 'finder-icon-btn', type: 'button', title: 'Search shops', 'aria-label': 'Search shops',
    onclick: () => { search.focus(); search.select(); },
  }, '⌕');

  const sideToggle = h('button', {
    class: 'finder-side-toggle', type: 'button', title: 'Collapse sidebar', 'aria-label': 'Collapse sidebar', 'aria-expanded': 'true',
  }, '«');
  const sideLink = h('a', { class: 'finder-side-link on', href: '#/find', 'aria-current': 'page' },
    h('span', { class: 'finder-side-icon', 'aria-hidden': 'true' }, '◎'), h('span', { class: 'finder-side-label' }, 'Nearby'));
  const finderSidebar = h('aside', { class: 'finder-sidebar', 'aria-label': 'Nearby shops view' },
    h('div', { class: 'finder-side-head' }, h('span', { class: 'finder-side-title' }, 'Nearby shops'), sideToggle),
    h('nav', null, sideLink));
  const sideScrim = h('div', { class: 'finder-side-scrim', 'aria-hidden': 'true' });

  const setNote = (text, tone = 'muted') => {
    note.textContent = text;
    note.className = `finder-note small ${tone === 'error' ? 'error' : 'muted'}`;
  };
  const matchedShops = () => {
    const term = search.value.trim().toLowerCase();
    const found = shops.filter((s) => !term || [s.name, s.city].some((v) => String(v || '').toLowerCase().includes(term)));
    if (!userPos) return found; // Location denied: keep the directory's original, unsorted order.
    return found.slice().sort((a, b) => {
      const da = hasPin(a) ? haversineKm(userPos, a) : Infinity;
      const db = hasPin(b) ? haversineKm(userPos, b) : Infinity;
      return da - db || String(a.name).localeCompare(String(b.name));
    });
  };
  const visibleShops = () => full && openOnly
    ? matchedShops().filter((s) => shopOpenStatus(s).open === true)
    : matchedShops();
  const updateCounts = () => {
    if (!full) return;
    const n = visibleShops().length;
    resultCount.textContent = String(n);
    resultLabel.textContent = n === 1 ? 'shop' : 'shops';
    openChipCount.textContent = String(matchedShops().filter((s) => shopOpenStatus(s).open === true).length);
  };

  function renderList() {
    if (!loaded) { list.replaceChildren(h('p', { class: 'muted small' }, 'Loading shops…')); updateCounts(); return; }
    const rows = visibleShops();
    if (!rows.length) { customerRows.clear(); list.replaceChildren(h('p', { class: 'muted small' }, 'No shops found.')); updateCounts(); return; }
    customerRows.clear();
    list.replaceChildren(...rows.map((s) => {
      const status = shopOpenStatus(s);
      const distance = userPos && hasPin(s) ? `${fmtDistance(haversineKm(userPos, s) * 1000)} away` : null;
      const row = h('div', {
        class: `shop-finder-row${full ? ' compact' : ''}${selectedSlug === s.slug ? ' selected' : ''}`, role: 'button', tabindex: '0',
        onclick: (e) => { if (!e.target.closest('a, button')) selectShop(s); },
        onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectShop(s); } },
      },
        shopAvatarNode(s),
        h('div', { class: 'shop-finder-main' },
          h('div', { class: 'row between' }, h('b', null, s.name), openStatusNode(status)),
          h('div', { class: 'muted small' }, [s.city, s.address, distance].filter(Boolean).join(' · ') || 'Address not set yet'),
          !hasPin(s) ? h('div', { class: 'muted small' }, 'Map pin not set yet') : null),
        h('div', { class: 'finder-actions' },
          h('a', {
            class: 'btn small primary', href: `#/shop/${encodeURIComponent(s.slug)}/book`,
            onclick: (e) => e.stopPropagation(),
          }, 'Book Appointment'),
          hasPin(s) ? h('button', {
            class: 'small', type: 'button', onclick: (e) => { e.stopPropagation(); planDirections(s); },
          }, 'Directions') : null));
      customerRows.set(s.slug, row);
      return row;
    }));
    updateCounts();
  }

  function updateUserMarker() {
    if (!customerMap || !userPos) return;
    const latlng = [userPos.lat, userPos.lng];
    if (customerUserMarker) customerUserMarker.setLatLng(latlng);
    else customerUserMarker = L.circleMarker(latlng, {
      radius: 8, color: '#fff', weight: 3, fillColor: '#2f6f9f', fillOpacity: 1,
    }).addTo(customerMap).bindPopup('You are here');
  }

  function popupFor(s) {
    const status = shopOpenStatus(s);
    return h('div', { class: 'shop-popup' },
      h('div', { class: 'row' }, shopAvatarNode(s, 'shop-finder-avatar'), h('b', null, s.name)),
      h('div', { class: 'muted small', style: 'margin-top:6px' }, s.address || s.city || 'Address not set yet'),
      h('div', { style: 'margin-top:6px' }, openStatusNode(status)),
      h('div', { class: 'row', style: 'margin-top:10px' },
        h('a', { class: 'btn small primary', href: `#/shop/${encodeURIComponent(s.slug)}/book` }, 'Book Appointment'),
        h('button', { class: 'small', type: 'button', onclick: () => planDirections(s) }, 'Directions')));
  }

  function renderMap(fit = true) {
    if (!customerMap) return;
    const pins = visibleShops().filter(hasPin);
    const keep = new Set(pins.map((s) => s.slug));
    customerMapMarkers.forEach((marker, slug) => {
      if (keep.has(slug)) return;
      customerMap.removeLayer(marker);
      customerMapMarkers.delete(slug);
    });
    pins.forEach((s) => {
      const latlng = [Number(s.lat), Number(s.lng)];
      let marker = customerMapMarkers.get(s.slug);
      if (!marker) {
        marker = L.marker(latlng, { icon: shopPinIcon(s), title: s.name }).bindPopup(popupFor(s)).addTo(customerMap);
        marker.on('click', () => {
          selectedSlug = s.slug;
          marker.openPopup();
          customerRows.forEach((row, slug) => row.classList.toggle('selected', slug === s.slug));
          customerRows.get(s.slug)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          // Defer the icon swap so Leaflet can finish opening this marker's popup first.
          setTimeout(() => customerMapMarkers.forEach((m, slug) => m.setIcon(shopPinIcon(shops.find((x) => x.slug === slug), slug === selectedSlug))), 0);
        });
        customerMapMarkers.set(s.slug, marker);
      }
      marker.setIcon(shopPinIcon(s, selectedSlug === s.slug));
      marker.setPopupContent(popupFor(s));
    });
    updateUserMarker();
    if (!fit) return;
    const points = [...pins.map((s) => [Number(s.lat), Number(s.lng)]), ...(userPos ? [[userPos.lat, userPos.lng]] : [])];
    if (points.length > 1) customerMap.fitBounds(L.latLngBounds(points).pad(0.2), { maxZoom: 14 });
    else customerMap.setView(points[0] || DEFAULT_MAP_CENTER, points.length ? 14 : 12);
  }

  function initMap() {
    if (!L) {
      mapHost.replaceChildren(h('p', { class: 'error small' }, 'The map library could not be loaded. Please refresh the page.'));
      return;
    }
    customerMap = L.map(mapHost, {
      zoomControl: false, scrollWheelZoom: false,
      zoomAnimation: false, fadeAnimation: false, markerZoomAnimation: false,
    }).setView(DEFAULT_MAP_CENTER, 12);
    mapTileLayer().addTo(customerMap);
    L.control.zoom({ position: 'bottomright' }).addTo(customerMap);
    const locate = L.control({ position: 'bottomright' });
    locate.onAdd = () => {
      const btn = h('button', { class: 'leaflet-control-locate', type: 'button', title: 'Find my location', 'aria-label': 'Find my location' }, '◎');
      btn.addEventListener('click', async () => {
        try {
          userPos = await getBrowserLocation(true);
          setNote('Showing nearby shops by distance. Directions are ready.');
          updateUserMarker();
          renderMap();
        } catch (err) { setNote(locationProblem(err).message, 'error'); }
      });
      return btn;
    };
    locate.addTo(customerMap);
    if (full) {
      // Reuse the site's existing theme switch; it stays available in the navbar too.
      const theme = L.control({ position: 'bottomright' });
      theme.onAdd = () => h('div', { class: 'leaflet-control-theme' }, themeToggle());
      theme.addTo(customerMap);
    }
    setTimeout(() => customerMap?.invalidateSize(), 0);
  }

  function showMap() {
    if (mapOpen) { if (!customerMap) initMap(); customerMap?.invalidateSize(); return; }
    mapOpen = true;
    mapHost.hidden = false;
    mapBtn.textContent = 'Hide map';
    mapBtn.setAttribute('aria-expanded', 'true');
    if (!customerMap) initMap();
    setTimeout(() => { customerMap?.invalidateSize(); renderMap(); }, 0);
  }
  function hideMap() {
    mapOpen = false;
    mapHost.hidden = true;
    mapBtn.textContent = 'View on map';
    mapBtn.setAttribute('aria-expanded', 'false');
  }
  function selectShop(s) {
    selectedSlug = s.slug;
    customerRows.forEach((row, slug) => row.classList.toggle('selected', slug === s.slug));
    if (!hasPin(s)) return;
    showMap();
    if (!customerMapMarkers.has(s.slug)) renderMap(false);
    const marker = customerMapMarkers.get(s.slug);
    if (!marker) return;
    customerMapMarkers.forEach((m, slug) => m.setIcon(shopPinIcon(shops.find((x) => x.slug === slug), slug === s.slug)));
    customerMap.panTo(marker.getLatLng(), { animate: true });
    marker.openPopup();
  }

  function drawRouteLine() {
    if (!customerMap || !customerRoute) return;
    if (customerRouteLine) customerMap.removeLayer(customerRouteLine);
    customerRouteLine = L.polyline(customerRoute.coords, { color: mapAccent(), weight: 5, opacity: 0.9 }).addTo(customerMap);
  }
  function navError(message) {
    navigating = false;
    navPanel.hidden = false;
    navPanel.replaceChildren(
      h('p', { class: 'error small' }, message),
      h('div', { class: 'row' },
        h('button', { class: 'small primary', type: 'button', onclick: () => startNavigation() }, 'Retry'),
        h('button', { class: 'small', type: 'button', onclick: () => endNavigation() }, 'End navigation')));
  }
  function showRoutePlan() {
    if (!customerRoute || !customerNavTarget) return;
    const turn = nextTurn(customerRoute, 0);
    navPanel.hidden = false;
    navPanel.replaceChildren(
      h('div', { class: 'row between' },
        h('b', null, `Route to ${customerNavTarget.name}`),
        h('span', { class: 'muted small' }, `${fmtDistance(customerRoute.distance)} · ${fmtDuration(customerRoute.duration)}`)),
      h('div', { class: 'muted small' }, `Next: ${turn.instruction} in ${fmtDistance(turn.distanceM)}`),
      h('div', { class: 'row', style: 'margin-top:8px' },
        h('button', { class: 'small primary', type: 'button', onclick: () => startNavigation() }, 'Start'),
        h('button', { class: 'small', type: 'button', onclick: () => endNavigation() }, 'Clear route')));
  }
  function showProgress(near) {
    if (!customerRoute || !customerNavTarget) return;
    const remaining = Math.max(0, customerRoute.distance - near.traveledM);
    const eta = customerRoute.distance ? customerRoute.duration * (remaining / customerRoute.distance) : 0;
    const turn = nextTurn(customerRoute, near.traveledM);
    navPanel.hidden = false;
    navPanel.replaceChildren(
      h('div', { class: 'row between' },
        h('b', null, `Navigating to ${customerNavTarget.name}`),
        h('button', { class: 'small danger', type: 'button', onclick: () => endNavigation() }, 'End')),
      h('div', { class: 'nav-turn' }, turn.instruction),
      h('div', { class: 'nav-metrics small' },
        h('span', null, `In ${fmtDistance(turn.distanceM)}`),
        h('span', null, `${fmtDistance(remaining)} left`),
        h('span', null, `ETA ${fmtDuration(eta)}`)));
  }
  function endNavigation() {
    if (customerNavWatch !== null && navigator.geolocation) navigator.geolocation.clearWatch(customerNavWatch);
    customerNavWatch = null;
    customerNavToken++;
    navigating = false;
    if (customerRouteLine && customerMap) customerMap.removeLayer(customerRouteLine);
    customerRouteLine = null;
    customerRoute = null;
    customerNavTarget = null;
    navPanel.hidden = true;
    navPanel.replaceChildren();
    renderMap();
  }
  async function recalculateRoute(pos, token) {
    if (!customerNavTarget) return;
    navPanel.hidden = false;
    navPanel.replaceChildren(h('p', { class: 'muted small' }, 'Recalculating route…'));
    try {
      const fresh = await fetchRoute(pos, customerNavTarget);
      if (token !== customerNavToken || !navigating) return;
      customerRoute = fresh;
      drawRouteLine();
      showProgress({ distanceM: 0, traveledM: 0 });
    } catch (err) { if (token === customerNavToken) navError(err.message); }
  }
  function startNavigation() {
    if (!customerRoute || !customerNavTarget) { planDirections(customerNavTarget || visibleShops()[0]); return; }
    if (!navigator.geolocation) { navError('This browser cannot follow your location. The route is still available above.'); return; }
    if (customerNavWatch !== null) navigator.geolocation.clearWatch(customerNavWatch);
    navigating = true;
    lastRecalc = 0;
    const token = ++customerNavToken;
    navPanel.hidden = false;
    navPanel.replaceChildren(h('p', { class: 'muted small' }, 'Getting your position…'));
    customerNavWatch = navigator.geolocation.watchPosition((p) => {
      if (token !== customerNavToken || !navigating) return;
      userPos = browserPosition(p);
      updateUserMarker();
      const near = nearestOnRoute(userPos, customerRoute);
      if (near.distanceM > 80 && Date.now() - lastRecalc > 8000) {
        lastRecalc = Date.now();
        recalculateRoute(userPos, token);
        return;
      }
      showProgress(near);
    }, (err) => {
      if (token !== customerNavToken) return;
      const problem = locationProblem(err);
      if (err && err.code === 1) navError(`${problem.message} Navigation stopped.`);
      else { navPanel.hidden = false; navPanel.replaceChildren(h('p', { class: 'error small' }, `${problem.message} Retrying…`)); }
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 1000 });
  }
  async function planDirections(s) {
    if (!s || !hasPin(s)) { setNote('This shop has not set a map pin yet.', 'error'); return; }
    showMap();
    if (!userPos) {
      setNote('We need your location to draw a route. Please allow location access.', 'error');
      try { userPos = await getBrowserLocation(); setNote('Showing nearby shops by distance. Directions are ready.'); }
      catch (err) { setNote(locationProblem(err).message, 'error'); navError(locationProblem(err).message); return; }
    }
    if (navigating) endNavigation();
    navPanel.hidden = false;
    navPanel.replaceChildren(h('p', { class: 'muted small' }, 'Finding the best route…'));
    try {
      customerRoute = await fetchRoute(userPos, s);
      customerNavTarget = s;
      drawRouteLine();
      customerMap.fitBounds(customerRouteLine.getBounds(), { padding: [24, 24] });
      updateUserMarker();
      showRoutePlan();
    } catch (err) {
      navError(err.message);
    }
  }

  const loadShops = guarded(async () => {
    shops = await api('GET', '/api/public/shops');
    loaded = true;
    renderList();
    if (mapOpen) renderMap();
  });

  let shell = null;
  const isMobile = () => window.matchMedia('(max-width: 900px)').matches;
  let sideExpanded = !isMobile();
  function setSide(expanded) {
    if (!shell) return;
    if (isMobile()) {
      shell.classList.toggle('is-side-expanded', expanded);
      shell.classList.remove('is-side-collapsed');
    } else {
      shell.classList.toggle('is-side-collapsed', !expanded);
      shell.classList.remove('is-side-expanded');
    }
    sideToggle.textContent = expanded ? '«' : '»';
    sideToggle.setAttribute('aria-expanded', String(expanded));
    sideToggle.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
    sideToggle.setAttribute('title', expanded ? 'Collapse sidebar' : 'Expand sidebar');
    setTimeout(() => customerMap?.invalidateSize(), 220);
  }
  sideToggle.addEventListener('click', () => { sideExpanded = !sideExpanded; setSide(sideExpanded); });
  sideScrim.addEventListener('click', () => { sideExpanded = false; setSide(false); });

  const card = full
    ? (() => {
        const mapPanel = h('div', { class: 'finder-map-panel' }, mapHost, navPanel);
        const topbar = h('div', { class: 'finder-topbar' },
          h('div', { class: 'finder-search-wrap' }, search),
          h('div', { class: 'finder-chips' }, openChip, shopsChip, filtersChip));
        const results = h('aside', { class: 'finder-results', 'aria-label': 'Nearby shop results' },
          h('div', { class: 'finder-results-head' }, h('b', null, resultCount, ' ', resultLabel, ' nearby'), resultSearch),
          list);
        shell = h('div', { class: 'finder-shell' }, sideScrim,
          h('div', { class: 'finder-layout' }, finderSidebar,
            h('div', { class: 'finder-main' }, topbar, h('div', { class: 'finder-status' }, note),
              h('div', { class: 'finder-workspace' }, mapPanel, results))));
        setSide(sideExpanded);
        return shell;
      })()
    : h('div', { class: 'card shop-finder', id: 'shops' },
        h('div', { class: 'finder-head' }, h('h2', null, 'Find a repair shop'),
          h('div', { class: 'row' }, h('a', { class: 'btn small', href: '#/find' }, 'Open full map'), mapBtn)),
        search, note, mapHost, navPanel,
        h('div', { class: 'finder-list-title' }, 'Registered shops', h('span', { class: 'muted small' }, 'Active and listed only')),
        list);

  search.addEventListener('input', () => {
    clearTimeout(card.t);
    card.t = setTimeout(() => { renderList(); if (mapOpen) renderMap(); }, 150);
  });
  if (!full) mapBtn.addEventListener('click', () => mapOpen ? hideMap() : showMap());

  function start() {
    loadShops();
    if (full) setTimeout(() => { if (!customerMap) initMap(); renderMap(); }, 0);
    getBrowserLocation().then((pos) => {
      userPos = pos;
      setNote('Showing nearby shops by distance. Directions are ready.');
      renderList();
      if (mapOpen) renderMap();
    }).catch((err) => {
      userPos = null;
      setNote(locationProblem(err).message, 'error');
      renderList();
      if (mapOpen) renderMap();
    });
  }

  return { card, start };
}

function pageCustomer() {
  const finder = createFinder();
  const { check, out } = aiCheck({ extra: () => h('a', { class: 'btn', href: '#shops', onclick: (ev) => { ev.preventDefault(); finder.card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }, 'Find a shop') });

  const trackBox = h('form', { class: 'card' },
    h('h2', null, 'Track my repair'),
    h('p', { class: 'muted small' }, 'Enter your repair code and the last 4 digits of your mobile number.'),
    field('Repair code', input('code', { required: true, ph: 'RS-2610-0001' })),
    field('Last 4 digits of mobile number', input('phone4', { required: true, max: 4, ph: '1234' })),
    h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Track')));
  trackBox.addEventListener('submit', (e) => { e.preventDefault(); const d = formData(trackBox); location.hash = `#/track?code=${encodeURIComponent(d.code)}&phone4=${encodeURIComponent(d.phone4)}`; });

  mount(
    h('section', { class: 'hero' },
      h('div', null, h('span', { class: 'door-tag' }, 'For clients'), h('h1', null, 'Not sure what’s wrong with your gadget?'),
        h('p', null, 'Get a quick AI check, then book an inspection with a repair shop. A technician always confirms the real problem and quotes before any repair.'))),
    h('div', { class: 'grid g2' }, h('div', { class: 'stack' }, check, out), h('div', { class: 'stack' }, finder.card, trackBox,
      h('div', { class: 'card' }, h('h3', null, 'How it works'), h('ol', { class: 'clean' }, ['Describe the problem and get a preliminary AI check.', 'Pick a shop and book; you get a repair code.', 'A technician inspects, confirms and sends you a quote.', 'You approve, they repair, and you follow each step until pick-up.'].map((t) => h('li', null, t)))))));
  finder.start();
}

// ---------- full-width nearby-shops finder route ----------
function pageFinder() {
  const finder = createFinder({ full: true });
  mount(
    h('section', { class: 'finder-page' },
      h('div', { class: 'finder-page-head' },
        h('span', { class: 'door-tag' }, 'For clients'),
        h('h1', null, 'Find a repair shop'),
        h('p', { class: 'muted' }, 'Search nearby shops, compare distances and hours, then book or start live directions.'),
        h('a', { class: 'btn small', href: '#/customer', style: 'margin-top:10px' }, 'Back to For Clients')),
      finder.card));
  finder.start();
}

// ---------- client side: booking at a chosen shop ----------
async function pageBook(q, fixedSlug = null) {
  let shops;
  if (fixedSlug) { try { shops = [await api('GET', '/api/public/shop/' + encodeURIComponent(fixedSlug))]; } catch { return pageShopUnavailable(); } }
  else shops = await api('GET', '/api/public/shops');
  if (!shops.length) return mount(h('div', { class: 'card' }, h('h2', null, 'No repair shops are registered yet'), h('p', null, 'Please check back soon.'), h('a', { class: 'btn', href: '#/customer' }, 'Back')));
  const result = h('div');
  const shopSel = h('select', { name: 'shop', disabled: !!fixedSlug }, shops.map((s) => h('option', { value: s.slug, selected: s.slug === q.shop }, `${s.name}${s.city ? ' – ' + s.city : ''}`)));
  const branchSel = h('select', { name: 'branchId' });
  const fillBranches = () => { const s = shops.find((x) => x.slug === shopSel.value); branchSel.replaceChildren(...s.branches.map((b) => h('option', { value: b.id }, b.name + (b.hours ? ` (${b.hours})` : '')))); };
  shopSel.addEventListener('change', fillBranches); fillBranches();
  const form = h('form', { class: 'card' },
    h('h2', null, 'Book a repair'),
    h('p', { class: 'muted small' }, 'Describe the problem in your own words. The shop’s AI gives a preliminary guess; a technician confirms it.'),
    me?.role === 'customer' ? h('div', { class: 'notice' }, `Booking as ${me.name}`) : [
      h('div', { class: 'grid g2' }, field('Your name', input('name', { required: true, ac: 'name' })), field('Mobile number', input('phone', { required: true, type: 'tel', ac: 'tel', ph: '09XX XXX XXXX' }))),
      field('Email (optional)', input('email', { type: 'email', ac: 'email' })),
    ],
    me?.role === 'customer' && !me.phone ? field('Mobile number', input('phone', { required: true, type: 'tel' })) : null,
    h('div', { class: 'grid g2' }, field('Repair shop', shopSel), field('Branch', branchSel)),
    h('div', { class: 'grid g2' }, field('Device type', select('deviceType', [['smartphone', 'Smartphone'], ['laptop', 'Laptop'], ['tablet', 'Tablet'], ['other', 'Other gadget']], q.device)),
      h('div', null)),
    h('div', { class: 'grid g2' }, field('Brand', input('brand', { ph: 'e.g. Samsung', value: q.brand })), field('Model', input('model', { ph: 'e.g. Galaxy A52', value: q.model }))),
    field('What is the problem?', h('textarea', { name: 'symptoms', required: true, minlength: 8, maxlength: 1000, value: q.symptoms || '', placeholder: 'e.g. My phone fell and the screen is cracked…' })),
    field('Accessories left with the device (optional)', input('accessories', { max: 200 })),
    h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'consent', required: true }), h('span', null, 'I agree that the selected repair shop may collect and process my name, contact details and device information to handle this repair, in line with the Data Privacy Act of 2012 (RA 10173). I can ask the shop for access, correction or erasure of my data.')),
    h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Submit repair request')));
  form.addEventListener('submit', guarded(async (e) => {
    e.preventDefault();
    const d = formData(form); d.consent = form.consent.checked; delete d.shop;
    const out = await api('POST', '/api/public/bookings', d);
    form.reset(); fillBranches();
    result.replaceChildren(h('div', { class: 'card ai' },
      h('h2', null, 'Request received'),
      h('div', { class: 'code-big' }, out.code),
      h('p', null, 'Bring your device to the selected branch and show this code. Save your tracking link to follow the progress:'),
      h('p', null, h('a', { href: out.track_path }, location.origin + out.track_path)),
      out.hint && h('div', { class: 'notice' }, h('b', null, 'Preliminary AI guess: '), out.hint.possible_areas.join(' / '), h('div', { class: 'small' }, out.hint.note))));
    result.scrollIntoView({ behavior: 'smooth' });
  }));
  mount(h('p', null, fixedSlug ? h('a', { href: `#/shop/${encodeURIComponent(fixedSlug)}` }, `← Back to ${shops[0].name}`) : h('a', { href: '#/customer' }, '← Back to client page')), h('div', { style: 'max-width:760px;margin:auto' }, form, result));
}

// ---------- a shop's own page (its personal booking link) ----------
function pageShopUnavailable() {
  mount(h('div', { class: 'card', style: 'max-width:560px;margin:30px auto' }, h('h2', null, 'This shop is not available for booking'),
    h('p', null, 'The link may be wrong, or the shop has not been approved yet. You can still use the free AI check and look for another shop.'),
    h('div', { class: 'row' }, h('a', { class: 'btn primary', href: '#/customer' }, 'Free AI check & find a shop'))));
}
async function pageShop(slug) {
  let shop;
  try { shop = await api('GET', '/api/public/shop/' + encodeURIComponent(slug)); } catch { return pageShopUnavailable(); }
  const enc = encodeURIComponent(slug);
  const { check, out } = aiCheck({ slug, bookPath: `#/shop/${enc}/book` });
  mount(
    h('section', { class: 'hero' },
      h('div', null, h('span', { class: 'door-tag' }, shop.city || 'Repair shop'), h('h1', null, shop.name),
        h('p', null, 'Get a free AI check, then book an inspection with us. A technician always confirms the real problem and sends you a quote before any repair.'),
        h('div', { class: 'row' }, h('a', { class: 'btn primary', href: `#/shop/${enc}/book` }, 'Book a repair'), h('a', { class: 'btn', href: '#/track' }, 'Track my repair'))),
      h('img', { src: shopLogo(shop.logo_path), alt: (shop.name || 'Shop') + ' logo' })),
    h('div', { class: 'grid g2' },
      h('div', { class: 'stack' }, check, out),
      h('div', { class: 'stack' },
        h('div', { class: 'card' }, h('h3', null, shop.branches.length === 1 ? 'Our branch' : 'Our branches'),
          shop.branches.map((b) => h('div', { class: 'rec' }, h('b', null, b.name), b.address && h('div', { class: 'muted small' }, b.address), b.hours && h('div', { class: 'small' }, '🕘 ' + b.hours), b.phone && h('div', { class: 'small' }, '📞 ' + b.phone)))),
        h('div', { class: 'card' }, h('h3', null, 'How it works'), h('ol', { class: 'clean' }, ['Describe the problem and get a preliminary AI check.', 'Book; you get a repair code and a tracking link.', 'Bring your device. A technician inspects and quotes.', 'Approve the quote online and follow each step until pick-up.'].map((t) => h('li', null, t)))))));
}

// ---------- platform administrator: review shop registrations ----------
async function pagePlatform() {
  const state = { status: 'pending' };
  const out = h('div'); const tabs = h('div', { class: 'seg', role: 'group', 'aria-label': 'Filter by status' });
  const TABS = [['pending', 'Pending'], ['active', 'Approved'], ['rejected', 'Rejected'], ['suspended', 'Suspended'], ['all', 'All']];
  const STAT = { pending: 'Pending review', active: 'Approved', rejected: 'Rejected', suspended: 'Suspended' };
  const act = guarded(async (s, action) => {
    const labels = { approve: 'Approve', reject: 'Reject', suspend: 'Suspend' };
    let note = '';
    if (action !== 'approve') {
      const d = await ask(`${labels[action]} “${s.name}”`, [field('Reason (the shop will see this)', h('textarea', { name: 'note', required: true, maxlength: 250, placeholder: action === 'reject' ? 'e.g. Could not verify the shop address' : 'e.g. Complaints from clients' }))], labels[action]);
      if (!d) return; note = d.note;
    }
    await api('POST', `/api/platform/shops/${s.id}/review`, { action, note });
    toast(action === 'approve' ? `${s.name} is approved and can now take bookings.` : `${s.name}: ${action === 'reject' ? 'rejected' : 'suspended'}.`);
    load();
  });
  const card = (s) => h('div', { class: 'card' },
    h('div', { class: 'row between' }, h('div', null, h('h3', { style: 'margin:0' }, s.name), h('div', { class: 'muted small' }, [s.city, `registered ${fmtDate(s.created_at)}`].filter(Boolean).join(' · '))),
      h('span', { class: `tag ${s.status === 'pending' ? 'high' : s.status === 'active' ? '' : 'urgent'}` }, STAT[s.status] || s.status)),
    h('div', { class: 'grid g2', style: 'margin:10px 0' },
      h('div', null, h('div', { class: 'muted small' }, 'Registered by'), h('div', null, s.admin_name || '—'), h('div', { class: 'small' }, s.email || ''), h('div', { class: 'small' }, s.phone || '')),
      h('div', null, h('div', { class: 'muted small' }, 'Address'), h('div', null, s.address || '—'), h('div', { class: 'muted small', style: 'margin-top:6px' }, 'Booking link'), h('div', { class: 'small' }, s.status === 'active' ? h('a', { href: shopLink(s.slug), target: '_blank', rel: 'noopener' }, shopLink(s.slug)) : h('span', { class: 'muted' }, 'works after approval')))),
    s.review_note && h('div', { class: 'notice', style: 'margin-bottom:10px' }, h('b', null, 'Review note: '), s.review_note),
    h('div', { class: 'row' },
      s.status !== 'active' && h('button', { class: 'primary', onclick: () => act(s, 'approve') }, s.status === 'pending' ? 'Approve shop' : 'Approve again'),
      s.status === 'pending' && h('button', { class: 'danger', onclick: () => act(s, 'reject') }, 'Reject'),
      s.status === 'active' && h('button', { class: 'danger', onclick: () => act(s, 'suspend') }, 'Suspend'),
      h('span', { class: 'muted small', style: 'margin-left:auto' }, `${s.repairs} repair${s.repairs === 1 ? '' : 's'} so far`)));
  const load = guarded(async () => {
    const r = await api('GET', '/api/platform/shops?status=' + state.status);
    tabs.replaceChildren(...TABS.map(([v, t]) => h('button', { class: state.status === v ? 'on' : '', onclick: () => { state.status = v; load(); } }, v === 'all' ? t : `${t} (${r.counts[v] ?? 0})`)));
    out.replaceChildren(...(r.shops.length ? r.shops.map(card) : [h('div', { class: 'card muted', style: 'text-align:center' }, state.status === 'pending' ? 'No shops are waiting for review. 🎉' : 'Nothing here.')]));
  });
  mount(h('h1', null, 'Shop registrations'),
    h('p', { class: 'muted' }, 'New shops stay hidden and cannot take bookings until you approve them. Check the contact details and address before approving. You can suspend an approved shop at any time.'),
    h('div', { style: 'margin:12px 0' }, tabs), h('div', { class: 'stack' }, out));
  load();
}

// ---------- shop settings (admins manage details/branches; admins and owners set the public map pin) ----------
async function pageSettings() {
  cleanupSettingsMap();
  const shop = await api('GET', '/api/shop');
  const adminOnly = me?.role === 'admin';
  const cards = [];

  if (adminOnly) {
    const detail = h('form', { class: 'card' }, h('h2', null, 'Shop details'),
      h('div', { class: 'grid g3' }, field('Shop name', input('name', { 'data-shop-name-input': '1', required: true, value: shop.name, max: 80 })), field('City / town', input('city', { value: shop.city || '', max: 80 })), field('Contact number', input('phone', { type: 'tel', value: shop.phone || '', max: 40 }))),
      h('div', { class: 'muted small', style: 'margin-top:6px' }, 'Your booking link stays the same when you rename the shop.'),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { class: 'primary', type: 'submit' }, 'Save shop details')));
    detail.addEventListener('submit', guarded(async (e) => { e.preventDefault(); await api('PATCH', '/api/shop', formData(detail)); toast('Shop details saved.'); }));
    cards.push(detail);

    const branchForm = (b) => {
      const f = h('form', { class: 'rec' },
        h('div', { class: 'grid g2' }, field('Branch name', input('name', { required: true, value: b?.name || '', max: 120 })), field('Address', input('address', { required: true, value: b?.address || '', max: 200 }))),
        h('div', { class: 'grid g2' }, field('Contact number (optional)', input('phone', { type: 'tel', value: b?.phone || '', max: 40 })), field('Opening hours (optional)', input('hours', { value: b?.hours || '', max: 100, ph: 'e.g. 8:00 AM – 9:00 PM' }))),
        h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'primary small', type: 'submit' }, b ? 'Save branch' : 'Add branch')));
      f.addEventListener('submit', guarded(async (e) => {
        e.preventDefault();
        await api(b ? 'PATCH' : 'POST', b ? `/api/branches/${b.id}` : '/api/branches', formData(f));
        toast(b ? 'Branch saved.' : 'Branch added.');
        if (!b) pageSettings();
      }));
      return f;
    };
    cards.push(h('div', { class: 'card' }, h('h2', null, 'Branches'), h('div', { class: 'stack' }, shop.branches.map(branchForm))));
    cards.push(h('div', { class: 'card' }, h('h2', null, 'Add a branch'), branchForm(null)));
  } else {
    cards.push(h('div', { class: 'notice' }, h('b', null, 'Shop details and branches are managed by your administrator. '),
      'Your shop name, logo, colours, public map pin and opening hours can be updated here. Every change stays scoped to your own shop.'));
  }

  const location = buildLocationCard(shop);
  cards.push(location.node);
  mount(h('h1', null, 'Shop settings'),
    h('p', { class: 'muted' }, 'Your public map pin, logo and opening hours appear in the “Find a repair shop” map, so clients know exactly where to go and whether you are open.'),
    h('div', { class: 'stack' }, cards));
  location.init();

  function buildLocationCard(current) {
    let pos = hasPin(current) ? { lat: Number(current.lat), lng: Number(current.lng) } : null;
    let previewHours = current.opening_hours || null;
    const errorHost = h('div', { class: 'settings-map-error' });
    const address = input('address', { required: true, value: current.address || '', max: 255, ph: 'e.g. 12 Rizal Street, Cebu City' });
    const mapHost = h('div', { class: 'settings-map', 'aria-label': 'Set your public map pin' });
    const preview = h('div', { class: 'public-map-preview' });
    const geoResults = h('div', { class: 'geo-results' });
    const searchBox = h('input', { type: 'search', placeholder: 'Search your shop address…', 'aria-label': 'Search shop address' });
    const safeCollect = () => { try { return hours.collect(); } catch { return previewHours; } };
    const hours = openingHoursEditor(previewHours, () => { previewHours = safeCollect(); refreshPreview(); });
    const coordLine = () => pos ? `Public pin: ${pos.lat.toFixed(6)}, ${pos.lng.toFixed(6)}` : 'Drop a pin to choose the exact public location.';

    function refreshPreview() {
      const status = shopOpenStatus({ opening_hours: previewHours });
      preview.replaceChildren(
        h('div', { class: 'row' }, shopAvatarNode(current), h('b', null, current.name)),
        h('div', { class: 'muted small', style: 'margin-top:6px' }, address.value.trim() || 'Address not set yet'),
        h('div', { class: 'row', style: 'margin-top:6px' }, openStatusNode(status), h('span', { class: 'muted small' }, coordLine())));
    }
    function placePin(latlng, label) {
      pos = { lat: Number(latlng[0]), lng: Number(latlng[1]) };
      if (label) address.value = label;
      if (settingsPin) settingsPin.setLatLng([pos.lat, pos.lng]);
      else if (settingsMap) {
        settingsPin = L.marker([pos.lat, pos.lng], { icon: shopPinIcon(current, true), draggable: true, title: 'Public map pin' }).addTo(settingsMap);
        settingsPin.on('dragend', () => {
          const ll = settingsPin.getLatLng();
          pos = { lat: ll.lat, lng: ll.lng };
          refreshPreview();
        });
      }
      settingsMap?.setView([pos.lat, pos.lng], Math.max(settingsMap.getZoom(), 16));
      clearError(errorHost);
      refreshPreview();
    }
    function searchAddress() {
      const q = searchBox.value.trim();
      if (!q) { geoResults.replaceChildren(h('p', { class: 'muted small' }, 'Type an address first.')); return; }
      geoResults.replaceChildren(h('p', { class: 'muted small' }, 'Searching…'));
      geocodeAddress(q).then((hits) => {
        if (!hits.length) { geoResults.replaceChildren(h('p', { class: 'muted small' }, 'No matching address found. You can still click the map to place the pin.')); return; }
        geoResults.replaceChildren(...hits.map((hit) => h('button', {
          class: 'geo-result', type: 'button', onclick: () => placePin([hit.lat, hit.lng], hit.label),
        }, hit.label)));
      }).catch((err) => { geoResults.replaceChildren(); inlineError(errorHost, err.message); });
    }

    const form = h('form', { class: 'stack' },
      h('div', { class: 'grid g2' },
        h('div', null, h('label', null, 'Public address'), address),
        h('div', null, h('label', null, 'Find the address on the map'),
          h('div', { class: 'row' }, searchBox, h('button', { class: 'small', type: 'button', onclick: searchAddress }, 'Search')))),
      geoResults,
      h('div', { class: 'settings-map-grid' },
        h('div', null, h('b', { class: 'small' }, 'Pin location'), mapHost,
          h('p', { class: 'muted small', style: 'margin-top:6px' }, 'Search the address, then drag the pin to your exact spot. You can also click anywhere on the map to move it.')),
        h('div', null, h('b', { class: 'small' }, 'Public map preview'), preview,
          h('p', { class: 'muted small', style: 'margin-top:6px' }, 'This is the name, logo, address and status clients will see before they open your marker.')),),
      h('div', null, h('b', null, 'Opening hours'), h('p', { class: 'muted small' }, 'Used for the live Open now / Closed status on the public map. Leave a day with no ranges if you are closed.'), hours.node),
      errorHost,
      h('div', { class: 'row' }, h('button', { class: 'primary', type: 'submit' }, 'Save map pin & hours')));
    searchBox.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); searchAddress(); } });
    address.addEventListener('input', refreshPreview);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError(errorHost);
      if (!pos) { inlineError(errorHost, 'Search for your address or click the map to drop a pin first.'); return; }
      try {
        let openingHours;
        try { openingHours = hours.collect(); } catch (err) { inlineError(errorHost, err.message); return; }
        const saved = await api('PATCH', '/api/shop/location', {
          address: address.value.trim(), lat: pos.lat, lng: pos.lng, openingHours,
        });
        if (me?.shop) Object.assign(me.shop, { address: saved.address, lat: saved.lat, lng: saved.lng, opening_hours: saved.opening_hours });
        previewHours = saved.opening_hours;
        refreshPreview();
        toast('Public map pin and opening hours saved.');
      } catch (err) { inlineError(errorHost, err.message); }
    });

    return {
      node: h('div', { class: 'card settings-map-card' }, h('h2', null, 'Map location & opening hours'),
        h('p', { class: 'muted small' }, 'Set the exact coordinates used by the public finder. Only approved, listed shops with a saved pin appear on the customer map.'), form),
      init() {
        if (!L) { inlineError(errorHost, 'The map library could not be loaded. Please refresh the page.'); return; }
        settingsMap = L.map(mapHost, { zoomControl: true }).setView(pos ? [pos.lat, pos.lng] : DEFAULT_MAP_CENTER, pos ? 16 : 12);
        mapTileLayer().addTo(settingsMap);
        settingsMap.on('click', (e) => placePin([e.latlng.lat, e.latlng.lng]));
        if (pos) placePin([pos.lat, pos.lng]);
        refreshPreview();
        setTimeout(() => settingsMap?.invalidateSize(), 0);
      },
    };
  }
}

// ---------- administrator: import past repairs and sales ----------
function pageImport() {
  const TEMPLATE = 'job_no,date_received,date_released,branch,device_type,brand,model,problem,diagnosis,repair_done,status,parts_cost,labor_cost,total,amount_paid,payment_method,technician\n'
    + 'JO-0001,2026-01-12,2026-01-14,Tisa,smartphone,Samsung,Galaxy A12,"[EXAMPLE - delete this row] Nahulog sa tubig, ayaw mag on","Liquid / water damage","Ultrasonic clean and replaced charging IC",released,1200,500,1700,1700,gcash,Tony\n'
    + 'JO-0002,1/15/2026,1/15/2026,SM JMall,laptop,Lenovo,IdeaPad 3,"[EXAMPLE - delete this row] Laptop is slow, clicking sound from the drive","Failing storage drive","Replaced with SSD and reinstalled OS",released,1800,700,,2500,cash,\n'
    + 'JO-0003,2026-01-16,2026-01-16,Lapulapu,smartphone,Oppo,A16,"[EXAMPLE - delete this row] Hindi na nag cha-charge",,,cancelled,,,,,,\n';
  let csvText = ''; let fileName = '';
  const file = h('input', { type: 'file', accept: '.csv,text/csv', 'aria-label': 'CSV file' });
  const report = h('div');
  const permission = h('input', { type: 'checkbox', id: 'perm' });
  const addKb = h('input', { type: 'checkbox', id: 'addkb', checked: true });
  const render = (r) => {
    report.replaceChildren(h('div', { class: 'notice', style: 'margin-top:12px' },
      r.dry_run ? h('b', null, `Preview: ${r.valid} of ${r.total_rows} rows are ready to import.`) : h('b', null, `Imported ${r.imported} past repair(s).${r.kb_added ? ` ${r.kb_added} added to the AI knowledge base.` : ''}`),
      h('ul', { class: 'clean' },
        r.valid ? h('li', null, `Sales to be recorded: ${peso(r.total_sales)}${r.from ? ` (${r.from} to ${r.to})` : ''}`) : null,
        r.by_branch.map((b) => h('li', null, `${b.branch}: ${b.repairs} repair${b.repairs === 1 ? '' : 's'}, ${peso(b.sales)}`)),
        r.dry_run && r.with_diagnosis ? h('li', null, `${r.with_diagnosis} have a diagnosis and can be added to the AI knowledge base.`) : null,
        r.duplicates ? h('li', null, `${r.duplicates} already imported earlier (skipped).`) : null,
        r.rejected ? h('li', { class: 'error' }, `${r.rejected} row(s) have problems and will be skipped:`) : null),
      r.errors.length ? h('ul', { class: 'clean small' }, r.errors.map((e) => h('li', null, `Row ${e.row}: ${e.message}`))) : null,
      r.dry_run && r.valid ? h('button', { class: 'primary', style: 'margin-top:8px', disabled: !permission.checked, id: 'commit', onclick: guarded(async () => { const o = await api('POST', '/api/repairs/import', { csv: csvText, dryRun: false, permission: permission.checked, addToKb: addKb.checked }); render(o); toast(`Imported ${o.imported} past repairs.`); }) }, `Import ${r.valid} repair${r.valid === 1 ? '' : 's'}`) : null));
  };
  const check = guarded(async () => { if (!csvText) return; render(await api('POST', '/api/repairs/import', { csv: csvText, dryRun: true })); });
  file.addEventListener('change', async () => { const f = file.files[0]; if (!f) return; fileName = f.name; csvText = await f.text(); await check(); });
  permission.addEventListener('change', () => { const b = $('#commit'); if (b) b.disabled = !permission.checked; });
  mount(h('h1', null, 'Import data'),
    h('p', { class: 'muted' }, 'Bring in your shop’s past repairs and payments so the reports and the sales graph show your real figures. Always preview first. Nothing is saved until you confirm.'),
    h('div', { class: 'grid g2' },
      h('div', { class: 'card' }, h('h2', null, 'Past repairs and sales'),
        h('div', { class: 'notice warn-box', style: 'margin-bottom:10px' }, h('b', null, 'Privacy: '), 'customer names and phone numbers are never imported. Every imported job is attached to one anonymized “past customer”. Remove those columns from your file if you can.'),
        h('ol', { class: 'clean' }, ['Download the template and copy your job-order records into it (one row per repair).', 'Save as CSV, choose the file below and check the preview.', 'Tick the permission box and import.'].map((t) => h('li', null, t))),
        h('div', { class: 'row', style: 'margin:8px 0' }, h('button', { type: 'button', onclick: () => { const a = h('a', { href: URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' })), download: 'repairsync-past-repairs-template.csv' }); document.body.append(a); a.click(); a.remove(); } }, 'Download template'), file),
        h('label', { class: 'check' }, permission, h('span', null, 'I have the shop owner’s permission to use these records, and customer names and numbers were left out.')),
        h('label', { class: 'check' }, addKb, h('span', null, 'Also add repairs that have a diagnosis to the AI knowledge base (so the AI learns from them).')),
        report),
      h('div', { class: 'stack' },
        h('div', { class: 'card' }, h('h3', null, 'Columns'), h('ul', { class: 'clean small' }, [
          'date_received and problem are required, plus an amount: total, or parts_cost and labor_cost.',
          'Dates: YYYY-MM-DD or M/D/YYYY (Philippine time). Not in the future.',
          'branch: part of the branch name, e.g. Tisa, SM JMall, Lapulapu. Optional if you have one branch.',
          'device_type: smartphone, laptop, tablet or other.',
          'status: released (default) or cancelled.',
          'amount_paid defaults to the total. payment_method: cash, gcash, card or bank.',
          'job_no (your job order number) prevents duplicates if you import the same file twice.',
          'technician: part of a technician’s name, if they have an account here.'].map((t) => h('li', null, t)))),
        h('div', { class: 'card' }, h('h3', null, 'Only want AI cases?'), h('p', { class: 'muted small' }, 'The Knowledge base page has a separate import for cases (problem, diagnosis, checks, parts, cost).'), h('a', { class: 'btn', href: '#/kb' }, 'Go to Knowledge base')))));
}

// A shop's personal booking link (works once the shop is approved).
const shopLink = (slug) => location.origin + location.pathname + '#/shop/' + encodeURIComponent(slug);
function shopStatusBlock() {
  const sh = me?.shop; if (!sh || !roleIn('admin', 'owner', 'frontdesk')) return null;
  if (sh.status === 'pending') return h('div', { class: 'notice warn-box', style: 'margin-bottom:14px' }, h('b', null, 'Your shop is waiting for approval. '), 'You can set up your staff and knowledge base now. Clients cannot find or book with you until the RepairSync AI administrator approves your registration. Your booking link will start working then.');
  const link = shopLink(sh.slug);
  return h('div', { class: 'card', style: 'margin-bottom:14px' }, h('div', { class: 'row between' },
    h('div', null, h('h3', { style: 'margin:0 0 2px' }, 'Your shop’s booking link'), h('div', { class: 'muted small' }, 'Share it on Facebook, Messenger or a QR code. Clients get a free AI check and book straight to your shop.'), h('div', { style: 'margin-top:6px;font-weight:600;overflow-wrap:anywhere' }, h('a', { href: link, target: '_blank', rel: 'noopener' }, link))),
    h('div', { class: 'row' },
      h('button', { class: 'primary', onclick: async () => { try { await navigator.clipboard.writeText(link); toast('Link copied.'); } catch { window.prompt('Copy this link:', link); } } }, 'Copy link'),
      h('a', { class: 'btn', href: link, target: '_blank', rel: 'noopener' }, 'Open'))));
}

// ---------- public: tracking ----------
async function pageTrack(q) {
  if (!q.code) {
    const f = h('form', { class: 'card', style: 'max-width:460px;margin:auto' }, h('h2', null, 'Track my repair'),
      field('Repair code', input('code', { required: true, ph: 'RS-2610-0001' })), field('Last 4 digits of mobile number', input('phone4', { required: true, max: 4 })),
      h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Track')));
    f.addEventListener('submit', (e) => { e.preventDefault(); const d = formData(f); location.hash = `#/track?code=${encodeURIComponent(d.code)}&phone4=${encodeURIComponent(d.phone4)}`; });
    return mount(f);
  }
  let t;
  try { t = await api('GET', '/api/public/track?' + new URLSearchParams(q)); } catch (e) { return mount(h('div', { class: 'card', style: 'max-width:520px;margin:auto' }, h('h2', null, 'We could not find that repair'), h('p', null, e.message), h('a', { class: 'btn', href: '#/track' }, 'Try again'))); }
  const decide = (approve) => guarded(async () => { t = await api('POST', '/api/public/track/approve', { ...q, approve }); toast(approve ? 'Thank you! Repair approved.' : 'Quote declined.'); pageTrackRender(); });
  const pageTrackRender = () => mount(trackView(t, decide, () => pageTrack(q)));
  pageTrackRender();
}

function trackView(t, decide, refresh) {
  return h('div', { class: 'stack', style: 'max-width:760px;margin:auto' },
    h('div', { class: 'card' },
      h('div', { class: 'row between' }, h('div', null, h('div', { class: 'muted small' }, 'Repair code'), h('div', { class: 'code-big' }, t.code)), t.status === 'cancelled' ? tag('cancelled') : tag(t.status)),
      h('p', null, h('b', null, t.device), ` · ${t.branch_name}`, t.est_ready_date ? ` · Estimated ready: ${t.est_ready_date}` : ''),
      t.status !== 'cancelled' && stepper(t.status),
      h('button', { class: 'small', onclick: refresh }, 'Refresh')),
    t.status === 'awaiting_approval' && h('div', { class: 'card ai' }, h('h2', null, 'Your quote is ready'), t.findings && h('p', null, h('b', null, 'Technician findings: '), t.findings),
      quoteTable(t.quote, t.total), h('p', { class: 'muted small' }, 'Repair starts only after you approve.'),
      h('div', { class: 'row' }, h('button', { class: 'primary', onclick: decide(true) }, 'Approve repair'), h('button', { class: 'danger', onclick: decide(false) }, 'Decline'))),
    t.status !== 'awaiting_approval' && t.quote?.length ? h('div', { class: 'card' }, h('h3', null, 'Quote'), quoteTable(t.quote, t.total), t.balance > 0 && h('p', null, 'Balance due: ', h('b', null, peso(t.balance)))) : null,
    h('div', { class: 'card' }, h('h3', null, 'Progress timeline'), h('ul', { class: 'timeline' }, t.timeline.map((e) => h('li', null, h('div', null, h('b', null, e.label), e.note ? ` – ${e.note}` : '', h('div', { class: 'muted small' }, fmtDate(e.created_at))))))),
    t.messages?.length ? h('div', { class: 'card' }, h('h3', null, 'Messages sent to you'), t.messages.map((m) => h('p', { class: 'small' }, h('span', { class: 'muted' }, fmtDate(m.created_at) + ' — '), m.message))) : null);
}
const quoteTable = (items, total) => h('div', { class: 'tablewrap' }, h('table', null, h('tbody', null, items.map((i) => h('tr', null, h('td', null, i.description), h('td', { class: 'muted' }, i.kind), h('td', { style: 'text-align:right' }, peso(i.amount)))), h('tr', null, h('td', { colspan: 2 }, h('b', null, 'Total')), h('td', { style: 'text-align:right' }, h('b', null, peso(total)))))));

// ---------- auth ----------
function pageLogin() {
  const f = h('form', { class: 'auth-card auth-form', style: 'max-width:460px;margin:20px auto' },
    h('div', { class: 'auth-logo' }, h('img', { src: url('/Images/Logo.png'), alt: 'RepairSync logo' })),
    h('h2', null, 'Sign in'),
    field('Email', input('email', { type: 'email', required: true, ac: 'username' })),
    field('Password', input('password', { type: 'password', required: true, ac: 'current-password', max: 200 })),
    h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Sign in'), h('a', { href: '#/register' }, 'Create client account'), h('a', { href: '#/business' }, 'Register your shop')),
    health.demo && h('div', { class: 'notice', style: 'margin-top:14px' }, h('b', null, 'Demo accounts '), h('span', { class: 'muted small' }, '(password RepairSync#2026)'),
      h('div', { class: 'chips', style: 'margin-top:6px' }, [['owner', 'Owner'], ['admin', 'Admin'], ['frontdesk', 'Front desk'], ['tech1', 'Technician'], ['customer', 'Client'], ['admin2', 'Shop 2 admin']].map(([u, l]) =>
        h('span', { class: 'chip', role: 'button', tabindex: 0, onclick: () => { f.email.value = `${u}@repairsync.test`; f.password.value = 'RepairSync#2026'; } }, l)))));
  f.addEventListener('submit', guarded(async (e) => { e.preventDefault(); const d = formData(f); me = await api('POST', '/api/auth/login', d); location.hash = '#/app'; route(); }));
  mount(f);
}
function pageRegister() {
  const f = h('form', { class: 'auth-card auth-form', style: 'max-width:500px;margin:20px auto' },
    h('div', { class: 'auth-logo' }, h('img', { src: url('/Images/Logo.png'), alt: 'RepairSync logo' })),
    h('h2', null, 'Create client account'),
    field('Full name', input('name', { required: true })), field('Email', input('email', { type: 'email', required: true })), field('Mobile number', input('phone', { type: 'tel' })),
    field('Password', input('password', { type: 'password', required: true, max: 200 }), 'At least 8 characters with letters and numbers.'),
    h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'consent', required: true }), h('span', null, 'I accept the Data Privacy notice (RA 10173).')),
    h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Create account')));
  f.addEventListener('submit', guarded(async (e) => { e.preventDefault(); const d = formData(f); d.consent = f.consent.checked; me = await api('POST', '/api/auth/register', d); location.hash = '#/app'; route(); }));
  mount(f);
}

// ---------- customer: my repairs ----------
async function pageMyRepairs() {
  const rows = await api('GET', '/api/repairs');
  mount(h('div', { class: 'row between' }, h('h1', null, 'My repairs'), h('a', { class: 'btn primary', href: '#/customer' }, 'AI check & book a repair')),
    rows.length ? h('div', { class: 'grid g3' }, rows.map((r) => h('a', { class: 'tk card', href: `#/repair/${r.id}` }, h('div', { class: 'code' }, r.code), h('div', { class: 'dev' }, [r.brand, r.model].filter(Boolean).join(' ') || r.device_type), h('div', { style: 'margin-top:6px' }, tag(r.status)), h('div', { class: 'meta' }, `Updated ${ago(r.updated_at)}`))))
      : h('div', { class: 'card' }, h('p', null, 'You have no repair requests yet.')));
}

// ---------- staff: workflow board ----------
async function pageBoard() {
  const state = { q: '', branch: '', mine: false, closed: false };
  const out = h('div');
  const load = guarded(async () => {
    const p = new URLSearchParams();
    if (state.q) p.set('q', state.q);
    if (state.branch) p.set('branch', state.branch);
    if (state.mine) p.set('mine', '1');
    const rows = await api('GET', '/api/repairs?' + p);
    const active = rows.filter((r) => !['released', 'cancelled'].includes(r.status));
    const closed = rows.filter((r) => ['released', 'cancelled'].includes(r.status));
    const today = new Date().toISOString().slice(0, 10);
    const cols = STATUS_ORDER.slice(0, 7);
    out.replaceChildren(
      h('div', { class: 'kpis' }, [['Active repairs', active.length], ['Awaiting diagnosis', active.filter((r) => ['booked', 'received'].includes(r.status)).length], ['Awaiting customer approval', active.filter((r) => r.status === 'awaiting_approval').length], ['Ready for pick-up', active.filter((r) => r.status === 'completed').length], ['Overdue', active.filter((r) => r.est_ready_date && r.est_ready_date < today && r.status !== 'completed').length]].map(([l, n]) => h('div', { class: 'kpi' }, h('b', null, n), h('span', null, l)))),
      h('div', { class: 'board' }, cols.map((s) => {
        const items = active.filter((r) => r.status === s);
        return h('section', { class: 'col', 'aria-label': STATUS_LABEL[s] }, h('h3', null, h('span', null, STATUS_LABEL[s]), h('span', null, items.length)),
          items.map((r) => h('a', { class: 'tk', href: `#/repair/${r.id}` },
            h('div', { class: 'row between' }, h('span', { class: 'code' }, r.code), r.priority !== 'normal' && h('span', { class: `tag ${r.priority}` }, r.priority)),
            h('div', { class: 'dev' }, [r.brand, r.model].filter(Boolean).join(' ') || r.device_type),
            h('div', { class: 'meta' }, `${r.customer_name} · ${r.tech_name ? '🔧 ' + r.tech_name : 'unassigned'}`),
            h('div', { class: 'meta' }, `${r.branch_name.split(' – ').pop()} · ${ago(r.updated_at)}`),
            r.est_ready_date && r.est_ready_date < today && h('div', { class: 'meta', style: 'color:var(--bad);font-weight:700' }, 'Overdue since ' + r.est_ready_date))));
      })),
      h('details', { style: 'margin-top:14px' }, h('summary', null, `Closed repairs (${closed.length})`), h('div', { class: 'tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['Code', 'Device', 'Customer', 'Status', 'Closed'].map((x) => h('th', null, x)))),
        h('tbody', null, closed.map((r) => h('tr', null, h('td', null, h('a', { href: `#/repair/${r.id}` }, r.code)), h('td', null, [r.brand, r.model].filter(Boolean).join(' ')), h('td', null, r.customer_name), h('td', null, tag(r.status)), h('td', null, fmtDate(r.released_at || r.updated_at)))))))));
  });
  const filters = h('div', { class: 'row', style: 'margin:0 0 14px' },
    h('input', { type: 'search', placeholder: 'Search code, customer, device…', style: 'max-width:280px', 'aria-label': 'Search repairs', oninput: (e) => { state.q = e.target.value; clearTimeout(filters.t); filters.t = setTimeout(load, 250); } }),
    h('select', { 'aria-label': 'Branch', style: 'max-width:240px', onchange: (e) => { state.branch = e.target.value; load(); } }, h('option', { value: '' }, 'All branches'), branches.map((b) => h('option', { value: b.id }, b.name))),
    me.role === 'technician' && h('label', { class: 'check', style: 'margin:0' }, h('input', { type: 'checkbox', onchange: (e) => { state.mine = e.target.checked; load(); } }), 'My jobs only'),
    roleIn('frontdesk', 'admin') && h('a', { class: 'btn primary', href: '#/new', style: 'margin-left:auto' }, '+ New walk-in intake'));
  mount(h('h1', null, 'Workflow board'), shopStatusBlock(), filters, out);
  load();
}

// ---------- front desk: new intake ----------
function pageNew() {
  const f = h('form', { class: 'card', style: 'max-width:820px' }, h('h2', null, 'New walk-in intake'),
    h('div', { class: 'grid g3' }, field('Customer name', input('name', { required: true })), field('Mobile number', input('phone', { type: 'tel', required: true })), field('Email (optional)', input('email', { type: 'email' }))),
    h('div', { class: 'grid g3' }, field('Branch', select('branchId', branches.map((b) => [b.id, b.name]), me.branch_id)), field('Device type', select('deviceType', [['smartphone', 'Smartphone'], ['laptop', 'Laptop'], ['tablet', 'Tablet'], ['other', 'Other']])), field('Priority', select('priority', [['normal', 'Normal'], ['low', 'Low'], ['high', 'High'], ['urgent', 'Urgent']]))),
    h('div', { class: 'grid g3' }, field('Brand', input('brand')), field('Model', input('model')), field('Serial / IMEI', input('serial', { max: 40 }))),
    field('Problem as described by the customer', h('textarea', { name: 'symptoms', required: true, minlength: 8, maxlength: 1000 })),
    h('div', { class: 'grid g2' }, field('Accessories received', input('accessories', { max: 200 })), field('Physical condition notes', input('conditionNotes', { max: 500, ph: 'scratches, dents, missing screws…' }))),
    h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'consent', required: true }), h('span', null, 'Customer was informed of and agreed to the Data Privacy notice.')),
    h('div', { class: 'row', style: 'margin-top:14px' }, h('button', { class: 'primary', type: 'submit' }, 'Create repair & run AI preliminary diagnosis')));
  f.addEventListener('submit', guarded(async (e) => { e.preventDefault(); const d = formData(f); d.consent = f.consent.checked; const r = await api('POST', '/api/repairs', d); toast(`Created ${r.code}`); location.hash = `#/repair/${r.id}`; }));
  mount(f);
}

// ---------- repair detail ----------
async function pageRepair(id) {
  const r = await api('GET', `/api/repairs/${id}`);
  const reload = () => pageRepair(id);
  const act = (fn) => guarded(async (...a) => { await fn(...a); await reload(); });
  const canWork = isStaff() && r.status !== 'released' && r.status !== 'cancelled';

  const head = h('div', { class: 'card' },
    h('div', { class: 'row between' }, h('div', null, h('div', { class: 'muted small' }, r.branch_name), h('h1', { style: 'margin:0' }, r.code)), h('div', { class: 'row' }, r.priority !== 'normal' && h('span', { class: `tag ${r.priority}` }, r.priority), tag(r.status))),
    r.status !== 'cancelled' && stepper(r.status),
    h('div', { class: 'grid g2', style: 'margin-top:8px' },
      h('div', null, h('div', { class: 'muted small' }, 'Device'), h('b', null, [r.brand, r.model].filter(Boolean).join(' ') || r.device_type), h('div', { class: 'small muted' }, r.device_type + (r.serial_no ? ` · S/N ${r.serial_no}` : '')), r.accessories && h('div', { class: 'small' }, 'Accessories: ' + r.accessories), r.condition_notes && h('div', { class: 'small' }, 'Condition: ' + r.condition_notes)),
      h('div', null, h('div', { class: 'muted small' }, 'Customer'), h('b', null, r.customer_name), r.customer_phone && h('div', { class: 'small' }, r.customer_phone), r.customer_email && h('div', { class: 'small' }, r.customer_email), !r.customer_phone && isStaff() && h('div', { class: 'small muted' }, 'Contact details hidden for your role'))),
    h('div', { style: 'margin-top:10px' }, h('div', { class: 'muted small' }, 'Reported problem'), h('div', null, r.reported_symptoms)),
    h('div', { class: 'row', style: 'margin-top:10px' }, h('span', { class: 'small' }, 'Technician: ', h('b', null, r.tech_name || 'unassigned')), r.est_ready_date && h('span', { class: 'small' }, 'Est. ready: ', h('b', null, r.est_ready_date))));

  // actions (status transitions)
  const labels = { received: 'Mark received', diagnosing: 'Start diagnosing', awaiting_approval: 'Send quote to customer', repairing: 'Start repair', quality_check: 'Send to quality check', completed: 'Pass QC – mark complete', released: 'Release to customer', cancelled: 'Cancel repair' };
  const transitionBtn = (to) => {
    const from = r.status;
    return h('button', { class: to === 'cancelled' ? 'danger' : to === r.allowed_next.find((x) => x !== 'cancelled') ? 'primary' : '', onclick: act(async () => {
      const body = { to };
      const needsNote = to === 'cancelled' || (from === 'awaiting_approval' && to === 'repairing') || (from === 'quality_check' && to === 'repairing');
      if (needsNote) {
        const d = await ask(labels[to] || 'Update status', [field(to === 'cancelled' ? 'Reason' : from === 'awaiting_approval' ? 'How did the customer approve?' : 'What failed in QC?', h('textarea', { name: 'note', required: true, maxlength: 300 }))]);
        if (!d) throw new Error('Cancelled');
        body.note = d.note;
      }
      if (to === 'released' && r.balance > 0 && me.role === 'admin') {
        const d = await ask('Outstanding balance', [h('p', null, `Balance of ${peso(r.balance)} is unpaid. Record payment first, or override as administrator.`), field('Override reason', h('input', { name: 'note', maxlength: 200 }))], 'Release anyway');
        if (!d) throw new Error('Cancelled');
        body.override = true; body.note = d.note || 'Released with balance (admin override)';
      }
      await api('POST', `/api/repairs/${id}/transition`, body);
      toast(`Status: ${STATUS_LABEL[to]}`);
    }) }, labels[to] || STATUS_LABEL[to]);
  };
  const actions = r.allowed_next.length || (me.role === 'customer' && r.status === 'awaiting_approval') ? h('div', { class: 'card' }, h('h3', null, 'Next steps'),
    h('div', { class: 'row' }, r.allowed_next.filter((t) => !(t === 'repairing' && r.status === 'awaiting_approval' && me.role === 'customer')).map(transitionBtn),
      r.status === 'awaiting_approval' && me.role === 'customer' && [h('button', { class: 'primary', onclick: act(async () => { await api('POST', `/api/repairs/${id}/approve`, { approve: true }); toast('Repair approved'); }) }, 'Approve repair'), h('button', { class: 'danger', onclick: act(async () => { await api('POST', `/api/repairs/${id}/approve`, { approve: false }); }) }, 'Decline')]),
    r.status === 'diagnosing' && isStaff() && h('p', { class: 'muted small' }, 'Before sending a quote: record findings and add quote items.')) : null;

  const assign = canWork && roleIn('frontdesk', 'admin') || (me.role === 'technician' && !r.assigned_to && canWork)
    ? h('div', { class: 'card' }, h('h3', null, 'Assignment'), await assignForm(r, act)) : null;

  // technician workspace
  const workspace = canWork && roleIn('technician', 'admin', 'frontdesk') ? (() => {
    const f = h('form', null, h('h3', null, 'Findings & repair record'),
      field('Technician findings', h('textarea', { name: 'findings', maxlength: 2000, value: r.findings || '' })),
      roleIn('technician', 'admin') ? field('Verified diagnosis', h('input', { name: 'verified_diagnosis', maxlength: 200, list: 'diag-list', value: r.verified_diagnosis || '', placeholder: 'confirmed by inspection' })) : null,
      roleIn('technician', 'admin') ? field('Repair action performed', h('textarea', { name: 'repair_action', maxlength: 2000, value: r.repair_action || '' })) : null,
      h('div', { class: 'grid g2' }, field('Estimated ready date', h('input', { type: 'date', name: 'est_ready_date', value: r.est_ready_date || '' })), field('Priority', select('priority', [['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent']], r.priority))),
      h('datalist', { id: 'diag-list' }, (r.ai_runs?.[0]?.result?.recommendations || []).map((x) => h('option', { value: x.diagnosis }))),
      h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'primary', type: 'submit' }, 'Save')));
    f.addEventListener('submit', act(async (e) => { e.preventDefault(); const d = formData(f); for (const k of Object.keys(d)) if (d[k] === '' && k !== 'findings') delete d[k]; await api('PATCH', `/api/repairs/${id}`, d); toast('Saved'); }));
    return h('div', { class: 'card' }, f);
  })() : (r.findings || r.verified_diagnosis ? h('div', { class: 'card' }, h('h3', null, 'Findings'), r.findings && h('p', null, r.findings), r.verified_diagnosis && h('p', null, h('b', null, 'Verified diagnosis: '), r.verified_diagnosis), r.repair_action && h('p', null, h('b', null, 'Repair action: '), r.repair_action)) : null);

  // quote & payments
  const editableItems = canWork && ['received', 'diagnosing'].includes(r.status) && roleIn('technician', 'frontdesk', 'admin');
  const quote = h('div', { class: 'card' }, h('h3', null, 'Quote & payments'),
    r.items.length ? h('div', { class: 'tablewrap' }, h('table', null, h('tbody', null, r.items.map((i) => h('tr', null, h('td', null, i.description), h('td', { class: 'muted' }, i.kind), h('td', { style: 'text-align:right' }, peso(i.amount)), editableItems && h('td', null, h('button', { class: 'small danger', 'aria-label': 'Remove item', onclick: act(() => api('DELETE', `/api/repairs/${id}/items/${i.id}`)) }, '×')))),
      h('tr', null, h('td', { colspan: 2 }, h('b', null, 'Total')), h('td', { style: 'text-align:right' }, h('b', null, peso(r.total)))), h('tr', null, h('td', { colspan: 2 }, 'Paid'), h('td', { style: 'text-align:right' }, peso(r.paid))), h('tr', null, h('td', { colspan: 2 }, h('b', null, 'Balance')), h('td', { style: 'text-align:right' }, h('b', null, peso(r.balance))))))) : h('p', { class: 'muted small' }, 'No quote items yet.'),
    editableItems && (() => { const f = h('form', { class: 'row', style: 'margin-top:10px' }, select('kind', [['part', 'Part'], ['labor', 'Labor'], ['other', 'Other']]), h('input', { name: 'description', placeholder: 'Description', required: true, maxlength: 150, style: 'flex:1;min-width:140px' }), h('input', { name: 'amount', type: 'number', min: 0, step: '0.01', required: true, placeholder: '₱', style: 'width:110px' }), h('button', { type: 'submit' }, 'Add'));
      f.addEventListener('submit', act(async (e) => { e.preventDefault(); await api('POST', `/api/repairs/${id}/items`, formData(f)); })); return f; })(),
    roleIn('frontdesk', 'admin') && r.total > 0 && r.balance > 0 && r.status !== 'cancelled' && (() => { const f = h('form', { class: 'row', style: 'margin-top:10px' }, h('input', { name: 'amount', type: 'number', min: 0.01, step: '0.01', required: true, value: r.balance, style: 'width:120px', 'aria-label': 'Payment amount' }), select('method', [['cash', 'Cash'], ['gcash', 'GCash'], ['card', 'Card'], ['bank', 'Bank']]), h('button', { type: 'submit' }, 'Record payment'), h('span', { class: 'muted small' }, 'Payment is recorded manually (no online gateway).'));
      f.addEventListener('submit', act(async (e) => { e.preventDefault(); await api('POST', `/api/repairs/${id}/payment`, formData(f)); toast('Payment recorded'); })); return f; })());

  // AI panel
  const ai = r.ai_runs ? aiPanel(r, act, canWork) : null;

  const timeline = h('div', { class: 'card' }, h('h3', null, 'History'), h('ul', { class: 'timeline' }, r.events.slice().reverse().map((e) => h('li', null, h('div', null, h('b', null, STATUS_LABEL[e.to_status]), e.note ? ` – ${e.note}` : '', h('div', { class: 'muted small' }, `${fmtDate(e.created_at)}${e.actor_label ? ' · ' + e.actor_label : ''}`))))));
  const notes = h('div', { class: 'card' }, h('h3', null, 'Customer notifications ', h('span', { class: 'muted small' }, '(simulated SMS/email log)')), r.notifications.length ? r.notifications.slice(0, 8).map((n) => h('p', { class: 'small' }, h('span', { class: 'muted' }, fmtDate(n.created_at) + ' — '), n.message)) : h('p', { class: 'muted small' }, 'None yet.'));

  mount(h('p', null, h('a', { href: '#/app' }, '← Back')), h('div', { class: 'detail' }, h('div', { class: 'stack' }, head, actions, workspace, quote, ai && me.role === 'customer' ? null : null), h('div', { class: 'stack' }, assign, ai, timeline, notes)));
}

async function assignForm(r, act) {
  if (me.role === 'technician') return h('button', { class: 'primary', onclick: act(() => api('POST', `/api/repairs/${r.id}/assign`, { technicianId: me.id })) }, 'Assign to me');
  const techs = await api('GET', '/api/users');
  const f = h('form', { class: 'row' }, h('select', { name: 'technicianId', 'aria-label': 'Technician' }, techs.filter((u) => u.role === 'technician' && (u.active ?? 1)).map((u) => h('option', { value: u.id, selected: u.id === r.assigned_to }, u.name))), h('button', { type: 'submit' }, r.assigned_to ? 'Reassign' : 'Assign'));
  f.addEventListener('submit', act(async (e) => { e.preventDefault(); await api('POST', `/api/repairs/${r.id}/assign`, { technicianId: Number(f.technicianId.value) }); toast('Assigned'); }));
  return f;
}

function aiPanel(r, act, canWork) {
  const runs = r.ai_runs || [];
  const latest = runs[0];
  const res = latest?.result;
  const wrap = h('div', { class: 'card ai' }, h('div', { class: 'row between' }, h('h2', { style: 'margin:0' }, '🤖 AI preliminary diagnosis'), latest && h('span', { class: 'muted small' }, `${latest.mode === 'llm' ? 'RAG + LLM' : 'RAG'} · ${res.cases_searched} cases · ${fmtDate(latest.created_at)}`)));
  if (!latest) { wrap.append(h('p', { class: 'muted' }, 'No AI run yet.')); }
  else if (res.insufficient) wrap.append(h('div', { class: 'notice', style: 'margin-top:10px' }, res.summary));
  else {
    wrap.append(h('p', { style: 'margin:10px 0 0' }, res.summary));
    res.recommendations.forEach((x, i) => {
      const accepted = latest.feedback_diagnosis === x.diagnosis && latest.feedback_accepted === 1;
      wrap.append(h('div', { class: 'rec' + (i === 0 ? ' first' : '') },
        h('div', { class: 'row between' }, h('b', null, `${x.rank}. ${x.diagnosis}`), h('span', { class: `conf ${x.confidence}` }, x.confidence)),
        h('div', { class: 'bar', style: 'margin:6px 0' }, h('i', { style: `width:${Math.max(4, x.match_score)}%` })),
        h('div', { class: 'small muted' }, `Best case similarity ${x.match_score}%` + (x.est_cost_php ? ` · typical cost ${peso(x.est_cost_php[0])}–${peso(x.est_cost_php[1])}` : '') + (x.est_hours ? ` · ~${x.est_hours}h` : '')),
        x.root_cause && h('div', { class: 'small', style: 'margin-top:4px' }, x.root_cause),
        x.suggested_checks.length ? h('details', null, h('summary', null, 'Suggested checks'), h('ul', { class: 'clean small' }, x.suggested_checks.map((c) => h('li', null, c)), x.likely_repair && h('li', null, h('b', null, 'Likely repair: '), x.likely_repair))) : null,
        h('details', null, h('summary', null, `Evidence: ${x.supporting_cases.length} similar past case(s)`), h('ul', { class: 'clean small' }, x.supporting_cases.map((c) => h('li', null, `#${c.id} (${c.similarity}%) ${c.brand || ''} – ${c.symptoms}`)))),
        canWork && roleIn('technician', 'admin') && h('div', { class: 'row', style: 'margin-top:6px' }, accepted ? h('span', { class: 'tag' }, '✓ Accepted by technician') : h('button', { class: 'small primary', onclick: act(async () => { await api('POST', `/api/repairs/${r.id}/ai/${latest.id}/feedback`, { diagnosis: x.diagnosis, accepted: true }); toast('Recorded as verified diagnosis'); }) }, 'Confirm after inspection'))));
    });
  }
  if (latest?.feedback_accepted === 0) wrap.append(h('p', { class: 'small' }, `Technician rejected suggestions: ${latest.feedback_diagnosis}`));
  wrap.append(h('div', { class: 'disclaimer' }, res?.disclaimer || 'AI suggestions are preliminary and must be verified by a technician.'));
  if (canWork && roleIn('technician', 'admin', 'frontdesk')) {
    const f = h('form', { style: 'margin-top:10px' }, field('Add inspection observations and re-run', h('textarea', { name: 'extra', maxlength: 800, placeholder: 'e.g. liquid indicator is red, battery reads 62% health…' })), h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { type: 'submit' }, 'Re-run AI diagnosis'),
      latest && roleIn('technician', 'admin') && h('button', { type: 'button', onclick: act(async () => { const d = await ask('Reject AI suggestions', [field('Actual diagnosis (your own finding)', h('input', { name: 'diagnosis', required: true, maxlength: 200 })), field('Note', h('input', { name: 'note', maxlength: 300 }))]); if (!d) throw new Error('Cancelled'); await api('POST', `/api/repairs/${r.id}/ai/${latest.id}/feedback`, { diagnosis: d.diagnosis, accepted: false, note: d.note }); await api('PATCH', `/api/repairs/${r.id}`, { verified_diagnosis: d.diagnosis }); toast('Feedback saved'); }) }, 'None match – enter diagnosis')));
    f.addEventListener('submit', act(async (e) => { e.preventDefault(); toast('Running AI…'); await api('POST', `/api/repairs/${r.id}/diagnose`, formData(f)); }));
    wrap.append(f);
  }
  return wrap;
}

// ---------- knowledge base ----------
async function pageKB() {
  const state = { q: '', status: 'approved' };
  const out = h('div');
  const load = guarded(async () => {
    const rows = await api('GET', `/api/cases?${new URLSearchParams({ q: state.q, status: state.status })}`);
    out.replaceChildren(h('div', { class: 'tablewrap card' }, h('table', null, h('thead', null, h('tr', null, ['#', 'Device', 'Symptoms', 'Diagnosis', 'Typical repair', 'Source', roleIn('admin', 'owner') ? '' : null].map((x) => x != null && h('th', null, x)))),
      h('tbody', null, rows.map((c) => h('tr', null, h('td', null, c.id), h('td', null, `${c.device_type}${c.brand ? ' · ' + c.brand : ''}`), h('td', null, c.symptoms), h('td', null, h('b', null, c.diagnosis)), h('td', { class: 'small' }, c.repair_action || ''), h('td', null, c.source), roleIn('admin', 'owner') && h('td', null, c.status === 'pending' ? h('div', { class: 'row' }, h('button', { class: 'small primary', onclick: guarded(async () => { await api('PATCH', `/api/cases/${c.id}`, { status: 'approved' }); toast('Approved – added to AI knowledge'); load(); }) }, 'Approve'), h('button', { class: 'small danger', onclick: guarded(async () => { await api('PATCH', `/api/cases/${c.id}`, { status: 'rejected' }); load(); }) }, 'Reject')) : c.status === 'approved' ? h('button', { class: 'small', onclick: guarded(async () => { await api('PATCH', `/api/cases/${c.id}`, { status: 'rejected' }); load(); }) }, 'Retire') : null)))))));
  });
  const addForm = roleIn('technician', 'admin') ? (() => {
    const f = h('form', { class: 'card' }, h('h3', null, 'Add a verified case'), h('p', { class: 'muted small' }, me.role === 'admin' ? 'Cases you add are used by the AI immediately.' : 'Your case will be reviewed by an administrator before the AI uses it.'),
      h('div', { class: 'grid g3' }, field('Device type', select('device_type', [['smartphone', 'Smartphone'], ['laptop', 'Laptop'], ['tablet', 'Tablet'], ['other', 'Other']])), field('Brand', input('brand')), field('Model', input('model_hint'))),
      field('Symptoms (as the customer would describe)', h('textarea', { name: 'symptoms', required: true, minlength: 8 })), field('Verified diagnosis', input('diagnosis', { required: true, max: 200 })),
      h('div', { class: 'grid g2' }, field('Root cause', input('root_cause', { max: 400 })), field('Repair action', input('repair_action', { max: 600 }))),
      field('Suggested checks (separate with |)', input('checks', { max: 600 })),
      h('div', { class: 'grid g3' }, field('Min cost (₱)', input('cost_min', { type: 'number', min: 0, step: '0.01' })), field('Max cost (₱)', input('cost_max', { type: 'number', min: 0, step: '0.01' })), field('Hours', input('hours', { type: 'number', min: 0, step: '0.1' }))),
      h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'primary', type: 'submit' }, 'Submit case')));
    f.addEventListener('submit', guarded(async (e) => { e.preventDefault(); const r = await api('POST', '/api/cases', formData(f)); toast(r.status === 'approved' ? 'Case added' : 'Submitted for approval'); f.reset(); load(); }));
    return f;
  })() : null;
  const importCard = roleIn('admin') ? (() => {
    const TEMPLATE = 'device_type,brand,model,symptoms,diagnosis,root_cause,checks,repair_action,parts,cost_min,cost_max,hours\n'
      + '"smartphone","Samsung","Galaxy A12","[EXAMPLE - delete this row] Ayaw mag-charge, kailangan i-angle ang cable","Faulty charging port (dirty, loose or damaged)","Worn or dirty USB port","Clean port; try another cable | Check port with magnifier","Replace charging port flex","Charging port flex",450,900,1\n'
      + '"laptop","Lenovo","IdeaPad 3","[EXAMPLE - delete this row] Laptop is slow and clicking sound from the drive","Failing storage drive (HDD/SSD) or corrupted OS install","Aging HDD","Check SMART status | Test with another drive","Replace with SSD and reinstall OS","SSD",1500,2800,2\n';
    const file = h('input', { type: 'file', accept: '.csv,text/csv', 'aria-label': 'CSV file' });
    const report = h('div');
    let csvText = '';
    const render = (r) => {
      report.replaceChildren(h('div', { class: 'notice', style: 'margin-top:10px' },
        r.dry_run ? h('b', null, `Preview: ${r.valid} of ${r.total_rows} rows are ready to import.`) : h('b', null, `Imported ${r.imported} case(s).`),
        h('ul', { class: 'clean small' },
          r.duplicates ? h('li', null, `${r.duplicates} duplicate row(s) skipped (same symptoms and diagnosis already in your knowledge base).`) : null,
          r.relabelled ? h('li', null, `${r.relabelled} diagnosis name(s) matched to existing spelling so similar cases vote together.`) : null,
          r.new_labels.length ? h('li', null, `New diagnosis names: ${r.new_labels.join('; ')}`) : null,
          r.rejected ? h('li', null, `${r.rejected} row(s) rejected:`) : null),
        r.errors.length ? h('ul', { class: 'clean small' }, r.errors.map((e) => h('li', null, `Row ${e.row}: ${e.message}`))) : null),
        r.dry_run && r.valid ? h('button', { class: 'primary', style: 'margin-top:8px', onclick: guarded(async () => { const o = await api('POST', '/api/cases/import', { csv: csvText, dryRun: false }); toast(`Imported ${o.imported} cases`); render(o); load(); }) }, `Import ${r.valid} case(s)`) : null);
    };
    file.addEventListener('change', guarded(async () => {
      const f = file.files[0]; if (!f) return;
      if (f.size > 1_500_000) throw new Error('File is too large (max about 1.5 MB).');
      csvText = await f.text();
      render(await api('POST', '/api/cases/import', { csv: csvText, dryRun: true }));
    }));
    return h('div', { class: 'card' }, h('h3', null, 'Import past repairs from CSV'),
      h('p', { class: 'muted small' }, 'Bring in your verified job history in bulk. Required columns: symptoms and diagnosis (plus device_type). Imported cases are used by your AI immediately, and only by your shop. You see a preview before anything is saved.'),
      h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => { const a = h('a', { href: URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' })), download: 'repairsync-case-import-template.csv' }); document.body.append(a); a.click(); a.remove(); } }, 'Download template'), file), report);
  })() : null;
  mount(h('h1', null, 'Knowledge base'), h('p', { class: 'muted' }, 'Verified historical repair cases used by the RAG diagnostic module. Completed repairs with a verified diagnosis are queued here for approval.'),
    h('div', { class: 'row', style: 'margin-bottom:12px' }, h('input', { type: 'search', placeholder: 'Search cases…', style: 'max-width:280px', 'aria-label': 'Search cases', oninput: (e) => { state.q = e.target.value; clearTimeout(out.t); out.t = setTimeout(load, 250); } }),
      h('select', { style: 'max-width:200px', 'aria-label': 'Status', onchange: (e) => { state.status = e.target.value; load(); } }, [['approved', 'Approved'], ['pending', 'Pending approval'], ['rejected', 'Rejected / retired']].map(([v, t]) => h('option', { value: v }, t)))),
    h('div', { class: 'detail' }, out, h('div', { class: 'stack' }, addForm, importCard)));
  load();
}

// ---------- users ----------
async function pageUsers() {
  const users = await api('GET', '/api/users');
  const admin = me.role === 'admin';
  const custs = admin ? await api('GET', '/api/customers') : [];
  const reload = () => pageUsers();

  const addForm = admin ? (() => {
    const f = h('form', { class: 'card' }, h('h3', null, 'Add user'),
      field('Name', input('name', { required: true })), field('Email', input('email', { type: 'email', required: true })),
      h('div', { class: 'grid g2' },
        field('Role', select('role', [['technician', 'Technician'], ['frontdesk', 'Front desk'], ['admin', 'Administrator'], ['owner', 'Shop owner']])),
        field('Branch', select('branch_id', [['', '—'], ...branches.map((b) => [b.id, b.name])]))),
      field('Temporary password', input('password', { type: 'password', required: true, max: 100 }), 'Min 8 chars, letters + numbers'),
      h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'primary', type: 'submit' }, 'Create user')));
    f.addEventListener('submit', guarded(async (e) => { e.preventDefault(); await api('POST', '/api/users', formData(f)); toast('User created'); reload(); }));
    return f;
  })() : null;

  const userRow = (u) => {
    const toggle = h('button', { class: 'small', onclick: guarded(async () => { await api('PATCH', `/api/users/${u.id}`, { active: !u.active }); reload(); }) }, u.active ? 'Disable' : 'Enable');
    const reset = h('button', { class: 'small', onclick: guarded(async () => {
      const d = await ask(`Reset password for ${u.name}`, [field('New password', h('input', { name: 'password', type: 'password', required: true, maxlength: 100 }))]);
      if (!d) return;
      await api('PATCH', `/api/users/${u.id}`, { password: d.password });
      toast('Password reset');
    }) }, 'Reset password');
    return h('tr', null, h('td', null, u.name), h('td', null, u.email), h('td', null, ROLE_LABEL[u.role]), h('td', null, u.active ? 'Active' : 'Disabled'),
      admin && u.id !== me.id ? h('td', null, h('div', { class: 'row' }, toggle, reset)) : h('td'));
  };
  const usersCard = h('div', { class: 'card tablewrap' }, h('table', null,
    h('thead', null, h('tr', null, ['Name', 'Email', 'Role', 'Status', ''].map((x) => h('th', null, x)))),
    h('tbody', null, users.map(userRow))));

  const custRow = (c) => {
    const erase = h('button', { class: 'small danger', disabled: c.active_repairs > 0, onclick: guarded(async () => {
      const d = await ask('Erase personal data?', [h('p', null, `This anonymises ${c.name}'s name, phone and email permanently.`)], 'Erase');
      if (!d) return;
      await api('POST', `/api/customers/${c.id}/anonymize`, {});
      toast('Customer data anonymised');
      reload();
    }) }, 'Erase data');
    return h('tr', null, h('td', null, c.name), h('td', { class: 'small' }, [c.phone, c.email].filter(Boolean).join(' · ')),
      h('td', null, `${c.repairs} (${c.active_repairs} open)`), h('td', null, c.anonymized_at ? h('span', { class: 'muted small' }, 'Erased') : erase));
  };
  const privacyCard = admin ? h('div', { class: 'card' }, h('h3', null, 'Customer data requests (Data Privacy Act)'),
    h('p', { class: 'muted small' }, 'Anonymise a customer’s personal data on request. Only possible when they have no open repairs.'),
    h('div', { class: 'tablewrap' }, h('table', null,
      h('thead', null, h('tr', null, ['Customer', 'Contact', 'Repairs', ''].map((x) => h('th', null, x)))),
      h('tbody', null, custs.slice(0, 15).map(custRow))))) : null;

  mount(h('h1', null, 'Users & access'),
    h('div', { class: 'detail' }, h('div', { class: 'stack' }, usersCard, privacyCard), addForm || h('div', { class: 'notice' }, 'Read-only view for the shop owner.')));
}

// ---------- sales report (admin + owner) ----------
const salesState = { range: 'month', at: null };
const escSvg = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const pesoShort = (n) => (n >= 1000 ? '₱' + +(n / 1000).toFixed(1) + 'k' : '₱' + Math.round(n));
const localTime = (s) => new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString('en-PH', { timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'short' });
const MONTH_FULL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function niceMax(v) {
  if (v <= 0) return 1;
  const pow = 10 ** Math.floor(Math.log10(v)); const f = v / pow;
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * pow;
}
function salesChartSvg(d) {
  const W = 900; const H = 300; const L = 62; const R = 8; const T = 14; const B = 30;
  const pw = W - L - R; const ph = H - T - B; const n = d.buckets.length;
  const max = niceMax(Math.max(0, ...d.buckets.map((b) => b.total)));
  const slot = pw / n; const bw = Math.max(3, slot * 0.68);
  let out = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${escSvg(`Sales for ${d.label}: ${peso(d.totals.total)} from ${d.totals.count} payments`)}" class="sales-svg">`;
  for (let i = 0; i <= 4; i++) {
    const v = (max / 4) * i; const y = T + ph - (v / max) * ph;
    out += `<line x1="${L}" x2="${W - R}" y1="${y}" y2="${y}" class="sales-grid"/><text x="${L - 8}" y="${y + 4}" text-anchor="end" class="sales-axis">${escSvg(pesoShort(v))}</text>`;
  }
  const tipLabel = (b, i) => (d.range === 'today' ? `${b.label}:00` : d.range === 'month' ? `${MONTH_FULL[+d.at.slice(5, 7) - 1]} ${b.label}` : `${MONTH_FULL[i]} ${d.label}`);
  d.buckets.forEach((b, i) => {
    const x = L + i * slot + (slot - bw) / 2; const bh = (b.total / max) * ph;
    const tip = `${tipLabel(b, i)} — ${peso(b.total)} (${b.count} payment${b.count === 1 ? '' : 's'})`;
    out += `<g><title>${escSvg(tip)}</title><rect x="${L + i * slot}" y="${T}" width="${slot}" height="${ph}" class="sales-hit"/>`;
    if (b.total > 0) out += `<rect x="${x}" y="${T + ph - bh}" width="${bw}" height="${Math.max(1.5, bh)}" rx="2.5" class="sales-bar"/>`;
    out += '</g>';
    const show = d.range === 'year' || (d.range === 'today' ? i % 3 === 0 : (i === 0 || (i + 1) % 5 === 0));
    if (show) out += `<text x="${x + bw / 2}" y="${H - 9}" text-anchor="middle" class="sales-axis">${escSvg(d.range === 'today' ? b.label : b.label)}</text>`;
  });
  return out + '</svg>';
}

function salesSection() {
  const box = h('section', { class: 'card sales', 'aria-live': 'polite' }, h('p', { class: 'muted' }, 'Loading sales…'));
  const qs = () => {
    const q = new URLSearchParams({ range: salesState.range, tz: String(-new Date().getTimezoneOffset()) });
    if (salesState.at) q.set('at', salesState.at);
    return q.toString();
  };
  const load = async () => {
    try { draw(await api('GET', '/api/reports/sales?' + qs())); } catch (e) { box.replaceChildren(h('p', { class: 'error' }, e.message)); }
  };
  const go = (range, at) => { salesState.range = range; salesState.at = at; load(); };
  const rowsBars = (rows, label) => {
    const max = Math.max(1, ...rows.map((r) => r.total));
    return rows.length ? rows.map((r) => h('div', { class: 'hbar money' }, h('span', null, label(r)), h('div', { class: 'bar' }, h('i', { style: `width:${(r.total / max) * 100}%` })), h('b', null, peso(r.total)))) : h('p', { class: 'muted small' }, 'No payments in this period.');
  };
  const draw = (d) => {
    const t = d.totals;
    const chg = t.change_pct;
    const chgEl = chg == null ? h('b', null, '—') : h('b', { class: chg > 0 ? 'up' : chg < 0 ? 'down' : '' }, `${chg > 0 ? '▲' : chg < 0 ? '▼' : '■'} ${Math.abs(chg)}%`);
    const chart = h('div', { class: 'sales-chart' });
    chart.innerHTML = t.count ? salesChartSvg(d) : '';
    if (!t.count) chart.append(h('p', { class: 'muted sales-empty' }, `No payments recorded for ${d.label}.`));
    box.replaceChildren(
      h('div', { class: 'sales-head' },
        h('div', null, h('h2', null, 'Sales'), h('p', { class: 'muted small' }, 'Payments received by your shop (recorded in this system).')),
        h('div', { class: 'seg', role: 'tablist', 'aria-label': 'Sales period' }, [['today', 'Today'], ['month', 'Month'], ['year', 'Year']].map(([k, l]) => h('button', { type: 'button', role: 'tab', class: d.range === k ? 'on' : '', 'aria-selected': d.range === k ? 'true' : 'false', onclick: () => go(k, null) }, l)))),
      h('div', { class: 'sales-nav' },
        h('button', { type: 'button', class: 'small', 'aria-label': 'Previous period', onclick: () => go(d.range, d.prev_at) }, '‹'),
        h('b', null, d.label),
        h('button', { type: 'button', class: 'small', 'aria-label': 'Next period', disabled: d.next_at ? null : true, onclick: () => go(d.range, d.next_at) }, '›'),
        h('a', { class: 'btn small ghost csv', href: url('/api/reports/sales.csv?' + qs()), download: true }, 'Download CSV')),
      h('div', { class: 'kpis' },
        h('div', { class: 'kpi' }, h('b', null, peso(t.total)), h('span', null, 'Total sales')),
        h('div', { class: 'kpi' }, h('b', null, t.count), h('span', null, 'Payments')),
        h('div', { class: 'kpi' }, h('b', null, peso(t.average)), h('span', null, 'Average payment')),
        h('div', { class: 'kpi' }, chgEl, h('span', null, `vs ${t.comparison === 'same_point' ? 'same point of previous' : 'previous'} ${d.range === 'today' ? 'day' : d.range} (${peso(t.previous_total)})`))),
      chart,
      h('div', { class: 'grid g2' },
        h('div', null, h('h3', null, 'By payment method'), rowsBars(d.by_method, (r) => `${r.method} · ${r.count}`)),
        h('div', null, h('h3', null, 'By branch'), rowsBars(d.by_branch, (r) => r.name.split(' – ').pop()))),
      h('h3', { style: 'margin-top:16px' }, 'Sales log'),
      d.log.length
        ? h('div', { class: 'tablewrap sales-log' }, h('table', null,
          h('thead', null, h('tr', null, ['When', 'Repair', 'Customer', 'Branch', 'Method', 'Amount', 'Received by'].map((x, i) => h('th', { style: i === 5 ? 'text-align:right' : null }, x)))),
          h('tbody', null, d.log.map((r) => h('tr', null, h('td', { class: 'small' }, localTime(r.local_at)), h('td', null, h('code', null, r.code)), h('td', null, r.customer_name || ''), h('td', { class: 'small' }, (r.branch || '').split(' – ').pop()), h('td', null, r.method), h('td', { style: 'text-align:right' }, peso(r.amount)), h('td', { class: 'small' }, r.received_by_name || ''))))))
        : h('p', { class: 'muted small' }, 'No payments in this period.'),
      d.log_truncated ? h('p', { class: 'muted small' }, 'Showing the latest 300 payments. Download the CSV for the full list. Every export is recorded in the audit log.') : h('p', { class: 'muted small' }, 'Every CSV export is recorded in the audit log.'));
  };
  load();
  return box;
}

// ---------- reports ----------
async function pageReports() {
  const s = await api('GET', '/api/reports/summary');
  const bars = (rows, label, val) => { const max = Math.max(1, ...rows.map((r) => r[val])); return rows.length ? rows.map((r) => h('div', { class: 'hbar' }, h('span', null, r[label]), h('div', { class: 'bar' }, h('i', { style: `width:${(r[val] / max) * 100}%` })), h('b', null, r[val]))) : h('p', { class: 'muted small' }, 'No data yet.'); };
  const pct = (a, b) => (b ? Math.round((a / b) * 100) + '%' : '—');
  mount(h('h1', null, 'Reports'),
    salesSection(),
    h('div', { class: 'kpis' }, [['Total repairs', s.totals.repairs], ['Active', s.totals.active], ['Avg. turnaround', s.avg_turnaround_hours == null ? '—' : s.avg_turnaround_hours + ' h'], ['Overdue', s.overdue], ['Collected (recorded)', peso(s.revenue_collected)], ['Customers', s.totals.customers]].map(([l, n]) => h('div', { class: 'kpi' }, h('b', null, n), h('span', null, l)))),
    h('div', { class: 'grid g2' },
      h('div', { class: 'card' }, h('h3', null, 'Repairs by status'), bars(Object.entries(s.by_status).map(([k, n]) => ({ k: STATUS_LABEL[k], n })), 'k', 'n')),
      h('div', { class: 'card' }, h('h3', null, 'Repairs by branch'), bars(s.by_branch.map((b) => ({ k: b.name.split(' – ').pop(), n: b.n })), 'k', 'n'), h('h3', { style: 'margin-top:14px' }, 'By device type'), bars(s.by_device.map((d) => ({ k: d.device_type, n: d.n })), 'k', 'n')),
      h('div', { class: 'card' }, h('h3', null, 'Top verified diagnoses'), bars(s.top_diagnoses.map((d) => ({ k: d.diagnosis.slice(0, 40), n: d.n })), 'k', 'n')),
      h('div', { class: 'card' }, h('h3', null, 'Technician workload (active)'), bars(s.technician_load.map((t) => ({ k: t.name, n: t.active })), 'k', 'n'),
        h('h3', { style: 'margin-top:14px' }, 'AI diagnostic feedback'), h('p', { class: 'small' }, `${s.ai.runs} AI runs · ${s.ai.kb_cases} cases indexed · mode: ${s.ai.mode}`),
        h('p', { class: 'small' }, `Technician-reviewed: ${s.ai.reviewed}. Accepted: ${s.ai.accepted} (${pct(s.ai.accepted, s.ai.reviewed)}). Top-1 accepted: ${pct(s.ai.top1_accepted, s.ai.reviewed)}. Within top 3: ${pct(s.ai.top3_accepted, s.ai.reviewed)}.`),
        h('p', { class: 'muted small' }, 'Acceptance rates reflect technician judgement on real jobs; they are not a controlled accuracy test.'))));
}

async function pageAudit() {
  const rows = await api('GET', '/api/audit?limit=200');
  mount(h('h1', null, 'Audit log'), h('p', { class: 'muted' }, 'Security-relevant actions: sign-ins, record views, status changes, data erasures.'),
    h('div', { class: 'card tablewrap' }, h('table', null, h('thead', null, h('tr', null, ['When', 'Who', 'Action', 'Entity', 'Detail'].map((x) => h('th', null, x)))), h('tbody', null, rows.map((r) => h('tr', null, h('td', { class: 'small' }, fmtDate(r.created_at)), h('td', null, r.actor_label), h('td', null, h('code', null, r.action)), h('td', null, [r.entity, r.entity_id].filter(Boolean).join(' #')), h('td', { class: 'small' }, r.detail || '')))))));
}

boot();
