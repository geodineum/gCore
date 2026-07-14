<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Exceptions;

/**
 * CircularDependencyException - Thrown when circular dependencies are detected
 *
 * This exception provides detailed information about circular dependencies to help
 * developers identify and resolve dependency cycles.
 *
 * @package     gCore
 * @subpackage  Core\Exceptions
 * @version     0.1.0 
 */
class CircularDependencyException extends \Exception
{
    /** @var array Path of the circular dependency */
    private array $path;
    
    /**
     * Constructor
     */
    public function __construct(string $message, array $path = [], int $code = 0, \Throwable $previous = null)
    {
        if (!empty($path)) {
            $message .= ': ' . implode(' -> ', $path) . ' -> ' . $path[0];
        }
        
        parent::__construct($message, $code, $previous);
        $this->path = $path;
    }
    
    /**
     * Get the path of the circular dependency
     */
    public function getPath(): array
    {
        return $this->path;
    }
    
    /**
     * Get a visual representation of the circular dependency
     */
    public function getVisualPath(): string
    {
        if (empty($this->path)) {
            return '(unknown path)';
        }
        
        return implode(' -> ', $this->path) . ' -> ' . $this->path[0] . ' (cycle)';
    }
    
    /**
     * Convert to string
     */
    public function __toString(): string
    {
        return parent::__toString() . PHP_EOL . 'Path: ' . $this->getVisualPath();
    }
}