<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Ports\PasswordHasher;
use App\Application\Ports\UserRepository;
use App\Domain\Entities\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class BootstrapLibraryAdmin extends Command
{
    protected $signature = 'library:bootstrap-admin';
    protected $description = 'Provision the initial library administrator from environment variables';

    public function handle(UserRepository $users, PasswordHasher $passwords): int
    {
        $username = trim((string) getenv('LIBRARY_ADMIN_USERNAME'));
        $password = (string) getenv('LIBRARY_ADMIN_PASSWORD');

        if ($username === '' || $password === '') {
            $this->error('Set LIBRARY_ADMIN_USERNAME and LIBRARY_ADMIN_PASSWORD in the container environment.');
            return self::FAILURE;
        }

        if (mb_strlen($password) < 12) {
            $this->error('LIBRARY_ADMIN_PASSWORD must contain at least 12 characters.');
            return self::FAILURE;
        }

        try {
            $existing = $users->findByUsername($username);
            if ($existing !== null) {
                if ($existing->role !== 'admin') {
                    $this->error('The configured username is already assigned to a non-admin user.');
                    return self::FAILURE;
                }
                $this->info('Administrator already exists; no changes made.');
                return self::SUCCESS;
            }

            $admin = new User((string) Str::uuid(), $username, $passwords->hash($password), 'admin');
            $users->save($admin);
            $this->info('Initial administrator created.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Administrator provisioning failed; check database connectivity and configuration.');
            return self::FAILURE;
        }
    }
}
