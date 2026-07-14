<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Exceptions;

class ErrorException extends \RuntimeException 
{
    protected $context;
    protected $severity;
    
    public function __construct(string $message, array $context = [], string $severity = 'ERROR', int $code = 0) 
    {
        $this->context = $context;
        $this->severity = $severity;
        parent::__construct($message, $code);
    }
    
    public function getContext(): array 
    {
        return $this->context;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }
}