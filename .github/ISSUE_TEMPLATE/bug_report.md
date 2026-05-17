---
name: Bug report
about: Create a report to help us improve
title: ''
type: Bug
---

**Describe the bug**
A clear and concise description of what the bug is.

**Code to reproduce**
```php
// Minimal PHP snippet that triggers the issue
```

**Impacted Versions**
Output of the
```shell
composer show | grep -E 'psalm|laravel/'
```
```text
```

**Plugin diagnose output**
Run
```shell
./vendor/bin/psalm-laravel diagnose
```
and paste the result below (helps us see boot mode, stubs, and integrations).
```text
```

**Psalm config**
```xml
<!--Please paste your `psalm.xml` (or `psalm.xml.dist`) contents-->
```

**Additional context**
Add any other context about the problem here.
