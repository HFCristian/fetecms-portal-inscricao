/**
 * Máscaras e toggles compartilhados — telas de cadastro FETECMS
 */
(function () {
  function maskCpf(el) {
    if (!el) return;
    el.addEventListener('input', function (e) {
      let v = e.target.value.replace(/\D/g, '').slice(0, 11);
      if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
      else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
      else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
      e.target.value = v;
    });
  }

  function maskPhone(el) {
    if (!el) return;
    el.addEventListener('input', function (e) {
      let v = e.target.value.replace(/\D/g, '').slice(0, 11);
      if (v.length <= 2) e.target.value = v ? '(' + v : '';
      else if (v.length <= 7) e.target.value = '(' + v.slice(0, 2) + ') ' + v.slice(2);
      else if (v.length <= 10) e.target.value = '(' + v.slice(0, 2) + ') ' + v.slice(2, 6) + '-' + v.slice(6);
      else e.target.value = '(' + v.slice(0, 2) + ') ' + v.slice(2, 7) + '-' + v.slice(7);
    });
  }

  function maskCep(el) {
    if (!el) return;
    el.addEventListener('input', function (e) {
      let v = e.target.value.replace(/\D/g, '').slice(0, 8);
      if (v.length > 5) v = v.replace(/(\d{5})(\d{1,3})/, '$1-$2');
      e.target.value = v;
    });
  }

  function togglePassword(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      const input = btn.closest('.relative')?.querySelector('input[type="password"], input[type="text"]');
      const icon = btn.querySelector('.material-symbols-outlined');
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      if (icon) icon.textContent = show ? 'visibility' : 'visibility_off';
    });
  }

  document.querySelectorAll('#cpf, [name="cpf"], [id^="cpf"]').forEach(maskCpf);
  document.querySelectorAll('#telefone, [name="telefone"], [id^="telefone"]').forEach(maskPhone);
  document.querySelectorAll('#cep, [name="cep"]').forEach(maskCep);
  document.querySelectorAll('[data-toggle-password]').forEach(togglePassword);
  document.querySelectorAll('button[type="button"] .material-symbols-outlined').forEach(function (icon) {
    if (icon.textContent.trim() === 'visibility_off') {
      togglePassword(icon.closest('button'));
    }
  });
})();
