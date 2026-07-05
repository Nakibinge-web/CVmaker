<?php
/**
 * template.php — CV Template Engine
 *
 * Provides three rendering functions:
 *   renderCVHtml(array $cvData): string
 *   renderCVPdf(array $cvData): Dompdf\Dompdf
 *   renderCVDocx(array $cvData): PhpOffice\PhpWord\PhpWord
 *
 * Requirements: 6.1–6.7, 9.2
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * HTML-encode a value for safe output in HTML context.
 * Returns an empty string for null/false.
 *
 * Requirements: 9.2
 */
function e(mixed $val): string
{
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convert a newline-separated string into <p> tags.
 * Each non-empty line becomes its own paragraph.
 */
function nl2p(string $text): string
{
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    if (empty($lines)) {
        return '';
    }
    return implode('', array_map(fn(string $l) => '<p class="cv-para">' . e($l) . '</p>', $lines));
}

// ---------------------------------------------------------------------------
// Section renderers
// ---------------------------------------------------------------------------

/**
 * Render the Contact Information section.
 *
 * @param array<string, mixed> $contact
 */
function renderContact(array $contact, string $photo = ''): string
{
    $name    = e($contact['name']    ?? '');
    $phone   = e($contact['phone']   ?? '');
    $email   = e($contact['email']   ?? '');
    $linkedin = e($contact['linkedin'] ?? '');
    $address = e($contact['address'] ?? '');

    $meta = [];
    if ($phone)    $meta[] = '<span class="cv-meta-item">' . $phone . '</span>';
    if ($email)    $meta[] = '<span class="cv-meta-item">' . $email . '</span>';
    if ($linkedin) $meta[] = '<span class="cv-meta-item">' . $linkedin . '</span>';
    if ($address)  $meta[] = '<span class="cv-meta-item">' . $address . '</span>';

    $metaHtml = $meta ? '<div class="cv-contact-meta">' . implode('<span class="cv-meta-sep"> | </span>', $meta) . '</div>' : '';

    $photoHtml = '';
    if ($photo !== '') {
        // Only allow data URLs with safe image MIME types
        if (preg_match('/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/', $photo)) {
            $photoHtml = '<img class="cv-photo" src="' . $photo . '" alt="Profile photo">';
        }
    }

    return <<<HTML
<header class="cv-section cv-contact">
  {$photoHtml}
  <div class="cv-contact-text">
    <h1 class="cv-name">{$name}</h1>
    {$metaHtml}
  </div>
</header>
HTML;
}

/**
 * Render the Professional Summary section.
 */
function renderSummary(string $summary): string
{
    if (trim($summary) === '') {
        return '';
    }
    $body = nl2p($summary);
    return <<<HTML
<section class="cv-section cv-summary">
  <h2 class="cv-section-heading">Professional Summary</h2>
  <div class="cv-section-body">{$body}</div>
</section>
HTML;
}

/**
 * Render the Work Experience section.
 * Entries are sorted in reverse chronological order (most recent first).
 *
 * Requirements: 6.3
 *
 * @param array<int, array<string, mixed>> $entries
 */
function renderWorkExperience(array $entries): string
{
    if (empty($entries)) {
        return '';
    }

    // Sort reverse chronologically by start_date (desc).
    usort($entries, function (array $a, array $b): int {
        $dateA = $a['start_date'] ?? '';
        $dateB = $b['start_date'] ?? '';
        return strcmp((string) $dateB, (string) $dateA);
    });

    $rows = '';
    foreach ($entries as $entry) {
        $title   = e($entry['job_title']    ?? '');
        $company = e($entry['company']      ?? '');
        $start   = e($entry['start_date']   ?? '');
        $end     = e($entry['end_date']     ?? '');
        $present = !empty($entry['present']) ? 'Present' : $end;
        $resp    = e($entry['responsibilities'] ?? '');

        $rows .= <<<HTML
  <div class="cv-entry">
    <div class="cv-entry-header">
      <div class="cv-entry-left">
        <span class="cv-entry-title">{$title}</span>
        <span class="cv-entry-org">{$company}</span>
      </div>
      <span class="cv-entry-dates">{$start} – {$present}</span>
    </div>
    <div class="cv-entry-body">{$resp}</div>
  </div>
HTML;
    }

    return <<<HTML
<section class="cv-section cv-work-experience">
  <h2 class="cv-section-heading">Work Experience</h2>
  <div class="cv-section-body">{$rows}</div>
</section>
HTML;
}

/**
 * Render the Skills section.
 *
 * @param array<int, string>|string $skills
 */
function renderSkills(mixed $skills): string
{
    if (empty($skills)) {
        return '';
    }

    if (is_string($skills)) {
        $items = array_filter(array_map('trim', explode(',', $skills)));
    } else {
        $items = (array) $skills;
    }

    if (empty($items)) {
        return '';
    }

    $tags = implode('', array_map(fn(string $s) => '<span class="cv-skill-tag">' . e($s) . '</span>', $items));

    return <<<HTML
<section class="cv-section cv-skills">
  <h2 class="cv-section-heading">Skills</h2>
  <div class="cv-section-body cv-skills-list">{$tags}</div>
</section>
HTML;
}

/**
 * Render the Education section.
 *
 * @param array<int, array<string, mixed>> $entries
 */
function renderEducation(array $entries): string
{
    if (empty($entries)) {
        return '';
    }

    $rows = '';
    foreach ($entries as $entry) {
        $degree      = e($entry['degree']      ?? '');
        $institution = e($entry['institution'] ?? '');
        $grad        = e($entry['graduation_date'] ?? '');
        $honours     = e($entry['honours']     ?? '');

        $honoursHtml = $honours ? '<span class="cv-entry-honours">' . $honours . '</span>' : '';

        $rows .= <<<HTML
  <div class="cv-entry">
    <div class="cv-entry-header">
      <div class="cv-entry-left">
        <span class="cv-entry-title">{$degree}</span>
        <span class="cv-entry-org">{$institution}</span>
      </div>
      <span class="cv-entry-dates">{$grad}</span>
    </div>
    {$honoursHtml}
  </div>
HTML;
    }

    return <<<HTML
<section class="cv-section cv-education">
  <h2 class="cv-section-heading">Education</h2>
  <div class="cv-section-body">{$rows}</div>
</section>
HTML;
}

// ---------------------------------------------------------------------------
// Optional section renderers
// ---------------------------------------------------------------------------

/**
 * Render a generic list-based optional section.
 *
 * @param string                           $heading
 * @param array<int, array<string, mixed>> $entries
 * @param callable                         $rowFn   Maps entry array → HTML string
 */
function renderOptionalList(string $heading, array $entries, callable $rowFn): string
{
    if (empty($entries)) {
        return '';
    }
    $rows = implode('', array_map($rowFn, $entries));
    $h    = e($heading);
    return <<<HTML
<section class="cv-section cv-optional">
  <h2 class="cv-section-heading">{$h}</h2>
  <div class="cv-section-body">{$rows}</div>
</section>
HTML;
}

/** Render Projects/Portfolio optional section. */
function renderProjects(array $entries): string
{
    return renderOptionalList('Projects / Portfolio', $entries, function (array $e): string {
        $name = e($e['name'] ?? '');
        $desc = e($e['description'] ?? '');
        $url  = e($e['url'] ?? '');
        $urlHtml = $url ? '<span class="cv-entry-url">' . $url . '</span>' : '';
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$name}</span>{$urlHtml}<div class=\"cv-entry-body\">{$desc}</div></div>";
    });
}

/** Render Certifications & Licenses optional section. */
function renderCertifications(array $entries): string
{
    return renderOptionalList('Certifications &amp; Licenses', $entries, function (array $e): string {
        $name   = e($e['name']   ?? '');
        $issuer = e($e['issuer'] ?? '');
        $date   = e($e['date']   ?? '');
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$name}</span><span class=\"cv-entry-org\">{$issuer}</span><span class=\"cv-entry-dates\">{$date}</span></div>";
    });
}

/** Render Awards & Honors optional section. */
function renderAwards(array $entries): string
{
    return renderOptionalList('Awards &amp; Honors', $entries, function (array $e): string {
        $title = e($e['title'] ?? '');
        $org   = e($e['organization'] ?? '');
        $date  = e($e['date'] ?? '');
        $desc  = e($e['description'] ?? '');
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$title}</span><span class=\"cv-entry-org\">{$org}</span><span class=\"cv-entry-dates\">{$date}</span><div class=\"cv-entry-body\">{$desc}</div></div>";
    });
}

/** Render Languages optional section. */
function renderLanguages(array $entries): string
{
    return renderOptionalList('Languages', $entries, function (array $e): string {
        $lang  = e($e['language']    ?? '');
        $level = e($e['proficiency'] ?? '');
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$lang}</span><span class=\"cv-entry-org\">{$level}</span></div>";
    });
}

/** Render Publications & Research optional section. */
function renderPublications(array $entries): string
{
    return renderOptionalList('Publications &amp; Research', $entries, function (array $e): string {
        $title   = e($e['title']   ?? '');
        $journal = e($e['journal'] ?? '');
        $date    = e($e['date']    ?? '');
        $url     = e($e['url']     ?? '');
        $urlHtml = $url ? '<span class="cv-entry-url">' . $url . '</span>' : '';
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$title}</span><span class=\"cv-entry-org\">{$journal}</span><span class=\"cv-entry-dates\">{$date}</span>{$urlHtml}</div>";
    });
}

/** Render Professional Memberships optional section. */
function renderMemberships(array $entries): string
{
    return renderOptionalList('Professional Memberships', $entries, function (array $e): string {
        $org  = e($e['organization'] ?? '');
        $role = e($e['role']         ?? '');
        $date = e($e['date']         ?? '');
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$org}</span><span class=\"cv-entry-org\">{$role}</span><span class=\"cv-entry-dates\">{$date}</span></div>";
    });
}

/** Render References optional section. */
function renderReferences(array $entries): string
{
    return renderOptionalList('References', $entries, function (array $e): string {
        $name    = e($e['name']    ?? '');
        $title   = e($e['title']   ?? '');
        $company = e($e['company'] ?? '');
        $contact = e($e['contact'] ?? '');
        return "<div class=\"cv-entry\"><span class=\"cv-entry-title\">{$name}</span><span class=\"cv-entry-org\">{$title}, {$company}</span><span class=\"cv-entry-dates\">{$contact}</span></div>";
    });
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Render CV data as a complete, styled HTML document.
 *
 * Core sections are always rendered in the required order:
 *   Contact → Summary → Work Experience → Skills → Education
 *
 * Optional sections are rendered only when activated, in the required order:
 *   Projects → Certifications → Awards → Languages →
 *   Publications → Memberships → References
 *
 * All output values are HTML-encoded to prevent XSS.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 9.2
 *
 * @param  array<string, mixed> $cvData Decoded CV data from the database
 * @return string               Complete HTML document string
 */
function renderCVHtml(array $cvData): string
{
    // ---- Core sections (always rendered) -----------------------------------
    $contact = renderContact((array) ($cvData['contact'] ?? []), (string) ($cvData['photo'] ?? ''));
    $summary = renderSummary((string) ($cvData['summary'] ?? ''));
    $work    = renderWorkExperience((array) ($cvData['work_experience'] ?? []));
    $skills  = renderSkills($cvData['skills'] ?? []);
    $edu     = renderEducation((array) ($cvData['education'] ?? []));

    // ---- Optional sections (rendered only when active) ---------------------
    $optional = '';

    $optionalSections = $cvData['optional_sections'] ?? [];

    if (!empty($optionalSections['projects']['active'])) {
        $optional .= renderProjects((array) ($optionalSections['projects']['entries'] ?? []));
    }
    if (!empty($optionalSections['certifications']['active'])) {
        $optional .= renderCertifications((array) ($optionalSections['certifications']['entries'] ?? []));
    }
    if (!empty($optionalSections['awards']['active'])) {
        $optional .= renderAwards((array) ($optionalSections['awards']['entries'] ?? []));
    }
    if (!empty($optionalSections['languages']['active'])) {
        $optional .= renderLanguages((array) ($optionalSections['languages']['entries'] ?? []));
    }
    if (!empty($optionalSections['publications']['active'])) {
        $optional .= renderPublications((array) ($optionalSections['publications']['entries'] ?? []));
    }
    if (!empty($optionalSections['memberships']['active'])) {
        $optional .= renderMemberships((array) ($optionalSections['memberships']['entries'] ?? []));
    }
    if (!empty($optionalSections['references']['active'])) {
        $optional .= renderReferences((array) ($optionalSections['references']['entries'] ?? []));
    }

    // ---- Inline CSS --------------------------------------------------------
    $css = <<<CSS
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@300;400;500;600&display=swap');
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
  font-size: 13.5px;
  font-weight: 400;
  line-height: 1.65;
  color: #1c1c1e;
  background: #fff;
  padding: 48px 52px;
  max-width: 900px;
  margin: 0 auto;
}

/* ── Header / Contact ── */
.cv-contact {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 14px;
  padding-bottom: 28px;
  margin-bottom: 32px;
  border-bottom: 2px solid #1c1c1e;
}
.cv-contact-text { width: 100%; }
.cv-name {
  font-family: 'Playfair Display', 'Georgia', serif;
  font-size: 2.6em;
  font-weight: 700;
  letter-spacing: -0.02em;
  line-height: 1.1;
  color: #1c1c1e;
  margin-bottom: 10px;
}
.cv-contact-meta {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px 0;
  font-size: 0.82em;
  font-weight: 400;
  color: #555;
  letter-spacing: 0.01em;
}
.cv-meta-item { white-space: nowrap; }
.cv-meta-sep { color: #bbb; padding: 0 8px; }
.cv-photo {
  width: 110px;
  height: 110px;
  border-radius: 50%;
  object-fit: cover;
  display: block;
  border: 3px solid #e8e8e8;
  box-shadow: 0 2px 12px rgba(0,0,0,0.10);
}

/* ── Sections ── */
.cv-section { margin-bottom: 28px; }

.cv-section-heading {
  font-family: 'Inter', sans-serif;
  font-size: 0.68em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.18em;
  color: #888;
  margin-bottom: 14px;
  padding-bottom: 6px;
  border-bottom: 1px solid #e8e8e8;
}

.cv-section-body { }

/* ── Summary ── */
.cv-summary .cv-section-body {
  font-size: 0.95em;
  color: #3a3a3a;
  line-height: 1.75;
}
.cv-para { margin-bottom: 6px; }

/* ── Work / Education entries ── */
.cv-entry { margin-bottom: 20px; }

.cv-entry-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 3px;
  flex-wrap: wrap;
}

.cv-entry-left { flex: 1; }

.cv-entry-title {
  font-size: 0.97em;
  font-weight: 600;
  color: #1c1c1e;
  display: block;
}

.cv-entry-org {
  font-size: 0.88em;
  font-weight: 400;
  color: #666;
  display: block;
  margin-top: 1px;
}

.cv-entry-dates {
  font-size: 0.78em;
  font-weight: 500;
  color: #999;
  white-space: nowrap;
  letter-spacing: 0.02em;
  flex-shrink: 0;
}

.cv-entry-body {
  font-size: 0.88em;
  color: #4a4a4a;
  line-height: 1.65;
  margin-top: 6px;
}

.cv-entry-honours {
  font-size: 0.82em;
  font-style: italic;
  color: #777;
  margin-top: 3px;
  display: block;
}

.cv-entry-url {
  font-size: 0.82em;
  color: #2563eb;
  display: block;
  margin-top: 2px;
}

/* ── Skills ── */
.cv-skills-list {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
}

.cv-skill-tag {
  background: #f4f4f5;
  color: #3a3a3a;
  border: 1px solid #e4e4e7;
  border-radius: 4px;
  padding: 3px 11px;
  font-size: 0.82em;
  font-weight: 500;
  letter-spacing: 0.01em;
}

/* ── Optional sections ── */
.cv-optional .cv-entry-header { flex-wrap: wrap; }
CSS;

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Curriculum Vitae</title>
  <style>{$css}</style>
</head>
<body>
{$contact}
{$summary}
{$work}
{$skills}
{$edu}
{$optional}
</body>
</html>
HTML;
}

/**
 * Render CV data as a PDF using Dompdf.
 *
 * Generates HTML via renderCVHtml(), loads it into Dompdf, and calls
 * render() so the instance is ready for output streaming.
 *
 * Requirements: 6.6
 *
 * @param  array<string, mixed> $cvData Decoded CV data from the database
 * @return \Dompdf\Dompdf       Rendered Dompdf instance ready for output
 */
function renderCVPdf(array $cvData): \Dompdf\Dompdf
{
    $html = renderCVHtml($cvData);

    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'serif');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf;
}

/**
 * Render CV data as a DOCX document using PHPWord.
 *
 * Maps each active CV section to a Word section, preserving structure
 * and content. Returns the PhpWord instance ready for saving/streaming.
 *
 * Requirements: 6.7
 *
 * @param  array<string, mixed>              $cvData Decoded CV data from the database
 * @return \PhpOffice\PhpWord\PhpWord        Configured PhpWord instance
 */
function renderCVDocx(array $cvData): \PhpOffice\PhpWord\PhpWord
{
    $phpWord = new \PhpOffice\PhpWord\PhpWord();

    // ---- Document-wide styles ----------------------------------------------
    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(11);

    $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 20], []);
    $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 13, 'allCaps' => true], [
        'borderBottomSize' => 6,
        'borderBottomColor' => '333333',
        'spaceAfter'  => 80,
    ]);

    $section = $phpWord->addSection([
        'marginTop'    => 720,
        'marginBottom' => 720,
        'marginLeft'   => 1080,
        'marginRight'  => 1080,
    ]);

    // ---- Contact -----------------------------------------------------------
    $contact = (array) ($cvData['contact'] ?? []);
    $name    = (string) ($contact['name']     ?? '');
    $phone   = (string) ($contact['phone']    ?? '');
    $email   = (string) ($contact['email']    ?? '');
    $linkedin = (string) ($contact['linkedin'] ?? '');
    $address = (string) ($contact['address']  ?? '');

    if ($name) {
        $section->addTitle($name, 1);
    }

    $metaParts = array_filter([$phone, $email, $linkedin, $address]);
    if ($metaParts) {
        $metaRun = $section->addTextRun(['spaceAfter' => 120]);
        $metaRun->addText(implode('  |  ', $metaParts), ['size' => 9, 'color' => '555555']);
    }

    $section->addTextBreak(1);

    // ---- Summary -----------------------------------------------------------
    $summary = (string) ($cvData['summary'] ?? '');
    if (trim($summary) !== '') {
        $section->addTitle('Professional Summary', 2);
        foreach (array_filter(array_map('trim', explode("\n", $summary))) as $line) {
            $section->addText($line, [], ['spaceAfter' => 60]);
        }
        $section->addTextBreak(1);
    }

    // ---- Work Experience ---------------------------------------------------
    $workEntries = (array) ($cvData['work_experience'] ?? []);
    if (!empty($workEntries)) {
        // Sort reverse chronologically (most recent first).
        usort($workEntries, function (array $a, array $b): int {
            return strcmp((string) ($b['start_date'] ?? ''), (string) ($a['start_date'] ?? ''));
        });

        $section->addTitle('Work Experience', 2);
        foreach ($workEntries as $entry) {
            $jobTitle = (string) ($entry['job_title']  ?? '');
            $company  = (string) ($entry['company']    ?? '');
            $start    = (string) ($entry['start_date'] ?? '');
            $end      = !empty($entry['present']) ? 'Present' : (string) ($entry['end_date'] ?? '');
            $resp     = (string) ($entry['responsibilities'] ?? '');

            $headerRun = $section->addTextRun(['spaceAfter' => 40]);
            $headerRun->addText($jobTitle, ['bold' => true]);
            if ($company) {
                $headerRun->addText('  —  ' . $company, ['color' => '555555']);
            }
            if ($start || $end) {
                $headerRun->addText('  (' . $start . ($end ? ' – ' . $end : '') . ')', ['size' => 9, 'color' => '777777']);
            }

            if ($resp) {
                $section->addText($resp, [], ['spaceAfter' => 80]);
            }
        }
        $section->addTextBreak(1);
    }

    // ---- Skills ------------------------------------------------------------
    $skills = $cvData['skills'] ?? [];
    if (!empty($skills)) {
        if (is_string($skills)) {
            $skillItems = array_filter(array_map('trim', explode(',', $skills)));
        } else {
            $skillItems = (array) $skills;
        }

        if (!empty($skillItems)) {
            $section->addTitle('Skills', 2);
            $section->addText(implode(', ', $skillItems), [], ['spaceAfter' => 80]);
            $section->addTextBreak(1);
        }
    }

    // ---- Education ---------------------------------------------------------
    $eduEntries = (array) ($cvData['education'] ?? []);
    if (!empty($eduEntries)) {
        $section->addTitle('Education', 2);
        foreach ($eduEntries as $entry) {
            $degree  = (string) ($entry['degree']          ?? '');
            $inst    = (string) ($entry['institution']     ?? '');
            $grad    = (string) ($entry['graduation_date'] ?? '');
            $honours = (string) ($entry['honours']         ?? '');

            $headerRun = $section->addTextRun(['spaceAfter' => 40]);
            $headerRun->addText($degree, ['bold' => true]);
            if ($inst) {
                $headerRun->addText('  —  ' . $inst, ['color' => '555555']);
            }
            if ($grad) {
                $headerRun->addText('  (' . $grad . ')', ['size' => 9, 'color' => '777777']);
            }
            if ($honours) {
                $section->addText($honours, ['italic' => true, 'color' => '555555'], ['spaceAfter' => 80]);
            }
        }
        $section->addTextBreak(1);
    }

    // ---- Optional sections -------------------------------------------------
    $optionalSections = (array) ($cvData['optional_sections'] ?? []);

    $optionalMap = [
        'projects'       => ['Projects / Portfolio',          fn(array $e) => [(string)($e['name'] ?? ''), (string)($e['description'] ?? '')]],
        'certifications' => ['Certifications & Licenses',     fn(array $e) => [(string)($e['name'] ?? ''), (string)($e['issuer'] ?? '') . ' ' . (string)($e['date'] ?? '')]],
        'awards'         => ['Awards & Honors',               fn(array $e) => [(string)($e['title'] ?? ''), (string)($e['description'] ?? '')]],
        'languages'      => ['Languages',                     fn(array $e) => [(string)($e['language'] ?? ''), (string)($e['proficiency'] ?? '')]],
        'publications'   => ['Publications & Research',       fn(array $e) => [(string)($e['title'] ?? ''), (string)($e['journal'] ?? '')]],
        'memberships'    => ['Professional Memberships',      fn(array $e) => [(string)($e['organization'] ?? ''), (string)($e['role'] ?? '')]],
        'references'     => ['References',                    fn(array $e) => [(string)($e['name'] ?? ''), (string)($e['title'] ?? '') . ', ' . (string)($e['company'] ?? '')]],
    ];

    foreach ($optionalMap as $key => [$heading, $rowFn]) {
        if (empty($optionalSections[$key]['active'])) {
            continue;
        }
        $entries = (array) ($optionalSections[$key]['entries'] ?? []);
        if (empty($entries)) {
            continue;
        }

        $section->addTitle($heading, 2);
        foreach ($entries as $entry) {
            [$primary, $secondary] = $rowFn((array) $entry);
            $run = $section->addTextRun(['spaceAfter' => 60]);
            if ($primary) {
                $run->addText($primary, ['bold' => true]);
            }
            if ($secondary) {
                $run->addText('  ' . trim($secondary), ['color' => '555555']);
            }
        }
        $section->addTextBreak(1);
    }

    return $phpWord;
}
