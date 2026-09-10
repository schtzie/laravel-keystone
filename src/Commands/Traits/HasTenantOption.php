<?php

declare(strict_types=1);

namespace Schtzie\Keystone\Commands\Traits;

use Schtzie\Keystone\Facades\Keystone;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait HasTenantOption
{
    /**
     * Intercept the command execution to initialize the tenant context if requested.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasOption('tenant') && $this->option('tenant')) {
            $val = $this->option('tenant');
            if (is_array($val)) {
                $tenantId = isset($val[0]) && is_scalar($val[0]) ? (string) $val[0] : '';
            } else {
                $tenantId = (string) $val;
            }

            if (Keystone::$tenantResolver === null) {
                $this->error('The --tenant option requires Keystone::initializeTenantUsing() to be registered in your AppServiceProvider.');

                return self::FAILURE;
            }

            // Execute the closure to initialize the tenant environment
            call_user_func(Keystone::$tenantResolver, $tenantId);
        }

        return parent::execute($input, $output);
    }
}
