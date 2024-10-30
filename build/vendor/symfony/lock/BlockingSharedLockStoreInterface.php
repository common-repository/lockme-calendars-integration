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
interface BlockingSharedLockStoreInterface extends SharedLockStoreInterface
{
    /**
     * Waits until a key becomes free for reading, then stores the resource.
     *
     * @return void
     *
     * @throws LockConflictedException
     */
    public function waitAndSaveRead(Key $key);
}
