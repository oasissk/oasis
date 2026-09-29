(function () {
  if (typeof KAIA === 'undefined') return;

  var AI = KAIA.mode === 'ai';
  var STORE = 'kaia_history';
  var history = [];
  if (AI) { try { history = JSON.parse(sessionStorage.getItem(STORE) || '[]'); } catch (e) {} }

  var TITLE = KAIA.title || '簡単お困りごと診断';
  var AVATAR =
    '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
    '<rect width="64" height="64" fill="#dbe8f7"/>' +
    '<path d="M8 64c2-12 12-18 24-18s22 6 24 18z" fill="#12305f"/>' +
    '<path d="M27 40h10v9c0 3-2.5 5-5 5s-5-2-5-5z" fill="#f2c4a2"/>' +
    '<path d="M27 46l5 6 5-6" fill="#fff"/>' +
    '<path d="M15 34c-2-17 6-27 17-27s19 10 17 27c-.5 7-2 11-4 13l-2-4c1-5 0-10-1-14-6-1-12-4-16-9-3 5-6 9-7 14-1 4 0 9 1 13l-2 4c-2-3-3-7-3-13z" fill="#3a2a25"/>' +
    '<ellipse cx="32" cy="29" rx="12.5" ry="14.5" fill="#f8d7bb"/>' +
    '<path d="M19.5 27c1-8 6-13 12.5-13 6 0 11 4 12.5 12-4-1-9-4-12-8-2 4-8 8-13 9z" fill="#3a2a25"/>' +
    '<circle cx="27" cy="30" r="1.6" fill="#3a2a25"/><circle cx="37" cy="30" r="1.6" fill="#3a2a25"/>' +
    '<circle cx="23.5" cy="34" r="2.6" fill="#f4a9a0" opacity=".55"/><circle cx="40.5" cy="34" r="2.6" fill="#f4a9a0" opacity=".55"/>' +
    '<path d="M28 36.5c2.5 2.4 5.5 2.4 8 0" stroke="#b5544a" stroke-width="1.6" fill="none" stroke-linecap="round"/>' +
    '</svg>';

  var root = document.createElement('div');
  root.id = 'kaia-root';
  root.innerHTML =
    '<button class="kaia-fab" type="button"><span class="kaia-ava">' + AVATAR + '</span><span><b></b><small>無料・1分・選ぶだけ</small></span></button>' +
    '<div class="kaia-panel" role="dialog" aria-label="' + TITLE + '">' +
    '<div class="kaia-head"><span class="kaia-ava">' + AVATAR + '</span><span class="kaia-title"><b></b><small></small></span><button class="kaia-close" type="button" aria-label="閉じる">×</button></div>' +
    '<div class="kaia-log" aria-live="polite"></div>' +
    '<div class="kaia-note"></div>' +
    '<form class="kaia-form"><textarea maxlength="1000"></textarea><button type="submit"></button></form>' +
    '</div>';
  document.body.appendChild(root);

  var log = root.querySelector('.kaia-log');
  var form = root.querySelector('.kaia-form');
  var input = form.querySelector('textarea');
  var send = form.querySelector('button');

  root.querySelector('.kaia-fab b').textContent = TITLE;
  root.querySelector('.kaia-head b').textContent = TITLE;
  root.querySelector('.kaia-head small').textContent = KAIA.company + 'が屋根・雨漏りのお悩みを一緒に整理します';
  input.placeholder = AI ? 'ご要望をお書きください' : 'キーワードで探すこともできます';
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
  function addMsg(text, who) {
    var row = el('div', 'kaia-row ' + who);
    if (who === 'ai') { var a = el('span', 'kaia-ava'); a.innerHTML = AVATAR; row.appendChild(a); }
    row.appendChild(el('div', 'kaia-msg', text));
    log.appendChild(row); scroll();
    return row;
  }

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

  function addActions(withInquiry, prefill) {
    var wrap = el('div', 'kaia-actions');
    if (withInquiry) {
      var b = el('button', '', '担当者に相談する'); b.type = 'button';
      b.addEventListener('click', function () { b.remove(); addInquiryForm(prefill); });
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
  function addInquiryForm(prefill) {
    var f = el('form', 'kaia-inq');
    f.innerHTML =
      '<h4>担当者に相談する</h4>' +
      '<p class="kaia-sub">' + KAIA.company + 'の担当者が、内容を確認してご連絡します。</p>' +
      '<label><span>お名前<em class="kaia-req">必須</em></span><input name="name" required maxlength="100" autocomplete="name"></label>' +
      '<label><span>電話番号またはメール<em class="kaia-req">必須</em></span><input name="contact" required maxlength="200" autocomplete="tel"></label>' +
      '<label><span>ご相談内容</span><textarea name="message" maxlength="1000"></textarea></label>' +
      '<input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">' +
      '<label class="kaia-consent"><input type="checkbox" name="consent"><span>個人情報の取り扱いに同意します' + (KAIA.privacy ? '(<a href="' + KAIA.privacy + '" target="_blank" rel="noopener">詳細</a>)' : '') + '</span></label>' +
      '<button type="submit">この内容で送信する</button>';
    if (prefill) f.querySelector('textarea').value = prefill;
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


  // ---- ヒアリング(検索のみモード)
  var answers = [], kws = [], urgent = false, awaitFree = false, chipsEl = null;

  function askChips(labels, onPick) {
    chipsEl = el('div', 'kaia-actions');
    labels.forEach(function (l, i) {
      var b = el('button', '', l); b.type = 'button';
      b.addEventListener('click', function () { if (chipsEl) { chipsEl.remove(); chipsEl = null; } onPick(i); });
      chipsEl.appendChild(b);
    });
    log.appendChild(chipsEl); scroll();
  }

  function startFlow() {
    answers = []; kws = []; urgent = false; awaitFree = false; form.style.display = '';
    askStep('start');
  }

  function askStep(id) {
    if (id === 'free') {
      awaitFree = true;
      form.style.display = 'none'; // 入力欄が2つに見えないよう、この質問の間は下の検索欄を隠す
      addMsg('最後の質問です。ほかに気になること(場所・状況など)があれば、下の枠にご記入ください。なければそのまま進めます。', 'ai');
      var box = el('div', 'kaia-free');
      var ta = el('textarea'); ta.maxLength = 500; ta.rows = 3; ta.placeholder = '例: 2階の寝室の天井にシミがあります';
      var go = el('button', 'kaia-free-go', 'この内容で進む'); go.type = 'button';
      var skip = el('button', 'kaia-free-skip', '特になし・次へ進む'); skip.type = 'button';
      function done(text) { box.remove(); chipsEl = null; finishFlow(text); }
      go.addEventListener('click', function () { done(ta.value.trim()); });
      skip.addEventListener('click', function () { done(''); });
      box.appendChild(ta); box.appendChild(go); box.appendChild(skip);
      chipsEl = box;
      log.appendChild(box); scroll();
      return;
    }
    var step = KAIA.flow[id];
    if (!step) { finishFlow(''); return; }
    addMsg(step.q, 'ai');
    askChips(step.opts.map(function (o) { return o.label; }), function (i) {
      var o = step.opts[i];
      addMsg(o.label, 'me');
      answers.push(step.key + ': ' + o.label);
      (o.kw || []).forEach(function (k) { if (kws.indexOf(k) < 0) kws.push(k); });
      if (o.urgent) urgent = true;
      askStep(o.next || 'free');
    });
  }

  function finishFlow(free) {
    awaitFree = false;
    form.style.display = '';
    if (free) { addMsg(free, 'me'); answers.push('ほかに気になること: ' + free); }
    var summary = answers.join('\n');
    addMsg('ご相談内容を整理しました。\n\n' + summary, 'ai');
    if (urgent) {
      addMsg('今まさに雨漏りしている場合は、屋根には登らず、お急ぎでしたらお電話ください。室内は、バケツやタオルで被害を広げないようにしてください。', 'ai');
    }
    var wait = addMsg('近い事例を探しています…', 'ai');
    post('search', { keywords: kws.slice(0, 5).join(' ') || (free ? '' : '屋根'), text: free || '' }).then(function (res) {
      wait.remove();
      if (res.ok && res.j.cases.length) { addMsg('近い事例・記事です。', 'ai'); addCards(res.j.cases); }
      else { addMsg('ぴったりの事例は見つかりませんでした。担当者がお話をうかがいます。', 'ai'); }
    }).catch(function () { wait.remove(); })
      .finally(function () {
        addActions(true, 'ご相談内容(チャットで選択):\n' + summary);
        var again = el('div', 'kaia-actions');
        var b = el('button', '', '最初からやり直す'); b.type = 'button';
        b.addEventListener('click', function () { again.remove(); startFlow(); });
        again.appendChild(b); log.appendChild(again); scroll();
      });
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
    addMsg(KAIA.greeting, 'ai');
    if (!AI) startFlow();
  }

  root.querySelector('.kaia-fab').addEventListener('click', function () { root.classList.add('open'); input.focus(); scroll(); });
  root.querySelector('.kaia-close').addEventListener('click', function () { root.classList.remove('open'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    if (AI) runChat(text);
    else if (awaitFree) { if (chipsEl) { chipsEl.remove(); chipsEl = null; } finishFlow(text); }
    else runSearch(text);
  });
})();
