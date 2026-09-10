/* Wirtshausquiz — backoffice: KI-Fragengenerator (P6).
   Talks to api/ai/generate.php (NDJSON stream) and api/ai/import.php.
   Uses the helpers admin.js puts on window.WQ. */
(function () {
  'use strict';

  var ICON = { MULTIPLE_CHOICE: '🔘', OPEN_TEXT: '✍️', ESTIMATE: '📏', IMAGE: '🖼️', AUDIO: '🎵', VIDEO: '🎬', MAP: '🗺️', MASTER: '👑' };
  var LABEL = { MULTIPLE_CHOICE: 'Multiple Choice', OPEN_TEXT: 'Offene Frage', ESTIMATE: 'Schätzfrage', IMAGE: 'Bilderfrage',
                AUDIO: 'Musikfrage', VIDEO: 'Videofrage', MAP: 'Stumme Karte', MASTER: 'Masterfrage' };
  var AI_TYPES = [['MULTIPLE_CHOICE', '🔘 Multiple Choice'], ['OPEN_TEXT', '✍️ Offen'], ['ESTIMATE', '📏 Schätzen'],
                  ['MAP', '🗺️ Stumme Karte'], ['AUDIO', '🎵 Musik']];
  var STORE = 'wq-ki-last';

  var W = window.WQ;
  var F = { mode: 'round', topic: '', roundIndex: '', count: 8, difficulty: 2,
            types: ['MULTIPLE_CHOICE', 'OPEN_TEXT', 'ESTIMATE', 'MAP', 'AUDIO'], masterClue: true, notes: '', idea: '' };
  var CTX = null;    // {session, byId}
  var RESULT = null; // last generation, kept until imported or discarded
  var BUSY = false;

  try { RESULT = JSON.parse(localStorage.getItem(STORE) || 'null'); } catch (e) { RESULT = null; }
  function persist() { try { if (RESULT) localStorage.setItem(STORE, JSON.stringify(RESULT)); else localStorage.removeItem(STORE); } catch (e) {} }

  function esc(s) { return W.esc(s); }
  function $(id) { return document.getElementById(id); }
  function stars(d) { d = Math.min(Math.max(d || 2, 1), 3); return '★★★'.slice(0, d) + '☆☆☆'.slice(0, 3 - d); }
  function mmss(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60); }
  function field(label, control, hint) {
    return '<label class="tm-field"><span class="tm-label">' + label + '</span>' + control
      + (hint ? '<span class="tm-hint">' + hint + '</span>' : '') + '</label>';
  }
  function opts(list, cur) {
    return list.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(cur) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
  }
  function master() {
    var s = CTX.session;
    return s.finale && s.finale.masterId ? CTX.byId[s.finale.masterId] : null;
  }
  /** Answer of the 👑 clue already sitting in round ri, or null. */
  function roundClue(ri) {
    if (ri === '' || ri == null) return null;
    var r = CTX.session.rounds[Number(ri)];
    if (!r) return null;
    for (var i = 0; i < r.questions.length; i++) {
      var q = CTX.byId[r.questions[i].questionId];
      if (r.questions[i].masterClue && q) return q.answer;
    }
    return null;
  }
  function allClues() {
    var out = [];
    CTX.session.rounds.forEach(function (r, i) { var a = roundClue(i); if (a) out.push(a); });
    return out;
  }

  function roundOptions(cur, poolLabel) {
    return '<option value="">' + poolLabel + '</option>' + CTX.session.rounds.map(function (r, i) {
      return '<option value="' + i + '"' + (String(cur) === String(i) ? ' selected' : '') + '>R' + (i + 1) + ' „' + esc(r.title) + '“'
        + (r.kind === 'HANDOUT' ? ' (Handout)' : '') + ' · ' + r.questions.length + (r.questions.length === 1 ? ' Frage' : ' Fragen') + '</option>';
    }).join('');
  }

  // ---- entry ---------------------------------------------------------------------
  W.views.ki = function () {
    W.loading();
    Promise.all([W.api('sessions/get.php'), W.api('questions/list.php')]).then(function (r) {
      var byId = {};
      (r[1].questions || []).forEach(function (q) { byId[q.id] = q; });
      CTX = { session: r[0].session, byId: byId };
      CTX.session.finale = CTX.session.finale || {};
      render();
    }).catch(W.failView);
  };

  // ---- form ------------------------------------------------------------------------
  function render() {
    W.app.innerHTML = '<div id="ki">'
      + '<div class="page-head"><div><h2>✨ KI-Generator</h2><p>Claude Opus 5 schreibt Fragen mit Hintergrund für den Quizmaster. '
      + 'Alles landet als <b>Entwurf</b> — ihr prüft, ändert und gebt frei.</p></div></div>'
      + '<div class="ki-modes">'
      + modeBtn('round', '🎯 Runde erzeugen', 'Fragen zu einem Thema, direkt in eine Runde des Abends')
      + modeBtn('master', '👑 Masterfrage & roter Faden', 'Die Masterfrage fürs Finale plus pro Runde ein Hinweis')
      + '</div>'
      + '<section class="ed-card ed-stack" id="ki-form">' + (F.mode === 'round' ? roundForm() : masterForm()) + '</section>'
      + '<div id="ki-run"></div><div id="ki-result"></div></div>';
    bindForm($('ki'));
    if (RESULT) renderResult();
  }

  function modeBtn(mode, title, sub) {
    return '<button type="button" class="ki-mode' + (F.mode === mode ? ' is-on' : '') + '" data-mode="' + mode + '"><b>' + title + '</b><span>' + sub + '</span></button>';
  }

  function roundForm() {
    var rounds = CTX.session.rounds, m = master();
    var r = F.roundIndex !== '' ? rounds[Number(F.roundIndex)] : null, handout = r && r.kind === 'HANDOUT';
    return field('Thema *', '<input class="tm-input" data-f="topic" maxlength="200" value="' + esc(F.topic) + '" '
        + 'placeholder="z. B. „Die Donau“, „Die 80er“, „Essen & Trinken rund um die Welt“">')
      + '<div class="ed-grid">'
      + field('Für welche Runde?', '<select class="tm-select" data-f="roundIndex" data-rerender>' + roundOptions(F.roundIndex, 'Nur in den Fragenpool') + '</select>')
      + field('Anzahl Fragen', '<select class="tm-select" data-f="count">' + opts([[4, '4'], [6, '6'], [8, '8'], [10, '10']], F.count) + '</select>')
      + field('Schwierigkeit', '<select class="tm-select" data-f="difficulty">' + opts([[1, '★ leicht'], [2, '★★ mittel'], [3, '★★★ schwer']], F.difficulty) + '</select>')
      + '</div>'
      + (handout
        ? '<p class="tm-hint">📄 Handout-Runde: Claude schlägt Bildfragen vor und beschreibt, welches Bild ihr dafür sucht.</p>'
        : '<div><span class="tm-label">Fragetypen</span><div class="chip-row">' + AI_TYPES.map(function (t) {
            return '<button type="button" class="chip-btn' + (F.types.indexOf(t[0]) >= 0 ? ' is-on' : '') + '" data-type="' + t[0] + '" aria-pressed="'
              + (F.types.indexOf(t[0]) >= 0) + '">' + t[1] + '</button>';
          }).join('') + '</div></div>')
      + (m
        ? '<label class="tm-check"><input type="checkbox" data-f="masterClue"' + (F.masterClue ? ' checked' : '') + '><span>👑 Einen Masterhinweis auf „'
          + esc(m.answer) + '“ einbauen</span></label>'
          + (roundClue(F.roundIndex) ? '<p class="tm-hint">Diese Runde hat schon ihren Masterhinweis („' + esc(roundClue(F.roundIndex))
            + '“) — ein zweiter ist nicht nötig.</p>' : '')
        : '<p class="tm-hint">Im Finale steht noch keine Masterfrage, deshalb gibt es auch keinen Masterhinweis. Den roten Faden baut „Masterfrage & roter Faden“.</p>')
      + field('Wünsche (optional)', '<input class="tm-input" data-f="notes" maxlength="500" value="' + esc(F.notes) + '" '
        + 'placeholder="z. B. „mit Bezug zum Tullnerfeld“, „keine Sportfragen“, „eine Frage über Mohn“">')
      + '<div><button class="tm-btn tm-btn--primary" type="button" id="ki-go">✨ Runde erzeugen</button></div>';
  }

  function masterForm() {
    var m = master(), n = CTX.session.rounds.length;
    var clues = allClues();
    return (m ? '<div class="info-note">Im Finale steht schon „<b>' + esc(m.answer) + '</b>“. Eine neue Masterfrage ersetzt sie nur, '
        + 'wenn du beim Übernehmen „ins Finale setzen“ anhakst.'
        + (clues.length ? ' <strong>Achtung:</strong> Die Runden haben schon ' + clues.length + ' 👑 Hinweise dazu (' + esc(clues.join(', '))
          + ') — die passen zu einer neuen Masterfrage nicht mehr. Im Abend bei diesen Fragen das 👑 wegnehmen.' : '')
        + '</div>' : '')
      + field('Idee oder Richtung (optional)', '<input class="tm-input" data-f="idea" maxlength="300" value="' + esc(F.idea) + '" '
        + 'placeholder="leer = Claude überrascht euch · z. B. „ein Land in Afrika“, „eine Erfindung“, „jemand aus Niederösterreich“">')
      + '<div class="ed-grid">'
      + field('Schwierigkeit der Hinweisfragen', '<select class="tm-select" data-f="difficulty">' + opts([[1, '★ leicht'], [2, '★★ mittel'], [3, '★★★ schwer']], F.difficulty) + '</select>')
      + '</div>'
      + '<p class="tm-hint">Claude erfindet die Masterfrage mit 5 Countdown-Hinweisen und schreibt für jede der ' + n
      + ' Runden eine Frage, deren Antwort ein 👑 Hinweis ist — passend zum Rundentitel. Gib den Runden unter „Abend“ vorher ihre Themen, dann passt es besser.</p>'
      + '<div><button class="tm-btn tm-btn--primary" type="button" id="ki-go">✨ Masterfrage & roten Faden erzeugen</button></div>';
  }

  function bindForm(root) {
    root.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-mode], [data-type], #ki-go');
      if (!b) return;
      if (b.id === 'ki-go') { generate(); return; }
      if (b.hasAttribute('data-mode')) { F.mode = b.getAttribute('data-mode'); render(); return; }
      var t = b.getAttribute('data-type'), i = F.types.indexOf(t);
      if (i >= 0) { if (F.types.length > 1) F.types.splice(i, 1); } else F.types.push(t);
      $('ki-form').innerHTML = roundForm();
    });
    root.addEventListener('input', function (ev) {
      var el = ev.target, k = el.getAttribute('data-f');
      if (!k || !el.closest('#ki-form')) return;
      F[k] = el.type === 'checkbox' ? el.checked : (k === 'count' || k === 'difficulty' ? Number(el.value) : el.value);
      // One 👑 clue per round: preselect only where the round has none yet.
      if (k === 'roundIndex') F.masterClue = !roundClue(F.roundIndex);
      if (el.hasAttribute('data-rerender')) $('ki-form').innerHTML = roundForm();
    });
    root.addEventListener('change', function (ev) {
      var el = ev.target;
      if (el.type === 'checkbox' && el.getAttribute('data-f')) F[el.getAttribute('data-f')] = el.checked;
    });
  }

  // ---- generate (NDJSON stream) ----------------------------------------------------------
  function generate() {
    if (BUSY) return;
    if (F.mode === 'round' && F.topic.trim().length < 2) { W.toast('Bitte ein Thema eingeben.', true); return; }
    if (RESULT && !confirm('Die letzten Vorschläge sind noch nicht übernommen. Verwerfen und neu erzeugen?')) return;
    BUSY = true;
    RESULT = null;
    persist();
    $('ki-result').innerHTML = '';
    var btn = $('ki-go');
    if (btn) btn.disabled = true;
    var t0 = Date.now(), last = { phase: 'thinking', chars: 0 };
    var timer = setInterval(paint, 1000);
    paint();

    function paint() {
      var run = $('ki-run');
      if (!run) return;
      var s = Math.round((Date.now() - t0) / 1000);
      run.innerHTML = '<div class="ki-progress"><div class="spin"></div><div><b>'
        + (last.phase === 'collect' ? 'Die Verbindung ist abgerissen — Claude arbeitet am Server weiter, ich hole das Ergebnis ab …'
          : last.phase === 'writing' ? 'Claude schreibt die Fragen …' : 'Claude denkt nach …') + '</b><br>'
        + '<span class="tm-hint">' + s + ' s' + (last.chars ? ' · ' + last.chars + ' Zeichen' : '')
        + ' · eine ganze Runde dauert meist 1 bis 3 Minuten</span></div></div>';
    }
    function finish(errMsg) {
      clearInterval(timer);
      BUSY = false;
      var b = $('ki-go');
      if (b) b.disabled = false;
      var run = $('ki-run');
      if (run) run.innerHTML = errMsg ? '<div class="err-box">' + esc(errMsg) + '</div>' : '';
      if (!errMsg) renderResult();
    }

    var jobId = newJobId();
    var body = F.mode === 'round'
      ? { mode: 'round', topic: F.topic, roundIndex: F.roundIndex === '' ? null : Number(F.roundIndex), count: F.count,
          difficulty: F.difficulty, types: F.types, masterClue: F.masterClue, notes: F.notes }
      : { mode: 'master', idea: F.idea, difficulty: F.difficulty };
    body.jobId = jobId;

    // If the proxy cuts the connection, the server keeps working and parks the
    // result — then collect it instead of reporting an error.
    function collect() {
      last = { phase: 'collect', chars: 0 };
      var until = Date.now() + 6 * 60 * 1000;
      function poll() {
        return new Promise(function (res) { setTimeout(res, 5000); })
          .then(function () { return W.api('ai/job.php?id=' + jobId); })
          .then(function (d) {
            if (d.pending) {
              if (Date.now() > until) throw new Error('Claude braucht ungewöhnlich lange. Schau in ein paar Minuten nochmal vorbei oder erzeuge neu.');
              return poll();
            }
            if (d.event.t === 'error') throw new Error(d.event.message);
            RESULT = prepare(d.event);
            persist();
          });
      }
      return poll();
    }

    fetch('../api/ai/generate.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/x-ndjson' },
      body: JSON.stringify(body)
    }).then(function (r) {
      if ((r.headers.get('Content-Type') || '').indexOf('ndjson') < 0) {
        if (r.status === 502 || r.status === 504) return collect(); // proxy gave up, PHP didn't
        return r.json().catch(function () { return {}; }).then(function (j) {
          var e = new Error(j.error || ('HTTP ' + r.status)); e.status = r.status; e.final = true; throw e;
        });
      }
      var reader = r.body.getReader(), dec = new TextDecoder(), buf = '', got = false;
      function pump() {
        return reader.read().then(function (x) {
          if (x.done) {
            if (!got) return collect();
            return;
          }
          buf += dec.decode(x.value, { stream: true });
          var i;
          while ((i = buf.indexOf('\n')) >= 0) {
            var line = buf.slice(0, i).trim();
            buf = buf.slice(i + 1);
            if (!line) continue;
            var ev = JSON.parse(line);
            if (ev.t === 'beat') last = ev;
            else if (ev.t === 'error') { var e = new Error(ev.message); e.final = true; throw e; }
            else if (ev.t === 'result') { got = true; RESULT = prepare(ev); persist(); }
          }
          return pump();
        }, function () { return got ? null : collect(); }); // connection dropped mid-way
      }
      return pump();
    }, function () {
      return collect(); // the request itself failed (network, proxy)
    }).then(function () { finish(null); }, function (e) { finish(W.errText(e)); });
  }

  function newJobId() {
    var a = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
  }

  function prepare(ev) {
    ev.items.forEach(function (it) { it.sel = it.valid; it.roundIndex = it.roundIndex == null ? '' : String(it.roundIndex); });
    if (ev.master) { ev.master.sel = ev.master.valid; ev.setFinale = !master(); }
    return ev;
  }

  // ---- result ----------------------------------------------------------------------------
  function renderResult() {
    var box = $('ki-result'), R = RESULT;
    if (!box || !R) return;
    var html = '<div class="ki-result">';
    if (R.mode === 'round') {
      html += '<div class="ki-head"><h3>„' + esc(R.roundTitle) + '“</h3>'
        + (R.roundIntro ? '<p class="ki-intro">🎤 ' + esc(R.roundIntro) + '</p>' : '') + '</div>';
    }
    if (R.master) html += masterCard(R.master);
    html += R.items.map(itemCard).join('');
    html += importBar();
    html += '<p class="tm-hint">≈ ' + String((R.usage.usd || 0).toFixed(2)).replace('.', ',') + ' $ für diese Anfrage ('
      + R.usage.inputTokens + ' + ' + R.usage.outputTokens + ' Tokens' + (R.fellBack ? ', beantwortet vom Ersatzmodell ' + esc(R.model) : '') + ')</p>';
    html += '</div>';
    box.innerHTML = html;
    bindResult(box);
  }

  function masterCard(m) {
    var q = m.input, p = q.payload || {}, hints = p.hints || [], lad = p.ladder || [];
    return '<article class="ki-q ki-q--master' + (m.valid ? '' : ' is-bad') + (m.sel ? '' : ' is-off') + '">'
      + '<label class="ki-q__pick"><input type="checkbox" data-act="pick-master"' + (m.sel ? ' checked' : '') + (m.valid ? '' : ' disabled') + ' aria-label="Masterfrage übernehmen"></label>'
      + '<div class="ki-q__body"><div class="ki-q__meta"><span class="badge badge--promo">👑 Masterfrage</span></div>'
      + '<p class="ki-q__prompt">' + esc(q.prompt) + '</p><p class="ki-q__answer">→ ' + esc(q.answer) + '</p>'
      + (hints.length ? '<ol class="ladder">' + hints.map(function (h, i) {
          return '<li><span class="ladder__pts">' + esc(lad[i] || '') + ' P</span><span>' + esc(h) + '</span></li>';
        }).join('') + '</ol>' : '')
      + (p.clueExplanation ? '<p class="ki-q__bg">🎤 <b>Auflösung:</b> ' + esc(p.clueExplanation) + '</p>' : '')
      + (q.background ? '<p class="ki-q__bg">' + esc(q.background) + '</p>' : '')
      + (m.valid ? '' : '<p class="err-box">' + esc(m.error) + '</p>')
      + '<label class="tm-check"><input type="checkbox" data-act="set-finale"' + (RESULT.setFinale ? ' checked' : '') + '><span>Als Masterfrage ins Finale setzen'
      + (master() ? ' (ersetzt „' + esc(master().answer) + '“)' : '') + '</span></label>'
      + '</div></article>';
  }

  function detail(q) {
    var p = q.payload || {};
    if (q.type === 'MULTIPLE_CHOICE' && p.options) {
      return '<ol class="ki-opts" type="A">' + p.options.map(function (o, i) {
        return '<li' + (i === p.correctIndex ? ' class="ok"' : '') + '>' + esc(o) + '</li>';
      }).join('') + '</ol>';
    }
    if (q.type === 'OPEN_TEXT' && p.acceptedAnswers && p.acceptedAnswers.length) return '<p class="ki-q__fc">Zählt auch: ' + esc(p.acceptedAnswers.join(', ')) + '</p>';
    if (q.type === 'ESTIMATE') return '<p class="ki-q__fc">📏 Richtwert: ' + esc(p.value) + ' ' + esc(p.unit || '') + '</p>';
    if (q.type === 'MAP' && p.lat != null) {
      return '<p class="ki-q__fc">🗺️ ' + Number(p.lat).toFixed(4) + ', ' + Number(p.lng).toFixed(4) + ' · Radius ' + esc(p.radiusKm) + ' km · '
        + '<a href="https://www.openstreetmap.org/?mlat=' + p.lat + '&mlon=' + p.lng + '#map=7/' + p.lat + '/' + p.lng + '" target="_blank" rel="noopener">auf der Karte ansehen</a></p>';
    }
    if (q.type === 'AUDIO' && p.youtubeSearch) {
      return '<p class="ki-q__fc">🎵 <a href="https://www.youtube.com/results?search_query=' + encodeURIComponent(p.youtubeSearch)
        + '" target="_blank" rel="noopener">Auf YouTube suchen: „' + esc(p.youtubeSearch) + '“</a>' + (p.startSeconds ? ' · ab ' + mmss(p.startSeconds) : '') + '</p>';
    }
    if (q.type === 'IMAGE' && p.imageSearch) return '<p class="ki-q__fc">🖼️ Bildidee: ' + esc(p.imageSearch) + '</p>';
    return '';
  }

  function itemCard(it, k) {
    var q = it.input;
    return '<article class="ki-q' + (it.valid ? '' : ' is-bad') + (it.sel ? '' : ' is-off') + '">'
      + '<label class="ki-q__pick"><input type="checkbox" data-act="pick" data-k="' + k + '"' + (it.sel ? ' checked' : '') + (it.valid ? '' : ' disabled')
      + ' aria-label="Frage ' + (k + 1) + ' übernehmen"></label>'
      + '<div class="ki-q__body"><div class="ki-q__meta"><span class="badge">' + (ICON[q.type] || '❓') + ' ' + esc(LABEL[q.type] || q.type) + '</span>'
      + '<span class="q-stars">' + stars(q.difficulty) + '</span>'
      + (it.masterClue ? '<span class="badge badge--promo">👑 Masterhinweis</span>' : '')
      + (q.category ? '<span>' + esc(q.category) + '</span>' : '') + '</div>'
      + '<p class="ki-q__prompt">' + esc(q.prompt) + '</p><p class="ki-q__answer">→ ' + esc(q.answer) + '</p>'
      + detail(q)
      + (q.background ? '<p class="ki-q__bg">🎤 ' + esc(q.background) + '</p>' : '')
      + (q.factCheck ? '<p class="ki-q__fc">🔎 ' + esc(q.factCheck) + '</p>' : '')
      + (it.masterClue && it.clueNote ? '<p class="ki-q__fc">👑 ' + esc(it.clueNote) + '</p>' : '')
      + (it.valid ? '' : '<p class="err-box">' + esc(it.error) + '</p>')
      + '<div class="ki-q__target">Übernehmen nach <select class="tm-select sm" data-act="target" data-k="' + k + '">'
      + roundOptions(it.roundIndex, 'nur in den Pool') + '</select></div>'
      + '</div></article>';
  }

  function importCount() {
    var R = RESULT;
    return R.items.filter(function (it) { return it.sel && it.valid; }).length + (R.master && R.master.sel && R.master.valid ? 1 : 0);
  }

  function importBar() {
    var n = importCount();
    return '<div class="ki-import"><button class="tm-btn tm-btn--primary" type="button" id="ki-import"' + (n ? '' : ' disabled') + '>'
      + n + (n === 1 ? ' Frage' : ' Fragen') + ' übernehmen</button>'
      + '<button class="tm-btn tm-btn--ghost" type="button" id="ki-again">Nochmal erzeugen</button>'
      + '<button class="tm-btn tm-btn--ghost btn-danger" type="button" id="ki-drop">Verwerfen</button></div>';
  }

  function bindResult(box) {
    box.addEventListener('change', function (ev) {
      var el = ev.target, act = el.getAttribute('data-act'), k = Number(el.getAttribute('data-k'));
      if (act === 'pick') RESULT.items[k].sel = el.checked;
      else if (act === 'target') RESULT.items[k].roundIndex = el.value;
      else if (act === 'pick-master') RESULT.master.sel = el.checked;
      else if (act === 'set-finale') RESULT.setFinale = el.checked;
      else return;
      persist();
      el.closest('.ki-q') && el.closest('.ki-q').classList.toggle('is-off', (act === 'pick' || act === 'pick-master') && !el.checked);
      var bar = box.querySelector('.ki-import');
      bar.outerHTML = importBar();
    });
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('#ki-import, #ki-again, #ki-drop');
      if (!b || b.disabled) return;
      if (b.id === 'ki-import') doImport(b);
      else if (b.id === 'ki-again') { RESULT = null; persist(); generate(); }
      else if (b.id === 'ki-drop' && confirm('Diese Vorschläge verwerfen?')) { RESULT = null; persist(); box.innerHTML = ''; }
    });
  }

  function doImport(btn) {
    var R = RESULT;
    btn.disabled = true;
    btn.textContent = 'Übernimmt …';
    W.api('ai/import.php', { method: 'POST', body: {
      items: R.items.filter(function (it) { return it.sel && it.valid; }).map(function (it) {
        return { input: it.input, roundIndex: it.roundIndex === '' ? null : Number(it.roundIndex), masterClue: it.masterClue, clueNote: it.clueNote };
      }),
      master: R.master && R.master.sel && R.master.valid ? R.master.input : null,
      setFinale: !!(R.master && R.master.sel && R.setFinale)
    } }).then(function (d) {
      RESULT = null;
      persist();
      $('ki-result').innerHTML = '<div class="info-note">✅ <strong>' + d.created + (d.created === 1 ? ' Frage' : ' Fragen') + ' übernommen</strong>'
        + (d.placed ? ', ' + d.placed + ' davon direkt im Abend' : '') + (d.finale ? ', die Masterfrage steht im Finale' : '')
        + (d.skipped ? ' — ' + d.skipped + ' passten nicht mehr in ihre Runde (max. 20)' : '')
        + '. Alles ist als Entwurf markiert: bitte durchlesen, Musik- und Bild-Links ergänzen und freigeben. '
        + '<a href="#abend">Zum Abend</a> · <a href="#fragen">Zu den Fragen</a></div>';
      W.toast('Übernommen.');
      W.api('sessions/get.php').then(function (s) { CTX.session = s.session; CTX.session.finale = CTX.session.finale || {}; });
    }).catch(function (e) {
      btn.disabled = false;
      btn.textContent = 'Nochmal versuchen';
      W.toast(W.errText(e), true);
    });
  }
})();
