<?php
declare(strict_types=1);
ini_set('display_errors','1'); 
error_reporting(E_ALL);
require_once __DIR__ . '/admin/config.php';
$CANVAS_W = defined('CANVAS_W') ? (float)CANVAS_W : 1200.0;
$CANVAS_H = defined('CANVAS_H') ? (float)CANVAS_H : 1500.0;
$CANVAS_R = $CANVAS_W > 0 ? ($CANVAS_H / $CANVAS_W * 100.0) : (1500.0/1200.0*100.0); // во vw

$db = __DIR__.'/data/zerro_blog.db';
@mkdir(dirname($db), 0775, true);

try {
  $pdo = new PDO('sqlite:'.$db, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch(Throwable $e) {
  http_response_code(500);
  echo "<pre>DB error: ".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

/* Базовая схема с адаптивными колонками */
$pdo->exec("CREATE TABLE IF NOT EXISTS pages(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL DEFAULT 'Страница',
  data_json TEXT NOT NULL DEFAULT '{}',
  data_tablet TEXT DEFAULT '{}',
  data_mobile TEXT DEFAULT '{}',
  meta_title TEXT NOT NULL DEFAULT '',
  meta_description TEXT NOT NULL DEFAULT ''
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS urls(
  page_id INTEGER PRIMARY KEY,
  slug TEXT NOT NULL DEFAULT '',
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_urls_slug ON urls(slug) WHERE slug<>''");

/* Проверяем и добавляем адаптивные колонки если их нет */
function hasColumn($pdo, $table, $column) {
  $result = $pdo->query("PRAGMA table_info($table)");
  foreach($result as $row) {
    if($row['name'] === $column) return true;
  }
  return false;
}

if(!hasColumn($pdo, 'pages', 'data_tablet')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_tablet TEXT DEFAULT '{}'");
}
if(!hasColumn($pdo, 'pages', 'data_mobile')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_mobile TEXT DEFAULT '{}'");
}

/* Определяем, какую страницу показать */
$pageId = (int)($_GET['id'] ?? 0);
$slug = (string)($_GET['slug'] ?? '');

if(!$pageId) {
  if($slug !== '') {
    $st = $pdo->prepare("SELECT page_id FROM urls WHERE slug=:s");
    $st->execute(['s' => strtolower($slug)]);
    $pageId = (int)($st->fetchColumn() ?: 0);
  } else {
    $pageId = (int)$pdo->query("SELECT MIN(id) FROM pages")->fetchColumn();
  }
}

if(!$pageId) {
  $pageId = (int)$pdo->query("SELECT id FROM pages ORDER BY id DESC LIMIT 1")->fetchColumn();
}

if(!$pageId) {
  echo "<pre>Нет страниц</pre>";
  exit;
}

/* Данные страницы с адаптивными версиями */
$st = $pdo->prepare("SELECT * FROM pages WHERE id=:id");
$st->execute(['id' => $pageId]);
$row = $st->fetch();

if(!$row) {
  echo "<pre>Страница не найдена</pre>";
  exit;
}

// Декодируем данные для всех устройств
$desktop = json_decode($row['data_json'] ?? '{}', true) ?: ['elements' => []];
$tablet = json_decode($row['data_tablet'] ?? '{}', true) ?: ['elements' => []];
$mobile = json_decode($row['data_mobile'] ?? '{}', true) ?: ['elements' => []];

// Получаем язык из параметров или куки
// Проверяем наличие переводов для английского языка
$hasEnglishTranslation = false;
try {
    $transPdo = new PDO('sqlite:' . $db);
    $checkStmt = $transPdo->prepare("SELECT COUNT(*) FROM translations WHERE page_id = ? AND lang = 'en' LIMIT 1");
    $checkStmt->execute([$pageId]);
    $hasEnglishTranslation = ($checkStmt->fetchColumn() > 0);
} catch(Exception $e) {
    // Если таблицы нет, используем русский
}

// Определяем язык по умолчанию
$defaultLang = $hasEnglishTranslation ? 'en' : 'ru';
$currentLang = $_GET['lang'] ?? $_COOKIE['site_lang'] ?? $defaultLang;

// Базовые значения
// Подключаем SEO Manager
require_once __DIR__ . '/ui/seo/SeoManager.php';
$seoManager = new SeoManager($pdo);

// Определяем доступные языки из langbadge элементов
$availableLanguages = ['ru'];
foreach($desktop['elements'] as $el) {
    if (($el['type'] ?? '') === 'langbadge' && !empty($el['langs'])) {
        $langs = array_map('trim', explode(',', $el['langs']));
        $availableLanguages = array_unique(array_merge($availableLanguages, $langs));
        break;
    }
}

$titleRaw = ($row['meta_title'] ?: $row['name']);
$descRaw  = ($row['meta_description'] ?? '');

// Загружаем все переводы для текущей страницы
$translations = [];
if ($currentLang !== 'ru') {
    try {
        $transPdo = new PDO('sqlite:' . $db);
        $stmt = $transPdo->prepare("SELECT * FROM translations WHERE page_id = ? AND lang = ?");
        $stmt->execute([$pageId, $currentLang]);
        
        while ($trans = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($trans['element_id'] === 'meta') {
    if ($trans['field'] === 'title' && !empty($trans['content'])) {
        $titleRaw = $trans['content'];
    } elseif ($trans['field'] === 'description' && !empty($trans['content'])) {
        $descRaw = $trans['content'];
    }

            } else {
                $key = $trans['element_id'] . '_' . $trans['field'];
                $translations[$key] = $trans['content'];
            }
        }
    } catch(Exception $e) {
        // Используем оригинальные значения при ошибке
    }
}

// Создаем карту элементов для быстрого поиска
$tabletMap = [];
$mobileMap = [];

foreach($tablet['elements'] as $e) {
  if(isset($e['id'])) {
    $tabletMap[$e['id']] = $e;
  }
}

foreach($mobile['elements'] as $e) {
  if(isset($e['id'])) {
    $mobileMap[$e['id']] = $e;
  }
}
?>
<!doctype html>
<?php /* dynamic html lang */ ?>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php
// Генерируем все SEO‑теги с уже подставленными переводами
$rowForSeo = $row;
$rowForSeo['meta_title']       = $titleRaw;
$rowForSeo['meta_description'] = $descRaw;

echo $seoManager->generateMetaTags($rowForSeo, $currentLang, $availableLanguages);
// JSON-LD структурированные данные
echo $seoManager->generateJsonLd($rowForSeo, $currentLang);

// (опционально) если хотите дополнительно продублировать теги вручную:
$title = htmlspecialchars($titleRaw, ENT_QUOTES, 'UTF-8');
$desc  = htmlspecialchars($descRaw,  ENT_QUOTES, 'UTF-8');
?>
<title><?= $title ?></title>
<meta name="description" content="<?= $desc ?>">
<link rel="alternate" hreflang="en" href="?<?= http_build_query(array_merge($_GET, ['lang' => 'en'])) ?>" />
<link rel="alternate" hreflang="ru" href="?<?= http_build_query(array_merge($_GET, ['lang' => 'ru'])) ?>" />
<link rel="alternate" hreflang="x-default" href="?<?= http_build_query(array_merge($_GET, ['lang' => $defaultLang])) ?>" />
<style>
/* Основные стили */
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; } /* фикс iOS-увеличения */

body {
  margin: 0;
  background: #0e141b;
  color: #e6f0fa;
  font: 16px/1.4 system-ui, Segoe UI, Roboto;
}


.wrap {
  position: relative;
  min-height: 100vh;
  height: <?= number_format($CANVAS_R, 6, '.', '') ?>vw; /* Соответствует пропорциям редактора */

  overflow-x: hidden;
}
/* iOS/Android: корректная динамическая высота вьюпорта */
@supports (height: 100dvh) {
  .wrap { min-height: 100dvh; }
}

.el {
  position: absolute;
  box-sizing: border-box;
}

/* Общие правила */
.el img, .el video {
  width: 100%;
  height: 100%;
  border-radius: inherit;
  display: block;
}

/* Для изображений */
.el[data-type="image"] img {
  object-fit: contain;
  object-position: center;
}

/* Для видео */
.el[data-type="video"] video {
  object-fit: cover;
}
/* Текстовые блоки — фиксированная высота как в редакторе */
.el[data-type="text"] {
  min-height: 30px;
  padding: 8px;
  line-height: 1.3;
  white-space: normal;
  word-wrap: break-word;
  overflow-wrap: anywhere;
  box-sizing: border-box;
  overflow-y: auto; /* скролл если текст не влезает */
}

/* типографика как в редакторе — чтобы не «расползались» отступы */
.el[data-type="text"] p,
.el[data-type="text"] h1,
.el[data-type="text"] h2,
.el[data-type="text"] h3,
.el[data-type="text"] h4,
.el[data-type="text"] h5,
.el[data-type="text"] h6,
.el[data-type="text"] ul,
.el[data-type="text"] ol { margin: 0; padding: 0; }

.el[data-type="text"] li { margin: 0 0 .35em; }
.el[data-type="text"] p + p { margin-top: .35em; }


/* Стили для кнопок */
.el.linkbtn a, .el.filebtn a {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  box-sizing: border-box; /* <-- чтобы padding не увеличивал реальный размер */
  text-decoration: none;
  font-weight: 600;
  font-size: var(--btn-font-size, 1rem);
  line-height: 1.15;
  padding: var(--btn-py, 10px) var(--btn-px, 16px);
  min-height: 0;
  transition: all 0.3s ease;
}

.el.linkbtn a:hover {
  transform: scale(1.05);
  filter: brightness(1.2);
}

.el.filebtn a:hover {
  transform: scale(1.05);
  filter: brightness(1.2);
}
/* Стили для langbadge */
.el.langbadge {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 4px 6px;
  background: transparent;
  border: none;
}

.el.langbadge .lang-chip {
  padding: 6px 10px;
  border-radius: 10px;
  border: 1px solid #2ea8ff;
  background: #2ea8ff;
  color: #ffffff;
  font-size: 13px;
  cursor: default;
}

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ DESKTOP (базовые) */
<?php foreach($desktop['elements'] as $e): 
  $id = $e['id'] ?? uniqid('el_');
  $left = (float)($e['left'] ?? 0);
  $top = (float)($e['top'] ?? 0);
  $width = (float)($e['width'] ?? 30);
  $height = (float)($e['height'] ?? 25);
  $zIndex = (int)($e['z'] ?? 1);
  $radius = (int)($e['radius'] ?? 8);
  $rotate = (float)($e['rotate'] ?? 0);
  $autoHeight = false; // больше не делаем авто-высоту для текста

  /* Приводим вертикальную систему координат к базе редактора:
     Desktop ширина сцены = 1200px, высота сцены = 1500px */
  $DESKTOP_W = (int)$CANVAS_W;
$EDITOR_H  = (int)$CANVAS_H;


  $topPx    = $top; /* сохраняем пиксели как есть */
  $heightVW = round((($height / 100) * $EDITOR_H) / $DESKTOP_W * 100, 4);
?>
#el-<?= $id ?> {
  left: <?= $left ?>%;
  top: <?= $top ?>px;
  width: <?= $width ?>%;
  height: <?= $heightVW ?>vw;
  z-index: <?= $zIndex ?>;
  border-radius: <?= $radius ?>px;
  transform: rotate(<?= $rotate ?>deg);
}
<?php endforeach; ?>

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ TABLET */
@media (max-width: 768px) and (min-width: 481px) {
  <?php foreach($desktop['elements'] as $e): 
    $id = $e['id'] ?? '';
    if(!$id) continue;

    if(isset($tabletMap[$id])) {
      $te = $tabletMap[$id];
      $left = (float)($te['left'] ?? 0);
      $top = (float)($te['top'] ?? 0);
      $width = (float)($te['width'] ?? 45);
      $height = (float)($te['height'] ?? 25);
      $autoHeight = false; // больше не делаем авто-высоту для текста

      /* Tablet: база редактора = 768px по ширине, высота сцены = 1500px */
      $TABLET_W = 768;
      $EDITOR_H = (int)$CANVAS_H;

      $topVW    = round($top / $TABLET_W * 100, 4);
      $heightVW = round((($height / 100) * $EDITOR_H) / $TABLET_W * 100, 4);
    ?>
    #el-<?= $id ?> {
      left: <?= $left ?>% !important;
      top: <?= $topVW ?>vw !important;
      width: <?= $width ?>% !important;
      height: <?= $heightVW ?>vw !important;
    }
    <?php
    }
  endforeach; ?>
}

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ MOBILE */
@media (max-width: 480px) {
/* скрыть сцену, пока не применим позиции */
.wrap.pack-pending { visibility: hidden; }

  <?php foreach($desktop['elements'] as $e): 
    $id = $e['id'] ?? '';
    if(!$id) continue;

    if(isset($mobileMap[$id])) {
      $me = $mobileMap[$id];
      $left = (float)($me['left'] ?? 0);
      $top = (float)($me['top'] ?? 0);
      $width = (float)($me['width'] ?? 90);
      $height = (float)($me['height'] ?? 25);
      $autoHeight = false; // больше не делаем авто-высоту для текста

      /* Mobile: база редактора = 375px по ширине, высота сцены = 1500px */
      $MOBILE_W = 375;
      $EDITOR_H = (int)$CANVAS_H;

      $topVW    = round($top / $MOBILE_W * 100, 4);
      $heightVW = round((($height / 100) * $EDITOR_H) / $MOBILE_W * 100, 4);
    ?>
    #el-<?= $id ?> {
      left: <?= $left ?>% !important;
      top: <?= $topVW ?>vw !important;
      width: <?= $width ?>% !important;
      height: <?= $heightVW ?>vw !important;
    }
    <?php
    }
  endforeach; ?>

  /* Дополнительные адаптивные правила для мобильных */
  .el[data-type="text"] {
    font-size: 16px !important;
    line-height: 1.3 !important;
    white-space: normal !important;
    overflow-wrap: break-word !important;
    word-break: normal !important;
    word-wrap: break-word !important;
    hyphens: auto !important;
  }

  /* Не фиксируем высоту кнопок — размер задаёт сам элемент */
  .el.linkbtn,
  .el.filebtn {
    min-height: 0 !important;
  }

  /* Компактнее шрифт и паддинги на телефонах */
  .el.linkbtn a,
  .el.filebtn a {
    font-size: var(--btn-font-size-mobile, 0.875rem) !important; /* ≈14px */
    padding: var(--btn-py-mobile, 8px) var(--btn-px-mobile, 12px) !important;
    line-height: 1.2; /* для визуального баланса */
  }

}

/* === Text Animations (для анимированного текста из мини-редактора) === */
.ta { display: inline; }

/* Появление */
@keyframes ta-appear-kf {
  from { opacity: 0; transform: translateY(0.35em); }
  to   { opacity: 1; transform: none; }
}
.ta-appear { animation: ta-appear-kf .6s ease both; }

/* Мерцание (мигание прозрачности) */
@keyframes ta-blink-kf {
  0%, 49% { opacity: 1; }
  50%,100%{ opacity: 0; }
}
.ta-blink { animation: ta-blink-kf 1s step-start infinite; }

/* Мигание цвета */
@keyframes ta-colorcycle-kf {
  0%   { color: var(--ta-origin, currentColor); }
  25%  { color: #ff1744; }
  50%  { color: #ff9100; }
  75%  { color: #2979ff; }
  100% { color: var(--ta-origin, currentColor); }
}
.ta-colorcycle { animation: ta-colorcycle-kf 2s linear infinite; }

/* Пользователям с reduce motion — без анимации */
@media (prefers-reduced-motion: reduce) {
  .ta-appear, .ta-blink, .ta-colorcycle { animation: none !important; }
}
/* === /Text Animations === */

</style>

</style>
<script>
// Оставляем переменные для совместимости, но убираем клиентский 'перевод'.
window.siteTranslations = <?= json_encode($translations) ?>;
window.currentLang = '<?= $currentLang ?>';
</script>
<link rel="stylesheet" href="/ui/modules/button-link/button-link.css">
<?php
// Build version для кеширования
$buildVersion = $seoManager->getBuildVersion();
?>
<link rel="stylesheet" href="/ui/modules/button-link/button-link.css?v=<?= $buildVersion ?>">
<link rel="stylesheet" href="/ui/button-file/button-file.css?v=<?= $buildVersion ?>">
<link rel="stylesheet" href="/ui/button-file/button-file.css">


</head>
<body>
<div class="wrap pack-pending">
<?php 
// Выводим элементы с уникальными ID
foreach($desktop['elements'] as $e):
  $type = (string)($e['type'] ?? '');
  $id = $e['id'] ?? uniqid('el_');
  
  if($type === 'text'):
    // Сначала выводим сохранённый html (с форматированием), иначе безопасный text для старых данных
    $html  = (string)($e['html'] ?? '');
    $fs    = (int)($e['fontSize'] ?? 20);
    $color = htmlspecialchars($e['color'] ?? '#e8f2ff', ENT_QUOTES, 'UTF-8');
    $bg    = trim((string)($e['bg'] ?? ''));
    $bgStyle = $bg !== '' ? "background:{$bg};" : "";

    // SSR-перевод: подменяем контент на сервере, чтобы не было вспышки русского
    if ($currentLang !== 'ru') {
        $trKeyHtml = $id . '_html';
        $trKeyText = $id . '_text';
        if (!empty($translations[$trKeyHtml])) {
            $html = $translations[$trKeyHtml];
        } elseif ($html === '' && !empty($translations[$trKeyText])) {
            // На случай старых записей, где текст хранится в поле text
            $e['text'] = $translations[$trKeyText];
        }
    }
    ?>
    <?php
  // если выбран «Без скрола», принудительно переопределяем глобальное CSS-правило
  $noScroll     = !empty($e['noScroll']);
  $scrollInline = $noScroll
    ? 'overflow-y:visible;box-sizing:border-box;'
    : 'overflow-y:auto;box-sizing:border-box;';
?>
<div id="el-<?= $id ?>" class="el" data-type="text"
     style="<?= $bgStyle ?>color:<?= $color ?>;font-size:<?= $fs ?>px;line-height:1.3;<?= $scrollInline ?>">
  <?= $html !== '' ? $html : nl2br(htmlspecialchars($e['text'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
</div>

    <?php

    
  elseif($type === 'box'):
    $bg = trim((string)($e['bg'] ?? ''));
    $bd = trim((string)($e['border'] ?? ''));
    $bgStyle = $bg !== '' ? "background:{$bg};" : "";
    $bdStyle = $bd !== '' ? "border:{$bd};" : "";
    ?>
    <div id="el-<?= $id ?>" class="el" data-type="box" style="<?= $bgStyle . $bdStyle ?>"></div>
    <?php
    
  elseif($type === 'image'):
    if(!empty($e['html'])):
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="image">
        <div style="width:100%;height:100%"><?= $e['html'] ?></div>
      </div>
      <?php
    else:
      $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="image">
        <img src="<?= $src ?>" alt="" loading="lazy">
      </div>
      <?php
    endif;
    
  elseif($type === 'video'):
    if(!empty($e['html'])):
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="video">
        <div style="width:100%;height:100%"><?= $e['html'] ?></div>
      </div>
      <?php
    else:
      $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
      $controls = !isset($e['controls']) || $e['controls'] ? ' controls' : '';
      $autoplay = !empty($e['autoplay']) ? ' autoplay' : '';
      $loop = !empty($e['loop']) ? ' loop' : '';
      $muted = !empty($e['muted']) ? ' muted' : '';
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="video">
        <video src="<?= $src ?>"<?= $controls . $autoplay . $loop . $muted ?> playsinline></video>
      </div>
      <?php
    endif;
    
  elseif (strtolower($type) === 'linkbtn'):
    // Подставляем перевод текста кнопки на сервере (если есть)
    $btnTextRaw = $e['text'] ?? 'Кнопка';
    if ($currentLang !== 'ru' && !empty($translations[$id . '_text'])) {
        $btnTextRaw = $translations[$id . '_text'];
    }

    $text   = htmlspecialchars($btnTextRaw, ENT_QUOTES, 'UTF-8');
    $url    = htmlspecialchars($e['url'] ?? '#', ENT_QUOTES, 'UTF-8');

    // Цвета и радиус — как в редакторе
    $bg     = trim((string)($e['bg'] ?? '#3b82f6'));
    $color  = trim((string)($e['color'] ?? '#ffffff'));
    $radius = (int)($e['radius'] ?? 8);

    // Тип анимации, сохранённый редактором (none|pulse|shake|fade|slide)
    $anim   = preg_replace('~[^a-z]~', '', strtolower((string)($e['anim'] ?? 'none')));

    ?>
    <div id="el-<?= $id ?>" class="el linkbtn" data-type="linkbtn">
      <a class="bl-linkbtn bl-anim-<?= $anim ?>" href="<?= $url ?>"
         style="--bl-bg:<?= htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') ?>;
                --bl-color:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;
                --bl-radius:<?= $radius ?>px"
         target="_blank"><?= $text ?></a>
    </div>
    <?php

    
  elseif(strtolower($type) === 'filebtn'):
    // Подставляем перевод текста кнопки скачивания на сервере (если есть)
    $btnTextRaw = $e['text'] ?? 'Скачать файл';
    if ($currentLang !== 'ru' && !empty($translations[$id . '_text'])) {
        $btnTextRaw = $translations[$id . '_text'];
    }
    $text = htmlspecialchars($btnTextRaw, ENT_QUOTES, 'UTF-8');
    $fileUrl = htmlspecialchars($e['fileUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
    $fileName = htmlspecialchars($e['fileName'] ?? '', ENT_QUOTES, 'UTF-8');
    $bg = htmlspecialchars(trim((string)($e['bg'] ?? '#10b981')), ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars(trim((string)($e['color'] ?? '#ffffff')), ENT_QUOTES, 'UTF-8');
    $radius = (int)($e['radius'] ?? 8);
    
    // Тип анимации, сохранённый редактором (none|pulse|shake|fade|slide|bounce|glow|rotate)
    $anim = preg_replace('~[^a-z]~', '', strtolower((string)($e['anim'] ?? 'none')));
    
    // Определяем иконку
    $icon = '📄';
    if($fileName) {
      $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
      if(in_array($ext, ['zip','rar','7z','tar','gz','bz2'])) $icon = '📦';
      elseif($ext === 'pdf') $icon = '📕';
      elseif(in_array($ext, ['doc','docx'])) $icon = '📘';
      elseif(in_array($ext, ['xls','xlsx'])) $icon = '📗';
      elseif(in_array($ext, ['ppt','pptx'])) $icon = '📙';
      elseif(in_array($ext, ['mp3','wav','ogg','aac','flac'])) $icon = '🎵';
      elseif(in_array($ext, ['mp4','avi','mkv','mov','webm'])) $icon = '🎬';
      elseif(in_array($ext, ['jpg','jpeg','png','gif','svg','webp'])) $icon = '🖼️';
      elseif(in_array($ext, ['js','json','xml','html','css','php','py'])) $icon = '💻';
      elseif(in_array($ext, ['exe','apk','dmg','deb'])) $icon = '💿';
      elseif(in_array($ext, ['txt','md','csv'])) $icon = '📝';
    }
    ?>
    <div id="el-<?= $id ?>" class="el filebtn" data-type="filebtn">
  <a class="bf-filebtn bf-anim-<?= $anim ?>"
     href="<?= $fileUrl ?>"
     download="<?= $fileName ?>"
     style="--bf-bg:<?= $bg ?>;--bf-color:<?= $color ?>;--bf-radius:<?= $radius ?>px">
    <span class="bf-icon" aria-hidden="true"><?= $icon ?></span><?= $text ?>
  </a>
</div>
    <?php

  elseif ($type === 'langbadge'):
    $langs = htmlspecialchars($e['langs'] ?? 'ru,en', ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($e['label'] ?? 'Языки', ENT_QUOTES, 'UTF-8');
    $badgeColor = htmlspecialchars($e['badgeColor'] ?? '', ENT_QUOTES, 'UTF-8');

    $langsArray = array_filter(array_map('trim', explode(',', $langs)));
    $currentLang = $_GET['lang'] ?? $_COOKIE['site_lang'] ?? ($langsArray[0] ?? 'ru');

    // Сохраняем выбранный язык в куку
    if (isset($_GET['lang'])) {
        setcookie('site_lang', $_GET['lang'], time() + (365 * 24 * 60 * 60), '/');
    }

    // Мапинг языков на флаги и названия (полный список DeepL)
    $langMap = [
        'ru' => ['flag' => '🇷🇺', 'name' => 'Русский'],
        'en' => ['flag' => '🇬🇧', 'name' => 'English'],
        'zh-Hans' => ['flag' => '🇨🇳', 'name' => '中文'],
        'es' => ['flag' => '🇪🇸', 'name' => 'Español'],
        'fr' => ['flag' => '🇫🇷', 'name' => 'Français'],
        'de' => ['flag' => '🇩🇪', 'name' => 'Deutsch'],
        'it' => ['flag' => '🇮🇹', 'name' => 'Italiano'],
        'pt' => ['flag' => '🇵🇹', 'name' => 'Português'],
        'ja' => ['flag' => '🇯🇵', 'name' => '日本語'],
        'ko' => ['flag' => '🇰🇷', 'name' => '한국어'],
        'nl' => ['flag' => '🇳🇱', 'name' => 'Nederlands'],
        'pl' => ['flag' => '🇵🇱', 'name' => 'Polski'],
        'tr' => ['flag' => '🇹🇷', 'name' => 'Türkçe'],
        'ar' => ['flag' => '🇸🇦', 'name' => 'العربية'],
        'cs' => ['flag' => '🇨🇿', 'name' => 'Čeština'],
        'da' => ['flag' => '🇩🇰', 'name' => 'Dansk'],
        'el' => ['flag' => '🇬🇷', 'name' => 'Ελληνικά'],
        'fi' => ['flag' => '🇫🇮', 'name' => 'Suomi'],
        'hu' => ['flag' => '🇭🇺', 'name' => 'Magyar'],
        'id' => ['flag' => '🇮🇩', 'name' => 'Indonesia'],
        'no' => ['flag' => '🇳🇴', 'name' => 'Norsk'],
        'ro' => ['flag' => '🇷🇴', 'name' => 'Română'],
        'sv' => ['flag' => '🇸🇪', 'name' => 'Svenska'],
        'uk' => ['flag' => '🇺🇦', 'name' => 'Українська'],
        'bg' => ['flag' => '🇧🇬', 'name' => 'Български'],
        'et' => ['flag' => '🇪🇪', 'name' => 'Eesti'],
        'lt' => ['flag' => '🇱🇹', 'name' => 'Lietuvių'],
        'lv' => ['flag' => '🇱🇻', 'name' => 'Latviešu'],
        'sk' => ['flag' => '🇸🇰', 'name' => 'Slovenčina'],
        'sl' => ['flag' => '🇸🇮', 'name' => 'Slovenščina'],
        'hi' => ['flag' => '🇮🇳', 'name' => 'हिन्दी']
    ];
    // Текущий язык (флаг + название)
    $currentLangData = $langMap[trim($currentLang)] ?? ['flag' => '🌐', 'name' => strtoupper(trim($currentLang))];

    // Базовая часть query‑строки без параметра lang — чтобы не терять id/slug и другие параметры
    $baseQuery = $_GET;
    unset($baseQuery['lang']);
    $baseQueryStr = http_build_query($baseQuery);
    $baseHrefPrefix = ($baseQueryStr !== '' ? '?' . $baseQueryStr . '&' : '?');

    ?>
    <div id="el-<?= $id ?>" class="el langbadge" data-type="langbadge" data-langs="<?= $langs ?>">
      <div class="langbadge__wrap">
        <div class="lang-selector" onclick="this.querySelector('.lang-dropdown').classList.toggle('show')">
          <div class="lang-chip"<?php if ($badgeColor) { echo ' style="background:'.$badgeColor.'; border: 1px solid '.$badgeColor.'; color:#fff"'; } ?>>
            <span class="lang-flag"><?= $currentLangData['flag'] ?></span>
            <span class="lang-name"><?= $currentLangData['name'] ?></span>
          </div>
          <div class="lang-dropdown">
            <?php foreach($langsArray as $lang): 
                $code = trim($lang);
                $langData = $langMap[$code] ?? ['flag' => '🌐', 'name' => strtoupper($code)];
                $active = ($code === $currentLang) ? ' active' : '';
                $href = $baseHrefPrefix . 'lang=' . urlencode($code);
            ?>
              <a class="lang-option<?= $active ?>" href="<?= $href ?>">
                <span class="lang-flag"><?= $langData['flag'] ?></span>
                <span class="lang-name"><?= $langData['name'] ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <style>
      .el.langbadge { background: transparent !important; border: none !important; padding: 0 !important; }
      .lang-selector { position: relative; cursor: pointer; display: inline-block; }
      .lang-chip { padding: 8px 16px; border-radius: 12px; border: 1px solid #2ea8ff; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.3s ease; background: #0f1723; color: #fff; }
      .lang-chip:hover { background: #2ea8ff; transform: scale(1.05); }
      .lang-flag { font-size: 20px; line-height: 1; }
      .lang-dropdown { position: absolute; top: calc(100% + 8px); left: 0; background: #1a2533; border: 1px solid #2ea8ff; border-radius: 12px; padding: 8px; z-index: 10000; display: none; min-width: 200px; width: max-content; max-height: 380px; overflow-y: auto; overflow-x: hidden; box-shadow: 0 4px 20px rgba(46, 168, 255, 0.3); }
      .lang-dropdown.show { display: block !important; }
      .lang-option { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: #e8f2ff; }
      .lang-option:hover { background: rgba(46, 168, 255, 0.12); }
      .lang-option.active { background: #2ea8ff; color: #fff; }
      .lang-dropdown::-webkit-scrollbar { width: 8px; }
      .lang-dropdown::-webkit-scrollbar-track { background: #0b111a; border-radius: 4px; }
      .lang-dropdown::-webkit-scrollbar-thumb { background: #2a3f5f; border-radius: 4px; }
      .lang-dropdown::-webkit-scrollbar-thumb:hover { background: #3a5070; }
    </style>
    <?php
  endif;
endforeach; 
?>
</div>
<!-- Трекер событий для Telegram -->
<script src="/ui/tg-notify/tracker.js?v=<?php echo time(); ?>"></script>
<!-- Mobile spacing = exactly as in editor (375px) for ALL elements; stable & no scroll jumps -->
<script>
(function(){
  if (!matchMedia('(max-width:480px)').matches) return;
  if (window.__MOBILE_PACK_INIT__) return;  // защита от двойного подключения
  window.__MOBILE_PACK_INIT__ = true;

  var BASE_W = 375;                 // ширина мобильной сцены в редакторе
  var stage  = document.querySelector('.wrap');
  var initial = null;               // исходные "редакторские" данные (фиксируем ОДИН раз)
  var lastW   = 0;                  // ширина сцены, при которой применяли

  function stageWidth(){
    // ширина именно сцены (на ней стоят absolute-элементы)
    return (stage && stage.getBoundingClientRect().width) ||
           (window.visualViewport ? window.visualViewport.width : window.innerWidth);
  }
  function horizOverlap(a,b){
    var ar=a.getBoundingClientRect(), br=b.getBoundingClientRect();
    return !(ar.right<=br.left || ar.left>=br.right);
  }
  function vwUnit(){ return window.innerWidth/100; } // px в 1vw (для top/height в CSS)

  // --- невидимый контейнер для измерения высоты "как в редакторе @375px" ---
  var measBox;
  function ensureMeasBox(){
    if (measBox) return measBox;
    measBox = document.createElement('div');
    measBox.style.cssText =
      'position:fixed;left:-99999px;top:-99999px;width:'+BASE_W+'px;visibility:hidden;pointer-events:none;z-index:-1';
    document.body.appendChild(measBox);
    return measBox;
  }
  function measureTextDesignHeight(el, widthPx){
    var cs = getComputedStyle(el);
    var heightPx = parseFloat(cs.height) || 0;
    
    // Если в редакторе задана фиксированная высота (не auto), используем её
    if (heightPx > 0 && cs.height !== 'auto') {
      var vw = vwUnit();
      // Переводим текущую высоту в "редакторские пиксели"
      return (heightPx / vw) * (BASE_W / 100);
    }
    
    // Иначе измеряем auto-высоту по контенту (старая логика)
    var box = ensureMeasBox();
    var clone = el.cloneNode(true);
    clone.removeAttribute('id');
    clone.style.cssText = [
      'position:static;left:auto;top:auto;right:auto;bottom:auto',
      'box-sizing:border-box',
      'width:'+widthPx+'px',
      'height:auto',
      'margin:0',
      'padding:'+cs.padding,
      'border-style:'+cs.borderStyle,
      'border-width:'+cs.borderWidth,
      'border-color:'+cs.borderColor,
      'border-radius:'+cs.borderRadius,
      'font:'+cs.font,
      'letter-spacing:'+cs.letterSpacing,
      'text-transform:'+cs.textTransform,
      'white-space:normal;word-break:normal;overflow-wrap:break-word;hyphens:auto'
    ].join(';');
    box.appendChild(clone);
    var h = clone.offsetHeight;
    box.removeChild(clone);
    return h;
  }

  // === 1) Снимаем ИСХОДНЫЕ "редакторские" топы/ширины/высоты ОДИН РАЗ ===
  function captureInitial(){
    var items = [].slice.call(document.querySelectorAll('.el')); // все элементы, не только текст
    if (!items.length){ initial = []; return; }

    // порядок — по текущему top до любых правок
    items.sort(function(a,b){
      return (parseFloat(getComputedStyle(a).top)||0) -
             (parseFloat(getComputedStyle(b).top)||0);
    });

    var stgW = stageWidth();
    var vw   = vwUnit();

    initial = items.map(function(el){
      var cs   = getComputedStyle(el);
      var topPx= parseFloat(cs.top)||0;
      var wPx  = parseFloat(cs.width)||0;
      var isText = (el.getAttribute('data-type') === 'text');

      // "редакторский" top (px@375) = px@device → vw → px@375
      var topDesign   = (topPx / vw) * (BASE_W/100);
      // "редакторская" ширина = доля от ширины сцены × 375
      var widthDesign = (wPx / stgW) * BASE_W;

      // "редакторская" высота
      var heightDesign = isText
        ? measureTextDesignHeight(el, widthDesign)
        : ((parseFloat(cs.height) || el.offsetHeight) / vw) * (BASE_W/100);

      return { el, isText, topDesign, widthDesign, heightDesign };
    });

    // "редакторский" зазор между соседями одной колонки
    for (var i=1;i<initial.length;i++){
      var prevIndex = -1;
      for (var j=i-1;j>=0;j--){ if (horizOverlap(initial[j].el, initial[i].el)) { prevIndex=j; break; } }
      initial[i].prevIndex = prevIndex;
      initial[i].gapDesign = (prevIndex>=0) ? (initial[i].topDesign - (initial[prevIndex].topDesign + initial[prevIndex].heightDesign)) : null;
    }
  }

  // === 2) Обновляем ТОЛЬКО высоты/ширины (при смене ширины/после загрузки шрифтов) ===
  function refreshHeights(){
    if (!initial || !initial.length) return;
    var stgW = stageWidth();
    var vw   = vwUnit();
    initial.forEach(function(m){
      var cs = getComputedStyle(m.el);
      var wPx = parseFloat(cs.width)||0;
      m.widthDesign  = (wPx / stgW) * BASE_W;
      m.heightDesign = m.isText
        ? measureTextDesignHeight(m.el, m.widthDesign)
        : ((parseFloat(cs.height) || m.el.offsetHeight) / vw) * (BASE_W/100);
    });
  }

  // === 3) Применяем: prev.bottom + editor_gap — БЕЗ повторного "снятия" top ===
  function applyFromInitial(){
    if (!initial || initial.length < 2) return;
    var scale = window.innerWidth / BASE_W; // перевод "редакторских px" в текущие px

    // идём в "редакторском" порядке
    for (var i=0;i<initial.length;i++){
      var cur = initial[i];
      if (cur.prevIndex == null || cur.prevIndex < 0) continue;

      var prev = initial[cur.prevIndex];
      var prevTopPx = parseFloat(getComputedStyle(prev.el).top)||0;
      var desired   = Math.round(prevTopPx + prev.heightDesign*scale + (cur.gapDesign||0)*scale);

      var curTopPx  = parseFloat(getComputedStyle(cur.el).top)||0;
      if (Math.abs(curTopPx - desired) > 0.5){
        cur.el.style.setProperty('top', desired + 'px', 'important'); // перебиваем top: …vw !important
      }
    }
  }

  // ---- Инициализация ----
  function firstRun(){
    lastW = Math.round(stageWidth());
    captureInitial();   // фиксируем "как в редакторе" — ОДИН раз
    refreshHeights();   // высоты на текущей ширине/шрифтах
    applyFromInitial(); // применяем
    if (stage && stage.classList.contains('pack-pending')){
      stage.classList.remove('pack-pending'); // показать сцену без мигания
    }
  }

  // Пересчёт ТОЛЬКО при реальной смене ширины сцены (ориентация/другое устройство)
  var deb = null;
  function onWidthMaybeChanged(){
    var w = Math.round(stageWidth());
    if (Math.abs(w - lastW) >= 2){
      lastW = w;
      clearTimeout(deb);
      deb = setTimeout(function(){
        // НЕ переснимаем initial (topDesign остаётся как в редакторе)
        refreshHeights();
        applyFromInitial();
      }, 100);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', firstRun, {once:true});
  } else {
    firstRun();
  }

  // После загрузки шрифтов просто обновим высоты и повторим применение (без recapture)
  if (document.fonts && document.fonts.ready){
    document.fonts.ready.then(function(){ refreshHeights(); applyFromInitial(); });
  }

  // Следим только за изменением ШИРИНЫ (а не высоты при прокрутке)
  if (window.visualViewport && window.visualViewport.addEventListener){
    window.visualViewport.addEventListener('resize', onWidthMaybeChanged, {passive:true});
  } else {
    window.addEventListener('resize', onWidthMaybeChanged, {passive:true});
  }
  window.addEventListener('orientationchange', function(){ setTimeout(onWidthMaybeChanged, 250); }, {passive:true});
})();
</script>



</body>
</html>