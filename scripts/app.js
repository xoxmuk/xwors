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

// ---------- Загрузка изображений (реальный прогресс через XMLHttpRequest) ----------
(function () {
  var ALLOWED = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'];

  function sendFile(box, file, onProgress) {
    var xhr = new XMLHttpRequest();
    var promise = new Promise(function (resolve, reject) {
      var data = new FormData();
      data.append('csrf', box.getAttribute('data-csrf') || '');
      data.append('folder', box.getAttribute('data-folder') || '');
      data.append('file', file);
      xhr.open('POST', '/api/upload-image');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) onProgress(Math.min(99, (e.loaded / e.total) * 100));
      };
      xhr.onload = function () {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (res && res.ok) { onProgress(100); resolve(res.path); }
        else reject(new Error((res && res.error) || 'Ошибка загрузки (' + xhr.status + ').'));
      };
      xhr.onerror = function () { reject(new Error('Нет соединения с сервером.')); };
      xhr.onabort = function () { reject(new Error('abort')); };
      xhr.send(data);
    });
    return { promise: promise, abort: function () { xhr.abort(); } };
  }

  function checkFile(box, file) {
    var max = parseInt(box.getAttribute('data-max-bytes'), 10) || 0;
    if (file.type && ALLOWED.indexOf(file.type) === -1) return '«' + file.name + '»: допустимы PNG, JPG, WEBP, GIF и AVIF.';
    if (max && file.size > max) return '«' + file.name + '»: файл больше ' + (max / 1048576) + ' МБ.';
    return '';
  }

  function setError(box, message, append) {
    var el = box.parentElement.querySelector('[data-error]');
    if (!el) return;
    el.textContent = append && !el.hidden && el.textContent ? el.textContent + ' ' + message : message;
    el.hidden = !message;
  }

  // Блокируем кнопку сохранения, пока идёт загрузка
  function pending(box, delta) {
    var form = box.closest('form');
    if (!form) return;
    form._pending = (form._pending || 0) + delta;
    form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = form._pending > 0; });
  }

  function bindDrop(box, drop, fileInput, onFiles) {
    drop.addEventListener('click', function () { fileInput.click(); });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(fileInput.files);
      fileInput.value = '';
      if (files.length) onFiles(files);
    });
    box.addEventListener('dragover', function (e) { e.preventDefault(); box.classList.add('dragover'); });
    box.addEventListener('dragleave', function (e) { if (!box.contains(e.relatedTarget)) box.classList.remove('dragover'); });
    box.addEventListener('drop', function (e) {
      e.preventDefault();
      box.classList.remove('dragover');
      var files = Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []);
      if (files.length) onFiles(files);
    });
  }

  function setProgress(bar, value) { if (bar) bar.style.width = value + '%'; }

  // Одна картинка (аватарка товара)
  function initSingle(box) {
    var value = box.querySelector('[data-value]');
    var fileInput = box.querySelector('[data-file]');
    var drop = box.querySelector('[data-drop]');
    var img = box.querySelector('[data-preview]');
    var empty = box.querySelector('[data-empty]');
    var removeBtn = box.querySelector('[data-remove]');
    var bar = box.querySelector('[data-progress] i');
    var urlInput = box.parentElement.querySelector('[data-url]');
    var urlMode = !!(urlInput && urlInput.value.trim() !== '');
    var current = null;

    function render(src) {
      if (src) { img.src = src; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
      empty.hidden = !!src;
      removeBtn.hidden = !src;
      box.classList.toggle('has-image', !!src);
    }
    render(img.getAttribute('src') || '');

    function start(file) {
      setError(box, '');
      var problem = checkFile(box, file);
      if (problem) { setError(box, problem); return; }
      if (current) current.abort();
      var up = current = sendFile(box, file, function (p) { setProgress(bar, p); });
      box.classList.add('uploading');
      setProgress(bar, 0);
      pending(box, 1);
      up.promise.then(function (path) {
        value.value = path;
        render(path);
        if (urlInput) urlInput.value = '';
        urlMode = false;
      }).catch(function (err) {
        if (err.message !== 'abort') setError(box, err.message);
      }).then(function () {
        if (current === up) current = null;
        box.classList.remove('uploading');
        setTimeout(function () { if (!box.classList.contains('uploading')) setProgress(bar, 0); }, 250);
        pending(box, -1);
      });
    }

    bindDrop(box, drop, fileInput, function (files) { start(files[0]); });

    removeBtn.addEventListener('click', function () {
      if (current) current.abort();
      value.value = '';
      render('');
      if (urlInput) urlInput.value = '';
      urlMode = false;
      setError(box, '');
    });

    if (urlInput) urlInput.addEventListener('input', function () {
      var v = urlInput.value.trim();
      if (v === '') {
        if (urlMode) { value.value = ''; render(''); urlMode = false; }
        return;
      }
      if (/^https?:\/\/\S+$/i.test(v)) { value.value = v; render(v); urlMode = true; }
    });
  }

  // Несколько картинок (новости): уже загруженные + новые в одном блоке
  function initMulti(box) {
    var grid = box.querySelector('[data-grid]');
    var fileInput = box.querySelector('[data-file]');
    var drop = box.querySelector('[data-drop]');
    var batch = parseInt(box.getAttribute('data-batch'), 10) || 3;
    var total = parseInt(box.getAttribute('data-total'), 10) || 12;
    var field = box.getAttribute('data-field') || 'new_image[]';

    function addTile(file) {
      var tile = document.createElement('div');
      tile.className = 'media-tile uploading';
      tile.setAttribute('data-tile', '');
      var img = document.createElement('img');
      img.alt = '';
      var progress = document.createElement('div');
      progress.className = 'upload-progress';
      var bar = document.createElement('i');
      progress.appendChild(bar);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn-secondary btn-danger media-remove';
      remove.setAttribute('data-remove-tile', '');
      remove.textContent = 'Удалить';
      tile.appendChild(img); tile.appendChild(progress); tile.appendChild(remove);
      grid.appendChild(tile);

      var up = sendFile(box, file, function (p) { setProgress(bar, p); });
      tile._abort = up.abort;
      pending(box, 1);
      up.promise.then(function (path) {
        img.src = path;
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = field; input.value = path;
        tile.appendChild(input);
        tile.classList.remove('uploading');
        tile._abort = null;
      }).catch(function (err) {
        tile.remove();
        if (err.message !== 'abort') setError(box, '«' + file.name + '»: ' + err.message, true);
      }).then(function () { pending(box, -1); });
    }

    bindDrop(box, drop, fileInput, function (files) {
      setError(box, '');
      var errors = [];
      var room = total - grid.querySelectorAll('[data-tile]').length;
      if (files.length > batch) { errors.push('За раз можно загрузить не больше ' + batch + ' файлов.'); files = files.slice(0, batch); }
      if (room <= 0) { setError(box, 'Достигнут лимит: ' + total + ' картинок.'); return; }
      if (files.length > room) { errors.push('Можно добавить ещё только ' + room + '.'); files = files.slice(0, room); }
      files.forEach(function (file) {
        var problem = checkFile(box, file);
        if (problem) errors.push(problem); else addTile(file);
      });
      if (errors.length) setError(box, errors.join(' '));
    });

    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-remove-tile]');
      if (!btn) return;
      var tile = btn.closest('[data-tile]');
      if (tile && tile._abort) tile._abort();
      if (tile) tile.remove();
      setError(box, '');
    });
  }

  document.querySelectorAll('[data-uploader]').forEach(function (box) {
    if (box.getAttribute('data-uploader') === 'single') initSingle(box); else initMulti(box);
  });

  // Файл, брошенный мимо зоны загрузки, не должен открываться в браузере
  ['dragover', 'drop'].forEach(function (name) {
    document.addEventListener(name, function (e) {
      var types = (e.dataTransfer && e.dataTransfer.types) ? Array.prototype.slice.call(e.dataTransfer.types) : [];
      if (types.indexOf('Files') !== -1 && !(e.target.closest && e.target.closest('[data-uploader]'))) e.preventDefault();
    });
  });
})();

// ---------- Меню смены статуса товара (всплывает поверх списка) ----------
(function () {
  var menu = null, trigger = null;

  function close() {
    if (menu) { menu.remove(); menu = null; }
    if (trigger) { trigger.setAttribute('aria-expanded', 'false'); trigger = null; }
  }

  function open(btn) {
    var form = document.getElementById('good-status-form');
    var source = document.getElementById('good-status-options');
    if (!form || !source) return;
    var options = JSON.parse(source.textContent);
    menu = document.createElement('div');
    menu.className = 'status-menu';
    menu.setAttribute('role', 'menu');
    options.forEach(function (option) {
      var item = document.createElement('button');
      item.type = 'button';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('aria-checked', option.key === btn.getAttribute('data-current') ? 'true' : 'false');
      item.setAttribute('data-color', option.color);
      item.appendChild(document.createElement('i'));
      item.appendChild(document.createTextNode(option.label));
      item.addEventListener('click', function () {
        if (option.key === btn.getAttribute('data-current')) { close(); return; }
        form.querySelector('[name="id"]').value = btn.getAttribute('data-id');
        form.querySelector('[name="status"]').value = option.key;
        form.submit();
      });
      menu.appendChild(item);
    });
    document.body.appendChild(menu);
    var rect = btn.getBoundingClientRect();
    var top = rect.bottom + 6;
    if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, rect.top - menu.offsetHeight - 6);
    menu.style.top = top + 'px';
    menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
    trigger = btn;
    btn.setAttribute('aria-expanded', 'true');
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-status-menu]');
    if (!btn) { if (menu && !e.target.closest('.status-menu')) close(); return; }
    if (trigger === btn) { close(); return; }
    close();
    open(btn);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', close);
  window.addEventListener('scroll', close, true);
})();

// ---------- Уведомления админки: всплывают справа сверху и исчезают сами ----------
(function () {
  var items = document.querySelectorAll('.admin-main .flash');
  if (!items.length) return;
  var box = document.createElement('div');
  box.className = 'toasts';
  box.setAttribute('role', 'status');
  document.body.appendChild(box);
  items.forEach(function (el) {
    box.appendChild(el);
    var timer = setTimeout(hide, el.classList.contains('form-error') ? 7000 : 4000);
    function hide() {
      clearTimeout(timer);
      el.classList.add('leaving');
      setTimeout(function () { el.remove(); }, 300);
    }
    el.addEventListener('click', hide);
  });
  // Убираем служебные параметры, чтобы после обновления страницы уведомление не появилось снова
  try {
    var url = new URL(window.location.href);
    ['saved', 'deleted', 'toggled', 'created', 'error', 'enabled', 'disabled'].forEach(function (k) { url.searchParams.delete(k); });
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
  } catch (err) {}
})();

// ---------- Кнопка «+ Tooltip» в описании новости ----------
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-insert-tooltip]');
  if (!btn) return;
  var field = btn.closest('.field');
  var area = field && field.querySelector('textarea');
  if (!area) return;
  var start = area.selectionStart, end = area.selectionEnd;
  var text = area.value.slice(start, end) || 'текст';
  var open = '<span class="tooltip" data-tooltip="';
  area.setRangeText(open + '">' + text + '</span>', start, end, 'end');
  area.focus();
  var caret = start + open.length;
  area.setSelectionRange(caret, caret);
});

