<?php
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/admin/config.php';
$CANVAS_W = defined('CANVAS_W') ? (int)CANVAS_W : 1200;
$CANVAS_H = defined('CANVAS_H') ? (int)CANVAS_H : 1500;

$action = $_REQUEST['action'] ?? 'export';

if ($action === 'export') {
    exportSite();
} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

function exportSite() {
    $exportDir = null;
    // 🧹 Автоочистка старых временных папок (старше 1 часа)
    $pattern = __DIR__ . '/temp_export_*';
    foreach (glob($pattern) as $dir) {
        if (is_dir($dir) && (time() - filemtime($dir)) > 3600) {
            @deleteDirectory($dir);
        }
    }
    
    try {
        $db = dirname(__DIR__) . '/data/zerro_blog.db';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Создаем временную директорию для экспорта
        $exportDir = __DIR__ . '/temp_export_' . time();
        @mkdir($exportDir, 0777, true);
        @mkdir($exportDir . '/assets', 0777, true);
        @mkdir($exportDir . '/assets/uploads', 0777, true);
        @mkdir($exportDir . '/assets/js', 0777, true);
        // 🔧 Регистрируем автоудаление папки при завершении скрипта (даже после exit)
        register_shutdown_function(function() use ($exportDir) {
            if ($exportDir && is_dir($exportDir)) {
                deleteDirectory($exportDir);
            }
        });
        
        // Получаем все страницы с их URL
        $pages = getPages($pdo);
        
        // Получаем настройки языков из langbadge элементов
        $languages = getLanguages($pdo);
        
        // Получаем переводы
        $translations = getTranslations($pdo);
        
        // Проверяем наличие английских переводов
        $hasEnglishTranslations = false;
        if (in_array('en', $languages)) {
            try {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM translations WHERE lang = 'en' LIMIT 1");
                $checkStmt->execute();
                $hasEnglishTranslations = ($checkStmt->fetchColumn() > 0);
            } catch(Exception $e) {
                // Игнорируем если таблицы нет
            }
        }
        
        // Основной язык: сначала из модалки экспорта, иначе авто (en если есть переводы, иначе ru)
$primaryLang = preg_replace('~[^A-Za-z\-]~', '', $_REQUEST['primary_lang'] ?? '')
             ?: ($hasEnglishTranslations ? 'en' : 'ru');

        // Язык SEO из модалки (fallback: primary_lang -> авто)
$exportSeoLang = preg_replace('~[^A-Za-z\-]~', '', $_REQUEST['seo_lang'] ?? ($_REQUEST['primary_lang'] ?? $primaryLang));
// Если 1 — SEO/HTML-lang берём из языка самой страницы; если 0 — из модалки (единый язык)
$seoPerLang = (int)($_REQUEST['seo_per_lang'] ?? 1);

// Языки, выбранные в модалке (чекбоксы). Могут быть пустыми.
$__seoLangsRaw = $_REQUEST['seo_langs'] ?? '';
$__seoLangs = is_array($__seoLangsRaw) ? $__seoLangsRaw : explode(',', (string)$__seoLangsRaw);
$__seoLangs = array_values(array_unique(array_filter(array_map(function($x){
    return preg_replace('~[^A-Za-z\-]~', '', (string)$x);
}, $__seoLangs))));

// Итоговый список языков для ЭКСПОРТА страниц
$exportLangs = $languages;
if (!empty($__seoLangs)) {
    // Экспортируем только те языки, которые реально выбраны в модалке
    $exportLangs = array_values(array_intersect($languages, $__seoLangs));
}
// Всегда добавляем основной язык проекта (для index.html)
if (!in_array($primaryLang, $exportLangs, true)) {
    $exportLangs[] = $primaryLang;
}
$exportLangs = array_values(array_unique($exportLangs));



// Ключевые слова + флаг хлебных крошек из модалки
$exportMetaKeywords = trim((string)($_REQUEST['meta_keywords'] ?? ''));
$exportBreadcrumbs  = (int)($_REQUEST['breadcrumbs'] ?? 1);


        
        // Собираем все используемые файлы
        $usedFiles = [];
        
        // Генерируем CSS и JavaScript
        generateAssets($exportDir);
        
        /* Структура файлов в корне:
         * index.html - главная на русском
         * index-en.html - главная на английском
         * about.html - страница "О нас" на русском
         * about-en.html - страница "О нас" на английском
         * и т.д.
         */
        
        // Генерируем страницы для всех языков в корневой папке
        foreach ($pages as $page) {
            // Генерируем для всех языков
            foreach ($exportLangs as $lang) {
                if ($lang === $primaryLang) {
                    // Основной язык без суффикса
                    $pageTrans = $lang === 'ru' ? null : ($translations[$page['id']][$lang] ?? []);
                    $seoLangForPage = $seoPerLang ? $lang : $exportSeoLang;
                    $html = generatePageHTML($pdo, $page, $lang, $pageTrans, $usedFiles, $exportLangs, $primaryLang, $seoLangForPage);
                    $filename = getPageFilename($page, $lang, $primaryLang);
                    file_put_contents($exportDir . '/' . $filename, $html);
                } else {
                    // Другие языки с суффиксом
                    $pageTrans = $lang === 'ru' ? null : ($translations[$page['id']][$lang] ?? []);
                    $seoLangForPage = $seoPerLang ? $lang : $exportSeoLang;
                    $html = generatePageHTML($pdo, $page, $lang, $pageTrans, $usedFiles, $exportLangs, $primaryLang, $seoLangForPage);
                    $filename = getPageFilename($page, $lang, $primaryLang);
                    file_put_contents($exportDir . '/' . $filename, $html);
                }
            }
        }
        
        // Копируем используемые файлы
        copyUsedFiles($usedFiles, $exportDir);
        
        // Копируем HTML-изображения
        copyHtmlImages($exportDir);
        // Копируем HTML-видео
        copyHtmlVideos($exportDir);
        
        // Создаем .htaccess для красивых URL (Apache)
        generateHtaccess($exportDir);

        
        // Создаем nginx.conf для Nginx серверов
        generateNginxConfig($exportDir);
        

        // Создаём Remote Management API для модалки «Мои сайты»
if (function_exists('generateRemoteAPI')) {
    generateRemoteAPI($exportDir);   // запишет /remote-api.php в корень экспорта
}

        // === ЖЕЛЕЗОБЕТОННЫЙ ФИНАЛИЗАТОР ЭКСПОРТА (Variant C) ===
// Подключаем наш модуль (путь от текущего файла /editor/export.php)
require_once __DIR__ . '/export/post_export.php';



// Собираем опции из запроса (из модалки) + резервные значения
$opts = [
    'export_dir'   => $exportDir,                                 // куда собран статический сайт
    'domain'       => $_REQUEST['domain']       ?? '',            // домен (можно с http/https)
    'https'        => (int)($_REQUEST['https']  ?? 1),            // 1=https, 0=http
    'www_mode'     => $_REQUEST['www_mode']     ?? 'keep',        // keep | www | non-www
    'force_host'   => (int)($_REQUEST['force_host'] ?? 0),        // 1 — редиректить на домен
    'primary_lang' => $_REQUEST['primary_lang'] ?? $primaryLang,  // автоопределённый ранее $primaryLang
    'seo_lang'     => $exportSeoLang,
        'seo_per_lang' => $seoPerLang,
        'seo_langs'    => $exportLangs,   // ← финализатору для hreflang/sitemap


    'meta_keywords'=> $exportMetaKeywords,


    'zip_name'     => 'website_export_' . date('Y-m-d_His') . '.zip',
];

// Отдаст готовый ZIP и завершит скрипт (exit)
\Export\Finalizer\PostExport::entry($opts);

        
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } finally {
        // Удаляем временную папку в любом случае
        if ($exportDir && is_dir($exportDir)) {
            deleteDirectory($exportDir);
        }
    }
}

function getPages($pdo) {
    $sql = "SELECT p.id, p.name, p.data_json, p.data_tablet, p.data_mobile, 
                   p.meta_title, p.meta_description, u.slug,
                   CASE WHEN p.id=(SELECT MIN(id) FROM pages) THEN 1 ELSE 0 END AS is_home
            FROM pages p
            LEFT JOIN urls u ON u.page_id = p.id
            ORDER BY p.id";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getLanguages($pdo) {
    // Получаем языки из langbadge элементов
    $stmt = $pdo->query("SELECT data_json FROM pages");
    $languages = []; // собираем фактические языки из элементов и переводов
    
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data_json'], true);
        foreach ($data['elements'] ?? [] as $element) {
            if ($element['type'] === 'langbadge' && !empty($element['langs'])) {
                $langs = explode(',', $element['langs']);
                $languages = array_merge($languages, array_map('trim', $langs));
            }
        }
    }
    // Подтянем языки из таблицы переводов (если есть)
try {
    $q = $pdo->query("SELECT DISTINCT lang FROM translations");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['lang'])) $languages[] = trim($r['lang']);
    }
} catch (Exception $e) {
    // таблицы может не быть — тогда просто игнорируем
}

    return array_unique($languages);
}

function getTranslations($pdo) {
    $trans = [];
    
    // Проверяем существование таблицы
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'")->fetchAll();
    if (empty($tables)) {
        return $trans;
    }
    
    $stmt = $pdo->query("SELECT * FROM translations");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pageId = $row['page_id'];
        $lang = $row['lang'];
        $elementId = $row['element_id'];
        $field = $row['field'];
        
        if (!isset($trans[$pageId])) $trans[$pageId] = [];
        if (!isset($trans[$pageId][$lang])) $trans[$pageId][$lang] = [];
        
        $trans[$pageId][$lang][$elementId . '_' . $field] = $row['content'];
    }
    
    return $trans;
}

function getPageFilename($page, $lang = 'ru', $primaryLang = 'ru') {
    $basename = '';
    
    if ($page['is_home']) {
        $basename = 'index';
    } elseif (!empty($page['slug'])) {
        $basename = $page['slug'];
    } else {
        $basename = 'page_' . $page['id'];
    }
    
    // Добавляем языковой суффикс для всех языков кроме основного
    if ($lang !== $primaryLang) {
        $basename .= '-' . $lang;
    }
    
    return $basename . '.html';
}

function generatePageHTML($pdo, $page, $lang, $translations, &$usedFiles, $allLanguages, $primaryLang = 'ru', $seoLang = null) {
      // Подключаем SEO Manager
    require_once __DIR__ . '/../ui/seo/SeoManager.php';
$seoManager = new SeoManager($pdo, [
    'base_url_token' => '{{BASE_URL}}' // токен вместо «жёсткого» домена
]);
// Параметры модалки (используем прямо здесь)
$__metaKeywords = trim((string)($_REQUEST['meta_keywords'] ?? ''));
$__withBreadcrumbs = (int)($_REQUEST['breadcrumbs'] ?? 1);


    
    // Build version
    $buildVersion = $seoManager->getBuildVersion();
    $data = json_decode($page['data_json'], true) ?: [];
    $dataTablet = json_decode($page['data_tablet'], true) ?: [];
    $dataMobile = json_decode($page['data_mobile'], true) ?: [];
    
    // Получаем мета-данные с учетом перевода
    $title = $page['meta_title'] ?: $page['name'];
    $description = $page['meta_description'] ?: '';
    
    if ($translations && $lang !== 'ru') {
        if (isset($translations['meta_title'])) {
            $title = $translations['meta_title'];
        }
        if (isset($translations['meta_description'])) {
            $description = $translations['meta_description'];
        }
    }
    
    // Получаем цвет фона страницы
    $bgColor = $data['bgColor'] ?? '#0e141b';
    $pageHeight = $data['height'] ?? 2000;
    
    // Все страницы в корне, поэтому путь к assets всегда относительный
    $assetsPath = 'assets';
    // Если отдельный SEO-язык не передан — используем язык страницы
// Если отдельный SEO-язык не передан — используем язык страницы
$seoLang = $seoLang ?: $lang;
// Нормализуем (разрешаем только буквы/дефис, чтобы поддержать zh-Hans и т.п.)
$htmlLang = preg_replace('~[^A-Za-z\-]~', '', (string)$seoLang) ?: $lang;


// Динамический хост основного домена для Telegram‑трекера (как было в 99)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$notifyBase = $host ? "{$scheme}://{$host}" : '';
$notifyApi  = $notifyBase ? "{$notifyBase}/tg_notify_track.php" : "/tg_notify_track.php";
$notifyJs   = $notifyBase ? "{$notifyBase}/ui/tg-notify/tracker.js" : "/ui/tg-notify/tracker.js";
// ---- Stats: базовые пути (как для tg-notify) ----
$statsBase = $host ? "{$scheme}://{$host}" : '';
$statsApi  = $statsBase ? "{$statsBase}/stats_track.php" : "/stats_track.php";
$statsJs   = $statsBase ? "{$statsBase}/ui/stats/tracker.js" : "/ui/stats/tracker.js";

// Токен проекта для группировки статистики
$tokenFile = dirname(__DIR__) . '/data/.stats_token';
$statsToken = '';
if (is_file($tokenFile)) {
    $statsToken = trim((string)@file_get_contents($tokenFile));
}
if ($statsToken === '') {
    $statsToken = bin2hex(random_bytes(8));            // сгенерировать
    @file_put_contents($tokenFile, $statsToken);       // сохранить, чтобы все экспорты были в одной группе
}

    
    // Пробрасываем переводы в SeoManager
$pageForSeo = $page;
$pageForSeo['meta_title']       = $title;        // уже учтён перевод
$pageForSeo['meta_description'] = $description;  // уже учтён перевод

// Генерируем SEO‑теги уже с переведёнными значениями
$seoTags = $seoManager->generateMetaTags($pageForSeo, $htmlLang, $allLanguages);
$jsonLd  = $seoManager->generateJsonLd($pageForSeo, $htmlLang);
// <meta name="keywords"> из модалки
if ($__metaKeywords !== '') {
    $kw = htmlspecialchars($__metaKeywords, ENT_QUOTES, 'UTF-8');
    $seoTags .= "\n<meta name=\"keywords\" content=\"{$kw}\">";
}

// <meta name="author"> = Домен из модалки + " Team"
$__rawDomain = trim((string)($_REQUEST['domain'] ?? ''));
$authorTeam  = 'Site Team';
if ($__rawDomain !== '') {
    $tmp  = $__rawDomain;
    if (!preg_match('~^https?://~i', $tmp)) { $tmp = 'https://' . $tmp; }
    $host = parse_url($tmp, PHP_URL_HOST) ?: $__rawDomain;
    $host = preg_replace('~^www\.~i', '', $host);
    // Приводим к виду "Example.Com" (каждый сегмент с заглавной)
    $authorTeam = preg_replace_callback('~[A-Za-z0-9]+~', function($m){
        return ucfirst(strtolower($m[0]));
    }, $host) . ' Team';
}
// Добавляем author только если его ещё нет в $seoTags
if (!preg_match('~<meta\\b[^>]*name=["\']author["\'][^>]*>~i', $seoTags)) {
    $seoTags .= "\n<meta name=\"author\" content=\"{$authorTeam}\">";
}

// BreadcrumbList JSON‑LD (Home + текущая страница) — по рекомендациям Google
if ($__withBreadcrumbs) {
    // 1) URL главной — через токен (потом заменится на домен из модалки)
    $homeUrl = '{{BASE_URL}}/';

    // 2) Канонический URL текущей страницы — берём из уже сгенерированных тегов
    $currentUrl = $homeUrl;
    if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)~i', $seoTags, $m)) {
        $currentUrl = $m[1];
    }

    // Имя текущей страницы — берём из рассчитанного выше $title
    $items = [];

    // ПРАВИЛО GOOGLE: последний элемент в цепочке НЕ должен иметь поле 'item'
    if (empty($page['is_home'])) {
        // Внутренняя страница: Home (с item) + текущая страница (БЕЗ item)
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home',
            'item'     => $homeUrl  // ← первый элемент ИМЕЕТ item
        ];
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => $title ?: 'Page'
            // ← последний элемент НЕ ИМЕЕТ item (по рекомендации Google)
        ];
    } else {
        // Главная страница: только один элемент (без item, т.к. он последний)
        $items[] = [
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home'
            // ← единственный элемент НЕ ИМЕЕТ item (по рекомендации Google)
        ];
    }

    $breadcrumb = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items
    ];
    $jsonLd .= "\n<script type=\"application/ld+json\">"
             . json_encode($breadcrumb, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
             . "</script>";
}


    
    // Начало HTML
    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$htmlLang}">
<head>
{$seoTags}
{$jsonLd}
<script>
/*
  JS‑fallback: подменяет токен {{BASE_URL}} на реальный origin.
  Исправляет href canonical / hreflang, og:url / og:image, twitter:image и JSON‑LD (url/logo/image).
*/
(function(){
  try{
    var origin = location.protocol + '//' + location.host;
    var fix = function(v){ return v ? v.replace(/\{\{BASE_URL\}\}/g, origin) : v; };

    // canonical
    var c = document.querySelector('link[rel="canonical"]');
    if (c) c.href = fix(c.getAttribute('href'));

    // hreflang
    document.querySelectorAll('link[rel="alternate"][hreflang]').forEach(function(l){
      l.href = fix(l.getAttribute('href'));
    });

    // Open Graph
    ['og:url','og:image'].forEach(function(p){
      document.querySelectorAll('meta[property="'+p+'"]').forEach(function(m){
        var v = m.getAttribute('content'); if (!v) return;
        if (v.indexOf('{{BASE_URL}}') !== -1) {
          m.setAttribute('content', fix(v));
        } else if (p==='og:image' && !/^https?:\/\//i.test(v)) {
          m.setAttribute('content', origin.replace(/\/$/, '') + '/' + v.replace(/^\//,''));
        }
      });
    });

    // Twitter Card
    document.querySelectorAll('meta[name="twitter:image"]').forEach(function(m){
      var v = m.getAttribute('content'); if (!v) return;
      if (v.indexOf('{{BASE_URL}}') !== -1) m.setAttribute('content', fix(v));
      else if (!/^https?:\/\//i.test(v)) m.setAttribute('content', origin.replace(/\/$/, '') + '/' + v.replace(/^\//,''));
    });

    // JSON-LD
    document.querySelectorAll('script[type="application/ld+json"]').forEach(function(s){
      try{
        var data = JSON.parse(s.textContent);
        var arr = Array.isArray(data) ? data : [data];
        var changed = false;
        arr.forEach(function(o){
          if (o && typeof o === 'object') {
            if (o.url) { o.url = fix(o.url); changed = true; }
            if (o.logo && typeof o.logo === 'string') { o.logo = fix(o.logo); changed = true; }
            if (o.image && typeof o.image === 'string') { o.image = fix(o.image); changed = true; }
          }
        });
        if (changed) s.textContent = JSON.stringify(arr);
      }catch(e){}
    });
  }catch(e){}
})();
</script>
<link rel="stylesheet" href="{$assetsPath}/style.css?v={$buildVersion}">
</head>
<body style="background-color: {$bgColor};">
    <div class="wrap pack-pending" style="min-height: {$pageHeight}px;">
HTML;
    
    // Генерируем элементы
    foreach ($data['elements'] ?? [] as $element) {
        $html .= generateElement($element, $lang, $translations, $usedFiles, $dataTablet, $dataMobile, $page, $allLanguages, $primaryLang);
    }
    
  $html .= <<<HTML
    </div>
    <!-- Telegram notify -->
<script>window.TG_NOTIFY_API = '{$notifyApi}';</script>
<script src="{$notifyJs}?v={$buildVersion}" defer></script>

<!-- Stats -->
<script>window.STATS_API = '{$statsApi}'; window.STATS_TOKEN = '{$statsToken}';</script>
<script src="{$statsJs}?v={$buildVersion}" defer></script>

<script src="{$assetsPath}/js/main.js?v={$buildVersion}"></script>

</body>
</html>
HTML;

// Оптимизация изображений и ссылок
$html = $seoManager->optimizeImages($html, $title);
$html = $seoManager->secureLinks($html);

// Доменно‑агностичная правка превью и JSON‑LD медиа + добавление в $usedFiles
$html = rewriteOgTwitterImagesInPlace($html, $usedFiles);
$html = fixJsonLdMediaInPlace($html, $usedFiles);
// Чистим дубли <meta name="author"> вне <head>: оставляем только тот, что в <head>
$__parts = explode('</head>', $html, 2);
if (count($__parts) === 2) {
    $__head = $__parts[0];
    $__body = $__parts[1];
    // Удаляем все meta author из BODY
    $__body = preg_replace('~<meta\s+[^>]*name\s*=\s*["\']author["\'][^>]*>\s*~i', '', $__body);
    $html = $__head . '</head>' . $__body;
}


    return $html;
}


function generateElement($element, $lang, $translations, &$usedFiles, $dataTablet, $dataMobile, $page, $allLanguages, $primaryLang = 'ru') {
    global $CANVAS_W, $CANVAS_H;
    
    $type = $element['type'] ?? '';
    $id = $element['id'] ?? '';
    
    // Получаем адаптивные стили
    $tabletElement = null;
    $mobileElement = null;
    
    foreach ($dataTablet['elements'] ?? [] as $te) {
        if (($te['id'] ?? '') === $id) {
            $tabletElement = $te;
            break;
        }
    }
    
    foreach ($dataMobile['elements'] ?? [] as $me) {
        if (($me['id'] ?? '') === $id) {
            $mobileElement = $me;
            break;
        }
    }
    
    // Базовые стили с улучшенной обработкой
    $left = $element['left'] ?? 0;
    $top = $element['top'] ?? 0;
    $width = $element['width'] ?? 30;
    $height = $element['height'] ?? 25;
    $zIndex = $element['z'] ?? 1;
    $radius = $element['radius'] ?? 0;
    $rotate = $element['rotate'] ?? 0;
    $opacity = $element['opacity'] ?? 1;
    
    // Пересчитываем вертикальные единицы управления как в index.php редактора
    $DESKTOP_W = $CANVAS_W; // ширина сцены редактора
$EDITOR_H  = $CANVAS_H; // высота сцены редактора

    // Высоту считаем для всех типов (включая text) — как на главной.
// Это критично для работы overflow-y:auto (внутренний скролл).
$topVW    = round(($top / $DESKTOP_W) * 100, 4);
$heightVW = round(((($height / 100) * $EDITOR_H) / $DESKTOP_W) * 100, 4);

$style = sprintf(
    'left:%s%%;top:%spx;width:%s%%;height:%svw;z-index:%d;border-radius:%dpx;transform:rotate(%sdeg);opacity:%s',
    $left,
    $top, // PX как на главной для Desktop
    $width,
    $heightVW,
    $zIndex,
    $radius,
    $rotate,
    $opacity
);


    
    // Добавляем дополнительные стили если есть
    if (!empty($element['shadow'])) {
        $style .= ';box-shadow:' . $element['shadow'];
    }
    
    $html = '';
    
    switch ($type) {
        case 'text':
    $content = $element['html'] ?? $element['text'] ?? '';
    if ($translations && isset($translations[$id . '_html'])) {
        $content = $translations[$id . '_html'];
    } elseif ($translations && isset($translations[$id . '_text'])) {
        $content = $translations[$id . '_text'];
    }
    
    // Обработка стилей текста
    $fontSize = $element['fontSize'] ?? 20;
    $color = $element['color'] ?? '#e8f2ff';
    $bg = $element['bg'] ?? 'transparent';
    $padding = $element['padding'] ?? 8;
    $textAlign = $element['textAlign'] ?? 'left';
    $fontWeight = $element['fontWeight'] ?? 'normal';
    $lineHeight = $element['lineHeight'] ?? '1.5';
    
    // !!! ДОБАВИТЬ перед $textStyle:
$minHeightCss = '30px';
if (!empty($height)) {
    // те же базовые величины, что и выше при конвертации в vw
    $DESKTOP_W = $CANVAS_W;  // ширина сцены редактора
    $EDITOR_H  = $CANVAS_H;  // высота сцены редактора
    $minHeightVW = round(((($height / 100) * $EDITOR_H) / $DESKTOP_W) * 100, 4);
    $minHeightCss = $minHeightVW . 'vw';
}

// !!! ЗАМЕНИТЬ существующий sprintf на:
$textStyle = sprintf(
    'font-size:%dpx;color:%s;background:%s;padding:%dpx;text-align:%s;font-weight:%s;line-height:%s;min-height:%s;word-wrap:break-word;overflow-wrap:break-word',
    $fontSize,
    $color,
    $bg,
    $padding,
    $textAlign,
    $fontWeight,
    $lineHeight,
    $minHeightCss
);

    $scrollCss = empty($element['noScroll']) ? 'overflow-y:auto' : 'overflow:visible';


    
    $html = sprintf(
        '<div class="el text" style="%s;%s;%s;box-sizing:border-box;" id="%s" data-type="text" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
        $style, $textStyle, $scrollCss, $id,
        json_encode($tabletElement ?: [], JSON_HEX_APOS),
        json_encode($mobileElement ?: [], JSON_HEX_APOS),
        $content
    );
    break;
            
        case 'image':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $alt = $element['alt'] ?? '';
            $objectFit = $element['objectFit'] ?? 'contain';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'><img src="%s" alt="%s" style="width:100%%;height:100%%;object-fit:%s;"></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    htmlspecialchars($alt),
                    $objectFit
                );
            }
            break;
            
        case 'video':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $poster = isset($element['poster']) ? processMediaPath($element['poster'], $usedFiles) : '';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $controls = ($element['controls'] ?? true) ? 'controls' : '';
                $autoplay = ($element['autoplay'] ?? false) ? 'autoplay' : '';
                $loop = ($element['loop'] ?? false) ? 'loop' : '';
                $muted = ($element['muted'] ?? false) ? 'muted' : '';
                $posterAttr = $poster ? 'poster="' . $poster . '"' : '';
                
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'><video src="%s" %s %s %s %s %s style="width:100%%;height:100%%;object-fit:cover;"></video></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    $controls,
                    $autoplay,
                    $loop,
                    $muted,
                    $posterAttr
                );
            }
            break;
            
        case 'box':
            $bg = $element['bg'] ?? 'rgba(95,179,255,0.12)';
            $border = $element['border'] ?? '1px solid rgba(95,179,255,0.35)';
            $blur = isset($element['blur']) ? 'backdrop-filter:blur(' . $element['blur'] . 'px);' : '';
            
            $boxStyle = sprintf(
                'background:%s;border:%s;%s',
                $bg,
                $border,
                $blur
            );
            
            $html = sprintf(
                '<div class="el box" style="%s;%s" id="%s" data-type="box" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $style,
                $boxStyle,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
            

        case 'linkbtn':
    // Текст с учётом перевода
    $text = $element['text'] ?? 'Кнопка';
    if ($translations && isset($translations[$id . '_text'])) {
        $text = $translations[$id . '_text'];
    }

    // Параметры кнопки
    $url      = $element['url'] ?? '#';
    $bg       = $element['bg'] ?? '#3b82f6';
    $color    = $element['color'] ?? '#ffffff';
    $fontSize = (int)($element['fontSize'] ?? 16);
    $radius   = (int)($element['radius'] ?? 8);
    $target   = $element['target'] ?? '_blank';
    
    // 🔧 ФИКС: читаем anim из tablet/mobile если нет в desktop
    $anim = $element['anim'] ?? null;
    if (!$anim && $tabletElement && isset($tabletElement['anim'])) {
        $anim = $tabletElement['anim'];
    }
    if (!$anim && $mobileElement && isset($mobileElement['anim'])) {
        $anim = $mobileElement['anim'];
    }
    $anim = preg_replace('~[^a-z]~', '', strtolower($anim ?? 'none'));

    // HTML с классами модуля и CSS‑переменными (как в редакторе)
    $html = sprintf(
        '<div class="el linkbtn" style="%s" id="%s" data-type="linkbtn" data-tablet=\'%s\' data-mobile=\'%s\'>
            <a class="bl-linkbtn bl-anim-%s" href="%s" target="%s"
               style="--bl-bg:%s;--bl-color:%s;--bl-radius:%dpx;--bl-font-size:%dpx;">%s</a>
        </div>',
        $style,
        $id,
        json_encode($tabletElement ?: [], JSON_HEX_APOS),
        json_encode($mobileElement ?: [], JSON_HEX_APOS),
        $anim,
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($target, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($bg, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
        $radius,
        $fontSize,
        htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
    );
    break;


            
        case 'filebtn':
    // Текст с учётом перевода
    $text = $element['text'] ?? 'Скачать файл';
    if ($translations && isset($translations[$id . '_text'])) {
        $text = $translations[$id . '_text'];
    }
    
    // Параметры кнопки
    $fileUrl  = processMediaPath($element['fileUrl'] ?? '#', $usedFiles);
    $fileName = $element['fileName'] ?? '';
    $bg       = $element['bg'] ?? '#10b981';
    $color    = $element['color'] ?? '#ffffff';
    $fontSize = (int)($element['fontSize'] ?? 16);
    $radius   = (int)($element['radius'] ?? 8);
    
    // 🔧 ФИКС: читаем anim из tablet/mobile если нет в desktop
    $anim = $element['anim'] ?? null;
    if (!$anim && $tabletElement && isset($tabletElement['anim'])) {
        $anim = $tabletElement['anim'];
    }
    if (!$anim && $mobileElement && isset($mobileElement['anim'])) {
        $anim = $mobileElement['anim'];
    }
    $anim = preg_replace('~[^a-z]~', '', strtolower($anim ?? 'none'));
    
    // Определяем иконку файла
    $icon = getFileIcon($fileName);
    
    // HTML с классами модуля и CSS-переменными (как в редакторе)
    $html = sprintf(
        '<div class="el filebtn" style="%s" id="%s" data-type="filebtn" data-tablet=\'%s\' data-mobile=\'%s\'>
            <a class="bf-filebtn bf-anim-%s" href="%s" download="%s"
               style="--bf-bg:%s;--bf-color:%s;--bf-radius:%dpx;--bf-font-size:%dpx;">
                <span class="bf-icon" aria-hidden="true">%s</span>%s
            </a>
        </div>',
        $style,
        $id,
        json_encode($tabletElement ?: [], JSON_HEX_APOS),
        json_encode($mobileElement ?: [], JSON_HEX_APOS),
        $anim,
        htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($bg, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
        $radius,
        $fontSize,
        $icon,
        htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
    );
    break;
            
        
        
        case 'langbadge':
            // Языковой переключатель (как в редакторе)
            $langs = !empty($element['langs']) ? explode(',', $element['langs']) : $allLanguages;
            $langs = array_map('trim', $langs);
            $badgeColor = $element['badgeColor'] ?? '#2ea8ff';
            $fontSize = $element['fontSize'] ?? 14;

            $langMap = [
                'en' => '🇬🇧 English',
                'zh-Hans' => '🇨🇳 中文',
                'es' => '🇪🇸 Español',
                'hi' => '🇮🇳 हिन्दी',
                'ar' => '🇸🇦 العربية',
                'pt' => '🇵🇹 Português',
                'ru' => '🇷🇺 Русский',
                'de' => '🇩🇪 Deutsch',
                'fr' => '🇫🇷 Français',
                'it' => '🇮🇹 Italiano',
                'ja' => '🇯🇵 日本語',
                'ko' => '🇰🇷 한국어',
                'tr' => '🇹🇷 Türkçe',
                'uk' => '🇺🇦 Українська',
                'pl' => '🇵🇱 Polski',
                'nl' => '🇳🇱 Nederlands',
                'sv' => '🇸🇪 Svenska',
                'fi' => '🇫🇮 Suomi',
                'no' => '🇳🇴 Norsk',
                'da' => '🇩🇰 Dansk',
                'cs' => '🇨🇿 Čeština',
                'hu' => '🇭🇺 Magyar',
                'ro' => '🇷🇴 Română',
                'bg' => '🇧🇬 Български',
                'el' => '🇬🇷 Ελληνικά',
                'id' => '🇮🇩 Indonesia',
                'vi' => '🇻🇳 Tiếng Việt',
                'th' => '🇹🇭 ไทย',
                'he' => '🇮🇱 עברית',
                'fa' => '🇮🇷 فارسی',
                'ms' => '🇲🇾 Bahasa Melayu',
                'et' => '🇪🇪 Eesti',
                'lt' => '🇱🇹 Lietuvių',
                'lv' => '🇱🇻 Latviešu',
                'sk' => '🇸🇰 Slovenčina',
                'sl' => '🇸🇮 Slovenščina'
            ];

            $currentLang = $lang;
            $currentDisplay = $langMap[$currentLang] ?? ('🌐 ' . strtoupper($currentLang));
            $currentParts = explode(' ', $currentDisplay, 2);
            $currentFlag = $currentParts[0] ?? '🌐';
            $currentName = $currentParts[1] ?? strtoupper($currentLang);

            $optionsHtml = '';
            foreach ($langs as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $display = $langMap[$l] ?? ('🌐 ' . strtoupper($l));
                $parts = explode(' ', $display, 2);
                $flag = $parts[0] ?? '🌐';
                $name = $parts[1] ?? strtoupper($l);

                $pageFilename = getPageFilename($page, $l, $primaryLang); // напр.: index.html или index-ru.html
                $active = ($l == $currentLang) ? ' active' : '';
                $optionsHtml .= sprintf(
                    '<a class="lang-option%s" href="%s"><span class="lang-flag">%s</span><span class="lang-name">%s</span></a>',
                    $active,
                    htmlspecialchars($pageFilename, ENT_QUOTES, 'UTF-8'),
                    $flag,
                    htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                );
            }

            $chipStyle = sprintf(' style="background:%s;border:1px solid %s;color:#fff;font-size:%dpx;"',
                $badgeColor,
                $badgeColor,
                $fontSize
            );

            $html = sprintf(
                '<div class="el langbadge" style="%s" id="%s" data-type="langbadge" data-tablet=\'%s\' data-mobile=\'%s\'>' .
                '<div class="lang-selector" onclick="this.querySelector(\'.lang-dropdown\').classList.toggle(\'show\')">' .
                '<div class="lang-chip"%s><span class="lang-flag">%s</span><span class="lang-name">%s</span></div>' .
                '<div class="lang-dropdown">%s</div>' .
                '</div>' .
                '</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $chipStyle,
                $currentFlag,
                htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8'),
                $optionsHtml
            );
            break;


            
        case 'embed':
            // Встраиваемый контент (iframe, embed code)
            $embedCode = $element['embedCode'] ?? '';
            if ($translations && isset($translations[$id . '_embedCode'])) {
                $embedCode = $translations[$id . '_embedCode'];
            }
            
            $html = sprintf(
                '<div class="el embed" style="%s" id="%s" data-type="embed" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $embedCode
            );
            break;
            
        default:
            // Для неизвестных типов элементов
            $html = sprintf(
                '<div class="el %s" style="%s" id="%s" data-type="%s" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $type,
                $style,
                $id,
                $type,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
    }
    
    return $html;
}
// === ДОБАВИТЬ: переписываем og:image и twitter:image на локальные ассеты + кладём в usedFiles
function rewriteOgTwitterImagesInPlace(string $html, array &$usedFiles): string {
    return preg_replace_callback(
        '~<meta\s+(?:property="og:image"|name="twitter:image")\s+content="([^"]+)"\s*/?>~i',
        function($m) use (&$usedFiles) {
            $src = $m[1];
            // прогоняем через ваш процессор путей, он же добавит файл в $usedFiles
            $local = processMediaPath($src, $usedFiles);
            // подменяем значение content на локальный путь (если не изменилось — оставим как было)
            $safe = htmlspecialchars($local, ENT_QUOTES);
            return str_replace($src, $safe, $m[0]);
        },
        $html
    );
}

// === ДОБАВИТЬ: чиним logo/image в JSON‑LD (локализуем editor/uploads -> assets/uploads и добавляем в usedFiles)
function fixJsonLdMediaInPlace(string $html, array &$usedFiles): string {
    return preg_replace_callback(
        '~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is',
        function($m) use (&$usedFiles) {
            $json = trim($m[1]);
            if ($json === '') return $m[0];

            $data = json_decode($json, true);
            if ($data === null) return $m[0];

            // Нормализуем к массиву объектов
            $arr = (is_array($data) && array_keys($data) === range(0, count($data)-1)) ? $data : [$data];
            $changed = false;

            foreach ($arr as &$node) {
                if (!is_array($node)) continue;

                foreach (['logo','image'] as $prop) {
                    if (isset($node[$prop]) && is_string($node[$prop])) {
                        $orig = $node[$prop];
                        $new  = processMediaPath($orig, $usedFiles); // локализует editor/uploads и добавит файл

                        if ($new !== $orig) {
                            $node[$prop] = $new;
                            $changed = true;
                        } else {
                            // если это /editor/uploads или https://*/editor/uploads — лучше удалить, чтобы не отдавать 404
                            if (preg_match('~(^/|https?://[^/]+/)editor/uploads/~i', $orig)) {
                                unset($node[$prop]);
                                $changed = true;
                            }
                        }
                    }
                }
            }
            unset($node);

            if (!$changed) return $m[0];

            $newJson = json_encode(count($arr) === 1 ? $arr[0] : $arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            return str_replace($m[1], $newJson, $m[0]);
        },
        $html
    );
}
function getFileIcon($fileName) {
    if (!$fileName) return '📄';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $icons = [
        'zip' => '📦', 'rar' => '📦', '7z' => '📦', 'tar' => '📦', 'gz' => '📦',
        'pdf' => '📕',
        'doc' => '📘', 'docx' => '📘', 'odt' => '📘',
        'xls' => '📗', 'xlsx' => '📗', 'ods' => '📗', 'csv' => '📗',
        'ppt' => '📙', 'pptx' => '📙', 'odp' => '📙',
        'mp3' => '🎵', 'wav' => '🎵', 'ogg' => '🎵', 'flac' => '🎵', 'm4a' => '🎵',
        'mp4' => '🎬', 'avi' => '🎬', 'mkv' => '🎬', 'mov' => '🎬', 'webm' => '🎬',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️', 'svg' => '🖼️', 'webp' => '🖼️',
        'txt' => '📝', 'md' => '📝',
        'html' => '🌐', 'css' => '🎨', 'js' => '⚡', 'json' => '📋',
        'exe' => '⚙️', 'dmg' => '⚙️', 'apk' => '📱',
    ];
    
    return $icons[$ext] ?? '📄';
}

function processMediaPath($path, &$usedFiles) {
    if (!$path || $path === '#') return $path;

    // Абсолютные URL редактора -> локальная копия в assets/uploads
    if (preg_match('~^https?://[^/]+/(editor/uploads/([^?#]+))~i', $path, $m)) {
        $relative = $m[1];                 // editor/uploads/file.jpg
        $basename = basename($m[2]);       // file.jpg
        $localSrc = dirname(__DIR__) . '/' . $relative;

        if (file_exists($localSrc)) {
            $dest = 'assets/uploads/' . $basename;
            $usedFiles[] = [
                'source' => $localSrc,
                'dest'   => $dest
            ];
            return $dest; // относительный путь в экспорте
        }
        // если файла нет на диске — возвращаем как есть
        return $path;
    }

    // Внешние ссылки на сторонние домены — оставляем как есть
    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    // data URL
    if (strpos($path, 'data:') === 0) {
        return $path;
    }

    // Локальные файлы из editor/uploads -> копируем в assets/uploads
    if (strpos($path, '/editor/uploads/') === 0 || strpos($path, 'editor/uploads/') === 0) {
        $filename = basename($path);
        $sourcePath = dirname(__DIR__) . '/' . ltrim($path, '/');

        if (file_exists($sourcePath)) {
            $usedFiles[] = [
                'source' => $sourcePath,
                'dest'   => 'assets/uploads/' . $filename
            ];
            return 'assets/uploads/' . $filename;
        }
    }

    return $path;
}

function processHtmlContent($html, &$usedFiles) {
    // Обрабатываем все src и href в HTML
    $html = preg_replace_callback(
        '/(src|href)=["\']([^"\']+)["\']/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return $matches[1] . '="' . $path . '"';
        },
        $html
    );
    
    // Обрабатываем background в style
    $html = preg_replace_callback(
        '/background(-image)?:\s*url\(["\']?([^"\')]+)["\']?\)/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return 'background' . $matches[1] . ':url(' . $path . ')';
        },
        $html
    );
    
    return $html;
}

function generateAssets($exportDir) {
    global $CANVAS_W, $CANVAS_H;
    
    // CSS файл
    $css = <<<CSS
* { 
    margin: 0; 
    padding: 0; 
    box-sizing: border-box; 
}

body {
    background: #0e141b;
    color: #e6f0fa;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    margin: 0;
    overflow-x: hidden;
}

.wrap {
    position: relative;
    min-height: 100vh;
    overflow-x: hidden;
    width: 100%;
}
/* iOS/Android: корректная динамическая высота вьюпорта */
@supports (height: 100dvh) {
    .wrap { min-height: 100dvh; }
}


.el {
    position: absolute;
    box-sizing: border-box;
    transition: none;
}

/* Текстовые элементы */
.el.text {
    min-height: 30px;           /* как в редакторе (страховка) */
    line-height: 1.3;
    white-space: normal;
    word-wrap: break-word;
    overflow-wrap: break-word;
    box-sizing: border-box;
    overflow-y: auto;           /* скролл внутри блока */
}
.el.text p,
.el.text h1,
.el.text h2,
.el.text h3,
.el.text h4,
.el.text h5,
.el.text h6,
.el.text ul,
.el.text ol { margin: 0; padding: 0; }
.el.text li { margin: 0 0 .35em; }
.el.text p + p { margin-top: .35em; }
.el.text a { color: inherit; }


/* Изображения */
.el.image {
    overflow: hidden;
}

.el img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    border-radius: inherit;
    display: block;
}

/* Видео */
.el.video {
    overflow: hidden;
}

.el video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
    display: block;
}

/* Блоки */
.el.box {
    pointer-events: none;
}

/* Кнопки */
.el.linkbtn,
.el.filebtn {
    overflow: hidden;
    cursor: pointer;
}

.el.linkbtn a, 
.el.filebtn a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    gap: 8px;
}

.el.filebtn a:hover {
    transform: scale(1.02);
}

.el.linkbtn a:active,
.el.filebtn a:active {
    transform: scale(0.98);
}

/* Языковой переключатель (как в редакторе) */
.el.langbadge { background: transparent !important; border: none !important; padding: 0 !important; }
.lang-selector { position: relative; cursor: pointer; display: inline-block; }
.lang-chip { padding: 8px 16px; border-radius: 12px; border: 1px solid #2ea8ff; background: #0f1723; color: #fff; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; }
.lang-chip:hover { background: #2ea8ff; transform: scale(1.05); }
.lang-flag { font-size: 20px; line-height: 1; }
.lang-dropdown { position: absolute; top: calc(100% + 8px); left: 0; display: none; min-width: 220px; max-height: 280px; overflow-y: auto; background: rgba(12, 18, 26, 0.96); border: 1px solid rgba(46,168,255,0.25); border-radius: 12px; padding: 10px; box-shadow: 0 8px 24px rgba(46,168,255,0.2); backdrop-filter: blur(8px); z-index: 9999; }
.lang-dropdown.show { display: block !important; }
.lang-option { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: #e8f2ff; transition: background .2s ease; }
.lang-option:hover { background: rgba(46, 168, 255, 0.12); }
.lang-option.active { background: #2ea8ff; color: #fff; }
.lang-dropdown::-webkit-scrollbar { width: 8px; }
.lang-dropdown::-webkit-scrollbar-track { background: #0b111a; border-radius: 4px; }
.lang-dropdown::-webkit-scrollbar-thumb { background: #2a3f5f; border-radius: 4px; }
.lang-dropdown::-webkit-scrollbar-thumb:hover { background: #3a5070; }
/* Встраиваемый контент */
.el.embed {
    overflow: hidden;
}

.el.embed iframe {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: inherit;
}

/* Адаптивность для планшетов */
@media (max-width: 768px) and (min-width: 481px) {
    .wrap {
        min-height: 100vh;
    }
    
    .el.text {
        font-size: calc(100% - 2px) !important;
    }
}

/* Адаптивность для мобильных устройств */
@media (max-width: 480px) {
/* скрыть сцену, пока не применим позиции (как в index.php) */
.wrap.pack-pending { visibility: hidden; }
    .wrap {
        min-height: 100vh;
    }
    
    .el {
        transition: none !important;
    }
    
    .el.text {
        font-size: max(14px, calc(100% - 4px)) !important;
        line-height: 1.4 !important;
    }
    
    .el.langbadge .lang-chip {
        font-size: 14px !important;
        padding: 6px 12px !important;
    }
}

/* Анимации при загрузке */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.el {
    animation: fadeIn 0.5s ease-out;
}

/* Печать */
@media print {
    .el.langbadge {
        display: none !important;
    }
    
    .wrap {
        min-height: auto;
    }
}
CSS;
    $css .= <<<CSS
/* === Модуль "кнопка – ссылка" (linkbtn): стили и анимации === */
.el.linkbtn .bl-linkbtn{
  --bl-bg:#3b82f6; --bl-color:#ffffff; --bl-radius:12px;
  display:flex; align-items:center; justify-content:center;
  width:100%; height:100%; box-sizing:border-box;
  padding:var(--bl-py,10px) var(--bl-px,16px);
  min-height:0;
  background:var(--bl-bg); color:var(--bl-color); border-radius:var(--bl-radius);
  text-decoration:none; font-weight:600; line-height:1;
  font-size:var(--bl-font-size,1em);
  box-shadow:0 2px 6px rgba(0,0,0,.12);
  transition:transform .2s ease, filter .2s ease;
}
.el.linkbtn .bl-linkbtn:hover{ transform:scale(1.03); filter:brightness(1.08); }

@keyframes bl-pulse{0%{transform:scale(1)}50%{transform:scale(1.03)}100%{transform:scale(1)}}
@keyframes bl-shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-2px)}75%{transform:translateX(2px)}}
@keyframes bl-fade{0%{opacity:.7}100%{opacity:1}}
@keyframes bl-slide{0%{transform:translateY(0)}50%{transform:translateY(-2px)}100%{transform:translateY(0)}}

.bl-anim-none{}
.bl-anim-pulse{animation:bl-pulse 1.6s ease-in-out infinite;}
.bl-anim-shake{animation:bl-shake .6s linear infinite;}
.bl-anim-fade{animation:bl-fade 1.4s ease-in-out infinite;}
.bl-anim-slide{animation:bl-slide 1.4s ease-in-out infinite;}
/* === Модуль "кнопка – файл" (filebtn): стили и анимации === */
.el.filebtn .bf-filebtn{
  --bf-bg:#10b981; --bf-color:#ffffff; --bf-radius:12px; --bf-font-size:1em;
  display:flex; align-items:center; justify-content:center;
  width:100%; height:100%; box-sizing:border-box;
  padding:var(--bf-py,10px) var(--bf-px,16px);
  min-height:0; gap:8px;
  background:var(--bf-bg); color:var(--bf-color); border-radius:var(--bf-radius);
  text-decoration:none; font-weight:600; line-height:1;
  font-size:var(--bf-font-size);
  box-shadow:0 2px 6px rgba(0,0,0,.12);
  transition:transform .2s ease, filter .2s ease;
  will-change: transform, opacity, filter;
}
.el.filebtn .bf-filebtn:hover{ transform:scale(1.03); filter:brightness(1.08); }
.el.filebtn .bf-icon{ font-size:1.2em; line-height:1; }

/* Анимации для filebtn (используют те же keyframes что и linkbtn) */
.bf-anim-none{}
.bf-anim-pulse{animation:bl-pulse 1.6s ease-in-out infinite; -webkit-animation:bl-pulse 1.6s ease-in-out infinite;}
.bf-anim-shake{animation:bl-shake .6s linear infinite; -webkit-animation:bl-shake .6s linear infinite;}
.bf-anim-fade{animation:bl-fade 1.4s ease-in-out infinite; -webkit-animation:bl-fade 1.4s ease-in-out infinite;}
.bf-anim-slide{animation:bl-slide 1.4s ease-in-out infinite; -webkit-animation:bl-slide 1.4s ease-in-out infinite;}
/* === /filebtn === */
$css .= <<<CSS
/* === Дополнительные анимации для кнопок === */

/* Bounce - отскок */
@keyframes bl-bounce{
  0%, 100% { transform: translateY(0); }
  25% { transform: translateY(-6px); }
  50% { transform: translateY(0); }
  75% { transform: translateY(-3px); }
}
@-webkit-keyframes bl-bounce{
  0%, 100% { transform: translateY(0); }
  25% { transform: translateY(-6px); }
  50% { transform: translateY(0); }
  75% { transform: translateY(-3px); }
}

/* Glow - свечение */
@keyframes bl-glow{
  0%, 100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.3); }
  50% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.8), 0 0 30px rgba(59, 130, 246, 0.4); }
}
@-webkit-keyframes bl-glow{
  0%, 100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.3); }
  50% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.8), 0 0 30px rgba(59, 130, 246, 0.4); }
}

/* Rotate - вращение */
@keyframes bl-rotate{
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-3deg); }
  75% { transform: rotate(3deg); }
}
@-webkit-keyframes bl-rotate{
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-3deg); }
  75% { transform: rotate(3deg); }
}

/* Применение анимаций для linkbtn */
.bl-anim-bounce{animation:bl-bounce 1s ease-in-out infinite; -webkit-animation:bl-bounce 1s ease-in-out infinite;}
.bl-anim-glow{animation:bl-glow 1.5s ease-in-out infinite; -webkit-animation:bl-glow 1.5s ease-in-out infinite;}
.bl-anim-rotate{animation:bl-rotate 1.2s ease-in-out infinite; -webkit-animation:bl-rotate 1.2s ease-in-out infinite;}

/* Применение анимаций для filebtn */
.bf-anim-bounce{animation:bl-bounce 1s ease-in-out infinite; -webkit-animation:bl-bounce 1s ease-in-out infinite;}
.bf-anim-glow{animation:bl-glow 1.5s ease-in-out infinite; -webkit-animation:bl-glow 1.5s ease-in-out infinite;}
.bf-anim-rotate{animation:bl-rotate 1.2s ease-in-out infinite; -webkit-animation:bl-rotate 1.2s ease-in-out infinite;}


CSS;
$css .= <<<CSS
/* === Модуль "кнопка – ссылка" (linkbtn): стили и анимации === */
.el.linkbtn .bl-linkbtn{
  --bl-bg:#3b82f6; --bl-color:#ffffff; --bl-radius:12px;
  display:flex; align-items:center; justify-content:center;
  width:100%; height:100%; box-sizing:border-box;
  padding:var(--bl-py,10px) var(--bl-px,16px);
  min-height:0;
  background:var(--bl-bg); color:var(--bl-color); border-radius:var(--bl-radius);
  text-decoration:none; font-weight:600; line-height:1;
  font-size:var(--bl-font-size,1em);
  box-shadow:0 2px 6px rgba(0,0,0,.12);
  transition:transform .2s ease, filter .2s ease;
  will-change: transform, opacity, filter;
}
.el.linkbtn .bl-linkbtn:hover{ transform:scale(1.03); filter:brightness(1.08); }

@keyframes bl-pulse{0%{transform:scale(1)}50%{transform:scale(1.03)}100%{transform:scale(1)}}
@-webkit-keyframes bl-pulse{0%{transform:scale(1)}50%{transform:scale(1.03)}100%{transform:scale(1)}}

@keyframes bl-shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-2px)}75%{transform:translateX(2px)}}
@-webkit-keyframes bl-shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-2px)}75%{transform:translateX(2px)}}

@keyframes bl-fade{0%{opacity:.7}100%{opacity:1}}
@-webkit-keyframes bl-fade{0%{opacity:.7}100%{opacity:1}}

@keyframes bl-slide{0%{transform:translateY(0)}50%{transform:translateY(-2px)}100%{transform:translateY(0)}}
@-webkit-keyframes bl-slide{0%{transform:translateY(0)}50%{transform:translateY(-2px)}100%{transform:translateY(0)}}

.bl-anim-none{}
.bl-anim-pulse{animation:bl-pulse 1.6s ease-in-out infinite; -webkit-animation:bl-pulse 1.6s ease-in-out infinite;}
.bl-anim-shake{animation:bl-shake .6s linear infinite; -webkit-animation:bl-shake .6s linear infinite;}
.bl-anim-fade{animation:bl-fade 1.4s ease-in-out infinite; -webkit-animation:bl-fade 1.4s ease-in-out infinite;}
.bl-anim-slide{animation:bl-slide 1.4s ease-in-out infinite; -webkit-animation:bl-slide 1.4s ease-in-out infinite;}
/* === /linkbtn === */
/* === Text Animations (для анимированного текста из мини-редактора) === */
.ta { display: inline; }
@keyframes ta-appear-kf {
  from { opacity: 0; transform: translateY(0.35em); }
  to   { opacity: 1; transform: none; }
}
.ta-appear { animation: ta-appear-kf .6s ease both; }

@keyframes ta-blink-kf {
  0%, 49% { opacity: 1; }
  50%,100%{ opacity: 0; }
}
.ta-blink { animation: ta-blink-kf 1s step-start infinite; }

@keyframes ta-colorcycle-kf {
  0%   { color: var(--ta-origin, currentColor); }
  25%  { color: #ff1744; }
  50%  { color: #ff9100; }
  75%  { color: #2979ff; }
  100% { color: var(--ta-origin, currentColor); }
}
.ta-colorcycle { animation: ta-colorcycle-kf 2s linear infinite; }

@media (prefers-reduced-motion: reduce) {
  .ta-appear, .ta-blink, .ta-colorcycle { animation: none !important; }
}
CSS;

    file_put_contents($exportDir . '/assets/style.css', $css);
    
    // JavaScript файл
$editorH = (int)$CANVAS_H; // берём целое число
$js = <<<JS
(function() {
    'use strict';
    const DESKTOP_W = 1200, TABLET_W = 768, MOBILE_W = 375, EDITOR_H = {$editorH};

    // Функция для применения адаптивных стилей
    
    function applyResponsive() {
        const width = window.innerWidth;
        const elements = document.querySelectorAll('.el[data-tablet], .el[data-mobile]');
        
        elements.forEach(el => {
            try {
                let styles = {};
                let baseW = DESKTOP_W;
                
                if (width <= 480 && el.dataset.mobile) {
                    // Мобильные устройства
                    styles = JSON.parse(el.dataset.mobile);
                    baseW = MOBILE_W;
                } else if (width <= 768 && width > 480 && el.dataset.tablet) {
    // Планшеты
    styles = JSON.parse(el.dataset.tablet);
    baseW = TABLET_W;
                } else {
                    // Десктоп - восстанавливаем оригинальные стили
                    if (el.dataset.originalStyle) {
                        el.setAttribute('style', el.dataset.originalStyle);
                    }
                    return;
                }
                
                // Сохраняем оригинальные стили
                if (!el.dataset.originalStyle) {
                    el.dataset.originalStyle = el.getAttribute('style');
                }
                
                // Применяем адаптивные стили
                if (styles.left !== undefined) el.style.left = styles.left + '%';
                if (styles.top !== undefined) { el.style.top = ((styles.top / baseW) * 100).toFixed(4) + 'vw'; }
                if (styles.width !== undefined) el.style.width = styles.width + '%';
                if (styles.height !== undefined) {
    var hvw = ((((styles.height / 100) * EDITOR_H) / baseW) * 100).toFixed(4) + 'vw';
    if (el.dataset.type === 'text') {
        // для текстовых — min-height (чтобы низ совпадал с «ресайзом» из редактора)
        el.style.minHeight = hvw;
    } else {
        el.style.height = hvw;
    }
}

                if (styles.fontSize !== undefined) {
                    const textEl = el.querySelector('a, span, div');
                    if (textEl) textEl.style.fontSize = styles.fontSize + 'px';
                }
                if (styles.padding !== undefined) {
                    const padTarget = el.querySelector('a, span, div') || el;
                    padTarget.style.padding = styles.padding + 'px';
                }
                if (styles.radius !== undefined) {
                    el.style.borderRadius = styles.radius + 'px';
                    const rEl = el.querySelector('a');
                    if (rEl) rEl.style.borderRadius = styles.radius + 'px';
                }
                if (styles.rotate !== undefined) {
                    el.style.transform = 'rotate(' + styles.rotate + 'deg)';
                }
            } catch(e) {
                console.error('Error applying responsive styles:', e);
            }
        });
    }
    
    // Функция для обработки высоты контейнера
    function adjustWrapHeight() {
        const wrap = document.querySelector('.wrap');
        if (!wrap) return;
        
        const elements = document.querySelectorAll('.el');
        let maxBottom = 0;
        
        elements.forEach(el => {
            const rect = el.getBoundingClientRect();
            const bottom = el.offsetTop + rect.height;
            if (bottom > maxBottom) {
                maxBottom = bottom;
            }
        });
        
        if (maxBottom > 0) {
            wrap.style.minHeight = Math.max(maxBottom + 100, window.innerHeight) + 'px';
        }
    }
    
    // Функция для плавной прокрутки к якорям
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    // Функция для обработки ленивой загрузки изображений
    function initLazyLoad() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            observer.unobserve(img);
                        }
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }
    
    // Инициализация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        applyResponsive();
        adjustWrapHeight();
        initSmoothScroll();
        initLazyLoad();
    });
    
    // Обработка изменения размера окна
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 250);
    });
    
    // Обработка изменения ориентации устройства
    window.addEventListener('orientationchange', function() {
        setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 100);
    });
    
    // Автоопределение языка браузера при первом посещении
    if (!localStorage.getItem('site_lang_set')) {
        const browserLang = navigator.language.substring(0, 2);
        const currentLang = document.documentElement.lang;
        
        // Если язык браузера английский, а страница не английская
        if (browserLang === 'en' && currentLang !== 'en') {
            // Пытаемся найти английскую версию
            const currentPath = window.location.pathname;
            const currentFile = currentPath.split('/').pop() || 'index.html';
            
            // Определяем имя английской версии
            let enFile;
            if (currentFile === 'index.html' || currentFile === '') {
                enFile = '/index.html'; // Если английский основной
            } else if (currentFile.includes('-ru.html')) {
                enFile = currentFile.replace('-ru.html', '.html');
            } else {
                enFile = currentFile.replace('.html', '-en.html');
            }
            
            // Проверяем существование английской версии
            fetch(enFile, { method: 'HEAD' })
                .then(response => {
                    if (response.ok) {
                        localStorage.setItem('site_lang_set', 'true');
                        window.location.href = enFile;
                    }
                })
                .catch(() => {
                    localStorage.setItem('site_lang_set', 'true');
                });
        } else {
            localStorage.setItem('site_lang_set', 'true');
        }
    }
})();

/* === MOBILE PACK (из index.php, 1:1) === */
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

  // --- невидимый контейнер для измерения высоты "как в редакторе @375px" ---
  var measBox;
  function ensureMeasBox(){
    if (measBox) return measBox;
    measBox = document.createElement('div');
    measBox.style.cssText =
      'position:fixed; left:-9999px; top:0; width:375px; '+
      'visibility:hidden; pointer-events:none; z-index:-1; '+
      'line-height:normal; white-space:normal;';
    document.body.appendChild(measBox);
    return measBox;
  }

  function measureTextDesignHeight(el, widthDesign){
    var cs = getComputedStyle(el);
    var box = ensureMeasBox();
    box.style.fontFamily  = cs.fontFamily;
    box.style.fontSize    = cs.fontSize;
    box.style.fontWeight  = cs.fontWeight;
    box.style.fontStyle   = cs.fontStyle;
    box.style.letterSpacing = cs.letterSpacing;
    box.style.wordSpacing = cs.wordSpacing;
    box.style.textTransform = cs.textTransform;
    box.style.textDecoration = cs.textDecoration;
    box.style.fontVariant = cs.fontVariant;
    box.style.lineHeight  = cs.lineHeight;
    box.style.padding     = cs.padding;
    box.style.border      = cs.border;
    box.style.boxSizing   = cs.boxSizing;
    box.style.width       = widthDesign + 'px';
    box.innerHTML = el.innerHTML;
    return box.scrollHeight * (BASE_W / 375); // масштаб на BASE_W (тот же 375)
  }

  // --- фиксация "редакторских" данных для всех элементов ---
  function captureInitial(){
    var els = Array.prototype.slice.call(document.querySelectorAll('.el'));
    var stgW = stageWidth();
    var vw   = stgW / 100;

    initial = els.map(function(el){
      var cs = getComputedStyle(el);
      var isText = (el.dataset.type === 'text');
      var topVW = parseFloat(cs.top) || 0;      // топ в vw
      var wPer  = parseFloat(cs.width) || 0;    // ширина в %
      var hVW   = parseFloat(cs.height) || 0;   // высота в vw (для текста пересчитаем)

      // "редакторский" top в пикселях при ширине 375:
      var topDesign = topVW/100*BASE_W;

      // текущая фактическая ширина колонки на сцене
      var wPx = el.getBoundingClientRect().width;

      // "редакторская" ширина = доля от ширины сцены × 375
      var widthDesign = (wPx / stgW) * BASE_W;

      // учтём min-height (задан в vw, но в CS придёт в px — переведём в "дизайнерские" единицы)
var minHD = 0;
var mhPx = parseFloat(cs.minHeight) || 0;
if (mhPx) minHD = (mhPx / vw) * (BASE_W/100);

// "редакторская" высота
var measured = isText
  ? measureTextDesignHeight(el, widthDesign)
  : ((parseFloat(cs.height) || el.offsetHeight) / vw) * (BASE_W/100);

var heightDesign = Math.max(measured, minHD);


      return { el, isText, topDesign, widthDesign, heightDesign };
    });

    // "редакторский" зазор между соседями одной колонки
    for (var i=1;i<initial.length;i++){
      var prevIndex = -1;
      for (var j=i-1;j>=0;j--){
        if (horizOverlap(initial[j].el, initial[i].el)) { prevIndex = j; break; }
      }
      if (prevIndex>=0){
        initial[i].gapAbove = Math.max(0, initial[i].topDesign - (initial[prevIndex].topDesign + initial[prevIndex].heightDesign));
      } else {
        initial[i].gapAbove = initial[i].topDesign; // от верха сцены
      }
    }
  }

  function refreshHeights(){
    if (!initial) return;
    var stgW = stageWidth();
    var vw   = stgW / 100;

    initial.forEach(function(it){
      var cs = getComputedStyle(it.el);

      // min-height задан в px на текущем вьюпорте — переведём в "дизайнерские" единицы
      var minHD = 0;
      var mhPx = parseFloat(cs.minHeight) || 0;
      if (mhPx) minHD = (mhPx / vw) * (BASE_W / 100);

      if (it.isText){
        var wPx = it.el.getBoundingClientRect().width;
        var widthDesign = (wPx / stgW) * BASE_W;
        var measured = measureTextDesignHeight(it.el, widthDesign);
        it.heightDesign = Math.max(measured, minHD);
      } else {
        it.heightDesign = ((parseFloat(cs.height) || it.el.offsetHeight) / vw) * (BASE_W / 100);
      }
    });
}


  function applyFromInitial(){
    if (!initial) return;
    var stgW = stageWidth();
    var vw   = stgW / 100;

    var colGroups = [];
    for (var i=0;i<initial.length;i++){
      var it = initial[i];
      var col = -1;
      for (var c=0;c<colGroups.length;c++){
        if (horizOverlap(colGroups[c][0].el, it.el)){ col=c; break; }
      }
      if (col<0){ colGroups.push([it]); }
      else { colGroups[col].push(it); }
    }

    colGroups.forEach(function(col){
      col.sort(function(a,b){ return a.topDesign - b.topDesign; });
      var y=0;
      col.forEach(function(it, idx){
        var desiredTop = (idx===0)
          ? it.gapAbove
          : (y + it.gapAbove);
        y = desiredTop + it.heightDesign;
        it.el.style.top = (desiredTop / BASE_W * 100).toFixed(4) + 'vw';
      });
    });

    // Показать сцену (один раз, после первого применения)
    var stage = document.querySelector('.wrap');
    if (stage && stage.classList.contains('pack-pending')){
      stage.classList.remove('pack-pending');
    }

    // подставим реальную высоту сцены (чтобы не резалось)
    var maxBtm = 0;
    document.querySelectorAll('.el').forEach(function(el){
      var rect = el.getBoundingClientRect();
      var bottom = el.offsetTop + rect.height;
      if (bottom > maxBtm) maxBtm = bottom;
    });
    if (maxBtm>0){ stage.style.minHeight = Math.max(maxBtm + 100, window.innerHeight) + 'px'; }
  }

  function horizOverlap(a,b){
    var ar=a.getBoundingClientRect(), br=b.getBoundingClientRect();
    return !(ar.right<=br.left || ar.left>=br.right);
  }

  function onWidthMaybeChanged(){
    var w = Math.round(stageWidth());
    if (Math.abs(w - lastW) >= 2){
      lastW = w;
      refreshHeights();
      applyFromInitial();
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    captureInitial();
    refreshHeights();
    applyFromInitial();
    lastW = Math.round(stageWidth());
  });

  if (document.fonts && document.fonts.ready){
    document.fonts.ready.then(function(){ refreshHeights(); applyFromInitial(); });
  }

  if (window.visualViewport && window.visualViewport.addEventListener){
    window.visualViewport.addEventListener('resize', onWidthMaybeChanged, {passive:true});
  } else {
    window.addEventListener('resize', onWidthMaybeChanged, {passive:true});
  }
  window.addEventListener('orientationchange', function(){ setTimeout(onWidthMaybeChanged, 250); }, {passive:true});
})();

JS;
    
    file_put_contents($exportDir . '/assets/js/main.js', $js);
}

function copyUsedFiles($usedFiles, $exportDir) {
    foreach ($usedFiles as $file) {
        if (file_exists($file['source'])) {
            $destPath = $exportDir . '/' . $file['dest'];
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            
            @copy($file['source'], $destPath);
        }
    }
}
function copyHtmlImages($exportDir) {
    try {
        // export.php находится в /editor/, поэтому dirname(__DIR__) = корень проекта
        $projectRoot = dirname(__DIR__);
        $htmlImagesDir = $projectRoot . '/assets/html-images';
        
        // Если папки нет - просто выходим, не ломая экспорт
        if (!is_dir($htmlImagesDir)) {
            return;
        }
        
        // Проверяем, есть ли файлы в папке
        $files = @scandir($htmlImagesDir);
        if ($files === false || count($files) <= 2) { // только . и ..
            return;
        }
        
        // Создаем папку в экспорте
        $exportImagesDir = $exportDir . '/assets/html-images';
        if (!is_dir($exportImagesDir)) {
            @mkdir($exportImagesDir, 0777, true);
        }
        
        // Копируем все изображения
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $sourcePath = $htmlImagesDir . '/' . $file;
            $destPath = $exportImagesDir . '/' . $file;
            
            // Копируем только файлы (не папки)
            if (is_file($sourcePath)) {
                @copy($sourcePath, $destPath);
            }
        }
    } catch (Exception $e) {
        // Если произошла ошибка - логируем, но не ломаем экспорт
        error_log('copyHtmlImages error: ' . $e->getMessage());
    }
}
function copyHtmlVideos($exportDir) {
    try {
        // export.php находится в /editor/, поэтому dirname(__DIR__) = корень проекта
        $projectRoot = dirname(__DIR__);
        $htmlVideosDir = $projectRoot . '/assets/html-videos';
        
        // Если папки нет - просто выходим, не ломая экспорт
        if (!is_dir($htmlVideosDir)) {
            return;
        }
        
        // Проверяем, есть ли файлы в папке
        $files = @scandir($htmlVideosDir);
        if ($files === false || count($files) <= 2) { // только . и ..
            return;
        }
        
        // Создаем папку в экспорте
        $exportVideosDir = $exportDir . '/assets/html-videos';
        if (!is_dir($exportVideosDir)) {
            @mkdir($exportVideosDir, 0777, true);
        }
        
        // Копируем все видео
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $sourcePath = $htmlVideosDir . '/' . $file;
            $destPath = $exportVideosDir . '/' . $file;
            
            // Копируем только файлы (не папки)
            if (is_file($sourcePath)) {
                @copy($sourcePath, $destPath);
            }
        }
    } catch (Exception $e) {
        // Если произошла ошибка - логируем, но не ломаем экспорт
        error_log('copyHtmlVideos error: ' . $e->getMessage());
    }
}
function generateHtaccess($exportDir) {

    $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# === БЕЗОПАСНОСТЬ ===
<FilesMatch "\\.(htaccess|htpasswd|ini|log|sh|sql|sqlite|db)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# === SEO: HTTPS (раскомментируйте если есть SSL) ===
# RewriteCond %{HTTPS} off
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# === SEO: Редирект на без WWW (раскомментируйте если нужно) ===
# RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]
# RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]

# === SEO: CANONICAL URL ===
# Удаление trailing slash (кроме корня)
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} (.+)/$
RewriteRule ^ %1 [L,R=301]



# === МНОГОЯЗЫЧНОСТЬ ===
# Автоопределение языка браузера (только для главной страницы)
RewriteCond %{HTTP:Accept-Language} ^en [NC]
RewriteCond %{REQUEST_URI} ^/?$
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^$ /index.html [L,R=302]

# === КРАСИВЫЕ URL ===
# Убираем .html из URL для всех страниц включая языковые версии
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([^/]+)/?$ $1.html [L]

# Редирект с .html на без .html (301 для SEO)
RewriteCond %{THE_REQUEST} \\s/+([^/]+)\\.html[\\s?] [NC]
RewriteRule ^ /%1 [R=301,L]

# === ПРОИЗВОДИТЕЛЬНОСТЬ ===
# Кеширование статических файлов
<IfModule mod_expires.c>
    ExpiresActive On
    
    # Изображения
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/x-icon "access plus 1 year"
    
    # Шрифты
    ExpiresByType font/woff "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
    ExpiresByType application/font-woff "access plus 1 year"
    ExpiresByType application/font-woff2 "access plus 1 year"
    
    # CSS и JS
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType text/javascript "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    
    # HTML (короткий кеш)
    ExpiresByType text/html "access plus 1 hour"
</IfModule>

# Cache-Control headers
<IfModule mod_headers.c>
    # Immutable для статики
    <FilesMatch "\\.(jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    
    # CSS/JS
    <FilesMatch "\\.(css|js)$">
        Header set Cache-Control "public, max-age=2592000"
    </FilesMatch>
    
    # HTML
    <FilesMatch "\\.html$">
        Header set Cache-Control "public, max-age=3600, must-revalidate"
    </FilesMatch>
</IfModule>

# Сжатие
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json application/xml application/rss+xml

    
    # Не сжимать уже сжатые форматы
    SetEnvIfNoCase Request_URI \\.(?:gif|jpe?g|png|webp|woff2?)$ no-gzip
</IfModule>
</IfModule>
HTACCESS;
    
    file_put_contents($exportDir . '/.htaccess', $htaccess);
}

function generateNginxConfig($exportDir) {
    $nginx = <<<'NGINX'
# Nginx конфигурация для экспортированного сайта

# ============================================
# HTTP → HTTPS редирект (если используется SSL)
# ============================================
server {
    listen 80;
    listen [::]:80;
    server_name example.com www.example.com;  # ← замените на ваш домен
    
    # Редирект всего трафика на HTTPS
    return 301 https://$host$request_uri;
}

# ============================================
# HTTPS сервер (основной)
# ============================================
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name example.com www.example.com;  # ← замените на ваш домен
    
    # Путь к файлам сайта
    root /var/www/example.com/public;  # ← замените на путь к экспорту
    index index.html;
    
    # ============================================
    # SSL сертификаты (Let's Encrypt)
    # ============================================
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;  # ← замените домен
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;  # ← замените домен
    
    # Современные SSL настройки
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # OCSP Stapling
    ssl_stapling on;
    ssl_stapling_verify on;
    ssl_trusted_certificate /etc/letsencrypt/live/example.com/chain.pem;  # ← замените домен
    resolver 8.8.8.8 8.8.4.4 valid=300s;
    resolver_timeout 5s;
    
    # ============================================
    # Безопасность
    # ============================================
    # HSTS (принудительный HTTPS на 1 год)
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    
    # Защита от clickjacking
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Защита от служебных файлов
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    location ~ \.(htaccess|htpasswd|ini|log|sh|sql|sqlite|db)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
    
    # ============================================
    # Обработка .html расширений (красивые URL)
    # ============================================
    # Убираем .html из URL
    location / {
        try_files $uri $uri.html $uri/ =404;
    }
    
    # Редирект с .html на без .html (301 для SEO)
    location ~ \.html$ {
        if ($request_uri ~ ^(.*)\.html$) {
            return 301 $1;
        }
    }
    
    # ============================================
    # Кеширование статических файлов
    # ============================================
    location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
    
    location ~* \.(css|js)$ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
        access_log off;
    }
    
    # ============================================
    # Сжатие
    # ============================================
    gzip on;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml+rss;
    gzip_vary on;
    gzip_proxied any;
    gzip_disable "msie6";
}
NGINX;
    
    file_put_contents($exportDir . '/nginx.conf.example', $nginx);
}

function generateRobots($exportDir, $pages = [], $languages = []) {
    // Создаем robots.txt с относительным путем к sitemap (работает для любого домена)
    $robots = <<<'TXT'
User-agent: *
Allow: /
Disallow: /editor/
Disallow: /data/

# Sitemap с автоопределением домена
Sitemap: /sitemap.php
TXT;

    file_put_contents($exportDir . '/robots.txt', $robots);
}

function generateSitemap($exportDir, $pages, $languages, $primaryLang = 'ru') {
    // Создаем динамический sitemap.php вместо статического sitemap.xml
    $sitemapPhp = <<<'PHP'
<?php
header('Content-Type: application/xml; charset=utf-8');

// Автоопределение текущего домена
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "{$scheme}://{$host}";

// Данные страниц и языков встроены в скрипт
$pages = PAGES_DATA;
$languages = LANGUAGES_DATA;
$primaryLang = PRIMARY_LANG;

// Функция получения URL страницы
function getPageUrl($page, $lang, $baseUrl, $primaryLang) {
    $isHome = !empty($page['is_home']);
    
    if ($isHome) {
    if ($lang === $primaryLang) {
        // канонический URL главной — корень
        return $baseUrl . '/';
    } else {
        // статический файл главной для локали
        return $baseUrl . '/index-' . $lang . '.html';
    }
}

    
    $slug = $page['slug'] ?? '';
    if ($slug) {
        $filename = $slug;
    } else {
        $filename = 'page_' . $page['id'];
    }
    
    // Для экспорта используем .html расширение
    if ($lang !== $primaryLang) {
        $filename .= '-' . $lang;
    }
    
    return $baseUrl . '/' . $filename . '.html';
}
// Путь к физическому файлу — для вычисления lastmod
function getPagePath($page, $lang, $primaryLang) {
    $isHome = !empty($page['is_home']);
    if ($isHome) {
        return ($lang === $primaryLang) ? 'index.html' : ('index-' . $lang . '.html');
    }
    $slug = $page['slug'] ?? '';
    $filename = $slug ? $slug : ('page_' . $page['id']);
    if ($lang !== $primaryLang) $filename .= '-' . $lang;
    return $filename . '.html';
}

// Генерация sitemap
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

foreach ($pages as $page) {
    foreach ($languages as $lang) {
        $loc = getPageUrl($page, $lang, $baseUrl, $primaryLang);
        $priority = !empty($page['is_home']) ? '1.0' : '0.8';
        if ($lang !== $primaryLang && !empty($page['is_home'])) {
            $priority = '0.9';
        } elseif ($lang !== $primaryLang) {
            $priority = '0.7';
        }
        
        echo "  <url>\n";
        echo "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
        
        // lastmod из mtime файла (если доступен)
$rel = getPagePath($page, $lang, $primaryLang);
$abs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/') . '/' . ltrim($rel, '/');
$lastmod = is_file($abs) ? gmdate('c', filemtime($abs)) : gmdate('c');
echo "    <lastmod>{$lastmod}</lastmod>\n";

// Альтернативные языковые версии
foreach ($languages as $altLang) {
    $altLoc = getPageUrl($page, $altLang, $baseUrl, $primaryLang);
    echo "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLang}\" href=\"" . htmlspecialchars($altLoc, ENT_XML1) . "\"/>\n";
}
// x-default → на основной язык
$defLoc = getPageUrl($page, $primaryLang, $baseUrl, $primaryLang);
echo "    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . htmlspecialchars($defLoc, ENT_XML1) . "\"/>\n";

echo "    <changefreq>weekly</changefreq>\n";
echo "    <priority>{$priority}</priority>\n";
echo "  </url>\n";

    }
}

echo '</urlset>';
PHP;

    // Подготовка данных для встраивания
    $pagesData = [];
    foreach ($pages as $page) {
        $pagesData[] = [
            'id' => $page['id'],
            'slug' => $page['slug'] ?? '',
            'is_home' => !empty($page['is_home']) ? true : false
        ];
    }
    
    // Определяем языки по реально сгенерированным HTML-файлам
$present = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($exportDir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    if (strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION)) !== 'html') continue;
    $name = pathinfo($f->getFilename(), PATHINFO_FILENAME);
    if ($name === 'index') {
        $present[$primaryLang] = true;
        continue;
    }
    if (preg_match('~^.+-([A-Za-z\-]+)$~', $name, $m)) {
        $present[$m[1]] = true; // поддерживает zh-Hans
    } else {
        $present[$primaryLang] = true;
    }
}
$effectiveLangs = array_keys($present);

// Сохраним “красивый” порядок: сначала основной, потом как в $languages, затем прочее
$order = array_values(array_unique(array_merge([$primaryLang], $languages, $effectiveLangs)));
$effectiveLangs = $order;

$sitemapPhp = str_replace(
    ['PAGES_DATA', 'LANGUAGES_DATA', 'PRIMARY_LANG'],
    [
        var_export($pagesData, true),
        var_export($effectiveLangs, true),
        var_export($primaryLang, true)
    ],
    $sitemapPhp
);

file_put_contents($exportDir . '/sitemap.php', $sitemapPhp);

    
    // Также создаем sitemap.xml как редирект на sitemap.php для совместимости
    $sitemapXml = <<<'XML'
<?php
header('Location: /sitemap.php', true, 301);
exit;
XML;
    file_put_contents($exportDir . '/sitemap.xml', $sitemapXml);
}

function createZipArchive($sourceDir) {
    $zipFile = sys_get_temp_dir() . '/export_' . time() . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Cannot create ZIP archive');
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourceDir) + 1);
        
        // Нормализуем путь для ZIP
        $relativePath = str_replace('\\', '/', $relativePath);
        
        $zip->addFile($filePath, $relativePath);
    }
    
    $zip->close();
    return $zipFile;
}

function generateReadme($exportDir, $languages) {
    $langList = implode(', ', $languages);
    $readme = <<<README
# Экспортированный сайт

## Структура файлов

Все страницы находятся в корневой папке с языковыми суффиксами:
- `index.html` - главная страница (русский язык)
- `index-en.html` - главная страница (английский язык)
- `about.html` - страница "О нас" (русский язык)
- `about-en.html` - страница "О нас" (английский язык)
- и т.д.

## Языковые версии

Поддерживаемые языки: {$langList}

Русский язык является основным и не имеет суффикса в именах файлов.
Остальные языки добавляют суффикс `-код_языка` к имени файла.

## Структура папок

```
/
├── assets/
│   ├── style.css         # Основные стили
│   ├── js/
│   │   └── main.js       # JavaScript для адаптивности
│   └── uploads/          # Загруженные файлы
├── index.html            # Главная страница (RU)
├── index-en.html         # Главная страница (EN)
├── .htaccess             # Конфигурация Apache
├── nginx.conf.example    # Пример конфигурации Nginx
├── robots.txt            # Для поисковых роботов
└── sitemap.xml           # Карта сайта
```

## Установка на хостинг

### Apache
Файл `.htaccess` уже настроен. Просто загрузите все файлы на хостинг.

### Nginx
Используйте настройки из файла `nginx.conf.example`, добавив их в конфигурацию сервера.

## Особенности

1. **Красивые URL**: расширение .html автоматически скрывается
2. **Адаптивность**: сайт адаптирован для мобильных устройств и планшетов
3. **Многоязычность**: встроенный переключатель языков
4. **SEO-оптимизация**: sitemap.xml с поддержкой hreflang
5. **Производительность**: настроено кеширование и сжатие

## Поддержка

Сайт создан с помощью конструктора Zerro Blog.
README;
    
    file_put_contents($exportDir . '/README.md', $readme);
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    rmdir($dir);
}
function generateRemoteAPI($exportDir) {
    $apiContent = <<<'PHP'
<?php
// Remote Management API for exported site - Enhanced Version
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$action = $_REQUEST["action"] ?? "";

/**
 * Парсит HTML файл и извлекает все кнопки
 */
function parseHtmlFile($filePath) {
    if (!file_exists($filePath)) return ['links' => [], 'files' => []];
    
    $content = file_get_contents($filePath);
    $links = [];
    $files = [];
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    
    // Ищем кнопки-ссылки (все варианты)
    $linkQueries = [
        "//a[contains(@class, 'bl-linkbtn')]",
        "//a[@data-type='linkbtn']",
        "//div[contains(@class, 'linkbtn')]//a[not(@download)]",
        "//div[@data-type='linkbtn']//a[not(@download)]"
    ];
    
    foreach ($linkQueries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes) {
            foreach ($nodes as $node) {
                $url = $node->getAttribute('href');
                if ($url && $url !== '#' && !$node->hasAttribute('download')) {
                    $links[$url] = [
                        'url' => $url,
                        'text' => trim($node->textContent),
                        'type' => 'linkbtn'
                    ];
                }
            }
        }
    }
    
    // Ищем кнопки-файлы (все варианты)
    $fileQueries = [
        "//a[contains(@class, 'bf-filebtn')]",
        "//a[@data-type='filebtn']",
        "//div[contains(@class, 'filebtn')]//a",
        "//div[@data-type='filebtn']//a",
        "//a[@download]"
    ];
    
    foreach ($fileQueries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes) {
            foreach ($nodes as $node) {
                $url = $node->getAttribute('href');
                $fileName = $node->getAttribute('download') ?: $node->getAttribute('data-file-name') ?: basename($url);
                
                if ($url && $url !== '#' && strpos($url, '#') !== 0 && strpos($url, 'javascript:') !== 0) {
                    $files[$url] = [
                        'url' => $url,
                        'name' => $fileName,
                        'text' => trim(strip_tags($node->textContent)),
                        'type' => 'filebtn'
                    ];
                }
            }
        }
    }
    
    libxml_clear_errors();
    
    return [
        'links' => array_values($links),
        'files' => array_values($files)
    ];
}

/**
 * Заменяет URL в HTML файле
 */
function replaceInHtmlFile($filePath, $oldUrl, $newUrl, $isFile = false, $newFileName = '') {
    if (!file_exists($filePath)) return 0;
    
    $content = file_get_contents($filePath);
    $count = 0;
    
    // Генерируем варианты старого URL
    $oldUrlVariants = generateUrlVariants($oldUrl);
    $oldFileName = basename($oldUrl);
    
    if ($isFile) {
        // Замена в ЛЮБЫХ ссылках с download (ищем ВСЕ файлы - и из редактора и из HTML)
        $pattern = '~(<a\s+[^>]*href=")([^"]+)("[^>]*)(download=")([^"]+)(")~is';
        
        $content = preg_replace_callback($pattern, function($matches) use ($oldUrlVariants, $oldFileName, $newUrl, $newFileName, &$count) {
            $currentUrl = $matches[2];
            $currentFileName = $matches[5];
            
            // Проверяем совпадение по URL или имени файла
            if (in_array($currentUrl, $oldUrlVariants) || $currentFileName === $oldFileName) {
                $count++;
                return $matches[1] . $newUrl . $matches[3] . $matches[4] . ($newFileName ?: $currentFileName) . $matches[6];
            }
            
            return $matches[0];
        }, $content);
        
        // Также меняем data-file-name если есть
        if ($newFileName) {
            $content = preg_replace(
                '~(data-file-name=")' . preg_quote($oldFileName, '~') . '(")~i',
                '$1' . $newFileName . '$2',
                $content
            );
        }
        
    } else {
        // Замена в ЛЮБЫХ ссылках БЕЗ download - ищем ВСЕ <a href="">
        $pattern = '~(<a\s+[^>]*href=")([^"]+)("(?![^>]*download)[^>]*>)~is';
        
        $content = preg_replace_callback($pattern, function($matches) use ($oldUrlVariants, $newUrl, &$count) {
            $currentUrl = $matches[2];
            
            if (in_array($currentUrl, $oldUrlVariants)) {
                $count++;
                return $matches[1] . $newUrl . $matches[3];
            }
            
            return $matches[0];
        }, $content);
    }
    
    if ($count > 0) {
        file_put_contents($filePath, $content);
    }
    
    return $count;
}

/**
 * Генерирует варианты URL
 */
function generateUrlVariants($url) {
    $variants = [$url];
    
    if (strpos($url, 'www.') !== false) {
        $variants[] = str_replace('www.', '', $url);
    } else if (strpos($url, '://') !== false) {
        $variants[] = str_replace('://', '://www.', $url);
    }
    
    $temp = [];
    foreach ($variants as $variant) {
        $temp[] = $variant;
        if (substr($variant, -1) === '/') {
            $temp[] = substr($variant, 0, -1);
        } else {
            $temp[] = $variant . '/';
        }
    }
    
    return array_unique($temp);
}

switch($action) {
    case "ping":
        echo json_encode(["ok" => true, "version" => "2.0-enhanced"]);
        break;
        
    case "list_files":
        $allFiles = [];
        
        foreach(glob("*.html") as $htmlFile) {
            $parsed = parseHtmlFile($htmlFile);
            $allFiles = array_merge($allFiles, $parsed['files']);
        }
        
        // Убираем дубликаты по URL
        $unique = [];
        foreach($allFiles as $file) {
            $key = $file["url"];
            if (!isset($unique[$key])) {
                $unique[$key] = $file;
            }
        }
        
        echo json_encode(["ok" => true, "items" => array_values($unique)]);
        break;
        
    case "list_links":
        $allLinks = [];
        
        foreach(glob("*.html") as $htmlFile) {
            $parsed = parseHtmlFile($htmlFile);
            $allLinks = array_merge($allLinks, $parsed['links']);
        }
        
        // Убираем дубликаты по URL
        $unique = [];
        foreach($allLinks as $link) {
            $key = $link["url"];
            if (!isset($unique[$key])) {
                $unique[$key] = $link;
            }
        }
        
        echo json_encode(["ok" => true, "items" => array_values($unique)]);
        break;
        
    case "replace_file":
        $oldUrl = $_POST["old_url"] ?? "";
        $fileName = $_POST["file_name"] ?? "";
        $fileContent = $_POST["file_content"] ?? "";
        
        if (!$oldUrl || !$fileName || !$fileContent) {
            echo json_encode(["ok" => false, "error" => "Missing parameters"]);
            break;
        }
        
        // Сохраняем новый файл
        $uploadDir = "assets/uploads/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        
        $newFileName = basename($fileName);
        $newPath = $uploadDir . $newFileName;
        
        $decodedContent = base64_decode($fileContent);
        if ($decodedContent === false) {
            echo json_encode(["ok" => false, "error" => "Failed to decode file content"]);
            break;
        }
        
        file_put_contents($newPath, $decodedContent);
        
        if (!file_exists($newPath)) {
            echo json_encode(["ok" => false, "error" => "Failed to save file"]);
            break;
        }
        
        // Заменяем во всех HTML файлах используя DOM парсер
        $replaced = 0;
        $totalFiles = 0;
        
        foreach(glob("*.html") as $htmlFile) {
            $totalFiles++;
            $count = replaceInHtmlFile($htmlFile, $oldUrl, $newPath, true, $newFileName);
            $replaced += $count;
        }
        
        echo json_encode([
            "ok" => true,
            "replaced" => $replaced,
            "new_path" => $newPath,
            "total_files" => $totalFiles
        ]);
        break;
        
    case "replace_link":
        $oldUrl = $_POST["old_url"] ?? "";
        $newUrl = $_POST["new_url"] ?? "";
        
        if (!$oldUrl || !$newUrl) {
            echo json_encode(["ok" => false, "error" => "Missing parameters"]);
            break;
        }
        
        // Заменяем во всех HTML файлах используя DOM парсер
        $replaced = 0;
        
        foreach(glob("*.html") as $htmlFile) {
            $count = replaceInHtmlFile($htmlFile, $oldUrl, $newUrl, false);
            $replaced += $count;
        }
        
        echo json_encode(["ok" => true, "replaced" => $replaced]);
        break;
        
    default:
        echo json_encode(["ok" => false, "error" => "Unknown action"]);
}
PHP;
    
    file_put_contents($exportDir . '/remote-api.php', $apiContent);
}