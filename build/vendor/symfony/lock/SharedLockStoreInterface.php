<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock;

use LockmeDep\Symfony\Component\Lock\Exception\LockConflictedException;
/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface SharedLockStoreInterface extends PersistingStoreInterface
{
    /**
     * Stores the resource if it's not locked for reading by someone else.
     *
     * @return void
     *
     * @throws LockConflictedException
     */
    public function saveRead(Key $key);
}
