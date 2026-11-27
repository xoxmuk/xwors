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

// Запрещаем перетаскивание картинок, видео и ссылок (в т.ч. в Firefox)
document.addEventListener('dragstart', function (e) {
  var target = e.target;
  if (target && target.closest && target.closest('img, picture, video, canvas, svg, a')) {
    e.preventDefault();
  }
});

// QR-код для привязки аутентификатора (генерируется локально в браузере)
document.querySelectorAll('[data-qr]').forEach(function (el) {
  var value = el.getAttribute('data-qr');
  if (!value || !window.QRCode) return;
  new QRCode(el, {
    text: value,
    width: 200,
    height: 200,
    correctLevel: QRCode.CorrectLevel.M
  });
  el.removeAttribute('title');
});

// Динамические блоки новости
document.addEventListener('click', function (e) {
  var addButton = e.target.closest('[data-add-block]');
  if (addButton) {
    var template = document.getElementById('news-block-template');
    var list = document.querySelector('[data-blocks]');
    if (template && list) list.appendChild(template.content.cloneNode(true));
    return;
  }

  var removeButton = e.target.closest('[data-remove-block]');
  if (removeButton) {
    var block = removeButton.closest('[data-block]');
    if (!block) return;
    var blocks = block.parentElement.querySelectorAll('[data-block]');
    if (blocks.length > 1) {
      block.remove();
    } else {
      block.querySelectorAll('input, textarea').forEach(function (field) { field.value = ''; });
    }
  }
});

// Подтверждение опасных действий (удаление и т.п.)
document.addEventListener('submit', function (e) {
  var message = e.target.getAttribute('data-confirm');
  if (message && !window.confirm(message)) e.preventDefault();
});
