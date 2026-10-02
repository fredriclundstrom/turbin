<?php
/*
  Turbin Transfer – backend. One endpoint, ?a=<action>.

  Clients (open):   config · start → file (per file, status after a dropped connection) → finish | cancel
  Crew (signed in): login · logout · deliver → file → finish | cancel · history · inbox → inbox_files → inbox_get
  Download (open):  delivery?d=<id> · get?d=<id>&f=<file id>

  Files never pass through this server on the way in: `file` returns a Google Drive resumable-upload URL
  and the browser sends the bytes straight to Google. On the way out, `get` streams from Drive.
*/
declare(strict_types=1);
require __DIR__ . '/lib.php';

set_exception_handler(function (Throwable $e) {
  error_log('Turbin Transfer: ' . $e->getMessage());
  out(['error' => 'Something went wrong on our side. Please try again.'], 500);
});

$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
// every POST is JSON from our own page (blocks plain cross-site form posts)
if ($post && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) fail('Bad request');

match ($a) {
  'config'   => a_config(),
  'login'    => $post ? a_login() : fail('POST only', 405),
  'logout'   => $post ? a_logout() : fail('POST only', 405),
  'start'    => $post ? a_start() : fail('POST only', 405),
  'deliver'  => $post ? a_deliver() : fail('POST only', 405),
  'file'     => $post ? a_file() : fail('POST only', 405),
  'status'   => $post ? a_status() : fail('POST only', 405),
  'finish'   => $post ? a_finish() : fail('POST only', 405),
  'cancel'   => $post ? a_cancel() : fail('POST only', 405),
  'history'  => a_history(),
  'inbox'    => a_inbox(),
  'inbox_files' => a_inbox_files(),
  'inbox_get'   => a_inbox_get(),
  'delivery' => a_delivery(),
  'get'      => a_get(),
  default    => fail('Unknown action', 404),
};

/* ================================================================== */

function a_config(): never {
  out([
    'live' => true,
    'crew' => array_map(fn($id, $c) => ['id' => $id, 'name' => $c[0]], array_keys(crew()), crew()),
    'login' => cfg('google_client_id') ? 'google' : 'password',
    'googleClientId' => cfg('google_client_id') ?: null,
    'turnstile' => cfg('turnstile_site_key') ?: null,
    'me' => ($m = me()) ? ['id' => $m['id'], 'name' => $m['name']] : null,
  ]);
}

/* ---------- crew sign-in: a Google ID token from "Sign in with Google", checked with Google ---------- */
function a_login(): never {
  if (!cfg('google_client_id')) login_password();
  $cred = (string)(body()['credential'] ?? '');
  if (!$cred) fail('Missing credential');
  $r = http('GET', 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($cred));
  $t = json_decode($r['body'], true) ?: [];
  if ($r['code'] !== 200 || ($t['aud'] ?? '') !== cfg('google_client_id') || ($t['email_verified'] ?? '') !== 'true'
      || ($t['hd'] ?? '') !== cfg('workspace_domain')) fail('Sign-in failed', 401);
  $m = crew_by_email($t['email']) ?? fail('This account isn’t on the Turbin crew list', 403);
  set_crew_cookie($m['id']);
  out(['me' => ['id' => $m['id'], 'name' => $m['name']]]);
}
/* ---------- …or the simple way: one shared crew password, then pick your name ---------- */
function login_password(): never {
  $pw = (string)(body()['password'] ?? ''); $m = crew(str_in(body(), 'who', 40));
  $rl = 'rl-login-' . md5(client_ip()) . '.json';
  $tries = array_values(array_filter(read_json($rl) ?? [], fn($t) => $t > time() - 900));
  if (count($tries) >= 8) fail('Too many attempts. Try again in 15 minutes', 429);
  if (!cfg('crew_password') || !hash_equals((string)cfg('crew_password'), $pw)) {
    with_json($rl, fn() => [[...$tries, time()], null]);
    fail('Wrong password', 401);
  }
  if (!$m) fail('Choose who you are');
  set_crew_cookie($m['id']);
  out(['me' => ['id' => $m['id'], 'name' => $m['name']]]);
}
function a_logout(): never { set_crew_cookie(null); out(['ok' => true]); }

/* ---------- shared: validate the file list a send starts with ---------- */
function file_list(): array {
  $files = body()['files'] ?? null;
  if (!is_array($files) || !$files) fail('No files');
  if (count($files) > cfg('max_files')) fail('Too many files in one send (max ' . cfg('max_files') . ')');
  $out = []; $total = 0;
  foreach ($files as $f) {
    $parts = array_map('clean_name', array_values(array_filter(explode('/', (string)($f['path'] ?? '')), fn($p) => trim($p) !== '')));
    $size = (int)($f['size'] ?? -1);
    if (!$parts || $size < 0) fail('Bad file list');
    $total += $size;
    $out[] = [implode('/', array_slice($parts, 0, 12)), $size, mb_substr((string)($f['type'] ?? ''), 0, 100)];
  }
  if ($total > cfg('max_send_bytes')) fail('That’s more than ' . fmt_bytes(cfg('max_send_bytes')) . ' in one send. Please split it up');
  return [$out, $total];
}
function new_job(array $job): string {
  $id = rid(20);
  with_json("job-$id.json", fn() => [$job + ['created' => time(), 'folders' => [], 'done' => false], null]);
  // tidy up jobs older than a week now and then
  if (random_int(1, 50) === 1) foreach (glob(data_path('job-*.json')) as $f) if (filemtime($f) < time() - 7 * 86400) @unlink($f);
  return $id;
}
function load_job(): array {
  $id = (string)(body()['job'] ?? '');
  if (!preg_match('/^[a-z2-9]{20}$/', $id)) fail('Unknown send');
  $j = read_json("job-$id.json") ?? fail('Unknown send', 404);
  if ($j['type'] === 'out') { $m = need_crew(); if ($m['id'] !== $j['by']) fail('Not yours', 403); }
  return [$id, $j];
}

/* ---------- client → Turbin ---------- */
function a_start(): never {
  $b = body();
  $name = str_in($b, 'name', 80); $email = str_in($b, 'email', 120); $company = str_in($b, 'company', 80);
  $project = str_in($b, 'project', 120); $message = str_in($b, 'message', 4000); $to = crew(str_in($b, 'to', 40));
  if (!$name || !valid_email($email) || !$to) fail('Please fill in name, email and who to send to');

  // abuse protection: sends per hour per IP, and the bot check if it's switched on
  $ok = with_json('rl-' . md5(client_ip()) . '.json', function ($d) {
    $d = array_values(array_filter($d ?? [], fn($t) => $t > time() - 3600));
    if (count($d) >= cfg('sends_per_hour')) return [$d, false];
    $d[] = time(); return [$d, true];
  });
  if (!$ok) fail('Too many sends from your network right now. Please try again in a while', 429);
  if (cfg('turnstile_secret')) {
    $r = http('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', ['Content-Type: application/x-www-form-urlencoded'],
      http_build_query(['secret' => cfg('turnstile_secret'), 'response' => str_in($b, 'turnstile', 4000), 'remoteip' => client_ip()]));
    if (!(json_decode($r['body'], true)['success'] ?? false)) fail('The bot check failed. Please reload the page and try again', 403);
  }

  [$files, $total] = file_list();
  $folderName = clean_name(date('Y-m-d') . ' ' . $name . ($company ? " ($company)" : '') . ($project ? " – $project" : ''));
  $desc = "Från: $name <$email>" . ($company ? ", $company" : '') . "\nTill: {$to['name']}" . ($message ? "\n\n$message" : '');
  $folder = make_folder(base_folder('in'), $folderName, ['tt' => 'in', 'to' => $to['id'], 'n' => (string)count($files), 'bytes' => (string)$total,
    'from' => mb_strcut($name . ($company ? " ($company)" : ''), 0, 90, 'UTF-8'), 'email' => mb_strcut($email, 0, 90, 'UTF-8')], $desc);
  $id = new_job(['type' => 'in', 'folder' => $folder, 'title' => $folderName, 'files' => $files, 'total' => $total,
    'name' => $name, 'email' => $email, 'company' => $company, 'project' => $project, 'message' => $message, 'to' => $to['id'], 'ip' => client_ip()]);
  out(['job' => $id]);
}

/* ---------- crew → client ---------- */
function a_deliver(): never {
  $m = need_crew(); $b = body();
  $title = str_in($b, 'title', 120); $client = str_in($b, 'client', 120); $message = str_in($b, 'message', 4000);
  $days = in_array((int)($b['expires'] ?? 14), [7, 14, 30], true) ? (int)$b['expires'] : 14;
  if (!$title) fail('Please name the delivery');
  if ($client && !valid_email($client)) fail('Check the client’s email address');
  [$files, $total] = file_list();
  $tid = rid(12); $exp = time() + $days * 86400;
  $folderName = clean_name(date('Y-m-d') . " {$m['name']} – $title");
  $folder = make_folder(base_folder('out'), $folderName,
    ['tt' => 'out', 'tid' => $tid, 'by' => $m['id'], 'exp' => (string)$exp, 'n' => (string)count($files), 'bytes' => (string)$total],
    $message);
  $id = new_job(['type' => 'out', 'folder' => $folder, 'title' => $title, 'files' => $files, 'total' => $total,
    'by' => $m['id'], 'tid' => $tid, 'exp' => $exp, 'client' => $client, 'message' => $message]);
  out(['job' => $id]);
}

/* ---------- per file: make its sub-folders, open a resumable upload, hand the URL to the browser ---------- */
function a_file(): never {
  [$id, $job] = load_job();
  $i = (int)(body()['i'] ?? -1);
  if ($job['done'] || !isset($job['files'][$i])) fail('Unknown file');
  [$path, $size, $type] = $job['files'][$i];
  $parts = explode('/', $path); $name = array_pop($parts);
  // folders are created under the job's lock, so files uploading in parallel never make the same folder twice
  $parent = with_json("job-$id.json", function ($j) use ($parts) {
    $at = $j['folder']; $key = '';
    foreach ($parts as $p) {
      $key .= $p . '/';
      if (empty($j['folders'][$key])) $j['folders'][$key] = make_folder($at, $p);
      $at = $j['folders'][$key];
    }
    return [$j, $at];
  });
  $url = upload_session($parent, $name, $size, $type, page_origin());
  with_json("job-$id.json", function ($j) use ($i, $url) { $j['uploads'][$i] = $url; return [$j, null]; });
  out(['url' => $url]);
}

/* After a dropped connection: how much of file i has Google got? (Google doesn't let the browser read this itself.) */
function a_status(): never {
  [, $job] = load_job();
  $i = (int)(body()['i'] ?? -1);
  $url = $job['uploads'][$i] ?? fail('Unknown file');
  $r = http('PUT', $url, ['Content-Range: bytes */' . $job['files'][$i][1], 'Content-Length: 0'], '');
  if ($r['code'] === 200 || $r['code'] === 201) out(['done' => true]);
  if ($r['code'] === 308) out(['next' => preg_match('/bytes=0-(\d+)/', $r['headers']['range'] ?? '', $m) ? (int)$m[1] + 1 : 0]);
  fail('The upload expired. Please start the send again', 410);
}

function a_finish(): never {
  [$id, $job] = load_job();
  $first = with_json("job-$id.json", fn($j) => [array_merge($j, ['done' => true]), !$j['done']]);
  $folderUrl = 'https://drive.google.com/drive/folders/' . $job['folder'];
  $n = count($job['files']); $size = fmt_bytes($job['total']);
  $list = implode("\n", array_map(fn($f) => '  ' . $f[0] . '  (' . fmt_bytes($f[1]) . ')', array_slice($job['files'], 0, 60)))
        . ($n > 60 ? "\n  … and " . ($n - 60) . ' more' : '');

  if ($job['type'] === 'in') {
    $to = crew($job['to']);
    if ($first) {
      drive('PATCH', '/files/' . $job['folder'], [], ['appProperties' => ['done' => '1']]);   // complete, so the crew inbox shows it as such in the crew inbox
      send_mail($to['email'], "Files from {$job['name']}" . ($job['company'] ? " ({$job['company']})" : '') . " – $n files, $size",
        "{$job['name']} <{$job['email']}>" . ($job['company'] ? ", {$job['company']}" : '') . " sent you $n files ($size)"
        . ($job['project'] ? " for “{$job['project']}”" : '') . ".\n\n" . ($job['message'] ? "Message:\n{$job['message']}\n\n" : '')
        . "Open in Drive:\n$folderUrl\n\n$list\n", $job['email']);
      send_mail($job['email'], "Your files reached Turbin",
        "Hi {$job['name']},\n\n{$to['name']} at Turbin has received your $n files ($size).\n\n$list\n\nThanks!\nTurbin · Klevgränd 2 · Stockholm\n", $to['email']);
    }
    out(['ok' => true, 'to' => $to['name']]);
  }

  $m = crew($job['by']);
  $url = cfg('site_url') . '?d=' . $job['tid'];
  if ($first && $job['client']) {
    send_mail($job['client'], "{$m['name']} at Turbin sent you files: {$job['title']}",
      "Hi,\n\n{$m['name']} at Turbin has sent you $n files ($size).\n\n" . ($job['message'] ? "{$job['message']}\n\n" : '')
      . "Download them here:\n$url\n\nThe link works until " . date('j M Y', $job['exp']) . ".\n\nTurbin · Klevgränd 2 · Stockholm\n", $m['email']);
  }
  out(['ok' => true, 'url' => $url, 'expires' => $job['exp'] * 1000]);
}

function a_cancel(): never {
  [$id, $job] = load_job();
  if (!$job['done']) {
    drive('PATCH', '/files/' . $job['folder'], [], ['trashed' => true]);
    @unlink(data_path("job-$id.json"));
  }
  out(['ok' => true]);
}

/* ---------- crew history: their deliveries in Utgående ---------- */
function a_history(): never {
  $m = need_crew();
  $q = q_str(base_folder('out')) . " in parents and appProperties has { key='by' and value=" . q_str($m['id']) . ' }';
  $rows = array_map(function ($f) {
    $p = $f['appProperties'] ?? [];
    return ['id' => $p['tid'] ?? '', 'title' => preg_replace('/^\d{4}-\d{2}-\d{2} \S+ – /u', '', $f['name']),
      'sent' => strtotime($f['createdTime']) * 1000, 'expires' => (int)($p['exp'] ?? 0) * 1000,
      'bytes' => (int)($p['bytes'] ?? 0), 'n' => (int)($p['n'] ?? 0), 'downloaded' => !empty($p['dl'])];
  }, drive_list($q, 'id,name,createdTime,appProperties', 200));
  usort($rows, fn($a, $b) => $b['sent'] <=> $a['sent']);
  out(['deliveries' => $rows]);
}

/* ---------- client download page ---------- */
function load_delivery(bool $fresh = false): array {
  $d = (string)($_GET['d'] ?? '');
  if (!preg_match('/^[a-z2-9]{12}$/', $d)) fail('Not found', 404);
  $cache = "dl-$d.json";
  $c = read_json($cache);
  if (!$c || $fresh || $c['at'] < time() - 300) {
    $f = drive_list(q_str(base_folder('out')) . " in parents and appProperties has { key='tid' and value=" . q_str($d) . ' }',
      'id,name,description,createdTime,appProperties', 1)[0] ?? fail('Not found', 404);
    $p = $f['appProperties'];
    $files = list_tree($f['id']);
    $c = ['at' => time(), 'folder' => $f['id'], 'by' => $p['by'] ?? '', 'exp' => (int)($p['exp'] ?? 0), 'dl' => $p['dl'] ?? '',
      'title' => preg_replace('/^\d{4}-\d{2}-\d{2} \S+ – /u', '', $f['name']), 'note' => $f['description'] ?? '',
      'sent' => strtotime($f['createdTime']), 'files' => $files];
    file_put_contents(data_path($cache), json_encode($c, JSON_UNESCAPED_UNICODE), LOCK_EX);
  }
  if ($c['exp'] < time()) fail('Expired', 410);
  return [$d, $c];
}

function a_delivery(): never {
  [, $c] = load_delivery();
  out(['title' => $c['title'], 'from' => crew($c['by'])['name'] ?? 'Turbin', 'sent' => $c['sent'] * 1000, 'expires' => $c['exp'] * 1000,
    'note' => $c['note'], 'files' => array_map(fn($f) => [$f['id'], $f['path'], $f['size']], $c['files'])]);
}

/* Every file in a folder, sub-folders included (Google Docs and the like can't be downloaded as files, so they're left out) */
function list_tree(string $folder): array {
  $files = []; $queue = [[$folder, '']];
  while ($queue) {
    [$fid, $base] = array_shift($queue);
    foreach (drive_list(q_str($fid) . ' in parents', 'id,name,mimeType,size', 10000) as $x) {
      if ($x['mimeType'] === FOLDER_MIME) $queue[] = [$x['id'], $base . $x['name'] . '/'];
      elseif (!str_starts_with($x['mimeType'], 'application/vnd.google-apps.')) $files[] = ['id' => $x['id'], 'path' => $base . $x['name'], 'size' => (int)($x['size'] ?? 0)];
    }
  }
  usort($files, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
  return $files;
}

/* Download one file of a client delivery */
function a_get(): never {
  [$d, $c] = load_delivery();
  $fid = (string)($_GET['f'] ?? '');
  $file = null;
  foreach ($c['files'] as $f) if ($f['id'] === $fid) { $file = $f; break; }
  $file ?? fail('Not found', 404);

  // the first download is marked on the folder (shows as "Downloaded" in the crew history) and the sender gets a note
  if (!$c['dl']) {
    $stamp = (string)time();
    drive('PATCH', '/files/' . $c['folder'], [], ['appProperties' => ['dl' => $stamp]]);
    with_json("dl-$d.json", fn($x) => [array_merge($x, ['dl' => $stamp]), null]);
    if ($m = crew($c['by'])) send_mail($m['email'], "Downloaded: {$c['title']}", "Your client has started downloading “{$c['title']}”.\n\n" . cfg('site_url') . "#crew\n");
  }
  stream_file($file);
}

/* Stream one file from Drive to the browser (with Range support, so a broken download can resume) */
function stream_file(array $file): never {
  $fid = $file['id'];
  @set_time_limit(0);
  ignore_user_abort(false);
  while (ob_get_level()) ob_end_clean();

  $size = $file['size']; $start = 0; $end = $size - 1; $partial = false;
  if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && $size > 0) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); } else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
    $partial = true;
  }
  $name = basename($file['path']);
  http_response_code($partial ? 206 : 200);
  header('Content-Type: application/octet-stream');
  header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\x20-\x7E]|"/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
  header('Accept-Ranges: bytes');
  header('Content-Length: ' . ($size ? $end - $start + 1 : 0));
  header('Cache-Control: private, no-store');
  header('X-Accel-Buffering: no');
  if ($partial) header("Content-Range: bytes $start-$end/$size");
  if ($size === 0) exit;

  $ch = curl_init(DRIVE_API . '/files/' . rawurlencode($fid) . '?alt=media&supportsAllDrives=true');
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . google_token(SCOPE_DRIVE), "Range: bytes=$start-$end"],
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 0, CURLOPT_BUFFERSIZE => 1 << 20,
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) { echo $chunk; flush(); return connection_aborted() ? -1 : strlen($chunk); },
  ]);
  curl_exec($ch);
  exit;
}

/* ---------- crew inbox: what clients have sent, right here instead of in Drive ---------- */
function a_inbox(): never {
  $m = need_crew();
  $q = q_str(base_folder('in')) . " in parents and appProperties has { key='tt' and value='in' }";
  if (($_GET['who'] ?? '') === 'me') $q .= " and appProperties has { key='to' and value=" . q_str($m['id']) . ' }';
  $rows = array_map(function ($f) {
    $p = $f['appProperties'] ?? [];
    return ['id' => $f['id'], 'title' => $f['name'], 'from' => $p['from'] ?? '', 'email' => $p['email'] ?? '', 'to' => $p['to'] ?? '',
      'sent' => strtotime($f['createdTime']) * 1000, 'n' => (int)($p['n'] ?? 0), 'bytes' => (int)($p['bytes'] ?? 0),
      'done' => !isset($p['n']) || !empty($p['done']), 'note' => $f['description'] ?? ''];
  }, drive_list($q, 'id,name,createdTime,description,appProperties', 300));
  usort($rows, fn($a, $b) => $b['sent'] <=> $a['sent']);
  out(['incoming' => array_slice($rows, 0, 150)]);
}
/* one incoming send: checked to really be a folder in Inkommande, then its file list (cached for 5 minutes) */
function load_incoming(): array {
  need_crew();
  $f = (string)($_GET['f'] ?? '');
  if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $f)) fail('Not found', 404);
  $c = read_json("in-$f.json");
  if (!$c || $c['at'] < time() - 300) {
    $meta = drive('GET', '/files/' . $f, ['fields' => 'id,parents,trashed,appProperties']);
    if (!empty($meta['trashed']) || !in_array(base_folder('in'), $meta['parents'] ?? [], true) || ($meta['appProperties']['tt'] ?? '') !== 'in') fail('Not found', 404);
    $c = ['at' => time(), 'files' => list_tree($f)];
    file_put_contents(data_path("in-$f.json"), json_encode($c, JSON_UNESCAPED_UNICODE), LOCK_EX);
  }
  return [$f, $c];
}
function a_inbox_files(): never {
  [$f, $c] = load_incoming();
  out(['drive' => 'https://drive.google.com/drive/folders/' . $f, 'files' => array_map(fn($x) => [$x['id'], $x['path'], $x['size']], $c['files'])]);
}
function a_inbox_get(): never {
  [, $c] = load_incoming();
  $g = (string)($_GET['g'] ?? '');
  foreach ($c['files'] as $x) if ($x['id'] === $g) stream_file($x);
  fail('Not found', 404);
}
