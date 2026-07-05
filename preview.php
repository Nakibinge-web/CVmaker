<?php
/**
 * preview.php — Full-page CV preview
 *
 * Loads a saved CV record and renders it as a full-page HTML preview
 * with download buttons. Opened after the user clicks "Save CV".
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/template.php';

$cvId = isset($_GET['cv_id']) && ctype_digit((string) $_GET['cv_id']) ? (int) $_GET['cv_id'] : 0;

$cvHtml  = '';
$error   = '';
$cvFound = false;

if ($cvId > 0) {
    try {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare('SELECT cv_data FROM cv_records WHERE id = :id');
        $stmt->execute([':id' => $cvId]);
        $row  = $stmt->fetch();

        if ($row) {
            $cvData  = json_decode($row['cv_data'], true) ?? [];
            $cvHtml  = renderCVHtml($cvData);
            $cvFound = true;
        } else {
            $error = 'CV not found.';
        }
    } catch (Throwable $e) {
        $error = 'Could not load CV.';
    }
} else {
    $error = 'No CV ID provided.';
}

$safeId = $cvId > 0 ? $cvId : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CV Preview</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --accent: #2563eb;
      --accent-dark: #1d4ed8;
      --success: #16a34a;
      --danger: #dc2626;
      --border: #e5e7eb;
      --radius: 6px;
    }
    body {
      font-family: 'Inter', system-ui, sans-serif;
      background: #f5f6f8;
      min-height: 100vh;
    }

    /* ── Top bar ── */
    .preview-topbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #fff;
      border-bottom: 1px solid var(--border);
      padding: 12px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .topbar-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      font-weight: 500;
      color: #555;
      text-decoration: none;
      padding: 6px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: #fff;
      transition: background .15s, color .15s;
    }
    .btn-back:hover { background: #f3f4f6; color: #111; }
    .topbar-title {
      font-size: 14px;
      font-weight: 600;
      color: #1c1c1e;
    }
    .topbar-actions { display: flex; gap: 8px; }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 18px;
      font-family: inherit;
      font-size: 13px;
      font-weight: 600;
      border-radius: var(--radius);
      border: none;
      cursor: pointer;
      text-decoration: none;
      transition: background .15s;
      white-space: nowrap;
    }
    .btn-primary   { background: var(--accent); color: #fff; }
    .btn-primary:hover { background: var(--accent-dark); }
    .btn-secondary { background: #e5e7eb; color: #1c1c1e; }
    .btn-secondary:hover { background: #d1d5db; }

    /* ── CV paper ── */
    .cv-wrapper {
      max-width: 860px;
      margin: 40px auto;
      padding: 0 24px 60px;
    }
    .cv-paper {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 4px 24px rgba(0,0,0,.10);
      overflow: hidden;
    }
    .cv-paper-inner {
      padding: 52px 56px;
    }

    /* ── Error state ── */
    .error-box {
      max-width: 480px;
      margin: 80px auto;
      text-align: center;
      color: #555;
    }
    .error-box h2 { font-size: 1.2em; margin-bottom: 8px; color: #1c1c1e; }
    .error-box p  { font-size: 0.9em; margin-bottom: 20px; }
  </style>
</head>
<body>

  <div class="preview-topbar">
    <div class="topbar-left">
      <a href="index.html<?= $safeId ? '?cv_id=' . $safeId : '' ?>" class="btn-back">
        ← Edit CV
      </a>
      <span class="topbar-title">CV Preview</span>
    </div>
    <?php if ($cvFound): ?>
    <div class="topbar-actions">
      <a href="download.php?cv_id=<?= $safeId ?>&format=pdf" class="btn btn-primary" target="_blank">
        Download PDF
      </a>
      <a href="download.php?cv_id=<?= $safeId ?>&format=docx" class="btn btn-secondary" target="_blank">
        Download DOCX
      </a>
    </div>
    <?php endif; ?>
  </div>

  <div class="cv-wrapper">
    <?php if ($cvFound): ?>
      <div class="cv-paper">
        <div class="cv-paper-inner">
          <?= $cvHtml ?>
        </div>
      </div>
    <?php else: ?>
      <div class="error-box">
        <h2>Preview unavailable</h2>
        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="index.html" class="btn btn-primary">← Back to editor</a>
      </div>
    <?php endif; ?>
  </div>

</body>
</html>
