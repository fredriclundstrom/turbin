<?php
/*
  Turbin Transfer – settings.
  Copy this file to config.php on the server and fill it in. config.php and everything in private/
  are never committed to git (see .gitignore) and Apache refuses to serve them (see .htaccess).
*/
return [

  // ---- Google ----------------------------------------------------------------------------
  // The service account's JSON key (Google Cloud → IAM → Service accounts → Keys → Add key → JSON).
  // Keep it outside the web root if Loopia allows it, otherwise in private/ (blocked by .htaccess).
  'service_account_file' => __DIR__ . '/private/service-account.json',

  // ID of the folder Turbin/Transfer in the shared drive (the last part of its URL in Drive).
  // Inkommande/ and Utgående/ are created inside it automatically if they don't exist.
  'transfer_folder_id' => 'PASTE-FOLDER-ID-HERE',
  'incoming_name' => 'Inkommande',
  'outgoing_name' => 'Utgående',

  // ---- Crew sign-in -------------------------------------------------------------------------
  // Simple: one shared crew password (pick a long one). Used when google_client_id is empty.
  'crew_password' => 'CHOOSE-A-LONG-PASSWORD',
  // Later, optional: "Sign in with Google" instead – an OAuth client ID from Google Cloud → APIs & Services → Credentials.
  'google_client_id' => '',
  // Only accounts in this Workspace domain can sign in as crew.
  'workspace_domain' => 'turbin.se',

  // ---- Mail ------------------------------------------------------------------------------
  // 'php':   PHP mail() on Loopia – works right away (add Loopia to turbin.se's SPF record so it isn't marked as spam).
  // 'gmail': later, optional – through the Gmail API as `mail_as` (needs domain-wide delegation, scope gmail.send).
  'mail_via'  => 'php',
  'mail_as'   => 'info@turbin.se',      // a real Workspace user (or an alias of one) that mail is sent from
  'mail_name' => 'Turbin Transfer',

  // ---- The crew: id => [name, email] ---------------------------------------------------
  // Clients choose a recipient from this list; crew sign in with the email listed here.
  'crew' => [
    'claes'   => ['Claes',   'claes@turbin.se'],
    'fredric' => ['Fredric', 'fredric@turbin.se'],
    'kicki'   => ['Kicki',   'kicki@turbin.se'],
    'ola'     => ['Ola',     'ola@turbin.se'],
    'patrik'  => ['Patrik',  'patrik@turbin.se'],
    'roger'   => ['Roger',   'roger@turbin.se'],
    'thomas'  => ['Thomas',  'thomas@turbin.se'],
  ],

  // ---- Site ------------------------------------------------------------------------------
  // Origins allowed to upload straight to Google (the page's own address; add http://localhost:8765 for testing).
  'origins'  => ['https://transfer.turbin.se'],
  'site_url' => 'https://transfer.turbin.se/',

  // ---- Abuse protection (the client side of the page is open) -----------------------------
  'max_send_bytes'   => 500 * 1000 ** 3,   // 500 GB per send
  'max_files'        => 5000,              // files per send
  'sends_per_hour'   => 10,                // per IP address
  // Cloudflare Turnstile (invisible bot check). Leave empty to switch it off.
  'turnstile_site_key' => '',
  'turnstile_secret'   => '',

  // Where the backend keeps small working files (upload jobs, token cache, rate limits).
  'data_dir' => __DIR__ . '/private/data',
];
