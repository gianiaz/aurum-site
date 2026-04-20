<?php
declare(strict_types=1);

$supportedLocales = ['it', 'en', 'de', 'fr'];
$supportedPages = ['home', 'privacy', 'filosofia', 'assistente-ia'];

function sitemap_origin(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8081';
    $https = ($_SERVER['HTTPS'] ?? '') === 'on';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    $scheme = $forwardedProto ?: ($https ? 'https' : 'http');

    return "{$scheme}://{$host}";
}

function sitemap_locale_path(string $locale): string
{
    return $locale === 'it' ? '/' : "/{$locale}/";
}

function sitemap_page_path(string $locale, string $page): string
{
    if ($page === 'home') {
        return sitemap_locale_path($locale);
    }

    return rtrim(sitemap_locale_path($locale), '/') . "/{$page}/";
}

header('Content-Type: application/xml; charset=UTF-8');

$updatedAt = gmdate('Y-m-d');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php foreach ($supportedPages as $page): ?>
<?php foreach ($supportedLocales as $locale): ?>
    <url>
        <loc><?= htmlspecialchars(sitemap_origin() . sitemap_page_path($locale, $page), ENT_XML1, 'UTF-8') ?></loc>
        <lastmod><?= $updatedAt ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority><?= $page === 'home' && $locale === 'it' ? '1.0' : '0.8' ?></priority>
        <xhtml:link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars(sitemap_origin() . sitemap_page_path('it', $page), ENT_XML1, 'UTF-8') ?>" />
        <?php foreach ($supportedLocales as $alternateLocale): ?>
        <xhtml:link rel="alternate" hreflang="<?= htmlspecialchars($alternateLocale, ENT_XML1, 'UTF-8') ?>" href="<?= htmlspecialchars(sitemap_origin() . sitemap_page_path($alternateLocale, $page), ENT_XML1, 'UTF-8') ?>" />
        <?php endforeach; ?>
    </url>
<?php endforeach; ?>
<?php endforeach; ?>
</urlset>
