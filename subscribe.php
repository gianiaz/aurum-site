<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function localized_message(string $locale, string $key): string
{
    $messages = [
        'it' => [
            'success' => 'Grazie, controlla la tua email: sei nella lista Aurum.',
            'invalid' => 'Inserisci un indirizzo email valido.',
            'config' => 'Newsletter non configurata. Imposta BREVO_API_KEY e BREVO_LIST_ID.',
            'consent' => 'Per iscriverti devi autorizzare l’invio di email da Aurum.',
            'error' => 'Non sono riuscito a completare l’iscrizione. Riprova tra poco.',
            'method' => 'Metodo non supportato.',
        ],
        'en' => [
            'success' => 'Thanks, check your inbox: you are on the Aurum list.',
            'invalid' => 'Please enter a valid email address.',
            'config' => 'Newsletter is not configured. Set BREVO_API_KEY and BREVO_LIST_ID.',
            'consent' => 'To subscribe, you need to allow Aurum to send you emails.',
            'error' => 'I could not complete the subscription. Please try again soon.',
            'method' => 'Method not supported.',
        ],
        'de' => [
            'success' => 'Danke, prüfe dein Postfach: du bist auf der Aurum-Liste.',
            'invalid' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
            'config' => 'Newsletter ist nicht konfiguriert. Setze BREVO_API_KEY und BREVO_LIST_ID.',
            'consent' => 'Für die Anmeldung musst du E-Mails von Aurum erlauben.',
            'error' => 'Die Anmeldung konnte nicht abgeschlossen werden. Bitte versuche es später erneut.',
            'method' => 'Methode nicht unterstützt.',
        ],
        'fr' => [
            'success' => 'Merci, vérifiez votre boîte mail: vous êtes dans la liste Aurum.',
            'invalid' => 'Veuillez saisir une adresse email valide.',
            'config' => 'Newsletter non configurée. Définissez BREVO_API_KEY et BREVO_LIST_ID.',
            'consent' => 'Pour vous inscrire, vous devez autoriser Aurum à vous envoyer des emails.',
            'error' => 'Impossible de finaliser l’inscription. Réessayez dans un instant.',
            'method' => 'Méthode non prise en charge.',
        ],
    ];

    return $messages[$locale][$key] ?? $messages['en'][$key];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, [
        'ok' => false,
        'message' => localized_message('en', 'method'),
    ]);
}

$locale = strtolower((string) ($_POST['locale'] ?? 'it'));
if (!in_array($locale, ['it', 'en', 'de', 'fr'], true)) {
    $locale = 'it';
}

$email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if ($email === false) {
    json_response(422, [
        'ok' => false,
        'message' => localized_message($locale, 'invalid'),
    ]);
}

// Honeypot field: humans never fill it, bots often do.
if (trim((string) ($_POST['company'] ?? '')) !== '') {
    json_response(200, [
        'ok' => true,
        'message' => localized_message($locale, 'success'),
    ]);
}

if ((string) ($_POST['consent'] ?? '') !== '1') {
    json_response(422, [
        'ok' => false,
        'message' => localized_message($locale, 'consent'),
    ]);
}

$apiKey = trim((string) getenv('BREVO_API_KEY'));
$listId = filter_var(getenv('BREVO_LIST_ID'), FILTER_VALIDATE_INT);

if ($apiKey === '' || $listId === false) {
    json_response(500, [
        'ok' => false,
        'message' => localized_message($locale, 'config'),
    ]);
}

$payload = json_encode([
    'email' => $email,
    'listIds' => [(int) $listId],
    'updateEnabled' => true,
    'attributes' => [
        'SOURCE' => 'aurum-site',
        'LANGUAGE' => strtoupper($locale),
        'CONSENT_SOURCE' => 'newsletter_form',
    ],
], JSON_UNESCAPED_SLASHES);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Accept: application/json',
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ],
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 8,
    ],
]);

$result = @file_get_contents('https://api.brevo.com/v3/contacts', false, $context);
$statusLine = $http_response_header[0] ?? '';
$isSuccess = preg_match('/\s(200|201|204)\s/', $statusLine) === 1;

if (!$isSuccess) {
    error_log('Brevo subscribe failed: ' . $statusLine . ' ' . (string) $result);
    json_response(502, [
        'ok' => false,
        'message' => localized_message($locale, 'error'),
    ]);
}

json_response(200, [
    'ok' => true,
    'message' => localized_message($locale, 'success'),
]);
