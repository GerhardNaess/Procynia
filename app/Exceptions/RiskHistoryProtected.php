<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A risk with assessments, acceptances or tiltak cannot be deleted (decision B3); it is closed
 * instead. A 409: the request is allowed, the risk's state is what refuses it.
 */
class RiskHistoryProtected extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(__('procynia.risk.flash.delete_blocked'));
    }
}
