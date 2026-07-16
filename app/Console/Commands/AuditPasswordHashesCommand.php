<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AuditPasswordHashesCommand extends Command
{
    protected $signature = 'audit:password-hashes {--json : Emit the audit report as JSON}';

    protected $description = 'Count stored user password hashes by algorithm; with HASH_VERIFY=true any non-bcrypt hash throws on login instead of failing the check';

    public function handle(): int
    {
        $counts = [
            'bcrypt' => 0,
            'bcrypt_variant' => 0,
            'argon2id' => 0,
            'argon2i' => 0,
            'empty' => 0,
            'other' => 0,
        ];

        foreach (User::query()->pluck('password') as $hash) {
            $counts[$this->classify($hash)]++;
        }

        $total = array_sum($counts);
        $nonBcrypt = $total - $counts['bcrypt'];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'total' => $total,
                'non_bcrypt' => $nonBcrypt,
                'counts' => $counts,
            ], JSON_PRETTY_PRINT));

            return $nonBcrypt > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Hash algorithm', 'Users'],
            [
                ['bcrypt ($2y$)', (string) $counts['bcrypt']],
                ['bcrypt variant ($2a$/$2b$/$2x$)', (string) $counts['bcrypt_variant']],
                ['argon2id', (string) $counts['argon2id']],
                ['argon2i', (string) $counts['argon2i']],
                ['empty', (string) $counts['empty']],
                ['other/unknown', (string) $counts['other']],
                ['total', (string) $total],
            ],
        );

        if ($nonBcrypt > 0) {
            $this->error("{$nonBcrypt} of {$total} stored hashes are not \$2y\$ bcrypt. With HASH_VERIFY=true, Hash::check() throws for these rows, so an affected login attempt 500s. Remediate before trusting hash verification.");

            return self::FAILURE;
        }

        $this->info("All {$total} stored password hashes are \$2y\$ bcrypt. HASH_VERIFY=true is safe.");

        return self::SUCCESS;
    }

    private function classify(?string $hash): string
    {
        if ($hash === null || $hash === '') {
            return 'empty';
        }

        if (str_starts_with($hash, '$2y$')) {
            return 'bcrypt';
        }

        if (str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$2x$')) {
            return 'bcrypt_variant';
        }

        if (str_starts_with($hash, '$argon2id$')) {
            return 'argon2id';
        }

        if (str_starts_with($hash, '$argon2i$')) {
            return 'argon2i';
        }

        return 'other';
    }
}
