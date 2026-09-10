/* Wirtshausquiz — backoffice: question pool + editor (P4).
   Port of question-editor.tsx / questions/page.tsx from tm_wirtshausquiz_backoffice.
   Uses the helpers admin.js puts on window.WQ. */
(function () {
  'use strict';

  var TYPES = {
    MULTIPLE_CHOICE: { label: 'Multiple Choice', icon: '🔘', hint: 'Eine Frage, mehrere Antwortmöglichkeiten, eine richtig.', points: 10 },
    OPEN_TEXT: { label: 'Offene Frage', icon: '✍️', hint: 'Teams schreiben die Antwort frei auf.', points: 10 },
    ESTIMATE: { label: 'Schätzfrage', icon: '📏', hint: 'Numerische Antwort — nächste Schätzung oder Toleranzbereich gewinnt.', points: 10 },
    IMAGE: { label: 'Bilderfrage', icon: '🖼️', hint: 'Bild zeigen — wer/was/wo ist das?', points: 10 },
    AUDIO: { label: 'Musikfrage', icon: '🎵', hint: 'Song anspielen — Titel und/oder Interpret erraten.', points: 10 },
    VIDEO: { label: 'Videofrage', icon: '🎬', hint: 'Kurzen Clip zeigen — Frage dazu beantworten.', points: 10 },
    MAP: { label: 'Kartenfrage', icon: '🗺️', hint: 'Ort auf der Karte markieren — Punkte nach Entfernung.', points: 15 },
    MASTER: { label: 'Masterfrage', icon: '👑', hint: 'Die Meta-Frage der Runde — die Hinweise stecken in den anderen Antworten.', points: 25 }
  };
  var ORDER = ['MULTIPLE_CHOICE', 'OPEN_TEXT', 'ESTIMATE', 'IMAGE', 'AUDIO', 'VIDEO', 'MAP', 'MASTER'];
  var HOME = [48.287, 15.935]; // Michelhausen
  var LEAFLET = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';

  var W = window.WQ;
  var POOL = [];
  var LOADED = false;
  var UPLOAD_MAX = 0;
  var FILTER = { q: '', type: '', status: '', source: '' };
  var Q = null;    // question in the editor
  var MAP = null;  // {map, circle, marker}

  function esc(s) { return W.esc(s); }
  function $(id) { return document.getElementById(id); }
  function num(v) {
    if (typeof v === 'number') return v;
    var n = parseFloat(String(v == null ? '' : v).replace(',', '.'));
    return isNaN(n) ? null : n;
  }
  function mb(b) { return String(Math.round(b / 1048576 * 10) / 10).replace('.', ',') + ' MB'; }
  function letter(i) { return String.fromCharCode(65 + i); }
  function stars(d) { d = Math.min(Math.max(d || 2, 1), 3); return '★★★'.slice(0, d) + '☆☆☆'.slice(0, 3 - d); }
  function youtubeId(url) {
    var m = String(url || '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/);
    return m ? m[1] : null;
  }
  function field(label, control, hint) {
    return '<label class="tm-field"><span class="tm-label">' + label + '</span>' + control
      + (hint ? '<span class="tm-hint">' + hint + '</span>' : '') + '</label>';
  }
  function select(key, options, current, attrs) {
    return '<select class="tm-select" data-p="' + key + '"' + (attrs || '') + '>' + options.map(function (o) {
      return '<option value="' + o[0] + '"' + (String(current) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }

  function defaultPayload(type) {
    switch (type) {
      case 'MULTIPLE_CHOICE': return { options: ['', '', '', ''], correctIndex: 0 };
      case 'OPEN_TEXT': return { acceptedAnswers: [] };
      case 'ESTIMATE': return { value: null, unit: '', scoring: 'CLOSEST' };
      case 'IMAGE': return { imageUrl: '' };
      case 'AUDIO': case 'VIDEO': return { mediaUrl: '', youtubeUrl: '' };
      case 'MAP': return { lat: null, lng: null, radiusKm: 50, scoring: 'RADIUS' };
      case 'MASTER': return { clueMode: 'ANSWERS_ARE_CLUES', clueExplanation: '' };
    }
    return {};
  }

  function load() {
    return W.api('questions/list.php').then(function (d) {
      POOL = d.questions || []; UPLOAD_MAX = d.uploadMax || 0; LOADED = true;
    });
  }

  // ---- routing entry (called by admin.js) ------------------------------------
  W.views.fragen = function (parts) {
    MAP = null;
    if (parts[1] === 'neu') return openEditor(null);
    if (parts[1]) return openEditor(parts[1]);
    W.loading();
    load().then(renderList).catch(W.failView);
  };

  // ---- list ------------------------------------------------------------------
  function renderList() {
    var typeOpts = ORDER.map(function (t) { return [t, TYPES[t].icon + ' ' + TYPES[t].label]; });
    W.app.innerHTML = ''
      + '<div class="page-head"><div><h2>Fragenpool</h2><p id="q-count"></p></div>'
      + '<div class="btn-row"><a class="tm-btn tm-btn--primary btn-sm" href="#fragen/neu">+ Neue Frage</a></div></div>'
      + (POOL.length ? '<div class="q-filters">'
        + '<input class="tm-input" id="qf-q" type="search" placeholder="Suchen in Frage, Antwort, Kategorie, Tags …" value="' + esc(FILTER.q) + '">'
        + filterSelect('type', [['', 'Alle Typen']].concat(typeOpts))
        + filterSelect('status', [['', 'Alle Status'], ['READY', 'Bereit'], ['DRAFT', 'Entwurf']])
        + filterSelect('source', [['', 'Alle Quellen'], ['MANUAL', 'Selbst angelegt'], ['AI', 'KI-generiert']])
        + '</div>' : '')
      + '<div id="q-list" class="q-list"></div>';

    if (POOL.length) {
      $('qf-q').addEventListener('input', function () { FILTER.q = this.value; renderRows(); });
      ['type', 'status', 'source'].forEach(function (k) {
        $('qf-' + k).addEventListener('change', function () { FILTER[k] = this.value; renderRows(); });
      });
    }
    renderRows();
  }

  function filterSelect(key, options) {
    return '<select class="tm-select" id="qf-' + key + '" aria-label="Filter">' + options.map(function (o) {
      return '<option value="' + o[0] + '"' + (FILTER[key] === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('') + '</select>';
  }

  function matches(q) {
    if (FILTER.type && q.type !== FILTER.type) return false;
    if (FILTER.status && q.status !== FILTER.status) return false;
    if (FILTER.source && q.source !== FILTER.source) return false;
    if (FILTER.q) {
      var hay = [q.prompt, q.answer, q.category, (q.tags || []).join(' ')].join(' ').toLowerCase();
      if (hay.indexOf(FILTER.q.toLowerCase()) < 0) return false;
    }
    return true;
  }

  function renderRows() {
    var rows = POOL.filter(matches);
    var drafts = POOL.filter(function (q) { return q.status === 'DRAFT'; }).length;
    var n = POOL.length;
    $('q-count').textContent = (rows.length === n ? n + (n === 1 ? ' Frage' : ' Fragen') : rows.length + ' von ' + n + ' Fragen')
      + (drafts ? ' · ' + drafts + (drafts === 1 ? ' Entwurf' : ' Entwürfe') : '');
    $('q-list').innerHTML = !n
      ? '<div class="empty">Noch keine Fragen im Pool.<br>Leg die erste an — der KI-Generator kommt als Nächstes dazu.</div>'
      : rows.length ? rows.map(rowHtml).join('') : '<div class="empty">Keine Frage passt zu diesem Filter.</div>';
  }

  function rowHtml(q) {
    var t = TYPES[q.type] || { icon: '❓', label: q.type };
    return '<a class="q-row" href="#fragen/' + esc(q.id) + '">'
      + '<span class="q-row__icon" title="' + esc(t.label) + '">' + t.icon + '</span>'
      + '<span class="q-row__main"><span class="q-row__prompt">' + esc(q.prompt) + '</span>'
      + '<span class="q-row__meta"><span>' + esc(t.label) + '</span>'
      + (q.category ? '<span>· ' + esc(q.category) + '</span>' : '')
      + '<span>· <span class="q-stars">' + stars(q.difficulty) + '</span></span>'
      + '<span>· ' + esc(q.points) + ' P.</span>'
      + (q.status === 'DRAFT' ? '<span class="badge badge--wait">Entwurf</span>' : '')
      + (q.source === 'AI' ? '<span class="badge badge--ai">✨ KI</span>' : '')
      + '</span></span>'
      + '<span class="q-row__date">' + esc(W.fmtDate(q.updatedAt)) + '</span></a>';
  }

  // ---- editor ----------------------------------------------------------------
  function openEditor(id) {
    if (!id) {
      Q = { type: 'MULTIPLE_CHOICE', prompt: '', answer: '', points: 10, difficulty: 2, category: '', tags: '',
            status: 'READY', payload: defaultPayload('MULTIPLE_CHOICE') };
      renderEditor();
      if (!LOADED) load().catch(function () {}); // upload limit + categories, in the background
      return;
    }
    var show = function () {
      var f = POOL.filter(function (q) { return q.id === id; })[0];
      if (!f) { W.app.innerHTML = '<div class="empty">Diese Frage gibt es nicht (mehr). <a href="#fragen">Zum Fragenpool</a></div>'; return; }
      Q = JSON.parse(JSON.stringify(f));
      Q.tags = (Q.tags || []).join(', ');
      Q.payload = Q.payload || {};
      renderEditor();
    };
    if (LOADED) return show();
    W.loading();
    load().then(show).catch(W.failView);
  }

  function renderEditor() {
    var isNew = !Q.id, t = TYPES[Q.type];
    var cats = {};
    POOL.forEach(function (q) { if (q.category) cats[q.category] = true; });

    var meta = isNew ? '' : 'Angelegt ' + esc(W.fmtDate(Q.createdAt)) + (Q.createdBy ? ' von ' + esc(Q.createdBy) : '')
      + (Q.updatedAt && Q.updatedAt !== Q.createdAt ? ' · zuletzt geändert ' + esc(W.fmtDate(Q.updatedAt)) : '')
      + (Q.source === 'AI' ? ' · ✨ KI-generiert' : '');

    W.app.innerHTML = ''
      + '<button class="back-link" type="button" id="ed-back">← Fragenpool</button>'
      + '<div class="page-head"><div><h2>' + (isNew ? 'Neue Frage' : 'Frage bearbeiten') + '</h2>'
      + (meta ? '<p class="ed-meta">' + meta + '</p>' : '') + '</div></div>'
      + '<div class="ed">'
      + '<section class="ed-card"><h3>Fragetyp</h3><div class="type-grid">' + ORDER.map(function (k) {
          return '<button type="button" class="type-btn' + (k === Q.type ? ' is-on' : '') + '" data-type="' + k + '"'
            + (isNew ? '' : ' disabled') + '>' + TYPES[k].icon + ' ' + esc(TYPES[k].label) + '</button>';
        }).join('') + '</div>'
      + '<p class="type-hint">' + esc(t.hint) + (isNew ? '' : ' Der Typ lässt sich nachträglich nicht ändern.') + '</p></section>'

      + '<section class="ed-card ed-stack">'
      + field('Frage *', '<textarea class="tm-textarea" data-f="prompt" rows="3" maxlength="2000" placeholder="Die Frage, wie sie der Quizmaster vorliest …">' + esc(Q.prompt) + '</textarea>')
      + field('Antwort / Lösung *', '<input class="tm-input" data-f="answer" maxlength="2000" placeholder="Die richtige Antwort" value="' + esc(Q.answer) + '">')
      + '<div class="ed-grid">'
      + field('Punkte', '<input class="tm-input" type="number" min="1" max="100" data-f="points" data-num value="' + esc(Q.points) + '">')
      + field('Schwierigkeit', '<select class="tm-select" data-f="difficulty" data-num>'
          + [[1, '★ leicht'], [2, '★★ mittel'], [3, '★★★ schwer']].map(function (o) {
            return '<option value="' + o[0] + '"' + (Number(Q.difficulty) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
          }).join('') + '</select>')
      + field('Kategorie', '<input class="tm-input" data-f="category" list="ed-cats" maxlength="80" placeholder="z. B. Geographie" value="' + esc(Q.category) + '">'
          + '<datalist id="ed-cats">' + Object.keys(cats).sort().map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist>')
      + field('Tags', '<input class="tm-input" data-f="tags" placeholder="komma, getrennt" value="' + esc(Q.tags) + '">')
      + '</div>'
      + '<label class="tm-check"><input type="checkbox" id="ed-ready"' + (Q.status === 'READY' ? ' checked' : '')
      + '><span>Bereit für den Einsatz (sonst Entwurf)</span></label>'
      + '</section>'

      + '<section class="ed-card"><h3>' + t.icon + ' ' + esc(t.label) + ' – Details</h3><div id="ed-type" class="ed-stack"></div></section>'
      + '<div id="ed-err" class="err-box" role="alert" hidden></div>'
      + '<div class="ed-actions"><div class="btn-row">'
      + '<button class="tm-btn tm-btn--primary" type="button" id="ed-save">' + (isNew ? 'Frage anlegen' : 'Speichern') + '</button>'
      + '<a class="tm-btn tm-btn--ghost" href="#fragen">Abbrechen</a></div>'
      + (isNew ? '' : '<button class="tm-btn tm-btn--ghost btn-danger" type="button" id="ed-del">Löschen</button>')
      + '</div></div>';

    [].forEach.call(W.app.querySelectorAll('[data-f]'), function (el) {
      el.addEventListener('input', function () {
        var k = el.getAttribute('data-f');
        Q[k] = el.hasAttribute('data-num') ? (num(el.value) || 0) : el.value;
      });
    });
    [].forEach.call(W.app.querySelectorAll('[data-type]'), function (b) {
      b.addEventListener('click', function () {
        if (Q.id) return;
        var k = b.getAttribute('data-type');
        Q.type = k; Q.points = TYPES[k].points; Q.payload = defaultPayload(k);
        renderEditor();
      });
    });
    $('ed-ready').addEventListener('change', function () { Q.status = this.checked ? 'READY' : 'DRAFT'; });
    $('ed-back').addEventListener('click', function () { location.hash = '#fragen'; });
    $('ed-save').addEventListener('click', save);
    if ($('ed-del')) $('ed-del').addEventListener('click', remove);

    renderTypeSection();
  }

  // ---- type-specific section ---------------------------------------------------
  function renderTypeSection() {
    var box = $('ed-type'), p = Q.payload;
    MAP = null;
    switch (Q.type) {
      case 'MULTIPLE_CHOICE': box.innerHTML = mcHtml(p); break;
      case 'OPEN_TEXT':
        box.innerHTML = field('Weitere akzeptierte Antworten (eine pro Zeile)',
          '<textarea class="tm-textarea" id="ot-acc" rows="4" placeholder="z. B. Schreibvarianten&#10;Abkürzungen">'
          + esc((p.acceptedAnswers || []).join('\n')) + '</textarea>',
          'Hilft beim Auswerten: Was zählt sonst noch als richtig?');
        break;
      case 'ESTIMATE':
        box.innerHTML = '<div class="ed-grid">'
          + field('Richtiger Wert *', '<input class="tm-input" inputmode="decimal" data-p="value" data-num value="' + esc(p.value == null ? '' : p.value) + '">')
          + field('Einheit', '<input class="tm-input" data-p="unit" maxlength="40" placeholder="z. B. Liter, km, Jahre" value="' + esc(p.unit || '') + '">')
          + field('Wertung', select('scoring', [['CLOSEST', 'Näheste Schätzung gewinnt'], ['TOLERANCE', 'Innerhalb Toleranz']], p.scoring || 'CLOSEST', ' data-rerender'))
          + (p.scoring === 'TOLERANCE'
            ? field('Toleranz (± %)', '<input class="tm-input" type="number" min="1" max="100" data-p="tolerancePercent" data-num value="' + esc(p.tolerancePercent || 10) + '">')
            : '')
          + '</div>';
        break;
      case 'IMAGE':
        box.innerHTML = mediaBlock('imageUrl', 'image', 'Bild');
        break;
      case 'AUDIO':
      case 'VIDEO':
        var kind = Q.type === 'AUDIO' ? 'audio' : 'video';
        box.innerHTML = mediaBlock('mediaUrl', kind, kind === 'audio' ? 'Audiodatei' : 'Videodatei')
          + field('… oder YouTube-Link', '<input class="tm-input" data-p="youtubeUrl" id="yt-in" placeholder="https://www.youtube.com/watch?v=…" value="' + esc(p.youtubeUrl || '') + '">')
          + '<div class="media-prev" id="prev-yt">' + ytHtml() + '</div>'
          + '<div class="ed-grid">'
          + field('Start (Sek.)', '<input class="tm-input" type="number" min="0" data-p="startSeconds" data-num value="' + esc(p.startSeconds || '') + '">')
          + field('Cliplänge (Sek.)', '<input class="tm-input" type="number" min="0" data-p="clipSeconds" data-num value="' + esc(p.clipSeconds || '') + '">')
          + '</div>';
        break;
      case 'MAP':
        box.innerHTML = '<div><div id="map-box" class="map-box"></div>'
          + '<p class="tm-hint">In die Karte klicken, um den Zielort zu setzen.</p></div>'
          + '<div class="ed-grid">'
          + field('Breitengrad', '<input class="tm-input" inputmode="decimal" data-p="lat" data-num data-map value="' + esc(p.lat == null ? '' : p.lat) + '">')
          + field('Längengrad', '<input class="tm-input" inputmode="decimal" data-p="lng" data-num data-map value="' + esc(p.lng == null ? '' : p.lng) + '">')
          + field('Radius (km)', '<input class="tm-input" type="number" min="1" data-p="radiusKm" data-num data-map value="' + esc(p.radiusKm || 50) + '">')
          + field('Wertung', select('scoring', [['RADIUS', 'Innerhalb Radius = Punkte'], ['CLOSEST', 'Näheste Markierung gewinnt']], p.scoring || 'RADIUS'))
          + '</div>';
        break;
      case 'MASTER':
        box.innerHTML = '<div class="master-note">👑 Die Masterfrage ist die Meta-Frage einer Runde: Je mehr normale Fragen '
          + 'ein Team richtig hat, desto mehr Hinweise hat es. Beim Einbau in eine Runde hinterlegst du pro Frage, '
          + 'was sie zur Masterfrage beiträgt.</div>'
          + field('Hinweis-Modus', select('clueMode', [
              ['FIRST_LETTERS', 'Anfangsbuchstaben der Antworten ergeben die Lösung'],
              ['ANSWERS_ARE_CLUES', 'Jede Antwort ist ein inhaltlicher Hinweis'],
              ['CUSTOM', 'Eigene Logik (unten beschreiben)']], p.clueMode || 'ANSWERS_ARE_CLUES'))
          + field('Erklärung für den Quizmaster', '<textarea class="tm-textarea" data-p="clueExplanation" rows="3" '
            + 'placeholder="Wie genau führen die Antworten der Runde zur Lösung?">' + esc(p.clueExplanation || '') + '</textarea>');
        break;
    }
    bindPayload(box);
    if (Q.type === 'MULTIPLE_CHOICE') bindMc(box);
    if (Q.type === 'OPEN_TEXT') {
      $('ot-acc').addEventListener('input', function () {
        Q.payload.acceptedAnswers = this.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      });
    }
    if (Q.type === 'IMAGE' || Q.type === 'AUDIO' || Q.type === 'VIDEO') bindMedia(box);
    if (Q.type === 'MAP') mountMap();
  }

  function bindPayload(box) {
    [].forEach.call(box.querySelectorAll('[data-p]'), function (el) {
      var k = el.getAttribute('data-p');
      el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () {
        Q.payload[k] = el.hasAttribute('data-num') ? num(el.value) : el.value;
        if (k === 'scoring' && Q.type === 'ESTIMATE' && el.value === 'TOLERANCE' && !Q.payload.tolerancePercent) {
          Q.payload.tolerancePercent = 10;
        }
        if (el.hasAttribute('data-map')) syncMap();
        if (el.hasAttribute('data-rerender')) renderTypeSection();
      });
    });
  }

  // multiple choice
  function mcHtml(p) {
    var opts = p.options || ['', ''];
    return '<div><span class="tm-label">Antwortmöglichkeiten — die richtige markieren</span><div class="opt-list">'
      + opts.map(function (o, i) {
        return '<div class="opt-row"><input type="radio" name="mc-correct" data-ci="' + i + '"'
          + (Number(p.correctIndex) === i ? ' checked' : '') + ' aria-label="Option ' + letter(i) + ' ist richtig">'
          + '<input class="tm-input" data-opt="' + i + '" maxlength="300" placeholder="Option ' + letter(i) + '" value="' + esc(o) + '">'
          + '<button type="button" class="icon-btn" data-rm="' + i + '"' + (opts.length <= 2 ? ' disabled' : '')
          + ' aria-label="Option ' + letter(i) + ' entfernen">✕</button></div>';
      }).join('') + '</div>'
      + '<button type="button" class="tm-btn tm-btn--ghost btn-sm" id="mc-add"' + (opts.length >= 8 ? ' disabled' : '') + '>+ Option</button></div>';
  }
  function bindMc(box) {
    var p = Q.payload;
    [].forEach.call(box.querySelectorAll('[data-opt]'), function (el) {
      el.addEventListener('input', function () { p.options[Number(el.getAttribute('data-opt'))] = el.value; });
    });
    [].forEach.call(box.querySelectorAll('[data-ci]'), function (el) {
      el.addEventListener('change', function () { p.correctIndex = Number(el.getAttribute('data-ci')); });
    });
    [].forEach.call(box.querySelectorAll('[data-rm]'), function (el) {
      el.addEventListener('click', function () {
        var i = Number(el.getAttribute('data-rm'));
        p.options.splice(i, 1);
        var ci = Number(p.correctIndex);
        p.correctIndex = ci === i ? 0 : ci > i ? ci - 1 : ci;
        renderTypeSection();
      });
    });
    $('mc-add').addEventListener('click', function () {
      p.options.push('');
      renderTypeSection();
      var inputs = box.querySelectorAll('[data-opt]');
      if (inputs.length) inputs[inputs.length - 1].focus();
    });
  }

  // media
  function mediaBlock(key, kind, label) {
    return '<div class="tm-field"><span class="tm-label">' + label + '</span>'
      + '<div class="media-row"><input class="tm-input" data-p="' + key + '" data-media="' + kind + '" '
      + 'placeholder="Adresse (https://…) oder Datei hochladen" value="' + esc(Q.payload[key] || '') + '">'
      + '<input type="file" accept="' + kind + '/*" data-file="' + key + '" data-kind="' + kind + '" hidden>'
      + '<button type="button" class="tm-btn tm-btn--ghost btn-sm" data-pick="' + key + '">Hochladen</button></div>'
      + (UPLOAD_MAX ? '<span class="tm-hint">Bis ' + mb(UPLOAD_MAX) + ' pro Datei.</span>' : '')
      + '<div class="media-prev" id="prev-' + key + '">' + previewHtml(kind, Q.payload[key]) + '</div></div>';
  }
  function previewHtml(kind, url) {
    if (!url) return '';
    var u = esc(url);
    if (kind === 'image') return '<img src="' + u + '" alt="Vorschau">';
    if (kind === 'audio') return '<audio controls preload="none" src="' + u + '"></audio>';
    return '<video controls preload="metadata" src="' + u + '"></video>';
  }
  function ytHtml() {
    var id = youtubeId(Q.payload.youtubeUrl);
    if (!id) return '';
    var s = num(Q.payload.startSeconds);
    return '<iframe src="https://www.youtube-nocookie.com/embed/' + id + (s ? '?start=' + Math.round(s) : '')
      + '" title="YouTube-Vorschau" allowfullscreen loading="lazy"></iframe>';
  }
  function bindMedia(box) {
    [].forEach.call(box.querySelectorAll('[data-media]'), function (el) {
      el.addEventListener('change', function () {
        $('prev-' + el.getAttribute('data-p')).innerHTML = previewHtml(el.getAttribute('data-media'), el.value.trim());
      });
    });
    [].forEach.call(box.querySelectorAll('[data-pick]'), function (b) {
      b.addEventListener('click', function () { box.querySelector('[data-file="' + b.getAttribute('data-pick') + '"]').click(); });
    });
    [].forEach.call(box.querySelectorAll('[data-file]'), function (inp) {
      inp.addEventListener('change', function () {
        if (inp.files && inp.files[0]) upload(inp.files[0], inp.getAttribute('data-file'), box.querySelector('[data-pick="' + inp.getAttribute('data-file') + '"]'));
      });
    });
    var yt = $('yt-in');
    if (yt) yt.addEventListener('change', function () { $('prev-yt').innerHTML = ytHtml(); });
  }
  function upload(file, key, btn) {
    if (UPLOAD_MAX && file.size > UPLOAD_MAX) { W.toast('Datei zu groß — erlaubt sind ' + mb(UPLOAD_MAX) + '.', true); return; }
    var fd = new FormData();
    fd.append('file', file);
    btn.disabled = true; btn.textContent = 'Lädt …';
    fetch('../api/upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || !j.ok) throw new Error(j.error || ('Upload fehlgeschlagen (HTTP ' + r.status + ')'));
          Q.payload[key] = j.url;
          renderTypeSection();
          W.toast('Hochgeladen.');
        });
      })
      .catch(function (e) { W.toast(e.message, true); btn.disabled = false; btn.textContent = 'Hochladen'; });
  }

  // map (Leaflet, loaded on first use)
  var leafletWaiters = null;
  function withLeaflet(cb) {
    if (window.L && window.L.map) return cb(window.L);
    if (leafletWaiters) { leafletWaiters.push(cb); return; }
    leafletWaiters = [cb];
    var css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = LEAFLET + 'leaflet.css';
    document.head.appendChild(css);
    var s = document.createElement('script');
    s.src = LEAFLET + 'leaflet.js';
    s.onload = function () { var w = leafletWaiters; leafletWaiters = null; w.forEach(function (f) { f(window.L); }); };
    s.onerror = function () {
      leafletWaiters = null;
      var b = $('map-box');
      if (b) b.innerHTML = '<p class="empty">Karte konnte nicht geladen werden — Koordinaten unten direkt eintragen.</p>';
    };
    document.head.appendChild(s);
  }
  function hasTarget(p) {
    return p.lat != null && p.lng != null && !isNaN(p.lat) && !isNaN(p.lng) && !(p.lat === 0 && p.lng === 0);
  }
  function mountMap() {
    withLeaflet(function (L) {
      var box = $('map-box');
      if (!box || Q.type !== 'MAP' || box._leaflet_id) return;
      var p = Q.payload, has = hasTarget(p), at = has ? [p.lat, p.lng] : HOME;
      var map = L.map(box).setView(at, has ? 7 : 5);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      }).addTo(map);
      MAP = {
        map: map,
        circle: L.circle(at, { radius: (num(p.radiusKm) || 50) * 1000, color: '#e51324', fillColor: '#e51324', fillOpacity: 0.12, weight: 2 }),
        marker: L.circleMarker(at, { radius: 7, color: '#fff', weight: 3, fillColor: '#e51324', fillOpacity: 1 })
      };
      if (has) { MAP.circle.addTo(map); MAP.marker.addTo(map); }
      map.on('click', function (e) {
        p.lat = Math.round(e.latlng.lat * 1e5) / 1e5;
        p.lng = Math.round(e.latlng.lng * 1e5) / 1e5;
        W.app.querySelector('[data-p="lat"]').value = p.lat;
        W.app.querySelector('[data-p="lng"]').value = p.lng;
        syncMap();
      });
    });
  }
  function syncMap() {
    var p = Q.payload;
    if (!MAP || !hasTarget(p)) return;
    MAP.circle.setLatLng([p.lat, p.lng]).setRadius((num(p.radiusKm) || 50) * 1000);
    MAP.marker.setLatLng([p.lat, p.lng]);
    if (!MAP.map.hasLayer(MAP.circle)) { MAP.circle.addTo(MAP.map); MAP.marker.addTo(MAP.map); }
  }

  // ---- save / delete -----------------------------------------------------------
  function save() {
    var btn = $('ed-save'), err = $('ed-err'), label = btn.textContent;
    err.hidden = true;
    btn.disabled = true; btn.textContent = 'Speichert …';
    W.api('questions/save.php', { method: 'POST', body: {
      id: Q.id || '', type: Q.type, prompt: Q.prompt, answer: Q.answer, points: Q.points,
      difficulty: Q.difficulty, category: Q.category, tags: Q.tags, status: Q.status, payload: Q.payload
    } }).then(function () {
      W.toast(Q.id ? 'Gespeichert.' : 'Frage angelegt.');
      location.hash = '#fragen';
    }).catch(function (e) {
      err.textContent = W.errText(e); err.hidden = false;
      err.scrollIntoView({ block: 'center', behavior: 'smooth' });
      btn.disabled = false; btn.textContent = label;
    });
  }

  function remove() {
    if (!confirm('Diese Frage wirklich löschen?')) return;
    W.api('questions/delete.php', { method: 'POST', body: { id: Q.id } })
      .then(function () { W.toast('Frage gelöscht.'); location.hash = '#fragen'; })
      .catch(function (e) { W.toast(W.errText(e), true); });
  }
})();
