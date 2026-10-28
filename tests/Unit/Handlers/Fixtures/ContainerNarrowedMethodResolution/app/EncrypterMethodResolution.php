<?php

declare(strict_types=1);

namespace EncrypterMethodResolutionFixture;

// Narrowing-shadow regression (sibling of #1113): every method here is reached only through
// `app('encrypter')` container narrowing — the concrete `Illuminate\Encryption\Encrypter` class is
// never named, so nothing else forces Psalm to scan its real source. With the old full-class taint
// stub in place these non-taint methods reported `UndefinedMethod` (the stub shadowed the class down
// to its four taint methods, and the encrypter — unlike Repository/Store/Connection — has no
// Macroable `__call` to mask the strip). They must now resolve against the real Laravel class.
//
// `encryptString`/`decryptString` are the taint methods that survived in the stub; `getKey`,
// `getAllKeys`, `getPreviousKeys` are the real-source methods the shadow stripped.
function exerciseEncrypter(): void
{
    app('encrypter')->encryptString('secret');
    app('encrypter')->decryptString('payload');
    app('encrypter')->getKey();
    app('encrypter')->getAllKeys();
    app('encrypter')->getPreviousKeys();
}
