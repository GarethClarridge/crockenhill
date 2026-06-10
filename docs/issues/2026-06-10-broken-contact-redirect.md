# 🔗 Pathfinder: Broken redirect and typo in `config/redirects.php`

## Summary
The redirect for `/contactus` is currently returning a 404 because of a typo in the configuration key within `config/redirects.php`.

## Affected items
- `http://localhost:8000/contactus` returns **404 Not Found**.
- `http://localhost:8000/contacttus` returns **301 Moved Permanently** to `/`.

## Verification
I discovered this by checking for hardcoded internal paths and cross-referencing them with the `config/redirects.php` file and live route responses.

```bash
# Verification results:
$ curl -I http://localhost:8000/contactus
HTTP/1.1 404 Not Found
...

$ curl -I http://localhost:8000/contacttus
HTTP/1.1 301 Moved Permanently
Location: /
...
```

## Likely cause
A typo in `config/redirects.php` on line 27:
```php
'contacttus' => '/',
```

## Suggested action
Correct the key in `config/redirects.php` from `'contacttus'` to `'contactus'`.

```php
'contactus' => '/',
```

## Risk note
Minimal. This is a fix for a broken legacy redirect.
