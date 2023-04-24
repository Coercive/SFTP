# SFTP
SFTP utility for PHP

Get
---
```
composer require coercive/sftp
```

Dependencies
------------

This package use `ext-ssh2` : [manual](https://www.php.net/manual/en/ref.ssh2.php)


Usage
-----

Connect to FTP

```php
use Coercive\Utility\SFTP\SFTP;

$SFtp = new SFTP('127.0 0.1', 22);
$SFtp->login('BestUser', 'BestPassword');
$SFtp->connect();
```

Disconnect

```php
$SFtp->disconnect();
```

Create directory

```php
$SFtp->mkdir('/example/dir/test');
```

List diretories and files

```php
$data = $SFtp->list('/example/dir/test');
```

Upload file

```php
$SFtp->upload('/README.md', '/example/dir/test/test.md');
```

Download file

```php
$SFtp->download('/example/dir/test/test.md', '/test/dowloaded_file.md');
```

Download file : with auto tmp name and prefix

```php
$SFtp->setTmpPrefix('_test_tmp_prefix_');
$SFtp->download('/example/dir/test/test.md', $filepath);

# do something with your file
rename($filepath, '/test/dowloaded_file.md');
```

Filesize

```php
$integer = $SFtp->filesize('/example/dir/test/test.md');
```

Read file

```php
$data = $SFtp->read('/example/dir/test/test.md');
```

Write into file

```php
$SFtp->write('/example/dir/test/test.md', "# Hello World !\n");
```

Remove file

```php
$SFtp->delete('/example/dir/test/test.md');
```
