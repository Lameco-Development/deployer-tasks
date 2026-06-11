# Changelog

## [2.2.0](https://github.com/Lameco-Development/deployer-tasks/compare/2.1.0...2.2.0) (2026-06-11)


### Features

* add local endpoint support to lameco:sync ([ef91d38](https://github.com/Lameco-Development/deployer-tasks/commit/ef91d3814beb5a665d70a2594f7d72db9f92ded1))
* default lameco:sync to production -&gt; staging selection ([f0f23db](https://github.com/Lameco-Development/deployer-tasks/commit/f0f23db41cc696f3ff92155c0ccfa5e29499e1c0))
* order lameco:sync endpoints as local, staging, then production ([6369035](https://github.com/Lameco-Development/deployer-tasks/commit/6369035e1b2ba47f3102431c0819f94588198a64))

## [2.1.0](https://github.com/Lameco-Development/deployer-tasks/compare/2.0.0...2.1.0) (2026-06-08)


### Features

* support Deployer 8 alongside Deployer 7 ([#63](https://github.com/Lameco-Development/deployer-tasks/issues/63)) ([4d996c8](https://github.com/Lameco-Development/deployer-tasks/commit/4d996c81ae04d86d94e08f3084f4704686c0e41b))

## [2.0.0](https://github.com/Lameco-Development/deployer-tasks/compare/1.4.1...2.0.0) (2026-05-22)


### ⚠ BREAKING CHANGES

* The lameco:db_download, lameco:db_upload, lameco:sync, and lameco:deactivate tasks now invoke the mariadb and mariadb-dump clients instead of mysql and mysqldump. Every host that runs these tasks — locally and on each remote — must have the MariaDB client installed; the task will otherwise fail with "command not found".

### Features

* switch database CLI from mysql/mysqldump to mariadb/mariadb-dump ([60c965a](https://github.com/Lameco-Development/deployer-tasks/commit/60c965a37233cb6d9b78156471f3401dd5f704b6))

## [1.4.1](https://github.com/Lameco-Development/deployer-tasks/compare/1.4.0...1.4.1) (2026-05-11)


### Miscellaneous Chores

* add release-please automation ([d494fa1](https://github.com/Lameco-Development/deployer-tasks/commit/d494fa148e1d9b51c0f7d869793fde6fc57ec450))
* add release-please automation ([c67a474](https://github.com/Lameco-Development/deployer-tasks/commit/c67a4747963caff1bcf255106d7038269d6dc003))
