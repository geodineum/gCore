# gCore CLI Usage Guide

This guide provides instructions for using gCoreCLI, the command-line interface for managing and interacting with gCore.

## Installation and Setup

The gCoreCLI tool is included with gCore and can be run directly from the terminal:

```bash
# Navigate to your gCore installation directory
cd /path/to/gcore

# Run the CLI
php gCoreCLI.php
```

If you want to access gCoreCLI from anywhere on your system, you can create a symbolic link:

```bash
# For Linux/macOS
sudo ln -s /absolute/path/to/gcore/gCoreCLI.php /usr/local/bin/gcore
chmod +x /usr/local/bin/gcore

# For Windows (PowerShell as Administrator)
New-Item -ItemType SymbolicLink -Path "C:\Windows\System32\gcore.bat" -Target "C:\path\to\gcore\gcore.bat"
```

Where `gcore.bat` contains:
```batch
@echo off
php "%~dp0\gCoreCLI.php" %*
```

## Basic Usage

To view all available commands:

```bash
php gCoreCLI.php help
```

### Command Structure

gCoreCLI uses the following command structure:

```
php gCoreCLI.php [command] [options]
```

### Available Commands

| Command | Description |
|---------|-------------|
| `status` | Show the status of gCore and all services |
| `service` | Manage a specific service |
| `test` | Run tests (unit/integration/all) |
| `topology` | Manage the service topology |
| `schema` | Validate configuration schemas |
| `dependency` | Analyze and fix dependencies |
| `check-capabilities` | Analyze code to find used capabilities |
| `help` | Show help information |

## Command Reference

### Status Command

Displays the current status of gCore and all registered services:

```bash
php gCoreCLI.php status
```

Example output:
```
gCore Status
===========
Environment: development
Debug: Yes

Services:
  SecurityManager     [active] Uptime: 3600.00 seconds
  CacheManager        [active] Uptime: 3600.00 seconds
  ErrorManager        [active] Uptime: 3600.00 seconds
  APIManager          [active] Uptime: 3600.00 seconds

Capability Dimensions: 12
Active Nodes: 1
```

### Service Command

Manage specific services:

```bash
php gCoreCLI.php service [name] [action]
```

Available actions:
- `status`: Show service status
- `start`: Start a service
- `stop`: Stop a service
- `restart`: Restart a service

Example:
```bash
php gCoreCLI.php service CacheManager status
```

Output:
```
Service: CacheManager
===================
State: active
Uptime: 3600 seconds
Health: 100%

Capabilities:
  caching: 5.0
  stream_processing: 3.0

Dependencies:
  ErrorManager

Metrics:
  hits: 15432
  misses: 234
  memory_usage: 1.2MB
```

### Test Command

Run tests:

```bash
php gCoreCLI.php test [suite]
```

Available test suites:
- `unit`: Run unit tests only
- `integration`: Run integration tests only
- `all`: Run all tests (default)

You can also provide a specific test file path.

### Topology Command

Manage service topology:

```bash
php gCoreCLI.php topology [action] [options]
```

Available actions:
- `visualize`: Show service topology visualization
- `discover`: Discover services based on requirements
- `path`: Find service invocation path
- `dimensions`: List capability dimensions

Examples:

```bash
# Visualize service topology
php gCoreCLI.php topology visualize

# Discover services with specific capabilities
php gCoreCLI.php topology discover --capability-logging=3.0 --capability-caching=2.0

# List all capability dimensions
php gCoreCLI.php topology dimensions
```

### Schema Command

Validate configuration schemas:

```bash
php gCoreCLI.php schema [options] [files...]
```

Options:
- `--config=DIR`: Directory containing configuration files
- `--verbose`: Show detailed validation information
- `--fix`: Attempt to fix validation errors

Examples:

```bash
# Validate all configuration files
php gCoreCLI.php schema

# Validate specific files with verbose output
php gCoreCLI.php schema --verbose config/services.yaml config/dependencies.yaml

# Validate and attempt to fix errors
php gCoreCLI.php schema --fix
```

### Dependency Command

Analyze and fix service dependencies:

```bash
php gCoreCLI.php dependency [action] [options]
```

Available actions:
- `analyze`: Analyze dependencies for issues
- `fix`: Attempt to fix circular dependencies
- `generate-graph`: Generate dependency graph in DOT format

Options:
- `--config=DIR`: Configuration directory
- `--strategy=STRATEGY`: Dependency resolution strategy (strict, relaxed, auto-fix)
- `--verbose`: Show detailed output
- `--export=PATH`: Export results to file

Examples:

```bash
# Analyze dependencies
php gCoreCLI.php dependency analyze --verbose

# Fix circular dependencies
php gCoreCLI.php dependency fix

# Generate dependency graph
php gCoreCLI.php dependency generate-graph --export=graph.dot
```

### Check Capabilities Command

This command identifies which gCore capabilities are being used in your code and generates an optimized configuration file:

```bash
php gCoreCLI.php check-capabilities [path] [options]
```

Options:
- `--recursive` or `-r`: Scan subdirectories recursively
- `--verbose` or `-v`: Show detailed scan information
- `--output-config=PATH`: Generate minimal configuration file

Examples:

```bash
# Scan a project directory with all options
php gCoreCLI.php check-capabilities /path/to/project --recursive --verbose --output-config=config/minimal.yaml

# Scan a single file
php gCoreCLI.php check-capabilities /path/to/file.php --output-config=config/file-config.yaml
```

## Capability Checker In-Depth

The capability checker is a tool that analyzes your code to identify which gCore capabilities are being used. This helps optimize your configuration by including only the components you actually need.

### How It Works

The capability checker:
1. Scans PHP files for patterns that indicate capability usage
2. Identifies traits, interfaces, managers, and direct capability references
3. Generates a report of all capabilities found
4. Creates a minimal configuration file with only the required components

### Usage Patterns Detected

The checker looks for:

1. **Trait and Interface Usage**:
   - Classes using gCore traits 
   - Classes implementing gCore interfaces
   - Classes extending gCore managers

2. **Manager References**:
   - SecurityManager
   - CacheManager
   - ErrorManager
   - APIManager

3. **Direct Capability Checks**:
   - `hasCapability()`
   - `requireCapability()`
   - `checkCapability()`

4. **Topology Functions**:
   - `geometric_discover()`
   - `geometric_register()`
   - `discover_services()`

### Example Output

```
Scanning for capability usage in: /path/to/project (recursive)
Found 24 PHP files to scan.

Capability Analysis Results:
===========================

traits (3):
  - AuthenticationTrait
  - CryptoTrait
  - ValidationTrait

capabilities (2):
  - manage_security
  - access_api

managers (2):
  - SecurityManager
  - APIManager

functions (1):
  - geometric_discover

Total distinct capability indicators found: 8

Generating minimal configuration file: config/minimal.yaml
✅ Configuration file generated successfully!
Note: This is a minimal starting configuration. You may need to customize it further.
```

### Generated Configuration

The generated configuration file includes only the components detected:

```yaml
# gCore Minimal Configuration
# Generated by gCoreCLI check-capabilities on 2023-06-15 10:30:45
# This configuration includes only the components detected in your codebase

core:
  environment: development
  debug: true

services:
  securitymanager:
    class: gCore\Modules\Managers\Base\SecurityManager\SecurityManager
    enabled: true
    traits:
      - AuthenticationTrait
      - CryptoTrait
  apimanager:
    class: gCore\Modules\Managers\Base\APIManager\APIManager
    enabled: true
    traits:
      - ValidationTrait

capabilities:
  manage_security: true
  access_api: true
```

## Advanced Examples

### Service Dependency Analysis

Detect and visualize potential circular dependencies:

```bash
# Analyze dependencies with detailed output
php gCoreCLI.php dependency analyze --verbose

# Generate a visual representation of service dependencies
php gCoreCLI.php dependency generate-graph --export=dependencies.dot

# Convert DOT file to PNG image
dot -Tpng dependencies.dot -o dependencies.png
```

### Capability-Based Service Discovery

Find services that meet specific capability requirements:

```bash
# Discover services with multiple capabilities
php gCoreCLI.php topology discover --capability-security=3.0 --capability-performance=4.0
```

### Multi-Project Capability Analysis

Compare capability usage across multiple projects:

```bash
# Create configuration files for each project
php gCoreCLI.php check-capabilities /path/to/project1 -r --output-config=config/project1.yaml
php gCoreCLI.php check-capabilities /path/to/project2 -r --output-config=config/project2.yaml

# Use a custom script to compare configurations
php compare_configs.php config/project1.yaml config/project2.yaml
```

## Automation & Integration

### Continuous Integration

Integrate capability checking into your CI pipeline:

```yaml
# Example GitLab CI configuration
capability-check:
  stage: test
  script:
    - php gCoreCLI.php check-capabilities . -r --output-config=config/ci-config.yaml
    - php verify_config.php config/ci-config.yaml
```

### Pre-Commit Hook

Create a pre-commit hook to check for capability usage:

```bash
#!/bin/bash
# .git/hooks/pre-commit

# Get list of staged PHP files
files=$(git diff --cached --name-only --diff-filter=ACM | grep ".php$")

if [ -n "$files" ]; then
  # Create a temporary file to store these files
  temp_file=$(mktemp)
  echo "$files" > "$temp_file"
  
  # Run capability check on staged files
  php gCoreCLI.php check-capabilities --file-list="$temp_file" --verbose
  
  # Clean up
  rm "$temp_file"
fi
```

## Best Practices

### For Capability Checker

1. **Regular Analysis**: Run the capability checker periodically as your codebase evolves
2. **Version Control**: Store generated configuration files in version control
3. **Targeted Scanning**: Use the capability checker on specific modules or plugins
4. **Configuration Review**: Review generated configurations before applying them

### For CLI Usage

1. **Scripted Operations**: Create shell scripts for common operations
2. **Environment Variables**: Use environment variables for consistent configuration
3. **Logging**: Redirect CLI output to log files for troubleshooting
4. **Scheduled Tasks**: Use cron jobs for regular maintenance tasks

## Troubleshooting

### Command Not Found

If you get "command not found" errors:

1. Make sure you're in the gCore directory
2. Verify PHP is installed and in your PATH
3. Check file permissions on gCoreCLI.php

### Scan Failures

If capability scanning fails:

1. Check file permissions in the target directory
2. Ensure PHP has read access to all files
3. Try scanning subdirectories individually
4. Use verbose mode for more detailed error information

### Connection Issues

If you see Redis/ValKey connection errors:

1. Verify the Redis/ValKey server is running
2. Check connection settings in your configuration
3. Ensure network connectivity between CLI and server

## Next Steps

After setting up and using gCoreCLI:

1. Explore the gCore API documentation
2. Check out example implementations in the examples directory
3. Create custom scripts to automate common tasks
4. Set up scheduled tasks for regular maintenance

For more information, see:
- [gCore Documentation](INDEX.md)
- [Docker Setup Guide](Guide-Docker.md)
- [WordPress Setup Guide](Guide-WordPress.md)