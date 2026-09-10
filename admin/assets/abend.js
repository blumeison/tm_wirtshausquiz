/* Wirtshausquiz — backoffice: quiz night builder (P5).
   One quiz night = one JSON document with the rounds and the finale embedded.
   Every change saves itself; the server's `rev` stops two editors from
   overwriting each other. Uses the helpers admin.js puts on window.WQ. */
(function () {
  'use strict';

  var ICON = { MULTIPLE_CHOICE: '🔘', OPEN_TEXT: '✍️', ESTIMATE: '📏', IMAGE: '🖼️', AUDIO: '🎵', VIDEO: '🎬', MAP: '🗺️', MASTER: '👑' };
  var LABEL = { MULTIPLE_CHOICE: 'Multiple Choice', OPEN_TEXT: 'Offene Frage', ESTIMATE: 'Schätzfrage', IMAGE: 'Bilderfrage',
                AUDIO: 'Musikfrage', VIDEO: 'Videofrage', MAP: 'Kartenfrage', MASTER: 'Masterfrage' };
  var KIND = [['NORMAL', 'Normale Runde'], ['HANDOUT', 'Handout am Tisch']];
  var MULT = [[1, '×1'], [1.5, '×1,5'], [2, '×2'], [3, '×3']];
  var TARGET = 8; // questions per round, as agreed for the premiere

  var W = window.WQ;
  var S = null, EVENT = {}, POOL = [], BY_ID = {};
  var PF = { q: '', type: '' };
  var OPEN = {}; // questionId -> true: expanded to show the whole question
  var POOL_OPEN = true; // the pool column can fold away to a slim tab
  try { POOL_OPEN = localStorage.getItem('wq-pool-open') !== '0'; } catch (e) {}
  var saveTimer = null, pending = false, saving = false, again = false;
  var stateText = '', stateErr = false;

  function esc(s) { return W.esc(s); }
  function $(id) { return document.getElementById(id); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function short(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function stars(d) { d = Math.min(Math.max(d || 2, 1), 3); return '★★★'.slice(0, d) + '☆☆☆'.slice(0, 3 - d); }
  function options(list, current) {
    return list.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(current) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
  }
  function field(label, control, hintHtml) {
    return '<label class="tm-field"><span class="tm-label">' + label + '</span>' + control
      + (hintHtml ? '<span class="tm-hint">' + hintHtml + '</span>' : '') + '</label>';
  }
  function kpi(v, label) {
    return '<div class="kpi"><div class="kpi__val">' + esc(v) + '</div><div class="kpi__lab">' + esc(label) + '</div></div>';
  }
  function swap(arr, a, b) {
    if (b < 0 || b >= arr.length) return;
    var t = arr[a]; arr[a] = arr[b]; arr[b] = t;
  }

  // ---- points, time, usage -----------------------------------------------------
  function eff(rq, round) {
    var q = BY_ID[rq.questionId];
    if (!q) return 0;
    return Math.round((rq.pointsOverride != null ? rq.pointsOverride : q.points) * (round.multiplier || 1));
  }
  function roundPoints(r) { return r.questions.reduce(function (s, rq) { return s + eff(rq, r); }, 0); }

  /** Rough running time: 1.5 min per question + 10 min answers per round, plus the fixed blocks. */
  function minutes() {
    var m = 10; // welcome and rules
    S.rounds.forEach(function (r) { m += (r.kind === 'HANDOUT' ? 0 : r.questions.length * 1.5) + 10; });
    m += 30;    // break after round 2 + short break before the finale
    if (S.finale.masterId) m += 15;
    m += 15;    // final standings, tie-break, prizes
    return Math.round(m);
  }
  function dur(m) { return Math.floor(m / 60) + ':' + pad(m % 60) + ' h'; }
  function clock(start, m) {
    var p = String(start || '18:30').split(':'), t = Number(p[0]) * 60 + Number(p[1]) + m;
    return pad(Math.floor(t / 60) % 24) + ':' + pad(t % 60);
  }

  function usedMap() {
    var u = {};
    S.rounds.forEach(function (r, i) { r.questions.forEach(function (rq) { u[rq.questionId] = 'R' + (i + 1); }); });
    if (S.finale.masterId) u[S.finale.masterId] = 'Finale';
    if (S.finale.tiebreakId) u[S.finale.tiebreakId] = 'Stechfrage';
    return u;
  }

  /** Answers announced as 👑 clues over the evening, in play order. */
  function masterClues() {
    var out = [];
    S.rounds.forEach(function (r, i) {
      r.questions.forEach(function (rq) {
        var q = BY_ID[rq.questionId];
        if (rq.masterClue && q) out.push({ round: i + 1, q: q, note: rq.clueNote || '' });
      });
    });
    return out;
  }

  function needsMedia(q) {
    var p = q.payload || {};
    if (q.type === 'IMAGE') return !p.imageUrl;
    if (q.type === 'AUDIO' || q.type === 'VIDEO') return !p.mediaUrl && !p.youtubeUrl;
    return false;
  }

  /** [ok, text] rows for "Bereit für den Abend?" */
  function checks() {
    var out = [], drafts = 0, missing = 0, noMedia = 0;
    S.rounds.forEach(function (r) {
      r.questions.forEach(function (rq) {
        var q = BY_ID[rq.questionId];
        if (!q) { missing++; return; }
        if (q.status === 'DRAFT') drafts++;
        if (needsMedia(q)) noMedia++;
      });
    });
    out.push([S.rounds.length >= 4, S.rounds.length + ' Runden geplant']);
    S.rounds.forEach(function (r, i) {
      out.push([r.questions.length >= TARGET, 'R' + (i + 1) + ' „' + r.title + '“: ' + r.questions.length + ' von ' + TARGET + ' Fragen']);
    });
    var m = S.finale.masterId ? BY_ID[S.finale.masterId] : null;
    out.push([!!m, m ? 'Masterfrage fürs Finale: „' + short(m.answer, 30) + '“' : 'Masterfrage fürs Finale fehlt']);
    var clues = masterClues(), withClue = {};
    clues.forEach(function (c) { withClue[c.round] = true; });
    var covered = Object.keys(withClue).length;
    out.push([S.rounds.length > 0 && covered === S.rounds.length,
      '👑 ' + clues.length + (clues.length === 1 ? ' Masterhinweis' : ' Masterhinweise') + ' — in ' + covered + ' von ' + S.rounds.length + ' Runden'
      + (covered < S.rounds.length ? ' (am besten einer pro Runde)' : '')]);
    if (m) {
      var p = m.payload || {}, h = p.hints || [];
      out.push([p.clueMode === 'COUNTDOWN' && h.length >= 3,
        p.clueMode === 'COUNTDOWN' ? 'Countdown mit ' + h.length + ' Hinweisen' + (h.length < 3 ? ' — mindestens 3 sind besser' : '')
                                   : 'Die Masterfrage ist nicht im Countdown-Modus']);
    }
    out.push([!!S.finale.tiebreakId, S.finale.tiebreakId ? 'Stechfrage für Gleichstand gesetzt' : 'Stechfrage für Gleichstand fehlt']);
    if (drafts) out.push([false, drafts + (drafts === 1 ? ' Frage ist' : ' Fragen sind') + ' noch als Entwurf markiert']);
    if (noMedia) out.push([false, noMedia + ' Bild-, Musik- oder Videofrage' + (noMedia === 1 ? '' : 'n') + ' ohne Datei']);
    if (missing) out.push([false, missing + ' Frage' + (missing === 1 ? '' : 'n') + ' gibt es im Pool nicht mehr']);
    return out;
  }

  // ---- entry (called by admin.js) ------------------------------------------------
  W.views.abend = function () {
    W.loading();
    Promise.all([W.api('sessions/get.php'), W.api('questions/list.php')]).then(function (r) {
      S = r[0].session;
      S.finale = S.finale || { masterId: null, tiebreakId: null };
      EVENT = r[0].event || {};
      POOL = r[1].questions || [];
      BY_ID = {};
      POOL.forEach(function (q) { BY_ID[q.id] = q; });
      stateText = S.rev ? '' : 'Neu angelegt — wird beim ersten Ändern gespeichert';
      stateErr = false;
      render();
    }).catch(W.failView);
  };

  window.addEventListener('beforeunload', function (e) {
    if (pending || saving) { e.preventDefault(); e.returnValue = ''; }
  });

  // ---- render --------------------------------------------------------------------
  function render() {
    var poolScroll = $('pool-list') ? $('pool-list').scrollTop : 0;
    var nQ = 0, total = 0;
    S.rounds.forEach(function (r) { nQ += r.questions.length; total += roundPoints(r); });
    var mins = minutes(), end = clock(EVENT.startTime, mins), late = EVENT.endTime && end > EVENT.endTime;
    var ch = checks(), open = ch.filter(function (c) { return !c[0]; }).length;
    var sub = [EVENT.dateLong, EVENT.venueShort, EVENT.startTime ? 'Quizstart ' + EVENT.startTime : ''].filter(Boolean).join(' · ');

    W.app.innerHTML = '<div id="abend">'
      + '<div class="page-head"><div><h2>' + esc(S.title) + '</h2><p>' + esc(sub) + '</p></div>'
      + '<span class="save-state" id="save-state" role="status"></span></div>'
      + '<div class="kpi-grid">' + kpi(S.rounds.length, 'Runden') + kpi(nQ, 'Fragen') + kpi(total, 'Punkte')
      + kpi(dur(mins), 'Dauer ca.') + kpi(end, late ? 'Ende ca. — zu spät!' : 'Ende ca.') + '</div>'
      + '<details class="checklist"' + (open ? ' open' : '') + '><summary>'
      + (open ? '⚠️ Noch ' + open + (open === 1 ? ' Punkt' : ' Punkte') + ' offen bis zum Abend' : '✅ Alles bereit für den Abend')
      + '</summary><ul>' + ch.map(function (c) {
        return '<li class="' + (c[0] ? 'ok' : 'todo') + '">' + (c[0] ? '✓ ' : '• ') + esc(c[1]) + '</li>';
      }).join('') + '</ul></details>'
      + '<div class="builder' + (POOL_OPEN ? '' : ' pool-closed') + '"><div class="builder__main">'
      + settingsHtml()
      + S.rounds.map(roundHtml).join('')
      + '<div><button class="tm-btn tm-btn--ghost btn-sm" type="button" data-act="round-add"'
      + (S.rounds.length >= 8 ? ' disabled' : '') + '>+ Runde</button></div>'
      + finaleHtml()
      + '</div><aside class="builder__pool">'
      + (POOL_OPEN ? poolHtml()
        : '<button type="button" class="pool-tab" data-act="pool-toggle" title="Fragenpool öffnen" aria-expanded="false">📚 <span>Fragenpool</span></button>')
      + '</aside></div></div>';

    bind($('abend'));
    if (POOL_OPEN) {
      renderPool();
      $('pool-list').scrollTop = poolScroll;
    }
    showState();
  }

  function settingsHtml() {
    return '<section class="ed-card ed-stack"><h3>Abend</h3>'
      + field('Titel', '<input class="tm-input" data-s="title" maxlength="120" value="' + esc(S.title) + '">')
      + field('Notizen für den Quizmaster', '<textarea class="tm-textarea" data-s="notes" rows="2" maxlength="4000" '
        + 'placeholder="Begrüßung, Regeln, Ansagen, Dank an den Wirt …">' + esc(S.notes || '') + '</textarea>')
      + '<label class="tm-check"><input type="checkbox" data-s="jokerEnabled"' + (S.jokerEnabled ? ' checked' : '')
      + '><span>Joker: Jedes Team darf eine Runde verdoppeln — vor der Runde ansagen</span></label>'
      + '</section>';
  }

  function roundHtml(r, i) {
    var n = r.questions.length;
    return '<section class="round" data-round="' + i + '">'
      + '<div class="round__head"><span class="round__no">R' + (i + 1) + '</span>'
      + '<input class="tm-input round__title" data-r="title" maxlength="80" value="' + esc(r.title) + '" aria-label="Titel von Runde ' + (i + 1) + '">'
      + '<select class="tm-select sm" data-r="kind" aria-label="Art der Runde">' + options(KIND, r.kind) + '</select>'
      + '<select class="tm-select sm" data-r="multiplier" title="Punkte-Multiplikator" aria-label="Multiplikator">' + options(MULT, r.multiplier) + '</select>'
      + '<span class="round__pts">' + roundPoints(r) + ' P</span>'
      + '<span class="round__tools">'
      + '<button type="button" class="icon-btn" data-act="round-up"' + (i === 0 ? ' disabled' : '') + ' aria-label="Runde nach oben">↑</button>'
      + '<button type="button" class="icon-btn" data-act="round-down"' + (i === S.rounds.length - 1 ? ' disabled' : '') + ' aria-label="Runde nach unten">↓</button>'
      + '<button type="button" class="icon-btn" data-act="round-del" aria-label="Runde entfernen">✕</button></span></div>'
      + (r.kind === 'HANDOUT'
        ? '<p class="round__note">📄 Die Fragen kommen aufs Handout. Es liegt ab Einlass am Tisch und wird zur Pause eingesammelt — am Beamer läuft nur die Auflösung.</p>'
        : '')
      + (n ? '<ol class="rq-list">' + r.questions.map(function (rq, j) { return rqHtml(rq, j, r); }).join('') + '</ol>'
           : '<p class="round__empty">Noch keine Fragen — im Pool auf <b>R' + (i + 1) + '</b> tippen.</p>')
      + '<div class="round__foot' + (n >= TARGET ? ' ok' : '') + '">' + n + ' von ' + TARGET + ' Fragen</div>'
      + '</section>';
  }

  function rqHtml(rq, j, r) {
    var q = BY_ID[rq.questionId];
    var tools = '<span class="rq__tools">'
      + '<button type="button" class="icon-btn" data-act="rq-up"' + (j === 0 ? ' disabled' : '') + ' aria-label="Nach oben">↑</button>'
      + '<button type="button" class="icon-btn" data-act="rq-down"' + (j === r.questions.length - 1 ? ' disabled' : '') + ' aria-label="Nach unten">↓</button>'
      + '<button type="button" class="icon-btn" data-act="rq-del" aria-label="Aus der Runde nehmen">✕</button></span>';
    if (!q) return '<li class="rq rq--missing" data-j="' + j + '"><span class="rq__no">' + (j + 1) + '.</span><span class="rq__prompt">Diese Frage gibt es nicht mehr</span>' + tools + '</li>';
    var open = !!OPEN[q.id];
    return '<li class="rq' + (open ? ' is-open' : '') + '" data-j="' + j + '"><span class="rq__no">' + (j + 1) + '.</span>'
      + '<span class="rq__icon" title="' + esc(LABEL[q.type] || q.type) + '">' + (ICON[q.type] || '❓') + '</span>'
      + '<button type="button" class="rq__prompt" data-open="' + esc(q.id) + '" aria-expanded="' + open + '" title="Antippen: ganze Frage anzeigen">'
      + esc(q.prompt) + '</button>'
      + (q.status === 'DRAFT' ? '<span class="badge badge--wait">Entwurf</span>' : '')
      + (needsMedia(q) ? '<span class="badge badge--wait">ohne Datei</span>' : '')
      + '<button type="button" class="icon-btn clue-btn' + (rq.masterClue ? ' is-on' : '') + '" data-act="rq-clue" aria-pressed="'
      + (rq.masterClue ? 'true' : 'false') + '" title="Die Antwort ist ein Hinweis auf die Masterfrage">👑</button>'
      + '<input class="tm-input rq__pts" type="number" min="1" max="100" data-act="rq-pts" placeholder="' + esc(q.points) + '" value="'
      + (rq.pointsOverride != null ? esc(rq.pointsOverride) : '') + '" title="Punkte — leer lassen für den Standard der Frage" aria-label="Punkte">'
      + '<span class="rq__eff">' + eff(rq, r) + ' P</span>' + tools
      + (rq.masterClue ? '<input class="tm-input rq__clue" data-act="rq-note" maxlength="200" value="' + esc(rq.clueNote || '')
        + '" placeholder="👑 Wofür steht dieser Hinweis? (nur für euch, z. B. „Hauptstadt“)" aria-label="Notiz zum Masterhinweis">' : '')
      + (open ? qDetail(q) : '')
      + '</li>';
  }

  function finaleHtml() {
    var used = usedMap();
    var m = S.finale.masterId ? BY_ID[S.finale.masterId] : null;
    var masters = POOL.filter(function (q) { return q.type === 'MASTER'; });
    var ests = POOL.filter(function (q) { return q.type === 'ESTIMATE' && (!used[q.id] || q.id === S.finale.tiebreakId); });
    return '<section class="round round--finale"><div class="round__head"><span class="round__no">👑</span>'
      + '<h3 class="round__h">Finale: Die Masterfrage</h3></div>'
      + field('Masterfrage', '<select class="tm-select" data-fin="masterId"><option value="">— auswählen —</option>'
        + options(masters.map(function (q) { return [q.id, short(q.answer, 30) + ' — ' + short(q.prompt, 50)]; }), S.finale.masterId) + '</select>',
        masters.length ? '' : 'Noch keine Masterfrage im Pool — <a href="#fragen/neu">neue Frage anlegen</a> und Typ „Masterfrage“ wählen.')
      + tafelHtml()
      + (m ? masterPreview(m) : '')
      + field('Stechfrage bei Gleichstand', '<select class="tm-select" data-fin="tiebreakId"><option value="">— auswählen —</option>'
        + options(ests.map(function (q) { return [q.id, short(q.prompt, 70)]; }), S.finale.tiebreakId) + '</select>',
        'Eine Schätzfrage, die in keiner Runde steckt — die nächste Zahl gewinnt.')
      + '</section>';
  }

  function tafelHtml() {
    var clues = masterClues();
    return '<div class="master-prev"><p><b>👑 Masterfrage-Tafel</b> — diese Antworten sagt der Quizmaster über den Abend '
      + 'als Hinweise an, am Beamer sammeln sie sich auf der Tafel:</p>'
      + (clues.length
        ? '<ol class="ladder">' + clues.map(function (c) {
            return '<li><span class="ladder__pts">R' + c.round + '</span><span><b>' + esc(c.q.answer) + '</b>'
              + (c.note ? ' <span class="tm-hint">— ' + esc(c.note) + '</span>' : '') + '</span></li>';
          }).join('') + '</ol>'
        : '<p class="tm-hint">Noch keine. Tipp in einer Runde bei einer Frage auf 👑, wenn ihre Antwort ein Hinweis auf die Masterfrage ist.</p>')
      + '</div>';
  }

  function masterPreview(m) {
    var p = m.payload || {}, hints = p.hints || [], lad = p.ladder || [];
    return '<div class="master-prev"><p><b>Gesucht:</b> ' + esc(m.answer) + '</p>'
      + (p.clueMode === 'COUNTDOWN' && hints.length
        ? '<ol class="ladder">' + hints.map(function (h, i) {
            return '<li><span class="ladder__pts">' + esc(lad[i] || '') + ' P</span><span>' + esc(h) + '</span></li>';
          }).join('') + '</ol>'
        : '<p class="tm-hint">Diese Masterfrage hat keinen Countdown. <a href="#fragen/' + esc(m.id) + '">Hinweise ergänzen</a></p>')
      + (p.clueExplanation ? '<p class="tm-hint"><b>Auflösung:</b> ' + esc(p.clueExplanation) + '</p>' : '')
      + '<p><a href="#fragen/' + esc(m.id) + '">Masterfrage bearbeiten</a></p></div>';
  }

  // ---- pool --------------------------------------------------------------------------
  function poolHtml() {
    var types = [['', 'Alle Typen']].concat(Object.keys(LABEL).filter(function (t) { return t !== 'MASTER'; })
      .map(function (t) { return [t, ICON[t] + ' ' + LABEL[t]]; }));
    return '<div class="pool"><div class="pool__head"><h3 class="pool__h">Fragenpool — noch nicht verwendet</h3>'
      + '<button type="button" class="icon-btn" data-act="pool-toggle" title="Fragenpool einklappen" aria-label="Fragenpool einklappen" aria-expanded="true">⟩</button></div>'
      + '<input class="tm-input" id="pool-q" type="search" placeholder="Suchen …" value="' + esc(PF.q) + '" aria-label="Fragenpool durchsuchen">'
      + '<select class="tm-select" id="pool-type" aria-label="Fragetyp">' + options(types, PF.type) + '</select>'
      + '<div id="pool-list" class="pool__list"></div>'
      + '<a class="tm-btn tm-btn--ghost btn-sm" href="#fragen/neu">+ Neue Frage</a></div>';
  }

  function renderPool() {
    var used = usedMap(), q = PF.q.toLowerCase();
    var rows = POOL.filter(function (x) {
      if (x.type === 'MASTER' || used[x.id]) return false;
      if (PF.type && x.type !== PF.type) return false;
      if (q && [x.prompt, x.answer, x.category, (x.tags || []).join(' ')].join(' ').toLowerCase().indexOf(q) < 0) return false;
      return true;
    });
    var list = $('pool-list');
    if (!list) return; // pool folded away
    var top = list.scrollTop;
    list.innerHTML = rows.length ? rows.map(poolItem).join('')
      : '<p class="round__empty">' + (POOL.length ? 'Keine freie Frage passt.' : 'Der Pool ist noch leer.') + '</p>';
    list.scrollTop = top;
  }

  /** The whole question, unfolded under its row: answer, details, story, source. */
  function qDetail(q) {
    var p = q.payload || {}, h = '<div class="qd">';
    h += '<p class="qd__answer">→ ' + esc(q.answer) + '</p>';
    if (q.type === 'MULTIPLE_CHOICE' && p.options) {
      h += '<ol class="qd__opts" type="A">' + p.options.map(function (o, i) {
        return '<li' + (i === p.correctIndex ? ' class="ok"' : '') + '>' + esc(o) + '</li>';
      }).join('') + '</ol>';
    }
    if (q.type === 'OPEN_TEXT' && p.acceptedAnswers && p.acceptedAnswers.length) h += '<p class="qd__fc">Zählt auch: ' + esc(p.acceptedAnswers.join(', ')) + '</p>';
    if (q.type === 'ESTIMATE') {
      h += '<p class="qd__fc">📏 ' + esc(p.value) + ' ' + esc(p.unit || '')
        + (p.scoring === 'TOLERANCE' ? ' · gilt innerhalb ± ' + esc(p.tolerancePercent) + ' %' : ' · die nächste Schätzung gewinnt') + '</p>';
    }
    if (q.type === 'MAP' && p.lat != null) h += '<p class="qd__fc">🗺️ ' + esc(p.lat) + ', ' + esc(p.lng) + ' · Radius ' + esc(p.radiusKm) + ' km</p>';
    if (q.type === 'AUDIO' || q.type === 'VIDEO') {
      h += '<p class="qd__fc">' + (p.youtubeUrl || p.mediaUrl ? '🎵 Musik ist hinterlegt' : p.youtubeSearch ? '🎵 noch ohne Link — Vorschlag: ' + esc(p.youtubeSearch) : '🎵 noch ohne Musik') + '</p>';
    }
    if (q.type === 'IMAGE') h += p.imageUrl ? '<img class="qd__img" src="' + esc(p.imageUrl) + '" alt="">' : '<p class="qd__fc">🖼️ noch ohne Bild' + (p.imageSearch ? ' — Idee: ' + esc(p.imageSearch) : '') + '</p>';
    if (q.background) h += '<p class="qd__bg">🎤 ' + esc(q.background) + '</p>';
    if (q.factCheck) h += '<p class="qd__fc">🔎 ' + esc(q.factCheck) + '</p>';
    h += '<a class="qd__edit" href="#fragen/' + esc(q.id) + '">✎ Bearbeiten</a></div>';
    return h;
  }

  function poolItem(q) {
    var open = !!OPEN[q.id];
    return '<div class="pq' + (open ? ' is-open' : '') + '"><div class="pq__top"><span title="' + esc(LABEL[q.type]) + '">' + (ICON[q.type] || '❓') + '</span>'
      + '<button type="button" class="pq__prompt" data-open="' + esc(q.id) + '" aria-expanded="' + open + '" title="Antippen: ganze Frage anzeigen">'
      + esc(q.prompt) + '</button></div>'
      + (open ? qDetail(q) : '')
      + '<div class="pq__meta"><span class="q-stars">' + stars(q.difficulty) + '</span><span>· ' + esc(q.points) + ' P</span>'
      + (q.category ? '<span>· ' + esc(q.category) + '</span>' : '')
      + (q.status === 'DRAFT' ? '<span class="badge badge--wait">Entwurf</span>' : '') + '</div>'
      + '<div class="pq__add">' + S.rounds.map(function (r, i) {
        return '<button type="button" class="chip-btn" data-add="' + esc(q.id) + '" data-to="' + i + '" title="In Runde '
          + (i + 1) + ' „' + esc(r.title) + '“">R' + (i + 1) + '</button>';
      }).join('') + '</div></div>';
  }

  // ---- events ------------------------------------------------------------------------
  function roundOf(el) { return Number(el.closest('[data-round]').getAttribute('data-round')); }

  function bind(root) {
    root.addEventListener('click', function (ev) {
      var o = ev.target.closest('[data-open]');
      if (o) {
        var id = o.getAttribute('data-open');
        if (OPEN[id]) delete OPEN[id]; else OPEN[id] = true;
        if (o.closest('#pool-list')) renderPool(); else render();
        return;
      }
      var b = ev.target.closest('button[data-act], button[data-add]');
      if (!b || b.disabled) return;
      if (b.hasAttribute('data-add')) {
        var to = Number(b.getAttribute('data-to')), qid = b.getAttribute('data-add');
        if (!S.rounds[to] || usedMap()[qid]) return;
        S.rounds[to].questions.push({ questionId: qid, pointsOverride: null });
        changed(true);
        return;
      }
      var act = b.getAttribute('data-act');
      if (act === 'pool-toggle') {
        POOL_OPEN = !POOL_OPEN;
        try { localStorage.setItem('wq-pool-open', POOL_OPEN ? '1' : '0'); } catch (e) {}
        render();
        return;
      }
      var sec = b.closest('[data-round]'), i = sec ? Number(sec.getAttribute('data-round')) : -1;
      var li = b.closest('[data-j]'), j = li ? Number(li.getAttribute('data-j')) : -1;
      var r = S.rounds[i];
      switch (act) {
        case 'round-add':
          S.rounds.push({ id: '', title: 'Runde ' + (S.rounds.length + 1), kind: 'NORMAL', multiplier: 1, questions: [] });
          break;
        case 'round-up': swap(S.rounds, i, i - 1); break;
        case 'round-down': swap(S.rounds, i, i + 1); break;
        case 'round-del':
          if (r.questions.length && !confirm('Runde „' + r.title + '“ mit ' + r.questions.length + ' Fragen entfernen?\n\nDie Fragen bleiben im Pool.')) return;
          S.rounds.splice(i, 1);
          break;
        case 'rq-up': swap(r.questions, j, j - 1); break;
        case 'rq-down': swap(r.questions, j, j + 1); break;
        case 'rq-del': r.questions.splice(j, 1); break;
        case 'rq-clue': r.questions[j].masterClue = !r.questions[j].masterClue; break;
        default: return;
      }
      changed(true);
    });

    root.addEventListener('input', function (ev) {
      var el = ev.target;
      if (el.hasAttribute('data-s') && el.type !== 'checkbox') { S[el.getAttribute('data-s')] = el.value; changed(false); return; }
      if (el.getAttribute('data-r') === 'title') { S.rounds[roundOf(el)].title = el.value; changed(false); return; }
      if (el.getAttribute('data-act') === 'rq-note') {
        S.rounds[roundOf(el)].questions[Number(el.closest('[data-j]').getAttribute('data-j'))].clueNote = el.value;
        changed(false);
        return;
      }
      if (el.id === 'pool-q') { PF.q = el.value; renderPool(); }
    });

    root.addEventListener('change', function (ev) {
      var el = ev.target, k = el.getAttribute('data-r');
      if (el.getAttribute('data-s') === 'jokerEnabled') { S.jokerEnabled = el.checked; changed(false); return; }
      if (k === 'kind' || k === 'multiplier') {
        S.rounds[roundOf(el)][k] = k === 'multiplier' ? Number(el.value) : el.value;
        changed(true);
        return;
      }
      if (el.getAttribute('data-act') === 'rq-pts') {
        var v = parseInt(el.value, 10);
        S.rounds[roundOf(el)].questions[Number(el.closest('[data-j]').getAttribute('data-j'))].pointsOverride = v >= 1 && v <= 100 ? v : null;
        changed(true);
        return;
      }
      if (el.hasAttribute('data-fin')) { S.finale[el.getAttribute('data-fin')] = el.value || null; changed(true); return; }
      if (el.id === 'pool-type') { PF.type = el.value; renderPool(); }
    });
  }

  // ---- autosave ----------------------------------------------------------------------
  function changed(rerender) {
    if (rerender) render();
    clearTimeout(saveTimer);
    pending = true;
    setState('Ungespeicherte Änderung …');
    saveTimer = setTimeout(doSave, 700);
  }

  function doSave() {
    if (saving) { again = true; return; }
    pending = false;
    saving = true;
    setState('Speichert …');
    W.api('sessions/save.php', { method: 'POST', body: { session: S } }).then(function (d) {
      saving = false;
      S.rev = d.session.rev;
      (d.session.rounds || []).forEach(function (r, i) { if (S.rounds[i] && !S.rounds[i].id) S.rounds[i].id = r.id; });
      if (again) { again = false; doSave(); return; }
      setState('Gespeichert ✓');
    }).catch(function (e) {
      saving = false;
      again = false;
      if (e.status === 409) {
        W.toast('Der Abend wurde inzwischen woanders geändert — ich lade den aktuellen Stand.', true);
        W.views.abend();
        return;
      }
      setState('Nicht gespeichert: ' + W.errText(e), true);
    });
  }

  function setState(t, err) { stateText = t; stateErr = !!err; showState(); }
  function showState() {
    var el = $('save-state');
    if (el) { el.textContent = stateText; el.className = 'save-state' + (stateErr ? ' err' : ''); }
  }
})();
