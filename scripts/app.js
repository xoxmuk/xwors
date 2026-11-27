document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-copy]');
  if (!btn) return;
  var el = document.querySelector(btn.getAttribute('data-copy'));
  if (!el || !navigator.clipboard) return;
  navigator.clipboard.writeText(el.textContent.trim()).then(function () {
    var old = btn.textContent; btn.textContent = 'Скопировано'; setTimeout(function () { btn.textContent = old; }, 1500);
  });
});

document.addEventListener('dragstart', function (e) {
  var target = e.target;
  if (target && target.closest && target.closest('img, picture, video, canvas, svg, a')) e.preventDefault();
});

document.querySelectorAll('[data-qr]').forEach(function (el) {
  var value = el.getAttribute('data-qr');
  if (!value || !window.QRCode) return;
  new QRCode(el, { text: value, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
  el.removeAttribute('title');
});

document.addEventListener('click', function (e) {
  var addButton = e.target.closest('[data-add-block]');
  if (addButton) { var template = document.getElementById('news-block-template'); var list = document.querySelector('[data-blocks]'); if (template && list) list.appendChild(template.content.cloneNode(true)); return; }
  var removeButton = e.target.closest('[data-remove-block]');
  if (removeButton) { var block = removeButton.closest('[data-block]'); if (!block) return; var blocks = block.parentElement.querySelectorAll('[data-block]'); if (blocks.length > 1) block.remove(); else block.querySelectorAll('input, textarea').forEach(function (field) { field.value = ''; }); }
});

// Lock content forms synchronously on the first submit; leave them locked while the browser waits for the server response.
document.addEventListener('submit', function (e) {
  var form = e.target;
  var message = form.getAttribute('data-confirm');
  if (message && !window.confirm(message)) { e.preventDefault(); return; }
  if (!form.matches('[data-submit-lock]')) return;
  if (form.dataset.submitting === '1') { e.preventDefault(); return; }
  form.dataset.submitting = '1';
  form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach(function (button) {
    button.disabled = true;
    if (button.tagName === 'BUTTON' && !button.closest('.status-menu')) button.textContent = 'Сохранение…';
  });
  form.querySelectorAll('[data-upload-progress]').forEach(function (bar) { bar.hidden = false; });
  var title = form.querySelector('[data-drop-title]'); if (title) title.textContent = 'Загрузка изображения…';
});

// A polished, keyboard-accessible file drop zone with live previews.
document.querySelectorAll('[data-drop-zone]').forEach(function (zone) {
  var form = zone.closest('form'); var input = form && form.querySelector('[data-image-input]'); if (!input) return;
  var depth = 0;
  function isImage(file) { return file && /^image\/(png|jpeg|gif|webp|avif)$/i.test(file.type) && file.size <= (input.name === 'images[]' ? 1024 * 1024 : 2 * 1024 * 1024); }
  function setFiles(files) {
    var picked = Array.prototype.slice.call(files).filter(isImage); if (input.multiple) picked = picked.slice(0, 3); if (!picked.length) return;
    var transfer = new DataTransfer(); picked.forEach(function (file) { transfer.items.add(file); }); input.files = transfer.files;
    zone.classList.add('has-files'); var title = zone.querySelector('[data-drop-title]'); if (title) title.textContent = picked.length === 1 ? picked[0].name : picked.length + ' изображений выбрано';
    var preview = form.querySelector('[data-product-preview]');
    if (preview && picked[0]) { var old = preview.querySelector('img'); if (old) old.remove(); var placeholder = preview.querySelector('[data-preview-placeholder]'); if (placeholder) placeholder.remove(); var img = document.createElement('img'); img.alt = 'Предпросмотр товара'; img.dataset.previewImage = '1'; img.src = URL.createObjectURL(picked[0]); preview.appendChild(img); preview.classList.remove('is-empty'); }
    var grid = form.querySelector('[data-selected-images]');
    if (grid) { grid.replaceChildren(); picked.forEach(function (file) { var card = document.createElement('div'); card.className = 'selected-image'; var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(file); var name = document.createElement('span'); name.textContent = file.name; card.append(img, name); grid.appendChild(card); }); }
    var remove = form.querySelector('[data-remove-image]'); if (remove) remove.checked = false;
  }
  input.addEventListener('change', function () { setFiles(input.files); });
  zone.addEventListener('dragenter', function (e) { e.preventDefault(); depth++; zone.classList.add('is-dragover'); });
  zone.addEventListener('dragover', function (e) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'; });
  zone.addEventListener('dragleave', function (e) { e.preventDefault(); depth = Math.max(0, depth - 1); if (!depth) zone.classList.remove('is-dragover'); });
  zone.addEventListener('drop', function (e) { e.preventDefault(); depth = 0; zone.classList.remove('is-dragover'); setFiles(e.dataTransfer.files); });
});

document.addEventListener('click', function (e) {
  var summary = e.target.closest('.status-menu summary');
  if (summary) document.querySelectorAll('.status-menu[open]').forEach(function (menu) { if (menu !== summary.parentElement) menu.removeAttribute('open'); });
  if (!e.target.closest('.status-menu')) document.querySelectorAll('.status-menu[open]').forEach(function (menu) { menu.removeAttribute('open'); });
});
