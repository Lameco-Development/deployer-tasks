# Security Configuration for Deployer Tasks

## Security Tools Setup

This project includes security configurations and tools to help identify and prevent vulnerabilities.

### Recommended Security Tools

1. **PHPStan with Security Rules**
```bash
composer require --dev phpstan/phpstan:^1.10
composer require --dev phpstan/phpstan-strict-rules:^1.5
vendor/bin/phpstan analyse
```

2. **Composer Security Advisories**
```bash
composer require --dev roave/security-advisories:dev-latest
composer audit
```

3. **PHPCS with Security Sniffs**
```bash
composer require --dev squizlabs/php_codesniffer
composer require --dev pheromone/phpcs-security-audit
```

### Security Best Practices

1. **Input Validation**
   - All user inputs must be validated using the security functions
   - Configuration names are sanitized to prevent path traversal
   - Node.js versions are validated against expected patterns

2. **Command Execution**
   - All shell arguments are escaped using `escapeshellarg()`
   - Command components are escaped using `escapeshellcmd()`
   - No direct string interpolation in shell commands

3. **Credential Handling**
   - Database credentials use secure MySQL configuration files
   - Passwords are never exposed in command line arguments
   - Temporary credential files are securely created and cleaned up
   - Credentials are redacted from logs

4. **File Operations**
   - All file paths are validated to prevent traversal attacks
   - Directory names are sanitized before use
   - Temporary files use secure permissions (0600)

## Security Functions

The `src/security.php` file provides validated functions for:

- `escapeShellArg()` - Escape shell arguments
- `escapeShellCmd()` - Escape shell commands
- `validateConfigName()` - Validate configuration names
- `validateNodeVersion()` - Validate Node.js versions
- `validateStage()` - Validate deployment stage names
- `validateDirectoryPath()` - Validate directory paths
- `createSecureMysqlConfig()` - Create secure MySQL config files
- `secureUnlink()` - Safely delete temporary files

## Regular Security Maintenance

1. **Dependency Updates**
   - Run `composer update` regularly
   - Monitor security advisories
   - Review changelog for security fixes

2. **Code Reviews**
   - Review all shell command executions
   - Validate input handling
   - Check credential management

3. **Security Audits**
   - Run static analysis tools regularly
   - Perform manual code reviews
   - Test with security-focused scenarios

## Incident Response

If a security issue is discovered:

1. **Immediate Response**
   - Assess the scope and impact
   - Implement temporary mitigations
   - Document the issue

2. **Fix Development**
   - Develop and test fixes
   - Review fixes for completeness
   - Update documentation

3. **Deployment**
   - Deploy fixes promptly
   - Notify users if necessary
   - Monitor for further issues

## Contact

For security concerns, please contact the security team directly rather than opening public issues.