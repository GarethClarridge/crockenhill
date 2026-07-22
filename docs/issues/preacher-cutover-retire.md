# 🪦 Mortician: Possibly dead — PreacherCutover Command & Service

## 1. What
The following Artisan command, service, and tests are completely spent and ready to be safely removed from the repository:
- **Command:** `App\Console\Commands\PreacherCutoverCommand` (`app/Console/Commands/PreacherCutoverCommand.php`)
- **Service:** `App\Services\Preacher\PreacherCutoverService` (`app/Services/Preacher/PreacherCutoverService.php`)
- **Tests:**
  - `tests/Feature/PreacherCutoverCommandTest.php`
  - `tests/Integration/Services/PreacherCutoverServiceTest.php`

---

## 2. Evidence of Disuse & Completion

### Database Invariant Met
The preacher cutover tool was introduced to backfill canonical `Preacher` records from legacy sermon preacher strings. The migration has completed successfully.
In the sandbox environment, we queried the database for any sermons with a null `preacher_id`:
```bash
$ php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "Sermons with null preacher_id: " . \App\Models\Sermon::whereNull("preacher_id")->count() . "\n";'
Sermons with null preacher_id: 0
```
This demonstrates that **0** sermons require backfilling. The gate (`sermons WHERE preacher_id IS NULL` count = 0) is fully met.

### Project-Wide Reference Audit (Grep Results)
Grep searches confirm that `PreacherCutoverService` and `PreacherCutoverCommand` are completely unreferenced in active core routes, configurations, or controllers:

```bash
$ grep -rn "PreacherCutover" app/ config/ bootstrap/ routes/
app/Console/Commands/PreacherCutoverCommand.php:7:use App\Services\Preacher\PreacherCutoverService;
app/Console/Commands/PreacherCutoverCommand.php:10:class PreacherCutoverCommand extends Command
app/Console/Commands/PreacherCutoverCommand.php:18:    public function handle(PreacherCutoverService $preacherCutoverService): int
app/Services/Preacher/PreacherCutoverService.php:14: * @phpstan-type PreacherCutoverSummary array{
app/Services/Preacher/PreacherCutoverService.php:22:class PreacherCutoverService
app/Services/Preacher/PreacherCutoverService.php:32:     *     summary: PreacherCutoverSummary
```

And search for the Artisan command signature string `preachers:cutover`:
```bash
$ grep -rn "preachers:cutover" .
./app/Console/Commands/PreacherCutoverCommand.php:12:    protected $signature = 'preachers:cutover
./docs/archived-plans/tech-debt-backlog-2026-03-17.md:1191:  - `preachers:cutover` reuse of `PreacherResolutionService`
./tests/Feature/PreacherCutoverCommandTest.php:33:        $this->artisan('preachers:cutover')->assertSuccessful();
./tests/Feature/PreacherCutoverCommandTest.php:61:        $this->artisan('preachers:cutover')->assertSuccessful();
./tests/Feature/PreacherCutoverCommandTest.php:65:        $this->artisan('preachers:cutover')->assertSuccessful();
```

There are zero active references outside the command, service, tests, and archived docs.

### Backlog Reference
The `PreacherCutoverCommand` + service is explicitly scheduled for deletion in `docs/plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md` under **R8 — Items 2.4 + 2.6: gated one-shot deletions**:
- **Gate:** `sermons WHERE preacher_id IS NULL` count = 0.
- **Status as of 2026-07-20:** ✅ Complete.

---

## 3. Risk Assessment
- **Risk:** **Low**
- **Justification:** The command is a one-shot backfill utility. Since `preacher_id` is now fully backfilled on all sermons, this tool has zero future utility. No running pipelines, scheduling, web/API routes, or core classes depend on it.

---

## 4. Recommendation
- **Action:** Safe to completely remove.
- **Remediation steps:**
  1. Delete `app/Console/Commands/PreacherCutoverCommand.php`.
  2. Delete `app/Services/Preacher/PreacherCutoverService.php`.
  3. Delete `tests/Feature/PreacherCutoverCommandTest.php`.
  4. Delete `tests/Integration/Services/PreacherCutoverServiceTest.php`.
  5. Run test suites, Pint, and PHPStan to verify 0 errors.
