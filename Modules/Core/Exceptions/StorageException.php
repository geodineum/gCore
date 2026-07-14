<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Exceptions;

class StorageException extends ErrorException {
    public function __construct(string $message, int $code = 0, \Throwable $previous = null) 
    {
        parent::__construct($message, [], 'ERROR', $code, $previous);
    }
}