<?php

namespace LockmeDep\LockmeIntegration;

use Exception;
interface PluginInterface
{
    /**
     * PluginInterface constructor.
     * @param Plugin $plugin
     */
    public function __construct(Plugin $plugin);
    /**
     * @return string
     */
    public function getPluginName(): string;
    /**
     * @return bool
     */
    public function CheckDependencies(): bool;
    /**
     * @return void
     */
    public function RegisterSettings(): void;
    /**
     * @return void
     */
    public function DrawForm(): void;
    /**
     * @param  array  $message
     * @return bool
     * @throws Exception
     */
    public function GetMessage(array $message): bool;
}
