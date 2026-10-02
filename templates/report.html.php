<?php
/**
 * ChatLens HTML report template.
 *
 * Available variables:
 * @var \ChatLens\Analysis\AnalysisResult $result
 * @var callable(string, array=): string   $t      translator
 * @var callable(mixed): string           $e      HTML escaper
 * @var string                            $lang
 * @var array<string, list<\ChatLens\Analysis\Classification>> $groups
 * @var array<string, int>                $counts
 * @var int                               $total
 */

$profile = $result->profile;
$fallback = $profile->fallback;

$renderUser = static function (\ChatLens\Analysis\Classification $c) use ($e, $t): string {
    $search = mb_strtolower($c->name . ' ' . $c->userId . ' ' . $c->reason . ' '
        . implode(' ', array_map(static fn ($q) => $q->text, $c->quotes)));

    $html = '<article class="user" data-search="' . $e($search) . '">';
    $html .= '<header><span class="name">' . $e($c->name) . '</span>';
    $html .= '<span class="uid">' . $e($c->userId) . '</span>';
    if ($c->categoryCorrected) {
        $html .= '<span class="flag" title="' . $e($t('report.corrected')) . '">!</span>';
    }
    $html .= '</header>';

    if ($c->reason !== '') {
        $html .= '<p class="reason">' . $e($c->reason) . '</p>';
    }
    foreach ($c->quotes as $q) {
        $html .= $q->verified
            ? '<blockquote>' . $e($q->text) . '</blockquote>'
            : '<blockquote class="unverified" title="' . $e($t('report.unverified_quote')) . '">' . $e($q->text) . '</blockquote>';
    }
    $html .= '<p class="count">' . $e($t('report.messages_count', ['analyzed' => $c->analyzedMessages, 'total' => $c->totalMessages])) . '</p>';

    return $html . '</article>';
};

$pct = static fn (int $n): string => $total > 0 ? (string) round($n * 100 / $total) : '0';
$meta = $result->meta;
?>
<!doctype html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($profile->title($lang)) ?> — <?= $e($result->chatName) ?></title>
<style>
  :root {
    --bg: #f6f5f2; --panel: #ffffff; --ink: #1c1b19; --muted: #6b6860; --line: #e7e4dd;
    --quote-bg: #f6f5f2; --warn: #b54708;
    --radius: 14px;
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #151513; --panel: #1f1e1c; --ink: #ecebe7; --muted: #9c998f; --line: #2f2e2a; --quote-bg: #272623; --warn: #f2a24a; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); line-height: 1.5; }
  .wrap { max-width: 1280px; margin: 0 auto; padding: 40px 20px 64px; }

  .top .eyebrow { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 6px; }
  .top h1 { font-size: clamp(26px, 4vw, 38px); line-height: 1.15; margin: 0 0 10px; letter-spacing: -.01em; }
  .top .meta { display: flex; flex-wrap: wrap; gap: 6px 18px; color: var(--muted); font-size: 14px; margin: 0; padding: 0; list-style: none; }
  .top .meta b { color: var(--ink); font-weight: 600; }

  .summary { margin: 28px 0 20px; }
  .bar { display: flex; height: 12px; border-radius: 99px; overflow: hidden; background: var(--line); }
  .bar span { background: var(--c); min-width: 3px; }
  .legend { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0 0; padding: 0; list-style: none; }
  .legend a { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 99px; border: 1px solid var(--line);
              background: var(--panel); color: var(--ink); text-decoration: none; font-size: 14px; }
  .legend a:hover { border-color: var(--c); }
  .legend i { width: 10px; height: 10px; border-radius: 50%; background: var(--c); }
  .legend small { color: var(--muted); }

  .search { width: 100%; max-width: 420px; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--line);
            background: var(--panel); color: var(--ink); font: inherit; margin: 0 0 22px; }
  .search:focus { outline: 2px solid color-mix(in srgb, var(--ink) 25%, transparent); outline-offset: 1px; }

  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 18px; align-items: start; }
  .cat { background: var(--panel); border-radius: var(--radius); border: 1px solid var(--line); border-top: 4px solid var(--c); padding: 18px 20px 6px; }
  .cat h2, .cat summary { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin: 0 0 6px; font-size: 18px; font-weight: 650; }
  .cat h2 .n, .cat summary .n { font-size: 14px; font-weight: 500; color: var(--muted); }
  .cat .desc { color: var(--muted); font-size: 13px; margin: 0 0 8px; }
  .cat.fallback { margin-top: 18px; }
  .cat details summary { cursor: pointer; list-style: none; justify-content: flex-start; }
  .cat details summary::-webkit-details-marker { display: none; }
  .cat details summary .n { margin-left: auto; }
  .cat details summary::after { content: "▾"; color: var(--muted); font-size: 14px; }
  .cat details[open] summary::after { content: "▴"; }
  .cat .list.wide { columns: 340px; column-gap: 28px; }
  .cat .list.wide .user { break-inside: avoid; }

  .user { padding: 14px 0; border-top: 1px solid var(--line); }
  .user header { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
  .user .name { font-weight: 600; }
  .user .uid { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .user .flag { font-size: 11px; font-weight: 700; color: var(--warn); border: 1px solid currentColor; border-radius: 99px; padding: 0 6px; cursor: help; }
  .user .reason { margin: 0 0 6px; font-size: 14.5px; }
  .user blockquote { margin: 6px 0; padding: 6px 10px; background: var(--quote-bg); border-left: 3px solid var(--c); border-radius: 0 8px 8px 0; font-size: 13.5px; color: var(--ink); }
  .user blockquote::before { content: "«"; } .user blockquote::after { content: "»"; }
  .user blockquote.unverified { border-left-style: dashed; border-left-color: var(--warn); color: var(--muted); cursor: help; }
  .user blockquote.unverified::after { content: "» ⚠"; color: var(--warn); }
  .user .count { margin: 4px 0 0; font-size: 12px; color: var(--muted); }
  .empty { color: var(--muted); font-style: italic; padding: 10px 0 14px; margin: 0; }
  .hidden { display: none !important; }

  .failed { margin-top: 18px; --c: var(--warn); }
  .failed ul { margin: 0 0 14px; padding-left: 18px; columns: 260px; }
  .failed li { font-size: 14px; }

  footer { margin-top: 36px; color: var(--muted); font-size: 13px; display: grid; gap: 4px; }
  footer p { margin: 0; }
</style>
</head>
<body>
<div class="wrap">

  <header class="top">
    <p class="eyebrow">ChatLens · <?= $e($profile->id) ?></p>
    <h1><?= $e($profile->title($lang)) ?></h1>
    <ul class="meta">
      <li><?= $e($t('report.chat')) ?>: <b><?= $e($result->chatName) ?></b></li>
      <li><?= $e($t('report.participants', ['n' => $total])) ?></li>
      <?php if (isset($meta['scanned_messages'])): ?>
        <li><?= $e($t('report.messages_scanned', ['n' => number_format((int) $meta['scanned_messages'], 0, '.', ' ')])) ?></li>
      <?php endif; ?>
      <?php if (isset($meta['scope'])): ?>
        <li><?= $e($t('report.scope')) ?>: <?= $e($meta['scope']) ?></li>
      <?php endif; ?>
      <li><?= $e($t('report.model')) ?>: <?= $e($result->provider) ?> / <?= $e($result->model) ?></li>
      <li><?= $e($t('report.generated_at', ['date' => $result->generatedAt->format('Y-m-d H:i')])) ?></li>
    </ul>
  </header>

  <?php if ($total === 0): ?>
    <p class="empty"><?= $e($t('report.no_results')) ?></p>
  <?php else: ?>

  <section class="summary">
    <div class="bar" role="img" aria-label="<?= $e(implode(', ', array_map(
        static fn ($k) => $profile->category($k)->label($lang) . ': ' . $counts[$k],
        array_keys($counts)
    ))) ?>">
      <?php foreach ($counts as $key => $n): if ($n === 0) continue; ?>
        <span style="--c: <?= $e($profile->category($key)->color) ?>; width: <?= $e($n * 100 / $total) ?>%"></span>
      <?php endforeach; ?>
    </div>
    <ul class="legend">
      <?php foreach ($counts as $key => $n): $cat = $profile->category($key); ?>
        <li><a href="#cat-<?= $e($key) ?>" style="--c: <?= $e($cat->color) ?>">
          <i></i><?= $e(trim($cat->icon . ' ' . $cat->label($lang))) ?> <b><?= $n ?></b> <small><?= $pct($n) ?>%</small>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <input class="search" type="search" placeholder="<?= $e($t('report.search')) ?>" aria-label="<?= $e($t('report.search')) ?>">

  <main class="grid">
    <?php foreach ($groups as $key => $users): if ($key === $fallback) continue; $cat = $profile->category($key); ?>
      <section class="cat" id="cat-<?= $e($key) ?>" style="--c: <?= $e($cat->color) ?>">
        <h2><span><?= $e(trim($cat->icon . ' ' . $cat->label($lang))) ?></span><span class="n"><?= count($users) ?></span></h2>
        <p class="desc"><?= $e($cat->description) ?></p>
        <div class="list">
          <?php foreach ($users as $c) echo $renderUser($c); ?>
          <p class="empty <?= $users ? 'hidden' : '' ?>" data-empty><?= $e($users ? $t('report.nothing_found') : $t('report.empty')) ?></p>
        </div>
      </section>
    <?php endforeach; ?>
  </main>

  <?php $fbUsers = $groups[$fallback] ?? []; $fbCat = $profile->category($fallback); ?>
  <?php if ($fbUsers): ?>
    <section class="cat fallback" id="cat-<?= $e($fallback) ?>" style="--c: <?= $e($fbCat->color) ?>">
      <details <?= count($fbUsers) <= 12 ? 'open' : '' ?>>
        <summary><span><?= $e(trim($fbCat->icon . ' ' . $fbCat->label($lang))) ?></span><span class="n"><?= count($fbUsers) ?></span></summary>
        <p class="desc"><?= $e($fbCat->description) ?></p>
        <div class="list wide">
          <?php foreach ($fbUsers as $c) echo $renderUser($c); ?>
          <p class="empty hidden" data-empty><?= $e($t('report.nothing_found')) ?></p>
        </div>
      </details>
    </section>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($result->failed): ?>
    <section class="cat failed">
      <h2><span><?= $e($t('report.failed_title')) ?></span><span class="n"><?= count($result->failed) ?></span></h2>
      <p class="desc"><?= $e($t('report.failed_note')) ?></p>
      <ul>
        <?php foreach ($result->failed as $u): ?>
          <li><?= $e($u->name) ?> <span class="uid"><?= $e($u->id) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <footer>
    <p><?= $e($t('report.disclaimer')) ?></p>
    <p><?= $e($t('report.export_note')) ?></p>
  </footer>
</div>

<script>
(() => {
  const input = document.querySelector('.search');
  if (!input) return;
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    document.querySelectorAll('.cat .list').forEach(list => {
      let visible = 0;
      list.querySelectorAll('.user').forEach(card => {
        const show = !q || card.dataset.search.includes(q);
        card.classList.toggle('hidden', !show);
        if (show) visible++;
      });
      const empty = list.querySelector('[data-empty]');
      if (empty && list.querySelector('.user')) empty.classList.toggle('hidden', visible > 0);
      if (q && visible) list.closest('details')?.setAttribute('open', '');
    });
  });
})();
</script>
</body>
</html>
