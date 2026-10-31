# Проверки AWS и TimeCapsule, 31.10.2026

Регион: `eu-central-1`. Бакет: `timecapsule-dev-uploads-dumkaguv`. Проверки выполнены в AWS CloudShell от IAM-пользователя `dumkaguv`, а проверки app-server — через SSH на `10.16.0.225` с его ролью EC2. Секреты STS и полные подписанные URL здесь не сохранены.

## S3 и IAM

- `get-bucket-encryption`: `SSEAlgorithm=AES256`, `BucketKeyEnabled=true`; `BlockedEncryptionTypes` содержит `SSE-C`. Это текущее состояние, первоначальная конфигурация бакета не установлена.
- `get-bucket-versioning`: `Enabled`.
- `get-bucket-lifecycle-configuration`: правило `timecapsule-dev-noncurrent`, статус `Enabled`, префикс `uploads/`; неактуальные версии переходят в `STANDARD_IA` через 30 дней и удаляются через 90 дней с `NewerNoncurrentVersions=2`; незавершённые multipart-загрузки отменяются через 7 дней; порог перехода 128 КБ.
- `head-object uploads/2026/10/report.txt`: 15 байт, `text/plain`, `AES256`.
- `aws s3 cp s3://timecapsule-dev-uploads-dumkaguv/uploads/photo.txt -`: `photo placeholder`. `HeadObject uploads/2026/10/report-2.txt` вернул 404; `list-object-versions` для точного ключа не показал ни версий, ни маркеров. История удаления из этих результатов не восстанавливается.
- Политика `arn:aws:iam::274520456264:policy/timecapsule-dev-app-s3`: исходная версия `v1` разрешала `ListBucket` с `s3:prefix=uploads/*` и `GetObject`/`PutObject`/`DeleteObject` для `uploads/*`. Теги: `Project=timecapsule`, `Environment=dev`, `Owner=dumkaguv`, `Lab=lab4`, `Name=timecapsule-dev-app-s3`. Прикреплена к `timecapsule-dev-s3-tester` и `timecapsule-dev-app-role`.
- Роль `timecapsule-dev-s3-tester` доверяет аккаунту `274520456264` и не имеет дополнительных inline-политик. Роль `timecapsule-dev-app-role` доверяет `ec2.amazonaws.com`.

## Тестовая роль с исходной версией политики

После `sts:AssumeRole` личность: `arn:aws:sts::274520456264:assumed-role/timecapsule-dev-s3-tester/lab4-verify`. Временные переменные учётных данных удалены из CloudShell после проверки.

| Действие | Получено |
| --- | --- |
| `PutObject uploads/lab4-role-test-20261031.txt` | Успех, 26 байт, `text/plain`, VersionId `y.Yh0sl5rISlnT.D43lZsz.NHFI0BADM` |
| `aws s3 ls .../uploads/` | Успех, тестовый объект виден |
| `aws s3 ls` корня бакета | `AccessDenied` для `ListBucket` с пустым префиксом |
| `GetObject uploads/photo.txt` | Успех, `photo placeholder` |
| Список `backups/` и запись `backups/lab4-role-test-20261031.txt` | `AccessDenied` |
| `ListBucketVersions` для `uploads/` | `AccessDenied` |
| `DeleteObject` с указанным VersionId | `AccessDenied` для `DeleteObjectVersion` |
| `DeleteObject` без VersionId | Успех, маркер `76WnE2Oqp.fF3FPNwfH10ftKhOYphL7c`; прежняя версия сохранилась |
| Список через HTTP endpoint | `AccessDenied` из-за явного запрета в политике бакета |

Тестовый объект скрыт маркером удаления, его версия остаётся восстанавливаемой. Постоянное удаление не выполнялось.

## App-server и миграция

- `sts get-caller-identity` на app-server: `arn:aws:sts::274520456264:assumed-role/timecapsule-dev-app-role/i-012cb83b9091855bb`.
- До сокращения политики список `uploads/` с app-server был доступен, список `backups/` давал `AccessDenied`.
- Каталог `/var/www/timecapsule/storage/uploads` содержал `.gitkeep` и `3e0a94bdae459ce76bc942fd6fc12a62.webp` (13 702 байта). Объект `uploads/3e0a94bdae459ce76bc942fd6fc12a62.webp` в S3 имеет 13 702 байта, `image/webp`, `AES256`.
- Повторный `aws s3 sync /var/www/timecapsule/storage/uploads s3://timecapsule-dev-uploads-dumkaguv/uploads/ --exclude .gitkeep --dryrun` завершился с кодом 0 без операций. Исторический вывод первоначального переноса не сохранён.
- На сервере `git HEAD=f959fed9e6a9454ce7d261e07c630c5ef339f8df`; SHA256 развёрнутых `src/storage.php` и `views/layout.php` совпадают с локальными файлами. `php -l src/storage.php` прошёл, AWS SDK установлен, `nginx` и `php-fpm` активны. В рабочей `.env` есть `S3_BUCKET` и `AWS_REGION`; ключей `AWS_ACCESS_KEY_ID` и `AWS_SECRET_ACCESS_KEY` нет (значения других переменных не выводились).
- 31.10 созданы через приложение капсулы №4 (PNG) и №5 (PDF). Новые объекты: `uploads/51838cddcf4f5f45cdf00ef598ca9d67.png` — 210 309 байт, `image/png`, `AES256`; `uploads/40ed6bc7637b8001b326efc4c789d805.pdf` — 589 байт, `application/pdf`, `AES256`.
- Локальный каталог временно перенесён в `/var/www/timecapsule/storage/uploads.lab4-backup-20261031`; прежнего пути больше нет. После этого создана капсула №6 с новым объектом `uploads/73dd8c96c224973a5fdf08180cf15971.pdf` — 589 байт, `application/pdf`, `AES256`.
- Для сквозной проверки старого ключа тестовая открытая капсула №7 временно указывала на `3e0a94bdae459ce76bc942fd6fc12a62.webp`: приложение показало изображение `old-lab2.webp` 310×301 по маршруту `/capsules/7/file`. После теста запись восстановлена на исходное `architecture.png` 1820×990. Скрипт [verify-old-object.php](../test_files/verify-old-object.php) проверял владельца, заголовок и прежний ключ перед каждым изменением.
- После успешной проверки резервный локальный каталог с `.gitkeep` и старым WebP удалён; `/var/www/timecapsule/storage/uploads` и `/var/www/timecapsule/storage/uploads.lab4-backup-20261031` отсутствуют. Исходная приватная капсула ЛР2 не открывалась через учётную запись её владельца.
- После перемещения старый `uploads/3e0a94bdae459ce76bc942fd6fc12a62.webp` скачан из S3 ролью app-server. Его SHA256 совпал с файлом из резервной копии: `56c0d131ae52b0f19dc2b18cc152e882a26a7b65302eeb32aea36f42f4fd04d3`. Временный скачанный файл удалён после проверки.
- Создана версия `v2` политики `timecapsule-dev-app-s3` и сделана версией по умолчанию: только `GetObject`, `PutObject`, `DeleteObject` для `uploads/*`, без `ListBucket`. После переключения app-server получает `AccessDenied` для `ListObjectsV2`, но `HeadObject` существующих объектов успешен, а приложение создало капсулу №6 и её объект в S3. Для отсутствующего `uploads/lab4-missing-20261031.txt` app-server получил `AccessDenied` (403), CloudShell с правом списка — `NoSuchKey` (404).

## Ожидают проверки

- Капсула №7 открылась 31.10 в 15:49 по местному времени; PNG с размерами 1820×990 виден в браузере. Переход `/capsules/7/file` перенаправил на S3 ключ `uploads/4e07678c3c46a9460b31882611f04bea.png` с `X-Amz-Algorithm=AWS4-HMAC-SHA256`, `X-Amz-Date=20261031T134941Z`, `X-Amz-Expires=300`. Полная подпись не сохранена. Открытая капсула №5 показала ссылку `Download lab4-test.pdf`, но браузер заблокировал автоматическое скачивание; имя скачанного файла не проверено.
- После подтверждённого удаления тестовой капсулы №5 приложение показало `Capsule deleted.`; `list-object-versions` показал прежнюю версию PDF `My2fg.2bmgCjXKM4GJiKx9birLcdaUrd` (589 байт) и новый текущий маркер `dUbycZCodxOlQsgBWYAOJSIpi8PicHTA`.
- Та же подписанная ссылка на PNG после истечения срока вернула `AccessDenied` и `Request has expired` (истечение `2026-10-31T13:54:41Z`, время ответа S3 `2026-10-31T13:55:00Z`). Снимок сохранён в `screenshots/12-expired-url.jpg`.
- Открытие исходной приватной капсулы из учётной записи её владельца не проверялось; старый S3 ключ проверен через временно перенастроенную учебную капсулу №7.
- После HTTP 200 `/health` (`status=ok`, `db=ok`) и проверки отсутствия локальных вложений вызван `stop-instances` для app-server `i-012cb83b9091855bb`, db-server `i-0f55807403e5f9a96`, bastion `i-093ce4db16e26e44e`. `aws ec2 wait instance-stopped` завершился успешно. Последующий `describe-instances` показал `stopped` для каждого из трёх.
