<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Exceptions;

/**
 * StateTransitionException
 * 
 * Thrown when an invalid state transition is requested. For example, attempting
 * to transition a trait from 'inactive' to 'failed' when the valid transitions
 * for 'inactive' might only include 'initializing' and 'pending'.
 * 
 * @package gCore
 * @subpackage Core\Exceptions
 */
class StateTransitionException extends \RuntimeException {
    /** @var array Valid states for the transition */
    private $validStates;

    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param array $validStates The valid states that could have been used
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        array $validStates = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->validStates = $validStates;
    }

    /**
     * Get the valid states
     * 
     * @return array Valid states that could have been used
     */
    public function getValidStates(): array {
        return $this->validStates;
    }
}