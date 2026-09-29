(function () {
  if (typeof KAIA === 'undefined') return;

  var STORE = 'kaia_history';
  var history = [];
  try { history = JSON.parse(sessionStorage.getItem(STORE) || '[]'); } catch (e) {}

  var root = document.createElement('div');
  root.id = 'kaia-root';
  root.innerHTML =
    '<button class="kaia-fab" type="button">💬 リフォームのご相談</button>' +
    '<div class="kaia-panel" role="dialog" aria-label="相談チャット">' +
    '<div class="kaia-head"><span></span><button type="button" aria-label="閉じる">×</button></div>' +
    '<div class="kaia-log" aria-live="polite"></div>' +
    '<div class="kaia-note"></div>' +
    '<form class="kaia-form"><textarea placeholder="ご要望をお書きください" maxlength="1000"></textarea><button type="submit">送信</button></form>' +
    '</div>';
  document.body.appendChild(root);

  var log = root.querySelector('.kaia-log');
  var form = root.querySelector('form');
  var input = form.querySelector('textarea');
  var send = form.querySelector('button');
  root.querySelector('.kaia-head span').textContent = KAIA.company + ' 相談アシスタント';

  var note = root.querySelector('.kaia-note');
  note.textContent = 'AIが回答します。内容は担当者の確認が必要な場合があります。';
  if (KAIA.privacy) {
    var a = document.createElement('a');
    a.href = KAIA.privacy; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ' プライバシーポリシー';
    note.appendChild(a);
  }

  function addMsg(text, who) {
    var d = document.createElement('div');
    d.className = 'kaia-msg ' + who;
    d.textContent = text;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
  }

  function addCards(cases) {
    if (!cases || !cases.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'kaia-cards';
    cases.forEach(function (c) {
      var a = document.createElement('a');
      a.className = 'kaia-card'; a.href = c.url; a.target = '_blank'; a.rel = 'noopener';
      if (c.image) { var im = document.createElement('img'); im.src = c.image; im.alt = ''; a.appendChild(im); }
      var s = document.createElement('span');
      var b = document.createElement('b'); b.textContent = c.title;
      s.appendChild(b); s.appendChild(document.createTextNode(c.summary || ''));
      a.appendChild(s); wrap.appendChild(a);
    });
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
  }

  function addActions() {
    var wrap = document.createElement('div');
    wrap.className = 'kaia-actions';
    if (KAIA.reservation) {
      var r = document.createElement('a'); r.href = KAIA.reservation; r.textContent = '現地調査を予約する'; wrap.appendChild(r);
    }
    if (KAIA.phone) {
      var t = document.createElement('a'); t.href = 'tel:' + KAIA.phone.replace(/[^0-9+]/g, ''); t.textContent = '電話する'; wrap.appendChild(t);
    }
    if (wrap.children.length) log.appendChild(wrap);
  }

  function save() {
    try { sessionStorage.setItem(STORE, JSON.stringify(history.slice(-20))); } catch (e) {}
  }

  // 復元
  if (history.length) {
    history.forEach(function (m) { addMsg(m.content, m.role === 'user' ? 'me' : 'ai'); });
  } else {
    addMsg(KAIA.greeting, 'ai');
  }

  root.querySelector('.kaia-fab').addEventListener('click', function () { root.classList.add('open'); input.focus(); log.scrollTop = log.scrollHeight; });
  root.querySelector('.kaia-head button').addEventListener('click', function () { root.classList.remove('open'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    addMsg(text, 'me');
    history.push({ role: 'user', content: text });
    send.disabled = true;
    var wait = document.createElement('div');
    wait.className = 'kaia-msg ai'; wait.textContent = '…';
    log.appendChild(wait);

    fetch(KAIA.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history.slice(-20) })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        wait.remove();
        if (!res.ok) { addMsg(res.j.message || 'エラーが発生しました。', 'ai'); addActions(); return; }
        if (res.j.reply) { addMsg(res.j.reply, 'ai'); history.push({ role: 'assistant', content: res.j.reply }); }
        addCards(res.j.cases);
        if (res.j.handoff) { addMsg('担当者に内容をお送りしました。折り返しをお待ちください。', 'ai'); }
        save();
      })
      .catch(function () { wait.remove(); addMsg('通信エラーが発生しました。', 'ai'); addActions(); })
      .finally(function () { send.disabled = false; input.focus(); });
  });
})();
