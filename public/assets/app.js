// Linhas clicáveis
document.addEventListener('click', function (ev) {
  const tr = ev.target.closest('tr[data-href]');
  if (tr && !ev.target.closest('a,button,input,select,form')) window.location = tr.dataset.href;
});

// Confirmação em formulários/botões
document.addEventListener('submit', function (ev) {
  const msg = ev.target.dataset.confirm || (ev.submitter && ev.submitter.dataset.confirm);
  if (msg && !confirm(msg)) ev.preventDefault();
});

// Campos condicionais: data-show-when="campo=valor"
function atualizarCondicionais() {
  document.querySelectorAll('[data-show-when]').forEach(function (el) {
    const [campo, valor] = el.dataset.showWhen.split('=');
    const src = document.querySelector('[name="' + campo + '"]:checked') || document.querySelector('[name="' + campo + '"]');
    el.style.display = src && valor.split('|').includes(src.value) ? '' : 'none';
  });
}
document.addEventListener('change', atualizarCondicionais);
document.addEventListener('DOMContentLoaded', atualizarCondicionais);

// Linhas dinâmicas (tarefas/materiais do plano)
document.addEventListener('click', function (ev) {
  const add = ev.target.closest('[data-add-row]');
  if (add) {
    const tpl = document.getElementById(add.dataset.addRow);
    const alvo = document.getElementById(add.dataset.target);
    alvo.insertAdjacentHTML('beforeend', tpl.innerHTML);
  }
  const rem = ev.target.closest('[data-remove-row]');
  if (rem) rem.closest('.linha-dinamica').remove();
});

// Checklist da OT via AJAX
document.addEventListener('change', function (ev) {
  const cb = ev.target.closest('input[data-tarefa]');
  if (!cb) return;
  const fd = new FormData();
  fd.append('_csrf', document.querySelector('meta[name=csrf]').content);
  fd.append('tarefa_id', cb.dataset.tarefa);
  fd.append('concluida', cb.checked ? '1' : '0');
  fetch(cb.dataset.url, { method: 'POST', body: fd }).then(r => r.ok || alert('Falha ao salvar a tarefa'));
});
