# 🪦 Mortician: Possibly dead — ConvertJpgToWebp Command

## 1. What
- **File Path:** `app/Console/Commands/ConvertJpgToWebp.php`
- **Companion Test Path:** `tests/Feature/Console/ConvertJpgToWebpCommandTest.php`
- **Command Signature:** `images:convert-to-webp`
- **Description:** A one-shot Artisan command that scans designated directories for JPG files, converts them to WebP using Intervention Image, and updates code references inside Blade templates, CSS/JS files, and PHP source code.

---

## 2. Evidence of Disuse

Project-wide search using `grep` confirms that `ConvertJpgToWebp` is completely unreferenced by the rest of the application. No controllers, services, models, views, or routing tables invoke it. It is not registered in the console kernel or scheduler.

### 🔍 Search for Class Name & Signature
```bash
$ grep -rn "ConvertJpgToWebp\|convert-to-webp" --include="*.php" --include="*.blade.php" --include="*.js"
```
**Output:**
```
app/Console/Commands/ConvertJpgToWebp.php:15:class ConvertJpgToWebp extends Command
app/Console/Commands/ConvertJpgToWebp.php:17:    protected $signature = 'images:convert-to-webp
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:7:use App\Console\Commands\ConvertJpgToWebp;
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:12:class ConvertJpgToWebpCommandTest extends TestCase
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:31:        $this->app->bind(ConvertJpgToWebp::class, fn () => new class($this->imageDir, $this->bladeDir) extends ConvertJpgToWebp
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:69:        $this->artisan('images:convert-to-webp', [
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:87:        $this->artisan('images:convert-to-webp', [
tests/Feature/Console/ConvertJpgToWebpCommandTest.php:99:        $this->artisan('images:convert-to-webp', [
```

### 🔍 String-name Resolution Check
We scanned for possible dynamic or string-resolved occurrences of `ConvertJpgToWebp` in the codebase.
```bash
$ grep -rn "'.*ConvertJpgToWebp.*'" --include="*.php"
```
**Output (Composer autoloader maps only):**
```
vendor/composer/autoload_static.php:1052:        'App\\Console\\Commands\\ConvertJpgToWebp' => __DIR__ . '/../..' . '/app/Console/Commands/ConvertJpgToWebp.php',
vendor/composer/autoload_static.php:47418:        'Tests\\Feature\\Console\\ConvertJpgToWebpCommandTest' => __DIR__ . '/../..' . '/tests/Feature/Console/ConvertJpgToWebpCommandTest.php',
vendor/composer/autoload_classmap.php:89:    'App\\Console\\Commands\\ConvertJpgToWebp' => $baseDir . '/app/Console/Commands/ConvertJpgToWebp.php',
vendor/composer/autoload_classmap.php:46455:    'Tests\\Feature\\Console\\ConvertJpgToWebpCommandTest' => $baseDir . '/tests/Feature/Console/ConvertJpgToWebpCommandTest.php',
```

### 🔍 Route Check
Because this is an Artisan command and not a controller, we verified that no routes target its class or invoke it:
```bash
$ php artisan route:list --json | grep -i "ConvertJpgToWebp"
```
**Output:**
*(empty)*

---

## 3. Risk Assessment & Alignment

- **Risk Level:** **Very Low — Pure Removal.**
  The command is a pure helper utility designed for a one-time migration. Deleting the command file and its companion test file will not impact any production flows or user-facing routes.
- **Simplification Backlog Alignment:**
  This matches the **R8 checklist** in the current Simplification Remainder plan (`docs/plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md`):
  > *`ConvertJpgToWebp` + test | Confirm the repo-wide conversion path is spent | ✅ 2026-07-20: 41 JPGs remain but zero code references would change; classified below. Do not run the real converter.*

  The command is spent, and the remaining JPG images are stable/intended.

---

## 4. Recommendation

**Safe to remove.**
Since the command has served its purpose and its repo-wide conversion path is fully spent, we recommend burying this dead artifact during the current Simplification Phase 8/9 work.

Removing it will:
1. Prune dead code and reduce `artisan list` clutter.
2. Speed up CI by removing its feature test (`ConvertJpgToWebpCommandTest.php`), which recursively scans temp directories.
3. Align with the Simplification Remainder's R8 goals.

---
*Reported by Mortician 🪦*
