<?php
require __DIR__ . '/admin_config.php';
require __DIR__ . '/lib_clicks.php';
date_default_timezone_set('Asia/Jakarta');

function format_time_wib(string $iso): string {
  $iso = trim($iso);
  if ($iso === '') return '';
  try {
    $dt = new DateTimeImmutable($iso);
    $dt = $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d H:i:s') . ' WIB';
  } catch (Throwable $e) {
    return $iso;
  }
}

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

// Pagination (Klik terbaru)
$perPage = 50;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalRecent = clicks_count_all();
$totalPages = max(1, (int)ceil($totalRecent / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$summary = clicks_get_summary(200);
$recent = clicks_get_recent($perPage, $offset);
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
              <th style="width: 45%">Nama</th>
              <th style="width: 25%">Target (URL)</th>
              <th style="width: 10%" class="text-end">Total</th>
              <th style="width: 30%">Terakhir klik (WIB)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($summary)): ?>
              <tr><td colspan="4" class="text-muted p-3">Belum ada data klik.</td></tr>
            <?php else: ?>
              <?php foreach ($summary as $row): ?>
                <tr>
                  <td class="text-break">
                    <?php
                      $label = trim((string)($row['label'] ?? ''));
                      echo htmlspecialchars($label !== '' ? $label : (string)($row['target'] ?? ''));
                    ?>
                  </td>
                  <td class="text-break small">
                    <a href="<?php echo htmlspecialchars((string)($row['target'] ?? '')); ?>" target="_blank" rel="noopener">
                      <?php echo htmlspecialchars((string)($row['target'] ?? '')); ?>
                    </a>
                  </td>
                  <td class="text-end"><?php echo (int)($row['total'] ?? 0); ?></td>
                  <td><?php echo htmlspecialchars(format_time_wib((string)($row['last_at'] ?? ''))); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header fw-semibold">
      Klik terbaru
      <span class="text-muted fw-normal small">
        <?php
          $from = $totalRecent === 0 ? 0 : ($offset + 1);
          $to = min($totalRecent, $offset + $perPage);
          echo "(Menampilkan {$from}–{$to} dari {$totalRecent})";
        ?>
      </span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 18%">Waktu (WIB)</th>
              <th style="width: 26%">Nama</th>
              <th style="width: 34%">Target</th>
              <th style="width: 28%">Sumber</th>
              <th style="width: 12%">IP</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr><td colspan="5" class="text-muted p-3">Belum ada data klik.</td></tr>
            <?php else: ?>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars(format_time_wib((string)($row['created_at'] ?? ''))); ?></td>
                  <td class="text-break">
                    <?php
                      $label = trim((string)($row['label'] ?? ''));
                      echo htmlspecialchars($label !== '' ? $label : (string)($row['target'] ?? ''));
                    ?>
                  </td>
                  <td class="text-break"><?php echo htmlspecialchars((string)($row['target'] ?? '')); ?></td>
                  <td class="text-break"><?php echo htmlspecialchars((string)($row['source'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($row['ip'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="p-3" aria-label="Paginasi klik">
          <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
              <a class="page-link" href="?page=<?php echo max(1, $currentPage - 1); ?>" tabindex="-1">Sebelumnya</a>
            </li>
            <li class="page-item disabled">
              <span class="page-link">Halaman <?php echo (int)$currentPage; ?> / <?php echo (int)$totalPages; ?></span>
            </li>
            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
              <a class="page-link" href="?page=<?php echo min($totalPages, $currentPage + 1); ?>">Berikutnya</a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
