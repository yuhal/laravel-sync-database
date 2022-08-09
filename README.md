# Laravel Database Migration


<p align="center"> 
    <img src="https://www.showdoc.com.cn/server/api/attachment/visitFile?sign=cbfa413353e63046688fefba35892e27" alt="Laravel Sync Migration">
</p>


[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]


If you don't want to create migration files manually during the development of laravel project. It can help you Automatically create and update the migration file corresponding to the table structure in the database。 In addition, it can be used in combination with laravel sync migration to realize bidirectional synchronization of database and migrated files.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/yuhal/laravel-sync-database.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/yuhal/laravel-sync-database.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/yuhal/laravel-sync-database
[link-downloads]: https://packagist.org/packages/yuhal/laravel-sync-database
[link-author]: https://github.com/if4lcon
[link-contributors]: ../../contributors


# 介绍

> 使用 laravel 框架的小伙伴，这将会是你的福音！推荐使用 [yuhal/laravel-sync-database](https://github.com/yuhal/laravel-sync-database "yuhal/laravel-sync-database")，可以帮助您高效开发哦，欢迎 star OR fork！如果您不想在开发laravel项目时手动创建迁移文件。它可以帮助您自动创建和更新数据库中表结构对应的迁移文件。此外，它还可以与早期同步迁移结合使用，实现数据库和迁移文件的双向同步。

# 安装

- 通过 composer 创建项目 

```
$ composer create-project --prefer-dist laravel/laravel blog
```

- 进入 blog 目录

```
$ cd blog
```

- 复制 .env.example 配置文件，命名为 .env

```
$ cp .env.example .env
```

- 修改 .env

```
# 项目地址
APP_URL=http://blog.com
# mysql数据库连接
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=blog
DB_USERNAME=root
DB_PASSWORD=root
```

- composer 设置国内镜像

```
$ composer config -g repo.packagist composer https://mirrors.aliyun.com/composer
```

- composer 安装依赖包

```
$ composer install
$ composer require kitloong/laravel-migrations-generator=5.0.1
$ composer require awssat/laravel-sync-migration
$ composer require yuhal/laravel-sync-database
```

# 同步

- 首次执行迁移

```
$ php artisan migrate
Migration table created successfully.
Migrating: 2014_10_12_000000_create_users_table
Migrated:  2014_10_12_000000_create_users_table (19.90ms)
Migrating: 2014_10_12_100000_create_password_resets_table
Migrated:  2014_10_12_100000_create_password_resets_table (15.12ms)
Migrating: 2019_08_19_000000_create_failed_jobs_table
Migrated:  2019_08_19_000000_create_failed_jobs_table (16.40ms)
Migrating: 2019_12_14_000001_create_personal_access_tokens_table
Migrated:  2019_12_14_000001_create_personal_access_tokens_table (23.22ms)
```

- users 迁移文件新增 phone 字段

```
table->string('phone')->unique();
```

- 迁移文件同步数据库

```
$ php artisan migrate:sync
New column  users->phone  was created
```

- users 数据库表重命名 phone 字段为 mobile

> 通过执行 SQL 或 数据库可视化工具更改。

- 数据库同步迁移文件

```
$ php artisan migrate:sync
/Users/hai/env/docker/laradock/blog/database/migrations/2014_10_12_000000_create_users_table.php
Add migration column: mobile
Delete migration column: phone
```

# 提示

> 数据库和迁移文件的双向同步，不仅支持字段的增删改，也支持数据表的增删改。
