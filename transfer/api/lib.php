<?php
/* Turbin Transfer – shared helpers: config, JSON replies, Google auth, Drive, mail, small file store. */
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) { http_response_code(503); header('Content-Type: application/json'); echo '{"error":"Not configured"}'; exit; }
$CFG = require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Stockholm');

const SCOPE_DRIVE = 'https://www.googleapis.com/auth/drive';
const SCOPE_GMAIL = 'https://www.googleapis.com/auth/gmail.send';
const DRIVE_API   = 'https://www.googleapis.com/drive/v3';
const FOLDER_MIME = 'application/vnd.google-apps.folder';

function cfg(string $k, mixed $d = null): mixed { global $CFG; return $CFG[$k] ?? $d; }

/* ---------- replies ---------- */
function out(mixed $data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function body(): array {
  static $b = null;
  if ($b === null) { $b = json_decode(file_get_contents('php://input') ?: '[]', true); if (!is_array($b)) $b = []; }
  return $b;
}
function str_in(array $a, string $k, int $max = 200): string {
  $v = trim((string)($a[$k] ?? ''));
  $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
  return mb_substr($v, 0, $max);
}
function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }

/* ---------- small file store (private/data) ---------- */
function data_path(string $name): string {
  $dir = cfg('data_dir');
  if (!is_dir($dir)) mkdir($dir, 0700, true);
  return $dir . '/' . $name;
}
function rid(int $n = 12): string {
  $abc = 'abcdefghijkmnpqrstuvwxyz23456789'; $s = '';
  foreach (str_split(random_bytes($n)) as $c) $s .= $abc[ord($c) % 32];
  return $s;
}
/* Read-modify-write a JSON file under an exclusive lock. $fn gets the data (or null) and returns the new data. */
function with_json(string $file, callable $fn): mixed {
  $fh = fopen(data_path($file), 'c+');
  flock($fh, LOCK_EX);
  $raw = stream_get_contents($fh);
  $data = $raw ? json_decode($raw, true) : null;
  try {
    [$new, $ret] = $fn($data);
    if ($new !== $data) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($new, JSON_UNESCAPED_UNICODE)); }
    return $ret;
  } finally { flock($fh, LOCK_UN); fclose($fh); }
}
function read_json(string $file): ?array {
  $p = data_path($file);
  return is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
}

/* ---------- HTTP ---------- */
function http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array {
  $ch = curl_init($url);
  $rh = [];
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$rh) {
      $p = strpos($h, ':'); if ($p) $rh[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
      return strlen($h);
    },
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $res = curl_exec($ch);
  if ($res === false) throw new RuntimeException('HTTP error: ' . curl_error($ch));
  return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $res, 'headers' => $rh];
}

/* ---------- Google: service-account access token (cached until it expires) ---------- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function google_token(string $scope, ?string $sub = null): string {
  $cache = 'token-' . md5($scope . '|' . $sub) . '.json';
  $c = read_json($cache);
  if ($c && $c['exp'] > time() + 120) return $c['token'];
  $sa = json_decode((string)file_get_contents(cfg('service_account_file')), true);
  if (!$sa) throw new RuntimeException('Service account key missing');
  $now = time();
  $claims = ['iss' => $sa['client_email'], 'scope' => $scope, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600];
  if ($sub) $claims['sub'] = $sub;
  $jwt = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64u(json_encode($claims));
  if (!openssl_sign($jwt, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) throw new RuntimeException('Could not sign token');
  $r = http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . b64u($sig)]));
  if ($r['code'] !== 200) throw new RuntimeException('Google token: ' . $r['body']);
  $j = json_decode($r['body'], true);
  file_put_contents(data_path($cache), json_encode(['token' => $j['access_token'], 'exp' => $now + (int)$j['expires_in']]), LOCK_EX);
  return $j['access_token'];
}

/* ---------- Drive ---------- */
function drive(string $method, string $path, array $query = [], ?array $json = null): array {
  $query += ['supportsAllDrives' => 'true'];
  $h = ['Authorization: Bearer ' . google_token(SCOPE_DRIVE)];
  if ($json !== null) $h[] = 'Content-Type: application/json; charset=UTF-8';
  $r = http($method, DRIVE_API . $path . '?' . http_build_query($query), $h, $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : null);
  if ($r['code'] >= 300) throw new RuntimeException("Drive $method $path: {$r['code']} {$r['body']}");
  return json_decode($r['body'] ?: 'null', true) ?? [];
}
function q_str(string $s): string { return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'"; }
function drive_list(string $q, string $fields = 'id,name', int $max = 1000): array {
  $out = []; $token = null;
  do {
    $r = drive('GET', '/files', array_filter([
      'q' => $q . ' and trashed = false', 'fields' => "nextPageToken,files($fields)", 'pageSize' => min(1000, $max),
      'corpora' => 'allDrives', 'includeItemsFromAllDrives' => 'true', 'pageToken' => $token,
    ]));
    array_push($out, ...($r['files'] ?? []));
    $token = $r['nextPageToken'] ?? null;
  } while ($token && count($out) < $max);
  return $out;
}
function find_folder(string $parent, string $name): ?string {
  $f = drive_list(q_str($parent) . ' in parents and name = ' . q_str($name) . " and mimeType = '" . FOLDER_MIME . "'", 'id', 1);
  return $f[0]['id'] ?? null;
}
function make_folder(string $parent, string $name, array $props = [], string $desc = ''): string {
  $meta = ['name' => $name, 'mimeType' => FOLDER_MIME, 'parents' => [$parent]];
  if ($props) $meta['appProperties'] = $props;
  if ($desc !== '') $meta['description'] = $desc;
  return drive('POST', '/files', ['fields' => 'id'], $meta)['id'];
}
/* Inkommande / Utgående inside Turbin/Transfer, looked up once and remembered */
function base_folder(string $which): string {
  $name = cfg($which === 'in' ? 'incoming_name' : 'outgoing_name');
  return with_json('folders.json', function ($d) use ($name) {
    $d = $d ?? [];
    if (empty($d[$name])) $d[$name] = find_folder(cfg('transfer_folder_id'), $name) ?? make_folder(cfg('transfer_folder_id'), $name);
    return [$d, $d[$name]];
  });
}
/* A Drive-safe folder or file name */
function clean_name(string $s): string { return trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $s) ?? '', " .") ?: 'Untitled'; }

/* Start a resumable upload; the browser then PUTs the bytes straight to the returned URL.
   Sending the page's Origin here is what lets the browser talk to that URL (CORS). */
function upload_session(string $parent, string $name, int $size, string $mime, string $origin): string {
  $r = http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id', [
    'Authorization: Bearer ' . google_token(SCOPE_DRIVE),
    'Content-Type: application/json; charset=UTF-8',
    'X-Upload-Content-Length: ' . $size,
    'X-Upload-Content-Type: ' . ($mime ?: 'application/octet-stream'),
    'Origin: ' . $origin,
  ], json_encode(['name' => $name, 'parents' => [$parent]], JSON_UNESCAPED_UNICODE));
  if ($r['code'] !== 200 || empty($r['headers']['location'])) throw new RuntimeException('Upload session: ' . $r['code'] . ' ' . $r['body']);
  return $r['headers']['location'];
}

/* ---------- mail ---------- */
function send_mail(string $to, string $subject, string $text, ?string $replyTo = null): void {
  $from = cfg('mail_as');
  $name = '=?UTF-8?B?' . base64_encode(cfg('mail_name')) . '?=';
  $headers = [
    "From: $name <$from>", "To: $to", 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
  ];
  if ($replyTo) $headers[] = "Reply-To: $replyTo";
  try {
    if (cfg('mail_via') === 'gmail') {
      $raw = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
      $r = http('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        ['Authorization: Bearer ' . google_token(SCOPE_GMAIL, $from), 'Content-Type: application/json'], json_encode(['raw' => b64u($raw)]));
      if ($r['code'] >= 300) throw new RuntimeException('Gmail: ' . $r['body']);
    } else {
      $h = array_values(array_filter($headers, fn($l) => !str_starts_with($l, 'To:') && !str_starts_with($l, 'Subject:')));
      mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), implode("\r\n", $h));
    }
  } catch (Throwable $e) { error_log('Turbin Transfer mail: ' . $e->getMessage()); }   // a failed mail never fails the send
}

/* ---------- people ---------- */
function crew(?string $id = null): ?array {
  $c = cfg('crew', []);
  if ($id === null) return $c;
  return isset($c[$id]) ? ['id' => $id, 'name' => $c[$id][0], 'email' => $c[$id][1]] : null;
}
function crew_by_email(string $email): ?array {
  foreach (cfg('crew', []) as $id => [$name, $mail]) if (strcasecmp($mail, $email) === 0) return crew($id);
  return null;
}
function start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name('tt');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
}
function me(): ?array { start_session(); return isset($_SESSION['crew']) ? crew($_SESSION['crew']) : null; }
function need_crew(): array { return me() ?? fail('Please sign in', 401); }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function page_origin(): string {
  $o = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (!$o && !empty($_SERVER['HTTP_REFERER'])) { $u = parse_url($_SERVER['HTTP_REFERER']); $o = ($u['scheme'] ?? '') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''); }
  return in_array($o, cfg('origins', []), true) ? $o : cfg('origins')[0];
}
function fmt_bytes(float $b): string { $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; while ($b >= 1000 && $i < 4) { $b /= 1000; $i++; } return ($i ? round($b, $b < 10 ? 1 : 0) : $b) . ' ' . $u[$i]; }
