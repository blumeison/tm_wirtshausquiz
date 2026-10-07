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
        return Object.assign({}, q, { pts: Math.round(pts * ((round && round.multiplier) || 1)), media: media(q),
          masterClue: !!rq.masterClue, clueNote: rq.clueNote || '' });
      }
      var rounds = S.rounds.map(function (round, i) {
        return {
          n: i + 1, title: round.title || 'Runde ' + (i + 1), kind: round.kind || 'NORMAL',
          questions: round.questions.map(function (rq) { return full(rq, round); }).filter(Boolean)
        };
      });
      var fin = S.finale || {};
      // 👑-Stichworte für die Masterfrage, in Rundenreihenfolge.
      var clues = [];
      rounds.forEach(function (r) { r.questions.forEach(function (q) { if (q.masterClue && q.clueNote) clues.push({ round: r.n, note: q.clueNote }); }); });
      return {
        title: S.title, event: r[0].event || {}, rounds: rounds, clues: clues,
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
   * With tbValue (the tie-break answer) equal totals are split by how close each
   * team's guess (scores[id].tb) came; no guess sorts behind any guess.
   */
  function table(live, upto, tbValue) {
    var anyIn = Object.keys(live.checkin || {}).length > 0;
    var rows = live.teams.filter(function (t) { return !anyIn || live.checkin[t.id]; }).map(function (t) {
      var s = (live.scores || {})[t.id] || {}, per = [], total = 0;
      for (var r = 0; r <= upto; r++) { var v = s[r]; per.push(v == null ? null : v); total += v || 0; }
      var tb = tbValue == null || s.tb == null ? Infinity : Math.abs(s.tb - tbValue);
      return { id: t.id, name: t.name, promo: !!t.promo, per: per, total: Math.round(total * 10) / 10, tb: tb, guess: s.tb };
    });
    rows.sort(function (a, b) { return b.total - a.total || a.tb - b.tb || a.name.localeCompare(b.name, 'de'); });
    rows.forEach(function (r, i) {
      var p = rows[i - 1];
      r.rank = p && p.total === r.total && p.tb === r.tb ? p.rank : i + 1;
    });
    return rows;
  }

  /**
   * Scoring rule of an estimate question as one sentence, with the concrete
   * range for TOLERANCE so nobody does percentages in their head at the pub.
   * Whole-number answers get a whole-number range (7 ± 10 % → "genau 7").
   */
  function estimateRule(q) {
    var p = q.payload || {}, unit = p.unit ? ' ' + p.unit : '';
    if (p.scoring !== 'TOLERANCE') return 'Punkte für das Team, das am nächsten dran ist (bei Gleichstand alle gleich nahen).';
    var v = Number(p.value), d = Math.abs(v) * (p.tolerancePercent || 10) / 100, lo = v - d, hi = v + d;
    if (v === Math.round(v)) { lo = Math.ceil(lo); hi = Math.floor(hi); }
    var f = function (n) { return n.toLocaleString('de-AT', { maximumFractionDigits: 2 }); };
    if (lo >= hi) return 'Punkte nur für genau ' + f(v) + unit + '.';
    return 'Punkte für alle Tipps von ' + f(lo) + ' bis ' + f(hi) + unit + ' (± ' + (p.tolerancePercent || 10) + ' %).';
  }

  window.WQD = { estimateRule: estimateRule, load: load, api: api, table: table, TYPE: TYPE, esc: esc, fail: fail, youtubeId: youtubeId };
})();
