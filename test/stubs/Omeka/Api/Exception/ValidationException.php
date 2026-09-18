<?php

namespace Omeka\Api\Exception;

use Omeka\Stdlib\ErrorStore;

class ValidationException extends \RuntimeException
{
    protected $errorStore;

    public function setErrorStore(ErrorStore $errorStore)
    {
        $this->errorStore = $errorStore;
    }

    public function getErrorStore()
    {
        if (!$this->errorStore instanceof ErrorStore) {
            $this->errorStore = new ErrorStore();
        }
        return $this->errorStore;
    }
}
