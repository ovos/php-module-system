# README #

### GitHub
https://github.com/ovos/php-module-system

### Requirements

* PHP 8.0

#### Installation
```
"repositories": [
{
  "type": "vcs",
  "url": "git@github.com:ovos/php-module-system.git"
} 
],
```

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
* `php cli.php tests run`  
runs tests  
#### Migrations
* `php cli.php migrations status`  
displays summary of migrations 
* `php cli.php migrations run :amount`  
runs migrations
* `php cli.php migrations rollback :amount`  
rolls back migrations  
