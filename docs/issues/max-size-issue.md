Production rejects any upload over 2 GB, and Livewire's upload JavaScript silently swallows the rejection, leaving the UI stuck on "Validating file..." forever. Your livestream files are presumably over 2 GB; my small test file uploaded through the production pipeline without a hitch, which is what isolated the size limit as the trigger.

The full chain

1. Three server layers cap request bodies at 2 GB, while the app itself advertises and validates 8 GB for livestreams (config/media-processing.php:39, and the UI says "Maximum file size: 8.00 GB"):
  - Caddyfile:20 — request_body { max_size 2GB }
  - docker/production/nginx.conf:24 — client_max_body_size 2G
  - docker/production/php.ini:7-8 — post_max_size = 2G, upload_max_filesize = 2G

Local Sail has none of these proxies, which is exactly why it works locally.
2. When the browser starts POSTing a >2 GB body to Livewire's temp-upload endpoint, the proxy answers 413 from the declared Content-Length and closes the connection while the browser is still sending. Chrome surfaces that as an XHR error event, not a normal response.
3. Livewire's upload code (makeRequest in vendor/livewire/livewire/dist/livewire.js) registers only a load listener — no error, timeout, or abort handler. The failure vanishes, wire:loading for mediaFile never clears, and the form sits greyed-out on "Validating file..." indefinitely.

I verified this empirically against the live production page (using a tiny throwaway .txt that can't trigger real processing): the normal flow works end-to-end; a simulated clean 413 recovers gracefully ("The mediaFile failed to upload."); a simulated connection reset reproduces your exact frozen "Validating file..." state. The Component not found: undefined console error I couldn't pin to a single line — it's a secondary artifact of the component being stuck mid-upload (a rejected promise inside Livewire's upload chain), not the root cause.

★ Insight ─────────────────────────────────────
- Proxies enforce body limits from the Content-Length header before reading the body, so an oversized upload fails instantly — that's why you hang at "Validating file" rather than partway through a progress bar.
- A clean 413 and a connection reset look identical to users but take completely different code paths: Livewire handles the former (via its load listener) and silently drops the latter. Whether you get one or the other depends on proxy timing, which is why this class of bug is so environment-specific.
─────────────────────────────────────────────────

Fix

Raise the three limits to match the app's 8 GB (I'd set the proxy layers slightly higher, e.g. 9G, since multipart encoding adds overhead):

- Caddyfile — note this is copied manually to /srv/crockenhill/ on the host (per scripts/server-setup.sh); a repo edit alone won't reach production, someone must update it on the server and reload Caddy.
- docker/production/nginx.conf and docker/production/php.ini — these bake into the app image (Dockerfile:82-83), so a normal deploy picks them up.

Two things to weigh before bumping: an 8 GB upload gets buffered to disk by nginx, then copied by PHP, then again into livewire-tmp, then into temp/livewire-upload — several transient copies on a box where disk pressure is a known constraint. And as defence-in-depth, a stall watchdog in media-upload-controller.js (no progress events for N seconds while uploadInProgress → surface an error) would stop any future network failure from presenting as an infinite "Validating file".