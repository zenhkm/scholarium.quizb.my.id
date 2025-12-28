<?php
require __DIR__ . '/admin_config.php';
require __DIR__ . '/lib_clicks.php';

function send_unauthorized(): void {
    header('WWW-Authenticate: Basic realm="Scholarium Admin"');
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

function get_basic_auth(): array {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    // Some server setups don't populate PHP_AUTH_*
    if ($user === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($auth, 'basic ') === 0) {
            $decoded = base64_decode(substr($auth, 6));
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }

    return [(string)$user, (string)$pass];
}

[$u, $p] = get_basic_auth();
if ($u === '' && $p === '') send_unauthorized();
if (!hash_equals($ADMIN_USER, $u) || !hash_equals($ADMIN_PASS, $p)) send_unauthorized();

$summary = clicks_get_summary(200);
$recent = clicks_get_recent(100);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Tracking Klik | Scholarium</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark" style="background:#0f172a;">
  <div class="container">
    <span class="navbar-brand mb-0 h1">Admin Tracking Klik</span>
    <a class="btn btn-sm btn-outline-light" href="index.php">Kembali ke situs</a>
  </div>
</nav>

<main class="container py-4">
  <div class="alert alert-warning">
    <strong>Keamanan:</strong> pastikan Anda sudah mengganti kredensial di <code>admin_config.php</code>.
  </div>

  <div class="card mb-4">
    <div class="card-header fw-semibold">Ringkasan (Top klik)</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 60%">Target (file / URL)</th>
              <th style="width: 10%" class="text-end">Total</th>
              <th style="width: 30%">Terakhir klik (UTC)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($summary)): ?>
              <tr><td colspan="3" class="text-muted p-3">Belum ada data klik.</td></tr>
            <?php else: ?>
              <?php foreach ($summary as $row): ?>
                <tr>
                  <td class="text-break">
                    <a href="<?php echo htmlspecialchars($row['target']); ?>" target="_blank" rel="noopener">
                      <?php echo htmlspecialchars($row['target']); ?>
                    </a>
                  </td>
                  <td class="text-end"><?php echo (int)($row['total'] ?? 0); ?></td>
                  <td><?php echo htmlspecialchars((string)($row['last_at'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header fw-semibold">Klik terbaru (100)</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 18%">Waktu (UTC)</th>
              <th style="width: 42%">Target</th>
              <th style="width: 28%">Sumber</th>
              <th style="width: 12%">IP</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr><td colspan="4" class="text-muted p-3">Belum ada data klik.</td></tr>
            <?php else: ?>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></td>
                  <td class="text-break"><?php echo htmlspecialchars((string)($row['target'] ?? '')); ?></td>
                  <td class="text-break"><?php echo htmlspecialchars((string)($row['source'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($row['ip'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
