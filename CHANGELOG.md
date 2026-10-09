# Changelog

All notable changes to `units/hasher` are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) ·
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-07

### Added

- `Units\Hasher\Hasher` — the hashing unit: a versioned identity that makes
  native digests, HMACs and timing-safe comparisons, on the `patterns/unit`
  contract.
- `Units\Hasher\HashResult` — the shape a digest gives back: the digest, the
  algorithm, the encoding, and the salt and iterations when they were used.
- `Units\Hasher\HasherSchema` — the unit's DNA (`Patterns\IUnitSchema`), a
  serializable shape that can be persisted alongside every digest it makes.

### Notes

- Stateless by construction: every field is `readonly`, and every call is a pure
  function of its arguments. There is no operation log and no `lastHash` — a
  hasher that remembers what it hashed is a log wearing a digest.
- No password hashing. `password_hash()` is deliberately slow and
  non-deterministic; credentials are `units/password`, a different unit with a
  different threat model.
- `Patterns\ValueObject` is a dependency of the DNA only. `HashResult` is a
  plain readonly class: it needs neither `equals()` nor the props array, so it
  takes neither the base class nor the dependency.

[1.0.0]: https://github.com/units-php/hasher/releases/tag/v1.0.0
