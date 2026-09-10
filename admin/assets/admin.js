/* Wirtshausquiz — backoffice. Vanilla JS, no build step. Shell from tm_go. */
(function () {
  'use strict';

  var SESSION = null;   // {loggedIn, role, user, googleClientId, sessionId}
  var $app = document.getElementById('app');
  var $gate = document.getElementById('gate');
  var $me = document.getElementById('me');
  var $tabs = document.getElementById('tabs');

  // ---- helpers -------------------------------------------------------------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function toast(msg, isErr) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast' + (isErr ? ' err' : ''); t.hidden = false;
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.hidden = true; }, 2800);
  }

  function api(path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch('../api/' + path, init).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || j.ok === false) { var e = new Error(j.error || ('HTTP ' + r.status)); e.status = r.status; throw e; }
        return j;
      });
    });
  }

  var ERR = {
    login_required: 'Bitte neu anmelden.',
    forbidden: 'Dafür fehlt die Berechtigung.',
    invalid_token: 'Google-Anmeldung ungültig, bitte nochmal.',
    google_not_configured: 'Google-Login ist noch nicht eingerichtet.',
    email_not_verified: 'Diese Google-Adresse ist nicht bestätigt.'
  };
  function errText(e) { return ERR[e.message] || e.message || 'Fehler'; }

  var DAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso); if (isNaN(d)) return iso;
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return DAYS[d.getDay()] + ' ' + p(d.getDate()) + '.' + p(d.getMonth() + 1) + '. ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  // ---- origin labels ---------------------------------------------------------
  var HEARD = {
    freunde: 'Freunde / Bekannte', facebook: 'Facebook', instagram: 'Instagram',
    schwarzesbrett: 'Schwarzes Brett (WhatsApp)', plakat: 'Plakat', zeitung: 'Zeitung',
    screen: 'Werbescreen', wirt: 'Im Gasthaus', sonstiges: 'Anders'
  };
  var CHANNEL = { burchhart: 'Plakat Burchhart', billa: 'Plakat Billa', bahnhof: 'Plakat Bahnhof' };

  function qrLabel(r) {
    if (!r.tmSrc && !r.tmCh) return '';
    if (r.tmCh) return CHANNEL[r.tmCh] || ('QR ' + r.tmCh);
    return 'QR/Kurzlink ' + r.tmSrc;
  }
  function refHost(u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return ''; } }
  /** Registrations before 10.09. were stored without any origin fields. */
  function hasOriginFields(r) { return r.heardFrom !== null || r.tmSrc !== null || r.referrer !== null; }

  // ---- boot ----------------------------------------------------------------
  function boot() {
    api('session.php').then(function (s) {
      SESSION = s;
      if (!s.loggedIn) { return showGate('login'); }
      renderMe();
      if (s.role === 'PENDING') { return showGate('pending'); }
      showApp();
    }).catch(function () { showGate('login', 'Verbindung zum Server fehlgeschlagen.'); });
  }

  function showGate(mode, msg) {
    $gate.hidden = false; $app.hidden = true; $tabs.hidden = true;
    var title = document.getElementById('gate-title'), text = document.getElementById('gate-text');
    document.getElementById('gate-msg').textContent = msg || '';
    if (mode === 'pending') {
      title.textContent = 'Noch nicht freigeschaltet';
      text.textContent = 'Du bist als ' + (SESSION.user.email || '') + ' angemeldet. Ein Admin muss dein Konto '
        + 'erst freischalten — sag Markus Bescheid, dann geht es hier weiter.';
      document.getElementById('gsi-btn').innerHTML = '';
      return;
    }
    initGoogle();
  }

  function showApp() {
    $gate.hidden = true; $app.hidden = false; $tabs.hidden = false;
    var adminTab = $tabs.querySelector('[data-admin]');
    adminTab.hidden = SESSION.role !== 'ADMIN';
    window.addEventListener('hashchange', route);
    route();
  }

  function renderMe() {
    var u = SESSION.user || {};
    $me.innerHTML = '';
    if (u.picture) { var img = new Image(); img.src = u.picture; img.alt = ''; img.referrerPolicy = 'no-referrer'; $me.appendChild(img); }
    var name = document.createElement('span'); name.textContent = u.name || u.email || ''; $me.appendChild(name);
    var out = document.createElement('button'); out.type = 'button'; out.textContent = 'Abmelden';
    out.onclick = function () { api('logout.php', { method: 'POST' }).then(function () { location.reload(); }); };
    $me.appendChild(out);
  }

  function initGoogle() {
    if (!SESSION || !SESSION.googleClientId) {
      document.getElementById('gsi-btn').innerHTML =
        '<p class="tm-text-muted" style="font-size:var(--tm-fs-sm)">Google-Login ist noch nicht eingerichtet — '
        + 'die Client-ID fehlt in <code>api/config.php</code>.</p>';
      return;
    }
    if (window.google && window.google.accounts) { drawGoogle(); return; }
    var s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.defer = true;
    s.onload = drawGoogle; document.head.appendChild(s);
  }
  function drawGoogle() {
    window.google.accounts.id.initialize({
      client_id: SESSION.googleClientId,
      callback: function (resp) {
        api('auth/google.php', { method: 'POST', body: { credential: resp.credential } })
          .then(function () { location.reload(); })
          .catch(function (e) { document.getElementById('gate-msg').textContent = errText(e); });
      }
    });
    window.google.accounts.id.renderButton(document.getElementById('gsi-btn'),
      { theme: 'filled_blue', size: 'large', text: 'signin_with', shape: 'pill' });
  }

  // ---- router --------------------------------------------------------------
  function route() {
    var parts = location.hash.replace(/^#\/?/, '').split('/');
    var tab = parts[0] || 'anmeldungen';
    if (tab === 'benutzer' && SESSION.role !== 'ADMIN') tab = 'anmeldungen';
    [].forEach.call($tabs.querySelectorAll('a'), function (a) { a.classList.toggle('is-on', a.getAttribute('data-tab') === tab); });
    window.scrollTo(0, 0);
    if (tab === 'benutzer') return viewUsers();
    if (tab === 'fragen' && window.WQ.views.fragen) return window.WQ.views.fragen(parts);
    if (tab === 'abend' && window.WQ.views.abend) return window.WQ.views.abend(parts);
    return viewRegistrations();
  }

  function loading() { $app.innerHTML = '<div class="spin" aria-label="Lädt"></div>'; }
  function failView(e) {
    if (e.status === 401) { location.reload(); return; }
    $app.innerHTML = '<div class="empty">' + esc(errText(e)) + '</div>';
  }

  // ---- view: registrations -------------------------------------------------
  function viewRegistrations() {
    loading();
    api('admin/registrations.php').then(renderRegistrations).catch(failView);
  }

  function renderRegistrations(d) {
    var c = d.counters;
    var active = d.rows.filter(function (r) { return r.status !== 'CANCELLED'; });
    var cancelled = d.rows.filter(function (r) { return r.status === 'CANCELLED'; });

    var html = ''
      + '<div class="page-head"><div><h2>Anmeldungen</h2>'
      + '<p>' + c.teamsConfirmed + ' von ' + d.capacity + ' Plätzen vergeben'
      + (c.teamsWaitlist ? ' · ' + c.teamsWaitlist + ' auf der Warteliste' : '') + '</p></div>'
      + '<div class="btn-row"><button class="tm-btn tm-btn--ghost btn-sm" type="button" id="reload">Aktualisieren</button>'
      + '<a class="tm-btn tm-btn--primary btn-sm" href="../api/admin/registrations.php?format=csv">CSV-Export</a></div></div>'
      + '<div class="kpi-grid">'
      + kpi(c.teamsTotal, 'Teams') + kpi(c.peopleConfirmed, 'Personen fix')
      + kpi(c.spotsLeft, 'Plätze frei') + kpi(c.teamsWaitlist, 'Warteliste') + kpi(c.cancelled, 'Abgesagt')
      + '</div>'
      + '<h3 class="section-title">Woher sie kommen</h3>' + originSummary(active)
      + '<h3 class="section-title">Teams</h3>';

    if (!active.length) {
      html += '<div class="empty">Noch keine Anmeldungen.</div>';
    } else {
      html += '<div class="team-list">' + active.map(teamCard).join('') + '</div>';
    }
    if (cancelled.length) {
      html += '<details class="cancelled-block"><summary>' + cancelled.length + ' abgesagt</summary>'
        + '<div class="team-list">' + cancelled.map(teamCard).join('') + '</div></details>';
    }
    $app.innerHTML = html;

    document.getElementById('reload').onclick = viewRegistrations;
    [].forEach.call($app.querySelectorAll('[data-cancel]'), function (b) {
      b.onclick = function () { cancelTeam(b.getAttribute('data-cancel'), b.getAttribute('data-name')); };
    });
  }

  function kpi(v, label) {
    return '<div class="kpi"><div class="kpi__val">' + esc(v) + '</div><div class="kpi__lab">' + esc(label) + '</div></div>';
  }

  function originSummary(rows) {
    if (!rows.length) return '<div class="empty">Sobald sich Teams anmelden, steht hier, woher sie kommen.</div>';
    var heard = {}, qr = {}, noData = 0;
    rows.forEach(function (r) {
      if (!hasOriginFields(r)) { noData++; return; }
      var h = r.heardFrom ? (HEARD[r.heardFrom] || r.heardFrom) : 'keine Angabe';
      heard[h] = (heard[h] || 0) + 1;
      var q = qrLabel(r) || (refHost(r.referrer) ? 'Link von ' + refHost(r.referrer) : 'direkt / geteilter Link');
      qr[q] = (qr[q] || 0) + 1;
    });
    function table(title, map, extra) {
      var keys = Object.keys(map).sort(function (a, b) { return map[b] - map[a]; });
      var max = keys.length ? map[keys[0]] : 1;
      var body = keys.map(function (k) {
        var muted = k === 'keine Angabe' || k === 'direkt / geteilter Link';
        return '<tr' + (muted ? ' class="src-muted"' : '') + '><td>' + esc(k)
          + '<span class="src-bar" style="width:' + Math.round(map[k] / max * 100) + '%"></span></td><td>' + map[k] + '</td></tr>';
      }).join('');
      if (extra) body += '<tr class="src-muted"><td>' + esc(extra[0]) + '</td><td>' + extra[1] + '</td></tr>';
      if (!body) body = '<tr class="src-muted"><td>—</td><td></td></tr>';
      return '<div><p class="src-title">' + esc(title) + '</p><table class="src-table"><tbody>' + body + '</tbody></table></div>';
    }
    var old = noData ? ['vor dem 10.09. (nicht erfasst)', noData] : null;
    return '<div class="origin-grid">'
      + table('Selbst angegeben: „Wie habt ihr vom Quiz erfahren?"', heard, old)
      + table('Technisch: über welchen Weg auf die Seite', qr, old)
      + '</div>';
  }

  function teamCard(r) {
    var cancelled = r.status === 'CANCELLED';
    var badges = '<span class="badge badge--size">' + esc(r.size) + ' Pers.</span>';
    if (!cancelled && r.slot === 'WAITLIST') badges += '<span class="badge badge--wait">Warteliste</span>';
    if (!cancelled && r.promoRank > 0) badges += '<span class="badge badge--promo">🍺 Freirunde #' + r.promoRank + '</span>';
    if (r.lookingForPlayers) badges += '<span class="badge badge--look">sucht Mitspieler</span>';

    var origin = [];
    if (r.heardFrom) origin.push('<b>' + esc(HEARD[r.heardFrom] || r.heardFrom) + '</b>');
    if (qrLabel(r)) origin.push(esc(qrLabel(r)));
    if (refHost(r.referrer)) origin.push('von ' + esc(refHost(r.referrer)));
    var originHtml = hasOriginFields(r)
      ? (origin.length ? origin.join(' · ') : '<span class="tm-text-muted">keine Angabe</span>')
      : '<span class="tm-text-muted">Herkunft nicht erfasst (vor dem 10.09.)</span>';

    var who = esc(r.captainName)
      + ' · <a href="mailto:' + esc(r.email) + '">' + esc(r.email) + '</a>'
      + (r.phone ? ' · <a href="tel:' + esc(String(r.phone).replace(/[^0-9+]/g, '')) + '">' + esc(r.phone) + '</a>' : '');

    var meta = 'Angemeldet ' + esc(fmtDate(r.createdAt))
      + (cancelled ? ' · abgesagt ' + esc(fmtDate(r.cancelledAt)) + (r.cancelledBy ? ' von ' + esc(r.cancelledBy) : ' (selbst)') : '')
      + (r.ip ? ' · IP ' + esc(r.ip) : '');

    return '<article class="team' + (cancelled ? ' is-cancelled' : '') + '">'
      + '<div class="team__pos">' + (cancelled ? '–' : esc(r.position)) + '</div>'
      + '<div><h4 class="team__name">' + esc(r.teamName) + '</h4>'
      + '<p class="team__who">' + who + '</p>'
      + '<div class="team__badges">' + badges + '</div>'
      + '<div class="team__origin">Herkunft: ' + originHtml + '</div>'
      + (r.note ? '<p class="team__note">' + esc(r.note) + '</p>' : '')
      + '<div class="team__meta">' + meta + '</div></div>'
      + '<div class="team__actions">'
      + (cancelled ? '' : '<button class="tm-btn tm-btn--ghost btn-sm btn-danger" type="button" data-cancel="'
        + esc(r.id) + '" data-name="' + esc(r.teamName) + '">Absagen</button>')
      + '</div></article>';
  }

  function cancelTeam(id, name) {
    if (!confirm('„' + name + '" wirklich absagen?\n\nDas Team bekommt keine Mail — sagt ihnen selbst Bescheid. '
      + 'Wer auf der Warteliste steht, rückt automatisch nach.')) return;
    api('admin/registrations.php', { method: 'POST', body: { action: 'cancel', id: id } })
      .then(function () { toast('„' + name + '" abgesagt.'); viewRegistrations(); })
      .catch(function (e) { toast(errText(e), true); });
  }

  // ---- view: users ---------------------------------------------------------
  var ROLE_LABEL = { ADMIN: 'Admin', EDITOR: 'Redaktion', PENDING: 'wartet' };

  function viewUsers() {
    loading();
    api('admin/users.php').then(renderUsers).catch(failView);
  }

  function roleSelect(u) {
    if (u.fixed) return '<span class="badge badge--size">Admin (fix)</span>';
    return '<select class="tm-select sm" data-role="' + esc(u.email) + '">'
      + ['ADMIN', 'EDITOR', 'PENDING'].map(function (r) {
        return '<option value="' + r + '"' + (u.role === r ? ' selected' : '') + '>' + ROLE_LABEL[r] + '</option>';
      }).join('') + '</select>';
  }

  function renderUsers(d) {
    var pending = d.users.filter(function (u) { return u.role === 'PENDING'; }).length;
    var html = '<div class="page-head"><div><h2>Benutzer</h2>'
      + '<p>Wer ins Backoffice darf. <strong>Redaktion</strong> sieht und bearbeitet Anmeldungen, '
      + '<strong>Admin</strong> zusätzlich diese Liste.</p></div></div>'
      + (pending ? '<div class="info-note"><strong>' + pending + ' wartet auf Freischaltung.</strong> '
        + 'Neue Google-Konten landen automatisch hier — Rolle wählen, fertig.</div>' : '')
      + '<div class="user-list">' + d.users.map(function (u) {
        return '<div class="user-row"><div class="user-row__main"><div class="user-row__mail">' + esc(u.email)
          + (u.email === d.me ? ' <span class="tm-text-muted">(du)</span>' : '') + '</div>'
          + '<div class="user-row__sub">' + esc(u.name || '')
          + (u.lastLogin ? ' · zuletzt ' + esc(fmtDate(u.lastLogin)) : ' · noch nie angemeldet') + '</div></div>'
          + roleSelect(u)
          + (u.fixed ? '' : '<button class="tm-btn tm-btn--ghost btn-sm btn-danger" type="button" data-del="' + esc(u.email) + '">Entfernen</button>')
          + '</div>';
      }).join('') + '</div>'
      + '<h3 class="section-title">Jemanden vorab freischalten</h3>'
      + '<form class="add-row" id="add-user"><input class="tm-input" type="email" required placeholder="name@gmail.com" id="add-email">'
      + '<select class="tm-select" id="add-role"><option value="EDITOR">Redaktion</option><option value="ADMIN">Admin</option></select>'
      + '<button class="tm-btn tm-btn--primary btn-sm" type="submit">Freischalten</button></form>'
      + '<p class="tm-text-muted" style="font-size:var(--tm-fs-sm);margin-top:.5rem">Gilt für die Google-Adresse, mit der sich die Person anmeldet.</p>';
    $app.innerHTML = html;

    [].forEach.call($app.querySelectorAll('[data-role]'), function (s) {
      s.onchange = function () { usersPost({ action: 'setRole', email: s.getAttribute('data-role'), role: s.value }, 'Rolle geändert.'); };
    });
    [].forEach.call($app.querySelectorAll('[data-del]'), function (b) {
      b.onclick = function () {
        var email = b.getAttribute('data-del');
        if (confirm(email + ' aus dem Backoffice entfernen?')) usersPost({ action: 'delete', email: email }, 'Entfernt.');
      };
    });
    document.getElementById('add-user').onsubmit = function (ev) {
      ev.preventDefault();
      usersPost({ action: 'add', email: document.getElementById('add-email').value, role: document.getElementById('add-role').value }, 'Freigeschaltet.');
    };
  }

  function usersPost(body, okMsg) {
    api('admin/users.php', { method: 'POST', body: body })
      .then(function () { toast(okMsg); viewUsers(); })
      .catch(function (e) { toast(errText(e), true); viewUsers(); });
  }

  // Shared with the other backoffice modules (fragen.js, …), which register
  // their views on WQ.views and are loaded after this file.
  window.WQ = {
    api: api, esc: esc, toast: toast, fmtDate: fmtDate, errText: errText,
    loading: loading, failView: failView, app: $app,
    session: function () { return SESSION; },
    views: {}
  };

  boot();
})();
