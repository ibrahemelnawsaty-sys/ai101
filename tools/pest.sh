#!/usr/bin/env bash
# Runs the suite with the portable PHP used on this machine (no system PHP here).
PHP="C:/Users/B70C7~1.MAH/AppData/Local/Temp/claude/c--Users-b-maher-Downloads-wesal-LARAVEL/7d3cf468-bc70-4ec4-bb3f-b70e90785c56/scratchpad/php/php.exe"
cd "c:/Users/b.maher/Downloads/wesal/LARAVEL" || exit 1
exec "$PHP" -d memory_limit=1G vendor/pestphp/pest/bin/pest "$@"
