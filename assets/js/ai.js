(function () {
  'use strict';
  var openBtn = document.getElementById('aiOpen'), pop = document.getElementById('aiPop');
  if (!openBtn || !pop) { return; }
  var log = document.getElementById('aiLog'), form = document.getElementById('aiForm');
  var input = document.getElementById('aiInput'), send = document.getElementById('aiSend');
  var mic = document.getElementById('aiMic'), speakBtn = document.getElementById('aiSpeak');
  var closeBtn = document.getElementById('aiClose');
  var history = [], busy = false, speakOn = false;
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var canSpeak = 'speechSynthesis' in window;

  function bubble(role, text) {
    var d = document.createElement('div');
    d.className = 'ai-msg ' + (role === 'user' ? 'me' : 'bot');
    d.textContent = text; // textContent: never render model output as HTML
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }
  function say(text) {
    if (!speakOn || !canSpeak) { return; }
    try {
      window.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'bn-BD';
      window.speechSynthesis.speak(u);
    } catch (e) { /* ignore */ }
  }
  function open() {
    pop.hidden = false;
    openBtn.setAttribute('aria-expanded', 'true');
    if (!log.childElementCount) {
      bubble('bot', 'আসসালামু আলাইকুম! আমি ডাঃ জেবুন্নেসা জেবুর এআই সহকারী। আমি অ্যাপয়েন্টমেন্ট নিতে, চেম্বারের তথ্য জানাতে বা সাধারণ প্রশ্নের উত্তর দিতে পারি। আপনার কী দরকার?');
    }
    setTimeout(function () { input.focus(); }, 50);
  }
  function close() { pop.hidden = true; openBtn.setAttribute('aria-expanded', 'false'); }

  openBtn.addEventListener('click', function () { pop.hidden ? open() : close(); });
  closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) { close(); openBtn.focus(); } });

  if (canSpeak && speakBtn) {
    speakBtn.hidden = false;
    speakBtn.addEventListener('click', function () {
      speakOn = !speakOn;
      speakBtn.setAttribute('aria-pressed', speakOn ? 'true' : 'false');
      speakBtn.textContent = speakOn ? '🔊' : '🔈';
      if (!speakOn) { window.speechSynthesis.cancel(); }
    });
  }
  if (SR && mic) {
    mic.hidden = false;
    var rec = new SR();
    rec.lang = 'bn-BD';
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    rec.onresult = function (e) {
      var t = e.results && e.results[0] && e.results[0][0] ? e.results[0][0].transcript : '';
      input.value = t;
      mic.classList.remove('on');
      if (t) { submit(); }
    };
    rec.onerror = rec.onend = function () { mic.classList.remove('on'); };
    mic.addEventListener('click', function () {
      try { mic.classList.add('on'); rec.start(); } catch (e) { mic.classList.remove('on'); }
    });
  }

  function submit() {
    var text = input.value.trim();
    if (!text || busy) { return; }
    busy = true; send.disabled = true;
    input.value = '';
    bubble('user', text);
    history.push({ role: 'user', text: text });
    var wait = bubble('bot', '…');
    wait.classList.add('wait');
    fetch('/ai-chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history }),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (j) {
      wait.remove();
      if (j && j.ok && j.reply) {
        bubble('bot', j.reply);
        history.push({ role: 'assistant', text: j.reply });
        say(j.reply);
        if (j.lead_id) { bubble('bot', '✔ আপনার অ্যাপয়েন্টমেন্ট অনুরোধ নম্বর #' + j.lead_id + ' নেওয়া হয়েছে। চেম্বার থেকে ফোনে সময় নিশ্চিত করা হবে।'); }
      } else {
        bubble('bot', (j && j.error) || 'সমস্যা হয়েছে। অনুগ্রহ করে অ্যাপয়েন্টমেন্ট বা হোয়াটসঅ্যাপ ব্যবহার করুন।');
      }
    }).catch(function () {
      wait.remove();
      bubble('bot', 'ইন্টারনেট সংযোগে সমস্যা। অনুগ্রহ করে আবার চেষ্টা করুন।');
    }).then(function () { busy = false; send.disabled = false; input.focus(); });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
  document.querySelectorAll('.ai-chips [data-q]').forEach(function (b) {
    b.addEventListener('click', function () { input.value = b.getAttribute('data-q'); submit(); });
  });
})();
