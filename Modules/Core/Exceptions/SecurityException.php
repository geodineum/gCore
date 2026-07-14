<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Exceptions;

class SecurityException extends \RuntimeException 
{
    protected $context;
    
    public function __construct(string $message, array $context = [], int $code = 0) 
    {
        $this->context = $context;
        parent::__construct($message, $code);
    }
    
    public function getContext(): array 
    {
        return $this->context;
    }
}