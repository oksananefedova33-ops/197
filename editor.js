/* ===== ZERRO EDITOR JS - ADAPTIVE VERSION (FIXED) ===== */
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const stage = $('#stage');
const EDITOR_BASE_H = window.EDITOR_BASE_H || 1500; // должна совпадать с $EDITOR_H в index.php


let currentPageId = 0;
Object.defineProperty(window, "currentPageId", { 
  get() { return currentPageId; }, 
  set(v) { currentPageId = v; } 
});

let selected = null;
let meta = { title: '', description: '' };

// === АДАПТИВНАЯ СИСТЕМА ===
let currentDevice = 'desktop';
let deviceData = {
  desktop: { elements: [] },
  tablet: { elements: [] },
  mobile: { elements: [] }
};

// Хранилище для временных позиций при переключении
let tempPositions = {};

function setDevice(m) {
  // Сохраняем текущие позиции перед переключением
  if (currentDevice) {
    saveCurrentDeviceData();

    // Новое: зеркалим новые элементы/изменения из текущего профиля во все профили,
    // чтобы на первом переключении элемент уже был в целевом устройстве
    (['desktop','tablet','mobile']).forEach(function(dev){
      if (!deviceData[dev]) deviceData[dev] = { elements: [] };
      if (!Array.isArray(deviceData[dev].elements)) deviceData[dev].elements = [];
    });

    const srcArr = (deviceData[currentDevice] && deviceData[currentDevice].elements)
      ? deviceData[currentDevice].elements : [];

    ['desktop','tablet','mobile'].forEach(function(dev){
      if (dev === currentDevice) return;
      const destArr = deviceData[dev].elements;

      srcArr.forEach(function(item){
        // если такого ID ещё нет — добавляем «тень» элемента
        if (!destArr.find(function(e){ return e.id === item.id; })) {
          destArr.push({ ...item });
        } else if (item.type === 'text') {
          // если есть, но пустой html — подхватим html из источника
          const d = destArr.find(function(e){ return e.id === item.id; });
          if (d && (!d.html || d.html === '')) { d.html = item.html || d.html || ''; }
        }
      });
    });

    // Цвета/HTML с desktop — как и раньше
    if (currentDevice === 'desktop') {
      syncElementColors();
    }
  }
  
  // Устанавливаем размер сцены
  const sizes = {
    desktop: 1200,
    tablet: 768,
    mobile: 375
  };
  stage.style.width = (sizes[m] || 1200) + 'px';
  
  // Убираем все старые классы
  stage.className = 'stage editing-' + m;
  // Сбрасываем инлайн-высоту сцены, чтобы применились CSS calc()* правила для высоты,
  // которые имитируют фронт (vw-скейлинг) — но только если пользователь не менял высоту вручную
  if (!window.stageUserResized) {
    stage.style.height = '';
    stage.style.minHeight = '';
    window.stageAutoGrownOnce = false;
  }

  
  // Подсвечиваем активную кнопку
  document.querySelectorAll('[data-device]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.device === m);
  });
  
  // Меняем устройство
  currentDevice = m;

  // Если впервые открываем tablet/mobile — скопировать элементы с desktop
  if (m !== 'desktop'
      && deviceData[m] && Array.isArray(deviceData[m].elements) && deviceData[m].elements.length === 0
      && deviceData.desktop && Array.isArray(deviceData.desktop.elements) && deviceData.desktop.elements.length > 0) {
    deviceData[m].elements = deviceData.desktop.elements.map(e => ({...e}));
  }
  
  // Загружаем данные для нового устройства
  loadDeviceData(m);
  
  // Показываем индикатор
  showDeviceIndicator(m);
}

function saveCurrentDeviceData() {
  const elements = [];
  document.querySelectorAll('#stage .el').forEach(el => {
    elements.push(gatherElementData(el));
  });
  deviceData[currentDevice] = { elements };
}

function loadDeviceData(device) {
  stage.innerHTML = '';
  
  if (deviceData[device] && deviceData[device].elements) {
    deviceData[device].elements.forEach(item => {
      // ВАЖНО: Убеждаемся, что элемент существует во всех устройствах
      // Для новых элементов используем цвета из desktop версии если она существует
      const desktopElem = (deviceData.desktop && deviceData.desktop.elements) ? 
      deviceData.desktop.elements.find(e => e.id === item.id) : null;
        const sourceItem = (desktopElem && device !== 'desktop') ? 
      {
    ...item,
    bg: desktopElem.bg || item.bg,
    color: desktopElem.color || item.color,
    border: desktopElem.border || item.border,
    ...(item.type === 'text' ? { html: (item.html || desktopElem.html || '') } : {})
     } : 
      item;

      
      // Создаем элемент с правильными цветами
      const el = createElement(item.type, sourceItem);

      if (!desktopElem && device !== 'desktop') {
        deviceData.desktop.elements.push({...item});
      }
      if (!deviceData.tablet.elements.find(e => e.id === item.id)) {
        deviceData.tablet.elements.push({...sourceItem});
      }
      if (!deviceData.mobile.elements.find(e => e.id === item.id)) {
        deviceData.mobile.elements.push({...sourceItem});
      }
    });
  }
}

function gatherElementData(el) {
  const t = el.dataset.type;

// размеры холста для пересчёта
const sw = stage?.clientWidth  || stage?.offsetWidth  || 1000;
const sh = stage?.clientHeight || stage?.offsetHeight || 1000;

// исходные CSS-строки ширины/высоты
const wcss = (el.style.width  || '').trim();
const hcss = (el.style.height || '').trim();

// числовые значения
let w = parseFloat(wcss) || 0;
let h = parseFloat(hcss) || 0;

// если элемент имел размеры в px — преобразуем в проценты,
// чтобы после сохранения и на фронте размер остался тем же
if (wcss.endsWith('px') && sw > 0) w = +((w / sw) * 100).toFixed(2);
if (hcss.endsWith('px') && sh > 0) h = +((h / sh) * 100).toFixed(2);

const base = {
  id: el.dataset.id,
  type: t,
  left: parseFloat(el.style.left) || 0,  // уже в %
  top: parseInt(el.style.top) || 0,      // px
  width: w,                               // всегда в %
  height: h,                              // всегда в %
  z: parseInt(el.style.zIndex || 1, 10),
  radius: parseInt(el.style.borderRadius || 0, 10),
  rotate: parseFloat(el.style.rotate || '0')
};

  
  if (t === 'text') {
    const wrapper = el.querySelector('.text-content-wrapper');
    base.html = el.dataset.html || (wrapper ? wrapper.innerHTML : el.innerHTML) || '';
    base.fontSize = parseInt(el.style.fontSize || 20, 10);
    base.color = el.style.color || '#e8f2ff';
    base.bg = el.style.background || '';
  
    base.noScroll = (el.dataset.noScroll === '1');
} else if (t === 'box') {
    base.bg = el.style.background || '';
    base.border = el.style.border || '';
  } else if (t === 'image') {
    const img = el.querySelector('img');
    base.src = img?.src || '';
    if (el.dataset.html) base.html = el.dataset.html;
  } else if (t === 'video') {
    const v = el.querySelector('video');
    base.src = v?.src || '';
    base.controls = v ? !!v.controls : true;
    base.autoplay = !!(v && v.autoplay);
    base.loop = !!(v && v.loop);
    base.muted = !!(v && v.muted);
    if (el.dataset.html) base.html = el.dataset.html;
  } else if (t === 'linkbtn') {
    const a = el.querySelector('a');
    const cs = a ? getComputedStyle(a) : null;

    const varBg    = cs ? (cs.getPropertyValue('--bl-bg') || '').trim() : '';
    const varColor = cs ? (cs.getPropertyValue('--bl-color') || '').trim() : '';
    const inlineBg    = a && a.style.background ? a.style.background : '';
    const inlineColor = a && a.style.color      ? a.style.color      : '';

    base.text  = (el.dataset.text || a?.textContent || 'Кнопка').replace(/[📄📦📕📘📗📙🎵🎬🖼️💻💿📝]/g,'');
    base.url   = (el.dataset.url || a?.getAttribute('href') || '#').replace(/^['"]|['"]$/g, '');
    base.bg    = el.dataset.bg    || varBg    || inlineBg    || '#3b82f6';
base.color = el.dataset.color || varColor || inlineColor || '#ffffff';
base.anim  = (a?.dataset?.anim || el.dataset.anim || 'none');

  } else if (t === 'filebtn') {
    const a = el.querySelector('a');
    const cs = a ? getComputedStyle(a) : null;

    const varBg    = cs ? (cs.getPropertyValue('--bf-bg') || '').trim() : '';
    const varColor = cs ? (cs.getPropertyValue('--bf-color') || '').trim() : '';
    const inlineBg    = a && a.style.background ? a.style.background : '';
    const inlineColor = a && a.style.color      ? a.style.color      : '';

    base.text     = el.dataset.text || (a?.textContent ? a.textContent.replace(/^\s*📄\s*/, '') : 'Скачать файл');
    base.fileUrl  = el.dataset.fileUrl  || a?.getAttribute('href')     || '#';
    base.fileName = el.dataset.fileName || a?.getAttribute('download') || '';
    base.bg       = el.dataset.bg    || varBg    || inlineBg    || '#10b981';
base.color    = el.dataset.color || varColor || inlineColor || '#ffffff';
base.anim     = (a?.dataset?.anim || el.dataset.anim || 'none');

  }
  
  return base;
}

function showDeviceIndicator(device) {
  let indicator = document.querySelector('.device-indicator');
  if (!indicator) {
    indicator = document.createElement('div');
    indicator.className = 'device-indicator';
    document.body.appendChild(indicator);
  }
  
  const labels = {
    desktop: '🖥️ Редактирование Desktop версии',
    tablet: '📱 Редактирование Tablet версии',
    mobile: '📱 Редактирование Mobile версии'
  };
  
  indicator.textContent = labels[device];
  indicator.style.display = 'block';
  
  // Автоматически скрываем через 2 секунды
  setTimeout(() => {
    if (indicator) {
      indicator.style.display = 'none';
    }
  }, 2000);
}

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
function clamp(n, min, max) { 
  return Math.max(min, Math.min(max, n)); 
}

function pxToPercent(px, ref) { 
  return (px / ref) * 100; 
}

function percentToPx(p, ref) { 
  return (p / 100) * ref; 
}

function uid() { 
  return 'el_' + Math.random().toString(36).slice(2, 9); 
}

function toHexColor(c, fb = '#000000') {
  if (!c) return fb;
  c = String(c).trim();
  if (c.startsWith('#')) {
    if (c.length === 4) return '#' + c.slice(1).split('').map(x => x + x).join('');
    return c;
  }
  const m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
  if (m) {
    const [r, g, b] = [m[1], m[2], m[3]].map(v => ('0' + (+v).toString(16)).slice(-2));
    return '#' + r + g + b;
  }
  return fb;
}

// === ЭЛЕМЕНТЫ И ИХ УПРАВЛЕНИЕ ===
function ensureTools(el) {
  if (el.querySelector('.el-tools')) return;
  const bar = document.createElement('div');
  bar.className = 'el-tools';
  const del = document.createElement('button');
  del.className = 'tool del';
  del.title = 'Удалить';
  del.addEventListener('click', async (ev) => {
    ev.stopPropagation();
    ev.preventDefault();
    if (selected === el) selected = null;

    // Собираем связанные локальные файлы (только из /editor/uploads)
    const urls = [];
    const push = (u) => {
      if (!u) return;
      try {
        const p = new URL(u, location.origin).pathname;
        if (p.startsWith('/editor/uploads/')) urls.push(p);
      } catch(e) {}
    };

    const type = (el.dataset.type || '').toLowerCase();
    if (type === 'image') {
      const img = el.querySelector('img');
      push(img?.src || '');
      if (el.dataset.html) {
        (el.dataset.html.match(/(?:src|href)=["']([^"']+)/gi) || [])
          .forEach(m => push(m.split(/=["']/)[1]));
      }
    } else if (type === 'video') {
      const v = el.querySelector('video');
      push(v?.src || '');
      if (el.dataset.html) {
        (el.dataset.html.match(/(?:src|href)=["']([^"']+)/gi) || [])
          .forEach(m => push(m.split(/=["']/)[1]));
      }
    } else if (type === 'filebtn') {
      const a = el.querySelector('a');
      push(a?.getAttribute('href') || '');
    }

    // Удаляем элемент из всех устройств
    const elementId = el.dataset.id;
    ['desktop', 'tablet', 'mobile'].forEach(device => {
      if (deviceData[device].elements) {
        deviceData[device].elements = deviceData[device].elements.filter(e => e.id !== elementId);
      }
    });
    
    el.remove();
    adjustStageHeight();
    renderProps(null);

    // Сообщаем API удалить файлы физически
    if (urls.length) {
      const fd = new FormData();
      urls.forEach(u => fd.append('urls[]', u));
      try {
        await fetch('/editor/api.php?action=deleteUploadsByUrls', { method: 'POST', body: fd, cache: 'no-store' });
      } catch(e) { /* игнор, чтобы не ломать UX */ }
    }
  });
  bar.appendChild(del);
  el.appendChild(bar);
}

function ensureHandle(el) {
  if (!el.querySelector('.handle')) {
    // Создаем 8 handles для всех направлений
    const positions = [
      'handle-nw', 'handle-n', 'handle-ne',
      'handle-w', 'handle-e',
      'handle-sw', 'handle-s', 'handle-se'
    ];
    
    positions.forEach(pos => {
      const h = document.createElement('div');
      h.className = 'handle ' + pos;
      h.dataset.direction = pos.replace('handle-', '');
      el.appendChild(h);
    });
  }
}

function selectEl(el) {
  if (selected) selected.classList.remove('selected');
  selected = el;
  if (selected) {
    selected.classList.add('selected');
// Зафиксировать текущие SEO-поля до перерисовки панели
const st = document.querySelector('#seoTitle');
const sd = document.querySelector('#seoDesc');
if (st) meta.title = st.value || '';
if (sd) meta.description = sd.value || '';
renderProps(selected);

  } else {
    renderProps(null);
  }
}

function attachDragResize(el) {
  el.style.cursor = 'move';
  
  let dragging = false, resizing = false;
  let startX = 0, startY = 0;
  let startLeft = 0, startTop = 0;
  let startW = 0, startH = 0;
  
  // Drag functionality
  el.addEventListener('pointerdown', e => {
  if (e.target.closest('.el-tools')) return;
  if (e.target.classList.contains('handle')) return;
  if (el.contentEditable === 'true' || el.contentEditable === true) return; // Улучшенная проверка
  
  selectEl(el);
  dragging = true;
  el.setPointerCapture(e.pointerId);
    
    const rect = stage.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    startLeft = percentToPx(parseFloat(el.style.left || '0'), rect.width);
    startTop = parseInt(el.style.top || '0');
  });
  
  el.addEventListener('pointermove', e => {
    if (!dragging) return;
    
    const rect = stage.getBoundingClientRect();
    let dx = e.clientX - startX;
    let dy = e.clientY - startY;
    let nl = startLeft + dx;
    let nt = startTop + dy;
    
    nl = clamp(nl, 0, rect.width - el.getBoundingClientRect().width);
    nt = clamp(nt, 0, rect.height - el.getBoundingClientRect().height);
    
    el.style.left = pxToPercent(nl, rect.width) + '%';
    el.style.top = nt + 'px';
    adjustStageHeight();
  });
  
  el.addEventListener('pointerup', () => {
    dragging = false;
  });
  
  // Resize functionality  
  const handles = el.querySelectorAll('.handle');
  handles.forEach(handle => {
    handle.addEventListener('pointerdown', e => {
      selectEl(el);
      resizing = true;
      const direction = handle.dataset.direction;
      handle.setPointerCapture(e.pointerId);
      
      // УДАЛЯЕМ фиксацию высоты для текстовых элементов - пусть остается auto
      // Текстовые элементы не должны ресайзиться по высоте
      
      const rect = stage.getBoundingClientRect();
      const elRect = el.getBoundingClientRect();
      startX = e.clientX;
      startY = e.clientY;

      const wcss = (el.style.width  || '').trim();
      const hcss = (el.style.height || '').trim();
      const lcss = (el.style.left   || '').trim();

      // Начальные значения ДОЛЖНЫ быть в пикселях
      if (wcss.endsWith('%')) {
        startW = percentToPx(parseFloat(wcss) || 0, rect.width);
      } else {
        startW = parseFloat(wcss) || 0;
      }

      if (hcss.endsWith('%')) {
        startH = percentToPx(
          parseFloat(hcss) || 0,
          stage.classList.contains('editing-mobile') ? EDITOR_BASE_H : rect.height
        );
      } else {
        startH = parseFloat(hcss) || 0;
      }

      if (lcss.endsWith('%')) {
        startLeft = percentToPx(parseFloat(lcss) || 0, rect.width);
      } else {
        startLeft = parseFloat(lcss) || 0;
      }

      startTop = parseInt(el.style.top || '0');
      
      e.stopPropagation();
      
      const moveHandler = e => {
        if (!resizing) return;
        
        const rect = stage.getBoundingClientRect();
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        
        let newLeft = startLeft;
        let newTop = startTop;
        let newWidth = startW;
        let newHeight = startH;
        
        // Обработка разных направлений
        if (direction.includes('w')) {
          newWidth = startW - dx;
          newLeft = startLeft + dx;
          if (newWidth < 40) {
            newLeft = startLeft + startW - 40;
            newWidth = 40;
          }
        }
        if (direction.includes('e')) {
          newWidth = startW + dx;
          if (newWidth < 40) newWidth = 40;
        }
        // Разрешаем изменение высоты для всех элементов включая текст
        if (direction.includes('n')) {
          newHeight = startH - dy;
          newTop = startTop + dy;
          if (newHeight < 40) {
            newTop = startTop + startH - 40;
            newHeight = 40;
          }
        }
        if (direction.includes('s')) {
          newHeight = startH + dy;
          if (newHeight < 40) newHeight = 40;
        }
        
        // Применение ограничений
        newLeft = clamp(newLeft, 0, rect.width - 40);
        newTop = clamp(newTop, 0, rect.height - 40);
        newWidth = clamp(newWidth, 40, rect.width - newLeft);
        newHeight = clamp(newHeight, 40, rect.height - newTop);
        
        el.style.left = pxToPercent(newLeft, rect.width) + '%';
        el.style.top = newTop + 'px';
        el.style.width = pxToPercent(newWidth, rect.width) + '%';
        // Устанавливаем высоту для всех элементов включая текст
        el.style.height = pxToPercent(
  newHeight,
  stage.classList.contains('editing-mobile') ? EDITOR_BASE_H : rect.height
) + '%';
      };
      
      handle.addEventListener('pointermove', moveHandler);
      handle.addEventListener('pointerup', () => {
        resizing = false;
        handle.removeEventListener('pointermove', moveHandler);
      });
    });
  });
}

function createElement(type, opts = {}) {
  const el = document.createElement('div');
  el.className = 'el ' + type;
  el.dataset.type = type;
  el.dataset.id = opts.id || uid();
  
    el.style.left = (opts.left ?? 10) + '%';
  el.style.top  = (opts.top  ?? 50) + 'px';

  // ВАЖНО: если размеры не переданы — даём маленькие значения в пикселях,
  // чтобы новые элементы НЕ зависели от высоты холста.
  if (opts.width == null) {
    // компактные дефолты (можете подправить под себя)
    const defaultWidthPx =
      (type === 'linkbtn' || type === 'filebtn' || type === 'button') ? 260
      : (type === 'text') ? 320
      : 300;
    el.style.width = defaultWidthPx + 'px';
  } else {
    // Для уже сохранённых элементов сохраняем старую семантику (%)
    el.style.width = opts.width + '%';
  }

  if (opts.height == null) {
    // компактные дефолты по высоте в пикселях
    const defaultHeightPx =
      (type === 'linkbtn' || type === 'filebtn' || type === 'button') ? 68
      : (type === 'text') ? 120   // текст теперь имеет высоту как другие элементы
      : 180;
    el.style.height = defaultHeightPx + 'px';
  } else {
    el.style.height = (opts.height ?? 25) + '%';
  }

  el.style.zIndex = (opts.z ?? 1);
  el.style.borderRadius = (opts.radius ?? 8) + 'px';
  el.style.overflow = ''; // не обрезаем
  el.style.rotate = (opts.rotate ?? 0) + 'deg';

  
  if (type === 'text') {
  // Создаем wrapper для текстового контента
  const wrapper = document.createElement('div');
  wrapper.className = 'text-content-wrapper';
  wrapper.contentEditable = 'false';
  
  let html;
  if (opts.html != null) {
    html = String(opts.html);
  } else {
    html = String(opts.text ?? 'Дважды кликните и\nредактируйте текст').replace(/\n/g,'<br>');
  }
  el.dataset.html = html;
  wrapper.innerHTML = html;
  
  // Стили применяем к el (они наследуются wrapper'ом)
  el.style.fontSize = (opts.fontSize ?? 20) + 'px';
  el.style.color = opts.color ?? '#e8f2ff';
  el.style.background = opts.bg ?? 'transparent';
  el.style.minHeight = '80px';
  
  el.appendChild(wrapper);
  
  
  if (opts.noScroll) { el.dataset.noScroll = '1'; }
// Обработчики на wrapper для редактирования
  wrapper.addEventListener('dblclick', () => {
    wrapper.setAttribute('contenteditable', 'true');
    wrapper.classList.add('editing');
    wrapper.focus();
  });
  
  wrapper.addEventListener('blur', () => {
    if (wrapper.classList.contains('editing')) {
      wrapper.classList.remove('editing');
      wrapper.setAttribute('contenteditable', 'false');
      el.dataset.html = wrapper.innerHTML; // сохраняем в dataset родителя
    }
  });

  // После изменения текста обновляем ручки и корзину
  wrapper.addEventListener('input', () => {
    if (typeof selectEl === 'function') {
      requestAnimationFrame(() => { 
        try { 
          selectEl(el); // выделяем родительский el
          ensureTools(el);
          ensureHandle(el);
        } catch(e){} 
      });
    }
    // авто-подгон высоты сцены
    try { adjustStageHeight('text-input'); } catch(e){}
  });
  
  } else if (type === 'box') {
    el.style.background = opts.bg ?? 'rgba(95,179,255,0.12)';
    el.style.border = opts.border ?? '1px solid rgba(95,179,255,0.35)';
  } else if (type === 'image') {
    if (opts.html) {
      el.dataset.html = opts.html;
      el.innerHTML = opts.html;
    } else {
      const img = document.createElement('img');
      img.src = opts.src ?? 'https://picsum.photos/800/600';
      el.appendChild(img);
    }
  } else if (type === 'video') {
    if (opts.html) {
      el.dataset.html = opts.html;
      el.innerHTML = opts.html;
    } else {
      const v = document.createElement('video');
      v.src = opts.src || '';
      v.controls = (opts.controls !== false);
      v.autoplay = !!opts.autoplay;
      v.loop = !!opts.loop;
      v.muted = !!opts.muted;
      el.appendChild(v);
    }
  } else if (type === 'linkbtn') {
    el.dataset.text = opts.text || 'Кнопка';
    el.dataset.url = opts.url || '#';
    el.dataset.bg = opts.bg || '#3b82f6';
    el.dataset.color = opts.color || '#ffffff';
    el.dataset.anim = opts.anim || 'none';
    const a = document.createElement('a');
    a.href = el.dataset.url;
    a.target = '_blank';
    a.textContent = el.dataset.text;
    a.style.display = 'flex';
    a.style.alignItems = 'center';
    a.style.justifyContent = 'center';
    a.style.width = '100%';
    a.style.height = '100%';
    a.style.background = opts.bg || '#3b82f6';  // Используем opts.bg напрямую
    a.style.color = opts.color || '#ffffff';    // Используем opts.color напрямую
    a.style.textDecoration = 'none';
    a.style.borderRadius = 'inherit';
    a.style.fontWeight = '600';
    el.appendChild(a);
  } else if (type === 'filebtn') {
    el.dataset.text = opts.text || 'Скачать файл';
    el.dataset.fileUrl = opts.fileUrl || '#';
    el.dataset.fileName = opts.fileName || '';
    el.dataset.bg = opts.bg || '#10b981';
    el.dataset.color = opts.color || '#ffffff';
    el.dataset.anim = opts.anim || 'none';  // ← добавили, чтобы anim не терялся при сохранении

    const a = document.createElement('a');
    a.href = el.dataset.fileUrl;
    a.download = el.dataset.fileName;
    a.target = '_blank';
    a.textContent = el.dataset.text;
    a.style.display = 'flex';
    a.style.alignItems = 'center';
    a.style.justifyContent = 'center';
    a.style.width = '100%';
    a.style.height = '100%';
    a.style.background = opts.bg || '#10b981';  // Используем opts.bg напрямую
    a.style.color = opts.color || '#ffffff';    // Используем opts.color напрямую
    a.style.textDecoration = 'none';
    a.style.borderRadius = 'inherit';
    a.style.fontWeight = '600';
    el.appendChild(a);
  }
  
  ensureTools(el);
  ensureHandle(el);
  stage.appendChild(el);
  attachDragResize(el);
  // сразу подсветить добавленный элемент
  requestAnimationFrame(() => { try { selectEl(el); } catch(_){} });
  
  return el;
}

// Продолжение следует в следующем сообщении из-за ограничения длины...
// === ПАНЕЛЬ СВОЙСТВ ===
function buildPalette(id) {
  const colors = [
    '#ffffff', '#000000', '#e74c3c', '#e67e22', '#f1c40f',
    '#2ecc71', '#1abc9c', '#3498db', '#9b59b6', '#bdc3c7',
    '#95a5a6', '#34495e', '#ff6b6b', '#ffa94d', '#ffe066',
    '#69db7c', '#40c057', '#15aabf', '#74c0fc', '#b197fc'
  ];
  return '<div class="palette" data-target="' + id + '">' +
    colors.map(c => '<div class="sw" data-color="' + c + '" style="background:' + c + '"></div>').join('') +
    '</div>';
}

function renderProps(el) {
  const htmlSEO = `
    <div class="row">
      <div>
        <div class="label">SEO Title <span id="seoTitleCnt">0</span></div>
        <input type="text" id="seoTitle" value="${(meta.title || '').replace(/"/g, '&quot;')}">
      </div>
      <div>
        <div class="label">SEO Description <span id="seoDescCnt">0</span></div>
        <input type="text" id="seoDesc" value="${(meta.description || '').replace(/"/g, '&quot;')}">
      </div>
    </div>
    <div class="label" style="margin-top:-4px">Рекомендации: Title 50–60 символов, Description 140–160.</div>
  `;
  
  const deviceInfo = `
    <div style="background:#1a2533;padding:8px;border-radius:8px;margin-bottom:10px;text-align:center;color:#2ea8ff;font-weight:bold">
      Режим: ${currentDevice.toUpperCase()}
    </div>
  `;
  
  if (!el) {
    $('#props').innerHTML = deviceInfo + htmlSEO;
    bindSeoHandlers();
    return;
  }
  
  const t = el.dataset.type;
  const left = parseFloat(el.style.left) || 0;
  const top = parseFloat(el.style.top) || 0;
  const w = parseFloat(el.style.width) || 0;
  const h = parseFloat(el.style.height) || 0;
  const z = parseInt(el.style.zIndex || 1, 10);
  const radius = parseInt(el.style.borderRadius || 0, 10);
  const rotate = parseFloat(el.style.rotate || '0');
  
  let html = deviceInfo + htmlSEO;
  html += `<div class="row">
  <div>
    <div class="label">Округление</div>
    <input type="range" id="pRad" min="0" max="64" step="1" value="${radius}">
    <input type="number" id="pRadNum" min="0" max="64" step="1" value="${radius}" style="width:80px;margin-left:8px">
  </div>
</div>`;
html += `<div class="row"><div><div class="label">Rotate (deg)</div><input type="text" id="pRot" value="${rotate}"></div><div></div></div>`;
  
  if (t === 'text') {
    html += `
  <label>Текст</label>
  <div id="rteMini" class="ri">
    <div class="ri-tools">
      <button id="riLink"  type="button" class="ri-btn">🔗 URL</button>
      <button id="riColorBtn" type="button" class="ri-trigger" title="Цвет выделенного текста"></button>
    </div>
    <div id="pTextMini" class="ri-editor"></div>
  </div>
`;
    html += `<div class="row"><div><div class="label">Размер (px)</div><input type="text" id="pFont" value="${parseInt(el.style.fontSize || '20', 10)}"></div><div><div class="label">Цвет</div><div style="display:flex;gap:8px"><input type="color" id="pColorPick" value="${toHexColor(el.style.color || '#e8f2ff')}" style="width:38px;height:38px;padding:0"><input type="text" id="pColor" value="${el.style.color || '#e8f2ff'}"></div></div></div>${buildPalette('pColor')}`;
    html += `<div class="row"><div><div class="label">Фон текста</div><div style="display:flex;gap:8px"><input type="color" id="pTextBgPick" value="${toHexColor(el.style.background || '#000000')}" style="width:38px;height:38px;padding:0"><input type="text" id="pTextBg" value="${el.style.background || ''}"></div></div><div></div></div>${buildPalette('pTextBg')}`;
  } else if (t === 'box') {
    html += `<div><div class="label">Фон</div><div style="display:flex;gap:8px"><input type="color" id="pBgPick" value="${toHexColor(el.style.background || '#2a3b50')}" style="width:38px;height:38px;padding:0"><input type="text" id="pBg" value="${el.style.background || ''}"></div>${buildPalette('pBg')}</div>`;
    html += `<div><div class="label">Граница (CSS)</div><input type="text" id="pBorder" value="${el.style.border || ''}"></div>`;
  } else if (t === 'image') {
    const img = el.querySelector('img');
    html += `<div class="row"><div><div class="label">Файл</div><input type="file" id="pImgFile" accept="image/*"></div><div><div class="label">URL</div><input type="text" id="pSrc" value="${img?.src || ''}"></div></div>`;
    html += `<div><div class="label">Код/HTML</div><textarea id="pImgHtml" style="width:100%;height:100px">${el.dataset.html || ''}</textarea></div>`;
  } else if (t === 'video') {
    const v = el.querySelector('video');
    html += `<div class="row"><div><div class="label">Файл</div><input type="file" id="pVidFile" accept="video/*"></div><div><div class="label">URL</div><input type="text" id="pVidSrc" value="${v?.src || ''}"></div></div>`;
    html += `<div><div class="label">Код/HTML (iframe)</div><textarea id="pVidHtml" style="width:100%;height:100px">${el.dataset.html || ''}</textarea></div>`;
    html += `<div class="row"><div><label><input type="checkbox" id="pVidCtrl" ${!v || v.controls ? 'checked' : ''}> Controls</label></div>
             <div><label><input type="checkbox" id="pVidAutoplay" ${v && v.autoplay ? 'checked' : ''}> Autoplay</label>
                  <label style="margin-left:10px"><input type="checkbox" id="pVidLoop" ${v && v.loop ? 'checked' : ''}> Loop</label>
                  <label style="margin-left:10px"><input type="checkbox" id="pVidMuted" ${v && v.muted ? 'checked' : ''}> Muted</label></div></div>`;
  } else if (t === 'linkbtn') {
    html += `<div><div class="label">Текст кнопки</div><input type="text" id="pLinkText" value="${el.dataset.text || 'Кнопка'}"></div>`;
    html += `<div><div class="label">URL ссылки</div><input type="text" id="pLinkUrl" value="${el.dataset.url || '#'}"></div>`;
    html += `<div class="row"><div><div class="label">Цвет фона</div><div style="display:flex;gap:8px"><input type="color" id="pLinkBgPick" value="${toHexColor(el.dataset.bg || '#3b82f6')}" style="width:38px;height:38px;padding:0"><input type="text" id="pLinkBg" value="${el.dataset.bg || '#3b82f6'}"></div></div><div><div class="label">Цвет текста</div><div style="display:flex;gap:8px"><input type="color" id="pLinkColorPick" value="${toHexColor(el.dataset.color || '#ffffff')}" style="width:38px;height:38px;padding:0"><input type="text" id="pLinkColor" value="${el.dataset.color || '#ffffff'}"></div></div></div>`;
  } 
  
  $('#props').innerHTML = html;
  
  if (window.TextScroll) { window.TextScroll.install(el); }
// убираем дублирующий input[type=file] для filebtn
if (el.dataset.type === 'filebtn') {
  var dup = document.querySelector('#props input[type="file"]');
  if (dup) {
    var row = dup.closest('.row');
    if (row && row.remove) row.remove();
    else if (dup.parentElement) dup.parentElement.style.display = 'none';
  }
}

  // Убираем блок координат для кнопок (linkbtn, filebtn) и текста (text)
(function(){
  if (!el || !el.dataset) return;
  var t = el.dataset.type;

  // перечисляем типы, для которых скрываем координаты
  if (['linkbtn','filebtn','text'].indexOf(t) === -1) return;

  ['pX', 'pY', 'pW', 'pH', 'pZ', 'pRad', 'pRot'].forEach(function(id){
    var n = document.getElementById(id);
    if (!n) return;
    var row = n.closest ? n.closest('.row') : null;
    if (!row) {
      var p = n.parentElement && n.parentElement.parentElement;
      if (p && p.classList && p.classList.contains('row')) row = p;
    }
    if (row && row.remove) {
      row.remove();
    } else if (row) {
      row.style.display = 'none';
    } else if (n && n.parentElement) {
      n.parentElement.style.display = 'none';
    }
  });
})();


// правый блок не должен снимать выделение со сцены
const _props = $('#props');
if (_props) {
  ['mousedown','click','pointerdown'].forEach(evt => {
    _props.addEventListener(evt, e => e.stopPropagation());
  });
  _props.addEventListener('input', (e) => {
    const id = e && e.target && e.target.id;
    // Обновляем рамки/ручки только для полей, которые меняют позицию/размер/поворот
    const needsRefresh = ['pX','pY','pW','pH','pRot'].includes(id);
    if (!needsRefresh || !selected) return;
    requestAnimationFrame(() => { 
      try { 
        ensureTools(selected);
        ensureHandle(selected);
      } catch(e){} 
    });
  });
}
  

  bindSeoHandlers();
  
  // Привязка обработчиков позиции/размера
  $('#pX')?.addEventListener('input', e => el.style.left = clamp(parseFloat(e.target.value) || 0, 0, 100) + '%');
  $('#pY')?.addEventListener('input', e => el.style.top = clamp(parseInt(e.target.value) || 0, 0, 2000) + 'px');
  $('#pW')?.addEventListener('input', e => el.style.width = clamp(parseFloat(e.target.value) || 10, 1, 100) + '%');
  $('#pH')?.addEventListener('input', e => el.style.height = clamp(parseFloat(e.target.value) || 10, 1, 200) + '%');
  $('#pZ')?.addEventListener('input', e => el.style.zIndex = parseInt(e.target.value || 1, 10));
  $('#pRad')?.addEventListener('input', e => {
  const v = Math.max(0, Math.min(64, parseInt(e.target.value || 0, 10)));
  $('#pRadNum')?.addEventListener('input', e => {
  const v = Math.max(0, Math.min(64, parseInt(e.target.value || 0, 10)));
  el.style.borderRadius = v + 'px';
  // Оставляем overflow по умолчанию, чтобы не прятать .el-tools
el.style.overflow = '';
  const range = $('#pRad');
  if (range) range.value = v;
});

  el.style.borderRadius = v + 'px';
  // Оставляем overflow по умолчанию, чтобы не прятать .el-tools
el.style.overflow = '';
  const num = $('#pRadNum');
  if (num) num.value = v;
});
  $('#pRot')?.addEventListener('input', e => el.style.rotate = (parseFloat(e.target.value || 0)) + 'deg');
  
  // Привязка обработчиков для каждого типа
  if (t === 'text') {
    const initialHtml =
  (el.dataset.html && el.dataset.html.trim()) ? el.dataset.html :
  ((el.innerHTML && el.innerHTML.trim()) ? el.innerHTML : (el.innerText || ''));

window.initRTEMini($('#rteMini'), {
  value: initialHtml || '',
  selectedEl: el,
  onChange: (html) => {
    // Сохраняем HTML
    el.dataset.html = html;
    
    // Извлекаем стили из HTML и добавляем их в head
    const styleMatches = html.match(/<style[^>]*>([\s\S]*?)<\/style>/gi);
    let contentWithoutStyles = html;
    
    if (styleMatches) {
      // Удаляем старые стили этого элемента
      const oldStyles = document.querySelectorAll(`style[data-element-id="${el.dataset.id}"]`);
      oldStyles.forEach(s => s.remove());
      
      // Добавляем новые стили в head
      styleMatches.forEach((styleTag, index) => {
        const styleContent = styleTag.replace(/<\/?style[^>]*>/gi, '');
        const styleEl = document.createElement('style');
        styleEl.setAttribute('data-element-id', el.dataset.id);
        styleEl.setAttribute('data-style-index', index);
        styleEl.textContent = styleContent;
        document.head.appendChild(styleEl);
        
        // Удаляем style из контента
        contentWithoutStyles = contentWithoutStyles.replace(styleTag, '');
      });
    }
    
    // Находим wrapper
    const wrapper = el.querySelector('.text-content-wrapper');
    if (wrapper) {
      // Обновляем только содержимое wrapper'а (без стилей)
      wrapper.innerHTML = contentWithoutStyles;
    } else {
      // Fallback для старых элементов без wrapper
      el.querySelectorAll('.handle, .el-tools').forEach(elem => elem.remove());
      el.innerHTML = contentWithoutStyles;
    }
    
    // Принудительно пересоздаем handles, tools и обработчики событий
    if (typeof ensureTools === 'function' && typeof ensureHandle === 'function' && typeof attachDragResize === 'function') {
      requestAnimationFrame(() => {
        try {
          ensureTools(el);
          ensureHandle(el);
          attachDragResize(el);
          
          // Убедимся, что элемент остается выделенным
          if (el.classList && !el.classList.contains('selected')) {
            el.classList.add('selected');
          }
        } catch(e){}
      });
    }
  }
});
    $('#pFont')?.addEventListener('input', e => el.style.fontSize = (parseInt(e.target.value || 20, 10)) + 'px');
    $('#pColor')?.addEventListener('input', e => {
      el.style.color = e.target.value;
      const cp = $('#pColorPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
    });
    $('#pColorPick')?.addEventListener('input', e => {
      el.style.color = e.target.value;
      const t = $('#pColor');
      if (t) t.value = e.target.value;
    });
    $('#pTextBg')?.addEventListener('input', e => {
      el.style.background = e.target.value;
      const cp = $('#pTextBgPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
    });
    $('#pTextBgPick')?.addEventListener('input', e => {
      el.style.background = e.target.value;
      const t = $('#pTextBg');
      if (t) t.value = e.target.value;
    });
  } else if (t === 'box') {
    $('#pBg')?.addEventListener('input', e => {
      el.style.background = e.target.value;
      const cp = $('#pBgPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
    });
    $('#pBgPick')?.addEventListener('input', e => {
      el.style.background = e.target.value;
      const t = $('#pBg');
      if (t) t.value = e.target.value;
    });
    $('#pBorder')?.addEventListener('input', e => el.style.border = e.target.value);
  } else if (t === 'image') {
    $('#pImgFile')?.addEventListener('change', async e => {
      const f = e.target.files?.[0];
      if (!f) return;
      const url = await uploadFile(f, 'image');
      if (!url) return;
      el.dataset.html = '';
      el.innerHTML = '';
      const img = document.createElement('img');
      img.src = url;
      el.appendChild(img);
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
      const s = $('#pSrc');
      if (s) s.value = url;
    });
    $('#pSrc')?.addEventListener('input', e => {
      el.dataset.html = '';
      el.innerHTML = '';
      const img = document.createElement('img');
      img.src = e.target.value;
      el.appendChild(img);
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
    });
    $('#pImgHtml')?.addEventListener('input', e => {
      const v = e.target.value.trim();
      el.dataset.html = v;
      if (v) {
        el.innerHTML = v;
      } else {
        el.innerHTML = '';
        const img = document.createElement('img');
        img.src = $('#pSrc')?.value || '';
        el.appendChild(img);
      }
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
    });
  } else if (t === 'video') {
    $('#pVidFile')?.addEventListener('change', async e => {
      const f = e.target.files?.[0];
      if (!f) return;
      const url = await uploadFile(f, 'video');
      if (!url) return;
      el.dataset.html = '';
      el.innerHTML = '';
      const v = document.createElement('video');
      v.controls = true;
      v.src = url;
      el.appendChild(v);
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
      $('#pVidSrc').value = url;
    });
    $('#pVidSrc')?.addEventListener('input', e => {
      const url = e.target.value;
      el.dataset.html = '';
      el.innerHTML = '';
      const v = document.createElement('video');
      v.controls = true;
      v.src = url;
      el.appendChild(v);
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
    });
    $('#pVidHtml')?.addEventListener('input', e => {
      const code = e.target.value.trim();
      el.dataset.html = code;
      if (code) {
        el.innerHTML = code;
      } else {
        el.innerHTML = '';
        const v = document.createElement('video');
        v.controls = true;
        v.src = $('#pVidSrc')?.value || '';
        el.appendChild(v);
      }
      ensureHandle(el);
      ensureTools(el);
      attachDragResize(el);
    });
    $('#pVidCtrl')?.addEventListener('change', e => {
      const v = el.querySelector('video');
      if (v) v.controls = e.target.checked;
    });
    $('#pVidAutoplay')?.addEventListener('change', e => {
      const v = el.querySelector('video');
      if (v) v.autoplay = e.target.checked;
    });
    $('#pVidLoop')?.addEventListener('change', e => {
      const v = el.querySelector('video');
      if (v) v.loop = e.target.checked;
    });
    $('#pVidMuted')?.addEventListener('change', e => {
      const v = el.querySelector('video');
      if (v) v.muted = e.target.checked;
    });
  } else if (t === 'linkbtn') {
    // Функция для синхронизации цветов при изменении в desktop режиме
    const syncColorsToOtherDevices = () => {
      const currentId = el.dataset.id;
      
      // Обновляем текущее устройство в deviceData
      const currentElem = deviceData[currentDevice].elements?.find(e => e.id === currentId);
      if (currentElem) {
        currentElem.bg = el.dataset.bg;
        currentElem.color = el.dataset.color;
      }
      
      // Если мы в desktop, синхронизируем с другими устройствами
      if (currentDevice === 'desktop') {
        ['tablet', 'mobile'].forEach(device => {
          const elem = deviceData[device].elements?.find(e => e.id === currentId);
          if (elem) {
            elem.bg = el.dataset.bg;
            elem.color = el.dataset.color;
          }
        });
      }
    };
    
    $('#pLinkText')?.addEventListener('input', e => {
      const val = (e.target.value || '').replace(/[📄📦📕📘📗📙🎵🎬🖼️💻💿📝]/g,'');
      el.dataset.text = val;
      const a = el.querySelector('a');
      if (a) a.textContent = val;
    });
    $('#pLinkUrl')?.addEventListener('input', e => {
      el.dataset.url = e.target.value;
      const a = el.querySelector('a');
      if (a) a.href = e.target.value;
    });

    $('#pLinkBg')?.addEventListener('input', e => {
      el.dataset.bg = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.background = e.target.value;
      if (a) a.style.setProperty('--bl-bg', e.target.value);
      const cp = $('#pLinkBgPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
      syncColorsToOtherDevices();
    });
    $('#pLinkBgPick')?.addEventListener('input', e => {
      el.dataset.bg = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.background = e.target.value;
      if (a) a.style.setProperty('--bl-bg', e.target.value);
      const t = $('#pLinkBg');
      if (t) t.value = e.target.value;
      syncColorsToOtherDevices();
    });
    $('#pLinkColor')?.addEventListener('input', e => {
      el.dataset.color = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.color = e.target.value;
      if (a) a.style.setProperty('--bl-color', e.target.value);
      const cp = $('#pLinkColorPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
      syncColorsToOtherDevices();
    });
    $('#pLinkColorPick')?.addEventListener('input', e => {
      el.dataset.color = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.color = e.target.value;
      if (a) a.style.setProperty('--bl-color', e.target.value);
      const t = $('#pLinkColor');
      if (t) t.value = e.target.value;
      syncColorsToOtherDevices();
    });
  } else if (t === 'filebtn') {
    // Функция для синхронизации цветов при изменении в desktop режиме
    const syncColorsToOtherDevices = () => {
      const currentId = el.dataset.id;
      
      // Обновляем текущее устройство в deviceData
      const currentElem = deviceData[currentDevice].elements?.find(e => e.id === currentId);
      if (currentElem) {
        currentElem.bg = el.dataset.bg;
        currentElem.color = el.dataset.color;
      }
      
      // Если мы в desktop, синхронизируем с другими устройствами
      if (currentDevice === 'desktop') {
        ['tablet', 'mobile'].forEach(device => {
          const elem = deviceData[device].elements?.find(e => e.id === currentId);
          if (elem) {
            elem.bg = el.dataset.bg;
            elem.color = el.dataset.color;
          }
        });
      }
    };
    
    $('#pFileText')?.addEventListener('input', e => {
      el.dataset.text = e.target.value;
      const a = el.querySelector('a');
      if (a) a.textContent = e.target.value;
    });
    $('#pFileUpload')?.addEventListener('change', async e => {
      const f = e.target.files?.[0];
      if (!f) return;
      const url = await uploadFile(f, 'file');
      if (!url) return;
      el.dataset.fileUrl = url;
      el.dataset.fileName = f.name;
      const a = el.querySelector('a');
      if (a) {
        a.href = url;
        a.download = f.name;
      }
      $('#pFileUrl').value = url;
      $('#pFileName').value = f.name;
    });
    $('#pFileUrl')?.addEventListener('input', e => {
      el.dataset.fileUrl = e.target.value;
      const a = el.querySelector('a');
      if (a) a.href = e.target.value;
    });
    $('#pFileName')?.addEventListener('input', e => {
      el.dataset.fileName = e.target.value;
      const a = el.querySelector('a');
      if (a) a.download = e.target.value;
    });
    $('#pFileBg')?.addEventListener('input', e => {
      el.dataset.bg = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.background = e.target.value;
      if (a) a.style.setProperty('--bf-bg', e.target.value);
      const cp = $('#pFileBgPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
      syncColorsToOtherDevices();
    });
    $('#pFileBgPick')?.addEventListener('input', e => {
      el.dataset.bg = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.background = e.target.value;
      if (a) a.style.setProperty('--bf-bg', e.target.value);
      const t = $('#pFileBg');
      if (t) t.value = e.target.value;
      syncColorsToOtherDevices();
    });
    $('#pFileColor')?.addEventListener('input', e => {
      el.dataset.color = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.color = e.target.value;
      if (a) a.style.setProperty('--bf-color', e.target.value);
      const cp = $('#pFileColorPick');
      if (cp) cp.value = toHexColor(e.target.value, cp.value);
      syncColorsToOtherDevices();
    });
    $('#pFileColorPick')?.addEventListener('input', e => {
      el.dataset.color = e.target.value;
      const a = el.querySelector('a');
      if (a) a.style.color = e.target.value;
      if (a) a.style.setProperty('--bf-color', e.target.value);
      const t = $('#pFileColor');
      if (t) t.value = e.target.value;
      syncColorsToOtherDevices();
    });
  }
  
  // Мини-палитры
  document.querySelectorAll('.palette .sw').forEach(s => {
    s.addEventListener('click', ev => {
      const color = ev.currentTarget.getAttribute('data-color');
      const trg = ev.currentTarget.parentElement.getAttribute('data-target');
      
      if (trg === 'pColor') {
        el.style.color = color;
        const t = $('#pColor');
        const cp = $('#pColorPick');
        if (t) t.value = color;
        if (cp) cp.value = toHexColor(color, cp.value);
      } else if (trg === 'pTextBg') {
        el.style.background = color;
        const t = $('#pTextBg');
        const cp = $('#pTextBgPick');
        if (t) t.value = color;
        if (cp) cp.value = toHexColor(color, cp.value);
      } else if (trg === 'pBg') {
        el.style.background = color;
        const t = $('#pBg');
        const cp = $('#pBgPick');
        if (t) t.value = color;
        if (cp) cp.value = toHexColor(color, cp.value);
      }
    });
  });
}

function bindSeoHandlers() {
  const t = $('#seoTitle'), d = $('#seoDesc');
  const tc = $('#seoTitleCnt'), dc = $('#seoDescCnt');
  
  if (!t || !d) return;
  
  function upd() {
    tc.textContent = (t.value || '').length;
    dc.textContent = (d.value || '').length;
    const okT = (t.value.length >= 50 && t.value.length <= 60);
    const okD = (d.value.length >= 140 && d.value.length <= 160);
    const green = '#17c964', grey = '#9fb2c6';
    tc.style.color = okT ? green : grey;
    dc.style.color = okD ? green : grey;
    t.style.borderColor = okT ? green : '#213247';
    d.style.borderColor = okD ? green : '#213247';
  }
  
  let timer = null;
  function push() {
    if (timer) clearTimeout(timer);
    // сразу обновляем локальную копию, чтобы при перерисовке панели значения не сбрасывались
    meta.title = t.value || '';
    meta.description = d.value || '';
    timer = setTimeout(async () => {
      const fd = new FormData();
      fd.append('id', currentPageId || 0);
      fd.append('title', meta.title);
      fd.append('description', meta.description);
      await fetch('/editor/api.php?action=updateMeta', {
        method: 'POST',
        body: fd,
        cache: 'no-store'
      });
    }, 300);
  }
  
  upd();
  t?.addEventListener('input', () => { upd(); push(); });
  d?.addEventListener('input', () => { upd(); push(); });
}

// Продолжение в следующем сообщении...
// === СЕРИАЛИЗАЦИЯ ===
function gatherData() {
  const els = $$('#stage .el').map(el => gatherElementData(el));
  return { elements: els };
}

function renderFromData(data) {
  stage.innerHTML = '';
  (data.elements || []).forEach(item => createElement(item.type, item));
}

// === API ===
async function uploadFile(file, type) {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('type', type);
  
  const r = await fetch('/editor/api.php?action=uploadAsset&type=' + encodeURIComponent(type), {
    method: 'POST',
    body: fd,
    cache: 'no-store'
  });
  
  let j = {};
  try {
    j = await r.json();
  } catch (e) {
    alert('Ошибка загрузки: неверный ответ');
    return null;
  }
  
  if (!j.ok) {
    alert('Ошибка загрузки: ' + (j.error || ('HTTP ' + r.status)));
    return null;
  }
  
  return j.url;
}

// АДАПТИВНОЕ СОХРАНЕНИЕ - ФУНКЦИЯ СИНХРОНИЗАЦИИ ЦВЕТОВ
// Синхронизируем цвета И html текста с desktop на tablet/mobile
function syncElementColors() {
  const desktopElements = (deviceData.desktop && deviceData.desktop.elements) ? deviceData.desktop.elements : [];
  const map = {};

  desktopElements.forEach(elem => {
    if (elem && (elem.type === 'linkbtn' || elem.type === 'filebtn' || elem.type === 'text' || elem.type === 'box')) {
      map[elem.id] = {
        bg: elem.bg,
        color: elem.color,
        border: elem.border,
        ...(elem.type === 'text' ? { html: elem.html } : {})
      };
    }
  });

  ['tablet', 'mobile'].forEach(dev => {
    const d = deviceData[dev];
    if (!d || !Array.isArray(d.elements)) return;

    d.elements = d.elements.map(elem => {
      const m = map[elem.id];
      if (!m) return elem;

      const extra = (elem.type === 'text' && typeof m.html === 'string')
        ? { html: m.html }
        : {};

      return {
        ...elem,
        ...extra,
        bg: m.bg || elem.bg,
        color: m.color || elem.color,
        border: m.border || elem.border
      };
    });
  });
}

async function savePage() {
  // Сохраняем текущие позиции
  saveCurrentDeviceData();
  
  // ВАЖНО: Синхронизируем цвета с desktop версии на все устройства
  syncElementColors();
  
  const fd = new FormData();
  fd.append('id', currentPageId || 0);
  fd.append('data_json', JSON.stringify(deviceData.desktop));
  fd.append('data_tablet', JSON.stringify(deviceData.tablet));
  fd.append('data_mobile', JSON.stringify(deviceData.mobile));
  
  const r = await fetch('/editor/api.php?action=savePageAdaptive', {
    method: 'POST',
    body: fd,
    cache: 'no-store'
  });
  
  let j = {};
  try {
    j = await r.json();
  } catch (e) {}
  
  if (j && j.ok) {
    if (j.id) currentPageId = j.id;
    alert('✅ Сохранено для всех устройств! Цвета синхронизированы.');
    refreshPages();
  } else {
    alert('Ошибка сохранения: ' + (j?.error || ('HTTP ' + r.status)));
  }
}

// АДАПТИВНАЯ ЗАГРУЗКА
async function loadPage(id) {
  const r = await fetch('/editor/api.php?action=loadPageAdaptive&id=' + (id || 0), {
    cache: 'no-store'
  });
  const j = await r.json();
  
  if (j.ok) {
    currentPageId = j.page.id;
    meta.title = j.page.meta_title || '';
    meta.description = j.page.meta_description || '';
    
    // Загружаем данные для всех устройств
    deviceData.desktop = j.page.data_desktop || { elements: [] };
    deviceData.tablet = j.page.data_tablet || { elements: [] };
    deviceData.mobile = j.page.data_mobile || { elements: [] };
    
    // Показываем текущее устройство
    loadDeviceData(currentDevice);
    refreshPages();
    renderProps(selected);
  } else {
    alert('Не удалось загрузить: ' + (j.error || ''));
  }
}

async function refreshPages() {
  const r = await fetch('/editor/api.php?action=listPages', {
    cache: 'no-store'
  });
  const j = await r.json();
  
  if (!j.ok) return;
  
  const list = $('#pages');
  list.innerHTML = '';
  
  j.pages.forEach(p => {
    const row = document.createElement('div');
    row.className = 'page-item';
    if (p.id === currentPageId) row.classList.add('active');
    
    const input = document.createElement('input');
    input.value = p.name;
    input.addEventListener('change', async () => {
      const fd = new FormData();
      fd.append('id', p.id);
      fd.append('name', input.value);
      await fetch('/editor/api.php?action=renamePage', {
        method: 'POST',
        body: fd
      });
      refreshPages();
    });
    input.addEventListener('dblclick', () => input.select());
    
    const btnOpen = document.createElement('button');
    btnOpen.className = 'btn';
    btnOpen.textContent = 'Открыть';
    btnOpen.addEventListener('click', () => loadPage(p.id));
    
    const btnDel = document.createElement('button');
    btnDel.className = 'btn danger del';
    btnDel.textContent = '×';
    btnDel.addEventListener('click', async () => {
      if (!confirm('Удалить страницу?')) return;
      const fd = new FormData();
      fd.append('id', p.id);
      await fetch('/editor/api.php?action=deletePage', {
        method: 'POST',
        body: fd
      });
      if (currentPageId === p.id) {
        loadPage(0);

// Обновлять высоту при изменениях
document.addEventListener('input', function(e) {
  if (e.target.closest('.el[data-type="text"]')) {
    adjustStageHeight();
  }
});

setInterval(adjustStageHeight, 1000);
      } else {
        refreshPages();
      }
    });
    
    row.append(input, btnOpen, btnDel);
    list.appendChild(row);
  });
}
// === АВТОПОДСТРОЙКА ВЫСОТЫ СЦЕНЫ (ОДНОКРАТНО) ===
// Глобальные флаги (используются также в /editor/assets/resize-stage.js)
window.stageUserResized = window.stageUserResized || false;   // +++ пользователь уже тянул ручку
window.stageAutoGrownOnce = window.stageAutoGrownOnce || false; // +++ автоподстройка уже произошла

function adjustStageHeight(evOrReason) {
  const stage = document.getElementById('stage');
  if (!stage) return;

  // Если пользователь уже менял высоту вручную — ничего не трогаем
  if (window.stageUserResized) {
    stage.style.minHeight = '720px'; // +++ гарантируем минимум
    return;
  }
  // Если однажды уже подстроили автоматически — больше не трогаем
  if (window.stageAutoGrownOnce) {
    stage.style.minHeight = '720px'; // +++ гарантируем минимум
    return;
  }

  // Подсчёт нижней границы контента
  let maxBottom = 0;
  const elements = stage.querySelectorAll('.el');
  elements.forEach(el => {
    const rect = el.getBoundingClientRect();
    const stageRect = stage.getBoundingClientRect();
    const absoluteBottom = rect.bottom - stageRect.top; // нижняя граница элемента относительно сцены
    if (absoluteBottom > maxBottom) maxBottom = absoluteBottom;
  });

  const padding = 80;
  const minHeight = 720;
  const currentH = parseFloat(getComputedStyle(stage).height) || minHeight;
  const newHeight = Math.max(minHeight, Math.ceil(maxBottom + padding));

  // Растим сцену только если стало НЕ хватать места — и только один раз
  if (newHeight > currentH + 1) {
    stage.style.height = newHeight + 'px';
    stage.style.minHeight = minHeight + 'px';
    window.stageAutoGrownOnce = true; // +++ больше автоматически не меняем
  } else {
    stage.style.minHeight = minHeight + 'px';
  }
}

// Дополнительный вызов при изменении элементов / при загрузке
document.addEventListener('DOMContentLoaded', adjustStageHeight);
window.addEventListener('resize', adjustStageHeight);


// === КНОПКИ ===

// === КНОПКИ ===
$('#btnAddText').addEventListener('click', () => selectEl(createElement('text')));
$('#btnAddBox').addEventListener('click', () => selectEl(createElement('box')));
$('#btnAddImage').addEventListener('click', () => selectEl(createElement('image')));     // без width/height
$('#btnAddVideo')?.addEventListener('click', () => selectEl(createElement('video')));     // без width/height
$('#btnAddLinkBtn')?.addEventListener('click', () => selectEl(createElement('linkbtn'))); // без width/height
$('#btnAddFileBtn')?.addEventListener('click', () => selectEl(createElement('filebtn'))); // без width/height
$('#btnSave').addEventListener('click', savePage);
$('#btnExport').addEventListener('click', (e) => {
  e.preventDefault();
  openExportModal(); // функция добавится ниже
});


$$('[data-device]').forEach(b => b.addEventListener('click', () => setDevice(b.dataset.device)));

$('#btnNewPage')?.addEventListener('click', async () => {
  const name = prompt('Название страницы', 'Новая');
  if (!name) return;
  
  const fd = new FormData();
  fd.append('name', name);
  fd.append('title', '');
  fd.append('description', '');
  
  const r = await fetch('/editor/api.php?action=createPage', {
    method: 'POST',
    body: fd
  });
  const j = await r.json();
  
  if (j.ok) {
    await refreshPages();
    await loadPage(j.id);
  }
});
$('#btnPurgeHome')?.addEventListener('click', async () => {
  if (!confirm('Очистить главную страницу и удалить связанные файлы из /editor/uploads?')) return;

  // Определяем id главной: ищем по имени "Главная", иначе текущая
  let homeId = currentPageId || 0;
  try {
    const rr = await fetch('/editor/api.php?action=listPages', { cache: 'no-store' });
    const jj = await rr.json();
    if (jj.ok && Array.isArray(jj.pages)) {
      const home = jj.pages.find(p => (p.name || '').toLowerCase() === 'главная');
      if (home) homeId = home.id;
    }
  } catch(e){}

  const fd = new FormData();
  fd.append('id', homeId);
  const r = await fetch('/editor/api.php?action=purgePage', { method:'POST', body: fd, cache: 'no-store' });
  const j = await r.json();
  if (j.ok) {
    if (currentPageId === homeId) {
      deviceData.desktop = { elements: [] };
      deviceData.tablet  = { elements: [] };
      deviceData.mobile  = { elements: [] };
      stage.innerHTML = '';
      renderProps(null);
    }
    await refreshPages();
    alert('Главная очищена');
  } else {
    alert(j.error || 'Не удалось очистить главную');
  }
});


// === ИНИЦИАЛИЗАЦИЯ ===
setDevice('desktop');
refreshPages();
loadPage(0);

// === FIX: медиа и корзина ===
(function() {
  function fixMedia(root = document) {
    root.querySelectorAll('.el img, .el video').forEach(n => {
      n.style.pointerEvents = 'none';
      n.style.userSelect = 'none';
    });
  }
  
  function fixTools(root = document) {
    root.querySelectorAll('.el .el-tools').forEach(bar => {
      Object.assign(bar.style, {
        position: 'absolute',
        left: '6px',
        bottom: 'auto',
        top: '100%',
        transform: 'translateY(6px)',
        zIndex: '10000',
        pointerEvents: 'auto'
      });
    });
  }
  
  fixMedia(document);
  fixTools(document);
  
  const target = document.getElementById('stage') || document.body;
  const obs = new MutationObserver(muts => {
    muts.forEach(m => {
      m.addedNodes && m.addedNodes.forEach(n => {
        if (n.nodeType !== 1) return;
        if (n.matches('.el img, .el video')) {
          n.style.pointerEvents = 'none';
          n.style.userSelect = 'none';
        }
        if (n.matches('.el .el-tools')) {
          fixTools(n.parentElement || document);
        }
        n.querySelectorAll && (fixMedia(n), fixTools(n));
      });
    });
  });
  
  obs.observe(target, {
    childList: true,
    subtree: true
  });
})();
// Обновлять высоту при изменениях
document.addEventListener('input', function(e) {
  if (e.target.closest('.el[data-type="text"]')) {
    setTimeout(adjustStageHeight, 100);
  }
});

// Обновлять при загрузке и изменениях DOM
window.addEventListener('load', function() {
  setTimeout(adjustStageHeight, 500);
});

const observer = new MutationObserver(function() {
  setTimeout(adjustStageHeight, 100);
});

if (document.getElementById('stage')) {
  observer.observe(document.getElementById('stage'), {
    childList: true,
    subtree: true,
    attributes: true
  });
}
// === Export Master Modal (Variant C) ===
function openExportModal() {
  const prev = localStorage.getItem('export_domain') || '';
  const prevHttps = (localStorage.getItem('export_https') || '1') === '1';
  const prevWWW = localStorage.getItem('export_www_mode') || 'keep';
  const prevForce = (localStorage.getItem('export_force_host') || '1') === '1';
  const prevLang = localStorage.getItem('export_primary_lang') || 'ru';

  const wrap = document.createElement('div');
  wrap.innerHTML = `
  <style>
    /* Темная модалка экспорта в стиле редактора */
    .xmodal{position:fixed;inset:0;display:grid;place-items:center;z-index:9999;backdrop-filter:blur(4px)}
    .xmodal__bg{position:absolute;inset:0;background:rgba(14,20,27,.85)}
    .xmodal__dlg{position:relative;background:#0f1622;max-width:580px;width:92%;padding:28px;border-radius:16px;border:1px solid #1f2b3b;box-shadow:0 20px 60px rgba(0,0,0,.5);font:14px/1.5 system-ui,-apple-system,sans-serif;color:#e6f0fa}
    .xmodal__dlg h3{margin:0 0 20px;color:#fff;font-size:20px;font-weight:600}
    .xmodal__row{margin:16px 0}
    .xmodal__row label{display:block;font-weight:600;margin-bottom:8px;color:#e4eef9;font-size:13px}
    .xmodal__row input[type=text]{width:100%;padding:10px 14px;background:#111925;border:1px solid #213247;color:#e4eef9;border-radius:10px;font-size:14px;transition:all .2s}
    .xmodal__row input[type=text]:focus{outline:none;border-color:#2ea8ff;box-shadow:0 0 0 3px rgba(46,168,255,.1)}
    .xmodal__row input[type=text]::placeholder{color:#6b7280}
    .xmodal__row input[type=checkbox]{width:18px;height:18px;cursor:pointer;accent-color:#2ea8ff}
    .xmodal__row .note{font-size:12px;color:#9fb2c6;margin-top:6px;line-height:1.4}
    .xmodal__actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;padding-top:20px;border-top:1px solid #1f2b3b}
    .xmodal .btn{padding:10px 20px;border-radius:10px;border:1px solid #2a3441;background:#1a2533;color:#e4eef9;cursor:pointer;font-weight:500;font-size:14px;transition:all .2s}
    .xmodal .btn:hover{background:#2a3441;transform:translateY(-1px)}
    .xmodal .btn.primary{background:#2ea8ff;border-color:#1e98ef;color:#fff;box-shadow:0 4px 12px rgba(46,168,255,.2)}
    .xmodal .btn.primary:hover{background:#1e98ef;box-shadow:0 6px 16px rgba(46,168,255,.3)}
    .radios{display:flex;gap:16px;flex-wrap:wrap;margin-top:8px}
    .radios label{font-weight:500;color:#e4eef9;display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px}
    .radios input[type=radio]{width:16px;height:16px;cursor:pointer;accent-color:#2ea8ff}
  </style>
  <div class="xmodal">
    <div class="xmodal__bg"></div>
    <div class="xmodal__dlg">
      <h3 style="margin:0 0 6px">Экспорт сайта</h3>
      <div class="xmodal__row">
        <label>Домен (можно с http/https, напр. <b>https://limux-com.com</b>)</label>
        <input id="expDomain" type="text" placeholder="https://example.com" value="${prev}">
        <div class="note">Используется в canonical, hreflang, robots.txt и sitemap.xml</div>
      </div>
      <div class="xmodal__row">
        <label><input id="expHttps" type="checkbox" ${prevHttps ? 'checked' : ''}> Использовать HTTPS</label>
      </div>
      <div class="xmodal__row">
        <label>WWW‑режим</label>
        <div class="radios">
          <label><input type="radio" name="expWWW" value="keep" ${prevWWW==='keep'?'checked':''}> Не трогать</label>
          <label><input type="radio" name="expWWW" value="www" ${prevWWW==='www'?'checked':''}> Принудительно <b>www</b></label>
          <label><input type="radio" name="expWWW" value="non-www" ${prevWWW==='non-www'?'checked':''}> Принудительно <b>без www</b></label>
        </div>
        <div class="note">Влияет на редиректы в .htaccess/nginx.conf (если включён фиксированный домен)</div>
      </div>
      <div class="xmodal__row">
        <label><input id="expForce" type="checkbox" ${prevForce ? 'checked' : ''}> Редиректить на указанный домен (canonical host)</label>
      </div>
      <div class="xmodal__row">
        <label>Основной язык (primary lang)</label>
        <select id="expLang" style="width:100%;padding:10px 14px;background:#111925;border:1px solid #213247;color:#e4eef9;border-radius:10px;font-size:14px;cursor:pointer">
          <option value="ru" ${prevLang==='ru'?'selected':''}>🇷🇺 Русский</option>
          <option value="en" ${prevLang==='en'?'selected':''}>🇬🇧 English</option>
          <option value="zh-Hans" ${prevLang==='zh-Hans'?'selected':''}>🇨🇳 中文</option>
          <option value="es" ${prevLang==='es'?'selected':''}>🇪🇸 Español</option>
          <option value="hi" ${prevLang==='hi'?'selected':''}>🇮🇳 हिन्दी</option>
          <option value="ar" ${prevLang==='ar'?'selected':''}>🇸🇦 العربية</option>
          <option value="pt" ${prevLang==='pt'?'selected':''}>🇵🇹 Português</option>
          <option value="de" ${prevLang==='de'?'selected':''}>🇩🇪 Deutsch</option>
          <option value="fr" ${prevLang==='fr'?'selected':''}>🇫🇷 Français</option>
          <option value="it" ${prevLang==='it'?'selected':''}>🇮🇹 Italiano</option>
          <option value="ja" ${prevLang==='ja'?'selected':''}>🇯🇵 日本語</option>
          <option value="ko" ${prevLang==='ko'?'selected':''}>🇰🇷 한국어</option>
          <option value="tr" ${prevLang==='tr'?'selected':''}>🇹🇷 Türkçe</option>
          <option value="uk" ${prevLang==='uk'?'selected':''}>🇺🇦 Українська</option>
          <option value="pl" ${prevLang==='pl'?'selected':''}>🇵🇱 Polski</option>
          <option value="nl" ${prevLang==='nl'?'selected':''}>🇳🇱 Nederlands</option>
          <option value="sv" ${prevLang==='sv'?'selected':''}>🇸🇪 Svenska</option>
          <option value="fi" ${prevLang==='fi'?'selected':''}>🇫🇮 Suomi</option>
          <option value="no" ${prevLang==='no'?'selected':''}>🇳🇴 Norsk</option>
          <option value="da" ${prevLang==='da'?'selected':''}>🇩🇰 Dansk</option>
          <option value="cs" ${prevLang==='cs'?'selected':''}>🇨🇿 Čeština</option>
          <option value="hu" ${prevLang==='hu'?'selected':''}>🇭🇺 Magyar</option>
          <option value="ro" ${prevLang==='ro'?'selected':''}>🇷🇴 Română</option>
          <option value="bg" ${prevLang==='bg'?'selected':''}>🇧🇬 Български</option>
          <option value="el" ${prevLang==='el'?'selected':''}>🇬🇷 Ελληνικά</option>
          <option value="id" ${prevLang==='id'?'selected':''}>🇮🇩 Indonesia</option>
          <option value="vi" ${prevLang==='vi'?'selected':''}>🇻🇳 Tiếng Việt</option>
          <option value="th" ${prevLang==='th'?'selected':''}>🇹🇭 ไทย</option>
          <option value="he" ${prevLang==='he'?'selected':''}>🇮🇱 עברית</option>
          <option value="fa" ${prevLang==='fa'?'selected':''}>🇮🇷 فارسی</option>
          <option value="ms" ${prevLang==='ms'?'selected':''}>🇲🇾 Bahasa Melayu</option>
          <option value="et" ${prevLang==='et'?'selected':''}>🇪🇪 Eesti</option>
          <option value="lt" ${prevLang==='lt'?'selected':''}>🇱🇹 Lietuvių</option>
          <option value="lv" ${prevLang==='lv'?'selected':''}>🇱🇻 Latviešu</option>
          <option value="sk" ${prevLang==='sk'?'selected':''}>🇸🇰 Slovenčina</option>
          <option value="sl" ${prevLang==='sl'?'selected':''}>🇸🇮 Slovenščina</option>
        </select>
        <div class="note">Главная: <code>/</code> для основного языка; <code>/index-xx.html</code> для остальных</div>
      </div>
      <div class="xmodal__actions">
        <button class="btn" id="expCancel">Отмена</button>
        <button class="btn primary" id="expGo">Экспорт</button>
      </div>
    </div>
  </div>`;

  document.body.appendChild(wrap);

  function valWWW(){ return [...wrap.querySelectorAll('input[name=expWWW]')].find(x=>x.checked).value; }

  wrap.querySelector('#expCancel').onclick = () => wrap.remove();
  wrap.querySelector('.xmodal__bg').onclick = () => wrap.remove();
  wrap.querySelector('#expGo').onclick = () => {
    let d = (wrap.querySelector('#expDomain').value || '').trim();
    if (d && !/^https?:\/\//i.test(d)) d = 'https://' + d;
    if (d && !/^(https?:\/\/)[a-z0-9.\-]+(?::\d+)?\/?$/i.test(d)) { alert('Введите корректный домен'); return; }

    const https = wrap.querySelector('#expHttps').checked ? 1 : 0;
    const www = valWWW();
    const force = wrap.querySelector('#expForce').checked ? 1 : 0;
    const lang = (wrap.querySelector('#expLang').value || 'ru').trim();

    localStorage.setItem('export_domain', d);
    localStorage.setItem('export_https', String(https));
    localStorage.setItem('export_www_mode', www);
    localStorage.setItem('export_force_host', String(force));
    localStorage.setItem('export_primary_lang', lang);

    const qs = new URLSearchParams({
      action: 'export',
      domain: d,
      https: String(https),
      www_mode: www,
      force_host: String(force),
      primary_lang: lang
    });
    window.location.href = '/editor/export.php?' + qs.toString();
    wrap.remove();
  };
}
