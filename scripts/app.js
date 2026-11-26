document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-copy]');
  if (!btn) return;
  var el = document.querySelector(btn.getAttribute('data-copy'));
  if (!el || !navigator.clipboard) return;
  navigator.clipboard.writeText(el.textContent.trim()).then(function () {
    var old = btn.textContent;
    btn.textContent = 'Скопировано';
    setTimeout(function () { btn.textContent = old; }, 1500);
  });
});
