<?php
declare(strict_types=1);
ini_set('display_errors','1'); 
error_reporting(E_ALL);

/* Headers */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$nocache = 'no-store, no-cache, must-revalidate, max-age=0';
header('Cache-Control: ' . $nocache);
header('Pragma: no-cache');
header('Expires: 0');

if ($action === 'exportPage') {
  header('Content-Type: text/html; charset=utf-8');
} else {
  header('Content-Type: application/json; charset=utf-8');
}

/* DB */
$db = __DIR__ . '/../data/zerro_blog.db';
@mkdir(dirname($db), 0775, true);

try {
  $pdo = new PDO('sqlite:' . $db, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL");
  $pdo->exec("PRAGMA synchronous=NORMAL");
  $pdo->exec("PRAGMA busy_timeout=5000");
  
  // Создаём базовую схему с адаптивными колонками
  $pdo->exec("CREATE TABLE IF NOT EXISTS pages(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT 'Страница',
    data_json TEXT NOT NULL DEFAULT '{}',
    data_tablet TEXT DEFAULT '{}',
    data_mobile TEXT DEFAULT '{}',
    meta_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT ''
  )");
} catch(Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => 'DB: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Helpers */
function ok(array $a = []) {
  http_response_code(200);
  echo json_encode(['ok' => true] + $a, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $m) {
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
  exit;
}

function hascol(PDO $pdo, string $tbl, string $col): bool {
  $tbl = preg_replace('/[^A-Za-z0-9_]/', '', $tbl);
  foreach($pdo->query("PRAGMA table_info($tbl)") as $r) {
    if (strcasecmp((string)$r['name'], $col) === 0) return true;
  }
  return false;
}

function mime_from_ext(string $url): string {
  $p = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
  return str_ends_with($p, '.mp4') ? 'video/mp4'
    : (str_ends_with($p, '.webm') ? 'video/webm'
    : (str_ends_with($p, '.ogg') ? 'video/ogg'
    : (str_ends_with($p, '.mov') ? 'video/quicktime' : '')));
}

// Проверяем наличие адаптивных колонок
if(!hascol($pdo, 'pages', 'data_tablet')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_tablet TEXT DEFAULT '{}'");
}
if(!hascol($pdo, 'pages', 'data_mobile')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_mobile TEXT DEFAULT '{}'");
}

$has_created = hascol($pdo, 'pages', 'created_at');
$has_updated = hascol($pdo, 'pages', 'updated_at');
$has_meta_t = hascol($pdo, 'pages', 'meta_title');
$has_meta_d = hascol($pdo, 'pages', 'meta_description');
$has_tablet = hascol($pdo, 'pages', 'data_tablet');
$has_mobile = hascol($pdo, 'pages', 'data_mobile');

/* Router */
try {
  switch($action) {
    
    case 'listPages': {
      $sel = "id,name";
      if($has_meta_t) $sel .= ",meta_title";
      if($has_meta_d) $sel .= ",meta_description";
      $order = $has_updated ? 'ORDER BY updated_at DESC, id DESC' : 'ORDER BY id DESC';
      $rows = $pdo->query("SELECT $sel FROM pages $order")->fetchAll();
      ok(['pages' => $rows]);
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'createPage': {
      $name = trim($_POST['name'] ?? 'Новая');
      $title = trim($_POST['title'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      
      $cols = ['name', 'data_json', 'data_tablet', 'data_mobile'];
      $vals = [':n', "'{}'", "'{}'", "'{}'"];
      $params = [':n' => $name];
      
      if($has_meta_t) {
        $cols[] = 'meta_title';
        $vals[] = ':t';
        $params[':t'] = $title;
      }
      if($has_meta_d) {
        $cols[] = 'meta_description';
        $vals[] = ':d';
        $params[':d'] = $desc;
      }
      if($has_updated) {
        $cols[] = 'updated_at';
        $vals[] = 'CURRENT_TIMESTAMP';
      }
      if($has_created) {
        $cols[] = 'created_at';
        $vals[] = 'CURRENT_TIMESTAMP';
      }
      
      $sql = "INSERT INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      ok(['id' => (int)$pdo->lastInsertId()]);
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'renamePage': {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $sql = "UPDATE pages SET name=:n" . ($has_updated ? ', updated_at=CURRENT_TIMESTAMP' : '') . " WHERE id=:id";
      $st = $pdo->prepare($sql);
      $st->execute(['n' => $name, 'id' => $id]);
      ok();
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'updateMeta': {
      if(!$has_meta_t && !$has_meta_d) ok();
      $id = (int)($_POST['id'] ?? 0);
      $t = (string)($_POST['title'] ?? '');
      $d = (string)($_POST['description'] ?? '');
      $set = [];
      $p = ['id' => $id];
      
      if($has_meta_t) {
        $set[] = 'meta_title=:t';
        $p['t'] = $t;
      }
      if($has_meta_d) {
        $set[] = 'meta_description=:d';
        $p['d'] = $d;
      }
      if($has_updated) {
        $set[] = 'updated_at=CURRENT_TIMESTAMP';
      }
      
      if(count($set) > 0) {
        $sql = "UPDATE pages SET " . implode(',', $set) . " WHERE id=:id";
        $st = $pdo->prepare($sql);
        $st->execute($p);
      }
      ok();
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'deletePage': {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) fail('No id');

      // 1) Забираем данные страницы ДО удаления
      $cols = "data_json,data_tablet,data_mobile";
      $st = $pdo->prepare("SELECT $cols FROM pages WHERE id=:id");
      $st->execute(['id' => $id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      // 2) Собираем все локальные файлы из /editor/uploads
      $toDelete = [];
      $add = function($url) use (&$toDelete) {
        if (!$url) return;
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return;
        if (strpos($path, '/editor/uploads/') === 0) {
          $toDelete[$path] = true; // как множество, чтобы без дублей
        }
      };
      $scan = function($json) use ($add) {
        if (!is_array($json) || empty($json['elements'])) return;
        foreach ($json['elements'] as $el) {
          $t = strtolower($el['type'] ?? '');
          if ($t === 'image') {
            $add($el['src'] ?? '');
            if (!empty($el['html']) && is_string($el['html'])) {
              if (preg_match_all('~(?:src|href)=["\']([^"\']+)~i', $el['html'], $m)) {
                foreach ($m[1] as $u) $add($u);
              }
            }
          } elseif ($t === 'video') {
            $add($el['src'] ?? '');
            if (!empty($el['html']) && is_string($el['html'])) {
              if (preg_match_all('~(?:src|href)=["\']([^"\']+)~i', $el['html'], $m)) {
                foreach ($m[1] as $u) $add($u);
              }
            }
          } elseif ($t === 'filebtn') {
            $add($el['fileUrl'] ?? '');
          }
        }
      };

      if ($row) {
        $desk = json_decode($row['data_json'] ?? '{}', true) ?: [];
        $tab  = json_decode($row['data_tablet'] ?? '{}', true) ?: [];
        $mob  = json_decode($row['data_mobile'] ?? '{}', true) ?: [];
        $scan($desk); $scan($tab); $scan($mob);
      }

      // 3) Удаляем строку страницы
      $pdo->prepare("DELETE FROM pages WHERE id=:id")->execute(['id' => $id]);

      // 4) Физически удаляем локальные файлы
      foreach (array_keys($toDelete) as $rel) {
        // /editor/uploads/filename.ext  ->  __DIR__ . '/uploads/filename.ext'
        $name = substr($rel, strlen('/editor/uploads/'));
        $full = __DIR__ . '/uploads/' . $name;
        // защита от выхода за пределы папки
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $fullReal = realpath($full);
        if ($fullReal && $uploadsRoot && strpos($fullReal, $uploadsRoot) === 0 && is_file($fullReal)) {
          @unlink($fullReal);
        }
      }

      ok(['deleted_files' => array_keys($toDelete)]);
      break;
    }

    case 'deleteUploadsByUrls': {
      $raw = $_POST['urls'] ?? [];
      $urls = [];
      if (is_string($raw)) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $urls = $dec;
      } elseif (is_array($raw)) {
        $urls = $raw;
      }

      $deleted = [];
      foreach ($urls as $u) {
        $path = parse_url((string)$u, PHP_URL_PATH);
        if (!$path) continue;
        if (strpos($path, '/editor/uploads/') !== 0) continue;
        $name = substr($path, strlen('/editor/uploads/'));
        $full = __DIR__ . '/uploads/' . $name;

        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $fullReal = realpath($full);
        if ($fullReal && $uploadsRoot && strpos($fullReal, $uploadsRoot) === 0 && is_file($fullReal)) {
          @unlink($fullReal);
          $deleted[] = $path;
        }
      }
      ok(['deleted' => $deleted]);
      break;
    }

    case 'purgePage': {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) fail('No id');

      // 1) Собираем все файлы, привязанные к странице (из desktop/tablet/mobile)
      $st = $pdo->prepare("SELECT data_json,data_tablet,data_mobile FROM pages WHERE id=:id");
      $st->execute(['id' => $id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      $toDelete = [];
      $add = function($url) use (&$toDelete) {
        if (!$url) return;
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && strpos($path, '/editor/uploads/') === 0) $toDelete[$path] = true;
      };
      $scan = function($json) use ($add) {
        if (!is_array($json) || empty($json['elements'])) return;
        foreach ($json['elements'] as $el) {
          $t = strtolower($el['type'] ?? '');
          if ($t === 'image') {
            $add($el['src'] ?? '');
            if (!empty($el['html']) && is_string($el['html'])) {
              if (preg_match_all('~(?:src|href)=["\']([^"\']+)~i', $el['html'], $m)) {
                foreach ($m[1] as $u) $add($u);
              }
            }
          } elseif ($t === 'video') {
            $add($el['src'] ?? '');
            if (!empty($el['html']) && is_string($el['html'])) {
              if (preg_match_all('~(?:src|href)=["\']([^"\']+)~i', $el['html'], $m)) {
                foreach ($m[1] as $u) $add($u);
              }
            }
          } elseif ($t === 'filebtn') {
            $add($el['fileUrl'] ?? '');
          }
        }
      };

      if ($row) {
        $desk = json_decode($row['data_json'] ?? '{}', true) ?: [];
        $tab  = json_decode($row['data_tablet'] ?? '{}', true) ?: [];
        $mob  = json_decode($row['data_mobile'] ?? '{}', true) ?: [];
        $scan($desk); $scan($tab); $scan($mob);
      }

      // 2) Очищаем страницу
      $set = "data_json='{}', data_tablet='{}', data_mobile='{}'";
      if (hascol($pdo, 'pages', 'updated_at')) $set .= ", updated_at=CURRENT_TIMESTAMP";
      $pdo->exec("UPDATE pages SET $set WHERE id=" . (int)$id);

      // 3) Удаляем локальные файлы
      foreach (array_keys($toDelete) as $rel) {
        $name = substr($rel, strlen('/editor/uploads/'));
        $full = __DIR__ . '/uploads/' . $name;
        $uploadsRoot = realpath(__DIR__ . '/uploads');
        $fullReal = realpath($full);
        if ($fullReal && $uploadsRoot && strpos($fullReal, $uploadsRoot) === 0 && is_file($fullReal)) {
          @unlink($fullReal);
        }
      }

      ok(['purged' => true, 'deleted_files' => array_keys($toDelete)]);
      break;
    }
    
    case 'loadPage': {
      $id = (int)($_GET['id'] ?? 0);
      if(!$id) {
        $order = $has_updated ? 'updated_at DESC, id DESC' : 'id DESC';
        $id = (int)$pdo->query("SELECT id FROM pages ORDER BY $order LIMIT 1")->fetchColumn();
        if(!$id) {
          $cols = ['name', 'data_json'];
          $vals = ["'Главная'", "'{}'"];
          if($has_tablet) {
            $cols[] = 'data_tablet';
            $vals[] = "'{}'";
          }
          if($has_mobile) {
            $cols[] = 'data_mobile';
            $vals[] = "'{}'";
          }
          if($has_updated) {
            $cols[] = 'updated_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          if($has_created) {
            $cols[] = 'created_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          $pdo->exec("INSERT INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")");
          $id = (int)$pdo->lastInsertId();
        }
      }
      $st = $pdo->prepare("SELECT id,name,data_json" . 
                          ($has_meta_t ? ',meta_title' : '') . 
                          ($has_meta_d ? ',meta_description' : '') . 
                          " FROM pages WHERE id=:id");
      $st->execute(['id' => $id]);
      $row = $st->fetch();
      $data = json_decode($row['data_json'] ?? '{}', true) ?: ['elements' => []];
      $resp = ['id' => (int)$row['id'], 'name' => $row['name'], 'data' => $data];
      if($has_meta_t) $resp['meta_title'] = $row['meta_title'];
      if($has_meta_d) $resp['meta_description'] = $row['meta_description'];
      ok(['page' => $resp]);
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'loadPageAdaptive': {
      $id = (int)($_GET['id'] ?? 0);
      
      if(!$id) {
        $order = $has_updated ? 'updated_at DESC, id DESC' : 'id DESC';
        $id = (int)$pdo->query("SELECT id FROM pages ORDER BY $order LIMIT 1")->fetchColumn();
        
        if(!$id) {
          // Создаем первую страницу
          $cols = ['name', 'data_json', 'data_tablet', 'data_mobile'];
          $vals = ["'Главная'", "'{}'", "'{}'", "'{}'"];
          
          if($has_meta_t) {
            $cols[] = 'meta_title';
            $vals[] = "''";
          }
          if($has_meta_d) {
            $cols[] = 'meta_description';
            $vals[] = "''";
          }
          if($has_updated) {
            $cols[] = 'updated_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          if($has_created) {
            $cols[] = 'created_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          
          $pdo->exec("INSERT INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")");
          $id = (int)$pdo->lastInsertId();
        }
      }
      
      $cols = "id,name,data_json,data_tablet,data_mobile";
      if($has_meta_t) $cols .= ",meta_title";
      if($has_meta_d) $cols .= ",meta_description";
      
      $st = $pdo->prepare("SELECT $cols FROM pages WHERE id=:id");
      $st->execute(['id' => $id]);
      $row = $st->fetch();
      
      if(!$row) fail('Page not found');
      
      $desktop = json_decode($row['data_json'] ?? '{}', true) ?: ['elements' => []];
      $tablet = json_decode($row['data_tablet'] ?? '{}', true) ?: ['elements' => []];
      $mobile = json_decode($row['data_mobile'] ?? '{}', true) ?: ['elements' => []];
      
      ok(['page' => [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'meta_title' => $row['meta_title'] ?? '',
        'meta_description' => $row['meta_description'] ?? '',
        'data_desktop' => $desktop,
        'data_tablet' => $tablet,
        'data_mobile' => $mobile
      ]]);
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'savePage': {
      $id = (int)($_POST['id'] ?? 0);
      $json = (string)($_POST['data_json'] ?? '{}');
      
      json_decode($json);
      if (json_last_error() !== JSON_ERROR_NONE) fail('invalid json: ' . json_last_error_msg());
      
      $pdo->beginTransaction();
      try {
        if($id > 0) {
          $sql = "UPDATE pages SET data_json=:j" . ($has_updated ? ', updated_at=CURRENT_TIMESTAMP' : '') . " WHERE id=:id";
          $st = $pdo->prepare($sql);
          $st->execute(['j' => $json, 'id' => $id]);
          if($st->rowCount() === 0) {
            $cols = ['id', 'name', 'data_json'];
            $vals = [':id', "'Страница'", ':j'];
            $p = ['id' => $id, 'j' => $json];
            if($has_tablet) {
              $cols[] = 'data_tablet';
              $vals[] = "'{}'";
            }
            if($has_mobile) {
              $cols[] = 'data_mobile';
              $vals[] = "'{}'";
            }
            if($has_meta_t) {
              $cols[] = 'meta_title';
              $vals[] = "''";
            }
            if($has_meta_d) {
              $cols[] = 'meta_description';
              $vals[] = "''";
            }
            if($has_updated) {
              $cols[] = 'updated_at';
              $vals[] = 'CURRENT_TIMESTAMP';
            }
            if($has_created) {
              $cols[] = 'created_at';
              $vals[] = 'CURRENT_TIMESTAMP';
            }
            $sql = "INSERT OR REPLACE INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($p);
          }
        } else {
          $cols = ['name', 'data_json'];
          $vals = ["'Новая'", ':j'];
          $p = ['j' => $json];
          if($has_tablet) {
            $cols[] = 'data_tablet';
            $vals[] = "'{}'";
          }
          if($has_mobile) {
            $cols[] = 'data_mobile';
            $vals[] = "'{}'";
          }
          if($has_meta_t) {
            $cols[] = 'meta_title';
            $vals[] = "''";
          }
          if($has_meta_d) {
            $cols[] = 'meta_description';
            $vals[] = "''";
          }
          if($has_updated) {
            $cols[] = 'updated_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          if($has_created) {
            $cols[] = 'created_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          $sql = "INSERT INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")";
          $st = $pdo->prepare($sql);
          $st->execute($p);
          $id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        ok(['id' => $id]);
      } catch(Throwable $e) {
        $pdo->rollBack();
        fail('save error: ' . $e->getMessage());
      }
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'savePageAdaptive': {
      $id = (int)($_POST['id'] ?? 0);
      $desktop = $_POST['data_json'] ?? '{}';
      $tablet = $_POST['data_tablet'] ?? '{}';
      $mobile = $_POST['data_mobile'] ?? '{}';
      
      // Проверяем JSON
      json_decode($desktop);
      if (json_last_error() !== JSON_ERROR_NONE) fail('Invalid desktop JSON: ' . json_last_error_msg());
      json_decode($tablet);
      if (json_last_error() !== JSON_ERROR_NONE) fail('Invalid tablet JSON: ' . json_last_error_msg());
      json_decode($mobile);
      if (json_last_error() !== JSON_ERROR_NONE) fail('Invalid mobile JSON: ' . json_last_error_msg());
      
      $pdo->beginTransaction();
      try {
        if($id > 0) {
          $set = ["data_json=:desktop", "data_tablet=:tablet", "data_mobile=:mobile"];
          $params = [':desktop' => $desktop, ':tablet' => $tablet, ':mobile' => $mobile, ':id' => $id];
          
          if($has_updated) {
            $set[] = "updated_at=CURRENT_TIMESTAMP";
          }
          
          $sql = "UPDATE pages SET " . implode(', ', $set) . " WHERE id=:id";
          $st = $pdo->prepare($sql);
          $st->execute($params);
          
          if($st->rowCount() === 0) {
            // Если строки нет, создаем
            $cols = ['id', 'name', 'data_json', 'data_tablet', 'data_mobile'];
            $vals = [':id', "'Страница'", ':desktop', ':tablet', ':mobile'];
            $params = [':id' => $id, ':desktop' => $desktop, ':tablet' => $tablet, ':mobile' => $mobile];
            
            if($has_meta_t) {
              $cols[] = 'meta_title';
              $vals[] = "''";
            }
            if($has_meta_d) {
              $cols[] = 'meta_description';
              $vals[] = "''";
            }
            if($has_updated) {
              $cols[] = 'updated_at';
              $vals[] = 'CURRENT_TIMESTAMP';
            }
            if($has_created) {
              $cols[] = 'created_at';
              $vals[] = 'CURRENT_TIMESTAMP';
            }
            
            $sql = "INSERT OR REPLACE INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")";
            $st = $pdo->prepare($sql);
            $st->execute($params);
          }
        } else {
          // Новая страница
          $cols = ['name', 'data_json', 'data_tablet', 'data_mobile'];
          $vals = ["'Новая'", ':desktop', ':tablet', ':mobile'];
          $params = [':desktop' => $desktop, ':tablet' => $tablet, ':mobile' => $mobile];
          
          if($has_meta_t) {
            $cols[] = 'meta_title';
            $vals[] = "''";
          }
          if($has_meta_d) {
            $cols[] = 'meta_description';
            $vals[] = "''";
          }
          if($has_updated) {
            $cols[] = 'updated_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          if($has_created) {
            $cols[] = 'created_at';
            $vals[] = 'CURRENT_TIMESTAMP';
          }
          
          $sql = "INSERT INTO pages(" . implode(',', $cols) . ") VALUES(" . implode(',', $vals) . ")";
          $st = $pdo->prepare($sql);
          $st->execute($params);
          $id = (int)$pdo->lastInsertId();
        }
        
        $pdo->commit();
        ok(['id' => $id]);
      } catch(Throwable $e) {
        $pdo->rollBack();
        fail('Save error: ' . $e->getMessage());
      }
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'uploadAsset': {
      if (empty($_FILES['file']) || empty($_FILES['file']['tmp_name'])) {
        $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $map = [
          1 => 'upload_max_filesize',
          2 => 'post_max_size',
          3 => 'Файл загружен частично',
          4 => 'Файл не выбран',
          6 => 'Нет temp',
          7 => 'Запись не удалась',
          8 => 'PHP-расширение'
        ];
        fail('Upload error code ' . $err . ' (' . ($map[$err] ?? 'неизвестно') . ')');
      }
      
      if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
        fail('Файл не принят PHP');
      }
      
      $type = $_GET['type'] ?? $_POST['type'] ?? 'image';
      $dir = __DIR__ . '/uploads';
      if(!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @chmod($dir, 0775);
      }
      
      $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
      
      if($type === 'file') {
        // Для типа file - разрешаем ВСЕ форматы
      } elseif($type === 'video') {
        $allow = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
        if (!in_array($ext, $allow)) {
          fail('Недопустимый формат видео .' . $ext);
        }
      } else {
        $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
        if (!in_array($ext, $allow)) {
          fail('Недопустимый формат изображения .' . $ext);
        }
      }
      
      $maxSize = ($type === 'file') ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
      if($_FILES['file']['size'] > $maxSize) {
        fail('Файл слишком большой. Максимум ' . ($type === 'file' ? '100' : '10') . ' МБ');
      }
      
      $prefix = ($type === 'video') ? 'vid' : (($type === 'file') ? 'file' : 'img');
      $new = uniqid($prefix . '_') . '.' . $ext;
      $dest = $dir . '/' . $new;
      
      if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        fail('Не удалось сохранить файл');
      }
      @chmod($dest, 0664);
      
      ok(['url' => '/editor/uploads/' . $new, 'filename' => $_FILES['file']['name']]);
      break; // ДОБАВЛЕН BREAK
    }
    
    case 'exportPage': {
      $id = (int)($_GET['id'] ?? 0);
      
      if(!$id) {
        $order = $has_updated ? 'updated_at DESC, id DESC' : 'id DESC';
        $id = (int)$pdo->query("SELECT id FROM pages ORDER BY $order LIMIT 1")->fetchColumn();
      }
      
      $cols = "name,data_json,data_tablet,data_mobile";
      if($has_meta_t) $cols .= ",meta_title";
      if($has_meta_d) $cols .= ",meta_description";
      
      $st = $pdo->prepare("SELECT $cols FROM pages WHERE id=:id");
      $st->execute(['id' => $id]);
      $row = $st->fetch();
      
      if(!$row) {
        echo "<!doctype html><html><body><h1>Страница не найдена</h1></body></html>";
        exit;
      }
      
      $desktop = json_decode($row['data_json'] ?? '{}', true) ?: ['elements' => []];
      $tablet = json_decode($row['data_tablet'] ?? '{}', true) ?: ['elements' => []];
      $mobile = json_decode($row['data_mobile'] ?? '{}', true) ?: ['elements' => []];
      
      $title = htmlspecialchars(($row['meta_title'] ?? '') ?: $row['name'], ENT_QUOTES, 'UTF-8');
      $desc = htmlspecialchars($row['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
      
      // Создаем карты для быстрого поиска
      $tabletMap = [];
      $mobileMap = [];
      
      foreach($tablet['elements'] as $e) {
        if(isset($e['id'])) $tabletMap[$e['id']] = $e;
      }
      
      foreach($mobile['elements'] as $e) {
        if(isset($e['id'])) $mobileMap[$e['id']] = $e;
      }
      
      echo "<!doctype html><html lang='ru'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
      echo "<title>{$title}</title><meta name='description' content='{$desc}'>";
      echo "<style>body{margin:0;background:#0e141b;color:#e6f0fa;font:16px/1.4 system-ui,Segoe UI,Roboto}.wrap{position:relative;min-height:100vh;overflow-x:hidden}.el{position:absolute;box-sizing:border-box}.el img,.el video{width:100%;height:100%;border-radius:inherit;display:block}.el[data-type=image] img{object-fit:contain;object-position:center}.el[data-type=video] video{object-fit:cover}"; 

      
      // Desktop стили
      foreach($desktop['elements'] as $e) {
        $id = $e['id'] ?? uniqid('el_');
        $autoHeight = ($e['type'] === 'text') ? 'height:auto;' : "height:{$e['height']}%;";
        echo "#el-{$id}{left:{$e['left']}%;top:{$e['top']}%;width:{$e['width']}%;{$autoHeight}z-index:{$e['z']};border-radius:{$e['radius']}px;transform:rotate({$e['rotate']}deg)}";
      }
      
      // Tablet стили
      echo "@media(max-width:768px) and (min-width:481px){";
      foreach($desktop['elements'] as $e) {
        $id = $e['id'] ?? '';
        if($id && isset($tabletMap[$id])) {
          $te = $tabletMap[$id];
          $autoHeight = ($te['type'] === 'text') ? '' : "height:{$te['height']}%!important;";
          echo "#el-{$id}{left:{$te['left']}%!important;top:{$te['top']}%!important;width:{$te['width']}%!important;{$autoHeight}}";
        }
      }
      echo "}";
      
      // Mobile стили
      echo "@media(max-width:480px){";
      foreach($desktop['elements'] as $e) {
        $id = $e['id'] ?? '';
        if($id && isset($mobileMap[$id])) {
          $me = $mobileMap[$id];
          $autoHeight = ($me['type'] === 'text') ? '' : "height:{$me['height']}%!important;";
          echo "#el-{$id}{left:{$me['left']}%!important;top:{$me['top']}%!important;width:{$me['width']}%!important;{$autoHeight}}";
        }
      }
      echo ".el[data-type='text']{font-size:16px!important}}";
      
      echo "</style></head><body><div class='wrap'>";
      
      // Выводим desktop элементы с ID
      foreach ($desktop['elements'] as $e) {
        $id = $e['id'] ?? uniqid('el_');
        $type = $e['type'] ?? '';
        
        if ($type === 'text') {
          $txt = htmlspecialchars($e['text'] ?? '', ENT_QUOTES, 'UTF-8');
          $fs = (int)($e['fontSize'] ?? 20);
          $color = htmlspecialchars($e['color'] ?? '#e8f2ff', ENT_QUOTES, 'UTF-8');
          $bg = htmlspecialchars($e['bg'] ?? '', ENT_QUOTES, 'UTF-8');
          echo "<div id='el-{$id}' class='el' data-type='text' style=\"background:{$bg};color:{$color};font-size:{$fs}px;line-height:1.3;height:auto\">{$txt}</div>";
        } elseif ($type === 'box') {
          $bg = htmlspecialchars($e['bg'] ?? '', ENT_QUOTES, 'UTF-8');
          $bd = htmlspecialchars($e['border'] ?? '', ENT_QUOTES, 'UTF-8');
          echo "<div id='el-{$id}' class='el' data-type='box' style=\"background:{$bg};border:{$bd}\"></div>";
        } elseif ($type === 'image') {
          if (!empty($e['html'])) {
            echo "<div id='el-{$id}' class='el' data-type='image'><div style=\"width:100%;height:100%\">{$e['html']}</div></div>";
          } else {
            $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
            echo "<div id='el-{$id}' class='el' data-type='image'><img src=\"{$src}\" alt=\"\"></div>";
          }
        } elseif ($type === 'video') {
          if (!empty($e['html'])) {
            echo "<div id='el-{$id}' class='el' data-type='video'><div style=\"width:100%;height:100%\">{$e['html']}</div></div>";
          } else {
            $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
            $type_mime = mime_from_ext($src);
            $ct = ' controls';
            $aut = !empty($e['autoplay']) ? ' autoplay' : '';
            $lp = !empty($e['loop']) ? ' loop' : '';
            $mu = !empty($e['muted']) ? ' muted' : '';
            if($type_mime) {
              echo "<div id='el-{$id}' class='el' data-type='video'><video{$ct}{$aut}{$lp}{$mu} playsinline><source src=\"{$src}\" type=\"{$type_mime}\"></video></div>";
            } else {
              echo "<div id='el-{$id}' class='el' data-type='video'><video src=\"{$src}\"{$ct}{$aut}{$lp}{$mu} playsinline></video></div>";
            }
          }
        } elseif (strtolower($type) === 'linkbtn') {
          $text = htmlspecialchars($e['text'] ?? 'Кнопка', ENT_QUOTES, 'UTF-8');
          $url = htmlspecialchars($e['url'] ?? '#', ENT_QUOTES, 'UTF-8');
          $bg = htmlspecialchars($e['bg'] ?? '#3b82f6', ENT_QUOTES, 'UTF-8');
          $color = htmlspecialchars($e['color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');
          echo "<div id='el-{$id}' class='el' data-type='linkbtn'><a href=\"{$url}\" style=\"display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:{$bg};color:{$color};text-decoration:none;border-radius:inherit;font-weight:600\" target=\"_blank\">{$text}</a></div>";
        } elseif (strtolower($type) === 'filebtn') {
          $text = htmlspecialchars($e['text'] ?? 'Скачать файл', ENT_QUOTES, 'UTF-8');
          $fileUrl = htmlspecialchars($e['fileUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
          $fileName = htmlspecialchars($e['fileName'] ?? '', ENT_QUOTES, 'UTF-8');
          $bg = htmlspecialchars($e['bg'] ?? '#10b981', ENT_QUOTES, 'UTF-8');
          $color = htmlspecialchars($e['color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');
          echo "<div id='el-{$id}' class='el' data-type='filebtn'><a href=\"{$fileUrl}\" download=\"{$fileName}\" style=\"display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:{$bg};color:{$color};text-decoration:none;border-radius:inherit;font-weight:600\" target=\"_blank\">📄 {$text}</a></div>";
        } elseif ($type === 'langbadge') {
  $langs = htmlspecialchars($e['langs'] ?? 'ru,en', ENT_QUOTES, 'UTF-8');
  $langsArray = explode(',', $langs);
  
  $langMap = [
    'en' => '🇬🇧 English',
    'zh-Hans' => '🇨🇳 中文',
    'es' => '🇪🇸 Español',
    'hi' => '🇮🇳 हिन्दी',
    'ar' => '🇸🇦 العربية',
    'pt' => '🇵🇹 Português',
    'ru' => '🇷🇺 Русский',
    'ja' => '🇯🇵 日本語',
    'de' => '🇩🇪 Deutsch',
    'fr' => '🇫🇷 Français'
];
  
  echo "<div id='el-{$id}' class='el langbadge' data-type='langbadge' data-langs='{$langs}' style='background:transparent;border:none;padding:0'>";
  echo "<select onchange='window.location.href=\"?lang=\"+this.value' style='padding:8px 16px;border-radius:12px;border:1px solid #2ea8ff;background:#1a2533;color:#fff;cursor:pointer;font-size:14px'>";
  foreach($langsArray as $lang) {
    $lang = trim($lang);
    $display = $langMap[$lang] ?? '🌐 ' . strtoupper($lang);
    $selected = ($_GET['lang'] ?? $langsArray[0]) == $lang ? ' selected' : '';
    echo "<option value='{$lang}'{$selected}>{$display}</option>";
  }
  echo "</select></div>";
        }
      }
      
      echo "</div></body></html>";
      exit;
      break; // ДОБАВЛЕН BREAK (хотя после exit не обязателен, но для единообразия)
    }
    
    default:
      fail('Unknown action');
      break; // ДОБАВЛЕН BREAK
  }
} catch(Throwable $e) {
  fail('Exception: ' . $e->getMessage());
}
