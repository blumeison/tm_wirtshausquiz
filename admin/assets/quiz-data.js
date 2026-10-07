/* Wirtshausquiz — loads the current quiz night for the print and beamer pages.
   One call, everything resolved: rounds with their full questions in order,
   finale, registered teams. Needs a logged-in backoffice session. */
(function () {
  'use strict';

  function api(path, body) {
    var init = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch('../api/' + path, init)
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || j.ok === false) { var e = new Error(j.error || 'HTTP ' + r.status); e.status = r.status; throw e; }
          return j;
        });
      });
  }

  function youtubeId(url) {
    var m = String(url || '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/);
    return m ? m[1] : '';
  }

  /** {kind:'youtube'|'file', id|src, start, end, search} or null — for AUDIO/VIDEO questions. */
  function media(q) {
    if (q.type !== 'AUDIO' && q.type !== 'VIDEO') return null;
    var p = q.payload || {}, start = p.startSeconds || 0;
    var end = p.clipSeconds ? start + p.clipSeconds : 0;
    var id = youtubeId(p.youtubeUrl);
    if (id) return { kind: 'youtube', id: id, start: start, end: end, video: q.type === 'VIDEO' };
    if (p.mediaUrl) return { kind: 'file', src: p.mediaUrl, start: start, end: end, video: q.type === 'VIDEO' };
    return { kind: 'missing', search: p.youtubeSearch || '', video: q.type === 'VIDEO' };
  }

  function load() {
    return Promise.all([
      api('sessions/get.php'), api('questions/list.php'), api('admin/registrations.php')
    ]).then(function (r) {
      var S = r[0].session, byId = {};
      r[1].questions.forEach(function (q) { byId[q.id] = q; });
      function full(rq, round) {
        var q = byId[rq.questionId];
        if (!q) return null;
        var pts = rq.pointsOverride != null ? rq.pointsOverride : q.points;
        return Object.assign({}, q, { pts: Math.round(pts * ((round && round.multiplier) || 1)), media: media(q) });
      }
      var rounds = S.rounds.map(function (round, i) {
        return {
          n: i + 1, title: round.title || 'Runde ' + (i + 1), kind: round.kind || 'NORMAL',
          questions: round.questions.map(function (rq) { return full(rq, round); }).filter(Boolean)
        };
      });
      var fin = S.finale || {};
      return {
        title: S.title, event: r[0].event || {}, rounds: rounds,
        master: fin.masterId ? full({ questionId: fin.masterId }) : null,
        tiebreak: fin.tiebreakId ? full({ questionId: fin.tiebreakId }) : null,
        teams: r[2].rows.filter(function (t) { return t.status !== 'CANCELLED'; })
          .map(function (t) { return t.teamName; })
      };
    });
  }

  var TYPE = {
    MULTIPLE_CHOICE: 'Multiple Choice', OPEN_TEXT: 'Offene Frage', ESTIMATE: 'Schätzfrage',
    IMAGE: 'Bildfrage', AUDIO: 'Hörfrage', VIDEO: 'Videofrage', MAP: 'Kartenfrage', MASTER: 'Masterfrage'
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Shown when the page is opened without a backoffice login. */
  function fail(e, el) {
    el.innerHTML = '<p style="font:16px/1.5 Rubik,sans-serif;padding:2rem">'
      + (e.status === 401 || e.status === 403
        ? 'Nicht angemeldet. Bitte zuerst im <a href="./">Backoffice</a> anmelden, dann diese Seite neu laden.'
        : 'Laden fehlgeschlagen: ' + esc(e.message)) + '</p>';
  }

  /**
   * Standings from api/live.php: rounds 0..upto summed, ties share a rank.
   * Once anyone is checked in, only checked-in teams count — no-shows vanish.
   */
  function table(live, upto) {
    var anyIn = Object.keys(live.checkin || {}).length > 0;
    var rows = live.teams.filter(function (t) { return !anyIn || live.checkin[t.id]; }).map(function (t) {
      var s = (live.scores || {})[t.id] || {}, per = [], total = 0;
      for (var r = 0; r <= upto; r++) { var v = s[r]; per.push(v == null ? null : v); total += v || 0; }
      return { id: t.id, name: t.name, per: per, total: Math.round(total * 10) / 10 };
    });
    rows.sort(function (a, b) { return b.total - a.total || a.name.localeCompare(b.name, 'de'); });
    rows.forEach(function (r, i) { r.rank = i && rows[i - 1].total === r.total ? rows[i - 1].rank : i + 1; });
    return rows;
  }

  window.WQD = { load: load, api: api, table: table, TYPE: TYPE, esc: esc, fail: fail, youtubeId: youtubeId };
})();
