<?php

declare(strict_types=1);

$supportedLocales = ['it', 'en', 'de', 'fr'];
$defaultLocale = 'it';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$segments = array_values(array_filter(explode('/', trim($path, '/'))));
$firstSegment = strtolower($segments[0] ?? '');
$hasLocalePrefix = in_array($firstSegment, $supportedLocales, true);
$locale = $hasLocalePrefix && $firstSegment !== $defaultLocale ? $firstSegment : $defaultLocale;
$pageSegments = $hasLocalePrefix ? array_slice($segments, 1) : $segments;
$page = strtolower($pageSegments[0] ?? 'home');
$page = $page === '' ? 'home' : $page;
$supportedPages = ['home', 'privacy', 'filosofia', 'assistente-ia'];

if ($hasLocalePrefix && $firstSegment === $defaultLocale) {
    $redirectPage = strtolower($pageSegments[0] ?? 'home');
    $redirectPath = $redirectPage === 'home' || $redirectPage === '' ? '/' : "/{$redirectPage}/";
    header("Location: {$redirectPath}", true, 301);
    exit;
}

if (!in_array($page, $supportedPages, true) || count($pageSegments) > 1) {
    http_response_code(404);
    exit('Not found.');
}

$contentPath = __DIR__ . "/content/{$locale}/{$page}.md";

if (!is_file($contentPath)) {
    http_response_code(404);
    exit('Missing content file.');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function starts_with(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function contains_text(string $value, string $needle): bool
{
    return strpos($value, $needle) !== false;
}

function site_origin(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8081';
    $https = ($_SERVER['HTTPS'] ?? '') === 'on';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    $scheme = $forwardedProto ?: ($https ? 'https' : 'http');

    return "{$scheme}://{$host}";
}

function locale_path(string $locale): string
{
    return $locale === 'it' ? '/' : "/{$locale}/";
}

function page_path(string $locale, string $page): string
{
    if ($page === 'home') {
        return locale_path($locale);
    }

    return rtrim(locale_path($locale), '/') . "/{$page}/";
}

function nav_href(string $target, string $locale): string
{
    $target = trim($target);

    if ($target === '') {
        return page_path($locale, 'home');
    }

    if (starts_with($target, '#')) {
        return page_path($locale, 'home') . $target;
    }

    if (starts_with($target, 'http') || starts_with($target, 'mailto:')) {
        return $target;
    }

    if (starts_with($target, '/')) {
        return $locale === 'it' ? $target : rtrim(locale_path($locale), '/') . $target;
    }

    return page_path($locale, $target);
}

function absolute_url(string $path): string
{
    return site_origin() . $path;
}

function register_url(): string
{
    $url = trim((string) getenv('AURUM_REGISTER_URL'));

    if ($url !== '' && preg_match('#^https://#i', $url) === 1) {
        return $url;
    }

    return 'https://go.aurumvault.app/#/register';
}

function markdown_plain(string $text): string
{
    $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text) ?? $text;
    $text = preg_replace('/\*(.*?)\*/', '$1', $text) ?? $text;

    return trim($text);
}

function markdown_inline(string $text): string
{
    $text = e($text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text) ?? $text;
    return $text;
}

function parse_home_markdown(string $path): array
{
    $raw = trim((string) file_get_contents($path));
    $meta = [];

    if (starts_with($raw, "---\n")) {
        $end = strpos($raw, "\n---", 4);
        if ($end !== false) {
            $frontMatter = substr($raw, 4, $end - 4);
            $raw = trim(substr($raw, $end + 4));

            foreach (explode("\n", $frontMatter) as $line) {
                if (!contains_text($line, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }
    }

    $sections = [];
    $current = 'intro';
    $sections[$current] = ['title' => '', 'blocks' => []];
    $paragraph = [];
    $list = [];

    $flushParagraph = static function () use (&$sections, &$current, &$paragraph): void {
        if ($paragraph === []) {
            return;
        }
        $sections[$current]['blocks'][] = ['type' => 'paragraph', 'text' => trim(implode(' ', $paragraph))];
        $paragraph = [];
    };

    $flushList = static function () use (&$sections, &$current, &$list): void {
        if ($list === []) {
            return;
        }
        $sections[$current]['blocks'][] = ['type' => 'list', 'items' => $list];
        $list = [];
    };

    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $trimmed = trim($line);

        if (starts_with($trimmed, '## ')) {
            $flushParagraph();
            $flushList();
            $title = trim(substr($trimmed, 3));
            $current = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));
            $sections[$current] = ['title' => $title, 'blocks' => []];
            continue;
        }

        if (starts_with($trimmed, '# ')) {
            $meta['title'] = trim(substr($trimmed, 2));
            continue;
        }

        if (starts_with($trimmed, '- ')) {
            $flushParagraph();
            $list[] = trim(substr($trimmed, 2));
            continue;
        }

        if ($trimmed === '') {
            $flushParagraph();
            $flushList();
            continue;
        }

        $flushList();
        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    $flushList();

    return ['meta' => $meta, 'sections' => $sections];
}

function first_block_text(array $section, string $fallback = ''): string
{
    return paragraph_text($section, 0, $fallback);
}

function paragraph_text(array $section, int $index = 0, string $fallback = ''): string
{
    $current = 0;
    foreach ($section['blocks'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'paragraph' && $current++ === $index) {
            return $block['text'];
        }
    }

    return $fallback;
}

function list_items(array $section): array
{
    foreach ($section['blocks'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'list') {
            return $block['items'];
        }
    }

    return [];
}

$content = parse_home_markdown($contentPath);
$meta = $content['meta'];
$sections = $content['sections'];

$title = $meta['title'] ?? 'Aurum';
$tagline = $meta['tagline'] ?? 'The AI-powered pulse of your money.';
$primaryCta = $meta['primary_cta'] ?? 'Start';
$secondaryCta = $meta['secondary_cta'] ?? 'Demo';
$newsletterPlaceholder = $meta['newsletter_placeholder'] ?? 'you@example.com';
$newsletterCta = $meta['newsletter_cta'] ?? 'Subscribe';
$newsletterConsent = $meta['newsletter_consent'] ?? 'I agree to receive email updates from Aurum.';
$copyright = $meta['copyright'] ?? 'Aurum';
$registerUrl = register_url();

$nav = list_items($sections['navigation'] ?? []);
$hero = $sections['hero'] ?? ['title' => $title, 'blocks' => []];
$heroHeading = first_block_text($hero, $title);
$heroText = paragraph_text($hero, 1, $tagline);
$insight = first_block_text($sections['insight'] ?? [], '');
$security = $sections['security'] ?? ['title' => '', 'blocks' => []];
$securityHeading = first_block_text($security, 'Security');
$securityText = paragraph_text($security, 1, '');
$features = list_items($sections['features'] ?? []);
$featuresHeading = first_block_text($sections['features'] ?? [], 'Features');
$featuresText = paragraph_text($sections['features'] ?? [], 1, '');
$automation = $sections['automation'] ?? ['title' => '', 'blocks' => []];
$automationHeading = first_block_text($automation, 'Automation');
$automationText = paragraph_text($automation, 1, '');
$automationItems = list_items($automation);
$pricing = $sections['pricing'] ?? ['title' => '', 'blocks' => []];
$pricingHeading = first_block_text($pricing, 'Pricing');
$pricingText = paragraph_text($pricing, 1, '');
$pricingItems = list_items($pricing);
$newsletter = $sections['newsletter'] ?? ['title' => '', 'blocks' => []];
$newsletterHeading = first_block_text($newsletter, 'Newsletter');
$newsletterText = paragraph_text($newsletter, 1, '');
$footer = first_block_text($sections['footer'] ?? [], $tagline);
$canonicalPath = page_path($locale, $page);
$canonicalUrl = absolute_url($canonicalPath);
$seoTitle = markdown_plain($heroHeading) . ' | Aurum';
$seoDescription = markdown_plain($heroText);
$seoImage = absolute_url($page === 'privacy' ? '/assets/screenshots/crypted-data.png' : '/assets/screenshots/06-chart-balance.png');
$ogLocaleMap = [
    'it' => 'it_IT',
    'en' => 'en_US',
    'de' => 'de_DE',
    'fr' => 'fr_FR',
];
$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => absolute_url('/#organization'),
            'name' => 'Aurum',
            'url' => absolute_url('/'),
            'logo' => absolute_url('/assets/aurum-logo.png'),
        ],
        [
            '@type' => 'WebSite',
            '@id' => absolute_url('/#website'),
            'name' => 'Aurum',
            'url' => absolute_url('/'),
            'inLanguage' => $locale,
            'publisher' => [
                '@id' => absolute_url('/#organization'),
            ],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => absolute_url('/#software'),
            'name' => 'Aurum',
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Web',
            'url' => $canonicalUrl,
            'description' => $seoDescription,
            'image' => $seoImage,
            'publisher' => [
                '@id' => absolute_url('/#organization'),
            ],
            'offers' => [
                [
                    '@type' => 'Offer',
                    'name' => 'Standard monthly',
                    'price' => '1.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Standard annual',
                    'price' => '14.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Free monthly',
                    'price' => '2.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Free annual',
                    'price' => '23.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Full monthly',
                    'price' => '4.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
                [
                    '@type' => 'Offer',
                    'name' => 'Full annual',
                    'price' => '39.99',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
        ],
    ],
];

$localeNames = [
    'it' => 'Italiano',
    'en' => 'English',
    'de' => 'Deutsch',
    'fr' => 'Francais',
];
$assetVersion = (string) max(
    filemtime(__DIR__ . '/assets/site.css') ?: 0,
    filemtime(__DIR__ . '/assets/site.js') ?: 0
);
?>
<!doctype html>
<html lang="<?= e($locale) ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($seoTitle) ?></title>
    <meta name="description" content="<?= e($seoDescription) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="application-name" content="Aurum">
    <meta name="theme-color" content="#131313">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e(absolute_url(page_path('it', $page))) ?>">
    <?php foreach ($supportedLocales as $alternateLocale): ?>
        <link rel="alternate" hreflang="<?= e($alternateLocale) ?>" href="<?= e(absolute_url(page_path($alternateLocale, $page))) ?>">
    <?php endforeach; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Aurum">
    <meta property="og:locale" content="<?= e($ogLocaleMap[$locale] ?? $locale) ?>">
    <meta property="og:title" content="<?= e($seoTitle) ?>">
    <meta property="og:description" content="<?= e($seoDescription) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta property="og:image" content="<?= e($seoImage) ?>">
    <meta property="og:image:alt" content="Aurum financial dashboard charts">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seoTitle) ?>">
    <meta name="twitter:description" content="<?= e($seoDescription) ?>">
    <meta name="twitter:image" content="<?= e($seoImage) ?>">
    <script type="application/ld+json">
        <?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/site.css?v=<?= e($assetVersion) ?>">
    <script src="/assets/site.js?v=<?= e($assetVersion) ?>" defer></script>
</head>

<body>
    <header class="site-header" data-header>
        <a class="brand" href="<?= e(locale_path($locale)) ?>" aria-label="Aurum">
            <img class="brand__logo" src="/assets/aurum-logo.svg" alt="" aria-hidden="true">
            <span>Aurum</span>
        </a>

        <button class="icon-button nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="main-nav">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
            <span class="sr-only">Menu</span>
        </button>

        <nav class="main-nav" id="main-nav" data-nav>
            <?php foreach ($nav as $item): ?>
                <?php [$label, $target] = array_pad(explode('|', $item, 2), 2, '#'); ?>
                <a href="<?= e(nav_href($target, $locale)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="language-picker" aria-label="Language">
            <span class="material-symbols-outlined" aria-hidden="true">language</span>
            <select data-language-select>
                <?php foreach ($supportedLocales as $code): ?>
                    <option value="<?= e(page_path($code, $page)) ?>" <?= $code === $locale ? 'selected' : '' ?>>
                        <?= e($localeNames[$code]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </header>

    <main>
        <?php if ($page === 'home'): ?>
            <section class="hero">
                <div class="hero__copy">
                    <p class="eyebrow"><?= e($tagline) ?></p>
                    <h1><?= markdown_inline($heroHeading) ?></h1>
                    <p class="hero__lead"><?= markdown_inline($heroText) ?></p>
                    <div class="hero__actions">
                        <a class="button button--primary" href="#pricing"><?= e($primaryCta) ?></a>
                        <a class="button button--secondary" href="#features"><?= e($secondaryCta) ?></a>
                    </div>
                </div>

                <div class="product-stage" data-slideshow aria-label="Aurum dashboard chart preview">
                    <img class="product-stage__slide is-active" src="/assets/aurum-dashboard.png" alt="Aurum balance chart dashboard">
                    <img class="product-stage__slide" src="/assets/screenshots/07-chart-spending.png" alt="Aurum spending chart dashboard">
                    <img class="product-stage__slide" src="/assets/screenshots/08-chart-monthly-flow.png" alt="Aurum monthly flow chart dashboard">
                    <div class="slide-dots" aria-hidden="true">
                        <span class="is-active"></span>
                        <span></span>
                        <span></span>
                    </div>
                    <aside class="insight-card">
                        <span class="pulse" aria-hidden="true"></span>
                        <span class="insight-card__label">AI Insight</span>
                        <p><?= markdown_inline($insight) ?></p>
                    </aside>
                </div>
            </section>

            <section class="section section--muted" id="security">
                <div class="split">
                    <div class="key-diagram" aria-label="Encryption flow">
                        <div class="key-step">
                            <span class="material-symbols-outlined" aria-hidden="true">password</span>
                            <strong>Password</strong>
                        </div>
                        <span class="key-line"></span>
                        <div class="key-core">
                            <span class="material-symbols-outlined" aria-hidden="true">vpn_key</span>
                        </div>
                        <span class="key-line key-line--rose"></span>
                        <div class="key-step">
                            <span class="material-symbols-outlined" aria-hidden="true">encrypted</span>
                            <strong>Master key</strong>
                        </div>
                    </div>

                    <div class="section-copy">
                        <p class="eyebrow">Security</p>
                        <h2><?= markdown_inline($securityHeading) ?></h2>
                        <p><?= markdown_inline($securityText) ?></p>
                        <ul class="check-list">
                            <?php foreach (array_slice(list_items($security), 0, 3) as $item): ?>
                                <li>
                                    <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
                                    <?= markdown_inline($item) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="section" id="features">
                <div class="section-heading">
                    <p class="eyebrow">Aurum</p>
                    <h2><?= markdown_inline($featuresHeading) ?></h2>
                    <p><?= markdown_inline($featuresText) ?></p>
                </div>

                <div class="feature-grid">
                    <?php
                    $icons = ['chat_bubble', 'send', 'query_stats'];
                    foreach (array_slice($features, 0, 3) as $index => $feature):
                        [$featureTitle, $featureText] = array_pad(explode('|', $feature, 2), 2, '');
                    ?>
                        <article class="feature-card <?= $index === 1 ? 'feature-card--featured' : '' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($icons[$index] ?? 'star') ?></span>
                            <h3><?= markdown_inline($featureTitle) ?></h3>
                            <p><?= markdown_inline($featureText) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="wide-shot">
                    <img src="/assets/aurum-transactions.png" alt="Aurum transactions list">
                </div>
            </section>

            <section class="section section--muted" id="automation">
                <div class="split split--reverse">
                    <div class="section-copy">
                        <p class="eyebrow">Automation</p>
                        <h2><?= markdown_inline($automationHeading) ?></h2>
                        <p><?= markdown_inline($automationText) ?></p>
                        <div class="stacked-list">
                            <?php foreach (array_slice($automationItems, 0, 2) as $item): ?>
                                <?php [$itemTitle, $itemText] = array_pad(explode('|', $item, 2), 2, ''); ?>
                                <article>
                                    <span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>
                                    <div>
                                        <h3><?= markdown_inline($itemTitle) ?></h3>
                                        <p><?= markdown_inline($itemText) ?></p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="activity-panel">
                        <div class="activity-panel__top">
                            <span>Recent activity</span>
                            <strong>Live sync</strong>
                        </div>
                        <div class="transaction transaction--out">
                            <span class="material-symbols-outlined" aria-hidden="true">local_cafe</span>
                            <div>
                                <strong>Bar Centrale</strong>
                                <small>Ristoranti</small>
                            </div>
                            <b>-4,80 EUR</b>
                        </div>
                        <div class="transaction transaction--out">
                            <span class="material-symbols-outlined" aria-hidden="true">cloud</span>
                            <div>
                                <strong>Cloud invoice</strong>
                                <small>Servizi</small>
                            </div>
                            <b>-142,20 EUR</b>
                        </div>
                        <div class="transaction transaction--in">
                            <span class="material-symbols-outlined" aria-hidden="true">account_balance_wallet</span>
                            <div>
                                <strong>Stipendio</strong>
                                <small>Entrate</small>
                            </div>
                            <b>+2.450,00 EUR</b>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" id="pricing">
                <div class="section-heading">
                    <p class="eyebrow">Pricing</p>
                    <h2><?= markdown_inline($pricingHeading) ?></h2>
                    <p><?= markdown_inline($pricingText) ?></p>
                </div>

                <div class="pricing-grid">
                    <?php foreach (array_slice($pricingItems, 0, 3) as $index => $item): ?>
                        <?php [$plan, $price, $annualPrice, $planText] = array_pad(explode('|', $item, 4), 4, ''); ?>
                        <article class="price-card <?= $index === 2 ? 'price-card--featured' : '' ?>">
                            <?php if ($index === 2): ?><span class="badge">Full</span><?php endif; ?>
                            <h3><?= markdown_inline($plan) ?></h3>
                            <p class="price">
                                <span><?= markdown_inline($price) ?></span>
                                <small><?= markdown_inline($annualPrice) ?></small>
                            </p>
                            <p><?= markdown_inline($planText) ?></p>
                            <a class="button <?= $index === 2 ? 'button--primary' : 'button--secondary' ?>" href="<?= e($registerUrl) ?>"><?= e($index === 2 ? $primaryCta : $secondaryCta) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="newsletter" id="newsletter">
                <div>
                    <h2><?= markdown_inline($newsletterHeading) ?></h2>
                    <p><?= markdown_inline($newsletterText) ?></p>
                </div>
                <form class="newsletter-form" data-newsletter>
                    <input type="hidden" name="locale" value="<?= e($locale) ?>">
                    <input class="newsletter-form__trap" type="text" name="company" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <input type="email" name="email" placeholder="<?= e($newsletterPlaceholder) ?>" required>
                    <label class="newsletter-consent">
                        <input type="checkbox" name="consent" value="1" required>
                        <span><?= e($newsletterConsent) ?></span>
                    </label>
                    <button class="button button--primary" type="submit"><?= e($newsletterCta) ?></button>
                    <p class="form-message" data-form-message role="status"></p>
                </form>
            </section>
        <?php elseif ($page === 'privacy'): ?>
            <section class="hero">
                <div class="hero__copy">
                    <p class="eyebrow"><?= e($tagline) ?></p>
                    <h1><?= markdown_inline($heroHeading) ?></h1>
                    <p class="hero__lead"><?= markdown_inline($heroText) ?></p>
                </div>

                <div class="key-diagram" aria-label="Aurum privacy model">
                    <div class="key-step">
                        <span class="material-symbols-outlined" aria-hidden="true">password</span>
                        <strong>Password</strong>
                    </div>
                    <span class="key-line"></span>
                    <div class="key-core">
                        <span class="material-symbols-outlined" aria-hidden="true">vpn_key</span>
                    </div>
                    <span class="key-line key-line--rose"></span>
                    <div class="key-step">
                        <span class="material-symbols-outlined" aria-hidden="true">encrypted</span>
                        <strong>Master key</strong>
                    </div>
                </div>
            </section>

            <?php foreach (array_slice(list_items($sections['privacy-highlights'] ?? []), 0, 2) as $index => $item): ?>
                <?php [$cardTitle, $cardText, $cardImage, $cardAlt] = array_pad(explode('|', $item, 4), 4, 'Aurum privacy screen'); ?>
                <section class="section <?= $index === 0 ? 'section--muted' : '' ?>">
                    <div class="split <?= $index === 1 ? 'split--reverse' : '' ?>">
                        <div class="product-stage product-stage--static">
                            <img class="product-stage__slide is-active" src="<?= e($cardImage) ?>" alt="<?= e($cardAlt) ?>">
                        </div>
                        <div class="section-copy">
                            <p class="eyebrow">Privacy</p>
                            <h2><?= markdown_inline($cardTitle) ?></h2>
                            <p><?= markdown_inline($cardText) ?></p>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="section section--muted">
                <div class="feature-grid">
                    <?php
                    $privacyIcons = ['key', 'database', 'psychology', 'lock'];
                    foreach (list_items($sections['privacy-details'] ?? []) as $index => $item):
                    ?>
                        <?php [$cardTitle, $cardText, $cardImage, $cardAlt] = array_pad(explode('|', $item, 4), 4, 'Aurum privacy screen'); ?>
                        <article class="feature-card <?= $index === 1 ? 'feature-card--featured' : '' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($privacyIcons[$index] ?? 'verified_user') ?></span>
                            <h3><?= markdown_inline($cardTitle) ?></h3>
                            <p><?= markdown_inline($cardText) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php elseif ($page === 'assistente-ia'): ?>
            <?php
            $chatPreview = list_items($sections['chat-preview'] ?? []);
            $aiModes = list_items($sections['ai-modes'] ?? []);
            ?>
            <section class="hero">
                <div class="hero__copy">
                    <p class="eyebrow"><?= e($tagline) ?></p>
                    <h1><?= markdown_inline($heroHeading) ?></h1>
                    <p class="hero__lead"><?= markdown_inline($heroText) ?></p>
                </div>

                <div class="assistant-phone" aria-label="Aurum AI assistant mobile chat preview">
                    <div class="assistant-phone__top">
                        <span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>
                        <strong>Aurum IA</strong>
                    </div>
                    <div class="assistant-chat">
                        <?php foreach (array_slice($chatPreview, 0, 4) as $item): ?>
                            <?php [$messageRole, $messageText] = array_pad(explode('|', $item, 2), 2, ''); ?>
                            <p class="assistant-message assistant-message--<?= e($messageRole === 'user' ? 'user' : 'assistant') ?>">
                                <?= markdown_inline($messageText) ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="section section--muted">
                <div class="feature-grid">
                    <?php foreach (array_slice($aiModes, 0, 3) as $index => $item): ?>
                        <?php [$cardTitle, $cardText, $cardIcon] = array_pad(explode('|', $item, 3), 3, 'settings'); ?>
                        <article class="feature-card <?= $index === 1 ? 'feature-card--featured' : '' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($cardIcon) ?></span>
                            <h3><?= markdown_inline($cardTitle) ?></h3>
                            <p><?= markdown_inline($cardText) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php foreach (array_slice(list_items($sections['assistant-highlights'] ?? []), 0, 2) as $index => $item): ?>
                <?php [$cardTitle, $cardText, $cardImage, $cardAlt] = array_pad(explode('|', $item, 4), 4, 'Aurum assistant screen'); ?>
                <section class="section <?= $index === 0 ? '' : 'section--muted' ?>">
                    <div class="split <?= $index === 1 ? 'split--reverse' : '' ?>">
                        <div class="product-stage product-stage--static">
                            <img class="product-stage__slide is-active" src="<?= e($cardImage) ?>" alt="<?= e($cardAlt) ?>">
                        </div>
                        <div class="section-copy">
                            <p class="eyebrow"><?= e($tagline) ?></p>
                            <h2><?= markdown_inline($cardTitle) ?></h2>
                            <p><?= markdown_inline($cardText) ?></p>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="section">
                <div class="feature-grid">
                    <?php
                    $assistantIcons = ['chat_bubble', 'receipt_long', 'smartphone', 'lock'];
                    foreach (list_items($sections['assistant-details'] ?? []) as $index => $item):
                    ?>
                        <?php [$cardTitle, $cardText] = array_pad(explode('|', $item, 2), 2, ''); ?>
                        <article class="feature-card <?= $index === 2 ? 'feature-card--featured' : '' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($assistantIcons[$index] ?? 'auto_awesome') ?></span>
                            <h3><?= markdown_inline($cardTitle) ?></h3>
                            <p><?= markdown_inline($cardText) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <?php
            $snapshot = $sections['method-snapshot'] ?? ['title' => '', 'blocks' => []];
            $snapshotHeading = first_block_text($snapshot, 'Budget method');
            $snapshotItems = list_items($snapshot);
            ?>
            <section class="hero">
                <div class="hero__copy">
                    <p class="eyebrow"><?= e($tagline) ?></p>
                    <h1><?= markdown_inline($heroHeading) ?></h1>
                    <p class="hero__lead"><?= markdown_inline($heroText) ?></p>
                </div>

                <div class="activity-panel philosophy-panel">
                    <div class="activity-panel__top">
                        <span><?= markdown_inline($snapshotHeading) ?></span>
                        <strong>Aurum</strong>
                    </div>
                    <?php
                    $methodIcons = ['account_balance_wallet', 'savings', 'sync_alt', 'auto_awesome'];
                    foreach (array_slice($snapshotItems, 0, 4) as $index => $item):
                        [$itemTitle, $itemText, $itemValue] = array_pad(explode('|', $item, 3), 3, '');
                    ?>
                        <div class="transaction <?= $index === 1 ? 'transaction--in' : 'transaction--out' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($methodIcons[$index] ?? 'check_circle') ?></span>
                            <div>
                                <strong><?= markdown_inline($itemTitle) ?></strong>
                                <small><?= markdown_inline($itemText) ?></small>
                            </div>
                            <?php if ($itemValue !== ''): ?><b><?= markdown_inline($itemValue) ?></b><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php foreach (array_slice(list_items($sections['philosophy-highlights'] ?? []), 0, 2) as $index => $item): ?>
                <?php [$cardTitle, $cardText, $cardImage, $cardAlt] = array_pad(explode('|', $item, 4), 4, 'Aurum screen'); ?>
                <section class="section <?= $index === 0 ? 'section--muted' : '' ?>">
                    <div class="split <?= $index === 1 ? 'split--reverse' : '' ?>">
                        <div class="product-stage product-stage--static">
                            <img class="product-stage__slide is-active" src="<?= e($cardImage) ?>" alt="<?= e($cardAlt) ?>">
                        </div>
                        <div class="section-copy">
                            <p class="eyebrow"><?= e($tagline) ?></p>
                            <h2><?= markdown_inline($cardTitle) ?></h2>
                            <p><?= markdown_inline($cardText) ?></p>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="section section--muted">
                <div class="feature-grid">
                    <?php
                    $philosophyIcons = ['payments', 'savings', 'published_with_changes', 'psychology'];
                    foreach (list_items($sections['philosophy-details'] ?? []) as $index => $item):
                    ?>
                        <?php [$cardTitle, $cardText] = array_pad(explode('|', $item, 2), 2, ''); ?>
                        <article class="feature-card <?= $index === 1 ? 'feature-card--featured' : '' ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?= e($philosophyIcons[$index] ?? 'check_circle') ?></span>
                            <h3><?= markdown_inline($cardTitle) ?></h3>
                            <p><?= markdown_inline($cardText) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <a class="brand" href="<?= e(locale_path($locale)) ?>">
            <img class="brand__logo" src="/assets/aurum-logo.svg" alt="" aria-hidden="true">
            <span>Aurum</span>
        </a>
        <p><?= markdown_inline($footer) ?></p>
        <small><?= e($copyright) ?></small>
    </footer>
</body>

</html>
