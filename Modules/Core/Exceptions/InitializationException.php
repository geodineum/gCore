<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Exceptions;

/**
 * Exception thrown when the framework cannot be initialized properly
 * 
 * @package gCore
 * @subpackage Core\Exceptions
 */
class InitializationException extends \Exception {
    /**
     * Create a new initialization exception
     * 
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}