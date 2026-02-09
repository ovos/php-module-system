# README #

### GitHub
https://github.com/ovos/php-module-system

### Requirements

* PHP 8.0

#### Installation
1. Add to your .ssh/config
```
Host ovos.php-module-system
    HostName github.com
    PreferredAuthentications publickey
    IdentityFile ~/.ssh/ovos.php-module-system
```
2. Add to composer.json
```
"repositories": [
{
  "type": "vcs",
  "url": "git@github.com:ovos/php-module-system.git"
}
],
```
3.
```
git require ovos/php-module-system
```

### CLI commands
#### Clear cache
* `php cli.php system cache clear`  
clears all types of active cache
#### Stats
* `php cli.php system stats free-space`  
displays free space on server
#### Collectors (garbage, logs)
* `php cli.php system collector`  
invokes all configured (in config) collectors
#### Tests
* `php cli.php tests run [:class] [:method]`  
runs tests, :class and :method params narrow pool of tests to run, for example:  
`php cli.php tests run Router parametersNamedSkipOptional`
#### Migrations
* `php cli.php migrations status`  
displays summary of migrations 
* `php cli.php migrations run :amount`  
runs migrations
* `php cli.php migrations rollback :amount`  
rolls back migrations  
