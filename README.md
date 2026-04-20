# Aurum Site

Landing page multilingua per Aurum, costruita con nginx, PHP 8.4-FPM, CSS e JavaScript senza dipendenze applicative.

## Avvio locale con Docker

```bash
make build
make up
```

Poi apri:

```text
http://127.0.0.1:8081
```

Puoi cambiare porta così:

```bash
make up APP_PORT=8090
```

Comandi utili:

```bash
make help
make logs
make lint
make down
```

## Newsletter Brevo

Copia `.env.example` in `.env.local` e imposta:

```bash
BREVO_API_KEY=xkeysib-...
BREVO_LIST_ID=123
```

Poi riavvia il container:

```bash
make up
```

Per testare l'iscrizione:

```bash
make test-newsletter EMAIL=tu@example.com
```

## Avvio locale senza Docker

Serve PHP disponibile da terminale. Questa modalità usa il router PHP solo per sviluppo:

```bash
php -S 127.0.0.1:8081 router.php
```

Poi apri:

```text
http://127.0.0.1:8081
```

Le lingue disponibili sono:

```text
/
/en/
/de/
/fr/
```

## Contenuti

I testi della landing sono in semplici file Markdown:

```text
content/it/home.md
content/en/home.md
content/de/home.md
content/fr/home.md
```

Ogni sezione usa questo formato:

```md
## Features
Titolo visibile della sezione.

Testo descrittivo.

- Titolo card|Descrizione card
```

Le immagini reali del prodotto vengono copiate da `docs/screenshots` e servite da `assets/screenshots`, così funzionano anche dentro Docker.
