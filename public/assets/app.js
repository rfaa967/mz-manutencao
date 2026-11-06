/*
 * MZ Manutenção — comportamentos da interface.
 * Todo o JavaScript fica neste arquivo (nada inline): a CSP só permite scripts com nonce e bloqueia
 * atributos on*, então os comportamentos são ligados por atributos data-*.
 */
(function () {
  'use strict';
  var $ = function (s, ctx) { return (ctx || document).querySelector(s); };
  var $$ = function (s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); };
  var csrf = function () { var m = $('meta[name=csrf]'); return m ? m.content : ''; };
  var pronto = function (fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

  /* ---------- Linhas clicáveis ---------- */
  document.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-href]');
    if (tr && !ev.target.closest('a,button,input,select,label,form')) window.location = tr.dataset.href;
  });

  /* ---------- Confirmação (data-confirm em form ou botão) ---------- */
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.dataset.confirm || (ev.submitter && ev.submitter.dataset.confirm);
    if (msg && !confirm(msg)) ev.preventDefault();
  });

  /* ---------- data-auto-submit: envia o formulário ao mudar ---------- */
  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-auto-submit]');
    if (el && el.form) el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
  });

  /* ---------- data-print ---------- */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-print]')) { ev.preventDefault(); window.print(); }
  });
  pronto(function () { if (document.body.dataset.autoPrint) setTimeout(function () { window.print(); }, 300); });

  /* ---------- Campos condicionais: data-show-when="campo=valor|valor2" ---------- */
  function atualizarCondicionais() {
    $$('[data-show-when]').forEach(function (el) {
      var p = el.dataset.showWhen.split('=');
      var src = $('[name="' + CSS.escape(p[0]) + '"]:checked') || $('[name="' + CSS.escape(p[0]) + '"]');
      el.style.display = src && p[1].split('|').indexOf(src.value) >= 0 ? '' : 'none';
    });
  }
  document.addEventListener('change', atualizarCondicionais);
  pronto(atualizarCondicionais);

  /* ---------- Linhas dinâmicas (tarefas/materiais do plano) ---------- */
  document.addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-add-row]');
    if (add) {
      var tpl = document.getElementById(add.dataset.addRow), alvo = document.getElementById(add.dataset.target);
      if (tpl && alvo) alvo.appendChild(tpl.content.cloneNode(true));
    }
    var rem = ev.target.closest('[data-remove-row]');
    if (rem) rem.closest('.linha-dinamica').remove();
  });

  /* ---------- Checklist da OT via AJAX ---------- */
  document.addEventListener('change', function (ev) {
    var cb = ev.target.closest('input[data-tarefa]');
    if (!cb) return;
    var fd = new FormData();
    fd.append('_csrf', csrf()); fd.append('tarefa_id', cb.dataset.tarefa); fd.append('concluida', cb.checked ? '1' : '0');
    fetch(cb.dataset.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) {
      if (r.ok && !r.redirected) return;
      cb.checked = !cb.checked;
      alert(r.status === 401 ? 'Sua sessão expirou. Entre novamente e marque a tarefa outra vez.'
          : r.status === 409 ? 'Esta OT está encerrada ou atribuída a outro técnico.' : 'Não foi possível salvar a tarefa.');
    }).catch(function () { cb.checked = !cb.checked; alert('Sem conexão: a tarefa não foi salva.'); });
    // contador "Checklist (x/y)" na versão mobile
    var card = cb.closest('[data-checklist]');
    if (card) {
      var t = $$('input[data-tarefa]', card), c = t.filter(function (x) { return x.checked; }).length;
      var h = $('[data-checklist-titulo]', card); if (h) h.textContent = 'Checklist (' + c + '/' + t.length + ')';
    }
  });

  /* ---------- QR Codes: [data-qr] (biblioteca qrcode-generator) ---------- */
  pronto(function () {
    if (!window.qrcode) return;
    $$('[data-qr]').forEach(function (el) {
      var q = qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
      el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
      var svg = $('svg', el); if (svg) svg.setAttribute('role', 'img'), svg.setAttribute('aria-label', 'QR Code');
    });
  });

  /* ---------- Gráficos: <canvas data-grafico='{"type":...}'> (Chart.js) ---------- */
  pronto(function () {
    if (!window.Chart) return;
    $$('canvas[data-grafico]').forEach(function (cv) {
      var cfg = JSON.parse(cv.dataset.grafico);
      if (cfg.moeda) {
        cfg.options = cfg.options || {}; cfg.options.scales = { y: { beginAtZero: true, ticks: { callback: function (v) { return 'R$ ' + v.toLocaleString('pt-BR'); } } } };
        delete cfg.moeda;
      }
      new Chart(cv, cfg);
    });
  });

  /* ---------- Calendário (FullCalendar) ---------- */
  pronto(function () {
    var el = $('#cal[data-eventos]');
    if (!el || !window.FullCalendar) return;
    var estreito = window.innerWidth < 576;
    var cal = new FullCalendar.Calendar(el, {
      locale: 'pt-br', height: 'auto', dayMaxEvents: 4,
      initialView: estreito ? 'listWeek' : 'dayGridMonth',
      headerToolbar: estreito ? { left: 'prev,next today', center: 'title', right: 'listWeek,dayGridMonth' }
                              : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
      events: function (info, ok, err) {
        var u = new URL(el.dataset.eventos, location.href);
        u.searchParams.set('start', info.startStr.slice(0, 10)); u.searchParams.set('end', info.endStr.slice(0, 10));
        $$('[data-cal-filtro]').forEach(function (f) { u.searchParams.set(f.name, f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value); });
        fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); }).then(ok).catch(err);
      }
    });
    cal.render();
    $$('[data-cal-filtro]').forEach(function (f) { f.addEventListener('change', function () { cal.refetchEvents(); }); });
  });

  /* ---------- Plano: carrega os contadores do ativo escolhido ---------- */
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('select[data-contadores-url]');
    if (!sel) return;
    var alvo = document.getElementById(sel.dataset.contadoresAlvo);
    fetch(sel.dataset.contadoresUrl + '&ativo=' + encodeURIComponent(sel.value), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (l) {
        alvo.replaceChildren(new Option('— selecione —', ''));
        l.forEach(function (c) { alvo.add(new Option(c.nome + ' (atual ' + c.valor_atual + ' ' + c.unidade + ')', c.id)); });
      });
  });

  /* ---------- Leitor de QR (mobile) ---------- */
  pronto(function () {
    var el = $('#leitor[data-destino]');
    if (!el) return;
    var msg = $('#leitor-msg'), lido = false;
    function abrir(txt) {
      if (lido) return; lido = true;
      var cod = txt;
      try { var u = new URL(txt, location.href); if (u.searchParams.get('c')) cod = u.searchParams.get('c'); } catch (e) { /* texto puro */ }
      location.href = el.dataset.destino + '&c=' + encodeURIComponent(cod);
    }
    function semCamera(texto) {
      msg.textContent = texto;
      el.hidden = true;                                   // tira o quadro preto da tela
      var cod = document.querySelector('input[name=q]'); if (cod) cod.focus();
    }
    if (!window.Html5Qrcode) { semCamera('Não foi possível carregar o leitor. Digite o código abaixo.'); return; }
    var q = new Html5Qrcode('leitor');
    q.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } },
      function (t) { q.stop().finally(function () { abrir(t); }); }, function () {})
      .catch(function (e) {
        semCamera('Câmera indisponível (' + (e && e.name ? e.name : 'permissão negada') + '). O navegador exige HTTPS e permissão de câmera. Digite o código abaixo.');
      });
  });

  /* ---------- Service worker (PWA) ---------- */
  pronto(function () {
    var sw = document.body.dataset.sw;
    if (sw && 'serviceWorker' in navigator) navigator.serviceWorker.register(sw).catch(function () {});
  });

  /* ---------- Acessibilidade: liga <label class="form-label"> ao campo do mesmo bloco ---------- */
  pronto(function () {
    var n = 0;
    $$('label.form-label:not([for])').forEach(function (lb) {
      var campo = lb.parentElement && lb.parentElement.querySelector('input:not([type=hidden]),select,textarea');
      if (!campo) return;
      if (!campo.id) campo.id = 'campo_auto_' + (++n);
      lb.htmlFor = campo.id;
    });
    $$('button:not([aria-label])').forEach(function (b) {
      if (b.textContent.trim() === '' && b.querySelector('.bi-x-lg,.bi-x')) b.setAttribute('aria-label', 'Remover');
    });
  });

  /* =====================================================================
   * Tabelas server-side (estilo DataTables)
   * Busca com atraso, ordenação, paginação e itens por página atualizam só a tabela
   * (requisição com _parcial=1); a URL da página acompanha (voltar/compartilhar funcionam).
   * Sem JavaScript, os mesmos links e formulários funcionam com recarga completa.
   * ===================================================================== */
  function dtCarregar(cont, url, focar) {
    var u = new URL(url, location.href);
    u.searchParams.set('_parcial', cont.dataset.dt);
    cont.classList.add('dt-carregando');
    cont.setAttribute('aria-busy', 'true');
    var ctrl = cont._dtCtrl; if (ctrl) ctrl.abort();
    cont._dtCtrl = new AbortController();
    return fetch(u, { credentials: 'same-origin', signal: cont._dtCtrl.signal, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (r.status === 429 || r.status === 401) return r.json().then(function (j) { throw new Error(j.erro || 'Muitas requisições.'); });
        if (!r.ok) throw new Error('Falha ao carregar (' + r.status + ').');
        return r.text();
      })
      .then(function (html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var novo = tmp.querySelector('[data-dt="' + CSS.escape(cont.dataset.dt) + '"]');
        if (!novo) throw new Error('Resposta inesperada.');
        cont.replaceWith(novo);
        u.searchParams.delete('_parcial');
        history.replaceState(null, '', u.pathname + u.search);
        if (focar) {
          var b = $('.dt-busca', novo);
          if (b) { b.focus(); b.setSelectionRange(b.value.length, b.value.length); }
        }
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        cont.classList.remove('dt-carregando'); cont.removeAttribute('aria-busy');
        var aviso = $('.dt-erro', cont); if (aviso) { aviso.textContent = e.message; aviso.hidden = false; }
      });
  }
  function dtUrl(cont, mudar) {
    var u = new URL(cont.dataset.url, location.href);
    Object.keys(mudar).forEach(function (k) { mudar[k] === '' ? u.searchParams.delete(k) : u.searchParams.set(k, mudar[k]); });
    return u.toString();
  }
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('.dt a.dt-nav');
    if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
    ev.preventDefault();
    dtCarregar(a.closest('.dt'), a.href, false);
  });
  var dtTimer;
  document.addEventListener('input', function (ev) {
    var b = ev.target.closest('.dt .dt-busca');
    if (!b) return;
    clearTimeout(dtTimer);
    dtTimer = setTimeout(function () { var c = b.closest('.dt'); dtCarregar(c, dtUrl(c, { q: b.value.trim(), pag: '' }), true); }, 350);
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('.dt form.dt-form');
    if (!f) return;
    ev.preventDefault();
    var c = f.closest('.dt'), b = $('.dt-busca', f);
    dtCarregar(c, dtUrl(c, { q: b ? b.value.trim() : '', pag: '' }), true);
  });
  document.addEventListener('change', function (ev) {
    var s = ev.target.closest('.dt .dt-pp');
    if (!s) return;
    var c = s.closest('.dt');
    dtCarregar(c, dtUrl(c, { pp: s.value, pag: '' }), false);
  });
})();
