<?php
/**
 * Export Finalizer — Variant C (железобетонный)
 * Поддерживает: валидацию домена, canonical/hreflang инъекцию в HTML,
 * статический sitemap.xml с xhtml:link, валидный robots.txt (абсолютный Sitemap),
 * генерацию .htaccess / nginx.conf для редиректов, diagnostics.txt,
 * упаковку в ZIP и CLI-запуск.
 *
 * PHP 7.2+ (ZipArchive требуется).
 */

namespace Export\Finalizer;

if (!class_exists(__NAMESPACE__ . '\PostExport')) {

    class PostExport
    {
        /** Точка входа (и для веб, и для CLI) */
        public static function entry(array $opts = []): void
        {
            $self = new self();
            $self->run($opts);
        }

        /** Основная логика */
        public function run(array $opts): void
        {
            $opt = Options::fromArray($opts);

            if (!is_dir($opt->exportDir)) {
                $this->fail("Export dir not found: {$opt->exportDir}");
            }

            // 1) Сканируем HTML, формируем карту страниц/языков
            $scan = ExportScanner::scanHtml($opt->exportDir, $opt->primaryLang);
            if (empty($scan['pages'])) {
                $this->fail("No HTML pages were found in export dir: {$opt->exportDir}");
            }
            $pagesBySlug = $scan['pages']; // [slug => [ lang => ['path'=>rel, 'is_home'=>bool] ]]
            $langs       = $scan['langs']; // ['ru','en',...]

            // 2) Построитель URL-ов
            $url = new UrlBuilder($opt);

            // 3) Инъекция SEO-тегов в каждый HTML
            $inj = new HtmlHeadInjector($opt, $url, $langs, $pagesBySlug);
            $inj->processAll();

            // 4) Генерация sitemap.xml и robots.txt
            (new SitemapBuilder($opt, $url, $langs, $pagesBySlug))->make();
            (new RobotsBuilder($opt, $url))->make();

            // 5) Конфиги редиректов
            (new ConfBuilder($opt, $url))->make();

            // 6) Диагностика
            (new Diagnostics($opt, $langs, $pagesBySlug))->write();

            // 7) Упаковка в ZIP + отдача/вывод результата
            $zipPath = Zipper::pack($opt);
            Out::deliver($zipPath, $opt);
        }

        private function fail(string $msg): void
        {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "[Export Finalizer] ERROR: {$msg}\n");
                exit(2);
            }
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "[Export Finalizer] ERROR: {$msg}";
            exit;
        }
    }

    /** ================== Модель опций ================== */
    final class Options
    {
        public $exportDir;
        public $domain;        // https://example.com
        public $host;          // example.com
        public $https;         // 1/0
        public $wwwMode;       // keep|www|non-www
        public $forceHost;     // 1/0 — принуд. редирект на домен
        public $primaryLang;   // ru
        public $zipName;       // имя архива

        public static function fromArray(array $a): self
        {
            $self = new self();

            // export_dir (обяз.)
            $self->exportDir = rtrim((string)($a['export_dir'] ?? ''), '/');
            if ($self->exportDir === '') {
                self::failStatic('Option export_dir is required');
            }

            // схема/домен/редиректы
            $domainRaw       = trim((string)($a['domain'] ?? ''));
            $httpsFlag       = (int)($a['https'] ?? 1);
            $wwwMode         = (string)($a['www_mode'] ?? 'keep');     // keep|www|non-www
            $forceHost       = (int)($a['force_host'] ?? 0);

            // язык по умолчанию
            $primaryLang     = trim((string)($a['primary_lang'] ?? 'ru'));
            if ($primaryLang === '') $primaryLang = 'ru';

            // zip name
            $zipName         = (string)($a['zip_name'] ?? ('site-' . date('Ymd-His') . '.zip'));

            // Нормализуем домен
            [$domain, $host] = self::normalizeDomain($domainRaw, $httpsFlag, $wwwMode);

            $self->domain      = $domain;
            $self->host        = $host;
            $self->https       = $httpsFlag ? 1 : 0;
            $self->wwwMode     = in_array($wwwMode, ['keep','www','non-www'], true) ? $wwwMode : 'keep';
            $self->forceHost   = $forceHost ? 1 : 0;
            $self->primaryLang = $primaryLang;
            $self->zipName     = $zipName;

            return $self;
        }

        /** Привести домен к виду https://example.com + host (IDN→ASCII, www‑режим) */
        private static function normalizeDomain(string $raw, int $https, string $wwwMode): array
        {
            $raw = trim($raw);
            if ($raw === '') {
                // Разрешаем экспорт без домена. Тогда canonical/во всех местах поставим {{BASE_URL}}
                return ['{{BASE_URL}}', ''];
            }
            // Добавим схему, если не указана
            if (!preg_match('~^https?://~i', $raw)) {
                $raw = ($https ? 'https://' : 'http://') . $raw;
            }
            $p = parse_url($raw);
            $host = strtolower($p['host'] ?? '');
            if ($host === '') self::failStatic('Invalid domain provided');

            // IDN → ASCII если доступно
            if (function_exists('idn_to_ascii')) {
                $idn = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46);
                if ($idn) $host = $idn;
            }

            // www режим
            if ($wwwMode === 'www' && strpos($host, 'www.') !== 0) {
                $host = 'www.' . $host;
            } elseif ($wwwMode === 'non-www' && strpos($host, 'www.') === 0) {
                $host = substr($host, 4);
            }

            $scheme = $https ? 'https' : (isset($p['scheme']) ? strtolower($p['scheme']) : 'https');
            $domain = $scheme . '://' . $host;

            return [$domain, $host];
        }

        private static function failStatic(string $msg): void
        {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "[Export Finalizer] ERROR: {$msg}\n");
                exit(2);
            }
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "[Export Finalizer] ERROR: {$msg}";
            exit;
        }
    }

    /** ================== Сканер HTML ================== */
    final class ExportScanner
    {
        /** Возвращает ['pages'=>[slug=>[lang=>['path','is_home']]], 'langs'=>[]] */
        public static function scanHtml(string $root, string $primaryLang): array
        {
            $pages = [];
            $langs = [$primaryLang => true];

            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($rii as $file) {
                if ($file->isDir()) continue;
                $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                if ($ext !== 'html') continue;

                $rel = self::relPath($root, $file->getPathname());
                $base = basename($rel, '.html');

                $isHome = false;
                $lang = $primaryLang;
                $slug = $base;

                if ($base === 'index') {
                    $isHome = true;
                    $slug = 'index';
                    $lang = $primaryLang;
                } elseif (preg_match('~^index-([A-Za-z\-]+)$~', $base, $m)) {
                    $isHome = true;
                    $slug   = 'index';
                    $lang   = $m[1];
                } elseif (preg_match('~^(.+)-([A-Za-z\-]+)$~', $base, $m)) {
                    $slug = $m[1];
                    $lang = $m[2];
                } else {
                    $slug = $base;
                    $lang = $primaryLang;
                }

                $langs[$lang] = true;
                $pages[$slug][$lang] = ['path' => '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel), 'is_home' => $isHome];
            }

            return ['pages' => $pages, 'langs' => array_keys($langs)];
        }

        private static function relPath(string $root, string $abs): string
        {
            $root = rtrim(str_replace('\\','/',$root), '/') . '/';
            $abs  = str_replace('\\','/',$abs);
            return ltrim(substr($abs, strlen($root)), '/');
        }
    }

    /** ================== Построитель URL ================== */
    final class UrlBuilder
    {
        private $opt;
        public function __construct(Options $opt) { $this->opt = $opt; }

        public function base(): string { return rtrim($this->opt->domain, '/'); }

        /** Абсолютный URL для конкретной страницы */
        public function abs(string $path): string
        {
            if ($this->opt->domain === '{{BASE_URL}}') {
                return '{{BASE_URL}}' . $path;
            }
            return $this->base() . $path;
        }

        /** Путь до файла по slug/lang (совпадает с именованием файлов в экспорте) */
        public function pathFor(string $slug, string $lang, bool $isHome): string
        {
            $pl = $this->opt->primaryLang;
            if ($slug === 'index' && $isHome) {
                return ($lang === $pl) ? '/' : '/index-' . $lang . '.html';
            }
            if ($lang === $pl) return '/' . $slug . '.html';
            return '/' . $slug . '-' . $lang . '.html';
        }
    }

    /** ================== Инъектор SEO-тегов ================== */
    final class HtmlHeadInjector
    {
        private $opt, $url, $langs, $pages;
        public function __construct(Options $o, UrlBuilder $u, array $langs, array $pages)
        { $this->opt=$o; $this->url=$u; $this->langs=$langs; $this->pages=$pages; }

        public function processAll(): void
        {
            foreach ($this->pages as $slug => $byLang) {
                foreach ($byLang as $lang => $meta) {
                    $this->processFile($slug, $lang, $meta['path'], $meta['is_home']);
                }
            }
        }

        private function processFile(string $slug, string $lang, string $relPath, bool $isHome): void
{
    $abs = rtrim($this->opt->exportDir, '/') . $relPath;
    if (!is_file($abs)) return;

    $html = @file_get_contents($abs);
if ($html === false) return;

// Нормализация двойного экранирования (&amp;amp; -> &amp;) в <title> и базовых мета-тегах
// <title>
$html = preg_replace_callback('~<title>([\s\S]*?)</title>~i', function($m){
    $txt = str_replace('&amp;amp;', '&amp;', $m[1]);
    return '<title>' . $txt . '</title>';
}, $html);

// meta name=description / og:title / og:description / twitter:title / twitter:description
$html = preg_replace_callback(
    '~(<meta\b[^>]+(?:name|property)=["\'](?:description|og:title|og:description|twitter:title|twitter:description)["\'][^>]*\scontent=["\'])([\s\S]*?)(["\'][^>]*>)~i',
    function($m){
        $val = str_replace('&amp;amp;', '&amp;', $m[2]);
        return $m[1] . $val . $m[3];
    },
    $html
);


    // 0) Сносим прежние canonical/alternate/og:url/twitter:url + og:locale(+alternate)
$html = preg_replace('~<link[^>]+rel=["\']canonical["\'][^>]*>\s*~i', '', $html);
$html = preg_replace('~<link[^>]+rel=["\']alternate["\'][^>]*hreflang=.+?>\s*~i', '', $html);
$html = preg_replace('~<meta[^>]+property=["\']og:url["\'][^>]*>\s*~i', '', $html);
$html = preg_replace('~<meta[^>]+name=["\']twitter:url["\'][^>]*>\s*~i', '', $html);
$html = preg_replace('~<meta[^>]+property=["\']og:locale(?::alternate)?["\'][^>]*>\s*~i', '', $html);


    $baseUrl = $this->url->base();
    $absMode = ($baseUrl !== '{{BASE_URL}}'); // true, если домен задан в модалке

    // 1) Определяем, надо ли убирать .html (по экспортированным .htaccess/nginx.conf)
    $stripHtmlExt = false;
    $dir = rtrim($this->opt->exportDir ?? '', '/');
    if ($dir && is_file($dir . '/.htaccess')) {
        $ht = @file_get_contents($dir . '/.htaccess');
        if ($ht && preg_match('~RewriteRule\s+\^\(.*\\\.html\$\)\s+\$1~', $ht)) {
            $stripHtmlExt = true;
        }
    }
    if (!$stripHtmlExt && $dir && is_file($dir . '/nginx.conf')) {
        $ng = @file_get_contents($dir . '/nginx.conf');
        if ($ng && preg_match('~return\s+301\s+\$1;~', $ng)) {
            $stripHtmlExt = true;
        }
    }

    // 2) Canonical
    $canonicalPath = $this->url->pathFor($slug, $lang, $isHome);
$canonicalPath = preg_replace('~\.html$~', '', $canonicalPath);
$canonicalUrl  = $absMode ? $this->url->abs($canonicalPath) : $canonicalPath;

    // 3) hreflang (только реально существующие локали страницы)
    $links    = [];
    $langList = array_keys($this->pages[$slug] ?? []);
    foreach ($langList as $l) {
        $p = $this->url->pathFor($slug, $l, $this->pages[$slug][$l]['is_home']);
$p = preg_replace('~\.html$~', '', $p);
$href = $absMode ? $this->url->abs($p) : $p;

        $links[] = '<link rel="alternate" hreflang="' . htmlspecialchars($l, ENT_QUOTES)
                 . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
    }
    // Самоссылка на случай, если текущий $lang не попал в список
if (!in_array($lang, $langList, true)) {
    $selfPath = $this->url->pathFor($slug, $lang, $isHome);
    $selfPath = preg_replace('~\.html$~', '', $selfPath);
    $selfHref = $absMode ? $this->url->abs($selfPath) : $selfPath;
    $links[] = '<link rel="alternate" hreflang="' . htmlspecialchars($lang, ENT_QUOTES)
             . '" href="' . htmlspecialchars($selfHref, ENT_QUOTES) . '">';
}


    // x-default → основной язык из модалки экспорта
    $defaultLang = $this->opt->primaryLang;
    $defaultPath = $this->url->pathFor($slug, $defaultLang, $this->pages[$slug][$defaultLang]['is_home'] ?? $isHome);
$defaultPath = preg_replace('~\.html$~', '', $defaultPath);
$defaultHref = $absMode ? $this->url->abs($defaultPath) : $defaultPath;
$links[] = '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($defaultHref, ENT_QUOTES) . '">';

    // 4) canonical + OG/Twitter url + og:locale(+alternate)
$og = [
    '<link rel="canonical" href="' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '">',
    '<meta property="og:url" content="' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '">',
    '<meta name="twitter:url" content="' . htmlspecialchars($canonicalUrl, ENT_QUOTES) . '">'
];

// Open Graph locale (Facebook) + alternates
$fbLocales = [
    // DeepL core set (+ extras), map site lang code → FB locale
    'en'      => 'en_US',
    'en-US'   => 'en_US',
    'en-GB'   => 'en_GB',
    'fr'      => 'fr_FR',
    'es'      => 'es_ES',
    'de'      => 'de_DE',
    'it'      => 'it_IT',
    'nl'      => 'nl_NL',
    'pt'      => 'pt_PT',
    'pt-BR'   => 'pt_BR',
    'pl'      => 'pl_PL',
    'cs'      => 'cs_CZ',
    'da'      => 'da_DK',
    'fi'      => 'fi_FI',
    'sv'      => 'sv_SE',
    'nb'      => 'nb_NO',   // Norwegian Bokmål
    'no'      => 'nb_NO',
    'sk'      => 'sk_SK',
    'sl'      => 'sl_SI',
    'el'      => 'el_GR',
    'ro'      => 'ro_RO',
    'bg'      => 'bg_BG',
    'hu'      => 'hu_HU',
    'lt'      => 'lt_LT',
    'lv'      => 'lv_LV',
    'et'      => 'et_EE',
    'tr'      => 'tr_TR',
    'uk'      => 'uk_UA',
    'ru'      => 'ru_RU',
    'id'      => 'id_ID',
    'ja'      => 'ja_JP',
    'ko'      => 'ko_KR',
    'zh'      => 'zh_CN',   // generic Chinese → Simplified
    'zh-Hans' => 'zh_CN',   // Simplified
    'zh-CN'   => 'zh_CN',
    'zh-TW'   => 'zh_TW',
    'ar'      => 'ar_AR',
];

$currentFb = $fbLocales[$lang] ?? 'en_US';
$og[] = '<meta property="og:locale" content="' . htmlspecialchars($currentFb, ENT_QUOTES) . '">';

// Альтернативы для остальных локалей страницы (без дублей)
$seenAlt = [];
foreach ($langList as $l) {
    if ($l === $lang) continue;
    if (empty($fbLocales[$l])) continue;
    $altFb = $fbLocales[$l];
    if (isset($seenAlt[$altFb])) continue;
    $seenAlt[$altFb] = true;
    $og[] = '<meta property="og:locale:alternate" content="' . htmlspecialchars($altFb, ENT_QUOTES) . '">';
}

$block = "\n<!-- SEO (export-generated) -->\n" . implode("\n", array_merge($og, $links)) . "\n<!-- /SEO -->\n";


    // Вставляем перед </head>
    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('~</head>~i', $block . '</head>', $html, 1);
    } else {
        $html .= $block;
    }

    // 5) Делаем og:image / twitter:image абсолютными (если домен задан)
    if ($absMode) {
        $html = preg_replace_callback(
            '/<meta\s+property="og:image"\s+content="([^"]+)"/i',
            function($m) use ($baseUrl) {
                $img = $m[1];
                if (!preg_match('/^https?:\/\//i', $img)) {
                    $img = rtrim($baseUrl, '/') . '/' . ltrim($img, '/');
                }
                return '<meta property="og:image" content="' . $img . '"';
            },
            $html
        );
        $html = preg_replace_callback(
            '/<meta\s+name="twitter:image"\s+content="([^"]+)"/i',
            function($m) use ($baseUrl) {
                $img = $m[1];
                if (!preg_match('/^https?:\/\//i', $img)) {
                    $img = rtrim($baseUrl, '/') . '/' . ltrim($img, '/');
                }
                return '<meta name="twitter:image" content="' . $img . '"';
            },
            $html
        );
    }

    // 6) Полностью убираем {{BASE_URL}} и исторический JS-костыль
    if ($absMode) {
        $html = str_replace('{{BASE_URL}}/', rtrim($baseUrl, '/') . '/', $html);
        $html = str_replace('{{BASE_URL}}',  rtrim($baseUrl, '/'),  $html);
    } else {
        $html = str_replace('{{BASE_URL}}/', '/', $html);
        $html = str_replace('{{BASE_URL}}',  '',  $html);
    }
    // Сносим любые <script> (кроме JSON‑LD), которые трогают canonical/hreflang/OG/Twitter или содержат {{BASE_URL}}
$html = preg_replace(
    '~<script(?![^>]*type=["\']application/ld\+json["\'])[^>]*>[\s\S]*?(?:\{\{BASE_URL\}\}|JS[\s\-]*fallback|link\[rel=["\']canonical["\']|hreflang|og:url|twitter:url)[\s\S]*?</script>~i',
    '',
    $html
);


    // 7) JSON-LD: добавляем "inLanguage" (если отсутствует) и нормализуем URL-поля до canonical
$html = preg_replace_callback(
    '~<script\s+type=["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>~i',
    function ($m) use ($lang, $canonicalUrl) {
        $json = trim($m[1]);
        $data = json_decode($json, true);
        if (!is_array($data)) return $m[0];

        $changed = false;

        // Сравниваем только URL этого же хоста
        $host = parse_url($canonicalUrl, PHP_URL_HOST);
        $rx   = '~^https?://' . preg_quote($host, '~') . '(/|\?|$)~i';

        // Типы страниц, для которых приводим url/@id/mainEntityOfPage к canonical
        $pageTypes = ['WebPage','Article','FAQPage','CollectionPage','NewsArticle','BlogPosting'];

        $fix = function (&$node) use (&$fix, $lang, $canonicalUrl, $rx, $pageTypes, &$changed) {
            if (!is_array($node)) return;

            // Нормализация для страничных сущностей
            $types = [];
            if (isset($node['@type'])) {
                $types = is_array($node['@type']) ? $node['@type'] : [$node['@type']];
            }
            $isPageLike = (bool)array_intersect($pageTypes, $types);

            if ($isPageLike) {
                // Добавим inLanguage, если отсутствует
                if (empty($node['inLanguage'])) {
                    $node['inLanguage'] = $lang;
                    $changed = true;
                }
                // url
                if (isset($node['url']) && is_string($node['url']) && preg_match($rx, $node['url'])) {
                    if ($node['url'] !== $canonicalUrl) { $node['url'] = $canonicalUrl; $changed = true; }
                }
                // @id
                if (isset($node['@id']) && is_string($node['@id']) && preg_match($rx, $node['@id'])) {
                    if ($node['@id'] !== $canonicalUrl) { $node['@id'] = $canonicalUrl; $changed = true; }
                }
                // mainEntityOfPage (строка или объект с @id)
                if (isset($node['mainEntityOfPage'])) {
                    if (is_string($node['mainEntityOfPage']) && preg_match($rx, $node['mainEntityOfPage'])) {
                        if ($node['mainEntityOfPage'] !== $canonicalUrl) { $node['mainEntityOfPage'] = $canonicalUrl; $changed = true; }
                    } elseif (is_array($node['mainEntityOfPage']) && isset($node['mainEntityOfPage']['@id'])
                              && is_string($node['mainEntityOfPage']['@id']) && preg_match($rx, $node['mainEntityOfPage']['@id'])) {
                        if ($node['mainEntityOfPage']['@id'] !== $canonicalUrl) { $node['mainEntityOfPage']['@id'] = $canonicalUrl; $changed = true; }
                    }
                }
            }

            // Рекурсивно обходим дочерние структуры
            foreach ($node as &$v) $fix($v);
        };

        $fix($data);
        if (!$changed) return $m[0];

        return '<script type="application/ld+json">' .
               json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) .
               '</script>';
    },
    $html
);


    // 8) JS‑редирект на домашней: ВСЕГДА на основной язык (из модалки)
    if ($isHome) {
        $primary = $this->opt->primaryLang;

        // Целевые URL для домов каждой локали
        $dest = [];
        $langsForSlug = array_keys($this->pages[$slug] ?? []);
        foreach ($langsForSlug as $l) {
            $home = $this->pages[$slug][$l]['is_home'] ?? false;
            $p    = $this->url->pathFor($slug, $l, $home);
            if ($l === $primary) {
                $p = '/'; // домашняя основного — всегда корень
            }
            if ($stripHtmlExt) {
                $p = preg_replace('~\.html$~', '', $p);
            }
            $dest[$l] = $absMode ? $this->url->abs($p) : $p;
        }
        if (!isset($dest[$primary])) {
            $dest[$primary] = $absMode ? $this->url->abs('/') : '/';
        }

        $jsDest = json_encode($dest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $redirectJs = "<script>(function(){var isBot=/bot|crawl|spider|slurp|baiduspider|bingpreview|facebookexternalhit|twitterbot|embedly|pinterest|vkshare|whatsapp|telegram|discord|linkbot/i.test(navigator.userAgent);if(isBot)return;try{var cookieName='pref_lang';if(document.cookie.indexOf(cookieName+'=')!==-1)return;var PRIMARY='".addslashes($primary)."';var DEST=".$jsDest.";var href=DEST[PRIMARY]||'/';if(location.pathname==='/'||/index(-[a-z0-9\\-]+)?\\.html$/i.test(location.pathname)){var targetPath=(new URL(href, location.origin)).pathname;if(location.pathname!==targetPath){document.cookie=cookieName+'='+PRIMARY+'; max-age='+(60*60*24*30)+'; path=/; SameSite=Lax';location.replace(href);}}}catch(e){}})();</script>";
        // Вставляем в самое начало <head>
        $html = preg_replace('~<head([^>]*)>~i', '<head$1>'.$redirectJs, $html, 1);
    }

    @file_put_contents($abs, $html);
}

    }

    /** ================== Генератор sitemap.xml ================== */
    final class SitemapBuilder
    {
        private $opt, $url, $langs, $pages;
        public function __construct(Options $o, UrlBuilder $u, array $langs, array $pages)
        { $this->opt=$o; $this->url=$u; $this->langs=$langs; $this->pages=$pages; }

        public function make(): void
{
    $absMode = ($this->url->base() !== '{{BASE_URL}}');

    // Определяем, надо ли убирать .html (по экспортированным .htaccess/nginx.conf)
    $stripHtmlExt = false;
    $dir = rtrim($this->opt->exportDir ?? '', '/');
    if ($dir && is_file($dir . '/.htaccess')) {
        $ht = @file_get_contents($dir . '/.htaccess');
        if ($ht && preg_match('~RewriteRule\s+\^\(.*\\\.html\$\)\s+\$1~', $ht)) {
            $stripHtmlExt = true;
        }
    }
    if (!$stripHtmlExt && $dir && is_file($dir . '/nginx.conf')) {
        $ng = @file_get_contents($dir . '/nginx.conf');
        if ($ng && preg_match('~return\s+301\s+\$1;~', $ng)) {
            $stripHtmlExt = true;
        }
    }

    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
    $xml[] = '        xmlns:xhtml="http://www.w3.org/1999/xhtml">';

    foreach ($this->pages as $slug => $byLang) {
        foreach ($byLang as $lang => $meta) {
            $path = $this->url->pathFor($slug, $lang, $meta['is_home']);
$path = preg_replace('~\.html$~', '', $path);
$loc  = $absMode ? $this->url->abs($path) : $path;


            $lastmod = !empty($meta['lastmod']) ? gmdate('c', (int)$meta['lastmod']) : gmdate('c');

            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
            $xml[] = '    <lastmod>' . $lastmod . '</lastmod>';
            $xml[] = '    <changefreq>' . ($meta['is_home'] ? 'daily' : 'weekly') . '</changefreq>';

            $isPrimary = ($lang === $this->opt->primaryLang);
            $priority  = $meta['is_home']
                       ? ($isPrimary ? '1.0' : '0.9')
                       : ($isPrimary ? '0.8' : '0.7');
            $xml[] = '    <priority>' . $priority . '</priority>';

            // Альтернативы — все реально существующие локали этой страницы (включая текущую)
$langList = array_keys($this->pages[$slug]);
foreach ($langList as $alt) {
    $altPath = $this->url->pathFor($slug, $alt, $this->pages[$slug][$alt]['is_home'] ?? false);
    $altPath = preg_replace('~\.html$~', '', $altPath);
    $altHref = $absMode ? $this->url->abs($altPath) : $altPath;

    $xml[] = '    <xhtml:link rel="alternate" hreflang="' . htmlspecialchars($alt, ENT_XML1)
           . '" href="' . htmlspecialchars($altHref, ENT_XML1) . '"/>';
}


            // x-default → основной язык
            $defLang = $this->opt->primaryLang;
            $defPath = $this->url->pathFor($slug, $defLang, $this->pages[$slug][$defLang]['is_home'] ?? $meta['is_home']);
$defPath = preg_replace('~\.html$~', '', $defPath);
$defHref = $absMode ? $this->url->abs($defPath) : $defPath;

            $xml[] = '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($defHref, ENT_XML1) . '"/>';
            $xml[] = '  </url>';
        }
    }

    $xml[] = '</urlset>';
    file_put_contents($this->opt->exportDir . '/sitemap.xml', implode("\n", $xml));
}

    }

    /** ================== Генератор robots.txt ================== */
    final class RobotsBuilder
    {
        private $opt, $url;
        public function __construct(Options $o, UrlBuilder $u) { $this->opt=$o; $this->url=$u; }

        public function make(): void
        {
            $txt = "User-agent: *\nAllow: /\n\nDisallow: /editor/\nDisallow: /data/\n\n";
$sm  = ($this->url->base() === '{{BASE_URL}}') ? '/sitemap.xml' : $this->url->abs('/sitemap.xml');
$txt .= 'Sitemap: ' . $sm . "\n";
file_put_contents($this->opt->exportDir . '/robots.txt', $txt);

        }
    }

    /** ================== Конфиги редиректов ================== */
    final class ConfBuilder
    {
        private $opt, $url;
        public function __construct(Options $o, UrlBuilder $u) { $this->opt=$o; $this->url=$u; }

        public function make(): void
        {
            $this->htaccess();
            $this->nginx();
        }

        private function htaccess(): void
        {
            $lines = [];
            $lines[] = 'RewriteEngine On';
            // HSTS (только при HTTPS)
if ($this->opt->https) {
    $lines[] = '<IfModule mod_headers.c>';
    $lines[] = 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"';
    $lines[] = '</IfModule>';
}

            // HTTPS
            if ($this->opt->https) {
                $lines[] = 'RewriteCond %{HTTPS} !=on';
                $lines[] = 'RewriteRule ^ https://' . ($this->opt->forceHost ? $this->opt->host : '%{HTTP_HOST}') . '%{REQUEST_URI} [L,R=301]';
            }
            // HOST
            if ($this->opt->forceHost && $this->opt->host) {
                $host = preg_quote($this->opt->host, '~');
                $lines[] = 'RewriteCond %{HTTP_HOST} !^' . $host . '$ [NC]';
                $lines[] = 'RewriteRule ^ ' . ($this->opt->https ? 'https' : 'http') . '://' . $this->opt->host . '%{REQUEST_URI} [L,R=301]';
            }
            $content = "# Canonical redirects\n" . implode("\n", $lines) . "\n";
            file_put_contents($this->opt->exportDir . '/.htaccess', $content);
        }

        private function nginx(): void
        {
            $host = $this->opt->host ?: 'example.com';
            $https = $this->opt->https ? 'https' : 'http';
            $conf = [];
            
            // HTTP → HTTPS редирект
            if ($this->opt->https) {
                $conf[] = "# HTTP → HTTPS редирект";
                $conf[] = "server {";
                $conf[] = "    listen 80;";
                $conf[] = "    listen [::]:80;";
                $conf[] = "    server_name {$host}" . ($this->opt->wwwMode === 'keep' ? " www.{$host}" : "") . ";";
                $conf[] = "    return 301 https://\$host\$request_uri;";
                $conf[] = "}";
                $conf[] = "";
            }
            
            // Основной сервер
            $conf[] = "# Основной сервер";
            $conf[] = "server {";
            
            // Порты
            if ($this->opt->https) {
                $conf[] = "    listen 443 ssl http2;";
                $conf[] = "    listen [::]:443 ssl http2;";
            } else {
                $conf[] = "    listen 80;";
                $conf[] = "    listen [::]:80;";
            }
            
            $conf[] = "    server_name {$host};";
            $conf[] = "    root /var/www/{$host}/public; # ← замените на ваш путь к экспорту";
            $conf[] = "    index index.html;";
            $conf[] = "";
            
            // SSL сертификаты
            if ($this->opt->https) {
                $conf[] = "    # SSL сертификаты (Let's Encrypt)";
                $conf[] = "    ssl_certificate /etc/letsencrypt/live/{$host}/fullchain.pem;";
                $conf[] = "    ssl_certificate_key /etc/letsencrypt/live/{$host}/privkey.pem;";
                $conf[] = "";
                $conf[] = "    # Современные SSL настройки";
                $conf[] = "    ssl_protocols TLSv1.2 TLSv1.3;";
                $conf[] = "    ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';";
                $conf[] = "    ssl_prefer_server_ciphers off;";
                $conf[] = "    ssl_session_cache shared:SSL:10m;";
                $conf[] = "    ssl_session_timeout 10m;";
                $conf[] = "";
                $conf[] = "    # OCSP Stapling";
                $conf[] = "    ssl_stapling on;";
                $conf[] = "    ssl_stapling_verify on;";
                $conf[] = "    ssl_trusted_certificate /etc/letsencrypt/live/{$host}/chain.pem;";
                $conf[] = "    resolver 8.8.8.8 8.8.4.4 valid=300s;";
                $conf[] = "    resolver_timeout 5s;";
                $conf[] = "";
            }
            
            // Безопасность
            $conf[] = "    # Безопасность";
            if ($this->opt->https) {
                $conf[] = '    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;';
            }
            $conf[] = '    add_header X-Frame-Options "SAMEORIGIN" always;';
            $conf[] = '    add_header X-Content-Type-Options "nosniff" always;';
            $conf[] = '    add_header X-XSS-Protection "1; mode=block" always;';
            $conf[] = "";
            
            // Защита служебных файлов
            $conf[] = "    # Защита служебных файлов";
            $conf[] = "    location ~ /\\. {";
            $conf[] = "        deny all;";
            $conf[] = "        access_log off;";
            $conf[] = "        log_not_found off;";
            $conf[] = "    }";
            $conf[] = "";
            $conf[] = "    location ~ \\.(htaccess|htpasswd|ini|log|sh|sql|sqlite|db)\$ {";
            $conf[] = "        deny all;";
            $conf[] = "        access_log off;";
            $conf[] = "        log_not_found off;";
            $conf[] = "    }";
            $conf[] = "";
            
            // Обработка HTML
            $conf[] = "    # Красивые URL (.html скрыты)";
            $conf[] = "    location / {";
            $conf[] = "        try_files \$uri \$uri.html \$uri/ =404;";
            $conf[] = "    }";
            $conf[] = "";
            $conf[] = "    location ~ \\.html\$ {";
            $conf[] = "        if (\$request_uri ~ ^(.*)\\.html\$) {";
            $conf[] = "            return 301 \$1;";
            $conf[] = "        }";
            $conf[] = "    }";
            $conf[] = "";
            
            // Кеширование
            $conf[] = "    # Кеширование статических файлов";
            $conf[] = "    location ~* \\.(jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|ttf|eot)\$ {";
            $conf[] = "        expires 1y;";
            $conf[] = "        add_header Cache-Control \"public, immutable\";";
            $conf[] = "        access_log off;";
            $conf[] = "    }";
            $conf[] = "";
            $conf[] = "    location ~* \\.(css|js)\$ {";
            $conf[] = "        expires 30d;";
            $conf[] = "        add_header Cache-Control \"public, max-age=2592000\";";
            $conf[] = "        access_log off;";
            $conf[] = "    }";
            $conf[] = "";
            
            // Сжатие
            $conf[] = "    # Сжатие";
            $conf[] = "    gzip on;";
            $conf[] = "    gzip_comp_level 6;";
            $conf[] = "    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml+rss;";
            $conf[] = "    gzip_vary on;";
            $conf[] = "    gzip_proxied any;";
            $conf[] = "    gzip_disable \"msie6\";";
            
            $conf[] = "}";
            
            file_put_contents($this->opt->exportDir . '/nginx.conf', implode("\n", $conf) . "\n");
        }
    }

    /** ================== Диагностика ================== */
    final class Diagnostics
    {
        private $opt, $langs, $pages;
        public function __construct(Options $o, array $langs, array $pages) { $this->opt=$o; $this->langs=$langs; $this->pages=$pages; }

        public function write(): void
        {
            $lines = [];
            $lines[] = "Export diagnostics (generated " . gmdate('c') . " UTC)";
            $lines[] = "Domain: " . ($this->opt->domain ?: '(none)');
            $lines[] = "Primary language: " . $this->opt->primaryLang;
            $lines[] = "Languages detected: " . implode(', ', $this->langs);
            $countPages = 0;
            foreach ($this->pages as $slug => $byLang) {
                $langs = implode(', ', array_keys($byLang));
                $lines[] = " - {$slug}: {$langs}";
                $countPages += count($byLang);
            }
            $lines[] = "Total HTML files: {$countPages}";
            $lines[] = "";
            $lines[] = "robots.txt: should contain absolute Sitemap → " . ($this->opt->domain ? 'OK' : 'WARNING ({{BASE_URL}})');
            $lines[] = "sitemap.xml: static XML with xhtml:link → OK";
            $lines[] = ".htaccess/nginx.conf: generated → check and deploy manually";
            file_put_contents($this->opt->exportDir . '/diagnostics.txt', implode("\n", $lines) . "\n");
        }
    }

    /** ================== Упаковщик ZIP ================== */
    final class Zipper
    {
        public static function pack(Options $opt): string
        {
            $zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $opt->zipName;
            if (file_exists($zipFile)) @unlink($zipFile);

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
                throw new \RuntimeException("Cannot create zip: {$zipFile}");
            }
            $root = rtrim($opt->exportDir, '/');
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file->isDir()) continue;
                $abs = $file->getPathname();
                $rel = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
                if ($rel === '') continue;
                $zip->addFile($abs, str_replace('\\','/',$rel));
            }
            $zip->close();
            return $zipFile;
        }
    }

    /** ================== Вывод результата ================== */
    final class Out
    {
        public static function deliver(string $zipPath, Options $opt): void
        {
            if (PHP_SAPI === 'cli') {
                echo $zipPath . PHP_EOL;
                return;
            }
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($zipPath));
            header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            readfile($zipPath);
            @unlink($zipPath);
            exit;
        }
    }
}
