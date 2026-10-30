/* Admin panel: mobile sidebar, AI drawer, password show/hide. Vanilla JS, no data stored in the browser. */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // ---------- Mobile sidebar ----------
  var side = $('#adm-side');
  var navOverlay = $('#adm-overlay');
  function setNav(open) {
    if (!side) { return; }
    side.classList.toggle('-translate-x-full', !open);
    if (navOverlay) { navOverlay.classList.toggle('hidden', !open); }
  }

  // Desktop sidebar: hover-in opens, hover-out closes (a click on the three-line icon toggles it).
  if (side) {
    side.addEventListener('mouseenter', function () {
      if (window.innerWidth >= 768) { side.classList.add('adm-open'); }
    });
    side.addEventListener('mouseleave', function () {
      if (window.innerWidth >= 768) {
        side.classList.remove('adm-open');
        side.classList.remove('adm-pinned');
        var br = side.querySelector('[data-adm="pin-side"]');
        if (br) { br.setAttribute('aria-expanded', 'false'); }
      }
    });
  }

  // ---------- Theme switcher ----------
  var THEMES = ['maroon', 'ocean', 'emerald'];
  function applyTheme(name) {
    if (THEMES.indexOf(name) < 0) { name = 'maroon'; }
    document.documentElement.setAttribute('data-theme', name);
    try { localStorage.setItem('adm-theme', name); } catch (e) { /* private mode: keep it for this page only */ }
    $$('[data-theme-name]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-theme-name') === name ? 'true' : 'false');
    });
  }
  applyTheme(document.documentElement.getAttribute('data-theme') || 'maroon');

  // ---------- AI drawer ----------
  var drawer = $('#ai-drawer');
  var aiOverlay = $('#ai-overlay');
  var chatTurns = []; // kept in memory only: cleared on reload, never sent anywhere but the AI endpoint
  var chatLog = $('#ai-chat-log');
  var chatForm = $('#ai-chat-form');
  var chatInput = $('#ai-chat-input');
  var outBox = $('#ai-output');
  var outTitle = $('#ai-output-title');
  var outBody = $('#ai-output-body');
  var activeTab = 'chat';

  function setDrawer(open) {
    if (!drawer) { return; }
    drawer.classList.toggle('translate-x-full', !open);
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (aiOverlay) { aiOverlay.classList.toggle('hidden', !open); }
    if (open) {
      setTimeout(focusActive, 320);
    }
  }

  function focusActive() {
    if (activeTab === 'chat' && chatInput) { chatInput.focus(); return; }
    var ta = $('[data-ai-panel="' + activeTab + '"] textarea');
    if (ta) { ta.focus(); }
  }

  function showTab(name) {
    activeTab = name;
    $$('[data-ai-tab]').forEach(function (b) {
      var on = b.getAttribute('data-ai-tab') === name;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.classList.toggle('bg-maroon-800', on);
      b.classList.toggle('text-white', on);
      b.classList.toggle('text-maroon-700', !on);
      b.classList.toggle('hover:bg-maroon-200/60', !on);
    });
    $$('[data-ai-panel]').forEach(function (p) {
      p.classList.toggle('hidden', p.getAttribute('data-ai-panel') !== name);
    });
    $$('[data-ai-panel-footer]').forEach(function (f) {
      f.classList.toggle('hidden', f.getAttribute('data-ai-panel-footer') !== name);
    });
    hideOutput();
  }

  function hideOutput() {
    if (outBox) { outBox.classList.add('hidden'); }
  }

  function showOutput(title, text, isError) {
    if (!outBox) { return; }
    outTitle.textContent = title;
    outBody.textContent = text; // textContent: model output is never rendered as HTML
    outBox.classList.remove('hidden');
    outBox.classList.toggle('bg-rose-50', !!isError);
    outBox.classList.toggle('border-rose-200', !!isError);
    outBox.classList.toggle('text-rose-800', !!isError);
    outBox.classList.toggle('bg-amber-50', !isError);
    outBox.classList.toggle('border-amber-200', !isError);
    outBox.classList.toggle('text-maroon-900', !isError);
  }

  function post(payload) {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var url = drawer ? drawer.getAttribute('data-url') : '';
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': meta ? meta.getAttribute('content') : ''
      },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'সার্ভার থেকে সঠিক উত্তর আসেনি।' }; });
    }).catch(function () {
      return { ok: false, error: 'নেটওয়ার্ক সমস্যা। ইন্টারনেট দেখে আবার চেষ্টা করুন।' };
    });
  }

  function setBusy(btn, on) {
    if (!btn) { return; }
    btn.disabled = on;
    btn.setAttribute('aria-busy', on ? 'true' : 'false');
    btn.classList.toggle('opacity-60', on);
  }

  function bubble(role, text) {
    if (!chatLog) { return null; }
    var row = document.createElement('div');
    row.className = role === 'user' ? 'flex justify-end' : 'flex justify-start';
    var b = document.createElement('div');
    b.className = (role === 'user'
      ? 'bg-maroon-800 text-white'
      : 'bg-maroon-100 text-maroon-900') + ' p-3 rounded-2xl text-xs max-w-[85%] whitespace-pre-wrap leading-relaxed';
    b.textContent = text;
    row.appendChild(b);
    chatLog.appendChild(row);
    row.scrollIntoView({ block: 'end' });
    return row;
  }

  function runOneShot(mode, text, btn, title) {
    var value = text.trim();
    if (!value) {
      showOutput('ত্রুটি', 'প্রথমে তথ্য লিখুন।', true);
      return;
    }
    setBusy(btn, true);
    showOutput(title, 'এআই বিশ্লেষণ করছে...', false);
    post({ mode: mode, text: value }).then(function (d) {
      setBusy(btn, false);
      if (d.ok) { showOutput(title, d.text, false); }
      else { showOutput('ত্রুটি', d.error || 'কিছু একটা সমস্যা হয়েছে।', true); }
    });
  }

  // ---------- Password show / hide ----------
  function enhancePasswords() {
    $$('input[type="password"]').forEach(function (inp) {
      if (inp.closest('.pw-wrap')) { return; }
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp);
      wrap.appendChild(inp);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-eye';
      btn.setAttribute('aria-label', 'পাসওয়ার্ড দেখুন');
      btn.setAttribute('aria-pressed', 'false');
      btn.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
      btn.addEventListener('click', function () {
        var reveal = inp.type === 'password';
        inp.type = reveal ? 'text' : 'password';
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        btn.setAttribute('aria-label', reveal ? 'পাসওয়ার্ড লুকান' : 'পাসওয়ার্ড দেখুন');
        btn.innerHTML = '<i class="fa-solid ' + (reveal ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i>';
        inp.focus();
      });
      wrap.appendChild(btn);
    });
  }

  // ---------- Events ----------
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-adm], [data-ai-tab], [data-ai-action]') : null;
    if (!t) { return; }
    var adm = t.getAttribute('data-adm');
    if (adm === 'toggle-cat') {
      var cat = t.closest('.adm-cat');
      if (!cat) { return; }
      var willOpen = !cat.classList.contains('open');
      cat.classList.toggle('open', willOpen);
      t.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      // on the slim desktop rail, opening a category also opens the sidebar
      if (side && window.innerWidth >= 768) { side.classList.add('adm-open'); }
      return;
    }
    if (adm === 'pin-side') {
      if (window.innerWidth < 768) { setNav(false); return; }
      if (side) {
        // click: pin open (or unpin and close); moving the mouse out always closes it
        var pinnedNow = !side.classList.contains('adm-pinned');
        side.classList.toggle('adm-pinned', pinnedNow);
        side.classList.toggle('adm-open', pinnedNow || false);
        t.setAttribute('aria-expanded', pinnedNow ? 'true' : 'false');
      }
      return;
    }
    if (adm === 'theme') {
      applyTheme(t.getAttribute('data-theme-name'));
      return;
    }
    if (adm === 'open-nav') { setNav(true); }
    else if (adm === 'close-nav') { setNav(false); }
    else if (adm === 'open-ai') { setDrawer(true); }
    else if (adm === 'close-ai') { setDrawer(false); }

    var tab = t.getAttribute('data-ai-tab');
    if (tab) { showTab(tab); return; }

    var action = t.getAttribute('data-ai-action');
    if (action === 'clear-chat') {
      chatTurns = [];
      if (chatLog) { chatLog.innerHTML = ''; }
      hideOutput();
    } else if (action === 'triage') {
      runOneShot('triage', $('#ai-triage-input').value, t, 'ট্রায়াজ বিশ্লেষণ');
    } else if (action === 'summary') {
      runOneShot('summary', $('#ai-summary-input').value, t, 'ভিজিট সামারি');
    }
  });

  if (chatForm) {
    chatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = chatInput.value.trim();
      if (!text) { return; }
      chatInput.value = '';
      chatTurns.push({ role: 'user', text: text });
      bubble('user', text);
      var pendingRow = bubble('assistant', '...');
      post({ mode: 'chat', messages: chatTurns.slice(-12) }).then(function (d) {
        if (pendingRow) { pendingRow.remove(); }
        if (d.ok) {
          chatTurns.push({ role: 'assistant', text: d.text });
          bubble('assistant', d.text);
        } else {
          chatTurns.pop(); // let the user retry the same question
          bubble('assistant', d.error || 'উত্তর তৈরি করা যায়নি।');
        }
      });
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    setDrawer(false);
    setNav(false);
  });

  // Initial state
  showTab('chat');
  enhancePasswords();
})();
