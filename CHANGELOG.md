# Changelog

## [2.0.0](https://github.com/Lameco-Development/deployer-tasks/compare/1.4.1...2.0.0) (2026-05-22)


### ⚠ BREAKING CHANGES

* The lameco:db_download, lameco:db_upload, lameco:sync, and lameco:deactivate tasks now invoke the mariadb and mariadb-dump clients instead of mysql and mysqldump. Every host that runs these tasks — locally and on each remote — must have the MariaDB client installed; the task will otherwise fail with "command not found".

### Features

* switch database CLI from mysql/mysqldump to mariadb/mariadb-dump ([60c965a](https://github.com/Lameco-Development/deployer-tasks/commit/60c965a37233cb6d9b78156471f3401dd5f704b6))

## [1.4.1](https://github.com/Lameco-Development/deployer-tasks/compare/1.4.0...1.4.1) (2026-05-11)


### Miscellaneous Chores

* add release-please automation ([d494fa1](https://github.com/Lameco-Development/deployer-tasks/commit/d494fa148e1d9b51c0f7d869793fde6fc57ec450))
* add release-please automation ([c67a474](https://github.com/Lameco-Development/deployer-tasks/commit/c67a4747963caff1bcf255106d7038269d6dc003))
