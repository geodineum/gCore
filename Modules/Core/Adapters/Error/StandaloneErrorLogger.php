<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Error;

use gCore\Modules\Core\Interfaces\Error\ErrorLoggerInterface;
use gCore\Modules\Core\Exceptions\LoggingException;

class StandaloneErrorLogger implements ErrorLoggerInterface {
    /** @var string Log directory path */
    private $log_dir;
    
    /** @var string Default log file */
    private $default_log_file;
    
    /** @var array Rotation settings */
    private $rotation = [
        'enabled' => true,
        'size_limit' => 5242880,  // 5MB
        'file_count' => 5
    ];
    
    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->log_dir = $config['log_dir'] ?? sys_get_temp_dir() . '/gcore-logs';
        $this->default_log_file = $config['default_log'] ?? 'error.log';
        
        if (isset($config['rotation'])) {
            $this->rotation = array_merge($this->rotation, $config['rotation']);
        }
        
        // Ensure log directory exists
        $this->ensureDirectory($this->log_dir);
    }
    
    /**
     * Log a message
     */
    public function log(string $message, array $context = []): bool {
        try {
            $log_file = $this->getLogFilePath();
            
            // Format the message with context
            $formatted_message = $this->formatLogMessage($message, $context);
            
            // Check rotation
            if ($this->rotation['enabled'] && file_exists($log_file)) {
                $size = filesize($log_file);
                if ($size > $this->rotation['size_limit']) {
                    $this->rotate($log_file);
                }
            }
            
            // Append to log
            $result = file_put_contents(
                $log_file,
                $formatted_message . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            
            return $result !== false;
            
        } catch (\Exception $e) {
            // Try to log to system log as fallback
            error_log("Failed to write to log file: {$e->getMessage()}");
            error_log("Original message: {$message}");
            
            // Don't throw here to avoid recursive errors
            return false;
        }
    }
    
    /**
     * Rotate log file
     */
    public function rotate(string $file): bool {
        if (!file_exists($file)) {
            return false;
        }
        
        try {
            // Rotate existing backup files
            for ($i = $this->rotation['file_count']; $i > 0; $i--) {
                $old_file = "{$file}.{$i}";
                $new_file = ($i == $this->rotation['file_count']) ? 
                    $old_file : 
                    "{$file}." . ($i + 1);
                
                if (file_exists($old_file) && $i == $this->rotation['file_count']) {
                    unlink($old_file);
                } else if (file_exists($old_file)) {
                    rename($old_file, $new_file);
                }
            }
            
            // Move current log to .1
            rename($file, "{$file}.1");
            
            // Create new empty log file
            file_put_contents($file, '');
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Failed to rotate log file: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Clean old log files
     */
    public function clean(array $files, int $retention): bool {
        try {
            $now = time();
            $cleaned = 0;
            
            foreach ($files as $file) {
                $full_path = $this->log_dir . '/' . $file;
                
                if (file_exists($full_path)) {
                    $modified = filemtime($full_path);
                    
                    // Delete if older than retention period
                    if (($now - $modified) > $retention) {
                        if (unlink($full_path)) {
                            $cleaned++;
                        }
                    }
                }
            }
            
            return $cleaned > 0;
            
        } catch (\Exception $e) {
            error_log("Failed to clean log files: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Compress log file
     */
    public function compress(string $file, int $level = 9): bool {
        if (!file_exists($file)) {
            return false;
        }
        
        try {
            // Check if zlib is available
            if (!function_exists('gzopen')) {
                throw new LoggingException("Zlib extension not available");
            }
            
            $compressed_file = "{$file}.gz";
            $fp_in = fopen($file, 'rb');
            $fp_out = gzopen($compressed_file, 'wb' . $level);
            
            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 8192));
            }
            
            fclose($fp_in);
            gzclose($fp_out);
            
            // Remove original if successful
            if (file_exists($compressed_file)) {
                unlink($file);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log("Failed to compress log file: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Ensure log directory exists
     */
    public function ensureDirectory(string $dir): bool {
        if (file_exists($dir)) {
            return is_dir($dir) && is_writable($dir);
        }
        
        try {
            $created = mkdir($dir, 0755, true);
            
            if (!$created) {
                throw new LoggingException("Failed to create directory");
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Failed to create log directory {$dir}: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Get log file path
     */
    private function getLogFilePath(): string {
        return $this->log_dir . '/' . $this->default_log_file;
    }
    
    /**
     * Format log message with context
     */
    private function formatLogMessage(string $message, array $context = []): string {
        $timestamp = date('Y-m-d H:i:s');
        
        // Basic format: [timestamp] message
        $formatted = "[{$timestamp}] {$message}";
        
        // Add context if available
        if (!empty($context)) {
            $json_context = json_encode($context);
            $formatted .= " | {$json_context}";
        }
        
        return $formatted;
    }
}