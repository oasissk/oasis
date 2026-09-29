(function () {
  if (typeof KAIA === 'undefined') return;

  var AI = KAIA.mode === 'ai';
  var STORE = 'kaia_history';
  var history = [];
  if (AI) { try { history = JSON.parse(sessionStorage.getItem(STORE) || '[]'); } catch (e) {} }

  var root = document.createElement('div');
  root.id = 'kaia-root';
  root.innerHTML =
    '<button class="kaia-fab" type="button"></button>' +
    '<div class="kaia-panel" role="dialog" aria-label="相談">' +
    '<div class="kaia-head"><span></span><button type="button" aria-label="閉じる">×</button></div>' +
    '<div class="kaia-log" aria-live="polite"></div>' +
    '<div class="kaia-note"></div>' +
    '<form class="kaia-form"><textarea maxlength="1000"></textarea><button type="submit"></button></form>' +
    '</div>';
  document.body.appendChild(root);

  var log = root.querySelector('.kaia-log');
  var form = root.querySelector('.kaia-form');
  var input = form.querySelector('textarea');
  var send = form.querySelector('button');

  root.querySelector('.kaia-fab').textContent = AI ? '💬 リフォームのご相談' : '🔍 事例を探す';
  root.querySelector('.kaia-head span').textContent = KAIA.company + (AI ? ' 相談アシスタント' : ' 事例検索');
  input.placeholder = AI ? 'ご要望をお書きください' : 'キーワード(例: 浴室 寒い)';
  send.textContent = AI ? '送信' : '検索';

  var note = root.querySelector('.kaia-note');
  note.textContent = AI ? 'AIが回答します。内容は担当者の確認が必要な場合があります。' : '掲載中の施工事例・記事から探します。';
  if (KAIA.privacy) {
    var pa = document.createElement('a');
    pa.href = KAIA.privacy; pa.target = '_blank'; pa.rel = 'noopener'; pa.textContent = ' プライバシーポリシー';
    note.appendChild(pa);
  }

  function scroll() { log.scrollTop = log.scrollHeight; }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }
  function addMsg(text, who) { var d = el('div', 'kaia-msg ' + who, text); log.appendChild(d); scroll(); return d; }

  function addCards(cases) {
    if (!cases || !cases.length) return;
    var wrap = el('div', 'kaia-cards');
    cases.forEach(function (c) {
      var a = el('a', 'kaia-card');
      a.href = c.url; a.target = '_blank'; a.rel = 'noopener';
      if (c.image) { var im = el('img'); im.src = c.image; im.alt = ''; a.appendChild(im); }
      var s = el('span');
      s.appendChild(el('b', '', (c.kind === 'article' ? '【参考記事】' : '【施工事例】') + c.title));
      s.appendChild(document.createTextNode(c.summary || ''));
      a.appendChild(s); wrap.appendChild(a);
    });
    log.appendChild(wrap); scroll();
  }

  function addActions(withInquiry) {
    var wrap = el('div', 'kaia-actions');
    if (withInquiry) {
      var b = el('button', '', '担当者に相談する'); b.type = 'button';
      b.addEventListener('click', function () { b.remove(); addInquiryForm(); });
      wrap.appendChild(b);
    }
    if (KAIA.reservation) { var r = el('a', '', '現地調査を予約する'); r.href = KAIA.reservation; wrap.appendChild(r); }
    if (KAIA.phone) { var t = el('a', '', '電話する'); t.href = 'tel:' + KAIA.phone.replace(/[^0-9+]/g, ''); wrap.appendChild(t); }
    if (wrap.children.length) { log.appendChild(wrap); scroll(); }
  }

  function post(path, body) {
    return fetch(KAIA.endpoint + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
  }

  // ---- 問い合わせフォーム(検索のみモード)
  function addInquiryForm() {
    var f = el('form', 'kaia-inq');
    f.innerHTML =
      '<label>お名前<input name="name" required maxlength="100"></label>' +
      '<label>電話番号またはメール<input name="contact" required maxlength="200"></label>' +
      '<label>ご相談内容<textarea name="message" rows="3" maxlength="1000"></textarea></label>' +
      '<input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">' +
      '<label class="kaia-consent"><input type="checkbox" name="consent"> 個人情報の取り扱いに同意します</label>' +
      '<button type="submit">送信する</button>';
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = new FormData(f), btn = f.querySelector('button');
      btn.disabled = true;
      post('inquiry', {
        name: d.get('name'), contact: d.get('contact'), message: d.get('message'),
        website: d.get('website'), consent: !!d.get('consent')
      }).then(function (res) {
        if (!res.ok) { btn.disabled = false; addMsg(res.j.message || 'エラーが発生しました。', 'ai'); return; }
        f.remove(); addMsg('送信しました。担当者より折り返しご連絡します。', 'ai');
      }).catch(function () { btn.disabled = false; addMsg('通信エラーが発生しました。', 'ai'); });
    });
    log.appendChild(f); scroll();
  }

  // ---- 検索のみモード
  function runSearch(kw) {
    kw = kw.trim();
    if (!kw) return;
    addMsg(kw, 'me');
    send.disabled = true;
    var wait = addMsg('…', 'ai');
    post('search', { keywords: kw }).then(function (res) {
      wait.remove();
      if (!res.ok) { addMsg(res.j.message || 'エラーが発生しました。', 'ai'); addActions(false); return; }
      if (res.j.cases.length) { addMsg('「' + kw + '」に近いものです。', 'ai'); addCards(res.j.cases); }
      else { addMsg('「' + kw + '」に近い事例は見つかりませんでした。別の言葉でお試しいただくか、ご相談ください。', 'ai'); }
      addActions(true);
    }).catch(function () { wait.remove(); addMsg('通信エラーが発生しました。', 'ai'); })
      .finally(function () { send.disabled = false; input.focus(); });
  }

  // ---- AIモード
  function runChat(text) {
    addMsg(text, 'me');
    history.push({ role: 'user', content: text });
    send.disabled = true;
    var wait = addMsg('…', 'ai');
    post('chat', { messages: history.slice(-20) }).then(function (res) {
      wait.remove();
      if (!res.ok) { addMsg(res.j.message || 'エラーが発生しました。', 'ai'); addActions(false); return; }
      if (res.j.reply) { addMsg(res.j.reply, 'ai'); history.push({ role: 'assistant', content: res.j.reply }); }
      addCards(res.j.cases);
      if (res.j.handoff) addMsg('担当者に内容をお送りしました。折り返しをお待ちください。', 'ai');
      try { sessionStorage.setItem(STORE, JSON.stringify(history.slice(-20))); } catch (e) {}
    }).catch(function () { wait.remove(); addMsg('通信エラーが発生しました。', 'ai'); addActions(false); })
      .finally(function () { send.disabled = false; input.focus(); });
  }

  // ---- 初期表示
  if (AI && history.length) {
    history.forEach(function (m) { addMsg(m.content, m.role === 'user' ? 'me' : 'ai'); });
  } else {
    addMsg(AI ? KAIA.greeting : 'お探しの場所や工事内容を選ぶか、言葉で入力してください。', 'ai');
    if (!AI && KAIA.quick && KAIA.quick.length) {
      var chips = el('div', 'kaia-actions');
      KAIA.quick.forEach(function (q) {
        var b = el('button', '', q); b.type = 'button';
        b.addEventListener('click', function () { runSearch(q); });
        chips.appendChild(b);
      });
      log.appendChild(chips);
    }
  }

  root.querySelector('.kaia-fab').addEventListener('click', function () { root.classList.add('open'); input.focus(); scroll(); });
  root.querySelector('.kaia-head button').addEventListener('click', function () { root.classList.remove('open'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    AI ? runChat(text) : runSearch(text);
  });
})();
