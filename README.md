# Units — Hasher

Fingerprints. Same input, same answer, every time.

```php
use Units\Hasher\Hasher;

$hasher = Hasher::create();                 // sha256, hex

$hasher->sha256('hello');                   // 2cf24dba…b9824
$hasher->hmac('payload', $secret)->hash;    // 0e7320e5…6ac3 — keyed
$hasher->compare($a, $b);                   // true — safe, use this not ===
$hasher->verify('hello', $known);           // true — hashes, then compares
```

PHP's own `hash()`, `hash_hmac()` and `hash_equals()`, with a name and a version
on top. No dependencies, no state.

## The words

**Hash** — one way. `hello` always becomes the same 64 characters, and those
characters never lead back to `hello`. For "is this the same?".

**HMAC** — a hash with a secret stirred in. Only a holder of the secret can
reproduce it. For "was this really us?".

**Salt** — extra random input, so the same value gives a *different* hash. Off
by default: a hash you cannot reproduce is useless for looking anything up.

**Pepper** — a secret mixed into a password before hashing, kept out of the
database. Your application's call, not the unit's.

## Is this the same?

```php
$hasher->hash($value)->hash;         // store this
$hasher->verify($value, $stored);    // check it later — true
```

Change one character and the answer changes completely. That is the whole point.

## Was this really us?

```php
$expected = $hasher->hmac($body, $secret)->hash;

$hasher->compare($expected, $signature);   // true — authentic
```

The sender signs the body with the shared secret; you sign what arrived. Nobody
without the secret can produce a match.

## Compare, don't `===`

`===` stops at the first byte that differs, and the time that takes tells the
caller how much of the guess was right. `compare()` does not, and it treats an
empty value as "not a match":

```php
$hasher->compare($a, $b);         // timing-safe, length-checked
$hasher->verify($value, $known);  // hashes first; never throws
```

`verify()` returns `false` for anything unusable, never an exception.

## A different answer each time

```php
$hasher->hash($value, ['salt' => $hasher->salt()])->hash;
```

`salt()` gives you 16 random bytes as hex. Use it when two identical rows must
not produce two identical hashes — and remember the result is then no longer
reproducible from the value alone.

## Shape of the output

| Encoding | Looks like | Use for |
| --- | --- | --- |
| `hex` *(default)* | `2cf24dba…` | databases, logs, shell |
| `base64` | `LPJNul+wow4m…` | the same, 33% shorter |
| `base64url` | `LPJNul-wow4m…` | URLs, filenames, tokens |
| `binary` | raw bytes | feeding another binary function |

```php
$hasher->hash('hello', ['encoding' => 'base64url'])->hash;
```

Set it once; override it per call:

```php
$hasher = Hasher::create(['algorithm' => 'sha512']);

$hasher->hash('hello', ['algorithm' => 'sha1'])->algorithm;   // 'sha1', just once
```

## Algorithms

| Algorithm | Use it when |
| --- | --- |
| `sha256` *(default)* | almost always |
| `sha512` | you want more margin |
| `sha3-512` | a spec or a standard names it |
| `blake2b` | you want a modern hash, fast on 64-bit |
| `sha1` · `md5` · `ripemd160` | never for new work — only to read values that already exist |

```php
$hasher->algorithms();          // all seven, in that order
$hasher->supports('sha3-512');  // true only if this PHP was built with it
```

## Passwords do not live here

A fingerprint must be fast and repeatable. A password hash must be slow and
never repeatable. Same word, opposite requirements — so passwords are
`units/password`:

```php
// an application composing both
$peppered = Hasher::create()->hmac($password, $pepper)->hash;
$stored   = Password::create(['algorithm' => 'argon2id'])->hash($peppered)->hash;
```

Never `stretch()` a password. It is a portable key stretch for legacy formats —
still far too fast to protect a credential.

## It knows its version

A hash outlives the code that made it. Keep the identity beside it, so a value
found later can say what produced it:

```php
$record = ['digest' => $hasher->hash($value)->hash, 'dna' => $hasher->dna()->toArray()];

Hasher::schema()->label();     // Hasher v1.0.0
Hasher::schema()->versions();  // the ledger, oldest first
```

## Reference

| Method | Gives you |
| --- | --- |
| `create(['algorithm' => …, 'encoding' => …, 'dna' => …])` | a hasher |
| `hash($data, ['algorithm' => …, 'encoding' => …, 'salt' => …, 'iterations' => …])` | `HashResult` |
| `hashBytes($bytes, $options)` | `HashResult`, for binary input |
| `hmac($data, $secret, $options)` | `HashResult`, keyed |
| `compare($a, $b)` | `bool`, timing-safe |
| `verify($data, $expected, $options)` | `bool`, never throws |
| `stretch($value, $salt = null, $iterations = 10000)` | `HashResult` |
| `sha256()` `sha512()` `md5()` `sha3_512($data, $encoding = 'hex')` | `string` |
| `salt($bytes = 16)` | random hex |
| `algorithms()` `supports($name)` | what this PHP can actually do |
| `algorithm()` `encoding()` `dna()` `whoami()` `help()` | itself |

`HashResult` carries `hash`, `algorithm`, `encoding`, and `salt` and `iterations`
when they were used. It is immutable, and `(string) $result` is the hash.

MIT · tests: `composer test`
